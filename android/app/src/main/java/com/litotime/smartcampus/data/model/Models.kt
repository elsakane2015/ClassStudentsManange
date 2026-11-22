package com.litotime.smartcampus.data.model

import com.litotime.smartcampus.core.util.PeriodFormatter
import com.litotime.smartcampus.core.util.SCDateFormatter
import com.litotime.smartcampus.core.util.arrOrEmpty
import com.litotime.smartcampus.core.util.asObjectOrNull
import com.litotime.smartcampus.core.util.boolVal
import com.litotime.smartcampus.core.util.doubleVal
import com.litotime.smartcampus.core.util.intList
import com.litotime.smartcampus.core.util.intOrNull
import com.litotime.smartcampus.core.util.intVal
import com.litotime.smartcampus.core.util.objOrNull
import com.litotime.smartcampus.core.util.str
import com.litotime.smartcampus.core.util.strList
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.intOrNull

// MARK: - User & Notifications

data class DepartmentRef(
    val id: Int,
    val name: String
) {
    companion object {
        fun fromJson(o: JsonObject) = DepartmentRef(
            id = o.intVal("id"),
            name = o.str("name").orEmpty()
        )
    }
}

data class StudentProfile(
    val id: Int,
    val userId: Int?,
    val schoolId: Int?,
    val classId: Int?,
    val studentNo: String?,
    val isClassAdmin: Boolean,
    val isRollCallAdmin: Boolean,
    val isBoarding: Boolean,
    val gender: String?
) {
    companion object {
        fun fromJson(o: JsonObject) = StudentProfile(
            id = o.intVal("id"),
            userId = o.intOrNull("user_id"),
            schoolId = o.intOrNull("school_id"),
            classId = o.intOrNull("class_id"),
            studentNo = o.str("student_no"),
            isClassAdmin = o.boolVal("is_class_admin"),
            isRollCallAdmin = o.boolVal("is_roll_call_admin"),
            isBoarding = o.boolVal("is_boarding"),
            gender = o.str("gender")
        )
    }
}

data class User(
    val id: Int,
    val name: String,
    val email: String?,
    val role: String,
    val student: StudentProfile?,
    val dutyDepartments: List<DepartmentRef>,
    val permissions: List<String>,
    val unreadNotificationsCount: Int
) {
    val isStudent: Boolean get() = role == "student"
    val isDutyTeacher: Boolean get() = role == "duty_teacher"
    val isAdminTier: Boolean get() = role in setOf("system_admin", "school_admin", "department_manager", "admin", "manager")
    val isTeacherOrAdmin: Boolean get() = role == "teacher" || isAdminTier
    val isClassAdminStudent: Boolean get() = isStudent && (student?.isClassAdmin == true)
    val isRollCallAdminStudent: Boolean get() = isStudent && (student?.isRollCallAdmin == true)
    val canTakeEveningStudy: Boolean
        get() = role in setOf("duty_teacher", "system_admin", "school_admin", "department_manager", "manager") ||
            dutyDepartments.isNotEmpty()

    val roleDisplayName: String
        get() = when (role) {
            "system_admin" -> "系统管理员"
            "school_admin", "admin" -> "校级管理员"
            "department_manager", "manager" -> "院系管理员"
            "duty_teacher" -> "值班教师"
            "teacher" -> "教师"
            "student" -> when {
                isClassAdminStudent && isRollCallAdminStudent -> "学生（班级考勤员 · 点名员）"
                isClassAdminStudent -> "学生（班级考勤员）"
                isRollCallAdminStudent -> "学生（点名员）"
                else -> "学生"
            }
            else -> role
        }

    fun hasPermission(permission: String): Boolean =
        role == "system_admin" || permissions.contains(permission)

    companion object {
        fun fromJson(o: JsonObject) = User(
            id = o.intVal("id"),
            name = o.str("name").orEmpty(),
            email = o.str("email"),
            role = o.str("role").orEmpty(),
            student = o.objOrNull("student")?.let { StudentProfile.fromJson(it) },
            dutyDepartments = o.arrOrEmpty("duty_departments").mapNotNull { it.asObjectOrNull()?.let(DepartmentRef::fromJson) },
            permissions = o.strList("permissions"),
            unreadNotificationsCount = o.intVal("unread_notifications_count")
        )
    }
}

data class AppNotificationItem(
    val id: Int,
    val type: String,
    val title: String,
    val body: String,
    val route: String?,
    val readAt: String?,
    val createdAt: String?
) {
    val isUnread: Boolean get() = readAt.isNullOrBlank()

    companion object {
        fun fromJson(o: JsonObject) = AppNotificationItem(
            id = o.intVal("id"),
            type = o.str("type").orEmpty(),
            title = o.str("title").orEmpty(),
            body = o.str("body").orEmpty(),
            route = o.objOrNull("data")?.str("route"),
            readAt = o.str("read_at"),
            createdAt = o.str("created_at")
        )
    }
}

data class NotificationsPage(
    val items: List<AppNotificationItem>,
    val unreadCount: Int
)

data class MobileDeviceItem(
    val id: Int,
    val platform: String,
    val deviceName: String?,
    val osVersion: String?,
    val pushToken: String?,
    val lastActiveAt: String?
) {
    companion object {
        fun fromJson(o: JsonObject) = MobileDeviceItem(
            id = o.intVal("id"),
            platform = o.str("platform").orEmpty(),
            deviceName = o.str("device_name"),
            osVersion = o.str("os_version"),
            pushToken = o.str("push_token"),
            lastActiveAt = o.str("last_active_at")
        )
    }
}

