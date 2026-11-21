import Foundation

enum NotificationRepository {
    static func fetchNotifications(page: Int = 1, unreadOnly: Bool = false) async throws -> NotificationsPageResponse {
        try await APIClient.shared.request(
            "/notifications",
            query: [
                "page": String(page),
                "per_page": "30",
                "unread_only": unreadOnly ? "1" : "0"
            ]
        )
    }

    static func markRead(id: Int) async throws {
        let _: EmptyResponse = try await APIClient.shared.request(
            "/notifications/\(id)/read",
            method: "POST"
        )
    }

    static func markAllRead() async throws {
        let _: EmptyResponse = try await APIClient.shared.request(
            "/notifications/read-all",
            method: "POST"
        )
    }

    static func deleteNotification(id: Int) async throws {
        let _: EmptyResponse = try await APIClient.shared.request(
            "/notifications/\(id)",
            method: "DELETE"
        )
    }

    static func fetchDevices() async throws -> [MobileDeviceItem] {
        let res: MobileDevicesListResponse = try await APIClient.shared.request("/devices")
        return res.data
    }

    static func changePassword(
        currentPassword: String,
        newPassword: String,
        confirmPassword: String
    ) async throws {
        struct Payload: Encodable {
            let current_password: String
            let new_password: String
            let new_password_confirmation: String
        }
        let _: MessageResponse = try await APIClient.shared.request(
            "/user/change-password",
            method: "POST",
            body: Payload(
                current_password: currentPassword,
                new_password: newPassword,
                new_password_confirmation: confirmPassword
            )
        )
    }
}
