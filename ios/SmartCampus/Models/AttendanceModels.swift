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

    /// 映射为后端 /attendance/bulk 所需的 status（对齐 Web AttendanceUpdateModal）
    var mappedAttendanceStatus: String {
        if ["late", "absent", "early_leave"].contains(slug) {
            return slug
        }
        return "leave"
    }

    /// 考勤类型徽标色值（未配置时按类型语义给醒目默认色）
    var resolvedColorKey: String {
        if let c = color?.trimmingCharacters(in: .whitespacesAndNewlines), !c.isEmpty {
            return c
        }
        if slug == "absent" || name.contains("旷课") || name.contains("缺勤") { return "red" }
        if slug == "late" || name.contains("迟到") { return "orange" }
        if slug == "early_leave" || name.contains("早退") { return "orange" }
        if name.contains("病假") { return "purple" }
        if name.contains("生理") { return "pink" }
        return "blue"
    }
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

    /// 是否为正常出勤/夜自习正常点名记录（不应计入异常汇总，兼容旧版后端返回）
    var isNormalPresentRecord: Bool {
        guard status == "present" else { return false }
        if isSelfApplied?.value == true, let ap = approvalStatus, !ap.isEmpty {
            return false
        }
        let cleanType = type.trimmingCharacters(in: .whitespacesAndNewlines)
        if !cleanType.isEmpty && !["未知", "正常", "出勤"].contains(cleanType) {
            return false
        }
        return true
    }

    var typeWithOption: String {
        let cleanType = type.trimmingCharacters(in: .whitespacesAndNewlines)
        let cleanOpt = option?.trimmingCharacters(in: .whitespacesAndNewlines) ?? ""
        if cleanType.isEmpty || cleanType == "未知" {
            if !cleanOpt.isEmpty {
                return cleanOpt
            }
            switch status {
            case "absent": return "旷课"
            case "late": return "迟到"
            case "early_leave": return "早退"
            case "excused", "leave": return "请假"
            default: return cleanType.isEmpty ? "考勤记录" : cleanType
            }
        }
        if !cleanOpt.isEmpty {
            return "\(cleanType)(\(cleanOpt))"
        }
        return cleanType
    }

    var studentShortLabel: String {
        if let no = studentNo, no.count >= 2 {
            return "\(no.suffix(2)) \(studentName)"
        }
        return studentName
    }

    /// 优先按具体异常类型语义取色（如点名旷课底层 status 为 leave，应醒目标红而非发灰）
    var displayColorKey: String {
        if type.contains("旷课") || type.contains("缺勤") { return "absent" }
        if type.contains("迟到") { return "late" }
        if type.contains("早退") { return "early_leave" }
        if type.contains("病假") { return "purple" }
        if type.contains("生理") { return "pink" }
        return status
    }
}

struct AttendanceDetails: Decodable, Hashable {
    let option: String?
    let optionLabel: String?
    let optionPeriods: FlexibleInt?
    let periodIds: [Int]?
    let periods: [Int]?
    let periodNames: [String]?
    let timeSlotId: FlexibleInt?
    let timeSlotName: String?
    let displayLabel: String?
    let text: String?
    let rollCallType: String?
    let rollCallTime: String?

    enum CodingKeys: String, CodingKey {
        case option, text, periods
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
        SCDateFormatter.normalizeDateKey(date)
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
        if let c = leaveType?.color?.trimmingCharacters(in: .whitespacesAndNewlines), !c.isEmpty {
            return c
        }
        if leaveType?.slug == "absent" || titleText.contains("旷课") || titleText.contains("缺勤") {
            return "absent"
        }
        if leaveType?.slug == "late" || titleText.contains("迟到") {
            return "late"
        }
        if leaveType?.slug == "early_leave" || titleText.contains("早退") {
            return "early_leave"
        }
        if titleText.contains("病假") { return "purple" }
        if titleText.contains("生理") { return "pink" }
        return eveningStudyStatus?.color ?? status
    }

    /// 是否为纯正常出勤/夜自习正常点名记录（学生日历与全班异常登记仅展示请假与异常记录）
    var isNormalPresentRecord: Bool {
        guard status == "present" else { return false }
        if isSelfApplied?.value == true, let ap = approvalStatus, !ap.isEmpty {
            return false
        }
        if leaveTypeId != nil || leaveType != nil {
            return false
        }
        return true
    }

    var sourceBadgeText: String? {
        if isSelfApplied?.value == true || sourceType == "self_applied" || sourceType == "leave_request" {
            return "自请"
        }
        if sourceType == "roll_call" { return "点名" }
        if sourceType == "manual" || sourceType == "manual_bulk" { return "登记" }
        if sourceType == "teacher_evening_leave" { return "夜自习" }
        return nil
    }

    var displayTimeText: String? {
        if let t = details?.value?.rollCallTime, !t.isEmpty { return t }
        if let t = recordTime, !t.isEmpty { return t }
        return nil
    }