// MARK: - Basic Config & Attendance Models

data class LeaveTypeOption(
    val key: String,
    val label: String
)

data class LeaveType(
    val id: Int,
    val name: String,
    val slug: String,
    val description: String?,
    val inputType: String?,
    val options: List<LeaveTypeOption>,
    val placeholder: String?,
    val isActive: Boolean,
    val studentRequestable: Boolean,
    val genderRestriction: String,
    val color: String,
    val displayUnit: String,
    val limitDays: Int?,
    val limitTimes: Int?
) {
    /** 映射为后端 /attendance/bulk 所需的 status（对齐 Web AttendanceUpdateModal） */
    val mappedAttendanceStatus: String
        get() = if (slug in setOf("late", "absent", "early_leave")) slug else "leave"

    /** 考勤类型徽标色值（未配置时按类型语义给醒目默认色） */
    val resolvedColorKey: String
        get() {
            val c = color.trim()
            if (c.isNotEmpty() && c != "blue") return c
            return when {
                slug == "absent" || name.contains("旷课") || name.contains("缺勤") -> "red"
                slug == "late" || name.contains("迟到") -> "orange"
                slug == "early_leave" || name.contains("早退") -> "orange"
                name.contains("病假") -> "purple"
                name.contains("生理") -> "pink"
                c.isNotEmpty() -> c
                else -> "blue"
            }
        }

    companion object {
        fun fromJson(o: JsonObject): LeaveType {
            val config = o.objOrNull("input_config")
            val opts = config?.arrOrEmpty("options")?.mapNotNull { el ->
                when (el) {
                    is JsonPrimitive -> el.content.let { LeaveTypeOption(it, it) }
                    is JsonObject -> {
                        val k = el.str("key") ?: return@mapNotNull null
                        LeaveTypeOption(k, el.str("label") ?: k)
                    }
                    else -> null
                }
            }.orEmpty()
            return LeaveType(
                id = o.intVal("id"),
                name = o.str("name").orEmpty(),
                slug = o.str("slug").orEmpty(),
                description = o.str("description"),
                inputType = o.str("input_type"),
                options = opts,
                placeholder = config?.str("placeholder"),
                isActive = o.boolVal("is_active", true),
                studentRequestable = o.boolVal("student_requestable", false),
                genderRestriction = o.str("gender_restriction") ?: "all",
                color = o.str("color") ?: "blue",
                displayUnit = o.str("display_unit") ?: "次",
                limitDays = o.intOrNull("limit_days"),
                limitTimes = o.intOrNull("limit_times")
            )
        }
    }
}

data class TimeSlot(
    val id: Int,
    val name: String,
    val timeStart: String?,
    val timeEnd: String?,
    val periodIds: List<Int>
) {
    companion object {
        fun fromJson(o: JsonObject) = TimeSlot(
            id = o.intVal("id"),
            name = o.str("name").orEmpty(),
            timeStart = o.str("time_start"),
            timeEnd = o.str("time_end"),
            periodIds = o.intList("period_ids")
        )
    }
}

data class ClassPeriod(
    val id: Int,
    val name: String,
    val scene: String,
    val audienceScope: String,
    val isActive: Boolean
) {
    val isEveningStudy: Boolean get() = scene == "evening_study"
    val isBoardingOnly: Boolean get() = audienceScope == "boarding"

    companion object {
        fun fromJson(o: JsonObject) = ClassPeriod(
            id = o.intVal("id"),
            name = o.str("name").orEmpty(),
            scene = o.str("scene") ?: "regular",
            audienceScope = o.str("audience_scope") ?: "all",
            isActive = o.boolVal("is_active", true)
        )
    }
}

data class Semester(
    val id: Int,
    val name: String,
    val startDate: String?,
    val totalWeeks: Int,
    val holidays: List<String>,
    val isCurrent: Boolean
) {
    companion object {
        fun fromJson(o: JsonObject) = Semester(
            id = o.intVal("id"),
            name = o.str("name").orEmpty(),
            startDate = o.str("start_date"),
            totalWeeks = o.intVal("total_weeks", 20),
            holidays = o.strList("holidays"),
            isCurrent = o.boolVal("is_current", false)
        )
    }
}

