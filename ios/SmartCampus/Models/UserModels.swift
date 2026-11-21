import Foundation

struct DepartmentRef: Codable, Identifiable, Hashable {
    let id: Int
    let name: String
}

struct StudentProfile: Codable, Identifiable, Hashable {
    let id: Int
    let userId: Int?
    let schoolId: Int?
    let classId: Int?
    let studentNo: String?
    let isClassAdmin: FlexibleBool?
    let isRollCallAdmin: FlexibleBool?
    let isBoarding: FlexibleBool?
    let gender: String?
    let parentContact: String?
    let parentEmail: String?

    enum CodingKeys: String, CodingKey {
        case id
        case userId = "user_id"
        case schoolId = "school_id"
        case classId = "class_id"
        case studentNo = "student_no"
        case isClassAdmin = "is_class_admin"
        case isRollCallAdmin = "is_roll_call_admin"
        case isBoarding = "is_boarding"
        case gender
        case parentContact = "parent_contact"
        case parentEmail = "parent_email"
    }

    var isClassAdminFlag: Bool { isClassAdmin?.value ?? false }
    var isRollCallAdminFlag: Bool { isRollCallAdmin?.value ?? false }
    var isBoardingFlag: Bool { isBoarding?.value ?? false }
}

struct User: Codable, Identifiable, Hashable {
    let id: Int
    let uuid: String?
    let name: String
    let email: String?
    let phone: String?
    let role: String
    let status: FlexibleBool?
    let avatarUrl: String?
    let student: StudentProfile?
    let dutyDepartments: [DepartmentRef]?
    let permissions: [String]?
    let unreadNotificationsCount: Int?

    enum CodingKeys: String, CodingKey {
        case id, uuid, name, email, phone, role, status
        case avatarUrl = "avatar_url"
        case student
        case dutyDepartments = "duty_departments"
        case permissions
        case unreadNotificationsCount = "unread_notifications_count"
    }

    var isStudent: Bool { role == "student" }
    var isDutyTeacher: Bool { role == "duty_teacher" }
    var isAdminTier: Bool {
        ["system_admin", "school_admin", "department_manager", "admin", "manager"].contains(role)
    }
    var isTeacherOrAdmin: Bool {
        role == "teacher" || isAdminTier
    }
    var isClassAdminStudent: Bool {
        isStudent && (student?.isClassAdminFlag ?? false)
    }
    var isRollCallAdminStudent: Bool {
        isStudent && (student?.isRollCallAdminFlag ?? false)
    }
    var canTakeEveningStudy: Bool {
        ["duty_teacher", "system_admin", "school_admin", "department_manager", "manager"].contains(role)
            || !(dutyDepartments ?? []).isEmpty
    }

    var roleDisplayName: String {
        switch role {
        case "system_admin": return "系统管理员"
        case "school_admin", "admin": return "校级管理员"
        case "department_manager", "manager": return "院系管理员"
        case "duty_teacher": return "值班教师"
        case "teacher": return "教师"
        case "student":
            if isClassAdminStudent && isRollCallAdminStudent { return "学生（班级管理员 · 点名员）" }
            if isClassAdminStudent { return "学生（班级管理员）" }
            if isRollCallAdminStudent { return "学生（点名员）" }
            return "学生"
        default: return role
        }
    }

    func hasPermission(_ permission: String) -> Bool {
        if role == "system_admin" { return true }
        return (permissions ?? []).contains(permission)
    }
}

struct LoginRequest: Encodable {
    let email: String
    let password: String
    let device_name: String
}

struct LoginResponse: Decodable {
    let accessToken: String
    let tokenType: String?
    let user: User?

    enum CodingKeys: String, CodingKey {
        case accessToken = "access_token"
        case tokenType = "token_type"
        case user
    }
}

struct AppNotificationItem: Decodable, Identifiable, Hashable {
    let id: Int
    let type: String
    let title: String
    let body: String
    let data: [String: FlexibleString]?
    let readAt: String?
    let pushedAt: String?
    let pushStatus: String?
    let createdAt: String?

    enum CodingKeys: String, CodingKey {
        case id, type, title, body, data
        case readAt = "read_at"
        case pushedAt = "pushed_at"
        case pushStatus = "push_status"
        case createdAt = "created_at"
    }

    var isUnread: Bool { readAt == nil || readAt?.isEmpty == true }
    var route: String? { data?["route"]?.value }
}

struct NotificationsPageResponse: Decodable {
    let data: [AppNotificationItem]
    let unreadCount: Int
    let currentPage: Int?
    let lastPage: Int?
    let total: Int?

    enum CodingKeys: String, CodingKey {
        case data
        case unreadCount = "unread_count"
        case currentPage = "current_page"
        case lastPage = "last_page"
        case total
    }
}

struct MobileDeviceItem: Decodable, Identifiable {
    let id: Int
    let platform: String
    let pushProvider: String?
    let deviceName: String?
    let appVersion: String?
    let osVersion: String?
    let pushEnabled: FlexibleBool?
    let pushToken: String?
    let lastActiveAt: String?

    enum CodingKeys: String, CodingKey {
        case id, platform
        case pushProvider = "push_provider"
        case deviceName = "device_name"
        case appVersion = "app_version"
        case osVersion = "os_version"
        case pushEnabled = "push_enabled"
        case pushToken = "push_token"
        case lastActiveAt = "last_active_at"
    }
}

struct MobileDevicesListResponse: Decodable {
    let data: [MobileDeviceItem]
}
