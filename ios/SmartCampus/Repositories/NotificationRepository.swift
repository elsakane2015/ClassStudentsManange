import Foundation

struct TeacherWechatConfigItem: Decodable, Hashable {
    let id: Int
    let useSystem: FlexibleBool?
    let isVerified: FlexibleBool?
    let pushEnabled: FlexibleBool?

    enum CodingKeys: String, CodingKey {
        case id
        case useSystem = "use_system"
        case isVerified = "is_verified"
        case pushEnabled = "push_enabled"
    }

    var isUseSystem: Bool { useSystem?.value ?? true }
    var isVerifiedFlag: Bool { isVerified?.value ?? false }
    var isPushEnabled: Bool { pushEnabled?.value ?? true }
}

struct TeacherWechatBindingSummary: Decodable, Identifiable, Hashable {
    let id: Int
    let userName: String?
    let userRole: String?
    let nickname: String?
    let pushEnabled: FlexibleBool?

    enum CodingKeys: String, CodingKey {
        case id, nickname
        case userName = "user_name"
        case userRole = "user_role"
        case pushEnabled = "push_enabled"
    }

    var isPushEnabled: Bool { pushEnabled?.value ?? true }
    var roleDisplayText: String {
        switch userRole {
        case "teacher": return "班主任"
        case "student": return "班级考勤员"
        default: return userRole ?? ""
        }
    }
}

struct TeacherWechatConfigResponse: Decodable {
    let useSystemEnabled: FlexibleBool?
    let customConfigEnabled: FlexibleBool?
    let config: TeacherWechatConfigItem?
    let bindings: [TeacherWechatBindingSummary]?

    enum CodingKeys: String, CodingKey {
        case config, bindings
        case useSystemEnabled = "use_system_enabled"
        case customConfigEnabled = "custom_config_enabled"
    }

    var isAvailable: Bool {
        (useSystemEnabled?.value ?? false) || (customConfigEnabled?.value ?? false) || config != nil
    }
}

struct TeacherWechatToggleResponse: Decodable {
    let message: String?
    let pushEnabled: FlexibleBool?

    enum CodingKeys: String, CodingKey {
        case message
        case pushEnabled = "push_enabled"
    }
}

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

    static func fetchTeacherWechatConfig() async throws -> TeacherWechatConfigResponse {
        try await APIClient.shared.request("/wechat/teacher-config")
    }

    static func toggleTeacherWechatPush(enabled: Bool) async throws -> TeacherWechatToggleResponse {
        struct Payload: Encodable {
            let push_enabled: Bool
        }
        return try await APIClient.shared.request(
            "/wechat/teacher-config/toggle",
            method: "POST",
            body: Payload(push_enabled: enabled)
        )
    }

    static func toggleTeacherWechatBindingPush(bindingId: Int, enabled: Bool) async throws -> TeacherWechatToggleResponse {
        struct Payload: Encodable {
            let push_enabled: Bool
        }
        return try await APIClient.shared.request(
            "/wechat/teacher-binding/\(bindingId)/toggle",
            method: "POST",
            body: Payload(push_enabled: enabled)
        )
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