data class CalendarSummaryRecord(
    val id: Int,
    val recordIds: List<Int> = emptyList(),
    val studentId: Int? = null,
    val type: String,
    val option: String?,
    val studentName: String,
    val studentNo: String?,
    val time: String?,
    val status: String,
    val isSelfApplied: Boolean,
    val approvalStatus: String?
) {
    val allRecordIds: List<Int>
        get() = if (recordIds.isNotEmpty()) recordIds else listOf(id)
    val approvalPrefix: String
        get() = if (!isSelfApplied) "" else when (approvalStatus) {
            "pending" -> "待审:"
            "approved" -> "批准:"
            "rejected" -> "驳回:"
            else -> ""
        }

    /** 是否为正常出勤/夜自习正常点名记录（不应计入异常汇总，兼容旧版后端返回） */
    val isNormalPresentRecord: Boolean
        get() {
            if (status != "present") return false
            if (isSelfApplied && !approvalStatus.isNullOrBlank()) return false
            val cleanType = type.trim()
            if (cleanType.isNotEmpty() && cleanType !in setOf("未知", "正常", "出勤")) {
                return false
            }
            return true
        }

    val typeWithOption: String
        get() {
            val cleanType = type.trim()
            val cleanOpt = option?.trim().orEmpty()
            if (cleanType.isEmpty() || cleanType == "未知") {
                if (cleanOpt.isNotEmpty()) return cleanOpt
                return when (status) {
                    "absent" -> "旷课"
                    "late" -> "迟到"
                    "early_leave" -> "早退"
                    "excused", "leave" -> "请假"
                    else -> cleanType.ifEmpty { "考勤记录" }
                }
            }
            return if (cleanOpt.isNotEmpty()) "$cleanType($cleanOpt)" else cleanType
        }

    val studentShortLabel: String
        get() = if (!studentNo.isNullOrBlank() && studentNo.length >= 2) {
            "${studentNo.takeLast(2)} $studentName"
        } else studentName

    /** 优先按具体异常类型语义取色（如点名旷课底层 status 为 leave，应醒目标红） */
    val displayColorKey: String
        get() = when {
            type.contains("旷课") || type.contains("缺勤") -> "absent"
            type.contains("迟到") -> "late"
            type.contains("早退") -> "early_leave"
            type.contains("病假") -> "purple"
            type.contains("生理") -> "pink"
            else -> status
        }

    companion object {
        fun fromJson(o: JsonObject) = CalendarSummaryRecord(
            id = o.intVal("id"),
            recordIds = o.intList("record_ids"),
            studentId = o.intOrNull("student_id"),
            type = o.str("type").orEmpty(),
            option = o.str("option"),
            studentName = o.str("student_name").orEmpty(),
            studentNo = o.str("student_no"),
            time = o.str("time"),
            status = o.str("status").orEmpty(),
            isSelfApplied = o.boolVal("is_self_applied"),
            approvalStatus = o.str("approval_status")
        )
    }
}

