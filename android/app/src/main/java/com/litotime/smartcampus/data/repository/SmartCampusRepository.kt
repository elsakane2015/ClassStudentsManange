package com.litotime.smartcampus.data.repository

import android.net.Uri
import com.litotime.smartcampus.core.network.ApiClient
import com.litotime.smartcampus.core.util.arrOrEmpty
import com.litotime.smartcampus.core.util.asArrayOrEmpty
import com.litotime.smartcampus.core.util.asObjectOrNull
import com.litotime.smartcampus.core.util.boolVal
import com.litotime.smartcampus.core.util.intVal
import com.litotime.smartcampus.core.util.objOrNull
import com.litotime.smartcampus.core.util.str
import com.litotime.smartcampus.data.model.*
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonArray
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import java.io.File

class SmartCampusRepository(private val api: ApiClient) {

    // MARK: - Basic Options

    suspend fun fetchLeaveTypes(): List<LeaveType> =
        api.requestElement("/leave-types").asArrayOrEmpty().mapNotNull { it.asObjectOrNull()?.let(LeaveType::fromJson) }

    suspend fun fetchTimeSlots(): List<TimeSlot> =
        api.requestElement("/time-slots").asArrayOrEmpty().mapNotNull { it.asObjectOrNull()?.let(TimeSlot::fromJson) }

    suspend fun fetchClassPeriods(): List<ClassPeriod> =
        api.requestElement("/class-periods").asArrayOrEmpty().mapNotNull { it.asObjectOrNull()?.let(ClassPeriod::fromJson) }

    suspend fun fetchSemesters(): List<Semester> =
        api.requestElement("/semesters").asArrayOrEmpty().mapNotNull { it.asObjectOrNull()?.let(Semester::fromJson) }

    suspend fun fetchClasses(): List<ClassOptionItem> =
        api.requestElement("/options/classes").asArrayOrEmpty().mapNotNull { it.asObjectOrNull()?.let(ClassOptionItem::fromJson) }

    // MARK: - Student Attendance

    suspend fun fetchStudentStats(scope: String): StudentStats {
        val obj = api.requestElement("/student/stats", query = mapOf("scope" to scope)).asObjectOrNull()
            ?: JsonObject(emptyMap())
        return StudentStats.fromJson(obj)
    }

    suspend fun fetchStudentCalendar(start: String, end: String): List<AttendanceRecord> {
        val obj = api.requestElement("/calendar", query = mapOf("start" to start, "end" to end)).asObjectOrNull()
        return obj?.arrOrEmpty("attendance")?.mapNotNull { it.asObjectOrNull()?.let(AttendanceRecord::fromJson) }.orEmpty()
    }

    suspend fun fetchStudentDetails(scope: String, status: String): List<StudentDetailItem> {
        val obj = api.requestElement("/student/details", query = mapOf("scope" to scope, "status" to status)).asObjectOrNull()
        return obj?.arrOrEmpty("records")?.mapNotNull { it.asObjectOrNull()?.let(StudentDetailItem::fromJson) }.orEmpty()
    }

    // MARK: - Teacher Dashboard & Attendance Management

    suspend fun fetchTeacherStats(scope: String, semesterId: Int? = null): TeacherStats {
        val q = buildMap<String, String?> {
            put("scope", scope)
            semesterId?.let { put("semester_id", it.toString()) }
        }
        val obj = api.requestElement("/attendance/stats", query = q).asObjectOrNull() ?: JsonObject(emptyMap())
        return TeacherStats.fromJson(obj)
    }

    suspend fun fetchAttendanceOverview(date: String): List<OverviewDepartment> =
        api.requestElement("/attendance/overview", query = mapOf("date" to date, "include_evening" to "0"))
            .asArrayOrEmpty()
            .mapNotNull { it.asObjectOrNull()?.let(OverviewDepartment::fromJson) }

    suspend fun fetchCalendarSummary(month: String): Map<String, List<CalendarSummaryRecord>> {
        val obj = api.requestElement("/attendance/calendar-summary", query = mapOf("month" to month))
            .asObjectOrNull() ?: return emptyMap()
        val result = mutableMapOf<String, List<CalendarSummaryRecord>>()
        obj.forEach { (dateKey, el) ->
            result[dateKey] = el.asArrayOrEmpty().mapNotNull { it.asObjectOrNull()?.let(CalendarSummaryRecord::fromJson) }
        }
        return result
    }

