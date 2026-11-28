import Foundation

struct RollCallClassRef: Decodable, Identifiable, Hashable {
    let id: Int
    let name: String
    let departmentId: Int?

    enum CodingKeys: String, CodingKey {
        case id, name
        case departmentId = "department_id"
    }
}

struct RollCallType: Decodable, Identifiable, Hashable {
    let id: Int
    let classId: Int?
    let name: String
    let description: String?
    let absentStatus: String?
    private let periodIdsRaw: [FlexibleInt]?
    let leaveTypeId: Int?
    let isActive: FlexibleBool?
    let sortOrder: Int?
    let schoolClass: RollCallClassRef?
    let leaveType: LeaveType?

    enum CodingKeys: String, CodingKey {
        case id, name, description
        case classId = "class_id"
        case absentStatus = "absent_status"
        case periodIdsRaw = "period_ids"
        case leaveTypeId = "leave_type_id"
        case isActive = "is_active"
        case sortOrder = "sort_order"
        case schoolClass = "class"
        case leaveType = "leave_type"
    }

    var isActiveFlag: Bool { isActive?.value ?? true }
    var periodIds: [Int] { (periodIdsRaw ?? []).map(\.value) }

    var absentStatusDisplayName: String {
        switch absentStatus {
        case "late": return "迟到"
        case "leave": return "请假"
        default: return "旷课"
        }
    }

    func periodSummary(using periods: [ClassPeriod]) -> String? {
        let ids = periodIds
        guard !ids.isEmpty else { return nil }
        let map = Dictionary(uniqueKeysWithValues: periods.map { ($0.id, $0.name) })
        let names = ids.compactMap { map[$0] ?? "第\($0)节" }
        return names.isEmpty ? nil : names.joined(separator: "、")
    }
}

enum RollCallPeriodNamingHelper {
    /// 根据多选的节次智能生成建议的点名活动名称（例如：第1节 -> 第1节点名；第1节+第2节 -> 第1-2节点名）
    static func suggestedName(for selectedIds: Set<Int>, in periods: [ClassPeriod]) -> String {
        let ordered = periods.filter { selectedIds.contains($0.id) }
        guard !ordered.isEmpty else { return "" }

        if ordered.count == 1 {
            let n = ordered[0].name.trimmingCharacters(in: .whitespacesAndNewlines)
            return n.hasSuffix("点名") ? n : "\(n)点名"
        }

        // 检查是否全为「第X节」格式
        let nums: [Int] = ordered.compactMap { p in
            let s = p.name.trimmingCharacters(in: .whitespacesAndNewlines)
            guard s.hasPrefix("第"), s.hasSuffix("节") else { return nil }
            let mid = s.dropFirst().dropLast()
            return Int(mid)
        }
        if nums.count == ordered.count {
            let sortedNums = nums.sorted()
            let isContiguous = zip(sortedNums, sortedNums.dropFirst()).allSatisfy { $1 == $0 + 1 }
            if isContiguous, let first = sortedNums.first, let last = sortedNums.last {
                return "第\(first)-\(last)节点名"
            }
            return "第\(sortedNums.map(String.init).joined(separator: "、"))节点名"
        }

        return ordered.map(\.name).joined(separator: "、") + "点名"
    }
}

struct RollCallStudentRef: Decodable, Identifiable, Hashable {
    let id: Int
    let studentNo: String?
    let gender: String?
    let user: User?

    enum CodingKeys: String, CodingKey {
        case id, gender, user
        case studentNo = "student_no"
    }

    var name: String { user?.name ?? "学生 #\(id)" }

    /// 两位数短学号（取尾号两位，一位数字补前导 0）
    var shortStudentNo: String? {
        let trimmed = (studentNo ?? "").trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmed.isEmpty else { return nil }
        if trimmed.count >= 2 {
            return String(trimmed.suffix(2))
        }
        return trimmed.allSatisfy(\.isNumber) ? "0\(trimmed)" : trimmed
    }
}

struct RollCallRecord: Decodable, Identifiable, Hashable {
    let id: Int
    let rollCallId: Int?
    let studentId: Int
    var status: String // pending, present, absent, on_leave
    var leaveTypeId: Int?
    var leaveDetail: String?
    var leaveStatus: String?
    var markedAt: String?
    var markedAtLocal: String?
    let student: RollCallStudentRef?
    var leaveType: LeaveType?

    enum CodingKeys: String, CodingKey {
        case id, status, student
        case rollCallId = "roll_call_id"
        case studentId = "student_id"
        case leaveTypeId = "leave_type_id"
        case leaveDetail = "leave_detail"
        case leaveStatus = "leave_status"
        case markedAt = "marked_at"
        case markedAtLocal = "marked_at_local"
        case leaveType = "leave_type"
    }
}

struct RollCall: Decodable, Identifiable, Hashable {
    let id: Int
    let classId: Int?
    let rollCallTypeId: Int?
    let rollCallTime: String?
    let status: String // in_progress, completed, cancelled
    private let totalStudentsRaw: FlexibleInt?
    private let presentCountRaw: FlexibleInt?
    private let onLeaveCountRaw: FlexibleInt?
    let notes: String?
    let completedAt: String?
    let canModifyRecords: FlexibleBool?
    let rollCallType: RollCallType?
    let schoolClass: RollCallClassRef?
    let creator: User?
    let records: [RollCallRecord]?

    enum CodingKeys: String, CodingKey {
        case id, status, notes, creator, records
        case classId = "class_id"
        case rollCallTypeId = "roll_call_type_id"
        case rollCallTime = "roll_call_time"
        case totalStudentsRaw = "total_students"
        case presentCountRaw = "present_count"
        case onLeaveCountRaw = "on_leave_count"
        case completedAt = "completed_at"
        case canModifyRecords = "can_modify_records"
        case rollCallType = "roll_call_type"
        case schoolClass = "class"
    }

    var totalStudents: Int { totalStudentsRaw?.value ?? (records?.count ?? 0) }
    var presentCount: Int { presentCountRaw?.value ?? 0 }
    var onLeaveCount: Int { onLeaveCountRaw?.value ?? 0 }

    var absentCount: Int {
        max(0, totalStudents - presentCount - onLeaveCount)
    }

    var statusDisplayName: String {
        switch status {
        case "in_progress": return "点名中"
        case "completed": return "已完成"
        case "cancelled": return "已取消"
        default: return status
        }
    }
}

struct RollCallStatsItem: Decodable, Identifiable {
    var id: Int { typeId }
    let typeId: Int
    let typeName: String
    let count: Int
    let absentTotal: FlexibleInt

    enum CodingKeys: String, CodingKey {
        case count
        case typeId = "type_id"
        case typeName = "type_name"
        case absentTotal = "absent_total"
    }
}

struct RollCallAdminItem: Decodable, Identifiable, Hashable {
    let id: Int
    let classId: Int
    let studentId: Int
    let rollCallTypeIds: [Int]?
    let isActive: FlexibleBool?
    let canModifyRecords: FlexibleBool?
    let student: RollCallStudentRef?
    let authorizedTypes: [RollCallType]?

    enum CodingKeys: String, CodingKey {
        case id, student
        case classId = "class_id"
        case studentId = "student_id"
        case rollCallTypeIds = "roll_call_type_ids"
        case isActive = "is_active"
        case canModifyRecords = "can_modify_records"
        case authorizedTypes = "authorized_types"
    }
}