data class AttendanceRecord(
    val id: Int,
    val studentId: Int?,
    val date: String?,
    val periodId: Int?,
    val status: String,
    val leaveBatchId: String?,
    val approvalStatus: String?,
    val reason: String?,
    val note: String?,
    val leaveTypeName: String?,
    val leaveTypeSlug: String? = null,
    val leaveTypeColor: String?,
    val eveningStudyStatusName: String?,
    val eveningStudyLabel: String?,
    val recordTime: String?,
    val periodIdsInDetails: List<Int>,
    val detailText: String,
    val isSelfApplied: Boolean = false,
    val leaveTypeId: Int? = null,
    val scene: String? = null,
    val sourceType: String? = null,
    val sourceId: Int? = null,
    val periodNameSnapshot: String? = null,
    val rollCallType: String? = null,
    val rollCallTime: String? = null,
    val detailsText: String? = null,
    val detailsOption: String? = null,
    val detailsOptionLabel: String? = null,
    val detailsTimeSlotName: String? = null,
    val detailsDisplayLabel: String? = null,
    val detailsPeriodNames: List<String> = emptyList(),
    val detailsPeriods: List<Int> = emptyList()
) {
    val dateKey: String get() = SCDateFormatter.normalizeDateKey(date)

    val isNormalPresentRecord: Boolean
        get() {
            if (status != "present") return false
            if (isSelfApplied && !approvalStatus.isNullOrBlank()) return false
            if (leaveTypeId != null || !leaveTypeName.isNullOrBlank()) return false
            return true
        }

    val titleText: String
        get() = when {
            !leaveTypeName.isNullOrBlank() -> leaveTypeName
            !eveningStudyStatusName.isNullOrBlank() -> eveningStudyStatusName
            status == "present" -> "出勤"
            status == "absent" -> "缺勤"
            status == "late" -> "迟到"
            status == "early_leave" -> "早退"
            status == "excused" || status == "leave" -> "请假"
            else -> status
        }

    val badgeColorKey: String
        get() {
            val c = leaveTypeColor?.trim()
            if (!c.isNullOrEmpty()) return c
            if (leaveTypeSlug == "absent" || titleText.contains("旷课") || titleText.contains("缺勤")) return "absent"
            if (leaveTypeSlug == "late" || titleText.contains("迟到")) return "late"
            if (leaveTypeSlug == "early_leave" || titleText.contains("早退")) return "early_leave"
            if (titleText.contains("病假")) return "purple"
            if (titleText.contains("生理")) return "pink"
            return status
        }

    val sourceBadgeText: String?
        get() = when {
            isSelfApplied || sourceType == "self_applied" || sourceType == "leave_request" -> "自请"
            sourceType == "roll_call" -> "点名"
            sourceType == "manual" || sourceType == "manual_bulk" -> "登记"
            sourceType == "teacher_evening_leave" -> "夜自习"
            else -> null
        }

    val displayTimeText: String?
        get() = rollCallTime?.takeIf { it.isNotBlank() } ?: recordTime?.takeIf { it.isNotBlank() }

    fun extractedPeriodIds(classPeriods: List<ClassPeriod>): List<Int> {
        val ids = linkedSetOf<Int>()
        ids.addAll(periodIdsInDetails)
        ids.addAll(detailsPeriods)
        periodId?.let { ids.add(it) }
        if (ids.isEmpty() && !rollCallType.isNullOrBlank()) {
            val rc = rollCallType.trim()
            classPeriods.firstOrNull { it.name == rc || rc.contains(it.name) }?.let { ids.add(it.id) }
        }
        return ids.sorted()
    }

    fun concisePeriodLabel(classPeriods: List<ClassPeriod>, timeSlots: List<TimeSlot>): String {
        rollCallType?.trim()?.takeIf { it.isNotEmpty() }?.let { return it }
        val ids = extractedPeriodIds(classPeriods)
        if (ids.isNotEmpty()) {
            val formatted = PeriodFormatter.formatPeriodNames(ids, classPeriods, timeSlots)
            if (formatted.isNotBlank()) return formatted
        }
        if (detailsPeriodNames.isNotEmpty()) {
            val merged = PeriodFormatter.mergePeriodNames(detailsPeriodNames)
            if (merged.isNotBlank()) return merged
        }
        for (candidate in listOf(detailsTimeSlotName, detailsOptionLabel, detailsDisplayLabel, periodNameSnapshot)) {
            val raw = candidate?.trim().orEmpty()
            if (raw.isNotEmpty()) {
                if (raw.startsWith("$titleText(") && raw.endsWith(")")) {
                    val inner = raw.removePrefix("$titleText(").removeSuffix(")")
                    if (inner.isNotBlank()) return inner
                }
                return raw
            }
        }
        return when (detailsOption) {
            "morning_half" -> "上午"
            "afternoon_half" -> "下午"
            "full_day", "all_day" -> "全天"
            "morning_exercise" -> "早操"
            "evening_exercise" -> "晚操"
            else -> "全天"
        }
    }

    companion object {
        fun fromJson(o: JsonObject): AttendanceRecord {
            val details = o.objOrNull("details")
            val lt = o.objOrNull("leave_type")
            val es = o.objOrNull("evening_study_status")
            val detailCandidate = listOfNotNull(
                o.str("detail_label"),
                o.str("period_names_display"),
                o.str("display_label"),
                o.str("detail"),
                details?.str("display_label"),
                details?.str("time_slot_name"),
                details?.str("option_label"),
                o.str("period_name_snapshot")
            ).firstOrNull { it.isNotBlank() }.orEmpty()

            return AttendanceRecord(
                id = o.intVal("id"),
                studentId = o.intOrNull("student_id"),
                date = o.str("date"),
                periodId = o.intOrNull("period_id"),
                status = o.str("status").orEmpty(),
                leaveBatchId = o.str("leave_batch_id"),
                approvalStatus = o.str("approval_status"),
                reason = o.str("reason"),
                note = o.str("note"),
                leaveTypeName = lt?.str("name"),
                leaveTypeSlug = lt?.str("slug"),
                leaveTypeColor = lt?.str("color") ?: es?.str("color"),
                eveningStudyStatusName = es?.str("name"),
                eveningStudyLabel = o.str("evening_study_label"),
                recordTime = o.str("record_time"),
                periodIdsInDetails = details?.intList("period_ids").orEmpty(),
                detailText = detailCandidate,
                isSelfApplied = o.boolVal("is_self_applied"),
                leaveTypeId = o.intOrNull("leave_type_id"),
                scene = o.str("scene"),
                sourceType = o.str("source_type"),
                sourceId = o.intOrNull("source_id"),
                periodNameSnapshot = o.str("period_name_snapshot"),
                rollCallType = details?.str("roll_call_type"),
                rollCallTime = details?.str("roll_call_time"),
                detailsText = details?.str("text"),
                detailsOption = details?.str("option"),
                detailsOptionLabel = details?.str("option_label"),
                detailsTimeSlotName = details?.str("time_slot_name"),
                detailsDisplayLabel = details?.str("display_label") ?: o.str("display_label"),
                detailsPeriodNames = details?.strList("period_names").orEmpty(),
                detailsPeriods = details?.intList("periods").orEmpty()
            )
        }
    }
}

/** 合并同一学生当日同类型的多条节次记录，用于「全班登记」清晰展示「类型 · 节次」 */
data class DayAttendanceBadgeGroup(
    val id: String,
    val title: String,
    val periodSummary: String,
    val colorKey: String
) {
    val badgeText: String get() = if (periodSummary.isBlank()) title else "$title · $periodSummary"

    companion object {
        fun build(
            records: List<AttendanceRecord>,
            classPeriods: List<ClassPeriod>,
            timeSlots: List<TimeSlot>
        ): List<DayAttendanceBadgeGroup> {
            val valid = records.filterNot { it.isNormalPresentRecord }
            if (valid.isEmpty()) return emptyList()
            val grouped = linkedMapOf<String, MutableList<AttendanceRecord>>()
            valid.forEach { rec ->
                val key = "${rec.titleText}|${rec.leaveTypeId ?: -1}|${rec.leaveBatchId.orEmpty()}|${rec.sourceType.orEmpty()}"
                grouped.getOrPut(key) { mutableListOf() }.add(rec)
            }
            return grouped.map { (key, items) ->
                val first = items.first()
                val allIds = items.flatMap { it.extractedPeriodIds(classPeriods) }.distinct().sorted()
                val periodText = if (allIds.isNotEmpty()) {
                    PeriodFormatter.formatPeriodNames(allIds, classPeriods, timeSlots)
                        .ifBlank { first.concisePeriodLabel(classPeriods, timeSlots) }
                } else {
                    items.map { it.concisePeriodLabel(classPeriods, timeSlots) }
                        .filter { it.isNotBlank() }
                        .distinct()
                        .joinToString("、")
                }
                DayAttendanceBadgeGroup(
                    id = key,
                    title = first.titleText,
                    periodSummary = periodText,
                    colorKey = first.badgeColorKey
                )
            }
        }
    }
}