    suspend fun fetchPresentDetails(scope: String, semesterId: Int? = null): List<PresentDetailStudent> {
        val q = buildMap<String, String?> {
            put("scope", scope)
            put("status", "present")
            semesterId?.let { put("semester_id", it.toString()) }
        }
        val obj = api.requestElement("/attendance/details", query = q).asObjectOrNull()
        return obj?.arrOrEmpty("students")?.mapNotNull { it.asObjectOrNull()?.let(PresentDetailStudent::fromJson) }.orEmpty()
    }

    suspend fun fetchNonPresentDetails(
        scope: String,
        status: String?,
        leaveTypeId: Int? = null,
        semesterId: Int? = null
    ): List<NonPresentDetailStudent> {
        val q = buildMap<String, String?> {
            put("scope", scope)
            status?.takeIf { it.isNotBlank() }?.let { put("status", it) }
            leaveTypeId?.let { put("leave_type_id", it.toString()) }
            semesterId?.let { put("semester_id", it.toString()) }
        }
        return api.requestElement("/attendance/details", query = q)
            .asArrayOrEmpty()
            .mapNotNull { it.asObjectOrNull()?.let(NonPresentDetailStudent::fromJson) }
    }

    suspend fun fetchStudentRecords(
        studentId: Int,
        scope: String,
        startDate: String? = null,
        endDate: String? = null,
        semesterId: Int? = null,
        status: String? = null,
        leaveTypeId: Int? = null
    ): List<AttendanceRecord> {
        val query = mutableMapOf("student_id" to studentId.toString(), "scope" to scope)
        if (scope == "custom") {
            startDate?.let { query["start_date"] = it }
            endDate?.let { query["end_date"] = it }
        }
        semesterId?.let { query["semester_id"] = it.toString() }
        status?.takeIf { it.isNotBlank() }?.let { query["status"] = it }
        leaveTypeId?.let { query["leave_type_id"] = it.toString() }
        return api.requestElement("/attendance/student-records", query = query)
            .asArrayOrEmpty().mapNotNull { it.asObjectOrNull()?.let(AttendanceRecord::fromJson) }
    }

    suspend fun submitBulkAttendance(
        date: String,
        studentId: Int,
        status: String,
        leaveTypeId: Int?,
        periodIds: List<Int>,
        note: String?,
        timeSlotId: Int? = null,
        timeSlotName: String? = null
    ) {
        val body = buildJsonObject {
            put("date", date)
            put("records", buildJsonArray {
                add(buildJsonObject {
                    put("student_id", studentId)
                    put("status", status)
                    leaveTypeId?.let { put("leave_type_id", it) }
                    if (periodIds.isNotEmpty() || !note.isNullOrBlank()) {
                        put("details", buildJsonObject {
                            if (periodIds.isNotEmpty()) {
                                put("period_ids", JsonArray(periodIds.sorted().map { JsonPrimitive(it) }))
                                if (timeSlotId != null && !timeSlotName.isNullOrBlank()) {
                                    put("time_slot_id", timeSlotId)
                                    put("time_slot_name", timeSlotName)
                                    put("is_custom", false)
                                } else {
                                    put("is_custom", true)
                                }
                            }
                            if (!note.isNullOrBlank()) put("text", note.trim())
                        })
                    }
                })
            })
        }
        api.requestRaw("/attendance/bulk", method = "POST", jsonBody = body.toString())
    }

    suspend fun submitBatchAttendance(classId: Int?, date: String, statusMap: Map<Int, String>) {
        val body = buildJsonObject {
            classId?.let { put("class_id", it) }
            put("records", buildJsonArray {
                statusMap.forEach { (studentId, status) ->
                    add(buildJsonObject {
                        put("student_id", studentId)
                        put("date", date)
                        put("status", status)
                    })
                }
            })
        }
        api.requestRaw("/attendance/batch", method = "POST", jsonBody = body.toString())
    }

