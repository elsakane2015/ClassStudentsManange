import Foundation
import Observation
import UIKit
import UserNotifications

/// 推送点击或消息中心触发的全局 Deep Link 跳转目标
enum PushDestination: Equatable, Hashable {
    case leaveApprovals
    case leaveHistory
    case studentDashboard
    case notifications
}

/// 原生 APNs 推送与应用内通知状态管理器（终态架构：无推送证书时仍支持完整设备注册与 App 内消息中心，配置证书后自动升级为系统锁屏推送）
@MainActor
@Observable
final class PushNotificationManager: NSObject {
    static let shared = PushNotificationManager()

    private static let cachedTokenKey = "smartcampus.last_apns_token"

    var authorizationStatus: UNAuthorizationStatus = .notDetermined
    var apnsDeviceToken: String? = UserDefaults.standard.string(forKey: PushNotificationManager.cachedTokenKey)
    var apnsEnvironment: String = PushNotificationManager.detectAPNsEnvironment()
    var lastRegistrationError: String?
    var pushGatewayConfiguredOnServer: Bool = false
    var lastSyncedAt: Date?
    var isSyncing: Bool = false
    var unreadCount: Int = 0
    var pendingDestination: PushDestination?

    private var pendingTokenContinuation: CheckedContinuation<String?, Never>?

    /// 读取安装包内 embedded.mobileprovision 中的 aps-environment，精准区分开发(sandbox)与生产环境
    private static func detectAPNsEnvironment() -> String {
        guard let url = Bundle.main.url(forResource: "embedded", withExtension: "mobileprovision"),
              let raw = try? String(contentsOf: url, encoding: .isoLatin1),
              let start = raw.range(of: "<plist"),
              let end = raw.range(of: "</plist>") else {
            #if DEBUG
            return "development"
            #else
            return "production"
            #endif
        }
        let plistStr = String(raw[start.lowerBound..<end.upperBound])
        guard let data = plistStr.data(using: .utf8),
              let dict = try? PropertyListSerialization.propertyList(from: data, format: nil) as? [String: Any],
              let entitlements = dict["Entitlements"] as? [String: Any],
              let env = entitlements["aps-environment"] as? String else {
            return "production"
        }
        return env == "development" ? "development" : "production"
    }

    private override init() {
        super.init()
        UNUserNotificationCenter.current().delegate = self
    }

    func refreshAuthorizationStatus() async {
        let settings = await UNUserNotificationCenter.current().notificationSettings()
        authorizationStatus = settings.authorizationStatus
    }

    /// 登录后或回到前台时自动请求通知权限并向 iOS 系统注册 APNs 远程推送，再以最新 Token 上报后端（消除空 Token 并发覆盖）
    func requestPermissionAndRegister() async {
        isSyncing = true
        defer { isSyncing = false }

        let center = UNUserNotificationCenter.current()
        var granted = false
        do {
            granted = try await center.requestAuthorization(options: [.alert, .sound, .badge])
            await refreshAuthorizationStatus()
        } catch {
            await refreshAuthorizationStatus()
        }

        if granted {
            UIApplication.shared.registerForRemoteNotifications()
            // 首次冷启动若尚无缓存 Token，短暂等待 Apple 返回真机 Token（最多 1.5 秒），避免先发空 Token 与真实 Token 竞态
            if apnsDeviceToken == nil {
                _ = await waitForAPNsToken(timeoutSeconds: 1.5)
            }
        }

        await syncDeviceWithBackend(pushToken: apnsDeviceToken)
    }

    /// 从后台切回前台时静默自愈：若用户已开启通知权限（例如刚从系统设置打开），自动刷新 APNs Token 并同步后端
    func syncOnForegroundIfAuthenticated() async {
        guard KeychainHelper.shared.readToken() != nil else { return }
        await refreshAuthorizationStatus()
        if authorizationStatus == .authorized || authorizationStatus == .provisional || authorizationStatus == .ephemeral {
            UIApplication.shared.registerForRemoteNotifications()
            if let token = apnsDeviceToken {
                await syncDeviceWithBackend(pushToken: token)
            }
        }
    }

    private func waitForAPNsToken(timeoutSeconds: Double) async -> String? {
        if let existing = apnsDeviceToken { return existing }
        return await withCheckedContinuation { continuation in
            self.pendingTokenContinuation?.resume(returning: self.apnsDeviceToken)
            self.pendingTokenContinuation = continuation
            Task { @MainActor in
                try? await Task.sleep(for: .seconds(timeoutSeconds))
                if let pending = self.pendingTokenContinuation {
                    self.pendingTokenContinuation = nil
                    pending.resume(returning: self.apnsDeviceToken)
                }
            }
        }
    }

    func handleRegisteredDeviceToken(_ tokenData: Data) {
        let tokenString = tokenData.map { String(format: "%02x", $0) }.joined()
        self.apnsDeviceToken = tokenString
        UserDefaults.standard.set(tokenString, forKey: Self.cachedTokenKey)
        self.lastRegistrationError = nil
        self.apnsEnvironment = Self.detectAPNsEnvironment()

        if let pending = self.pendingTokenContinuation {
            self.pendingTokenContinuation = nil
            pending.resume(returning: tokenString)
        }

        Task {
            await syncDeviceWithBackend(pushToken: tokenString)
        }
    }

