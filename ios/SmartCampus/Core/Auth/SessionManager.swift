import Foundation
import Observation
import UIKit

/// 全局登录会话与多角色状态管理器
@MainActor
@Observable
final class SessionManager {
    static let shared = SessionManager()

    var currentUser: User?
    var isBootstrapping: Bool = true
    var isAuthenticated: Bool { currentUser != nil }

    private init() {
        APIClient.shared.onUnauthorized = { [weak self] in
            self?.handleSessionExpired()
        }
    }

    /// App 启动时自动恢复登录态与学校名称
    func bootstrap() async {
        isBootstrapping = true
        defer { isBootstrapping = false }

        guard ServerConfigManager.shared.isConfigured else {
            currentUser = nil
            return
        }

        // 静默刷新学校名称
        if let base = ServerConfigManager.shared.activeBaseURL,
           let probed = try? await ServerConfigManager.shared.probeServer(base) {
            ServerConfigManager.shared.applyServer(baseURL: probed.baseURL, schoolName: probed.schoolName)
        }

        guard let token = KeychainHelper.shared.readToken(), !token.isEmpty else {
            currentUser = nil
            return
        }

        do {
            let user: User = try await APIClient.shared.request("/me")
            currentUser = user
            PushNotificationManager.shared.unreadCount = user.unreadNotificationsCount ?? 0
            Task {
                await PushNotificationManager.shared.requestPermissionAndRegister()
                await PushNotificationManager.shared.refreshUnreadCount()
            }
        } catch {
            KeychainHelper.shared.clearToken()
            currentUser = nil
        }
    }

    /// 邮箱或学号登录（自动附带 iOS 设备名称）
    func login(account: String, password: String) async throws {
        let payload = LoginRequest(
            email: account.trimmingCharacters(in: .whitespacesAndNewlines),
            password: password,
            device_name: "iOS - \(UIDevice.current.name)"
        )

        let loginRes: LoginResponse = try await APIClient.shared.request(
            "/auth/login",
            method: "POST",
            body: payload,
            requiresAuth: false
        )

        KeychainHelper.shared.saveToken(loginRes.accessToken)

        let me: User = try await APIClient.shared.request("/me")
        currentUser = me
        PushNotificationManager.shared.unreadCount = me.unreadNotificationsCount ?? 0

        Task {
            await PushNotificationManager.shared.requestPermissionAndRegister()
            await PushNotificationManager.shared.refreshUnreadCount()
        }
    }

    func refreshUser() async {
        guard isAuthenticated else { return }
        if let me: User = try? await APIClient.shared.request("/me") {
            currentUser = me
            PushNotificationManager.shared.unreadCount = me.unreadNotificationsCount ?? 0
        }
    }

    /// 安全退出登录：先解绑本机推送设备，再销毁当前 Sanctum Token
    func logout() async {
        await PushNotificationManager.shared.unregisterCurrentDevice()
        let _: EmptyResponse? = try? await APIClient.shared.request("/auth/logout", method: "POST")
        clearLocalSession()
    }

    /// 切换学校服务器时，注销并清除旧站点登录态
    func switchServer(baseURL: String, schoolName: String) async {
        if isAuthenticated {
            await logout()
        }
        ServerConfigManager.shared.applyServer(baseURL: baseURL, schoolName: schoolName)
    }

    private func handleSessionExpired() {
        clearLocalSession()
    }

    private func clearLocalSession() {
        KeychainHelper.shared.clearToken()
        currentUser = nil
        PushNotificationManager.shared.unreadCount = 0
        PushNotificationManager.shared.pendingDestination = nil
    }
}
