import Foundation

struct LeaveTypeOption: Decodable, Identifiable, Hashable {
    var id: String { key }
    let key: String
    let label: String
    let isDefault: Bool?
    let fullDay: Bool?

    enum CodingKeys: String, CodingKey {
        case key, label
        case isDefault = "is_default"
        case fullDay = "full_day"
    }

    init(key: String, label: String, isDefault: Bool? = nil, fullDay: Bool? = nil) {
        self.key = key
        self.label = label
        self.isDefault = isDefault
        self.fullDay = fullDay
    }

    init(from decoder: Decoder) throws {
        if let str = try? decoder.singleValueContainer().decode(String.self) {
            self.key = str
            self.label = str
            self.isDefault = nil
            self.fullDay = nil
            return
        }
        let c = try decoder.container(keyedBy: CodingKeys.self)
        let k = (try? c.decode(String.self, forKey: .key)) ?? UUID().uuidString
        self.key = k
        self.label = (try? c.decode(String.self, forKey: .label)) ?? k
        self.isDefault = (try? c.decode(FlexibleBool.self, forKey: .isDefault))?.value
        self.fullDay = (try? c.decode(FlexibleBool.self, forKey: .fullDay))?.value
    }
}

struct LeaveTypeInputConfig: Decodable, Hashable {
    let options: [LeaveTypeOption]?
    let format: String?
    let maxPeriods: FlexibleInt?
    let placeholder: String?

    enum CodingKeys: String, CodingKey {
        case options, format, placeholder
        case maxPeriods = "max_periods"
    }
}

struct LeaveType: Decodable, Identifiable, Hashable {
    let id: Int
    let schoolId: Int?
    let name: String
    let slug: String
    let description: String?
    let inputType: String?
    let inputConfig: PHPObject<LeaveTypeInputConfig>?
    let isActive: FlexibleBool?
    let studentRequestable: FlexibleBool?
    let genderRestriction: String?
    let color: String?
    let displayUnit: String?
    let useConversion: FlexibleBool?
    let countsAsAbsence: FlexibleBool?
    let limitDays: FlexibleInt?
    let limitTimes: FlexibleInt?

    enum CodingKeys: String, CodingKey {
        case id, name, slug, description, color
        case schoolId = "school_id"
        case inputType = "input_type"
        case inputConfig = "input_config"
        case isActive = "is_active"
        case studentRequestable = "student_requestable"
        case genderRestriction = "gender_restriction"
        case displayUnit = "display_unit"
        case useConversion = "use_conversion"
        case countsAsAbsence = "counts_as_absence"
        case limitDays = "limit_days"
        case limitTimes = "limit_times"
    }

    var isActiveFlag: Bool { isActive?.value ?? true }
    var isStudentRequestable: Bool { studentRequestable?.value ?? false }
    var options: [LeaveTypeOption] { inputConfig?.value?.options ?? [] }
}

struct TimeSlot: Decodable, Identifiable, Hashable {
    let id: Int
    let name: String
    let timeStart: String?
    let timeEnd: String?
    let periodIds: [Int]?
    let sortOrder: Int?
    let isActive: FlexibleBool?

    enum CodingKeys: String, CodingKey {
        case id, name
        case timeStart = "time_start"
        case timeEnd = "time_end"
        case periodIds = "period_ids"
        case sortOrder = "sort_order"
        case isActive = "is_active"
    }
}

struct ClassPeriod: Decodable, Identifiable, Hashable {
    let id: Int
    let name: String
    let type: String?
    let order: Int?
    let audienceScope: String?
    let scene: String?
    let countsInDayStats: FlexibleBool?
    let isActive: FlexibleBool?

    enum CodingKeys: String, CodingKey {
        case id, name, type, order, scene
        case audienceScope = "audience_scope"
        case countsInDayStats = "counts_in_day_stats"
        case isActive = "is_active"
    }

    var isEveningStudy: Bool { scene == "evening_study" }
    var isBoardingOnly: Bool { audienceScope == "boarding" }
    var isActiveFlag: Bool { isActive?.value ?? true }
}

struct Semester: Decodable, Identifiable, Hashable {
    let id: Int
    let name: String
    let startDate: String?
    let totalWeeks: Int?
    let holidays: [String]?
    let isCurrent: FlexibleBool?

    enum CodingKeys: String, CodingKey {
        case id, name, holidays
        case startDate = "start_date"
        case totalWeeks = "total_weeks"
        case isCurrent = "is_current"
    }

    var isCurrentFlag: Bool { isCurrent?.value ?? false }
}

struct CalendarSummaryRecord: Decodable, Identifiable, Hashable {
    let id: Int
    let type: String
    let option: String?
    let studentName: String
    let studentNo: String?
    let time: String?
    let status: String
    let isSelfApplied: FlexibleBool?
    let approvalStatus: String?

