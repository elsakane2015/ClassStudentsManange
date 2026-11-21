package com.litotime.smartcampus.data.model

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
    val type: String,
    val option: String?,
    val studentName: String,
    val studentNo: String?,
    val time: String?,
    val status: String,
    val isSelfApplied: Boolean,
    val approvalStatus: String?
) {
    val approvalPrefix: String
        get() = if (!isSelfApplied) "" else when (approvalStatus) {
            "pending" -> "待审:"
            "approved" -> "批准:"
            "rejected" -> "驳回:"
            else -> ""
        }

    val typeWithOption: String
        get() = if (!option.isNullOrBlank()) "$type($option)" else type

    val studentShortLabel: String
        get() = if (!studentNo.isNullOrBlank() && studentNo.length >= 2) {
            "${studentNo.takeLast(2)} $studentName"
        } else studentName

    companion object {
        fun fromJson(o: JsonObject) = CalendarSummaryRecord(
            id = o.intVal("id"),
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
    val leaveTypeColor: String?,
    val eveningStudyStatusName: String?,
    val eveningStudyLabel: String?,
    val recordTime: String?,
    val periodIdsInDetails: List<Int>,
    val detailText: String
) {
    val dateKey: String get() = date?.take(10).orEmpty()

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

    val badgeColorKey: String get() = leaveTypeColor ?: status

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
                leaveTypeColor = lt?.str("color") ?: es?.str("color"),
                eveningStudyStatusName = es?.str("name"),
                eveningStudyLabel = o.str("evening_study_label"),
                recordTime = o.str("record_time"),
                periodIdsInDetails = details?.intList("period_ids").orEmpty(),
                detailText = detailCandidate
            )
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
    val startDateDisplay: String get() = startDate.take(10)
    val endDateDisplay: String get() = endDate.take(10)

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
    val dateDisplay: String get() = attendanceDate.take(10)

    companion object {
        fun fromJson(o: JsonObject) = EveningStudySession(
            id = o.intVal("id"),
            classId = o.intVal("class_id"),
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