data class StudentStats(
    val present: Int,
    val workingDays: Int,
    val isRestDay: Boolean,
    val leaveCounts: Map<String, Int>,
    val displayUnits: Map<String, String>
) {
    companion object {
        fun fromJson(o: JsonObject): StudentStats {
            val reserved = setOf(
                "present", "working_days", "absent_lessons_as_day",
                "is_rest_day", "pending_requests", "_leave_types_config"
            )
            val counts = mutableMapOf<String, Int>()
            for ((k, v) in o) {
                if (k !in reserved && v is JsonPrimitive) {
                    val iv = v.intOrNull ?: v.content.toIntOrNull()
                    if (iv != null) counts[k] = iv
                }
            }
            val units = mutableMapOf<String, String>()
            o.objOrNull("_leave_types_config")?.forEach { (slug, cfgEl) ->
                cfgEl.asObjectOrNull()?.str("display_unit")?.let { units[slug] = it }
            }
            return StudentStats(
                present = o.intVal("present"),
                workingDays = o.intVal("working_days"),
                isRestDay = o.boolVal("is_rest_day"),
                leaveCounts = counts,
                displayUnits = units
            )
        }
    }
}

data class StudentDetailItem(
    val id: Int,
    val date: String,
    val typeName: String,
    val detailLabel: String,
    val note: String?
) {
    companion object {
        fun fromJson(o: JsonObject) = StudentDetailItem(
            id = o.intVal("id"),
            date = o.str("date").orEmpty(),
            typeName = o.str("type_name").orEmpty(),
            detailLabel = o.str("detail_label").orEmpty(),
            note = o.str("note")
        )
    }
}

data class TeacherStats(
    val totalStudents: Int,
    val schoolTotalStudents: Int,
    val departmentTotalStudents: Int?,
    val classTotalStudents: Int?,
    val presentCount: Int,
    val presentStudentsCount: Double?,
    val attendanceRate: Double,
    val pendingRequests: Int,
    val absentPeriods: Int,
    val latePeriods: Int,
    val leavesBreakdown: Map<String, String>
) {
    companion object {
        fun fromJson(o: JsonObject): TeacherStats {
            val periodStats = o.objOrNull("period_stats")
            val leaves = mutableMapOf<String, String>()
            o.objOrNull("details")?.objOrNull("leaves")?.forEach { (k, v) ->
                (v as? JsonPrimitive)?.content?.let { leaves[k] = it }
            }
            return TeacherStats(
                totalStudents = o.intVal("total_students"),
                schoolTotalStudents = o.intVal("school_total_students"),
                departmentTotalStudents = o.intOrNull("department_total_students"),
                classTotalStudents = o.intOrNull("class_total_students"),
                presentCount = o.intVal("present_count"),
                presentStudentsCount = o["present_students_count"]?.let { o.doubleVal("present_students_count") },
                attendanceRate = o.doubleVal("attendance_rate"),
                pendingRequests = o.intVal("pending_requests"),
                absentPeriods = periodStats?.intVal("absent_periods") ?: 0,
                latePeriods = periodStats?.intVal("late_periods") ?: 0,
                leavesBreakdown = leaves
            )
        }
    }
}

data class OverviewStudent(
    val id: Int,
    val name: String,
    val studentNo: String?,
    val classId: Int?,
    val attendance: List<AttendanceRecord>,
    val hasRecords: Boolean
) {
    companion object {
        fun fromJson(o: JsonObject) = OverviewStudent(
            id = o.intVal("id"),
            name = o.objOrNull("user")?.str("name") ?: "学生 #${o.intVal("id")}",
            studentNo = o.str("student_no"),
            classId = o.intOrNull("class_id"),
            attendance = o.arrOrEmpty("attendance").mapNotNull { it.asObjectOrNull()?.let(AttendanceRecord::fromJson) },
            hasRecords = o.objOrNull("attendance_summary")?.boolVal("has_records") ?: false
        )
    }
}

data class OverviewClass(
    val id: Int,
    val name: String,
    val students: List<OverviewStudent>
) {
    companion object {
        fun fromJson(o: JsonObject) = OverviewClass(
            id = o.intVal("id"),
            name = o.str("name").orEmpty(),
            students = o.arrOrEmpty("students").mapNotNull { it.asObjectOrNull()?.let(OverviewStudent::fromJson) }
        )
    }
}

data class OverviewDepartment(
    val id: String,
    val name: String,
    val classes: List<OverviewClass>
) {
    companion object {
        fun fromJson(o: JsonObject) = OverviewDepartment(
            id = o.str("id") ?: o.intVal("id").toString(),
            name = o.str("name").orEmpty(),
            classes = o.arrOrEmpty("classes").mapNotNull { it.asObjectOrNull()?.let(OverviewClass::fromJson) }
        )
    }
}