    enum CodingKeys: String, CodingKey {
        case id, type, option, time, status
        case studentName = "student_name"
        case studentNo = "student_no"
        case isSelfApplied = "is_self_applied"
        case approvalStatus = "approval_status"
    }

    var approvalPrefix: String {
        guard isSelfApplied?.value == true else { return "" }
        switch approvalStatus {
        case "pending": return "待审:"
        case "approved": return "批准:"
        case "rejected": return "驳回:"
        default: return ""
        }
    }

    var typeWithOption: String {
        if let opt = option?.trimmingCharacters(in: .whitespacesAndNewlines), !opt.isEmpty {
            return "\(type)(\(opt))"
        }
        return type
    }

    var studentShortLabel: String {
        if let no = studentNo, no.count >= 2 {
            return "\(no.suffix(2)) \(studentName)"
        }
        return studentName
    }
}

struct AttendanceDetails: Decodable, Hashable {
    let option: String?
    let optionLabel: String?
    let optionPeriods: FlexibleInt?
    let periodIds: [Int]?
    let periodNames: [String]?
    let timeSlotId: FlexibleInt?
    let timeSlotName: String?
    let displayLabel: String?
    let text: String?
    let rollCallType: String?
    let rollCallTime: String?

    enum CodingKeys: String, CodingKey {
        case option, text
        case optionLabel = "option_label"
        case optionPeriods = "option_periods"
        case periodIds = "period_ids"
        case periodNames = "period_names"
        case timeSlotId = "time_slot_id"
        case timeSlotName = "time_slot_name"
        case displayLabel = "display_label"
        case rollCallType = "roll_call_type"
        case rollCallTime = "roll_call_time"
    }
}

struct NamedStatusRef: Decodable, Identifiable, Hashable {
    let id: Int
    let name: String
    let color: String?
    let baseStatus: String?

    enum CodingKeys: String, CodingKey {
        case id, name, color
        case baseStatus = "base_status"
    }
}

struct AttendanceRecord: Decodable, Identifiable, Hashable {
    let id: Int
    let studentId: Int?
    let classId: Int?
    let date: String?
    let periodId: Int?
    let scene: String?
    let periodNameSnapshot: String?
    let status: String
    let leaveTypeId: Int?
    let leaveBatchId: String?
    let details: PHPObject<AttendanceDetails>?
    let images: [String]?
    let isSelfApplied: FlexibleBool?
    let approvalStatus: String?
    let rejectionReason: String?
    let reason: String?
    let sourceType: String?
    let note: String?
    let destination: String?
    let leaveType: LeaveType?
    let requestedEveningStatus: NamedStatusRef?
    let eveningStudyStatus: NamedStatusRef?
    let displayLabel: String?
    let detail: String?
    let detailLabel: String?
    let recordTime: String?
    let periodNamesDisplay: String?
    let eveningStudyLabel: String?
    let hasEveningStudy: FlexibleBool?

    enum CodingKeys: String, CodingKey {
        case id, date, scene, status, details, images, reason, note, destination, detail
        case studentId = "student_id"
        case classId = "class_id"
        case periodId = "period_id"
        case periodNameSnapshot = "period_name_snapshot"
        case leaveTypeId = "leave_type_id"
        case leaveBatchId = "leave_batch_id"
        case isSelfApplied = "is_self_applied"
        case approvalStatus = "approval_status"
        case rejectionReason = "rejection_reason"
        case sourceType = "source_type"
        case leaveType = "leave_type"
        case requestedEveningStatus = "requested_evening_status"
        case eveningStudyStatus = "evening_study_status"
        case displayLabel = "display_label"
        case detailLabel = "detail_label"
        case recordTime = "record_time"
        case periodNamesDisplay = "period_names_display"
        case eveningStudyLabel = "evening_study_label"
        case hasEveningStudy = "has_evening_study"
    }

    var dateKey: String {
        guard let date else { return "" }
        return String(date.prefix(10))
    }

    var titleText: String {
        if let name = leaveType?.name, !name.isEmpty { return name }
        if let es = eveningStudyStatus?.name, !es.isEmpty { return es }
        switch status {
        case "present": return "出勤"
        case "absent": return "缺勤"
        case "late": return "迟到"
        case "early_leave": return "早退"
        case "excused", "leave": return "请假"
        default: return status
        }
    }

    var resolvedDetailText: String {
        for candidate in [
            detailLabel,
            periodNamesDisplay,
            displayLabel,
            detail,
            details?.value?.displayLabel,
            details?.value?.timeSlotName,
            details?.value?.optionLabel,
            periodNameSnapshot
        ] {
            if let s = candidate?.trimmingCharacters(in: .whitespacesAndNewlines), !s.isEmpty {
                return s
            }
        }
        return ""
    }

