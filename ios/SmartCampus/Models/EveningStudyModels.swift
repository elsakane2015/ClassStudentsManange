import Foundation

struct StatusCountItem: Decodable, Identifiable, Hashable {
    var id: String { "\(name)_\(color)" }
    let name: String
    let color: String
    let count: Int
}

struct EveningStudyStatus: Decodable, Identifiable, Hashable {
    let id: Int
    let name: String
    let color: String
    let baseStatus: String
    let isDefault: FlexibleBool?
    let studentRequestable: FlexibleBool?
    let leaveTypeId: Int?
    let isActive: FlexibleBool?
    let sortOrder: Int?

    enum CodingKeys: String, CodingKey {
        case id, name, color
        case baseStatus = "base_status"
        case isDefault = "is_default"
        case studentRequestable = "student_requestable"
        case leaveTypeId = "leave_type_id"
        case isActive = "is_active"
        case sortOrder = "sort_order"
    }

    var isActiveFlag: Bool { isActive?.value ?? true }
    var isDefaultFlag: Bool { isDefault?.value ?? false }
    var isStudentRequestableFlag: Bool { studentRequestable?.value ?? false }
}

struct EveningStudyStatusesResponse: Decodable {
    let statuses: [EveningStudyStatus]
    let boardingSuspensionStatusId: Int?

    enum CodingKeys: String, CodingKey {
        case statuses
        case boardingSuspensionStatusId = "boarding_suspension_status_id"
    }
}

struct EveningStudyClassRef: Decodable, Identifiable, Hashable {
    let id: Int
    let name: String
    let department: DepartmentRef?
}

struct SimpleUserRef: Decodable, Identifiable, Hashable {
    let id: Int
    let name: String
    let email: String?
}

struct BoardingSuspension: Decodable, Identifiable, Hashable {
    let id: Int
    let studentId: Int
    let classId: Int?
    let startDate: String
    let endDate: String
    let reason: String
    let destination: String?
    let revokedAt: String?
    let revokeReason: String?
    let creator: SimpleUserRef?
    let revoker: SimpleUserRef?

    enum CodingKeys: String, CodingKey {
        case id, reason, destination, creator, revoker
        case studentId = "student_id"
        case classId = "class_id"
        case startDate = "start_date"
        case endDate = "end_date"
        case revokedAt = "revoked_at"
        case revokeReason = "revoke_reason"
    }

    var isRevoked: Bool { revokedAt != nil && !(revokedAt?.isEmpty ?? true) }
    var startDateDisplay: String { String(startDate.prefix(10)) }
    var endDateDisplay: String { String(endDate.prefix(10)) }
}

struct EveningStudySession: Decodable, Identifiable, Hashable {
    let id: Int
    let classId: Int
    let departmentId: Int?
    let periodId: Int
    let createdBy: Int?
    let periodNameSnapshot: String?
    let attendanceDate: String?
    let status: String // in_progress, completed
    let totalStudents: Int
    let normalCount: Int
    let exceptionCount: Int
    let notes: String?
    let completedAt: String?
    let recordedAt: String?
    let statusCounts: [StatusCountItem]?
    let schoolClass: EveningStudyClassRef?
    let department: DepartmentRef?
    let creator: SimpleUserRef?

    enum CodingKeys: String, CodingKey {
        case id, status, notes, department, creator
        case classId = "class_id"
        case departmentId = "department_id"
        case periodId = "period_id"
        case createdBy = "created_by"
        case periodNameSnapshot = "period_name_snapshot"
        case attendanceDate = "attendance_date"
        case totalStudents = "total_students"
        case normalCount = "normal_count"
        case exceptionCount = "exception_count"
        case completedAt = "completed_at"
        case recordedAt = "recorded_at"
        case statusCounts = "status_counts"
        case schoolClass = "school_class"
    }

    var isCompleted: Bool { status == "completed" }
    var dateDisplay: String { String((attendanceDate ?? "").prefix(10)) }
}

struct EveningStudyDeleteResponse: Decodable {
    let message: String?
    let deletedRecordCount: Int?
    let retainedLeaveCount: Int?

    enum CodingKeys: String, CodingKey {
        case message
        case deletedRecordCount = "deleted_record_count"
        case retainedLeaveCount = "retained_leave_count"
    }
}

struct EveningStudyClassItem: Decodable, Identifiable, Hashable {
    let id: Int
    let name: String
    let department: DepartmentRef?
    let boardingCount: Int
    let session: EveningStudySession?

    enum CodingKeys: String, CodingKey {
        case id, name, department, session
        case boardingCount = "boarding_count"
    }
}

struct EveningStudySummaryStats: Decodable {
    let classCount: Int
    let startedClassCount: Int
    let completedClassCount: Int
    let expectedCount: Int
    let recordedCount: Int
    let presentCount: Int
    let statusCounts: [StatusCountItem]?

    enum CodingKeys: String, CodingKey {
        case classCount = "class_count"
        case startedClassCount = "started_class_count"
        case completedClassCount = "completed_class_count"
        case expectedCount = "expected_count"
        case recordedCount = "recorded_count"
        case presentCount = "present_count"
        case statusCounts = "status_counts"
    }
}