data class PresentDetailStudent(
    val id: Int,
    val name: String,
    val studentNo: String?,
    val className: String?,
    val attendanceRate: Double
) {
    companion object {
        fun fromJson(o: JsonObject) = PresentDetailStudent(
            id = o.intVal("id"),
            name = o.str("name").orEmpty(),
            studentNo = o.str("student_no"),
            className = o.str("class_name"),
            attendanceRate = o.doubleVal("attendance_rate")
        )
    }
}

data class NonPresentDetailStudent(
    val id: Int,
    val name: String,
    val className: String?,
    val detail: String?
) {
    companion object {
        fun fromJson(o: JsonObject) = NonPresentDetailStudent(
            id = o.intVal("id"),
            name = o.str("name").orEmpty(),
            className = o.str("class"),
            detail = o.str("detail")
        )
    }
}

// MARK: - Leave Requests

data class LeaveSubRecord(
    val id: Int,
    val date: String,
    val label: String?,
    val scene: String?,
    val approvalStatus: String?,
    val destination: String?
) {
    companion object {
        fun fromJson(o: JsonObject) = LeaveSubRecord(
            id = o.intVal("id"),
            date = o.str("date").orEmpty(),
            label = o.str("label"),
            scene = o.str("scene"),
            approvalStatus = o.str("approval_status"),
            destination = o.str("destination")
        )
    }
}

data class LeaveRequestGroup(
    val id: Int,
    val studentName: String,
    val studentNo: String?,
    val className: String?,
    val leaveTypeName: String,
    val leaveTypeColor: String,
    val dateRangeText: String,
    val totalDays: Int,
    val periodLabel: String?,
    val eveningStudyLabel: String?,
    val destination: String?,
    val reason: String?,
    val images: List<String>,
    val status: String,
    val approverName: String?,
    val approvedAt: String?,
    val createdAt: String?,
    val records: List<LeaveSubRecord>
) {
    val statusDisplayName: String
        get() = when (status) {
            "pending" -> "待审批"
            "approved" -> "已通过"
            "rejected" -> "已驳回"
            else -> status
        }

    companion object {
        fun fromJson(o: JsonObject): LeaveRequestGroup {
            val studentObj = o.objOrNull("student")
            val ltObj = o.objOrNull("leave_type")
            val start = o.str("start_date").orEmpty()
            val end = o.str("end_date").orEmpty()
            val range = o.str("date_range_text").takeUnless { it.isNullOrBlank() }
                ?: if (start == end) start else "$start 至 $end"
            return LeaveRequestGroup(
                id = o.intVal("id"),
                studentName = studentObj?.objOrNull("user")?.str("name") ?: "学生",
                studentNo = studentObj?.str("student_no"),
                className = o.objOrNull("class")?.str("name"),
                leaveTypeName = ltObj?.str("name") ?: "请假",
                leaveTypeColor = ltObj?.str("color") ?: "blue",
                dateRangeText = range,
                totalDays = o.intVal("total_days"),
                periodLabel = o.str("half_day_label") ?: o.str("period_label"),
                eveningStudyLabel = o.str("evening_study_label"),
                destination = o.str("destination"),
                reason = o.str("reason"),
                images = o.strList("images"),
                status = o.str("approval_status") ?: o.str("status") ?: "pending",
                approverName = o.str("approver_name"),
                approvedAt = o.str("approved_at"),
                createdAt = o.str("created_at"),
                records = o.arrOrEmpty("records").mapNotNull { it.asObjectOrNull()?.let(LeaveSubRecord::fromJson) }
            )
        }
    }
}

data class LeaveRequestsPageResult(
    val items: List<LeaveRequestGroup>,
    val currentPage: Int,
    val lastPage: Int,
    val total: Int,
    val hasMore: Boolean,
    val serverPaginated: Boolean
)

data class LeaveImageSettings(
    val enabled: Boolean = true,
    val maxCount: Int = 3,
    val maxSizeMB: Int = 5
) {
    companion object {
        fun fromJson(o: JsonObject) = LeaveImageSettings(
            enabled = o.boolVal("enabled", true),
            maxCount = o.intVal("max_count", 3),
            maxSizeMB = o.intVal("max_size_mb", 5)
        )
    }
}

// MARK: - Roll Calls

data class RollCallType(
    val id: Int,
    val name: String,
    val className: String?,
    val isActive: Boolean
) {
    companion object {
        fun fromJson(o: JsonObject) = RollCallType(
            id = o.intVal("id"),
            name = o.str("name").orEmpty(),
            className = o.objOrNull("class")?.str("name"),
            isActive = o.boolVal("is_active", true)
        )
    }
}

