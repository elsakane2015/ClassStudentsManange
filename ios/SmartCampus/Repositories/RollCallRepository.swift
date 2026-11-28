import Foundation

enum RollCallRepository {
    static func fetchRollCallTypes(classId: Int? = nil) async throws -> [RollCallType] {
        var query: [String: String?] = [:]
        if let classId { query["class_id"] = String(classId) }
        return try await APIClient.shared.request("/roll-call-types", query: query)
    }

    static func createRollCallType(
        classId: Int,
        name: String,
        description: String? = nil,
        absentStatus: String = "absent",
        leaveTypeId: Int? = nil,
        periodIds: [Int] = [],
        isActive: Bool = true
    ) async throws -> RollCallType {
        var body: [String: Any] = [
            "class_id": classId,
            "name": name.trimmingCharacters(in: .whitespacesAndNewlines),
            "absent_status": absentStatus,
            "is_active": isActive,
            "period_ids": periodIds.isEmpty ? NSNull() : periodIds
        ]
        if let desc = description?.trimmingCharacters(in: .whitespacesAndNewlines), !desc.isEmpty {
            body["description"] = desc
        }
        if let leaveTypeId {
            body["leave_type_id"] = leaveTypeId
        }
        return try await APIClient.shared.requestWithJSONBody(
            "/roll-call-types",
            method: "POST",
            jsonBody: body
        )
    }

    static func batchCreateRollCallTypes(
        classIds: [Int],
        name: String,
        description: String? = nil,
        absentStatus: String = "absent",
        leaveTypeId: Int? = nil,
        periodIds: [Int] = [],
        isActive: Bool = true
    ) async throws -> MessageResponse {
        var body: [String: Any] = [
            "class_ids": classIds,
            "name": name.trimmingCharacters(in: .whitespacesAndNewlines),
            "absent_status": absentStatus,
            "is_active": isActive,
            "period_ids": periodIds.isEmpty ? NSNull() : periodIds
        ]
        if let desc = description?.trimmingCharacters(in: .whitespacesAndNewlines), !desc.isEmpty {
            body["description"] = desc
        }
        if let leaveTypeId {
            body["leave_type_id"] = leaveTypeId
        }
        return try await APIClient.shared.requestWithJSONBody(
            "/roll-call-types/batch",
            method: "POST",
            jsonBody: body
        )
    }

    static func updateRollCallType(
        id: Int,
        name: String,
        description: String? = nil,
        absentStatus: String = "absent",
        leaveTypeId: Int? = nil,
        periodIds: [Int] = [],
        isActive: Bool = true
    ) async throws -> RollCallType {
        var body: [String: Any] = [
            "name": name.trimmingCharacters(in: .whitespacesAndNewlines),
            "absent_status": absentStatus,
            "is_active": isActive,
            "period_ids": periodIds.isEmpty ? NSNull() : periodIds,
            "leave_type_id": leaveTypeId ?? NSNull(),
            "description": description?.trimmingCharacters(in: .whitespacesAndNewlines) ?? ""
        ]
        return try await APIClient.shared.requestWithJSONBody(
            "/roll-call-types/\(id)",
            method: "PUT",
            jsonBody: body
        )
    }

    static func deleteRollCallType(id: Int) async throws {
        let _: MessageResponse = try await APIClient.shared.request(
            "/roll-call-types/\(id)",
            method: "DELETE"
        )
    }

    static func fetchInProgress() async throws -> [RollCall] {
        try await APIClient.shared.request("/roll-calls/in-progress")
    }

    static func fetchHistory(
        scope: String = "month",
        status: String? = nil,
        classId: Int? = nil,
        page: Int = 1
    ) async throws -> LaravelPaginator<RollCall> {
        var query: [String: String?] = [
            "scope": scope,
            "page": String(page)
        ]
        if let status, !status.isEmpty, status != "all" { query["status"] = status }
        if let classId { query["class_id"] = String(classId) }
        return try await APIClient.shared.request("/roll-calls", query: query)
    }

    static func fetchStats(scope: String = "today", classId: Int? = nil) async throws -> [RollCallStatsItem] {
        var query: [String: String?] = ["scope": scope]
        if let classId { query["class_id"] = String(classId) }
        return try await APIClient.shared.request("/roll-calls/stats", query: query)
    }

    static func startRollCall(
        rollCallTypeId: Int,
        rollCallTime: String,
        notes: String? = nil
    ) async throws -> RollCall {
        var body: [String: Any] = [
            "roll_call_type_id": rollCallTypeId,
            "roll_call_time": rollCallTime
        ]
        if let notes, !notes.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
            body["notes"] = notes
        }
        return try await APIClient.shared.requestWithJSONBody(
            "/roll-calls",
            method: "POST",
            jsonBody: body
        )
    }

    static func fetchRollCallDetail(id: Int) async throws -> RollCall {
        try await APIClient.shared.request("/roll-calls/\(id)")
    }

    static func completeRollCall(
        id: Int,
        records: [[String: Any]]
    ) async throws -> RollCall {
        try await APIClient.shared.requestWithJSONBody(
            "/roll-calls/\(id)/complete",
            method: "POST",
            jsonBody: ["records": records]
        )
    }

    static func cancelRollCall(id: Int) async throws {
        let _: MessageResponse = try await APIClient.shared.request(
            "/roll-calls/\(id)/cancel",
            method: "POST"
        )
    }

    static func restoreRollCall(id: Int) async throws {
        let _: MessageResponse = try await APIClient.shared.request(
            "/roll-calls/\(id)/restore",
            method: "POST"
        )
    }

    static func deleteRollCall(id: Int) async throws {
        let _: MessageResponse = try await APIClient.shared.request(
            "/roll-calls/\(id)",
            method: "DELETE"
        )
    }

    static func updateRecord(
        rollCallId: Int,
        recordId: Int,
        status: String,
        leaveTypeId: Int? = nil
    ) async throws -> RollCallRecord {
        var body: [String: Any] = ["status": status]
        if let leaveTypeId {
            body["leave_type_id"] = leaveTypeId
        }
        return try await APIClient.shared.requestWithJSONBody(
            "/roll-calls/\(rollCallId)/records/\(recordId)",
            method: "PUT",
            jsonBody: body
        )
    }

    static func fetchRollCallAdmins(classId: Int) async throws -> [RollCallAdminItem] {
        try await APIClient.shared.request("/roll-call-admins", query: ["class_id": String(classId)])
    }

    static func saveRollCallAdmin(
        classId: Int,
        studentId: Int,
        typeIds: [Int],
        canModifyRecords: Bool
    ) async throws -> RollCallAdminItem {
        try await APIClient.shared.requestWithJSONBody(
            "/roll-call-admins",
            method: "POST",
            jsonBody: [
                "class_id": classId,
                "student_id": studentId,
                "roll_call_type_ids": typeIds,
                "can_modify_records": canModifyRecords
            ]
        )
    }

    static func deleteRollCallAdmin(id: Int) async throws {
        let _: MessageResponse = try await APIClient.shared.request(
            "/roll-call-admins/\(id)",
            method: "DELETE"
        )
    }
}