    suspend fun deleteAttendanceRecord(
        studentId: Int,
        date: String,
        recordId: Int,
        periodId: Int?,
        leaveBatchId: String?,
        sourceType: String? = null,
        scene: String? = null,
        deleteScope: String? = null
    ) {
        val body = buildJsonObject {
            put("student_id", studentId)
            put("date", date)
            put("record_id", recordId)
            periodId?.let { put("period_id", it) }
            leaveBatchId?.let { put("leave_batch_id", it) }
            sourceType?.let { put("source_type", it) }
            scene?.let { put("scene", it) }
            deleteScope?.let { put("delete_scope", it) }
        }
        api.requestRaw("/attendance/records", method = "DELETE", jsonBody = body.toString())
    }

    suspend fun downloadExportExcel(
        scope: String,
        semesterId: Int? = null,
        exportFormat: String = "count",
        studentRange: String = "all"
    ): File {
        val q = buildMap<String, String?> {
            put("scope", scope)
            put("export_format", exportFormat)
            put("student_range", studentRange)
            semesterId?.let { put("semester_id", it.toString()) }
        }
        return api.downloadFile("/attendance/export", query = q, suggestedFilename = "smartcampus_attendance_$scope.xlsx")
    }

    // MARK: - Leave Requests & Images

    suspend fun fetchLeaveRequests(status: String? = null): List<LeaveRequestGroup> {
        val q = if (status.isNullOrBlank() || status == "all") emptyMap() else mapOf("status" to status)
        val obj = api.requestElement("/leave-requests", query = q).asObjectOrNull()
        return obj?.arrOrEmpty("data")?.mapNotNull { it.asObjectOrNull()?.let(LeaveRequestGroup::fromJson) }.orEmpty()
    }

    suspend fun fetchLeaveRequestsPage(
        status: String? = null,
        dateFrom: String? = null,
        dateTo: String? = null,
        search: String? = null,
        page: Int = 1,
        perPage: Int = 20
    ): LeaveRequestsPageResult {
        val trimmedSearch = search?.trim().orEmpty()
        val q = buildMap<String, String?> {
            put("page", page.coerceAtLeast(1).toString())
            put("per_page", perPage.coerceAtLeast(1).toString())
            if (!status.isNullOrBlank() && status != "all") put("status", status)
            if (!dateFrom.isNullOrBlank()) put("date_from", dateFrom)
            if (!dateTo.isNullOrBlank()) put("date_to", dateTo)
            if (trimmedSearch.isNotEmpty()) put("search", trimmedSearch)
        }
        val obj = api.requestElement("/leave-requests", query = q).asObjectOrNull()
        val rawItems = obj?.arrOrEmpty("data")?.mapNotNull { it.asObjectOrNull()?.let(LeaveRequestGroup::fromJson) }.orEmpty()
        val meta = obj?.objOrNull("meta")

        if (meta != null) {
            return LeaveRequestsPageResult(
                items = rawItems,
                currentPage = meta.intVal("current_page", page),
                lastPage = meta.intVal("last_page", 1),
                total = meta.intVal("total", rawItems.size),
                hasMore = meta.boolVal("has_more", false),
                serverPaginated = true
            )
        }

        // 兼容尚未升级分页字段的旧服务端
        val fallbackItems = if (trimmedSearch.isEmpty()) {
            rawItems
        } else {
            val lower = trimmedSearch.lowercase()
            rawItems.filter {
                it.studentName.lowercase().contains(lower) ||
                    (it.studentNo ?: "").lowercase().contains(lower) ||
                    (it.className ?: "").lowercase().contains(lower)
            }
        }
        return LeaveRequestsPageResult(
            items = fallbackItems,
            currentPage = 1,
            lastPage = 1,
            total = fallbackItems.size,
            hasMore = false,
            serverPaginated = false
        )
    }

    suspend fun fetchLeaveImageSettings(): LeaveImageSettings {
        val obj = api.requestElement("/leave-image/settings").asObjectOrNull() ?: return LeaveImageSettings()
        return LeaveImageSettings.fromJson(obj)
    }