struct EveningStudyDepartmentSummary: Decodable, Identifiable {
    var id: String { "\(departmentId ?? -1)_\(departmentName)" }
    let departmentId: Int?
    let departmentName: String
    let classCount: Int
    let startedClassCount: Int
    let completedClassCount: Int
    let expectedCount: Int
    let recordedCount: Int
    let presentCount: Int
    let statusCounts: [StatusCountItem]?

    enum CodingKeys: String, CodingKey {
        case departmentId = "department_id"
        case departmentName = "department_name"
        case classCount = "class_count"
        case startedClassCount = "started_class_count"
        case completedClassCount = "completed_class_count"
        case expectedCount = "expected_count"
        case recordedCount = "recorded_count"
        case presentCount = "present_count"
        case statusCounts = "status_counts"
    }
}

struct EveningStudySummaryResponse: Decodable {
    let date: String
    let period: ClassPeriod?
    let scopeType: String?
    let overall: EveningStudySummaryStats
    let departments: [EveningStudyDepartmentSummary]?

    enum CodingKeys: String, CodingKey {
        case date, period, overall, departments
        case scopeType = "scope_type"
    }
}

struct EveningStudyStudentRef: Decodable, Hashable {
    let id: Int
    let studentNo: String?
    let isBoarding: FlexibleBool?
    let user: SimpleUserRef?

    enum CodingKeys: String, CodingKey {
        case id, user
        case studentNo = "student_no"
        case isBoarding = "is_boarding"
    }

    var name: String { user?.name ?? "学生 #\(id)" }
}

struct EveningStudyRecordItem: Decodable, Identifiable, Hashable {
    let id: Int
    let studentId: Int
    var eveningStudyStatusId: Int?
    let requestedEveningStatusId: Int?
    var destination: String?
    var note: String?
    let approvalStatus: String?
    let isSelfApplied: FlexibleBool?
    let boardingSuspensionId: Int?
    let student: EveningStudyStudentRef?
    let eveningStudyStatus: NamedStatusRef?
    let requestedEveningStatus: NamedStatusRef?
    let boardingSuspension: BoardingSuspension?

    enum CodingKeys: String, CodingKey {
        case id, destination, note, student
        case studentId = "student_id"
        case eveningStudyStatusId = "evening_study_status_id"
        case requestedEveningStatusId = "requested_evening_status_id"
        case approvalStatus = "approval_status"
        case isSelfApplied = "is_self_applied"
        case boardingSuspensionId = "boarding_suspension_id"
        case eveningStudyStatus = "evening_study_status"
        case requestedEveningStatus = "requested_evening_status"
        case boardingSuspension = "boarding_suspension"
    }
}

struct EveningStudySessionPayload: Decodable {
    let session: EveningStudySession
    let records: [EveningStudyRecordItem]
}

struct StudentListItem: Decodable, Identifiable, Hashable {
    let id: Int
    var name: String
    var studentNo: String?
    var gender: String?
    var isBoarding: FlexibleBool?
    var parentContact: String?
    var parentEmail: String?
    let classId: Int?
    let className: String?
    let departmentName: String?
    let enrollmentYear: FlexibleString?
    var email: String?
    var isClassAdmin: FlexibleBool?
    let activeBoardingSuspension: BoardingSuspension?

    enum CodingKeys: String, CodingKey {
        case id, name, gender, email
        case studentNo = "student_no"
        case isBoarding = "is_boarding"
        case parentContact = "parent_contact"
        case parentEmail = "parent_email"
        case classId = "class_id"
        case className = "class_name"
        case departmentName = "department_name"
        case enrollmentYear = "enrollment_year"
        case isClassAdmin = "is_class_admin"
        case activeBoardingSuspension = "active_boarding_suspension"
    }

    var isBoardingFlag: Bool { isBoarding?.value ?? false }
    var isClassAdminFlag: Bool { isClassAdmin?.value ?? false }
    var normalizedGender: String {
        switch gender {
        case "female", "other": return gender ?? "male"
        default: return "male"
        }
    }
    var genderDisplayText: String {
        switch normalizedGender {
        case "female": return "女"
        case "other": return "其他"
        default: return "男"
        }
    }
}

struct StudentListMeta: Decodable {
    let currentPage: Int
    let lastPage: Int
    let perPage: Int
    let total: Int

    enum CodingKeys: String, CodingKey {
        case currentPage = "current_page"
        case lastPage = "last_page"
        case perPage = "per_page"
        case total
    }
}

struct StudentListResponse: Decodable {
    let data: [StudentListItem]
    let meta: StudentListMeta?
}

struct ClassOptionItem: Decodable, Identifiable, Hashable {
    let id: Int
    let name: String
    let enrollmentYear: FlexibleInt?
    let departmentId: Int?
    let isGraduated: FlexibleBool?

    enum CodingKeys: String, CodingKey {
        case id, name
        case enrollmentYear = "enrollment_year"
        case departmentId = "department_id"
        case isGraduated = "is_graduated"
    }
}
