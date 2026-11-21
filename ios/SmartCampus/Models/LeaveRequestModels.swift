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
    let reason: String?
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
        case id, student, type, reason, scene, destination, images, status, records
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
}

struct LeaveRequestsListResponse: Decodable {
    let data: [LeaveRequestGroup]
    let total: Int?
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