    suspend fun uploadLeaveImage(uri: Uri, maxSizeMB: Int): String {
        val obj = api.uploadImage("/leave-image/upload", uri, maxSizeMB)
        return obj.str("path") ?: throw IllegalStateException("未返回图片路径")
    }

    suspend fun deleteLeaveImage(path: String) {
        val body = buildJsonObject { put("path", path) }
        api.requestRaw("/leave-image", method = "DELETE", jsonBody = body.toString())
    }

    suspend fun submitLeaveRequest(payload: JsonObject) {
        api.requestRaw("/leave-requests", method = "POST", jsonBody = payload.toString())
    }

    suspend fun cancelLeaveRequest(id: Int) {
        api.requestRaw("/leave-requests/$id", method = "DELETE")
    }

    suspend fun approveLeaveRequest(
        id: Int,
        recordIds: List<Int>? = null,
        leaveTypeId: Int? = null
    ) {
        val body = buildJsonObject {
            if (!recordIds.isNullOrEmpty()) {
                put("record_ids", JsonArray(recordIds.map { JsonPrimitive(it) }))
            }
            if (leaveTypeId != null) {
                put("leave_type_id", leaveTypeId)
            }
        }
        api.requestRaw("/leave-requests/$id/approve", method = "POST", jsonBody = body.toString())
    }

    suspend fun rejectLeaveRequest(
        id: Int,
        reason: String?,
        recordIds: List<Int>? = null
    ) {
        val body = buildJsonObject {
            if (!recordIds.isNullOrEmpty()) {
                put("record_ids", JsonArray(recordIds.map { JsonPrimitive(it) }))
            }
            if (!reason.isNullOrBlank()) put("reason", reason.trim())
        }
        api.requestRaw("/leave-requests/$id/reject", method = "POST", jsonBody = body.toString())
    }

    // MARK: - Roll Calls

    suspend fun fetchRollCallTypes(): List<RollCallType> =
        api.requestElement("/roll-call-types").asArrayOrEmpty().mapNotNull { it.asObjectOrNull()?.let(RollCallType::fromJson) }

    suspend fun fetchInProgressRollCalls(): List<RollCall> =
        api.requestElement("/roll-calls/in-progress").asArrayOrEmpty().mapNotNull { it.asObjectOrNull()?.let(RollCall::fromJson) }

    suspend fun fetchRollCallHistory(scope: String = "month"): List<RollCall> {
        val el = api.requestElement("/roll-calls", query = mapOf("scope" to scope))
        val arr = el.asObjectOrNull()?.arrOrEmpty("data") ?: el.asArrayOrEmpty()
        return arr.mapNotNull { it.asObjectOrNull()?.let(RollCall::fromJson) }
    }

    suspend fun startRollCall(typeId: Int, rollCallTime: String, notes: String?): RollCall {
        val body = buildJsonObject {
            put("roll_call_type_id", typeId)
            put("roll_call_time", rollCallTime)
            if (!notes.isNullOrBlank()) put("notes", notes.trim())
        }
        val obj = api.requestElement("/roll-calls", method = "POST", jsonBody = body.toString()).asObjectOrNull()
            ?: JsonObject(emptyMap())
        return RollCall.fromJson(obj)
    }

    suspend fun fetchRollCallDetail(id: Int): RollCall {
        val obj = api.requestElement("/roll-calls/$id").asObjectOrNull() ?: JsonObject(emptyMap())
        return RollCall.fromJson(obj)
    }

    suspend fun completeRollCall(id: Int, records: List<RollCallRecord>): RollCall {
        val body = buildJsonObject {
            put("records", buildJsonArray {
                records.forEach { rec ->
                    add(buildJsonObject {
                        put("student_id", rec.studentId)
                        put("status", rec.status)
                        put("marked_at", rec.markedAt)
                    })
                }
            })
        }
        val obj = api.requestElement("/roll-calls/$id/complete", method = "POST", jsonBody = body.toString())
            .asObjectOrNull() ?: JsonObject(emptyMap())
        return RollCall.fromJson(obj)
    }