    /// 提取本条记录关联的节次 ID（兼容 period_id / details.period_ids / details.periods / 点名类型名匹配）
    func extractedPeriodIds(classPeriods: [ClassPeriod]) -> [Int] {
        var ids = Set<Int>()
        if let pIds = details?.value?.periodIds, !pIds.isEmpty {
            ids.formUnion(pIds)
        }
        if let pIds = details?.value?.periods, !pIds.isEmpty {
            ids.formUnion(pIds)
        }
        if let pid = periodId {
            ids.insert(pid)
        }
        if ids.isEmpty, let rcType = details?.value?.rollCallType?.trimmingCharacters(in: .whitespacesAndNewlines), !rcType.isEmpty {
            if let matched = classPeriods.first(where: { $0.name == rcType || rcType.contains($0.name) }) {
                ids.insert(matched.id)
            }
        }
        return ids.sorted()
    }

    /// 生成精简课节/时段文案（如 "早操" / "第1-2节" / "上午" / "全天"），用于全班登记与当日标记卡片
    func concisePeriodLabel(classPeriods: [ClassPeriod], timeSlots: [TimeSlot]) -> String {
        if let rcType = details?.value?.rollCallType?.trimmingCharacters(in: .whitespacesAndNewlines), !rcType.isEmpty {
            return rcType
        }
        let ids = extractedPeriodIds(classPeriods: classPeriods)
        if !ids.isEmpty {
            let formatted = PeriodFormatter.formatPeriodNames(periodIds: ids, periods: classPeriods, timeSlots: timeSlots)
            if !formatted.isEmpty { return formatted }
        }
        if let pNames = details?.value?.periodNames, !pNames.isEmpty {
            return PeriodFormatter.mergePeriodNames(pNames)
        }
        for candidate in [
            details?.value?.timeSlotName,
            details?.value?.optionLabel,
            details?.value?.displayLabel,
            displayLabel,
            periodNameSnapshot
        ] {
            if let raw = candidate?.trimmingCharacters(in: .whitespacesAndNewlines), !raw.isEmpty {
                // 去掉类似 "旷课(早操)" 中重复的类型前缀
                if raw.hasPrefix(titleText + "(") && raw.hasSuffix(")") {
                    let inner = String(raw.dropFirst(titleText.count + 1).dropLast())
                    if !inner.isEmpty { return inner }
                }
                return raw
            }
        }
        if let opt = details?.value?.option {
            switch opt {
            case "morning_half": return "上午"
            case "afternoon_half": return "下午"
            case "full_day", "all_day": return "全天"
            case "morning_exercise": return "早操"
            case "evening_exercise": return "晚操"
            default: break
            }
        }
        return "全天"
    }
}

/// 合并同一学生当日同类型的多条节次记录，用于「全班登记」清晰展示「类型 · 节次」
struct DayAttendanceBadgeGroup: Identifiable, Hashable {
    let id: String
    let title: String
    let periodSummary: String
    let colorKey: String

    var badgeText: String {
        periodSummary.isEmpty ? title : "\(title) · \(periodSummary)"
    }

    static func build(
        from records: [AttendanceRecord],
        classPeriods: [ClassPeriod],
        timeSlots: [TimeSlot]
    ) -> [DayAttendanceBadgeGroup] {
        let valid = records.filter { !$0.isNormalPresentRecord }
        guard !valid.isEmpty else { return [] }

        var order: [String] = []
        var grouped: [String: [AttendanceRecord]] = [:]
        for rec in valid {
            let key = "\(rec.titleText)|\(rec.leaveTypeId ?? -1)|\(rec.leaveBatchId ?? "")|\(rec.sourceType ?? "")"
            if grouped[key] == nil { order.append(key) }
            grouped[key, default: []].append(rec)
        }

        return order.compactMap { key in
            guard let items = grouped[key], let first = items.first else { return nil }
            let allIds = Array(Set(items.flatMap { $0.extractedPeriodIds(classPeriods: classPeriods) })).sorted()
            let periodText: String
            if !allIds.isEmpty {
                let formatted = PeriodFormatter.formatPeriodNames(periodIds: allIds, periods: classPeriods, timeSlots: timeSlots)
                periodText = formatted.isEmpty ? first.concisePeriodLabel(classPeriods: classPeriods, timeSlots: timeSlots) : formatted
            } else {
                var labels: [String] = []
                for item in items {
                    let lbl = item.concisePeriodLabel(classPeriods: classPeriods, timeSlots: timeSlots)
                    if !lbl.isEmpty && !labels.contains(lbl) { labels.append(lbl) }
                }
                periodText = labels.joined(separator: "、")
            }
            return DayAttendanceBadgeGroup(
                id: key,
                title: first.titleText,
                periodSummary: periodText,
                colorKey: first.badgeColorKey
            )
        }
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
