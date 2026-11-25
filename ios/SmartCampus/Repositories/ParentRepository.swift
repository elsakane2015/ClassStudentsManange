import Foundation

struct ParentChildrenResponse: Decodable {
    let data: [ParentChildSummary]
}

struct ParentChildOverviewResponse: Decodable {
    let child: ParentChildSummary
    let scope: String
    let stats: StudentStatsResponse
    let leaveTypes: [LeaveType]

    enum CodingKeys: String, CodingKey {
        case child, scope, stats
        case leaveTypes = "leave_types"
    }
}

struct ParentLeaveRequestItem: Decodable, Identifiable, Hashable {
    let id: Int
    let leaveBatchId: String?
    let studentId: Int?
    let typeName: String?
    let color: String?
    let startDate: String?
    let endDate: String?
    let dateRangeText: String?
    let totalDays: FlexibleInt?
    let periodLabel: String?
    let eveningStudyLabel: String?
    let hasEveningStudy: FlexibleBool?
    let destination: String?
    let reason: String?
    let status: String?
    let approvalStatus: String?
    let approverName: String?
    let rejectionReason: String?
    let createdAt: String?

    enum CodingKeys: String, CodingKey {
        case id, color, destination, reason, status
        case leaveBatchId = "leave_batch_id"
        case studentId = "student_id"
        case typeName = "type_name"
        case startDate = "start_date"
        case endDate = "end_date"
        case dateRangeText = "date_range_text"
        case totalDays = "total_days"
        case periodLabel = "period_label"
        case eveningStudyLabel = "evening_study_label"
        case hasEveningStudy = "has_evening_study"
        case approvalStatus = "approval_status"
        case approverName = "approver_name"
        case rejectionReason = "rejection_reason"
        case createdAt = "created_at"
    }

    var effectiveStatus: String { approvalStatus ?? status ?? "approved" }
    var statusDisplayText: String {
        switch effectiveStatus {
        case "pending": return "待审批"
        case "approved": return "已通过"
        case "rejected": return "已驳回"
        default: return effectiveStatus
        }
    }
}

struct ParentLeaveRequestsResponse: Decodable {
    let data: [ParentLeaveRequestItem]
    let total: Int?
}

struct ParentRecordItem: Decodable, Identifiable, Hashable {
    let id: Int
    let studentId: Int?
    let date: String?
    let scene: String?
    let status: String?
    let typeName: String?
    let color: String?
    let isSelfApplied: FlexibleBool?
    let approvalStatus: String?
    let sourceType: String?
    let detailLabel: String?
    let recordTime: String?
    let reason: String?
    let note: String?
    let destination: String?

    enum CodingKeys: String, CodingKey {
        case id, date, scene, status, color, reason, note, destination
        case studentId = "student_id"
        case typeName = "type_name"
        case isSelfApplied = "is_self_applied"
        case approvalStatus = "approval_status"
        case sourceType = "source_type"
        case detailLabel = "detail_label"
        case recordTime = "record_time"
    }

    var sourceDisplayText: String {
        if isSelfApplied?.value == true { return "自主申请" }
        if sourceType == "roll_call" { return "点名标记" }
        if scene == "evening_study" { return "夜自习" }
        return "老师标记"
    }
}

struct ParentRecordsResponse: Decodable {
    let data: [ParentRecordItem]
}

struct StudentParentsResponse: Decodable {
    let data: [StudentParentAccount]
}

enum ParentRepository {
    static func fetchChildren() async throws -> [ParentChildSummary] {
        let res: ParentChildrenResponse = try await APIClient.shared.request("/parent/children")
        return res.data
    }

    static func fetchChildOverview(studentId: Int, scope: String = "month") async throws -> ParentChildOverviewResponse {
        try await APIClient.shared.request(
            "/parent/children/\(studentId)/overview",
            query: ["scope": scope]
        )
    }

    static func fetchChildLeaveRequests(studentId: Int, status: String = "all") async throws -> [ParentLeaveRequestItem] {
        let res: ParentLeaveRequestsResponse = try await APIClient.shared.request(
            "/parent/children/\(studentId)/leave-requests",
            query: ["status": status, "per_page": "40"]
        )
        return res.data
    }

    static func fetchChildRecords(studentId: Int, scope: String = "month") async throws -> [ParentRecordItem] {
        let res: ParentRecordsResponse = try await APIClient.shared.request(
            "/parent/children/\(studentId)/records",
            query: ["scope": scope]
        )
        return res.data
    }

    // MARK: - Password Reset via Email Code (Option C)

    static func sendPasswordResetCode(email: String) async throws -> String {
        struct Payload: Encodable { let email: String }
        let res: MessageResponse = try await APIClient.shared.request(
            "/auth/password-reset/send-code",
            method: "POST",
            body: Payload(email: email.trimmingCharacters(in: .whitespacesAndNewlines)),
            requiresAuth: false
        )
        return res.message ?? "验证码已发送至邮箱"
    }

    static func confirmPasswordReset(
        email: String,
        code: String,
        password: String,
        confirmPassword: String
    ) async throws -> String {
        struct Payload: Encodable {
            let email: String
            let code: String
            let password: String
            let password_confirmation: String
        }
        let res: MessageResponse = try await APIClient.shared.request(
            "/auth/password-reset/confirm",
            method: "POST",
            body: Payload(
                email: email.trimmingCharacters(in: .whitespacesAndNewlines),
                code: code.trimmingCharacters(in: .whitespacesAndNewlines),
                password: password,
                password_confirmation: confirmPassword
            ),
            requiresAuth: false
        )
        return res.message ?? "密码已重置成功"
    }

    // MARK: - Teacher / Admin Parent Account Management

    static func fetchStudentParents(studentId: Int) async throws -> [StudentParentAccount] {
        let res: StudentParentsResponse = try await APIClient.shared.request("/students/\(studentId)/parents")
        return res.data
    }

    static func resetStudentParentPassword(
        studentId: Int,
        parentUserId: Int,
        password: String?
    ) async throws -> String {
        var jsonBody: [String: Any] = [:]
        if let pwd = password?.trimmingCharacters(in: .whitespacesAndNewlines), !pwd.isEmpty {
            jsonBody["password"] = pwd
        }
        let res: MessageResponse = try await APIClient.shared.requestWithJSONBody(
            "/students/\(studentId)/parents/\(parentUserId)/reset-password",
            method: "POST",
            jsonBody: jsonBody
        )
        return res.message ?? "家长密码已重置"
    }
}