    suspend fun updateRollCallRecord(
        rollCallId: Int,
        recordId: Int,
        status: String,
        leaveTypeId: Int? = null
    ) {
        val body = buildJsonObject {
            put("status", status)
            if (leaveTypeId != null) {
                put("leave_type_id", leaveTypeId)
            }
        }
        api.requestRaw("/roll-calls/$rollCallId/records/$recordId", method = "PUT", jsonBody = body.toString())
    }

    suspend fun cancelRollCall(id: Int) {
        api.requestRaw("/roll-calls/$id/cancel", method = "POST")
    }

    suspend fun restoreRollCall(id: Int) {
        api.requestRaw("/roll-calls/$id/restore", method = "POST")
    }

    suspend fun deleteRollCall(id: Int) {
        api.requestRaw("/roll-calls/$id", method = "DELETE")
    }

    suspend fun fetchRollCallAdmins(classId: Int): List<RollCallAdminItem> =
        api.requestElement("/roll-call-admins", query = mapOf("class_id" to classId.toString()))
            .asArrayOrEmpty()
            .mapNotNull { it.asObjectOrNull()?.let(RollCallAdminItem::fromJson) }

    suspend fun deleteRollCallAdmin(id: Int) {
        api.requestRaw("/roll-call-admins/$id", method = "DELETE")
    }

    // MARK: - Evening Study & Boarding Suspensions & Students

    suspend fun fetchEveningPeriods(): List<ClassPeriod> =
        api.requestElement("/evening-study/periods").asArrayOrEmpty().mapNotNull { it.asObjectOrNull()?.let(ClassPeriod::fromJson) }

    suspend fun fetchEveningStatuses(studentRequestableOnly: Boolean = false): List<EveningStudyStatus> {
        val q = if (studentRequestableOnly) mapOf("student_requestable" to "1") else emptyMap()
        val obj = api.requestElement("/evening-study-statuses", query = q).asObjectOrNull()
        return obj?.arrOrEmpty("statuses")?.mapNotNull { it.asObjectOrNull()?.let(EveningStudyStatus::fromJson) }.orEmpty()
    }

    suspend fun fetchEveningClasses(date: String, periodId: Int, isTakeView: Boolean): List<EveningStudyClassItem> =
        api.requestElement(
            "/evening-study/classes",
            query = mapOf("date" to date, "period_id" to periodId.toString(), "take" to if (isTakeView) "1" else "0")
        ).asArrayOrEmpty().mapNotNull { it.asObjectOrNull()?.let(EveningStudyClassItem::fromJson) }

    suspend fun fetchEveningSummary(date: String, periodId: Int, isTakeView: Boolean): EveningStudySummary? {
        val obj = api.requestElement(
            "/evening-study/summary",
            query = mapOf("date" to date, "period_id" to periodId.toString(), "take" to if (isTakeView) "1" else "0")
        ).asObjectOrNull() ?: return null
        return EveningStudySummary.fromJson(obj)
    }

    suspend fun startEveningSession(date: String, periodId: Int, classId: Int): EveningStudySessionPayload {
        val body = buildJsonObject {
            put("date", date)
            put("period_id", periodId)
            put("class_id", classId)
        }
        val obj = api.requestElement("/evening-study/sessions", method = "POST", jsonBody = body.toString())
            .asObjectOrNull() ?: JsonObject(emptyMap())
        return EveningStudySessionPayload.fromJson(obj)
    }

    suspend fun fetchEveningSession(id: Int): EveningStudySessionPayload {
        val obj = api.requestElement("/evening-study/sessions/$id").asObjectOrNull() ?: JsonObject(emptyMap())
        return EveningStudySessionPayload.fromJson(obj)
    }

    suspend fun updateEveningRecords(sessionId: Int, records: List<EveningStudyRecordItem>, fallbackStatusId: Int): EveningStudySessionPayload {
        val body = buildJsonObject {
            put("records", buildJsonArray {
                records.forEach { rec ->
                    add(buildJsonObject {
                        put("id", rec.id)
                        put("status_id", rec.eveningStudyStatusId ?: fallbackStatusId)
                        rec.destination?.takeIf { it.isNotBlank() }?.let { put("destination", it) }
                        rec.note?.takeIf { it.isNotBlank() }?.let { put("note", it) }
                    })
                }
            })
        }
        val obj = api.requestElement("/evening-study/sessions/$sessionId/records", method = "PUT", jsonBody = body.toString())
            .asObjectOrNull() ?: JsonObject(emptyMap())
        return EveningStudySessionPayload.fromJson(obj)
    }