    func handleFailedRemoteRegistration(_ error: Error) {
        // 模拟器或尚未开启 Push Notifications 签名能力时会进入此处，记录状态并优雅降级
        self.lastRegistrationError = error.localizedDescription
        if let pending = self.pendingTokenContinuation {
            self.pendingTokenContinuation = nil
            pending.resume(returning: nil)
        }
        Task {
            await syncDeviceWithBackend(pushToken: apnsDeviceToken)
        }
    }

    func syncDeviceWithBackend(pushToken: String?) async {
        guard ServerConfigManager.shared.isConfigured,
              KeychainHelper.shared.readToken() != nil else {
            return
        }

        struct DeviceRegisterPayload: Encodable {
            let platform: String
            let push_provider: String
            let push_environment: String
            let device_identifier: String
            let push_token: String?
            let device_name: String
            let app_version: String
            let os_version: String
            let locale: String
            let push_enabled: Bool

            func encode(to encoder: Encoder) throws {
                var c = encoder.container(keyedBy: CodingKeys.self)
                try c.encode(platform, forKey: .platform)
                try c.encode(push_provider, forKey: .push_provider)
                try c.encode(push_environment, forKey: .push_environment)
                try c.encode(device_identifier, forKey: .device_identifier)
                if let token = push_token, !token.isEmpty {
                    try c.encode(token, forKey: .push_token)
                }
                try c.encode(device_name, forKey: .device_name)
                try c.encode(app_version, forKey: .app_version)
                try c.encode(os_version, forKey: .os_version)
                try c.encode(locale, forKey: .locale)
                try c.encode(push_enabled, forKey: .push_enabled)
            }

            private enum CodingKeys: String, CodingKey {
                case platform, push_provider, push_environment, device_identifier
                case push_token, device_name, app_version, os_version, locale, push_enabled
            }
        }

        struct DeviceRegisterResponse: Decodable {
            let push_gateway_configured: Bool?
        }

        let effectiveToken = (pushToken?.isEmpty == false ? pushToken : nil) ?? apnsDeviceToken
        let appVersion = Bundle.main.infoDictionary?["CFBundleShortVersionString"] as? String ?? "1.0.0"
        let payload = DeviceRegisterPayload(
            platform: "ios",
            push_provider: "apns",
            push_environment: apnsEnvironment,
            device_identifier: KeychainHelper.shared.getOrCreateDeviceIdentifier(),
            push_token: effectiveToken,
            device_name: UIDevice.current.name,
            app_version: appVersion,
            os_version: "iOS \(UIDevice.current.systemVersion)",
            locale: Locale.current.identifier,
            push_enabled: authorizationStatus == .authorized || authorizationStatus == .provisional
        )

        if let res: DeviceRegisterResponse = try? await APIClient.shared.request(
            "/devices/register",
            method: "POST",
            body: payload
        ) {
            pushGatewayConfiguredOnServer = res.push_gateway_configured ?? false
            lastSyncedAt = Date()
        }
    }

    func unregisterCurrentDevice() async {
        guard ServerConfigManager.shared.isConfigured,
              KeychainHelper.shared.readToken() != nil else {
            return
        }

        struct UnregisterPayload: Encodable {
            let platform: String
            let device_identifier: String
        }

        let payload = UnregisterPayload(
            platform: "ios",
            device_identifier: KeychainHelper.shared.getOrCreateDeviceIdentifier()
        )
        let _: EmptyResponse? = try? await APIClient.shared.request(
            "/devices/unregister",
            method: "POST",
            body: payload
        )
    }

    func refreshUnreadCount() async {
        struct UnreadResponse: Decodable {
            let unread_count: Int
        }
        guard KeychainHelper.shared.readToken() != nil else {
            unreadCount = 0
            return
        }
        if let res: UnreadResponse = try? await APIClient.shared.request("/notifications/unread-count") {
            unreadCount = res.unread_count
            try? await UNUserNotificationCenter.current().setBadgeCount(res.unread_count)
        }
    }

    func handleDeepLink(route: String?, type: String?) {
        switch route ?? type {
        case "leave_approvals", "leave_submitted":
            pendingDestination = .leaveApprovals
        case "leave_history", "leave_approved", "leave_rejected":
            pendingDestination = .leaveHistory
        case "student_dashboard", "attendance_alert":
            pendingDestination = .studentDashboard
        default:
            pendingDestination = .notifications
        }
    }
}

extension PushNotificationManager: UNUserNotificationCenterDelegate {
    nonisolated func userNotificationCenter(
        _ center: UNUserNotificationCenter,
        willPresent notification: UNNotification
    ) async -> UNNotificationPresentationOptions {
        _ = await MainActor.run {
            Task { await self.refreshUnreadCount() }
        }
        return [.banner, .sound, .badge]
    }

    nonisolated func userNotificationCenter(
        _ center: UNUserNotificationCenter,
        didReceive response: UNNotificationResponse
    ) async {
        let userInfo = response.notification.request.content.userInfo
        let dataDict = userInfo["data"] as? [String: Any]
        let route = dataDict?["route"] as? String
        let type = (userInfo["type"] as? String) ?? (dataDict?["type"] as? String)

        await MainActor.run {
            self.handleDeepLink(route: route, type: type)
            Task { await self.refreshUnreadCount() }
        }
    }
}