data class RollCallRecord(
    val id: Int,
    val studentId: Int,
    val studentName: String,
    val studentNo: String?,
    val status: String, // pending, present, absent, on_leave
    val leaveTypeName: String?,
    val markedAt: String?,
    val markedAtLocal: String?
) {
    /** 两位数短学号（取尾号两位，一位数字补前导 0） */
    val shortStudentNo: String?
        get() {
            val trimmed = studentNo?.trim().orEmpty()
            if (trimmed.isEmpty()) return null
            if (trimmed.length >= 2) return trimmed.takeLast(2)
            return if (trimmed.all { it.isDigit() }) "0$trimmed" else trimmed
        }

    companion object {
        fun fromJson(o: JsonObject): RollCallRecord {
            val st = o.objOrNull("student")
            return RollCallRecord(
                id = o.intVal("id"),
                studentId = o.intVal("student_id"),
                studentName = st?.objOrNull("user")?.str("name") ?: "学生 #${o.intVal("student_id")}",
                studentNo = st?.str("student_no"),
                status = o.str("status") ?: "pending",
                leaveTypeName = o.objOrNull("leave_type")?.str("name"),
                markedAt = o.str("marked_at"),
                markedAtLocal = o.str("marked_at_local")
            )
        }
    }
}

data class RollCall(
    val id: Int,
    val typeName: String,
    val className: String?,
    val rollCallTime: String?,
    val status: String,
    val totalStudents: Int,
    val presentCount: Int,
    val onLeaveCount: Int,
    val canModifyRecords: Boolean,
    val records: List<RollCallRecord>
) {
    val absentCount: Int get() = (totalStudents - presentCount - onLeaveCount).coerceAtLeast(0)
    val statusDisplayName: String
        get() = when (status) {
            "in_progress" -> "点名中"
            "completed" -> "已完成"
            "cancelled" -> "已取消"
            else -> status
        }

    companion object {
        fun fromJson(o: JsonObject) = RollCall(
            id = o.intVal("id"),
            typeName = o.objOrNull("roll_call_type")?.str("name") ?: "课堂点名",
            className = o.objOrNull("class")?.str("name"),
            rollCallTime = o.str("roll_call_time"),
            status = o.str("status").orEmpty(),
            totalStudents = o.intVal("total_students"),
            presentCount = o.intVal("present_count"),
            onLeaveCount = o.intVal("on_leave_count"),
            canModifyRecords = o.boolVal("can_modify_records"),
            records = o.arrOrEmpty("records").mapNotNull { it.asObjectOrNull()?.let(RollCallRecord::fromJson) }
        )
    }
}

data class RollCallAdminItem(
    val id: Int,
    val studentName: String,
    val canModifyRecords: Boolean,
    val authorizedTypeNames: List<String>
) {
    companion object {
        fun fromJson(o: JsonObject) = RollCallAdminItem(
            id = o.intVal("id"),
            studentName = o.objOrNull("student")?.objOrNull("user")?.str("name") ?: "学生 #${o.intVal("student_id")}",
            canModifyRecords = o.boolVal("can_modify_records"),
            authorizedTypeNames = o.arrOrEmpty("authorized_types").mapNotNull { it.asObjectOrNull()?.str("name") }
        )
    }
}

// MARK: - Evening Study, Boarding Suspensions & Students

data class StatusCountItem(
    val name: String,
    val color: String,
    val count: Int
) {
    companion object {
        fun fromJson(o: JsonObject) = StatusCountItem(
            name = o.str("name").orEmpty(),
            color = o.str("color") ?: "gray",
            count = o.intVal("count")
        )
    }
}

data class EveningStudyStatus(
    val id: Int,
    val name: String,
    val color: String,
    val baseStatus: String,
    val isDefault: Boolean,
    val studentRequestable: Boolean,
    val isActive: Boolean
) {
    companion object {
        fun fromJson(o: JsonObject) = EveningStudyStatus(
            id = o.intVal("id"),
            name = o.str("name").orEmpty(),
            color = o.str("color") ?: "gray",
            baseStatus = o.str("base_status") ?: "present",
            isDefault = o.boolVal("is_default"),
            studentRequestable = o.boolVal("student_requestable"),
            isActive = o.boolVal("is_active", true)
        )
    }
}

data class BoardingSuspension(
    val id: Int,
    val studentId: Int,
    val startDate: String,
    val endDate: String,
    val reason: String,
    val destination: String?,
    val revokedAt: String?
) {
    val isRevoked: Boolean get() = !revokedAt.isNullOrBlank()
    val startDateDisplay: String get() = SCDateFormatter.normalizeDateKey(startDate)
    val endDateDisplay: String get() = SCDateFormatter.normalizeDateKey(endDate)

    companion object {
        fun fromJson(o: JsonObject) = BoardingSuspension(
            id = o.intVal("id"),
            studentId = o.intVal("student_id"),
            startDate = o.str("start_date").orEmpty(),
            endDate = o.str("end_date").orEmpty(),
            reason = o.str("reason").orEmpty(),
            destination = o.str("destination"),
            revokedAt = o.str("revoked_at")
        )
    }
}

data class EveningStudySession(
    val id: Int,
    val classId: Int,
    val createdBy: Int?,
    val creatorName: String?,
    val className: String?,
    val periodName: String?,
    val attendanceDate: String,
    val status: String,
    val totalStudents: Int,
    val normalCount: Int,
    val exceptionCount: Int,
    val notes: String?,
    val statusCounts: List<StatusCountItem>
) {
    val isCompleted: Boolean get() = status == "completed"
    val dateDisplay: String get() = SCDateFormatter.normalizeDateKey(attendanceDate)

    companion object {
        fun fromJson(o: JsonObject): EveningStudySession {
            val creator = o.objOrNull("creator")
            return EveningStudySession(
                id = o.intVal("id"),
                classId = o.intVal("class_id"),
                createdBy = o.intOrNull("created_by") ?: creator?.intOrNull("id"),
                creatorName = creator?.str("name"),
                className = o.objOrNull("school_class")?.str("name"),
                periodName = o.str("period_name_snapshot"),
                attendanceDate = o.str("attendance_date").orEmpty(),
                status = o.str("status").orEmpty(),
                totalStudents = o.intVal("total_students"),
                normalCount = o.intVal("normal_count"),
                exceptionCount = o.intVal("exception_count"),
                notes = o.str("notes"),
                statusCounts = o.arrOrEmpty("status_counts").mapNotNull { it.asObjectOrNull()?.let(StatusCountItem::fromJson) }
            )
        }
    }
}