    suspend fun completeEveningSession(
        sessionId: Int,
        notes: String?,
        records: List<EveningStudyRecordItem>,
        fallbackStatusId: Int
    ): EveningStudySessionPayload {
        val body = buildJsonObject {
            if (!notes.isNullOrBlank()) put("notes", notes.trim())
            put("records", buildJsonArray {
                records.forEach { rec ->
                    add(buildJsonObject {
                        put("id", rec.id)
                        put("status_id", rec.eveningStudyStatusId ?: fallbackStatusId)
                        rec.destination?.takeIf { it.isNotBlank() }?.let { put("destination", it) }
                        rec.note?.takeIf { it.isNotBlank() }?.let { put("note", it) }
                    })
                }
            })
        }
        val obj = api.requestElement("/evening-study/sessions/$sessionId/complete", method = "POST", jsonBody = body.toString())
            .asObjectOrNull() ?: JsonObject(emptyMap())
        return EveningStudySessionPayload.fromJson(obj)
    }

    suspend fun reopenEveningSession(sessionId: Int): EveningStudySessionPayload {
        val obj = api.requestElement("/evening-study/sessions/$sessionId/reopen", method = "POST")
            .asObjectOrNull() ?: JsonObject(emptyMap())
        return EveningStudySessionPayload.fromJson(obj)
    }

    suspend fun deleteEveningSession(sessionId: Int): EveningStudyDeleteResult {
        val obj = api.requestElement("/evening-study/sessions/$sessionId", method = "DELETE")
            .asObjectOrNull()
        return EveningStudyDeleteResult(
            message = obj?.str("message") ?: "点名记录已删除",
            deletedRecordCount = obj?.intVal("deleted_record_count", 0) ?: 0,
            retainedLeaveCount = obj?.intVal("retained_leave_count", 0) ?: 0
        )
    }

    suspend fun fetchEveningHistory(isTakeView: Boolean): List<EveningStudySession> {
        val obj = api.requestElement("/evening-study/history", query = mapOf("take" to if (isTakeView) "1" else "0"))
            .asObjectOrNull()
        return obj?.arrOrEmpty("data")?.mapNotNull { it.asObjectOrNull()?.let(EveningStudySession::fromJson) }.orEmpty()
    }

    suspend fun fetchStudents(classId: Int? = null): List<StudentListItem> {
        val q = buildMap<String, String?> {
            put("per_page", "200")
            classId?.let { put("class_id", it.toString()) }
        }
        val obj = api.requestElement("/students", query = q).asObjectOrNull()
        return obj?.arrOrEmpty("data")?.mapNotNull { it.asObjectOrNull()?.let(StudentListItem::fromJson) }.orEmpty()
    }

    suspend fun toggleClassAdmin(studentId: Int): Boolean {
        val obj = api.requestElement("/students/$studentId/toggle-class-admin", method = "POST").asObjectOrNull()
        return obj?.boolVal("is_class_admin") ?: false
    }

    suspend fun updateStudentBasicInfo(
        studentId: Int,
        name: String,
        studentNo: String,
        gender: String,
        isBoarding: Boolean,
        email: String,
        parentContact: String,
        parentEmail: String,
        password: String?
    ): String? {
        val trimmedContact = parentContact.trim().ifEmpty { null }
        val trimmedParentEmail = parentEmail.trim().ifEmpty { null }
        val trimmedPassword = password?.trim()?.ifEmpty { null }
        val body = buildJsonObject {
            put("name", name.trim())
            put("student_no", studentNo.trim())
            put("gender", gender)
            put("is_boarding", isBoarding)
            put("email", email.trim())
            put("parent_contact", trimmedContact)
            put("parent_email", trimmedParentEmail)
            if (trimmedPassword != null) {
                put("password", trimmedPassword)
            }
        }
        val obj = api.requestElement("/students/$studentId", method = "PUT", jsonBody = body.toString()).asObjectOrNull()
        return obj?.str("parent_email")
    }