    var badgeColorKey: String {
        leaveType?.color ?? eveningStudyStatus?.color ?? status
    }
}

struct StudentCalendarResponse: Decodable {
    let attendance: [AttendanceRecord]
}

struct StudentStatsLeaveConfig: Decodable {
    let displayUnit: String?
    let useConversion: FlexibleBool?
    let rawCount: FlexibleInt?

    enum CodingKeys: String, CodingKey {
        case displayUnit = "display_unit"
        case useConversion = "use_conversion"
        case rawCount = "raw_count"
    }
}

struct StudentStatsResponse: Decodable {
    let present: Int
    let workingDays: Int
    let absentLessonsAsDay: Int
    let isRestDay: Bool
    let pendingRequests: Int?
    let leaveCounts: [String: Int]
    let leaveConfigs: [String: StudentStatsLeaveConfig]

    private struct DynamicKey: CodingKey {
        var stringValue: String
        var intValue: Int?
        init?(stringValue: String) { self.stringValue = stringValue }
        init?(intValue: Int) { self.intValue = intValue; self.stringValue = "\(intValue)" }
    }

    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: DynamicKey.self)
        let reserved: Set<String> = [
            "present", "working_days", "absent_lessons_as_day",
            "is_rest_day", "pending_requests", "_leave_types_config"
        ]

        func decodeInt(_ key: String) -> Int {
            guard let k = DynamicKey(stringValue: key) else { return 0 }
            return (try? container.decode(FlexibleInt.self, forKey: k).value) ?? 0
        }

        self.present = decodeInt("present")
        self.workingDays = decodeInt("working_days")
        self.absentLessonsAsDay = decodeInt("absent_lessons_as_day")
        self.isRestDay = DynamicKey(stringValue: "is_rest_day")
            .flatMap { try? container.decode(FlexibleBool.self, forKey: $0).value } ?? false
        self.pendingRequests = DynamicKey(stringValue: "pending_requests")
            .flatMap { try? container.decodeIfPresent(FlexibleInt.self, forKey: $0)?.value }
        self.leaveConfigs = DynamicKey(stringValue: "_leave_types_config")
            .flatMap { try? container.decode(PHPMap<StudentStatsLeaveConfig>.self, forKey: $0).dictionary } ?? [:]

        var counts: [String: Int] = [:]
        for key in container.allKeys where !reserved.contains(key.stringValue) {
            if let val = try? container.decode(FlexibleInt.self, forKey: key) {
                counts[key.stringValue] = val.value
            }
        }
        self.leaveCounts = counts
    }
}

struct StudentDetailItem: Decodable, Identifiable {
    let id: Int
    let date: String?
    let time: String?
    let typeName: String?
    let detailLabel: String?
    let note: String?
    let approvalStatus: String?
    let isSelfApplied: FlexibleBool?

    enum CodingKeys: String, CodingKey {
        case id, date, time, note
        case typeName = "type_name"
        case detailLabel = "detail_label"
        case approvalStatus = "approval_status"
        case isSelfApplied = "is_self_applied"
    }
}

struct StudentDetailsResponse: Decodable {
    let status: String?
    let message: String?
    let records: [StudentDetailItem]
}

struct TeacherStatsDetails: Decodable {
    let stats: PHPMap<FlexibleInt>?
    let leaves: PHPMap<FlexibleString>?
}

struct TeacherPeriodStats: Decodable {
    let totalPeriods: FlexibleInt?
    let presentPeriods: FlexibleInt?
    let latePeriods: FlexibleInt?
    let absentPeriods: FlexibleInt?
    let excusedPeriods: FlexibleInt?
    let earlyLeavePeriods: FlexibleInt?
    let fullDayRecords: FlexibleInt?
    let attendanceRate: FlexibleDouble?

    enum CodingKeys: String, CodingKey {
        case totalPeriods = "total_periods"
        case presentPeriods = "present_periods"
        case latePeriods = "late_periods"
        case absentPeriods = "absent_periods"
        case excusedPeriods = "excused_periods"
        case earlyLeavePeriods = "early_leave_periods"
        case fullDayRecords = "full_day_records"
        case attendanceRate = "attendance_rate"
    }
}

struct TeacherStatsResponse: Decodable {
    let totalStudents: FlexibleInt?
    let schoolTotalStudents: FlexibleInt?
    let departmentTotalStudents: FlexibleInt?
    let classTotalStudents: FlexibleInt?
    let presentCount: FlexibleInt?
    let presentStudentsCount: FlexibleDouble?
    let attendanceRate: FlexibleDouble?
    let pendingRequests: FlexibleInt?
    let scope: String?
    let details: TeacherStatsDetails?
    let periodStats: TeacherPeriodStats?

