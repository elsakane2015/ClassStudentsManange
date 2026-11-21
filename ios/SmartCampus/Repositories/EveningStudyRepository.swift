import Foundation

enum EveningStudyRepository {
    static func fetchPeriods() async throws -> [ClassPeriod] {
        try await APIClient.shared.request("/evening-study/periods")
    }

    static func fetchStatuses(studentRequestableOnly: Bool = false) async throws -> EveningStudyStatusesResponse {
        var query: [String: String?] = [:]
        if studentRequestableOnly { query["student_requestable"] = "1" }
        return try await APIClient.shared.request("/evening-study-statuses", query: query)
    }

    static func fetchClasses(date: String, periodId: Int, isTakeView: Bool) async throws -> [EveningStudyClassItem] {
        try await APIClient.shared.request(
            "/evening-study/classes",
            query: [
                "date": date,
                "period_id": String(periodId),
                "take": isTakeView ? "1" : "0"
            ]
        )
    }

    static func fetchSummary(date: String, periodId: Int, isTakeView: Bool) async throws -> EveningStudySummaryResponse {
        try await APIClient.shared.request(
            "/evening-study/summary",
            query: [
                "date": date,
                "period_id": String(periodId),
                "take": isTakeView ? "1" : "0"
            ]
        )
    }

    static func startSession(date: String, periodId: Int, classId: Int) async throws -> EveningStudySessionPayload {
        try await APIClient.shared.requestWithJSONBody(
            "/evening-study/sessions",
            method: "POST",
            jsonBody: [
                "date": date,
                "period_id": periodId,
                "class_id": classId
            ]
        )
    }

    static func fetchSession(id: Int) async throws -> EveningStudySessionPayload {
        try await APIClient.shared.request("/evening-study/sessions/\(id)")
    }

    static func updateSessionRecords(
        sessionId: Int,
        records: [[String: Any]]
    ) async throws -> EveningStudySessionPayload {
        try await APIClient.shared.requestWithJSONBody(
            "/evening-study/sessions/\(sessionId)/records",
            method: "PUT",
            jsonBody: ["records": records]
        )
    }

    static func completeSession(
        sessionId: Int,
        notes: String?,
        records: [[String: Any]]? = nil
    ) async throws -> EveningStudySessionPayload {
        var body: [String: Any] = [:]
        if let notes, !notes.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
            body["notes"] = notes
        }
        if let records, !records.isEmpty {
            body["records"] = records
        }
        return try await APIClient.shared.requestWithJSONBody(
            "/evening-study/sessions/\(sessionId)/complete",
            method: "POST",
            jsonBody: body
        )
    }

    static func reopenSession(sessionId: Int) async throws -> EveningStudySessionPayload {
        try await APIClient.shared.request(
            "/evening-study/sessions/\(sessionId)/reopen",
            method: "POST"
        )
    }

    @discardableResult
    static func deleteSession(sessionId: Int) async throws -> EveningStudyDeleteResponse {
        try await APIClient.shared.request(
            "/evening-study/sessions/\(sessionId)",
            method: "DELETE"
        )
    }

    static func fetchHistory(isTakeView: Bool, page: Int = 1) async throws -> LaravelPaginator<EveningStudySession> {
        try await APIClient.shared.request(
            "/evening-study/history",
            query: [
                "take": isTakeView ? "1" : "0",
                "page": String(page)
            ]
        )
    }

    // MARK: - Boarding Suspensions & Students

    static func fetchSuspensions(studentId: Int, includeRevoked: Bool = true) async throws -> [BoardingSuspension] {
        try await APIClient.shared.request(
            "/boarding-suspensions",
            query: [
                "student_id": String(studentId),
                "include_revoked": includeRevoked ? "1" : "0"
            ]
        )
    }

    static func createSuspension(
        studentId: Int,
        startDate: String,
        endDate: String,
        reason: String,
        destination: String?
    ) async throws -> BoardingSuspension {
        var body: [String: Any] = [
            "student_id": studentId,
            "start_date": startDate,
            "end_date": endDate,
            "reason": reason
        ]
        if let destination, !destination.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
            body["destination"] = destination
        }
        return try await APIClient.shared.requestWithJSONBody(
            "/boarding-suspensions",
            method: "POST",
            jsonBody: body
        )
    }

    static func revokeSuspension(id: Int, reason: String) async throws {
        let _: MessageResponse = try await APIClient.shared.requestWithJSONBody(
            "/boarding-suspensions/\(id)/revoke",
            method: "POST",
            jsonBody: ["reason": reason]
        )
    }

    static func fetchStudents(
        departmentId: Int? = nil,
        classId: Int? = nil,
        perPage: Int = 100,
        page: Int = 1
    ) async throws -> StudentListResponse {
        var query: [String: String?] = [
            "per_page": String(perPage),
            "page": String(page)
        ]
        if let departmentId { query["department_id"] = String(departmentId) }
        if let classId { query["class_id"] = String(classId) }
        return try await APIClient.shared.request("/students", query: query)
    }

    static func toggleClassAdmin(studentId: Int) async throws -> Bool {
        struct ToggleResponse: Decodable {
            let is_class_admin: Bool
        }
        let res: ToggleResponse = try await APIClient.shared.request(
            "/students/\(studentId)/toggle-class-admin",
            method: "POST"
        )
        return res.is_class_admin
    }

    static func updateStudentBasicInfo(
        studentId: Int,
        name: String,
        studentNo: String,
        gender: String,
        isBoarding: Bool,
        email: String,
        parentContact: String,
        parentEmail: String,
        password: String?
    ) async throws -> String? {
        let trimmedContact = parentContact.trimmingCharacters(in: .whitespacesAndNewlines)
        let trimmedParentEmail = parentEmail.trimmingCharacters(in: .whitespacesAndNewlines)

        var payload: [String: Any] = [
            "name": name.trimmingCharacters(in: .whitespacesAndNewlines),
            "student_no": studentNo.trimmingCharacters(in: .whitespacesAndNewlines),
            "gender": gender,
            "is_boarding": isBoarding,
            "email": email.trimmingCharacters(in: .whitespacesAndNewlines),
            "parent_contact": trimmedContact.isEmpty ? NSNull() : trimmedContact,
            "parent_email": trimmedParentEmail.isEmpty ? NSNull() : trimmedParentEmail
        ]
        if let pwd = password?.trimmingCharacters(in: .whitespacesAndNewlines), !pwd.isEmpty {
            payload["password"] = pwd
        }

        struct UpdatedStudentResponse: Decodable {
            let parentEmail: String?
            enum CodingKeys: String, CodingKey {
                case parentEmail = "parent_email"
            }
        }
        let res: UpdatedStudentResponse = try await APIClient.shared.requestWithJSONBody(
            "/students/\(studentId)",
            method: "PUT",
            jsonBody: payload
        )
        return res.parentEmail
    }
}