    suspend fun fetchSuspensions(studentId: Int): List<BoardingSuspension> =
        api.requestElement(
            "/boarding-suspensions",
            query = mapOf("student_id" to studentId.toString(), "include_revoked" to "1")
        ).asArrayOrEmpty().mapNotNull { it.asObjectOrNull()?.let(BoardingSuspension::fromJson) }

    suspend fun createSuspension(studentId: Int, startDate: String, endDate: String, reason: String, destination: String?) {
        val body = buildJsonObject {
            put("student_id", studentId)
            put("start_date", startDate)
            put("end_date", endDate)
            put("reason", reason.trim())
            if (!destination.isNullOrBlank()) put("destination", destination.trim())
        }
        api.requestRaw("/boarding-suspensions", method = "POST", jsonBody = body.toString())
    }

    suspend fun revokeSuspension(id: Int, reason: String) {
        val body = buildJsonObject { put("reason", reason.trim()) }
        api.requestRaw("/boarding-suspensions/$id/revoke", method = "POST", jsonBody = body.toString())
    }

    // MARK: - Notifications & Account

    suspend fun fetchNotifications(): NotificationsPage {
        val obj = api.requestElement("/notifications", query = mapOf("per_page" to "30")).asObjectOrNull()
        val items = obj?.arrOrEmpty("data")?.mapNotNull { it.asObjectOrNull()?.let(AppNotificationItem::fromJson) }.orEmpty()
        return NotificationsPage(items = items, unreadCount = obj?.intVal("unread_count") ?: 0)
    }

    suspend fun markNotificationRead(id: Int) {
        api.requestRaw("/notifications/$id/read", method = "POST")
    }

    suspend fun markAllNotificationsRead() {
        api.requestRaw("/notifications/read-all", method = "POST")
    }

    suspend fun deleteNotification(id: Int) {
        api.requestRaw("/notifications/$id", method = "DELETE")
    }

    suspend fun fetchDevices(): List<MobileDeviceItem> {
        val obj = api.requestElement("/devices").asObjectOrNull()
        return obj?.arrOrEmpty("data")?.mapNotNull { it.asObjectOrNull()?.let(MobileDeviceItem::fromJson) }.orEmpty()
    }

    suspend fun fetchTeacherWechatConfig(): TeacherWechatConfigState? {
        val obj = api.requestElement("/wechat/teacher-config").asObjectOrNull() ?: return null
        return TeacherWechatConfigState.fromJson(obj)
    }

    suspend fun toggleTeacherWechatPush(enabled: Boolean): Pair<Boolean, String> {
        val body = buildJsonObject { put("push_enabled", enabled) }
        val obj = api.requestElement("/wechat/teacher-config/toggle", method = "POST", jsonBody = body.toString()).asObjectOrNull()
        val finalEnabled = obj?.boolVal("push_enabled", enabled) ?: enabled
        val msg = obj?.str("message") ?: if (finalEnabled) "已开启公众号推送" else "已关闭公众号推送"
        return finalEnabled to msg
    }

    suspend fun toggleTeacherWechatBindingPush(bindingId: Int, enabled: Boolean): Pair<Boolean, String> {
        val body = buildJsonObject { put("push_enabled", enabled) }
        val obj = api.requestElement("/wechat/teacher-binding/$bindingId/toggle", method = "POST", jsonBody = body.toString()).asObjectOrNull()
        val finalEnabled = obj?.boolVal("push_enabled", enabled) ?: enabled
        val msg = obj?.str("message") ?: if (finalEnabled) "已开启该用户的微信推送" else "已关闭该用户的微信推送"
        return finalEnabled to msg
    }

    suspend fun changePassword(currentPassword: String, newPassword: String, confirmPassword: String) {
        val body = buildJsonObject {
            put("current_password", currentPassword)
            put("new_password", newPassword)
            put("new_password_confirmation", confirmPassword)
        }
        api.requestRaw("/user/change-password", method = "POST", jsonBody = body.toString())
    }
}
