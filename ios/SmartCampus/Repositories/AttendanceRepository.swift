import Foundation

enum AttendanceRepository {
    static func fetchLeaveTypes() async throws -> [LeaveType] {
        try await APIClient.shared.request("/leave-types")
    }

    static func fetchTimeSlots() async throws -> [TimeSlot] {
        try await APIClient.shared.request("/time-slots")
    }

    static func fetchClassPeriods() async throws -> [ClassPeriod] {
        try await APIClient.shared.request("/class-periods")
    }

    static func fetchSemesters() async throws -> [Semester] {
        try await APIClient.shared.request("/semesters")
    }

    static func fetchDepartments() async throws -> [DepartmentRef] {
        try await APIClient.shared.request("/options/departments")
    }

    static func fetchClasses(departmentId: Int? = nil) async throws -> [ClassOptionItem] {
        var query: [String: String?] = [:]
        if let departmentId { query["department_id"] = String(departmentId) }
        return try await APIClient.shared.request("/options/classes", query: query)
    }

    // MARK: - Student Endpoints

    static func fetchStudentCalendar(start: String, end: String) async throws -> StudentCalendarResponse {
        try await APIClient.shared.request("/calendar", query: ["start": start, "end": end])
    }

    static func fetchStudentStats(scope: String) async throws -> StudentStatsResponse {
        try await APIClient.shared.request("/student/stats", query: ["scope": scope])
    }

    static func fetchStudentDetails(scope: String, status: String) async throws -> StudentDetailsResponse {
        try await APIClient.shared.request("/student/details", query: ["scope": scope, "status": status])
    }

    // MARK: - Teacher & Admin Dashboard Endpoints

    static func fetchTeacherStats(scope: String, semesterId: Int? = nil) async throws -> TeacherStatsResponse {
        var query: [String: String?] = ["scope": scope]
        if let semesterId { query["semester_id"] = String(semesterId) }
        return try await APIClient.shared.request("/attendance/stats", query: query)
    }

    static func fetchAttendanceOverview(date: String, includeEvening: Bool = false) async throws -> [AttendanceOverviewDepartment] {
        try await APIClient.shared.request(
            "/attendance/overview",
            query: [
                "date": date,
                "include_evening": includeEvening ? "1" : "0"
            ]
        )
    }

    static func fetchCalendarSummary(month: String) async throws -> [String: [CalendarSummaryRecord]] {
        let map: PHPMap<[CalendarSummaryRecord]> = try await APIClient.shared.request(
            "/attendance/calendar-summary",
            query: ["month": month]
        )
        return map.dictionary
    }

    static func fetchPresentDetails(
        scope: String,
        semesterId: Int? = nil,
        departmentId: Int? = nil,
        classId: Int? = nil,
        filterType: String = "all"
    ) async throws -> PresentDetailsResponse {
        var query: [String: String?] = [
            "scope": scope,
            "status": "present",
            "filter_type": filterType
        ]
        if let semesterId { query["semester_id"] = String(semesterId) }
        if let departmentId { query["department_id"] = String(departmentId) }
        if let classId { query["class_id"] = String(classId) }
        return try await APIClient.shared.request("/attendance/details", query: query)
    }

    static func fetchNonPresentDetails(
        scope: String,
        status: String?,
        leaveTypeId: Int? = nil,
        semesterId: Int? = nil
    ) async throws -> [NonPresentDetailStudent] {
        var query: [String: String?] = ["scope": scope]
        if let status, !status.isEmpty { query["status"] = status }
        if let leaveTypeId { query["leave_type_id"] = String(leaveTypeId) }
        if let semesterId { query["semester_id"] = String(semesterId) }
        return try await APIClient.shared.request("/attendance/details", query: query)
    }

    static func fetchStudentRecords(
        studentId: Int,
        scope: String,
        startDate: String? = nil,
        endDate: String? = nil
    ) async throws -> [AttendanceRecord] {
        var query: [String: String?] = [
            "student_id": String(studentId),
            "scope": scope
        ]
        if scope == "custom" {
            query["start_date"] = startDate
            query["end_date"] = endDate
        }
        return try await APIClient.shared.request("/attendance/student-records", query: query)
    }

    // MARK: - Recording & Deleting

    static func submitBulkAttendance(
        date: String,
        records: [[String: Any]]
    ) async throws {
        let payload: [String: Any] = [
            "date": date,
            "records": records
        ]
        let _: MessageResponse = try await APIClient.shared.requestWithJSONBody(
            "/attendance/bulk",
            method: "POST",
            jsonBody: payload
        )
    }

    static func submitBatchAttendance(
        classId: Int?,
        records: [[String: Any]]
    ) async throws {
        var payload: [String: Any] = ["records": records]
        if let classId { payload["class_id"] = classId }
        let _: MessageResponse = try await APIClient.shared.requestWithJSONBody(
            "/attendance/batch",
            method: "POST",
            jsonBody: payload
        )
    }

    static func deleteAttendanceRecord(
        studentId: Int,
        date: String,
        recordId: Int? = nil,
        periodId: Int? = nil,
        leaveBatchId: String? = nil
    ) async throws {
        var payload: [String: Any] = [
            "student_id": studentId,
            "date": date
        ]
        if let recordId { payload["record_id"] = recordId }
        if let periodId { payload["period_id"] = periodId }
        if let leaveBatchId { payload["leave_batch_id"] = leaveBatchId }

        let _: MessageResponse = try await APIClient.shared.requestWithJSONBody(
            "/attendance/records",
            method: "DELETE",
            jsonBody: payload
        )
    }

    // MARK: - Export

    static func fetchExportOptions() async throws -> ExportOptionsResponse {
        try await APIClient.shared.request("/attendance/export-options")
    }

    static func downloadExportExcel(
        scope: String,
        semesterId: Int? = nil,
        startDate: String? = nil,
        endDate: String? = nil,
        exportFormat: String = "count",
        studentRange: String = "all"
    ) async throws -> URL {
        var query: [String: String?] = [
            "scope": scope,
            "export_format": exportFormat,
            "student_range": studentRange
        ]
        if let semesterId { query["semester_id"] = String(semesterId) }
        if let startDate { query["start_date"] = startDate }
        if let endDate { query["end_date"] = endDate }
        return try await APIClient.shared.downloadFile(
            "/attendance/export",
            query: query,
            suggestedFilename: "smartcampus_attendance_\(scope).xlsx"
        )
    }
}