    enum CodingKeys: String, CodingKey {
        case scope, details
        case totalStudents = "total_students"
        case schoolTotalStudents = "school_total_students"
        case departmentTotalStudents = "department_total_students"
        case classTotalStudents = "class_total_students"
        case presentCount = "present_count"
        case presentStudentsCount = "present_students_count"
        case attendanceRate = "attendance_rate"
        case pendingRequests = "pending_requests"
        case periodStats = "period_stats"
    }
}

struct AttendanceOverviewSummary: Decodable, Hashable {
    let hasRecords: FlexibleBool?
    let type: String?
    let defaultStatus: String?
    let periodCount: FlexibleInt?
    let statuses: [String]?

    enum CodingKeys: String, CodingKey {
        case type, statuses
        case hasRecords = "has_records"
        case defaultStatus = "default_status"
        case periodCount = "period_count"
    }
}

struct AttendanceOverviewStudent: Decodable, Identifiable, Hashable {
    let id: Int
    let userId: Int?
    let classId: Int?
    let studentNo: String?
    let gender: String?
    let isClassAdmin: FlexibleBool?
    let isBoarding: FlexibleBool?
    let user: User?
    let attendance: [AttendanceRecord]?
    let attendanceSummary: AttendanceOverviewSummary?

    enum CodingKeys: String, CodingKey {
        case id, gender, user, attendance
        case userId = "user_id"
        case classId = "class_id"
        case studentNo = "student_no"
        case isClassAdmin = "is_class_admin"
        case isBoarding = "is_boarding"
        case attendanceSummary = "attendance_summary"
    }

    var displayName: String { user?.name ?? "学生 #\(id)" }
}

struct AttendanceOverviewClass: Decodable, Identifiable, Hashable {
    let id: Int
    let name: String
    let departmentId: Int?
    let enrollmentYear: FlexibleString?
    let isGraduated: FlexibleBool?
    let students: [AttendanceOverviewStudent]?

    enum CodingKeys: String, CodingKey {
        case id, name, students
        case departmentId = "department_id"
        case enrollmentYear = "enrollment_year"
        case isGraduated = "is_graduated"
    }
}

struct AttendanceOverviewDepartment: Decodable, Identifiable, Hashable {
    var id: String { rawId.value }
    let rawId: FlexibleString
    let name: String
    let classes: [AttendanceOverviewClass]?

    enum CodingKeys: String, CodingKey {
        case rawId = "id"
        case name, classes
    }
}

struct PresentDetailStudent: Decodable, Identifiable {
    let id: Int
    let studentNo: String?
    let name: String
    let className: String?
    let departmentName: String?
    let presentDays: FlexibleInt?
    let totalDays: FlexibleInt?
    let attendanceRate: FlexibleDouble?
    let isPresent: FlexibleBool?
    let hasRecords: FlexibleBool?

    enum CodingKeys: String, CodingKey {
        case id, name
        case studentNo = "student_no"
        case className = "class_name"
        case departmentName = "department_name"
        case presentDays = "present_days"
        case totalDays = "total_days"
        case attendanceRate = "attendance_rate"
        case isPresent = "is_present"
        case hasRecords = "has_records"
    }
}

struct PresentDetailsResponse: Decodable {
    let students: [PresentDetailStudent]
    let totalDays: FlexibleInt?

    enum CodingKeys: String, CodingKey {
        case students
        case totalDays = "total_days"
    }
}

struct NonPresentDetailStudent: Decodable, Identifiable {
    let id: Int
    let studentNo: String?
    let name: String
    let department: String?
    let className: String?
    let detail: String?
    let records: [AttendanceRecord]?

    enum CodingKeys: String, CodingKey {
        case id, name, department, detail, records
        case studentNo = "student_no"
        case className = "class"
    }
}

struct ExportClassOption: Decodable, Identifiable, Hashable {
    let id: Int
    let name: String
    let department: String?
}

struct ExportLeaveTypeOption: Decodable, Identifiable, Hashable {
    let id: Int
    let name: String
    let slug: String?
}

struct ExportRollCallTypeOption: Decodable, Identifiable, Hashable {
    let id: Int
    let name: String
}

struct ExportOptionsResponse: Decodable {
    let classes: [ExportClassOption]?
    let leaveTypes: [ExportLeaveTypeOption]?
    let rollCallTypes: [ExportRollCallTypeOption]?
    let semesters: [Semester]?

    enum CodingKeys: String, CodingKey {
        case classes, semesters
        case leaveTypes = "leave_types"
        case rollCallTypes = "roll_call_types"
    }
}
