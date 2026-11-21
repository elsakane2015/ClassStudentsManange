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

    var authorizationStatus: UNAuthorizationStatus = .notDetermined
    var apnsDeviceToken: String?
    var apnsEnvironment: String = PushNotificationManager.detectAPNsEnvironment()
    var lastRegistrationError: String?
    var pushGatewayConfiguredOnServer: Bool = false
    var unreadCount: Int = 0
    var pendingDestination: PushDestination?

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

    /// 登录后请求通知权限并向 iOS 系统注册 APNs 远程推送，同时向后端上报当前设备
    func requestPermissionAndRegister() async {
        let center = UNUserNotificationCenter.current()
        do {
            let granted = try await center.requestAuthorization(options: [.alert, .sound, .badge])
            await refreshAuthorizationStatus()
            if granted {
                UIApplication.shared.registerForRemoteNotifications()
            }
        } catch {
            await refreshAuthorizationStatus()
        }

        // 即使在模拟器或暂未拿到 APNs Token 时，也先注册设备元数据（拿到 Token 后会自动再更新）
        await syncDeviceWithBackend(pushToken: apnsDeviceToken)
    }

    func handleRegisteredDeviceToken(_ tokenData: Data) {
        let tokenString = tokenData.map { String(format: "%02x", $0) }.joined()
        self.apnsDeviceToken = tokenString
        self.lastRegistrationError = nil
        self.apnsEnvironment = Self.detectAPNsEnvironment()
        Task {
            await syncDeviceWithBackend(pushToken: tokenString)
        }
    }

    func handleFailedRemoteRegistration(_ error: Error) {
        // 模拟器或尚未开启 Push Notifications 签名能力时会进入此处，记录状态并优雅降级
        self.lastRegistrationError = error.localizedDescription
        Task {
            await syncDeviceWithBackend(pushToken: nil)
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
        }

        struct DeviceRegisterResponse: Decodable {
            let push_gateway_configured: Bool?
        }

        let appVersion = Bundle.main.infoDictionary?["CFBundleShortVersionString"] as? String ?? "1.0.0"
        let payload = DeviceRegisterPayload(
            platform: "ios",
            push_provider: "apns",
            push_environment: apnsEnvironment,
            device_identifier: KeychainHelper.shared.getOrCreateDeviceIdentifier(),
            push_token: pushToken,
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
