import Foundation

struct LeaveSubRecord: Decodable, Identifiable, Hashable {
    let id: Int
    let date: String?
    let periodId: Int?
    let label: String?
    let scene: String?
    let approvalStatus: String?
    let destination: String?
    let requestedEveningStatus: NamedStatusRef?
    let eveningStudyStatus: NamedStatusRef?

    enum CodingKeys: String, CodingKey {
        case id, date, label, scene, destination
        case periodId = "period_id"
        case approvalStatus = "approval_status"
        case requestedEveningStatus = "requested_evening_status"
        case eveningStudyStatus = "evening_study_status"
    }
}

struct LeaveStudentRef: Decodable, Hashable {
    let id: Int
    let studentNo: String?
    let user: User?

    enum CodingKeys: String, CodingKey {
        case id, user
        case studentNo = "student_no"
    }

    var name: String { user?.name ?? "学生 #\(id)" }
}

struct LeaveClassRef: Decodable, Hashable {
    let id: Int
    let name: String
}

struct LeaveRequestGroup: Decodable, Identifiable, Hashable {
    let id: Int
    let leaveBatchId: String?
    let studentId: Int?
    let student: LeaveStudentRef?
    let schoolClass: LeaveClassRef?
    let classId: Int?
    let type: String?
    let leaveType: LeaveType?
    let startDate: String?
    let endDate: String?
    let dateRangeText: String?
    let totalDays: FlexibleInt?
    let halfDay: String?
    let halfDayLabel: String?
    let details: PHPObject<AttendanceDetails>?
    let reason: String?
    let note: String?
    let scene: String?
    let hasEveningStudy: FlexibleBool?
    let periodIds: [Int]?
    let periodLabel: String?
    let regularPeriodLabel: String?
    let eveningStudyLabel: String?
    let destination: String?
    let images: [String]?
    let status: String
    let approvalStatus: String?
    let approvedAt: String?
    let approverName: String?
    let createdAt: String?
    let recordIds: [Int]?
    let records: [LeaveSubRecord]?

    enum CodingKeys: String, CodingKey {
        case id, student, type, details, reason, note, scene, destination, images, status, records
        case leaveBatchId = "leave_batch_id"
        case studentId = "student_id"
        case schoolClass = "class"
        case classId = "class_id"
        case leaveType = "leave_type"
        case startDate = "start_date"
        case endDate = "end_date"
        case dateRangeText = "date_range_text"
        case totalDays = "total_days"
        case halfDay = "half_day"
        case halfDayLabel = "half_day_label"
        case hasEveningStudy = "has_evening_study"
        case periodIds = "period_ids"
        case periodLabel = "period_label"
        case regularPeriodLabel = "regular_period_label"
        case eveningStudyLabel = "evening_study_label"
        case approvalStatus = "approval_status"
        case approvedAt = "approved_at"
        case approverName = "approver_name"
        case createdAt = "created_at"
        case recordIds = "record_ids"
    }

    var effectiveStatus: String { approvalStatus ?? status }

    var statusDisplayName: String {
        switch effectiveStatus {
        case "pending": return "待审批"
        case "approved": return "已通过"
        case "rejected": return "已驳回"
        default: return effectiveStatus
        }
    }

    var displayDateRange: String {
        if let text = dateRangeText, !text.isEmpty { return text }
        if let s = startDate, let e = endDate {
            return s == e ? s : "\(s) 至 \(e)"
        }
        return startDate ?? "-"
    }

    /// 统一提取请假原因/补充说明（兼容 reason、details.text、details.reason、note），
    /// 解决「特殊」等文本输入型假类在审批页与请假记录页不显示原因的问题
    var displayReason: String? {
        let cleanReason = reason?.trimmingCharacters(in: .whitespacesAndNewlines) ?? ""
        let cleanDetailText = details?.value?.text?.trimmingCharacters(in: .whitespacesAndNewlines) ?? ""
        let cleanDetailReason = details?.value?.reason?.trimmingCharacters(in: .whitespacesAndNewlines) ?? ""
        let cleanNote = note?.trimmingCharacters(in: .whitespacesAndNewlines) ?? ""
        let cleanDetailNote = details?.value?.note?.trimmingCharacters(in: .whitespacesAndNewlines) ?? ""

        let primaryReason = !cleanReason.isEmpty ? cleanReason : cleanDetailReason
        let primaryText = cleanDetailText

        if !primaryText.isEmpty && !primaryReason.isEmpty {
            if primaryText == primaryReason || primaryReason.contains(primaryText) {
                return primaryReason
            }
            if primaryText.contains(primaryReason) {
                return primaryText
            }
            return "\(primaryText) · \(primaryReason)"
        }
        if !primaryReason.isEmpty { return primaryReason }
        if !primaryText.isEmpty { return primaryText }
        if !cleanNote.isEmpty { return cleanNote }
        if !cleanDetailNote.isEmpty { return cleanDetailNote }
        return nil
    }
}

struct LeaveRequestsPaginationMeta: Decodable {
    let currentPage: Int
    let lastPage: Int
    let perPage: Int
    let total: Int
    let hasMore: Bool

    enum CodingKeys: String, CodingKey {
        case currentPage = "current_page"
        case lastPage = "last_page"
        case perPage = "per_page"
        case total
        case hasMore = "has_more"
    }
}

struct LeaveRequestsListResponse: Decodable {
    let data: [LeaveRequestGroup]
    let total: Int?
    let meta: LeaveRequestsPaginationMeta?
}

struct LeaveRequestsPageResult {
    let items: [LeaveRequestGroup]
    let currentPage: Int
    let lastPage: Int
    let total: Int
    let hasMore: Bool
    let serverPaginated: Bool
}

struct LeaveImageSettings: Decodable {
    let enabled: Bool
    let maxCount: Int
    let maxSizeMB: Int
    let allowedFormats: String?

    enum CodingKeys: String, CodingKey {
        case enabled
        case maxCount = "max_count"
        case maxSizeMB = "max_size_mb"
        case allowedFormats = "allowed_formats"
    }
}

struct LeaveImageUploadResponse: Decodable {
    let path: String
    let url: String?
}