data class EveningStudyDeleteResult(
    val message: String,
    val deletedRecordCount: Int,
    val retainedLeaveCount: Int
)

data class EveningStudyClassItem(
    val id: Int,
    val name: String,
    val departmentName: String?,
    val boardingCount: Int,
    val session: EveningStudySession?
) {
    companion object {
        fun fromJson(o: JsonObject) = EveningStudyClassItem(
            id = o.intVal("id"),
            name = o.str("name").orEmpty(),
            departmentName = o.objOrNull("department")?.str("name"),
            boardingCount = o.intVal("boarding_count"),
            session = o.objOrNull("session")?.let(EveningStudySession::fromJson)
        )
    }
}

data class EveningStudySummary(
    val classCount: Int,
    val startedClassCount: Int,
    val completedClassCount: Int,
    val expectedCount: Int,
    val presentCount: Int,
    val statusCounts: List<StatusCountItem>
) {
    companion object {
        fun fromJson(o: JsonObject): EveningStudySummary {
            val overall = o.objOrNull("overall") ?: o
            return EveningStudySummary(
                classCount = overall.intVal("class_count"),
                startedClassCount = overall.intVal("started_class_count"),
                completedClassCount = overall.intVal("completed_class_count"),
                expectedCount = overall.intVal("expected_count"),
                presentCount = overall.intVal("present_count"),
                statusCounts = overall.arrOrEmpty("status_counts").mapNotNull { it.asObjectOrNull()?.let(StatusCountItem::fromJson) }
            )
        }
    }
}

data class EveningStudyRecordItem(
    val id: Int,
    val studentId: Int,
    val studentName: String,
    val studentNo: String?,
    val eveningStudyStatusId: Int?,
    val destination: String?,
    val note: String?,
    val isSelfApplied: Boolean,
    val approvalStatus: String?,
    val isSuspended: Boolean
) {
    companion object {
        fun fromJson(o: JsonObject): EveningStudyRecordItem {
            val st = o.objOrNull("student")
            return EveningStudyRecordItem(
                id = o.intVal("id"),
                studentId = o.intVal("student_id"),
                studentName = st?.objOrNull("user")?.str("name") ?: "学生 #${o.intVal("student_id")}",
                studentNo = st?.str("student_no"),
                eveningStudyStatusId = o.intOrNull("evening_study_status_id"),
                destination = o.str("destination"),
                note = o.str("note"),
                isSelfApplied = o.boolVal("is_self_applied"),
                approvalStatus = o.str("approval_status"),
                isSuspended = o.objOrNull("boarding_suspension") != null
            )
        }
    }
}

data class EveningStudySessionPayload(
    val session: EveningStudySession,
    val records: List<EveningStudyRecordItem>
) {
    companion object {
        fun fromJson(o: JsonObject) = EveningStudySessionPayload(
            session = EveningStudySession.fromJson(o.objOrNull("session") ?: JsonObject(emptyMap())),
            records = o.arrOrEmpty("records").mapNotNull { it.asObjectOrNull()?.let(EveningStudyRecordItem::fromJson) }
        )
    }
}

data class StudentListItem(
    val id: Int,
    val name: String,
    val studentNo: String?,
    val gender: String?,
    val email: String?,
    val classId: Int?,
    val className: String?,
    val isBoarding: Boolean,
    val isClassAdmin: Boolean,
    val parentContact: String?,
    val parentEmail: String?,
    val hasActiveSuspension: Boolean
) {
    val normalizedGender: String
        get() = when (gender) {
            "female", "other" -> gender
            else -> "male"
        }

    val genderDisplayText: String
        get() = when (normalizedGender) {
            "female" -> "女"
            "other" -> "其他"
            else -> "男"
        }

    companion object {
        fun fromJson(o: JsonObject) = StudentListItem(
            id = o.intVal("id"),
            name = o.str("name").orEmpty(),
            studentNo = o.str("student_no"),
            gender = o.str("gender"),
            email = o.str("email"),
            classId = o.intOrNull("class_id"),
            className = o.str("class_name"),
            isBoarding = o.boolVal("is_boarding"),
            isClassAdmin = o.boolVal("is_class_admin"),
            parentContact = o.str("parent_contact"),
            parentEmail = o.str("parent_email"),
            hasActiveSuspension = o.objOrNull("active_boarding_suspension") != null
        )
    }
}

data class ClassOptionItem(
    val id: Int,
    val name: String
) {
    companion object {
        fun fromJson(o: JsonObject) = ClassOptionItem(
            id = o.intVal("id"),
            name = o.str("name").orEmpty()
        )
    }
}
