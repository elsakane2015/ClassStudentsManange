package com.litotime.smartcampus.feature.teacher

import android.content.Context
import android.content.Intent
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.gestures.detectHorizontalDragGestures
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.IntrinsicSize
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.CalendarMonth
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Checklist
import androidx.compose.material.icons.rounded.ChevronLeft
import androidx.compose.material.icons.rounded.ChevronRight
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.ExpandMore
import androidx.compose.material.icons.rounded.FileDownload
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material.icons.rounded.Inbox
import androidx.compose.material.icons.rounded.Nightlight
import androidx.compose.material.icons.rounded.Notifications
import androidx.compose.material.icons.rounded.Refresh
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material.icons.rounded.Tune
import androidx.compose.material.icons.rounded.Warning
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Checkbox
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DatePicker
import androidx.compose.material3.DatePickerDialog
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilterChip
import androidx.compose.material3.FilterChipDefaults
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SegmentedButton
import androidx.compose.material3.SegmentedButtonDefaults
import androidx.compose.material3.SingleChoiceSegmentedButtonRow
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Surface
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.rememberDatePickerState
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.hapticfeedback.HapticFeedbackType
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.platform.LocalHapticFeedback
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.IntOffset
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.FileProvider
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.litotime.smartcampus.BuildConfig
import com.litotime.smartcampus.SmartCampusApp
import com.litotime.smartcampus.core.designsystem.ErrorBanner
import com.litotime.smartcampus.core.designsystem.FullScreenImageDialog
import com.litotime.smartcampus.core.designsystem.RemoteMediaThumb
import com.litotime.smartcampus.core.designsystem.SCCard
import com.litotime.smartcampus.core.designsystem.StatMetricCard
import com.litotime.smartcampus.core.designsystem.StatusBadge
import com.litotime.smartcampus.core.designsystem.statusColor
import com.litotime.smartcampus.core.util.PeriodFormatter
import com.litotime.smartcampus.core.util.SCDateFormatter
import com.litotime.smartcampus.data.model.*
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import java.io.File
import java.time.DayOfWeek
import java.time.Instant
import java.time.LocalDate
import java.time.YearMonth
import java.time.ZoneId
import java.time.temporal.ChronoUnit
import java.time.temporal.TemporalAdjusters
import java.util.Locale
import kotlin.math.roundToInt

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun TeacherDashboardScreen(
    user: User,
    onOpenNotifications: () -> Unit,
    onOpenApprovals: () -> Unit = {}
) {
    val repo = SmartCampusApp.container.repository
    val scope = rememberCoroutineScope()
    val scopes = listOf("today" to "今日", "week" to "本周", "month" to "本月", "semester" to "本学期")

    var selectedScope by remember { mutableStateOf("today") }
    var semesters by remember { mutableStateOf<List<Semester>>(emptyList()) }
    var selectedSemesterId by remember { mutableStateOf<Int?>(null) }
    var stats by remember { mutableStateOf<TeacherStats?>(null) }
    var leaveTypes by remember { mutableStateOf<List<LeaveType>>(emptyList()) }
    var timeSlots by remember { mutableStateOf<List<TimeSlot>>(emptyList()) }
    var classPeriods by remember { mutableStateOf<List<ClassPeriod>>(emptyList()) }

    // Scheme A: Attendance Calendar (default to weekly) + Linked Day Panel
    var calendarExpanded by remember { mutableStateOf(true) }
    var isWeekMode by remember { mutableStateOf(true) }
    var anchorDate by remember { mutableStateOf(LocalDate.now()) }
    var selectedDate by remember { mutableStateOf(LocalDate.now()) }
    var calendarSummary by remember { mutableStateOf<Map<String, List<CalendarSummaryRecord>>>(emptyMap()) }
    var loadingCalendar by remember { mutableStateOf(false) }
    var dayPanelMode by remember { mutableStateOf("summary") } // "summary" | "roster"
    var summaryTypeFilter by remember { mutableStateOf<String?>(null) }
    val summaryColumns by SmartCampusApp.container.prefs.summaryColumnsFlow.collectAsStateWithLifecycle(initialValue = 1)
    var rosterSearch by remember { mutableStateOf("") }
    var rosterFilter by remember { mutableStateOf("all") } // "all" | "has_records" | "normal"
    val rosterColumns by SmartCampusApp.container.prefs.rosterColumnsFlow.collectAsStateWithLifecycle(initialValue = 1)

    var overview by remember { mutableStateOf<List<OverviewDepartment>>(emptyList()) }
    var expandedDepts by remember { mutableStateOf<Set<String>>(emptySet()) }
    var expandedClasses by remember { mutableStateOf<Set<Int>>(emptySet()) }
    var loading by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    var selectedStudent by remember { mutableStateOf<OverviewStudent?>(null) }
    var focusedRecordId by remember { mutableStateOf<Int?>(null) }
    var openSwipeRecordId by remember { mutableStateOf<Int?>(null) }
    var busySummaryIds by remember { mutableStateOf<Set<Int>>(emptySet()) }
    var statDetailTarget by remember { mutableStateOf<StatDetailTarget?>(null) }
    var showExportModal by remember { mutableStateOf(false) }
    var semMenuOpen by remember { mutableStateOf(false) }
    val snackbarHostState = remember { SnackbarHostState() }
    val haptic = LocalHapticFeedback.current

    val activeSemester = remember(semesters) { semesters.firstOrNull { it.isCurrent } ?: semesters.firstOrNull() }
    val canViewCalendarSummary = user.hasPermission("attendance.calendar_summary") || user.isTeacherOrAdmin
    val selectedDateKey = selectedDate.format(SCDateFormatter.yyyyMMdd)
    val selectedDayRecords = calendarSummary[selectedDateKey].orEmpty()
    val isSelectedHoliday = activeSemester?.holidays?.contains(selectedDateKey) == true
    val regularPeriods = remember(classPeriods) { classPeriods.filter { (it.scene ?: "regular") != "evening_study" } }

    fun normalizedSummaryTypeName(rec: CalendarSummaryRecord): String {
        val clean = rec.type.trim()
        if (clean.isNotEmpty() && clean != "未知") return clean
        return when (rec.status) {
            "absent" -> "旷课"
            "late" -> "迟到"
            "early_leave" -> "早退"
            "excused", "leave" -> "请假"
            else -> clean.ifEmpty { "考勤记录" }
        }
    }

    val summaryFilterOptions = remember(selectedDayRecords, leaveTypes) {
        val countsByType = mutableMapOf<String, Int>()
        val fallbackColors = mutableMapOf<String, String>()
        selectedDayRecords.forEach { rec ->
            val name = normalizedSummaryTypeName(rec)
            countsByType[name] = (countsByType[name] ?: 0) + 1
            if (name !in fallbackColors) fallbackColors[name] = rec.displayColorKey
        }
        val list = mutableListOf<Triple<String, Int, String>>()
        val seen = mutableSetOf<String>()
        leaveTypes.filter { it.isActive }.forEach { lt ->
            val name = lt.name.trim()
            if (name.isNotEmpty() && seen.add(name)) {
                list.add(Triple(name, countsByType[name] ?: 0, lt.resolvedColorKey))
            }
        }
        selectedDayRecords.forEach { rec ->
            val name = normalizedSummaryTypeName(rec)
            if (seen.add(name)) {
                list.add(Triple(name, countsByType[name] ?: 0, fallbackColors[name] ?: "blue"))
            }
        }
        list
    }

    val filteredDayRecords = remember(selectedDayRecords, summaryTypeFilter) {
        val filter = summaryTypeFilter ?: return@remember selectedDayRecords
        selectedDayRecords.filter { normalizedSummaryTypeName(it) == filter }
    }

    val allClassRoster = remember(overview) { overview.flatMap { it.classes }.flatMap { it.students } }
    val rosterHasRecordCount = remember(allClassRoster) {
        allClassRoster.count { s -> s.attendance.any { !it.isNormalPresentRecord } }
    }
    val rosterNormalCount = (allClassRoster.size - rosterHasRecordCount).coerceAtLeast(0)

    val filteredRoster = remember(allClassRoster, rosterSearch, rosterFilter) {
        val q = rosterSearch.trim().lowercase()
        allClassRoster.filter { s ->
            val hasRec = s.attendance.any { !it.isNormalPresentRecord }
            when (rosterFilter) {
                "has_records" -> if (!hasRec) return@filter false
                "normal" -> if (hasRec) return@filter false
            }
            if (q.isEmpty()) true
            else s.name.lowercase().contains(q) || (s.studentNo ?: "").lowercase().contains(q)
        }
    }

    fun loadStats() {
        scope.launch {
            runCatching { repo.fetchTeacherStats(selectedScope, selectedSemesterId) }
                .onSuccess { stats = it }
                .onFailure { error = it.localizedMessage }
        }
    }

    fun loadCalendarSummary() {
        if (!canViewCalendarSummary) return
        scope.launch {
            loadingCalendar = true
            val months = if (!isWeekMode) {
                listOf(YearMonth.from(anchorDate).toString())
            } else {
                val mon = anchorDate.with(TemporalAdjusters.previousOrSame(DayOfWeek.MONDAY))
                val sun = mon.plusDays(6)
                listOf(YearMonth.from(mon).toString(), YearMonth.from(sun).toString()).distinct()
            }
            val merged = mutableMapOf<String, List<CalendarSummaryRecord>>()
            months.forEach { m ->
                runCatching { repo.fetchCalendarSummary(m) }.getOrNull()?.forEach { (k, v) ->
                    val filtered = v.filterNot { it.isNormalPresentRecord }
                    if (filtered.isNotEmpty()) merged[k] = filtered
                }
            }
            calendarSummary = merged
            loadingCalendar = false
        }
    }

    fun loadOverview() {
        scope.launch {
            runCatching { repo.fetchAttendanceOverview(selectedDate.format(SCDateFormatter.yyyyMMdd)) }
                .onSuccess { list ->
                    overview = list
                    if (expandedDepts.isEmpty() && list.isNotEmpty()) {
                        expandedDepts = setOf(list.first().id)
                        list.first().classes.firstOrNull()?.let { expandedClasses = setOf(it.id) }
                    }
                }
                .onFailure { error = it.localizedMessage }
        }
    }

    fun loadAll() {
        scope.launch {
            loading = true
            error = null
            leaveTypes = runCatching { repo.fetchLeaveTypes().filter { it.isActive } }.getOrDefault(emptyList())
            semesters = runCatching { repo.fetchSemesters() }.getOrDefault(emptyList())
            timeSlots = runCatching { repo.fetchTimeSlots() }.getOrDefault(emptyList())
            classPeriods = runCatching { repo.fetchClassPeriods() }.getOrDefault(emptyList())
            loadStats()
            loadCalendarSummary()
            loadOverview()
            loading = false
        }
    }

    LaunchedEffect(Unit) { loadAll() }
    LaunchedEffect(selectedScope, selectedSemesterId) { if (stats != null) loadStats() }
    LaunchedEffect(anchorDate, isWeekMode) { loadCalendarSummary() }
    LaunchedEffect(selectedDate) {
        openSwipeRecordId = null
        if (YearMonth.from(selectedDate) != YearMonth.from(anchorDate) && !isWeekMode) {
            anchorDate = selectedDate
        }
        loadOverview()
    }
    LaunchedEffect(dayPanelMode) { openSwipeRecordId = null }

    fun matchedStudent(rec: CalendarSummaryRecord): OverviewStudent? {
        val all = overview.flatMap { it.classes }.flatMap { it.students }
        rec.studentId?.let { sid -> all.firstOrNull { it.id == sid }?.let { return it } }
        rec.studentNo?.takeIf { it.isNotBlank() }?.let { no -> all.firstOrNull { it.studentNo == no }?.let { return it } }
        return all.firstOrNull { it.name == rec.studentName }
    }

    fun resolvedSummaryNote(rec: CalendarSummaryRecord): String? {
        rec.displayNote?.trim()?.takeIf { it.isNotEmpty() }?.let { return it }
        val student = matchedStudent(rec) ?: return null
        val nonNormal = student.attendance.filterNot { it.isNormalPresentRecord }
        if (nonNormal.isEmpty()) return null
        val idSet = rec.allRecordIds.toSet()
        var candidates = nonNormal.filter { it.id in idSet }
        if (candidates.isEmpty()) {
            val recType = normalizedSummaryTypeName(rec)
            val isEvening = rec.option?.contains("夜自习") == true
            candidates = nonNormal.filter { item ->
                val itemIsEvening = (item.scene ?: "regular") == "evening_study"
                if (itemIsEvening != isEvening) return@filter false
                val typeMatch = item.titleText == recType || item.status == rec.status
                val approvalMatch = rec.approvalStatus == null || item.approvalStatus == rec.approvalStatus
                typeMatch && approvalMatch
            }
        }
        val cleanOption = rec.compactDisplay.first.trim()
        for (item in candidates) {
            for (raw in listOf(item.reason, item.note, item.detailsText)) {
                val trimmed = raw?.trim().orEmpty()
                if (trimmed.isNotEmpty() && trimmed != cleanOption) {
                    return trimmed
                }
            }
        }
        return null
    }

    suspend fun resolveUnderlyingRecords(rec: CalendarSummaryRecord, student: OverviewStudent): List<AttendanceRecord> {
        val idSet = rec.allRecordIds.toSet()
        var candidates = student.attendance.filterNot { it.isNormalPresentRecord }
        var matched = candidates.filter { it.id in idSet }
        if (matched.isEmpty()) {
            val fetched = runCatching {
                repo.fetchStudentRecords(student.id, "custom", selectedDateKey, selectedDateKey)
            }.getOrDefault(emptyList())
            candidates = fetched.filterNot { it.isNormalPresentRecord }
            matched = candidates.filter { it.id in idSet }
        }
        if (rec.recordIds.isEmpty() && matched.size == 1) {
            val primary = matched.first()
            val opt = rec.option.orEmpty()
            if (primary.periodId != null && (primary.scene ?: "regular") != "evening_study" &&
                (opt.contains("、") || opt.contains("-") || opt.contains("节)"))
            ) {
                val group = candidates.filter { item ->
                    item.periodId != null &&
                        (item.scene ?: "regular") == (primary.scene ?: "regular") &&
                        item.leaveTypeId == primary.leaveTypeId &&
                        item.status == primary.status &&
                        item.approvalStatus == primary.approvalStatus &&
                        item.leaveBatchId == primary.leaveBatchId
                }
                if (group.isNotEmpty()) matched = group
            }
        }
        return matched
    }

    fun quickChangeSummaryRecord(rec: CalendarSummaryRecord, targetType: LeaveType) {
        openSwipeRecordId = null
        if (rec.id in busySummaryIds) return
        val student = matchedStudent(rec)
        if (student == null) {
            error = "未找到学生「${rec.studentName}」，请刷新后重试"
            return
        }
        scope.launch {
            busySummaryIds = busySummaryIds + rec.id
            error = null
            runCatching {
                val oldRecords = resolveUnderlyingRecords(rec, student)
                if (oldRecords.any { (it.scene ?: "regular") == "evening_study" }) {
                    focusedRecordId = rec.id
                    selectedStudent = student
                    return@runCatching
                }

                val targetStatus = targetType.mappedAttendanceStatus
                if (oldRecords.isNotEmpty() && oldRecords.all { it.leaveTypeId == targetType.id && it.status == targetStatus }) {
                    return@runCatching
                }

                val mergedPeriodIds = oldRecords.flatMap { it.extractedPeriodIds(regularPeriods) }.distinct().sorted()
                val existingNote = oldRecords.firstNotNullOfOrNull { it.detailsText?.trim()?.takeIf { t -> t.isNotEmpty() } }

                repo.submitBulkAttendance(
                    date = selectedDateKey,
                    studentId = student.id,
                    status = targetStatus,
                    leaveTypeId = targetType.id,
                    periodIds = mergedPeriodIds,
                    note = existingNote
                )

                val deletedRollCallIds = mutableSetOf<Int>()
                val deletedSelfBatches = mutableSetOf<String>()
                oldRecords.forEach { old ->
                    val oldPeriodIds = old.extractedPeriodIds(regularPeriods)
                    val sameTarget = old.leaveTypeId == targetType.id &&
                        old.status == targetStatus &&
                        oldPeriodIds == mergedPeriodIds &&
                        old.periodId == null
                    val autoReplacedRollCall = old.sourceType == "roll_call" &&
                        targetStatus == "leave" &&
                        (mergedPeriodIds.isEmpty() || oldPeriodIds.any { it in mergedPeriodIds })
                    if (sameTarget || autoReplacedRollCall) return@forEach

                    if (old.sourceType == "roll_call" && old.sourceId != null) {
                        if (!deletedRollCallIds.add(old.sourceId)) return@forEach
                    }
                    val isSelfApplied = old.isSelfApplied || old.sourceType == "self_applied"
                    if (isSelfApplied && !old.leaveBatchId.isNullOrBlank()) {
                        val key = "${old.leaveBatchId}_${old.scene ?: "regular"}"
                        if (!deletedSelfBatches.add(key)) return@forEach
                    }

                    runCatching {
                        repo.deleteAttendanceRecord(
                            studentId = student.id,
                            date = old.dateKey.ifEmpty { selectedDateKey },
                            recordId = old.id,
                            periodId = old.periodId,
                            leaveBatchId = old.leaveBatchId,
                            sourceType = if (isSelfApplied) "self_applied" else old.sourceType,
                            scene = old.scene ?: "regular",
                            deleteScope = if (isSelfApplied) "scene_date" else "record"
                        )
                    }
                }

                haptic.performHapticFeedback(HapticFeedbackType.LongPress)
                loadStats()
                loadCalendarSummary()
                loadOverview()
                snackbarHostState.showSnackbar("已将 ${rec.studentName} 改为 ${targetType.name}")
            }.onFailure { error = it.localizedMessage ?: "修改失败" }
            busySummaryIds = busySummaryIds - rec.id
        }
    }

    fun quickDeleteSummaryRecord(rec: CalendarSummaryRecord) {
        openSwipeRecordId = null
        if (rec.id in busySummaryIds) return
        val student = matchedStudent(rec)
        val targetStudentId = student?.id ?: rec.studentId
        if (targetStudentId == null) {
            error = "未找到学生「${rec.studentName}」，请刷新后重试"
            return
        }
        scope.launch {
            busySummaryIds = busySummaryIds + rec.id
            error = null
            runCatching {
                val oldRecords = if (student != null) resolveUnderlyingRecords(rec, student) else emptyList()
                if (oldRecords.isNotEmpty()) {
                    val deletedRollCallIds = mutableSetOf<Int>()
                    val deletedSelfBatches = mutableSetOf<String>()
                    oldRecords.forEach { item ->
                        if (item.sourceType == "roll_call" && item.sourceId != null) {
                            if (!deletedRollCallIds.add(item.sourceId)) return@forEach
                        }
                        val isSelfApplied = item.isSelfApplied || item.sourceType == "self_applied"
                        if (isSelfApplied && !item.leaveBatchId.isNullOrBlank()) {
                            val key = "${item.leaveBatchId}_${item.scene ?: "regular"}"
                            if (!deletedSelfBatches.add(key)) return@forEach
                        }
                        repo.deleteAttendanceRecord(
                            studentId = targetStudentId,
                            date = item.dateKey.ifEmpty { selectedDateKey },
                            recordId = item.id,
                            periodId = item.periodId,
                            leaveBatchId = item.leaveBatchId,
                            sourceType = if (isSelfApplied) "self_applied" else item.sourceType,
                            scene = item.scene ?: "regular",
                            deleteScope = if (isSelfApplied) "scene_date" else "record"
                        )
                    }
                } else {
                    val isSelfApplied = rec.isSelfApplied
                    repo.deleteAttendanceRecord(
                        studentId = targetStudentId,
                        date = selectedDateKey,
                        recordId = rec.id,
                        periodId = null,
                        leaveBatchId = null,
                        sourceType = if (isSelfApplied) "self_applied" else null,
                        scene = if (rec.option?.contains("夜自习") == true) "evening_study" else "regular",
                        deleteScope = if (isSelfApplied) "scene_date" else "record"
                    )
                }

                calendarSummary = calendarSummary.toMutableMap().apply {
                    val remain = this[selectedDateKey].orEmpty().filterNot { it.id == rec.id }
                    if (remain.isEmpty()) remove(selectedDateKey) else this[selectedDateKey] = remain
                }
                haptic.performHapticFeedback(HapticFeedbackType.LongPress)
                loadStats()
                loadCalendarSummary()
                loadOverview()
                snackbarHostState.showSnackbar("已删除 ${rec.studentName} 的考勤记录")
            }.onFailure { error = it.localizedMessage ?: "删除失败" }
            busySummaryIds = busySummaryIds - rec.id
        }
    }

    Scaffold(
        snackbarHost = { SnackbarHost(snackbarHostState) },
        topBar = {
            TopAppBar(
                title = { Text("考勤看板") },
                actions = {
                    if (user.hasPermission("attendance.export") || user.isTeacherOrAdmin) {
                        IconButton(onClick = { showExportModal = true }) {
                            Icon(Icons.Rounded.FileDownload, contentDescription = "导出报表")
                        }
                    }
                    IconButton(onClick = { loadAll() }) { Icon(Icons.Rounded.Refresh, contentDescription = "刷新") }
                    IconButton(onClick = onOpenNotifications) { Icon(Icons.Rounded.Notifications, contentDescription = "通知") }
                }
            )
        }
    ) { padding ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(padding)
                .verticalScroll(rememberScrollState())
                .padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(14.dp)
        ) {
            error?.let { ErrorBanner(message = it, onRetry = { loadAll() }) }

            SingleChoiceSegmentedButtonRow(modifier = Modifier.fillMaxWidth()) {
                scopes.forEachIndexed { idx, (key, label) ->
                    SegmentedButton(
                        selected = selectedScope == key,
                        onClick = { selectedScope = key },
                        shape = SegmentedButtonDefaults.itemShape(idx, scopes.size)
                    ) { Text(label) }
                }
            }

            if (selectedScope == "semester" && semesters.isNotEmpty()) {
                Box {
                    OutlinedButton(onClick = { semMenuOpen = true }, modifier = Modifier.fillMaxWidth()) {
                        Text(semesters.firstOrNull { it.id == selectedSemesterId }?.name ?: "当前学期")
                    }
                    DropdownMenu(expanded = semMenuOpen, onDismissRequest = { semMenuOpen = false }) {
                        DropdownMenuItem(text = { Text("当前学期") }, onClick = { selectedSemesterId = null; semMenuOpen = false })
                        semesters.forEach { sem ->
                            DropdownMenuItem(text = { Text(sem.name) }, onClick = { selectedSemesterId = sem.id; semMenuOpen = false })
                        }
                    }
                }
            }

            // 1. Key Stats Cards (aligned with Web formatting + clickable Pending card)
            stats?.let { st ->
                val studentTotalVal = when {
                    st.classTotalStudents != null -> "${st.classTotalStudents}/${st.departmentTotalStudents ?: st.classTotalStudents}/${st.schoolTotalStudents}"
                    st.departmentTotalStudents != null -> "${st.departmentTotalStudents}/${st.schoolTotalStudents}"
                    else -> "${st.schoolTotalStudents.takeIf { it > 0 } ?: st.totalStudents}"
                }
                val studentTotalSub = when {
                    st.classTotalStudents != null -> "班级 / 系部 / 全校"
                    st.departmentTotalStudents != null -> "系部 / 全校"
                    else -> "全校在籍"
                }
                val attendanceVal = if (selectedScope == "today") {
                    val presentNum = st.presentStudentsCount?.roundToInt() ?: st.presentCount
                    val totalDenom = st.classTotalStudents ?: st.departmentTotalStudents ?: st.totalStudents
                    "$presentNum/$totalDenom"
                } else {
                    String.format(Locale.US, "%.1f%%", st.attendanceRate)
                }

                Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                    StatMetricCard(
                        title = "学生总数",
                        value = studentTotalVal,
                        icon = Icons.Rounded.Groups,
                        color = MaterialTheme.colorScheme.primary,
                        subtitle = studentTotalSub,
                        modifier = Modifier.weight(1f)
                    )
                    StatMetricCard(
                        title = if (selectedScope == "today") "今日出勤" else "综合出勤率",
                        value = attendanceVal,
                        icon = Icons.Rounded.CheckCircle,
                        color = Color(0xFF16A34A),
                        subtitle = if (selectedScope == "today") "出勤人数/总人数 · 点看明细" else "出勤率 · 点看明细",
                        modifier = Modifier.weight(1f),
                        onClick = { statDetailTarget = StatDetailTarget("出勤明细", null, "present", null) }
                    )
                }
                Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                    StatMetricCard(
                        title = "待审批请假",
                        value = "${st.pendingRequests}",
                        icon = Icons.Rounded.Inbox,
                        color = if (st.pendingRequests > 0) Color(0xFFEA580C) else Color(0xFF64748B),
                        subtitle = if (st.pendingRequests > 0) "点击前往审批 →" else "暂无待办",
                        modifier = Modifier.weight(1f),
                        onClick = onOpenApprovals
                    )
                    StatMetricCard(
                        title = "缺勤节次",
                        value = "${st.absentPeriods}",
                        icon = Icons.Rounded.Warning,
                        color = if (st.absentPeriods > 0) Color(0xFFDC2626) else Color(0xFF64748B),
                        subtitle = "迟到 ${st.latePeriods} 节 · 点看明细",
                        modifier = Modifier.weight(1f),
                        onClick = { statDetailTarget = StatDetailTarget("缺勤明细", "旷课", "absent", null) }
                    )
                }

                if (st.leavesBreakdown.isNotEmpty()) {
                    SCCard {
                        Text("各类考勤", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold)
                        Spacer(Modifier.height(8.dp))
                        Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                            st.leavesBreakdown.entries.sortedBy { it.key }.forEach { (name, summaryText) ->
                                val matched = leaveTypes.firstOrNull { it.name == name }
                                val resolvedStatus = matched?.mappedAttendanceStatus ?: when {
                                    name.contains("旷课") || name.contains("缺勤") -> "absent"
                                    name.contains("迟到") -> "late"
                                    name.contains("早退") -> "early_leave"
                                    else -> "leave"
                                }
                                val resolvedLeaveTypeId = if (resolvedStatus == "leave") matched?.id else null
                                val fallbackColor = when {
                                    name.contains("旷课") || name.contains("缺勤") -> "red"
                                    name.contains("迟到") || name.contains("早退") -> "orange"
                                    name.contains("病假") -> "purple"
                                    name.contains("生理") -> "pink"
                                    else -> "blue"
                                }
                                val tint = statusColor(matched?.resolvedColorKey ?: fallbackColor)
                                Surface(
                                    shape = RoundedCornerShape(10.dp),
                                    color = tint.copy(alpha = 0.1f),
                                    modifier = Modifier.fillMaxWidth().clickable {
                                        statDetailTarget = StatDetailTarget("${name}明细", name, resolvedStatus, resolvedLeaveTypeId)
                                    }
                                ) {
                                    Row(
                                        modifier = Modifier.padding(horizontal = 12.dp, vertical = 9.dp),
                                        horizontalArrangement = Arrangement.SpaceBetween
                                    ) {
                                        Text(name, style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.Medium)
                                        Text(summaryText, style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.Bold, color = tint)
                                    }
                                }
                            }
                        }
                    }
                }
            } ?: if (loading) {
                Box(Modifier.fillMaxWidth().padding(24.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
            } else Unit

            // 2. Scheme A: Monthly / Weekly Attendance Calendar (School Week + Holidays + Badges)
            TeacherAttendanceCalendarCard(
                expanded = calendarExpanded,
                onToggleExpanded = { calendarExpanded = !calendarExpanded },
                isWeekMode = isWeekMode,
                onModeChange = { isWeekMode = it },
                anchorDate = anchorDate,
                onShift = { delta ->
                    anchorDate = if (isWeekMode) anchorDate.plusWeeks(delta.toLong()) else anchorDate.plusMonths(delta.toLong())
                    if (isWeekMode) selectedDate = anchorDate
                },
                onToday = {
                    val today = LocalDate.now()
                    anchorDate = today
                    selectedDate = today
                },
                selectedDate = selectedDate,
                onSelectDate = { selectedDate = it },
                semester = activeSemester,
                calendarSummary = calendarSummary,
                loading = loadingCalendar
            )

            // 3. Linked Selected-Date Attendance Panel
            SCCard {
                Column(modifier = Modifier.fillMaxWidth()) {
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        Text(selectedDate.format(SCDateFormatter.cnMonthDay), style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold)
                        if (isSelectedHoliday) StatusBadge("休", Color(0xFFDC2626))
                    }
                    Text(
                        if (selectedDayRecords.isEmpty()) "当日暂无考勤记录" else "当日共 ${selectedDayRecords.size} 条考勤情况",
                        style = MaterialTheme.typography.labelSmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant
                    )
                }

                Spacer(Modifier.height(10.dp))

                SingleChoiceSegmentedButtonRow(modifier = Modifier.fillMaxWidth()) {
                    listOf("summary" to "考勤情况", "roster" to "全班登记").forEachIndexed { idx, (k, label) ->
                        SegmentedButton(
                            selected = dayPanelMode == k,
                            onClick = { dayPanelMode = k },
                            shape = SegmentedButtonDefaults.itemShape(idx, 2)
                        ) { Text(label) }
                    }
                }

                Spacer(Modifier.height(10.dp))

                if (dayPanelMode == "summary") {
                    if (!canViewCalendarSummary) {
                        Text("当前账号无日历考勤情况查看权限，可切换「全班登记」查看维护。", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    } else if (loadingCalendar && selectedDayRecords.isEmpty()) {
                        Box(Modifier.fillMaxWidth().padding(16.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
                    } else if (selectedDayRecords.isEmpty()) {
                        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.padding(vertical = 8.dp)) {
                            Icon(Icons.Rounded.CheckCircle, contentDescription = null, tint = Color(0xFF16A34A), modifier = Modifier.size(18.dp))
                            Text("该日期暂无请假、缺勤、迟到或早退记录", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                        }
                    } else {
                        // 考勤类型下拉筛选 + 1列/2列切换（持久化记忆）
                        var summaryFilterMenuOpen by remember { mutableStateOf(false) }
                        val activeOption = summaryTypeFilter?.let { name ->
                            summaryFilterOptions.firstOrNull { it.first == name }
                        }
                        val activeTint = activeOption?.let { statusColor(it.third) } ?: MaterialTheme.colorScheme.primary
                        val filterButtonText = when {
                            activeOption != null -> "${activeOption.first} (${activeOption.second})"
                            summaryTypeFilter != null -> "${summaryTypeFilter.orEmpty()} (0)"
                            else -> "全部 (${selectedDayRecords.size})"
                        }

                        Row(
                            modifier = Modifier.fillMaxWidth(),
                            verticalAlignment = Alignment.CenterVertically,
                            horizontalArrangement = Arrangement.SpaceBetween
                        ) {
                            Box {
                                Surface(
                                    shape = RoundedCornerShape(999.dp),
                                    color = if (summaryTypeFilter == null) MaterialTheme.colorScheme.surfaceVariant else activeTint.copy(alpha = 0.14f),
                                    modifier = Modifier.clip(RoundedCornerShape(999.dp)).clickable {
                                        summaryFilterMenuOpen = true
                                    }
                                ) {
                                    Row(
                                        modifier = Modifier.padding(horizontal = 11.dp, vertical = 6.dp),
                                        verticalAlignment = Alignment.CenterVertically,
                                        horizontalArrangement = Arrangement.spacedBy(4.dp)
                                    ) {
                                        Text(
                                            filterButtonText,
                                            style = MaterialTheme.typography.labelSmall,
                                            fontWeight = FontWeight.SemiBold,
                                            color = if (summaryTypeFilter == null) MaterialTheme.colorScheme.onSurface else activeTint
                                        )
                                        Icon(
                                            Icons.Rounded.ExpandMore,
                                            contentDescription = "选择考勤类型",
                                            tint = if (summaryTypeFilter == null) MaterialTheme.colorScheme.onSurfaceVariant else activeTint,
                                            modifier = Modifier.size(16.dp)
                                        )
                                    }
                                }

                                DropdownMenu(
                                    expanded = summaryFilterMenuOpen,
                                    onDismissRequest = { summaryFilterMenuOpen = false }
                                ) {
                                    DropdownMenuItem(
                                        text = {
                                            Text(
                                                "全部（${selectedDayRecords.size}条）",
                                                fontWeight = if (summaryTypeFilter == null) FontWeight.SemiBold else FontWeight.Normal
                                            )
                                        },
                                        leadingIcon = if (summaryTypeFilter == null) {
                                            { Icon(Icons.Rounded.Check, contentDescription = null, modifier = Modifier.size(18.dp)) }
                                        } else null,
                                        onClick = {
                                            summaryFilterMenuOpen = false
                                            openSwipeRecordId = null
                                            summaryTypeFilter = null
                                        }
                                    )
                                    if (summaryFilterOptions.isNotEmpty()) {
                                        HorizontalDivider()
                                        summaryFilterOptions.forEach { (typeName, cnt, colorKey) ->
                                            val isSelected = summaryTypeFilter == typeName
                                            DropdownMenuItem(
                                                text = {
                                                    Text(
                                                        "${typeName}（${cnt}条）",
                                                        color = if (cnt > 0 || isSelected) statusColor(colorKey) else MaterialTheme.colorScheme.onSurfaceVariant,
                                                        fontWeight = if (isSelected) FontWeight.SemiBold else FontWeight.Normal
                                                    )
                                                },
                                                leadingIcon = if (isSelected) {
                                                    { Icon(Icons.Rounded.Check, contentDescription = null, modifier = Modifier.size(18.dp)) }
                                                } else null,
                                                onClick = {
                                                    summaryFilterMenuOpen = false
                                                    openSwipeRecordId = null
                                                    summaryTypeFilter = typeName
                                                }
                                            )
                                        }
                                    }
                                }
                            }

                            Surface(
                                shape = RoundedCornerShape(9.dp),
                                color = MaterialTheme.colorScheme.surfaceVariant
                            ) {
                                Row(modifier = Modifier.padding(2.dp), horizontalArrangement = Arrangement.spacedBy(2.dp)) {
                                    listOf(1, 2).forEach { cols ->
                                        val active = summaryColumns == cols
                                        Surface(
                                            shape = RoundedCornerShape(7.dp),
                                            color = if (active) MaterialTheme.colorScheme.primary else Color.Transparent,
                                            modifier = Modifier.clip(RoundedCornerShape(7.dp)).clickable {
                                                openSwipeRecordId = null
                                                scope.launch { SmartCampusApp.container.prefs.setSummaryColumns(cols) }
                                            }
                                        ) {
                                            Text(
                                                "${cols}列",
                                                style = MaterialTheme.typography.labelSmall,
                                                fontWeight = if (active) FontWeight.SemiBold else FontWeight.Normal,
                                                color = if (active) Color.White else MaterialTheme.colorScheme.onSurfaceVariant,
                                                modifier = Modifier.padding(horizontal = 7.dp, vertical = 5.dp)
                                            )
                                        }
                                    }
                                }
                            }
                        }

                        Spacer(Modifier.height(8.dp))

                        if (filteredDayRecords.isEmpty()) {
                            Text(
                                "当日暂无「${summaryTypeFilter.orEmpty()}」记录",
                                style = MaterialTheme.typography.bodySmall,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                                modifier = Modifier.padding(vertical = 8.dp)
                            )
                        } else if (summaryColumns == 2) {
                            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                                filteredDayRecords.chunked(2).forEach { rowItems ->
                                    Row(
                                        modifier = Modifier.fillMaxWidth(),
                                        horizontalArrangement = Arrangement.spacedBy(8.dp)
                                    ) {
                                        rowItems.forEach { rec ->
                                            SummaryCompactGridCard(
                                                rec = rec,
                                                resolvedNote = resolvedSummaryNote(rec),
                                                activeLeaveTypes = leaveTypes,
                                                isBusy = rec.id in busySummaryIds,
                                                modifier = Modifier.weight(1f),
                                                onTap = {
                                                    matchedStudent(rec)?.let {
                                                        focusedRecordId = rec.id
                                                        selectedStudent = it
                                                    }
                                                },
                                                onQuickChangeType = { targetType ->
                                                    quickChangeSummaryRecord(rec, targetType)
                                                },
                                                onDelete = {
                                                    quickDeleteSummaryRecord(rec)
                                                }
                                            )
                                        }
                                        if (rowItems.size == 1) {
                                            Spacer(Modifier.weight(1f))
                                        }
                                    }
                                }
                            }
                        } else {
                            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                                filteredDayRecords.forEach { rec ->
                                    SummarySwipeableRecordRow(
                                        rec = rec,
                                        resolvedNote = resolvedSummaryNote(rec),
                                        activeLeaveTypes = leaveTypes,
                                        isBusy = rec.id in busySummaryIds,
                                        isOpen = openSwipeRecordId == rec.id,
                                        onSetOpen = { open ->
                                            openSwipeRecordId = when {
                                                open -> rec.id
                                                openSwipeRecordId == rec.id -> null
                                                else -> openSwipeRecordId
                                            }
                                        },
                                        onTap = {
                                            matchedStudent(rec)?.let {
                                                focusedRecordId = rec.id
                                                selectedStudent = it
                                            }
                                        },
                                        onQuickChangeType = { targetType ->
                                            quickChangeSummaryRecord(rec, targetType)
                                        },
                                        onDelete = {
                                            quickDeleteSummaryRecord(rec)
                                        }
                                    )
                                }
                            }
                        }
                    }
                } else {
                    OutlinedTextField(
                        value = rosterSearch,
                        onValueChange = { rosterSearch = it },
                        placeholder = { Text("搜索姓名或学号以补录考勤") },
                        leadingIcon = { Icon(Icons.Rounded.Search, contentDescription = null) },
                        singleLine = true,
                        modifier = Modifier.fillMaxWidth()
                    )
                    Spacer(Modifier.height(8.dp))

                    // 筛选（全部 / 有记录 / 正常）+ 1列/2列切换
                    Row(
                        modifier = Modifier.fillMaxWidth(),
                        verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.spacedBy(6.dp)
                    ) {
                        Row(
                            modifier = Modifier.weight(1f).horizontalScroll(rememberScrollState()),
                            horizontalArrangement = Arrangement.spacedBy(6.dp)
                        ) {
                            listOf(
                                Triple("all", "全部", allClassRoster.size),
                                Triple("has_records", "有记录", rosterHasRecordCount),
                                Triple("normal", "正常", rosterNormalCount)
                            ).forEach { (key, label, cnt) ->
                                val selected = rosterFilter == key
                                Surface(
                                    shape = RoundedCornerShape(999.dp),
                                    color = if (selected) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.surfaceVariant,
                                    modifier = Modifier.clip(RoundedCornerShape(999.dp)).clickable { rosterFilter = key }
                                ) {
                                    Text(
                                        "$label $cnt",
                                        style = MaterialTheme.typography.labelSmall,
                                        fontWeight = if (selected) FontWeight.SemiBold else FontWeight.Normal,
                                        color = if (selected) Color.White else MaterialTheme.colorScheme.onSurface,
                                        modifier = Modifier.padding(horizontal = 10.dp, vertical = 6.dp)
                                    )
                                }
                            }
                        }

                        Surface(
                            shape = RoundedCornerShape(9.dp),
                            color = MaterialTheme.colorScheme.surfaceVariant
                        ) {
                            Row(modifier = Modifier.padding(2.dp), horizontalArrangement = Arrangement.spacedBy(2.dp)) {
                                listOf(1, 2).forEach { cols ->
                                    val active = rosterColumns == cols
                                    Surface(
                                        shape = RoundedCornerShape(7.dp),
                                        color = if (active) MaterialTheme.colorScheme.primary else Color.Transparent,
                                        modifier = Modifier.clip(RoundedCornerShape(7.dp)).clickable {
                                            scope.launch { SmartCampusApp.container.prefs.setRosterColumns(cols) }
                                        }
                                    ) {
                                        Text(
                                            "${cols}列",
                                            style = MaterialTheme.typography.labelSmall,
                                            fontWeight = if (active) FontWeight.SemiBold else FontWeight.Normal,
                                            color = if (active) Color.White else MaterialTheme.colorScheme.onSurfaceVariant,
                                            modifier = Modifier.padding(horizontal = 8.dp, vertical = 4.dp)
                                        )
                                    }
                                }
                            }
                        }
                    }

                    Spacer(Modifier.height(8.dp))
                    if (filteredRoster.isEmpty()) {
                        Text(
                            if (rosterFilter == "all") "暂无匹配的学生数据" else "当前筛选下暂无学生",
                            style = MaterialTheme.typography.bodySmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant
                        )
                    } else if (rosterColumns == 2) {
                        Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                            filteredRoster.chunked(2).forEach { pair ->
                                Row(
                                    modifier = Modifier.fillMaxWidth(),
                                    horizontalArrangement = Arrangement.spacedBy(8.dp)
                                ) {
                                    pair.forEach { s ->
                                        val badgeGroups = remember(s.attendance, classPeriods, timeSlots) {
                                            DayAttendanceBadgeGroup.build(s.attendance, classPeriods, timeSlots)
                                        }
                                        val hasRec = badgeGroups.isNotEmpty()
                                        val accent = if (hasRec) statusColor(badgeGroups.first().colorKey) else Color(0xFF16A34A)
                                        Surface(
                                            shape = RoundedCornerShape(10.dp),
                                            color = if (hasRec) accent.copy(alpha = 0.08f) else MaterialTheme.colorScheme.surfaceVariant.copy(alpha = 0.5f),
                                            border = if (hasRec) BorderStroke(1.dp, accent.copy(alpha = 0.32f)) else null,
                                            modifier = Modifier
                                                .weight(1f)
                                                .clip(RoundedCornerShape(10.dp))
                                                .clickable {
                                                    focusedRecordId = null
                                                    selectedStudent = s
                                                }
                                        ) {
                                            Column(
                                                modifier = Modifier.padding(9.dp),
                                                verticalArrangement = Arrangement.spacedBy(5.dp)
                                            ) {
                                                Row(verticalAlignment = Alignment.CenterVertically) {
                                                    Text(
                                                        s.name,
                                                        style = MaterialTheme.typography.bodyMedium,
                                                        fontWeight = FontWeight.SemiBold,
                                                        maxLines = 1,
                                                        overflow = TextOverflow.Ellipsis,
                                                        modifier = Modifier.weight(1f)
                                                    )
                                                    s.studentNo?.takeIf { it.isNotBlank() }?.let { no ->
                                                        Text(
                                                            if (no.length >= 2) no.takeLast(2) else no,
                                                            style = MaterialTheme.typography.labelSmall,
                                                            fontWeight = FontWeight.Bold,
                                                            color = MaterialTheme.colorScheme.onSurfaceVariant
                                                        )
                                                    }
                                                }
                                                if (hasRec) {
                                                    Column(verticalArrangement = Arrangement.spacedBy(3.dp)) {
                                                        badgeGroups.take(2).forEach { g ->
                                                            StatusBadge(g.badgeText, statusColor(g.colorKey))
                                                        }
                                                        if (badgeGroups.size > 2) {
                                                            Text(
                                                                "+${badgeGroups.size - 2} 项",
                                                                style = MaterialTheme.typography.labelSmall,
                                                                color = MaterialTheme.colorScheme.onSurfaceVariant
                                                            )
                                                        }
                                                    }
                                                } else {
                                                    Text("正常出勤", style = MaterialTheme.typography.labelSmall, color = Color(0xFF16A34A))
                                                }
                                            }
                                        }
                                    }
                                    if (pair.size == 1) {
                                        Spacer(Modifier.weight(1f))
                                    }
                                }
                            }
                        }
                    } else {
                        filteredRoster.forEach { s ->
                            val badgeGroups = remember(s.attendance, classPeriods, timeSlots) {
                                DayAttendanceBadgeGroup.build(s.attendance, classPeriods, timeSlots)
                            }
                            Row(
                                modifier = Modifier
                                    .fillMaxWidth()
                                    .clickable {
                                        focusedRecordId = null
                                        selectedStudent = s
                                    }
                                    .padding(vertical = 9.dp),
                                verticalAlignment = Alignment.CenterVertically
                            ) {
                                Column(modifier = Modifier.weight(1f)) {
                                    Text(s.name, style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.Medium)
                                    s.studentNo?.let { Text(it, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant) }
                                }
                                if (badgeGroups.isEmpty()) {
                                    Text("正常出勤", style = MaterialTheme.typography.labelSmall, color = Color(0xFF16A34A))
                                } else {
                                    Column(
                                        horizontalAlignment = Alignment.End,
                                        verticalArrangement = Arrangement.spacedBy(4.dp)
                                    ) {
                                        badgeGroups.take(2).forEach { g ->
                                            StatusBadge(g.badgeText, statusColor(g.colorKey))
                                        }
                                        if (badgeGroups.size > 2) {
                                            Text(
                                                "+${badgeGroups.size - 2} 项",
                                                style = MaterialTheme.typography.labelSmall,
                                                color = MaterialTheme.colorScheme.onSurfaceVariant
                                            )
                                        }
                                    }
                                }
                                Spacer(Modifier.width(4.dp))
                                Icon(Icons.Rounded.ChevronRight, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.size(18.dp))
                            }
                            HorizontalDivider()
                        }
                    }
                }
            }

            // 4. Admin-only Hierarchical Department/Class Overview (aligned with Web)
            if (user.isAdminTier) {
                SCCard {
                    Row(modifier = Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                        Text("全校 / 院系出勤总览", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                        Text(selectedDateKey, style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                    Spacer(Modifier.height(8.dp))
                    if (overview.isEmpty()) {
                        Text("暂无班级考勤数据", color = MaterialTheme.colorScheme.onSurfaceVariant)
                    } else {
                        overview.forEach { dept ->
                            val deptOpen = dept.id in expandedDepts
                            Row(
                                modifier = Modifier
                                    .fillMaxWidth()
                                    .clickable { expandedDepts = if (deptOpen) expandedDepts - dept.id else expandedDepts + dept.id }
                                    .padding(vertical = 8.dp),
                                verticalAlignment = Alignment.CenterVertically
                            ) {
                                Icon(if (deptOpen) Icons.Rounded.ExpandMore else Icons.Rounded.ChevronRight, contentDescription = null, tint = MaterialTheme.colorScheme.primary)
                                Text(dept.name, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                                Text("${dept.classes.size} 个班", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                            }
                            if (deptOpen) {
                                dept.classes.forEach { cls ->
                                    val clsOpen = cls.id in expandedClasses
                                    val abnormalCount = cls.students.count { st ->
                                        st.attendance.any { !it.isNormalPresentRecord }
                                    }
                                    Surface(
                                        shape = RoundedCornerShape(10.dp),
                                        color = MaterialTheme.colorScheme.surfaceVariant.copy(alpha = 0.7f),
                                        modifier = Modifier
                                            .fillMaxWidth()
                                            .padding(start = 8.dp, bottom = 6.dp)
                                            .clickable { expandedClasses = if (clsOpen) expandedClasses - cls.id else expandedClasses + cls.id }
                                    ) {
                                        Row(
                                            modifier = Modifier.padding(10.dp),
                                            verticalAlignment = Alignment.CenterVertically,
                                            horizontalArrangement = Arrangement.spacedBy(8.dp)
                                        ) {
                                            Text(cls.name, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
                                            StatusBadge(
                                                if (abnormalCount > 0) "异常 $abnormalCount" else "全勤",
                                                if (abnormalCount > 0) Color(0xFFEA580C) else Color(0xFF16A34A)
                                            )
                                            Text("${cls.students.size}人", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                                        }
                                    }
                                    if (clsOpen) {
                                        cls.students.forEach { s ->
                                            val badgeGroups = remember(s.attendance, classPeriods, timeSlots) {
                                                DayAttendanceBadgeGroup.build(s.attendance, classPeriods, timeSlots)
                                            }
                                            Row(
                                                modifier = Modifier
                                                    .fillMaxWidth()
                                                    .clickable {
                                                        focusedRecordId = null
                                                        selectedStudent = s
                                                    }
                                                    .padding(start = 20.dp, end = 8.dp, top = 8.dp, bottom = 8.dp),
                                                verticalAlignment = Alignment.CenterVertically
                                            ) {
                                                Column(modifier = Modifier.weight(1f)) {
                                                    Text(s.name, style = MaterialTheme.typography.bodyMedium)
                                                    s.studentNo?.let { Text(it, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant) }
                                                }
                                                if (badgeGroups.isEmpty()) {
                                                    Text("正常出勤", style = MaterialTheme.typography.labelSmall, color = Color(0xFF16A34A))
                                                } else {
                                                    Column(
                                                        horizontalAlignment = Alignment.End,
                                                        verticalArrangement = Arrangement.spacedBy(4.dp)
                                                    ) {
                                                        badgeGroups.take(2).forEach { g ->
                                                            StatusBadge(g.badgeText, statusColor(g.colorKey))
                                                        }
                                                        if (badgeGroups.size > 2) {
                                                            Text(
                                                                "+${badgeGroups.size - 2} 项",
                                                                style = MaterialTheme.typography.labelSmall,
                                                                color = MaterialTheme.colorScheme.onSurfaceVariant
                                                            )
                                                        }
                                                    }
                                                }
                                                Spacer(Modifier.width(4.dp))
                                                Icon(Icons.Rounded.ChevronRight, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.size(18.dp))
                                            }
                                            HorizontalDivider(modifier = Modifier.padding(start = 20.dp))
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    selectedStudent?.let { st ->
        StudentManualAttendanceSheet(
            student = st,
            date = selectedDate,
            leaveTypes = leaveTypes,
            timeSlots = timeSlots,
            classPeriods = classPeriods,
            initialFocusRecordId = focusedRecordId,
            onDismiss = {
                selectedStudent = null
                focusedRecordId = null
            },
            onChanged = {
                loadStats()
                loadCalendarSummary()
                loadOverview()
            }
        )
    }

    statDetailTarget?.let { target ->
        TeacherStatDetailModal(
            target = target,
            scopeKey = selectedScope,
            semesterId = selectedSemesterId,
            semesters = semesters,
            classPeriods = classPeriods,
            timeSlots = timeSlots,
            onDismiss = { statDetailTarget = null }
        )
    }

    if (showExportModal) {
        AttendanceExportModal(semesters = semesters, onDismiss = { showExportModal = false })
    }
}

@OptIn(ExperimentalFoundationApi::class)
@Composable
private fun SummarySwipeableRecordRow(
    rec: CalendarSummaryRecord,
    resolvedNote: String?,
    activeLeaveTypes: List<LeaveType>,
    isBusy: Boolean,
    isOpen: Boolean,
    onSetOpen: (Boolean) -> Unit,
    onTap: () -> Unit,
    onQuickChangeType: (LeaveType) -> Unit,
    onDelete: () -> Unit
) {
    val density = LocalDensity.current
    val haptic = LocalHapticFeedback.current
    val actionWidthDp = 78.dp
    val actionWidthPx = with(density) { actionWidthDp.toPx() }

    var dragging by remember { mutableStateOf(false) }
    var rawOffsetPx by remember { mutableFloatStateOf(0f) }
    var quickMenuOpen by remember { mutableStateOf(false) }

    val targetOffsetPx = if (dragging) rawOffsetPx else if (isOpen) -actionWidthPx else 0f
    val animatedOffsetPx by animateFloatAsState(
        targetValue = targetOffsetPx,
        label = "summary_swipe_offset"
    )
    val effectiveOffsetPx = if (dragging) rawOffsetPx else animatedOffsetPx
    val isEveningStudy = rec.option?.contains("夜自习") == true

    Box(
        modifier = Modifier
            .fillMaxWidth()
            .height(IntrinsicSize.Min)
            .clip(RoundedCornerShape(10.dp))
    ) {
        if (effectiveOffsetPx < -1f || isOpen) {
            Box(
                modifier = Modifier
                    .align(Alignment.CenterEnd)
                    .width(actionWidthDp)
                    .fillMaxHeight()
                    .background(Color(0xFFDC2626))
                    .clickable(enabled = isOpen && !isBusy) { onDelete() },
                contentAlignment = Alignment.Center
            ) {
                if (isBusy) {
                    CircularProgressIndicator(
                        modifier = Modifier.size(18.dp),
                        strokeWidth = 2.dp,
                        color = Color.White
                    )
                } else {
                    Column(
                        horizontalAlignment = Alignment.CenterHorizontally,
                        verticalArrangement = Arrangement.spacedBy(2.dp)
                    ) {
                        Icon(
                            Icons.Rounded.Delete,
                            contentDescription = "删除",
                            tint = Color.White,
                            modifier = Modifier.size(18.dp)
                        )
                        Text(
                            "删除",
                            color = Color.White,
                            style = MaterialTheme.typography.labelSmall,
                            fontWeight = FontWeight.SemiBold
                        )
                    }
                }
            }
        }

        Box(
            modifier = Modifier
                .fillMaxWidth()
                .offset { IntOffset(effectiveOffsetPx.roundToInt(), 0) }
                .clip(RoundedCornerShape(10.dp))
                .background(MaterialTheme.colorScheme.surface)
                .background(MaterialTheme.colorScheme.surfaceVariant.copy(alpha = 0.65f))
                .pointerInput(isOpen, isBusy) {
                    if (isBusy) return@pointerInput
                    detectHorizontalDragGestures(
                        onDragStart = {
                            dragging = true
                            rawOffsetPx = if (isOpen) -actionWidthPx else 0f
                        },
                        onDragEnd = {
                            val shouldOpen = rawOffsetPx < -(actionWidthPx * 0.45f)
                            dragging = false
                            onSetOpen(shouldOpen)
                        },
                        onDragCancel = {
                            dragging = false
                            onSetOpen(isOpen)
                        },
                        onHorizontalDrag = { _, dragAmount ->
                            rawOffsetPx = (rawOffsetPx + dragAmount).coerceIn(-actionWidthPx - 24f, 0f)
                        }
                    )
                }
                .combinedClickable(
                    enabled = !isBusy,
                    onClick = {
                        if (isOpen) {
                            onSetOpen(false)
                        } else {
                            onTap()
                        }
                    },
                    onLongClick = {
                        if (isOpen) onSetOpen(false)
                        haptic.performHapticFeedback(HapticFeedbackType.LongPress)
                        quickMenuOpen = true
                    }
                )
        ) {
            Row(
                modifier = Modifier.fillMaxWidth().padding(10.dp),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.spacedBy(8.dp)
            ) {
                Box(modifier = Modifier.size(8.dp).clip(CircleShape).background(statusColor(rec.displayColorKey)))
                Column(modifier = Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Row(horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                        if (rec.approvalPrefix.isNotEmpty()) {
                            Text(
                                rec.approvalPrefix,
                                style = MaterialTheme.typography.labelMedium,
                                fontWeight = FontWeight.Bold,
                                color = statusColor(rec.approvalStatus)
                            )
                        }
                        Text(
                            rec.typeWithOption,
                            style = MaterialTheme.typography.bodySmall,
                            fontWeight = FontWeight.SemiBold,
                            color = statusColor(rec.displayColorKey)
                        )
                    }
                    Row(horizontalArrangement = Arrangement.spacedBy(4.dp), verticalAlignment = Alignment.CenterVertically) {
                        Text(rec.studentShortLabel, style = MaterialTheme.typography.labelMedium)
                        resolvedNote?.takeIf { it.isNotBlank() }?.let { note ->
                            Text(
                                "· $note",
                                style = MaterialTheme.typography.labelMedium,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                                maxLines = 1,
                                overflow = TextOverflow.Ellipsis
                            )
                        }
                    }
                }
                if (isBusy) {
                    CircularProgressIndicator(modifier = Modifier.size(16.dp), strokeWidth = 2.dp)
                } else {
                    rec.time?.takeIf { it.isNotBlank() }?.let {
                        Text(it, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                    Icon(
                        Icons.Rounded.ChevronRight,
                        contentDescription = null,
                        tint = MaterialTheme.colorScheme.onSurfaceVariant,
                        modifier = Modifier.size(16.dp)
                    )
                }
            }

            DropdownMenu(
                expanded = quickMenuOpen,
                onDismissRequest = { quickMenuOpen = false }
            ) {
                if (!isEveningStudy && activeLeaveTypes.isNotEmpty()) {
                    activeLeaveTypes.forEach { lt ->
                        val isCurrent = lt.name == rec.type
                        DropdownMenuItem(
                            text = {
                                Text(
                                    if (isCurrent) "${lt.name}（当前）" else "改为 ${lt.name}",
                                    color = if (isCurrent) MaterialTheme.colorScheme.onSurfaceVariant else statusColor(lt.resolvedColorKey),
                                    fontWeight = if (isCurrent) FontWeight.Normal else FontWeight.Medium
                                )
                            },
                            leadingIcon = if (isCurrent) {
                                { Icon(Icons.Rounded.Check, contentDescription = null, modifier = Modifier.size(18.dp)) }
                            } else null,
                            enabled = !isCurrent && !isBusy,
                            onClick = {
                                quickMenuOpen = false
                                onQuickChangeType(lt)
                            }
                        )
                    }
                    HorizontalDivider()
                }
                DropdownMenuItem(
                    text = { Text("详细编辑（节次 / 备注）") },
                    leadingIcon = { Icon(Icons.Rounded.Tune, contentDescription = null, modifier = Modifier.size(18.dp)) },
                    onClick = {
                        quickMenuOpen = false
                        onTap()
                    }
                )
                DropdownMenuItem(
                    text = { Text("删除此记录", color = Color(0xFFDC2626)) },
                    leadingIcon = {
                        Icon(
                            Icons.Rounded.Delete,
                            contentDescription = null,
                            tint = Color(0xFFDC2626),
                            modifier = Modifier.size(18.dp)
                        )
                    },
                    enabled = !isBusy,
                    onClick = {
                        quickMenuOpen = false
                        onDelete()
                    }
                )
            }
        }
    }
}

@OptIn(ExperimentalFoundationApi::class)
@Composable
private fun SummaryCompactGridCard(
    rec: CalendarSummaryRecord,
    resolvedNote: String?,
    activeLeaveTypes: List<LeaveType>,
    isBusy: Boolean,
    modifier: Modifier = Modifier,
    onTap: () -> Unit,
    onQuickChangeType: (LeaveType) -> Unit,
    onDelete: () -> Unit
) {
    val haptic = LocalHapticFeedback.current
    var quickMenuOpen by remember { mutableStateOf(false) }
    val isEveningStudy = rec.option?.contains("夜自习") == true
    val accent = statusColor(rec.displayColorKey)
    val cleanTypeTitle = rec.type.trim().takeIf { it.isNotEmpty() && it != "未知" } ?: when (rec.status) {
        "absent" -> "旷课"
        "late" -> "迟到"
        "early_leave" -> "早退"
        "excused", "leave" -> "请假"
        else -> "考勤"
    }

    Box(modifier = modifier) {
        Surface(
            shape = RoundedCornerShape(10.dp),
            color = accent.copy(alpha = 0.08f),
            border = BorderStroke(1.dp, accent.copy(alpha = 0.28f)),
            modifier = Modifier
                .fillMaxWidth()
                .clip(RoundedCornerShape(10.dp))
                .combinedClickable(
                    enabled = !isBusy,
                    onClick = onTap,
                    onLongClick = {
                        haptic.performHapticFeedback(HapticFeedbackType.LongPress)
                        quickMenuOpen = true
                    }
                )
        ) {
            Column(
                modifier = Modifier.padding(9.dp),
                verticalArrangement = Arrangement.spacedBy(4.dp)
            ) {
                Row(
                    modifier = Modifier.fillMaxWidth(),
                    verticalAlignment = Alignment.CenterVertically,
                    horizontalArrangement = Arrangement.spacedBy(4.dp)
                ) {
                    Box(modifier = Modifier.size(7.dp).clip(CircleShape).background(accent))
                    if (rec.approvalPrefix.isNotEmpty()) {
                        Text(
                            rec.approvalPrefix,
                            style = MaterialTheme.typography.labelSmall,
                            fontWeight = FontWeight.Bold,
                            color = statusColor(rec.approvalStatus),
                            maxLines = 1
                        )
                    }
                    Text(
                        cleanTypeTitle,
                        style = MaterialTheme.typography.labelMedium,
                        fontWeight = FontWeight.SemiBold,
                        color = accent,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis,
                        modifier = Modifier.weight(1f)
                    )
                    if (isBusy) {
                        CircularProgressIndicator(modifier = Modifier.size(12.dp), strokeWidth = 1.5.dp)
                    } else {
                        rec.time?.takeIf { it.isNotBlank() }?.let {
                            Text(it, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                        }
                    }
                }

                val opt = rec.compactDisplay.first
                Text(
                    opt.ifEmpty { "全天" },
                    style = MaterialTheme.typography.labelSmall,
                    color = MaterialTheme.colorScheme.onSurface,
                    maxLines = 2,
                    overflow = TextOverflow.Ellipsis
                )

                Text(
                    rec.studentShortLabel,
                    style = MaterialTheme.typography.labelMedium,
                    fontWeight = FontWeight.SemiBold,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis
                )

                resolvedNote?.takeIf { it.isNotBlank() }?.let { note ->
                    Text(
                        note,
                        style = MaterialTheme.typography.labelSmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis
                    )
                }
            }
        }

        DropdownMenu(
            expanded = quickMenuOpen,
            onDismissRequest = { quickMenuOpen = false }
        ) {
            if (!isEveningStudy && activeLeaveTypes.isNotEmpty()) {
                activeLeaveTypes.forEach { lt ->
                    val isCurrent = lt.name == rec.type
                    DropdownMenuItem(
                        text = {
                            Text(
                                if (isCurrent) "${lt.name}（当前）" else "改为 ${lt.name}",
                                color = if (isCurrent) MaterialTheme.colorScheme.onSurfaceVariant else statusColor(lt.resolvedColorKey),
                                fontWeight = if (isCurrent) FontWeight.Normal else FontWeight.Medium
                            )
                        },
                        leadingIcon = if (isCurrent) {
                            { Icon(Icons.Rounded.Check, contentDescription = null, modifier = Modifier.size(18.dp)) }
                        } else null,
                        enabled = !isCurrent && !isBusy,
                        onClick = {
                            quickMenuOpen = false
                            onQuickChangeType(lt)
                        }
                    )
                }
                HorizontalDivider()
            }
            DropdownMenuItem(
                text = { Text("详细编辑（节次 / 备注）") },
                leadingIcon = { Icon(Icons.Rounded.Tune, contentDescription = null, modifier = Modifier.size(18.dp)) },
                onClick = {
                    quickMenuOpen = false
                    onTap()
                }
            )
            DropdownMenuItem(
                text = { Text("删除此记录", color = Color(0xFFDC2626)) },
                leadingIcon = {
                    Icon(
                        Icons.Rounded.Delete,
                        contentDescription = null,
                        tint = Color(0xFFDC2626),
                        modifier = Modifier.size(18.dp)
                    )
                },
                enabled = !isBusy,
                onClick = {
                    quickMenuOpen = false
                    onDelete()
                }
            )
        }
    }
}

@Composable
private fun TeacherAttendanceCalendarCard(
    expanded: Boolean,
    onToggleExpanded: () -> Unit,
    isWeekMode: Boolean,
    onModeChange: (Boolean) -> Unit,
    anchorDate: LocalDate,
    onShift: (Int) -> Unit,
    onToday: () -> Unit,
    selectedDate: LocalDate,
    onSelectDate: (LocalDate) -> Unit,
    semester: Semester?,
    calendarSummary: Map<String, List<CalendarSummaryRecord>>,
    loading: Boolean
) {
    val weekdays = listOf("一", "二", "三", "四", "五", "六", "日")
    val today = LocalDate.now()
    val holidaySet = remember(semester) { semester?.holidays?.toSet().orEmpty() }

    val semesterStartMonday = remember(semester) {
        SCDateFormatter.parseLocalDate(semester?.startDate)
            ?.with(TemporalAdjusters.previousOrSame(DayOfWeek.MONDAY))
    }

    fun schoolWeekText(monday: LocalDate): String {
        val startMon = semesterStartMonday ?: return "-"
        val weeksDiff = ChronoUnit.WEEKS.between(startMon, monday).toInt()
        val totalWeeks = semester?.totalWeeks ?: 20
        return if (weeksDiff < 0 || weeksDiff >= totalWeeks) "-" else "${weeksDiff + 1}"
    }

    val weekRows = remember(anchorDate, isWeekMode) {
        val startRange: LocalDate
        val endRange: LocalDate
        if (isWeekMode) {
            startRange = anchorDate.with(TemporalAdjusters.previousOrSame(DayOfWeek.MONDAY))
            endRange = startRange.plusDays(6)
        } else {
            val ym = YearMonth.from(anchorDate)
            startRange = ym.atDay(1).with(TemporalAdjusters.previousOrSame(DayOfWeek.MONDAY))
            endRange = ym.atEndOfMonth().with(TemporalAdjusters.nextOrSame(DayOfWeek.SUNDAY))
        }
        val rows = mutableListOf<List<LocalDate>>()
        var cursor = startRange
        while (!cursor.isAfter(endRange)) {
            rows.add((0L..6L).map { cursor.plusDays(it) })
            cursor = cursor.plusWeeks(1)
        }
        rows
    }

    SCCard {
        Row(modifier = Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
            Row(
                modifier = Modifier.weight(1f).clickable(onClick = onToggleExpanded),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.spacedBy(4.dp)
            ) {
                Icon(
                    if (expanded) Icons.Rounded.ExpandMore else Icons.Rounded.ChevronRight,
                    contentDescription = null,
                    tint = MaterialTheme.colorScheme.primary
                )
                Text("考勤日历", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                if (loading) {
                    Spacer(Modifier.width(4.dp))
                    CircularProgressIndicator(modifier = Modifier.size(14.dp), strokeWidth = 2.dp)
                }
            }

            TextButton(onClick = onToday, contentPadding = PaddingValues(horizontal = 8.dp, vertical = 2.dp)) {
                Text("今", fontWeight = FontWeight.Bold)
            }

            SingleChoiceSegmentedButtonRow {
                listOf(false to "月", true to "周").forEachIndexed { idx, (wk, label) ->
                    SegmentedButton(
                        selected = isWeekMode == wk,
                        onClick = { onModeChange(wk) },
                        shape = SegmentedButtonDefaults.itemShape(idx, 2)
                    ) { Text(label, style = MaterialTheme.typography.labelSmall) }
                }
            }
        }

        if (expanded) {
            Spacer(Modifier.height(6.dp))
            Row(
                modifier = Modifier.fillMaxWidth(),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.SpaceBetween
            ) {
                IconButton(onClick = { onShift(-1) }) {
                    Icon(Icons.Rounded.ChevronLeft, contentDescription = "上一页", tint = MaterialTheme.colorScheme.primary)
                }
                val baseTitle = anchorDate.format(SCDateFormatter.cnYearMonth)
                val titleText = if (isWeekMode) {
                    val wk = schoolWeekText(anchorDate.with(TemporalAdjusters.previousOrSame(DayOfWeek.MONDAY)))
                    if (wk == "-") baseTitle else "$baseTitle · 第${wk}周"
                } else baseTitle
                Text(titleText, style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold)
                IconButton(onClick = { onShift(1) }) {
                    Icon(Icons.Rounded.ChevronRight, contentDescription = "下一页", tint = MaterialTheme.colorScheme.primary)
                }
            }

            // 8-column header: Week # + Mon..Sun
            Row(modifier = Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Text(
                    "周",
                    modifier = Modifier.width(24.dp),
                    textAlign = TextAlign.Center,
                    style = MaterialTheme.typography.labelSmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant
                )
                weekdays.forEach { wd ->
                    Text(
                        wd,
                        modifier = Modifier.weight(1f),
                        textAlign = TextAlign.Center,
                        style = MaterialTheme.typography.labelSmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant
                    )
                }
            }

            Spacer(Modifier.height(6.dp))

            Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                weekRows.forEach { rowDays ->
                    Row(modifier = Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                        Text(
                            schoolWeekText(rowDays.first()),
                            modifier = Modifier.width(24.dp),
                            textAlign = TextAlign.Center,
                            style = MaterialTheme.typography.labelSmall,
                            fontWeight = FontWeight.Bold,
                            color = MaterialTheme.colorScheme.onSurfaceVariant
                        )
                        rowDays.forEach { day ->
                            val dateKey = day.format(SCDateFormatter.yyyyMMdd)
                            val dayRecs = calendarSummary[dateKey].orEmpty()
                            val isSelected = day == selectedDate
                            val isToday = day == today
                            val inCurrentMonth = isWeekMode || YearMonth.from(day) == YearMonth.from(anchorDate)
                            val isHoliday = dateKey in holidaySet

                            Column(
                                modifier = Modifier
                                    .weight(1f)
                                    .clip(RoundedCornerShape(8.dp))
                                    .background(if (isHoliday && !isSelected) Color(0xFFDC2626).copy(alpha = 0.08f) else Color.Transparent)
                                    .clickable { onSelectDate(day) }
                                    .padding(vertical = 3.dp),
                                horizontalAlignment = Alignment.CenterHorizontally
                            ) {
                                Box(contentAlignment = Alignment.TopEnd) {
                                    Box(
                                        modifier = Modifier
                                            .size(28.dp)
                                            .clip(CircleShape)
                                            .background(
                                                when {
                                                    isSelected -> MaterialTheme.colorScheme.primary
                                                    isToday -> MaterialTheme.colorScheme.primaryContainer
                                                    else -> Color.Transparent
                                                }
                                            ),
                                        contentAlignment = Alignment.Center
                                    ) {
                                        Text(
                                            "${day.dayOfMonth}",
                                            style = MaterialTheme.typography.labelMedium,
                                            fontWeight = if (isSelected || isToday) FontWeight.Bold else FontWeight.Medium,
                                            color = when {
                                                isSelected -> Color.White
                                                isHoliday -> Color(0xFFDC2626)
                                                !inCurrentMonth -> MaterialTheme.colorScheme.onSurfaceVariant.copy(alpha = 0.45f)
                                                isToday -> MaterialTheme.colorScheme.primary
                                                else -> MaterialTheme.colorScheme.onSurface
                                            }
                                        )
                                    }
                                    if (isHoliday && !isSelected) {
                                        Text(
                                            "休",
                                            fontSize = 8.sp,
                                            fontWeight = FontWeight.Bold,
                                            color = Color(0xFFDC2626)
                                        )
                                    }
                                }

                                Row(
                                    modifier = Modifier.height(13.dp),
                                    verticalAlignment = Alignment.CenterVertically,
                                    horizontalArrangement = Arrangement.spacedBy(2.dp)
                                ) {
                                    if (dayRecs.isNotEmpty()) {
                                        dayRecs.map { it.displayColorKey }.distinct().take(2).forEach { ck ->
                                            Box(modifier = Modifier.size(4.dp).clip(CircleShape).background(statusColor(ck)))
                                        }
                                        Text(
                                            "${dayRecs.size}",
                                            fontSize = 9.sp,
                                            fontWeight = FontWeight.Bold,
                                            color = if (isSelected) MaterialTheme.colorScheme.primary else Color(0xFFEA580C)
                                        )
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)
@Composable
private fun StudentManualAttendanceSheet(
    student: OverviewStudent,
    date: LocalDate,
    leaveTypes: List<LeaveType>,
    timeSlots: List<TimeSlot>,
    classPeriods: List<ClassPeriod>,
    initialFocusRecordId: Int? = null,
    onDismiss: () -> Unit,
    onChanged: () -> Unit
) {
    val repo = SmartCampusApp.container.repository
    val scope = rememberCoroutineScope()
    val dateKey = remember(date) { date.format(SCDateFormatter.yyyyMMdd) }

    val activeLeaveTypes = remember(leaveTypes) { leaveTypes.filter { it.isActive } }
    val regularPeriods = remember(classPeriods) {
        val filtered = classPeriods.filter { it.isActive && !it.isEveningStudy }
        filtered.ifEmpty { classPeriods }
    }
    val validTimeSlots = remember(timeSlots, regularPeriods) {
        val regularIdSet = regularPeriods.map { it.id }.toSet()
        timeSlots.mapNotNull { slot ->
            val ids = slot.periodIds.filter { it in regularIdSet }.distinct().sorted()
            if (ids.isEmpty()) null else slot to ids
        }
    }

    // 1. 当日已有标记
    var dayRecords by remember(student.id, dateKey) {
        mutableStateOf(student.attendance.filterNot { it.isNormalPresentRecord })
    }
    var loadingDayRecords by remember { mutableStateOf(false) }
    var groupPendingDelete by remember { mutableStateOf<DayRecordGroup?>(null) }

    // 2. 补录 / 修改表单（类 Web 端一步点选后台配置的考勤类型）
    var editingGroup by remember { mutableStateOf<DayRecordGroup?>(null) }
    var selectedLeaveTypeId by remember(activeLeaveTypes) { mutableStateOf(activeLeaveTypes.firstOrNull()?.id) }
    var fallbackStatus by remember { mutableStateOf("leave") }
    var selectedPeriods by remember { mutableStateOf<Set<Int>>(emptySet()) }
    var note by remember { mutableStateOf("") }
    var saving by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    // 3. 近期历史异常
    var historyScope by remember { mutableStateOf("month") }
    var history by remember { mutableStateOf<List<AttendanceRecord>>(emptyList()) }
    var loadingHistory by remember { mutableStateOf(false) }

    val groupedDayRecords = remember(dayRecords, regularPeriods, timeSlots) {
        DayRecordGroup.build(dayRecords, regularPeriods, timeSlots, groupByDate = false)
    }
    val groupedHistoryRecords = remember(history, regularPeriods, timeSlots) {
        DayRecordGroup.build(history, regularPeriods, timeSlots, groupByDate = true)
    }

    val occupiedPeriodIds = remember(dayRecords, editingGroup, regularPeriods) {
        val editingIds = editingGroup?.recordIdSet.orEmpty()
        buildSet {
            dayRecords.filter { it.id !in editingIds }.forEach { rec ->
                addAll(rec.extractedPeriodIds(regularPeriods))
            }
        }
    }
    val selectedLeaveType = remember(activeLeaveTypes, selectedLeaveTypeId) {
        activeLeaveTypes.firstOrNull { it.id == selectedLeaveTypeId }
    }

    fun refreshDayRecords() {
        scope.launch {
            loadingDayRecords = true
            runCatching { repo.fetchAttendanceOverview(dateKey) }.getOrNull()?.let { list ->
                list.flatMap { it.classes }.flatMap { it.students }
                    .firstOrNull { it.id == student.id }
                    ?.let { fresh ->
                        dayRecords = fresh.attendance.filterNot { it.isNormalPresentRecord }
                    }
            }
            loadingDayRecords = false
        }
    }

    fun loadHistory(targetScope: String = historyScope) {
        scope.launch {
            loadingHistory = true
            history = runCatching { repo.fetchStudentRecords(student.id, targetScope) }
                .getOrDefault(emptyList())
                .filterNot { it.isNormalPresentRecord }
            loadingHistory = false
        }
    }

    fun beginEditing(group: DayRecordGroup) {
        val rec = group.primary
        editingGroup = group
        selectedLeaveTypeId = when {
            rec.leaveTypeId != null && activeLeaveTypes.any { it.id == rec.leaveTypeId } -> rec.leaveTypeId
            else -> activeLeaveTypes.firstOrNull { it.slug == rec.status || it.name == rec.titleText }?.id
                ?: activeLeaveTypes.firstOrNull()?.id
        }
        if (selectedLeaveTypeId == null) {
            fallbackStatus = if (rec.status == "present") "leave" else rec.status
        }
        selectedPeriods = group.periodIds.toSet()
        note = group.remark.orEmpty()
    }

    fun cancelEditing() {
        editingGroup = null
        selectedPeriods = emptySet()
        note = ""
    }

    fun performDeleteGroup(group: DayRecordGroup) {
        scope.launch {
            error = null
            runCatching {
                val deletedRollCallIds = mutableSetOf<Int>()
                val deletedSelfBatches = mutableSetOf<String>()
                group.records.forEach { rec ->
                    if (rec.sourceType == "roll_call" && rec.sourceId != null) {
                        if (!deletedRollCallIds.add(rec.sourceId)) return@forEach
                    }
                    val isSelfApplied = rec.isSelfApplied || rec.sourceType == "self_applied"
                    if (isSelfApplied && !rec.leaveBatchId.isNullOrBlank()) {
                        val key = "${rec.leaveBatchId}_${rec.scene ?: "regular"}"
                        if (!deletedSelfBatches.add(key)) return@forEach
                    }
                    repo.deleteAttendanceRecord(
                        studentId = student.id,
                        date = rec.dateKey.ifBlank { dateKey },
                        recordId = rec.id,
                        periodId = rec.periodId,
                        leaveBatchId = rec.leaveBatchId,
                        sourceType = if (isSelfApplied) "self_applied" else rec.sourceType,
                        scene = rec.scene ?: "regular",
                        deleteScope = if (isSelfApplied) "scene_date" else "record"
                    )
                }
                if (editingGroup?.id == group.id) cancelEditing()
                val removedIds = group.recordIdSet
                dayRecords = dayRecords.filterNot { it.id in removedIds }
                history = history.filterNot { it.id in removedIds }
                onChanged()
                refreshDayRecords()
                loadHistory()
            }.onFailure { error = it.localizedMessage }
        }
    }

    LaunchedEffect(student.id, dateKey) {
        refreshDayRecords()
        loadHistory()
    }

    ModalBottomSheet(onDismissRequest = onDismiss) {
        Column(
            modifier = Modifier
                .fillMaxWidth()
                .verticalScroll(rememberScrollState())
                .padding(horizontal = 16.dp, vertical = 8.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp)
        ) {
            Text(
                "${student.name} · 考勤维护（$dateKey）",
                style = MaterialTheme.typography.titleMedium,
                fontWeight = FontWeight.Bold
            )
            error?.let { ErrorBanner(message = it) }

            // SECTION 1: 当日已有标记（置顶、同批次多节次合并为 1 条）
            Row(modifier = Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Text(
                    "$dateKey 当日已有标记（${groupedDayRecords.size}）",
                    style = MaterialTheme.typography.titleSmall,
                    fontWeight = FontWeight.Bold,
                    modifier = Modifier.weight(1f)
                )
                if (loadingDayRecords) {
                    CircularProgressIndicator(modifier = Modifier.size(14.dp), strokeWidth = 2.dp)
                }
            }

            if (groupedDayRecords.isEmpty()) {
                Surface(
                    shape = RoundedCornerShape(10.dp),
                    color = Color(0xFF16A34A).copy(alpha = 0.08f),
                    modifier = Modifier.fillMaxWidth()
                ) {
                    Row(
                        modifier = Modifier.padding(10.dp),
                        verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.spacedBy(8.dp)
                    ) {
                        Icon(Icons.Rounded.CheckCircle, contentDescription = null, tint = Color(0xFF16A34A), modifier = Modifier.size(18.dp))
                        Text("当日暂无考勤记录，当前状态为正常出勤", style = MaterialTheme.typography.bodySmall, color = Color(0xFF16A34A))
                    }
                }
            } else {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    groupedDayRecords.forEach { group ->
                        val rec = group.primary
                        val isEditingThis = editingGroup?.id == group.id
                        val isFocused = group.contains(initialFocusRecordId) || isEditingThis
                        Surface(
                            shape = RoundedCornerShape(12.dp),
                            color = if (isFocused) MaterialTheme.colorScheme.primaryContainer.copy(alpha = 0.4f)
                            else MaterialTheme.colorScheme.surfaceVariant.copy(alpha = 0.55f),
                            border = if (isFocused) BorderStroke(1.dp, MaterialTheme.colorScheme.primary) else null,
                            modifier = Modifier.fillMaxWidth()
                        ) {
                            Column(modifier = Modifier.padding(10.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                                    StatusBadge(rec.titleText, statusColor(rec.badgeColorKey))
                                    rec.approvalStatus?.takeIf { it.isNotBlank() }?.let { ap ->
                                        StatusBadge(
                                            when (ap) {
                                                "pending" -> "待审"
                                                "approved" -> "已批"
                                                else -> "驳回"
                                            },
                                            statusColor(ap)
                                        )
                                    }
                                    rec.sourceBadgeText?.let { src ->
                                        Surface(shape = RoundedCornerShape(999.dp), color = MaterialTheme.colorScheme.surface) {
                                            Text(
                                                src,
                                                style = MaterialTheme.typography.labelSmall,
                                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                                                modifier = Modifier.padding(horizontal = 6.dp, vertical = 2.dp)
                                            )
                                        }
                                    }
                                    Spacer(Modifier.weight(1f))
                                    rec.displayTimeText?.let { t ->
                                        Text(t, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                                    }
                                }

                                Row(
                                    verticalAlignment = Alignment.CenterVertically,
                                    horizontalArrangement = Arrangement.spacedBy(6.dp)
                                ) {
                                    Text(
                                        "课节：${group.periodDetailText}",
                                        style = MaterialTheme.typography.bodySmall,
                                        fontWeight = FontWeight.Medium,
                                        modifier = Modifier.weight(1f, fill = false)
                                    )
                                    if (group.periodIds.size > 1) {
                                        Text(
                                            "共${group.periodIds.size}节",
                                            style = MaterialTheme.typography.labelSmall,
                                            color = MaterialTheme.colorScheme.onSurfaceVariant
                                        )
                                    }
                                }
                                group.remark?.takeIf { it.isNotBlank() }?.let { remark ->
                                    Text("备注：$remark", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                                }

                                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                    OutlinedButton(
                                        onClick = { beginEditing(group) },
                                        contentPadding = PaddingValues(horizontal = 12.dp, vertical = 4.dp),
                                        modifier = Modifier.weight(1f)
                                    ) {
                                        Icon(Icons.Rounded.Edit, contentDescription = null, modifier = Modifier.size(14.dp))
                                        Spacer(Modifier.width(4.dp))
                                        Text(if (isEditingThis) "修改中" else "修改", style = MaterialTheme.typography.labelMedium)
                                    }
                                    OutlinedButton(
                                        onClick = { groupPendingDelete = group },
                                        colors = ButtonDefaults.outlinedButtonColors(contentColor = Color(0xFFDC2626)),
                                        border = BorderStroke(1.dp, Color(0xFFDC2626).copy(alpha = 0.5f)),
                                        contentPadding = PaddingValues(horizontal = 12.dp, vertical = 4.dp),
                                        modifier = Modifier.weight(1f)
                                    ) {
                                        Icon(Icons.Rounded.Delete, contentDescription = null, modifier = Modifier.size(14.dp))
                                        Spacer(Modifier.width(4.dp))
                                        Text("撤销", style = MaterialTheme.typography.labelMedium)
                                    }
                                }
                            }
                        }
                    }
                }
            }

            HorizontalDivider()

            // SECTION 2: 补录 / 修改考勤（类 Web 端一步点选考勤类型）
            Text(
                if (editingGroup == null) "为 ${student.name} 补录考勤" else "修改 ${student.name} 的考勤标记",
                style = MaterialTheme.typography.titleSmall,
                fontWeight = FontWeight.Bold
            )

            editingGroup?.let { editing ->
                Surface(
                    shape = RoundedCornerShape(10.dp),
                    color = MaterialTheme.colorScheme.primaryContainer.copy(alpha = 0.6f),
                    modifier = Modifier.fillMaxWidth()
                ) {
                    Row(
                        modifier = Modifier.padding(horizontal = 10.dp, vertical = 6.dp),
                        verticalAlignment = Alignment.CenterVertically
                    ) {
                        Text(
                            "正在修改：${editing.primary.titleText} · ${editing.periodDetailText}",
                            style = MaterialTheme.typography.labelMedium,
                            fontWeight = FontWeight.SemiBold,
                            color = MaterialTheme.colorScheme.primary,
                            modifier = Modifier.weight(1f)
                        )
                        TextButton(
                            onClick = { cancelEditing() },
                            contentPadding = PaddingValues(horizontal = 8.dp, vertical = 2.dp)
                        ) {
                            Text("取消修改", style = MaterialTheme.typography.labelMedium)
                        }
                    }
                }
            }

            Text("考勤类型（一步点选）", style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
            if (activeLeaveTypes.isNotEmpty()) {
                FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    activeLeaveTypes.forEach { lt ->
                        val tint = statusColor(lt.resolvedColorKey)
                        val selected = selectedLeaveTypeId == lt.id
                        FilterChip(
                            selected = selected,
                            onClick = { selectedLeaveTypeId = lt.id },
                            label = { Text(lt.name, fontWeight = if (selected) FontWeight.Bold else FontWeight.Medium) },
                            colors = FilterChipDefaults.filterChipColors(
                                selectedContainerColor = tint,
                                selectedLabelColor = Color.White,
                                containerColor = tint.copy(alpha = 0.1f),
                                labelColor = tint
                            )
                        )
                    }
                }
            } else {
                FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    listOf("leave" to "请假", "absent" to "旷课", "late" to "迟到", "early_leave" to "早退").forEach { (k, label) ->
                        FilterChip(selected = fallbackStatus == k, onClick = { fallbackStatus = k }, label = { Text(label) })
                    }
                }
            }

            if (regularPeriods.isNotEmpty()) {
                Row(modifier = Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                    Text(
                        "选择节次（留空代表全天）",
                        style = MaterialTheme.typography.labelMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        modifier = Modifier.weight(1f)
                    )
                    if (selectedPeriods.isNotEmpty()) {
                        TextButton(
                            onClick = { selectedPeriods = emptySet() },
                            contentPadding = PaddingValues(horizontal = 8.dp, vertical = 0.dp)
                        ) { Text("清空(全天)", style = MaterialTheme.typography.labelSmall) }
                    }
                }

                if (validTimeSlots.isNotEmpty()) {
                    Row(
                        modifier = Modifier.horizontalScroll(rememberScrollState()),
                        horizontalArrangement = Arrangement.spacedBy(6.dp)
                    ) {
                        validTimeSlots.forEach { (slot, ids) ->
                            val slotSet = ids.toSet()
                            val isExact = selectedPeriods == slotSet
                            FilterChip(
                                selected = isExact,
                                onClick = { selectedPeriods = if (isExact) emptySet() else slotSet },
                                label = { Text(slot.name, style = MaterialTheme.typography.labelSmall) }
                            )
                        }
                    }
                }

                FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    regularPeriods.forEach { p ->
                        val on = p.id in selectedPeriods
                        val occupied = p.id in occupiedPeriodIds
                        FilterChip(
                            selected = on,
                            onClick = { selectedPeriods = if (on) selectedPeriods - p.id else selectedPeriods + p.id },
                            label = {
                                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                                    Text(p.name)
                                    if (occupied) {
                                        Box(modifier = Modifier.size(6.dp).clip(CircleShape).background(Color(0xFFEA580C)))
                                    }
                                }
                            }
                        )
                    }
                }
                if (occupiedPeriodIds.isNotEmpty()) {
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                        Box(modifier = Modifier.size(6.dp).clip(CircleShape).background(Color(0xFFEA580C)))
                        Text("橙点表示该节次当日已有其他标记", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                }
            }

            OutlinedTextField(
                value = note,
                onValueChange = { note = it },
                label = { Text("备注（可选）") },
                singleLine = true,
                modifier = Modifier.fillMaxWidth()
            )

            Button(
                enabled = !saving && (activeLeaveTypes.isEmpty() || selectedLeaveTypeId != null),
                modifier = Modifier.fillMaxWidth(),
                onClick = {
                    scope.launch {
                        saving = true; error = null
                        val targetStatus = selectedLeaveType?.mappedAttendanceStatus ?: fallbackStatus
                        val targetLeaveTypeId = selectedLeaveType?.id
                        val sortedIds = selectedPeriods.sorted()
                        val matchedSlot = validTimeSlots.firstOrNull { it.second == sortedIds }?.first
                        val oldGroup = editingGroup

                        runCatching {
                            repo.submitBulkAttendance(
                                date = dateKey,
                                studentId = student.id,
                                status = targetStatus,
                                leaveTypeId = targetLeaveTypeId,
                                periodIds = sortedIds,
                                note = note,
                                timeSlotId = matchedSlot?.id,
                                timeSlotName = matchedSlot?.name
                            )

                            if (oldGroup != null) {
                                val deletedRollCallIds = mutableSetOf<Int>()
                                val deletedSelfBatches = mutableSetOf<String>()
                                oldGroup.records.forEach { oldRec ->
                                    val oldPeriodIds = oldRec.extractedPeriodIds(regularPeriods)
                                    val sameTarget = (oldRec.leaveTypeId == targetLeaveTypeId) &&
                                        (oldRec.status == targetStatus) &&
                                        (oldPeriodIds == sortedIds) &&
                                        (oldRec.periodId == null)
                                    val autoReplacedRollCall = (oldRec.sourceType == "roll_call") &&
                                        (targetStatus == "leave") &&
                                        (targetLeaveTypeId != null) &&
                                        (sortedIds.isEmpty() || oldPeriodIds.any { it in sortedIds })

                                    if (sameTarget || autoReplacedRollCall) return@forEach

                                    if (oldRec.sourceType == "roll_call" && oldRec.sourceId != null) {
                                        if (!deletedRollCallIds.add(oldRec.sourceId)) return@forEach
                                    }
                                    val isSelfApplied = oldRec.isSelfApplied || oldRec.sourceType == "self_applied"
                                    if (isSelfApplied && !oldRec.leaveBatchId.isNullOrBlank()) {
                                        val key = "${oldRec.leaveBatchId}_${oldRec.scene ?: "regular"}"
                                        if (!deletedSelfBatches.add(key)) return@forEach
                                    }

                                    runCatching {
                                        repo.deleteAttendanceRecord(
                                            studentId = student.id,
                                            date = oldRec.dateKey.ifBlank { dateKey },
                                            recordId = oldRec.id,
                                            periodId = oldRec.periodId,
                                            leaveBatchId = oldRec.leaveBatchId,
                                            sourceType = if (isSelfApplied) "self_applied" else oldRec.sourceType,
                                            scene = oldRec.scene ?: "regular",
                                            deleteScope = if (isSelfApplied) "scene_date" else "record"
                                        )
                                    }
                                }
                            }

                            editingGroup = null
                            note = ""
                            selectedPeriods = emptySet()
                            onChanged()
                            refreshDayRecords()
                            loadHistory()
                        }.onFailure { error = it.localizedMessage }
                        saving = false
                    }
                }
            ) {
                Text(
                    when {
                        saving -> "保存中..."
                        editingGroup != null -> "保存修改"
                        else -> "保存考勤记录"
                    }
                )
            }

            HorizontalDivider()

            // SECTION 3: 近期考勤记录（同日同批次合并展示）
            Text("近期考勤记录（点垃圾桶可删除）", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold)
            SingleChoiceSegmentedButtonRow(modifier = Modifier.fillMaxWidth()) {
                listOf("today" to "今日", "week" to "本周", "month" to "本月", "semester" to "本学期").forEachIndexed { idx, (k, label) ->
                    SegmentedButton(
                        selected = historyScope == k,
                        onClick = {
                            historyScope = k
                            loadHistory(k)
                        },
                        shape = SegmentedButtonDefaults.itemShape(idx, 4)
                    ) { Text(label, style = MaterialTheme.typography.labelSmall) }
                }
            }

            if (loadingHistory) {
                Box(Modifier.fillMaxWidth().padding(12.dp), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator(modifier = Modifier.size(20.dp), strokeWidth = 2.dp)
                }
            } else if (groupedHistoryRecords.isEmpty()) {
                Text("该范围内暂无考勤记录", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
            } else {
                groupedHistoryRecords.forEach { group ->
                    val rec = group.primary
                    Row(modifier = Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                        StatusBadge(rec.titleText, statusColor(rec.badgeColorKey))
                        Spacer(Modifier.width(8.dp))
                        Column(modifier = Modifier.weight(1f)) {
                            Text(group.dateKey, style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.Medium)
                            if (group.periodDetailText.isNotBlank()) {
                                Text(group.periodDetailText, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                            }
                            group.remark?.takeIf { it.isNotBlank() }?.let { rm ->
                                Text(rm, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant, maxLines = 1, overflow = TextOverflow.Ellipsis)
                            }
                        }
                        IconButton(onClick = { performDeleteGroup(group) }) {
                            Icon(Icons.Rounded.Delete, contentDescription = "删除", tint = Color(0xFFDC2626))
                        }
                    }
                }
            }
            Spacer(Modifier.height(24.dp))
        }
    }

    groupPendingDelete?.let { target ->
        AlertDialog(
            onDismissRequest = { groupPendingDelete = null },
            title = { Text("确认撤销该条标记？") },
            text = { Text("将删除 $dateKey「${target.primary.titleText} · ${target.periodDetailText}」考勤记录。") },
            confirmButton = {
                TextButton(
                    onClick = {
                        groupPendingDelete = null
                        performDeleteGroup(target)
                    },
                    colors = ButtonDefaults.textButtonColors(contentColor = Color(0xFFDC2626))
                ) { Text("确认撤销") }
            },
            dismissButton = {
                TextButton(onClick = { groupPendingDelete = null }) { Text("取消") }
            }
        )
    }
}

private data class StatDetailTarget(
    val title: String,
    val typeName: String?,
    val status: String?,
    val leaveTypeId: Int?
)

private data class StudentRecordDrillTarget(
    val studentId: Int,
    val studentName: String,
    val studentNo: String?,
    val className: String?
) {
    val displayTitle: String
        get() = if (!studentNo.isNullOrBlank() && studentNo.length >= 2) {
            "${studentNo.takeLast(2)} $studentName"
        } else studentName
}

private data class StudentRecordTypeFilter(
    val typeName: String,
    val status: String?,
    val leaveTypeId: Int?
)

private enum class StudentRecordScopeMode(val label: String) {
    DAY("日"),
    WEEK("本周"),
    MONTH("本月"),
    PICK_MONTH("选月"),
    SEMESTER("本学期")
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun TeacherStatDetailModal(
    target: StatDetailTarget,
    scopeKey: String,
    semesterId: Int?,
    semesters: List<Semester>,
    classPeriods: List<ClassPeriod>,
    timeSlots: List<TimeSlot>,
    onDismiss: () -> Unit
) {
    val repo = SmartCampusApp.container.repository
    val scope = rememberCoroutineScope()
    val sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true)
    val detailColumns by SmartCampusApp.container.prefs.statDetailColumnsFlow.collectAsStateWithLifecycle(initialValue = 1)
    var presentList by remember { mutableStateOf<List<PresentDetailStudent>>(emptyList()) }
    var otherList by remember { mutableStateOf<List<NonPresentDetailStudent>>(emptyList()) }
    var presentFilter by remember { mutableStateOf("all") } // "all" | "present" | "absent" | "has_records"
    var selectedDrillStudent by remember { mutableStateOf<StudentRecordDrillTarget?>(null) }
    var loading by remember { mutableStateOf(true) }

    val statusKey = target.status
    val regularPeriods = remember(classPeriods) { classPeriods.filter { it.scene != "evening_study" } }

    val presentCount = remember(presentList) { presentList.count { it.isPresent } }
    val absentCount = remember(presentList) { presentList.count { !it.isPresent } }
    val hasRecordsCount = remember(presentList) { presentList.count { it.hasRecords } }
    val filteredPresentList = remember(presentList, presentFilter) {
        when (presentFilter) {
            "present" -> presentList.filter { it.isPresent }
            "absent" -> presentList.filter { !it.isPresent }
            "has_records" -> presentList.filter { it.hasRecords }
            else -> presentList
        }
    }

    val activeTypeFilter = remember(target) {
        if (target.status == "present") null
        else StudentRecordTypeFilter(
            typeName = target.typeName ?: target.title.removeSuffix("明细"),
            status = target.status,
            leaveTypeId = target.leaveTypeId
        )
    }

    LaunchedEffect(target, scopeKey, semesterId) {
        loading = true
        if (statusKey == "present") {
            presentList = runCatching { repo.fetchPresentDetails(scopeKey, semesterId) }.getOrDefault(emptyList())
        } else {
            otherList = runCatching { repo.fetchNonPresentDetails(scopeKey, statusKey, target.leaveTypeId, semesterId) }.getOrDefault(emptyList())
        }
        loading = false
    }

    ModalBottomSheet(onDismissRequest = onDismiss, sheetState = sheetState) {
        val drillStudent = selectedDrillStudent
        if (drillStudent != null) {
            StudentStatRecordsDrillContent(
                student = drillStudent,
                initialScope = scopeKey,
                initialSemesterId = semesterId,
                semesters = semesters,
                typeFilter = activeTypeFilter,
                classPeriods = regularPeriods,
                timeSlots = timeSlots,
                onBack = { selectedDrillStudent = null },
                onClose = onDismiss
            )
            return@ModalBottomSheet
        }

        Column(modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 8.dp)) {
            Row(
                modifier = Modifier.fillMaxWidth(),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.SpaceBetween
            ) {
                Text(target.title, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                IconButton(onClick = onDismiss) {
                    Icon(Icons.Rounded.Close, contentDescription = "关闭")
                }
            }
            Spacer(Modifier.height(6.dp))
            if (loading) {
                Box(Modifier.fillMaxWidth().padding(24.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
            } else if (statusKey == "present" && presentList.isEmpty()) {
                Text("暂无出勤记录", color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.padding(vertical = 20.dp))
            } else if (statusKey != "present" && otherList.isEmpty()) {
                Text("暂无考勤记录", color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.padding(vertical = 20.dp))
            } else {
                // 顶部控制栏：出勤明细显示 全部/出勤/缺勤/有记录 + 1列/2列；各类考勤显示总人数 + 1列/2列
                Row(
                    modifier = Modifier.fillMaxWidth(),
                    verticalAlignment = Alignment.CenterVertically,
                    horizontalArrangement = Arrangement.spacedBy(8.dp)
                ) {
                    if (statusKey == "present") {
                        Row(
                            modifier = Modifier.weight(1f).horizontalScroll(rememberScrollState()),
                            horizontalArrangement = Arrangement.spacedBy(6.dp),
                            verticalAlignment = Alignment.CenterVertically
                        ) {
                            listOf(
                                Triple("all", "全部", presentList.size),
                                Triple("present", "出勤", presentCount),
                                Triple("absent", "缺勤", absentCount),
                                Triple("has_records", "有记录", hasRecordsCount)
                            ).forEach { (key, label, cnt) ->
                                val selected = presentFilter == key
                                Surface(
                                    shape = RoundedCornerShape(999.dp),
                                    color = if (selected) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.surfaceVariant,
                                    modifier = Modifier.clip(RoundedCornerShape(999.dp)).clickable {
                                        presentFilter = key
                                    }
                                ) {
                                    Text(
                                        "$label $cnt",
                                        style = MaterialTheme.typography.labelSmall,
                                        fontWeight = if (selected) FontWeight.SemiBold else FontWeight.Normal,
                                        color = if (selected) Color.White else MaterialTheme.colorScheme.onSurface,
                                        modifier = Modifier.padding(horizontal = 10.dp, vertical = 6.dp)
                                    )
                                }
                            }
                        }
                    } else {
                        Text(
                            "共 ${otherList.size} 人 · 点击卡片看历史记录",
                            style = MaterialTheme.typography.labelMedium,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                            modifier = Modifier.weight(1f)
                        )
                    }

                    Surface(
                        shape = RoundedCornerShape(9.dp),
                        color = MaterialTheme.colorScheme.surfaceVariant
                    ) {
                        Row(modifier = Modifier.padding(2.dp), horizontalArrangement = Arrangement.spacedBy(2.dp)) {
                            listOf(1, 2).forEach { cols ->
                                val active = detailColumns == cols
                                Surface(
                                    shape = RoundedCornerShape(7.dp),
                                    color = if (active) MaterialTheme.colorScheme.primary else Color.Transparent,
                                    modifier = Modifier.clip(RoundedCornerShape(7.dp)).clickable {
                                        scope.launch { SmartCampusApp.container.prefs.setStatDetailColumns(cols) }
                                    }
                                ) {
                                    Text(
                                        "${cols}列",
                                        style = MaterialTheme.typography.labelSmall,
                                        fontWeight = if (active) FontWeight.SemiBold else FontWeight.Normal,
                                        color = if (active) Color.White else MaterialTheme.colorScheme.onSurfaceVariant,
                                        modifier = Modifier.padding(horizontal = 8.dp, vertical = 5.dp)
                                    )
                                }
                            }
                        }
                    }
                }
                Spacer(Modifier.height(10.dp))

                if (statusKey == "present") {
                    if (filteredPresentList.isEmpty()) {
                        val filterLabel = when (presentFilter) {
                            "present" -> "出勤"
                            "absent" -> "缺勤"
                            "has_records" -> "有记录"
                            else -> "全部"
                        }
                        Box(Modifier.fillMaxWidth().padding(vertical = 28.dp), contentAlignment = Alignment.Center) {
                            Text("当前筛选「$filterLabel」下暂无学生", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                        }
                    } else if (detailColumns == 2) {
                        LazyColumn(modifier = Modifier.height(440.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                            items(filteredPresentList.chunked(2)) { rowItems ->
                                Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                    rowItems.forEach { s ->
                                        val isAbsent = !s.isPresent
                                        val rateColor = when {
                                            s.attendanceRate >= 95 -> Color(0xFF16A34A)
                                            isAbsent -> Color(0xFFDC2626)
                                            else -> Color(0xFFEA580C)
                                        }
                                        SCCard(
                                            modifier = Modifier.weight(1f),
                                            onClick = {
                                                selectedDrillStudent = StudentRecordDrillTarget(s.id, s.name, s.studentNo, s.className)
                                            }
                                        ) {
                                            Row(modifier = Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.SpaceBetween) {
                                                Text(s.name, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
                                                Spacer(Modifier.width(4.dp))
                                                StatusBadge(String.format(Locale.US, "%.0f%%", s.attendanceRate), rateColor)
                                            }
                                            Spacer(Modifier.height(4.dp))
                                            Row(modifier = Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                                                Text(
                                                    "${s.className.orEmpty()} · ${s.studentNo.orEmpty()}",
                                                    style = MaterialTheme.typography.labelSmall,
                                                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                                                    maxLines = 1,
                                                    overflow = TextOverflow.Ellipsis,
                                                    modifier = Modifier.weight(1f)
                                                )
                                                if (isAbsent) {
                                                    Spacer(Modifier.width(4.dp))
                                                    Box(modifier = Modifier.size(6.dp).clip(CircleShape).background(Color(0xFFDC2626)))
                                                } else if (s.hasRecords) {
                                                    Spacer(Modifier.width(4.dp))
                                                    Box(modifier = Modifier.size(6.dp).clip(CircleShape).background(Color(0xFFEA580C)))
                                                }
                                            }
                                        }
                                    }
                                    if (rowItems.size == 1) Spacer(Modifier.weight(1f))
                                }
                            }
                        }
                    } else {
                        LazyColumn(modifier = Modifier.height(440.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                            items(filteredPresentList, key = { it.id }) { s ->
                                val isAbsent = !s.isPresent
                                val rateColor = when {
                                    s.attendanceRate >= 95 -> Color(0xFF16A34A)
                                    isAbsent -> Color(0xFFDC2626)
                                    else -> Color(0xFFEA580C)
                                }
                                SCCard(
                                    onClick = {
                                        selectedDrillStudent = StudentRecordDrillTarget(s.id, s.name, s.studentNo, s.className)
                                    }
                                ) {
                                    Row(verticalAlignment = Alignment.CenterVertically) {
                                        Column(modifier = Modifier.weight(1f)) {
                                            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                                                Text(s.name, fontWeight = FontWeight.SemiBold)
                                                if (isAbsent) {
                                                    Surface(
                                                        shape = RoundedCornerShape(999.dp),
                                                        color = Color(0xFFDC2626).copy(alpha = 0.14f)
                                                    ) {
                                                        Text(
                                                            "缺勤",
                                                            style = MaterialTheme.typography.labelSmall,
                                                            fontWeight = FontWeight.Medium,
                                                            color = Color(0xFFDC2626),
                                                            modifier = Modifier.padding(horizontal = 6.dp, vertical = 2.dp)
                                                        )
                                                    }
                                                } else if (s.hasRecords) {
                                                    Surface(
                                                        shape = RoundedCornerShape(999.dp),
                                                        color = Color(0xFFEA580C).copy(alpha = 0.14f)
                                                    ) {
                                                        Text(
                                                            "有记录",
                                                            style = MaterialTheme.typography.labelSmall,
                                                            fontWeight = FontWeight.Medium,
                                                            color = Color(0xFFEA580C),
                                                            modifier = Modifier.padding(horizontal = 6.dp, vertical = 2.dp)
                                                        )
                                                    }
                                                }
                                            }
                                            Text("${s.className.orEmpty()} · ${s.studentNo.orEmpty()}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                                        }
                                        StatusBadge(String.format(Locale.US, "%.1f%%", s.attendanceRate), rateColor)
                                        Spacer(Modifier.width(4.dp))
                                        Icon(Icons.Rounded.ChevronRight, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.size(18.dp))
                                    }
                                }
                            }
                        }
                    }
                } else if (detailColumns == 2) {
                    LazyColumn(modifier = Modifier.height(440.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        items(otherList.chunked(2)) { rowItems ->
                            Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                rowItems.forEach { s ->
                                    val display = remember(s, scopeKey, regularPeriods, timeSlots) {
                                        StatDetailStudentDisplay.build(s, scopeKey, regularPeriods, timeSlots)
                                    }
                                    SCCard(
                                        modifier = Modifier.weight(1f),
                                        onClick = {
                                            selectedDrillStudent = StudentRecordDrillTarget(s.id, s.name, s.studentNo, s.className)
                                        }
                                    ) {
                                        Row(modifier = Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                                            Text(s.displayTitle, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
                                            Icon(Icons.Rounded.ChevronRight, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.size(15.dp))
                                        }
                                        s.className?.takeIf { it.isNotBlank() }?.let {
                                            Text(it, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant, maxLines = 1, overflow = TextOverflow.Ellipsis)
                                        }
                                        if (display.summaryHeadline.isNotEmpty()) {
                                            Spacer(Modifier.height(4.dp))
                                            Text(
                                                display.summaryHeadline,
                                                style = MaterialTheme.typography.labelSmall,
                                                fontWeight = FontWeight.Medium,
                                                color = Color(0xFFEA580C),
                                                maxLines = 2,
                                                overflow = TextOverflow.Ellipsis
                                            )
                                        }
                                        display.remark?.takeIf { it.isNotBlank() }?.let { remark ->
                                            Spacer(Modifier.height(2.dp))
                                            Text(
                                                "备注：$remark",
                                                style = MaterialTheme.typography.labelSmall,
                                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                                                maxLines = 1,
                                                overflow = TextOverflow.Ellipsis
                                            )
                                        }
                                        if (display.spanLines.isNotEmpty()) {
                                            Spacer(Modifier.height(3.dp))
                                            display.spanLines.take(2).forEach { line ->
                                                Text(
                                                    line.compactText,
                                                    style = MaterialTheme.typography.labelSmall,
                                                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                                                    maxLines = 2,
                                                    overflow = TextOverflow.Ellipsis
                                                )
                                            }
                                            if (display.spanLines.size > 2) {
                                                Text("+${display.spanLines.size - 2} 段", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                                            }
                                        }
                                    }
                                }
                                if (rowItems.size == 1) Spacer(Modifier.weight(1f))
                            }
                        }
                    }
                } else {
                    LazyColumn(modifier = Modifier.height(440.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        items(otherList, key = { it.id }) { s ->
                            val display = remember(s, scopeKey, regularPeriods, timeSlots) {
                                StatDetailStudentDisplay.build(s, scopeKey, regularPeriods, timeSlots)
                            }
                            SCCard(
                                onClick = {
                                    selectedDrillStudent = StudentRecordDrillTarget(s.id, s.name, s.studentNo, s.className)
                                }
                            ) {
                                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                                    Text(s.displayTitle, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
                                    Text(s.className.orEmpty(), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                                    Spacer(Modifier.width(4.dp))
                                    Icon(Icons.Rounded.ChevronRight, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.size(18.dp))
                                }
                                if (display.summaryHeadline.isNotEmpty()) {
                                    Spacer(Modifier.height(4.dp))
                                    Text(
                                        display.summaryHeadline,
                                        style = MaterialTheme.typography.bodySmall,
                                        fontWeight = FontWeight.Medium,
                                        color = Color(0xFFEA580C)
                                    )
                                }
                                display.remark?.takeIf { it.isNotBlank() }?.let { remark ->
                                    Spacer(Modifier.height(2.dp))
                                    Text(
                                        "备注：$remark",
                                        style = MaterialTheme.typography.labelSmall,
                                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                                        maxLines = 2,
                                        overflow = TextOverflow.Ellipsis
                                    )
                                }
                                if (display.spanLines.isNotEmpty()) {
                                    Spacer(Modifier.height(4.dp))
                                    Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
                                        display.spanLines.take(6).forEach { line ->
                                            Text(
                                                line.fullText,
                                                style = MaterialTheme.typography.labelSmall,
                                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                                                maxLines = 2,
                                                overflow = TextOverflow.Ellipsis
                                            )
                                        }
                                        if (display.spanLines.size > 6) {
                                            Text(
                                                "共 ${display.spanLines.size} 段记录",
                                                style = MaterialTheme.typography.labelSmall,
                                                color = MaterialTheme.colorScheme.onSurfaceVariant
                                            )
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
            Spacer(Modifier.height(16.dp))
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun StudentStatRecordsDrillContent(
    student: StudentRecordDrillTarget,
    initialScope: String,
    initialSemesterId: Int?,
    semesters: List<Semester>,
    typeFilter: StudentRecordTypeFilter?,
    classPeriods: List<ClassPeriod>,
    timeSlots: List<TimeSlot>,
    onBack: () -> Unit,
    onClose: () -> Unit
) {
    val repo = SmartCampusApp.container.repository
    val scope = rememberCoroutineScope()
    val recordColumns by SmartCampusApp.container.prefs.studentRecordsColumnsFlow.collectAsStateWithLifecycle(initialValue = 1)
    val effectiveColumns = recordColumns.coerceIn(1, 8)
    val today = remember { LocalDate.now() }

    val defaultMode = remember(initialScope) {
        when (initialScope) {
            "today" -> StudentRecordScopeMode.DAY
            "week" -> StudentRecordScopeMode.WEEK
            "month" -> StudentRecordScopeMode.MONTH
            "semester" -> StudentRecordScopeMode.SEMESTER
            else -> StudentRecordScopeMode.MONTH
        }
    }

    var scopeMode by remember(student.studentId, initialScope) { mutableStateOf(defaultMode) }
    var selectedDay by remember(student.studentId) { mutableStateOf(today) }
    var selectedYearMonth by remember(student.studentId) { mutableStateOf(YearMonth.from(today)) }
    var selectedSemesterId by remember(student.studentId, initialSemesterId) { mutableStateOf(initialSemesterId) }
    var showDayPicker by remember { mutableStateOf(false) }
    var monthMenuOpen by remember { mutableStateOf(false) }
    var semMenuOpen by remember { mutableStateOf(false) }
    var customColsMenuOpen by remember { mutableStateOf(false) }
    var showCustomColsDialog by remember { mutableStateOf(false) }
    var customColsInput by remember { mutableStateOf("3") }
    var inspectedGroup by remember { mutableStateOf<DayRecordGroup?>(null) }

    var records by remember { mutableStateOf<List<AttendanceRecord>>(emptyList()) }
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<String?>(null) }

    val selectedSemester = remember(semesters, selectedSemesterId) {
        selectedSemesterId?.let { sid -> semesters.firstOrNull { it.id == sid } }
            ?: semesters.firstOrNull { it.isCurrent }
            ?: semesters.firstOrNull()
    }

    val monthOptions = remember(semesters, selectedYearMonth) {
        val set = linkedSetOf<YearMonth>()
        val nowYm = YearMonth.now()
        for (offset in -18L..2L) {
            set.add(nowYm.plusMonths(offset))
        }
        semesters.forEach { sem ->
            val start = SCDateFormatter.parseLocalDate(sem.startDate)
            if (start != null) {
                val weeks = sem.totalWeeks.coerceAtLeast(1)
                val end = start.plusDays(weeks * 7L - 1L)
                var cursor = YearMonth.from(start)
                val endYm = YearMonth.from(end)
                while (!cursor.isAfter(endYm)) {
                    set.add(cursor)
                    cursor = cursor.plusMonths(1)
                }
            }
        }
        set.add(selectedYearMonth)
        set.sortedDescending()
    }

    fun formatCnDateWithWeekday(d: LocalDate): String {
        val wd = when (d.dayOfWeek) {
            DayOfWeek.MONDAY -> "星期一"
            DayOfWeek.TUESDAY -> "星期二"
            DayOfWeek.WEDNESDAY -> "星期三"
            DayOfWeek.THURSDAY -> "星期四"
            DayOfWeek.FRIDAY -> "星期五"
            DayOfWeek.SATURDAY -> "星期六"
            DayOfWeek.SUNDAY -> "星期日"
        }
        return "${d.monthValue}月${d.dayOfMonth}日 $wd"
    }

    fun formatRecordDateWithWeekday(dateKey: String): String {
        val d = SCDateFormatter.parseLocalDate(dateKey) ?: return dateKey
        val wd = when (d.dayOfWeek) {
            DayOfWeek.MONDAY -> "周一"
            DayOfWeek.TUESDAY -> "周二"
            DayOfWeek.WEDNESDAY -> "周三"
            DayOfWeek.THURSDAY -> "周四"
            DayOfWeek.FRIDAY -> "周五"
            DayOfWeek.SATURDAY -> "周六"
            DayOfWeek.SUNDAY -> "周日"
        }
        return "$dateKey $wd"
    }

    val activeScopeSubtitle = remember(scopeMode, selectedDay, selectedYearMonth, selectedSemesterId, selectedSemester) {
        when (scopeMode) {
            StudentRecordScopeMode.DAY -> formatCnDateWithWeekday(selectedDay)
            StudentRecordScopeMode.WEEK -> "本周"
            StudentRecordScopeMode.MONTH -> "本月（${today.year}年${today.monthValue}月）"
            StudentRecordScopeMode.PICK_MONTH -> "${selectedYearMonth.year}年${selectedYearMonth.monthValue}月"
            StudentRecordScopeMode.SEMESTER -> selectedSemester?.name ?: "本学期"
        }
    }

    val typeBadgeColor = remember(typeFilter) {
        if (typeFilter == null) Color(0xFF4F46E5)
        else when {
            typeFilter.status == "absent" || typeFilter.typeName.contains("旷课") || typeFilter.typeName.contains("缺勤") -> Color(0xFFDC2626)
            typeFilter.status == "late" || typeFilter.status == "early_leave" -> Color(0xFFEA580C)
            typeFilter.typeName.contains("病假") -> Color(0xFF9333EA)
            typeFilter.typeName.contains("生理") -> Color(0xFFDB2777)
            else -> Color(0xFF2563EB)
        }
    }

    val filteredRecords = remember(records, typeFilter) {
        records.filter { rec ->
            if (rec.isNormalPresentRecord) return@filter false
            val tf = typeFilter ?: return@filter true
            if (rec.approvalStatus == "rejected") return@filter false

            when (tf.status) {
                "absent" -> rec.status == "absent" || rec.leaveTypeSlug == "absent" || rec.titleText.contains("旷课") || rec.titleText.contains("缺勤")
                "late" -> rec.status == "late" || rec.leaveTypeSlug == "late" || rec.titleText.contains("迟到")
                "early_leave" -> rec.status == "early_leave" || rec.leaveTypeSlug == "early_leave" || rec.titleText.contains("早退")
                else -> {
                    val byId = tf.leaveTypeId != null && rec.leaveTypeId == tf.leaveTypeId
                    val cleanName = tf.typeName.trim()
                    val byName = cleanName.isNotEmpty() && rec.titleText == cleanName
                    byId || byName
                }
            }
        }
    }

    val groupedRecords = remember(filteredRecords, classPeriods, timeSlots) {
        DayRecordGroup.build(filteredRecords, classPeriods, timeSlots, groupByDate = true)
            .sortedWith(compareByDescending<DayRecordGroup> { it.dateKey }.thenByDescending { it.primary.id })
    }

    val uniqueDaysCount = remember(groupedRecords) {
        groupedRecords.map { it.dateKey }.filter { it.isNotEmpty() }.distinct().size
    }

    val totalPeriodsCount = remember(groupedRecords) {
        groupedRecords.sumOf { g ->
            when {
                g.periodIds.isNotEmpty() -> g.periodIds.size
                else -> {
                    val optMax = g.records.mapNotNull { it.detailsOptionPeriods }.maxOrNull() ?: 0
                    if (optMax > 0) optMax else g.records.size.coerceAtLeast(1)
                }
            }
        }
    }

    LaunchedEffect(student.studentId, scopeMode, selectedDay, selectedYearMonth, selectedSemesterId, typeFilter) {
        loading = true
        error = null
        var reqScope = "month"
        var startDate: String? = null
        var endDate: String? = null
        var reqSemesterId: Int? = null

        when (scopeMode) {
            StudentRecordScopeMode.DAY -> {
                reqScope = "custom"
                val dk = selectedDay.format(SCDateFormatter.yyyyMMdd)
                startDate = dk
                endDate = dk
            }
            StudentRecordScopeMode.WEEK -> reqScope = "week"
            StudentRecordScopeMode.MONTH -> reqScope = "month"
            StudentRecordScopeMode.PICK_MONTH -> {
                reqScope = "custom"
                startDate = selectedYearMonth.atDay(1).format(SCDateFormatter.yyyyMMdd)
                endDate = selectedYearMonth.atEndOfMonth().format(SCDateFormatter.yyyyMMdd)
            }
            StudentRecordScopeMode.SEMESTER -> {
                reqSemesterId = selectedSemesterId
                val chosen = selectedSemesterId?.let { sid -> semesters.firstOrNull { it.id == sid } }
                val semStart = chosen?.takeIf { !it.isCurrent }?.let { SCDateFormatter.parseLocalDate(it.startDate) }
                if (chosen != null && semStart != null) {
                    reqScope = "custom"
                    val weeks = chosen.totalWeeks.coerceAtLeast(1)
                    startDate = semStart.format(SCDateFormatter.yyyyMMdd)
                    endDate = semStart.plusDays(weeks * 7L - 1L).format(SCDateFormatter.yyyyMMdd)
                } else {
                    reqScope = "semester"
                }
            }
        }

        runCatching {
            repo.fetchStudentRecords(
                studentId = student.studentId,
                scope = reqScope,
                startDate = startDate,
                endDate = endDate,
                semesterId = reqSemesterId,
                status = typeFilter?.status,
                leaveTypeId = typeFilter?.leaveTypeId
            )
        }.onSuccess {
            records = it
        }.onFailure {
            error = it.localizedMessage ?: "加载考勤记录失败"
            records = emptyList()
        }
        loading = false
    }

    Column(
        modifier = Modifier
            .fillMaxWidth()
            .padding(horizontal = 16.dp, vertical = 6.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp)
    ) {
        // 顶部返回与标题栏
        Row(
            modifier = Modifier.fillMaxWidth(),
            verticalAlignment = Alignment.CenterVertically
        ) {
            IconButton(onClick = onBack) {
                Icon(Icons.AutoMirrored.Rounded.ArrowBack, contentDescription = "返回明细")
            }
            Text(
                if (typeFilter == null) "${student.studentName} · 全部记录" else "${student.studentName} · ${typeFilter.typeName}",
                style = MaterialTheme.typography.titleMedium,
                fontWeight = FontWeight.Bold,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
                modifier = Modifier.weight(1f)
            )
            IconButton(onClick = onClose) {
                Icon(Icons.Rounded.Close, contentDescription = "关闭")
            }
        }

        // 学生信息与统计汇总卡
        SCCard {
            Row(modifier = Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Column(modifier = Modifier.weight(1f)) {
                    Text(student.displayTitle, style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold)
                    val sub = listOfNotNull(
                        student.className?.takeIf { it.isNotBlank() },
                        student.studentNo?.takeIf { it.isNotBlank() }
                    ).joinToString(" · ")
                    if (sub.isNotEmpty()) {
                        Text(sub, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                }
                StatusBadge(typeFilter?.typeName ?: "全部考勤", typeBadgeColor)
            }
            Spacer(Modifier.height(8.dp))
            HorizontalDivider()
            Spacer(Modifier.height(8.dp))
            Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
                Text(activeScopeSubtitle, style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                when {
                    loading -> Text("统计中...", style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    groupedRecords.isEmpty() -> Text("0 条记录", style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.SemiBold, color = Color(0xFF16A34A))
                    else -> Text(
                        "共 ${uniqueDaysCount} 天 · ${groupedRecords.size} 条 · ${totalPeriodsCount} 节",
                        style = MaterialTheme.typography.labelMedium,
                        fontWeight = FontWeight.SemiBold,
                        color = typeBadgeColor
                    )
                }
            }
        }

        // 5 档范围切换栏：日 / 本周 / 本月 / 选月 / 本学期
        Surface(
            shape = RoundedCornerShape(11.dp),
            color = MaterialTheme.colorScheme.surfaceVariant,
            modifier = Modifier.fillMaxWidth()
        ) {
            Row(modifier = Modifier.padding(3.dp), horizontalArrangement = Arrangement.spacedBy(3.dp)) {
                StudentRecordScopeMode.entries.forEach { mode ->
                    val selected = scopeMode == mode
                    Surface(
                        shape = RoundedCornerShape(8.dp),
                        color = if (selected) MaterialTheme.colorScheme.primary else Color.Transparent,
                        modifier = Modifier
                            .weight(1f)
                            .clip(RoundedCornerShape(8.dp))
                            .clickable { scopeMode = mode }
                    ) {
                        Text(
                            mode.label,
                            style = MaterialTheme.typography.labelSmall,
                            fontWeight = if (selected) FontWeight.SemiBold else FontWeight.Normal,
                            color = if (selected) Color.White else MaterialTheme.colorScheme.onSurface,
                            textAlign = TextAlign.Center,
                            maxLines = 1,
                            modifier = Modifier.padding(vertical = 7.dp)
                        )
                    }
                }
            }
        }

        // 动态子控制栏
        when (scopeMode) {
            StudentRecordScopeMode.DAY -> {
                Row(
                    modifier = Modifier.fillMaxWidth(),
                    verticalAlignment = Alignment.CenterVertically,
                    horizontalArrangement = Arrangement.spacedBy(8.dp)
                ) {
                    Surface(
                        shape = CircleShape,
                        color = MaterialTheme.colorScheme.surfaceVariant,
                        modifier = Modifier.size(34.dp).clip(CircleShape).clickable { selectedDay = selectedDay.minusDays(1) }
                    ) {
                        Box(contentAlignment = Alignment.Center) {
                            Icon(Icons.Rounded.ChevronLeft, contentDescription = "前一天", modifier = Modifier.size(20.dp))
                        }
                    }

                    Surface(
                        shape = RoundedCornerShape(10.dp),
                        color = MaterialTheme.colorScheme.surfaceVariant.copy(alpha = 0.55f),
                        modifier = Modifier.weight(1f).clip(RoundedCornerShape(10.dp)).clickable { showDayPicker = true }
                    ) {
                        Row(
                            modifier = Modifier.padding(vertical = 8.dp, horizontal = 10.dp),
                            verticalAlignment = Alignment.CenterVertically,
                            horizontalArrangement = Arrangement.Center
                        ) {
                            Icon(Icons.Rounded.CalendarMonth, contentDescription = null, tint = MaterialTheme.colorScheme.primary, modifier = Modifier.size(16.dp))
                            Spacer(Modifier.width(6.dp))
                            Text(formatCnDateWithWeekday(selectedDay), style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold)
                            Spacer(Modifier.width(4.dp))
                            Icon(Icons.Rounded.ExpandMore, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.size(16.dp))
                        }
                    }

                    Surface(
                        shape = CircleShape,
                        color = MaterialTheme.colorScheme.surfaceVariant,
                        modifier = Modifier.size(34.dp).clip(CircleShape).clickable { selectedDay = selectedDay.plusDays(1) }
                    ) {
                        Box(contentAlignment = Alignment.Center) {
                            Icon(Icons.Rounded.ChevronRight, contentDescription = "后一天", modifier = Modifier.size(20.dp))
                        }
                    }

                    if (selectedDay != today) {
                        Surface(
                            shape = CircleShape,
                            color = MaterialTheme.colorScheme.primary.copy(alpha = 0.14f),
                            modifier = Modifier.size(34.dp).clip(CircleShape).clickable { selectedDay = today }
                        ) {
                            Box(contentAlignment = Alignment.Center) {
                                Text("今", style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.SemiBold, color = MaterialTheme.colorScheme.primary)
                            }
                        }
                    }
                }
            }
            StudentRecordScopeMode.PICK_MONTH -> {
                Row(
                    modifier = Modifier.fillMaxWidth(),
                    verticalAlignment = Alignment.CenterVertically,
                    horizontalArrangement = Arrangement.spacedBy(8.dp)
                ) {
                    Surface(
                        shape = CircleShape,
                        color = MaterialTheme.colorScheme.surfaceVariant,
                        modifier = Modifier.size(34.dp).clip(CircleShape).clickable { selectedYearMonth = selectedYearMonth.minusMonths(1) }
                    ) {
                        Box(contentAlignment = Alignment.Center) {
                            Icon(Icons.Rounded.ChevronLeft, contentDescription = "上一月", modifier = Modifier.size(20.dp))
                        }
                    }

                    Box(modifier = Modifier.weight(1f)) {
                        Surface(
                            shape = RoundedCornerShape(10.dp),
                            color = MaterialTheme.colorScheme.surfaceVariant.copy(alpha = 0.55f),
                            modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(10.dp)).clickable { monthMenuOpen = true }
                        ) {
                            Row(
                                modifier = Modifier.padding(vertical = 8.dp, horizontal = 10.dp),
                                verticalAlignment = Alignment.CenterVertically,
                                horizontalArrangement = Arrangement.Center
                            ) {
                                Icon(Icons.Rounded.CalendarMonth, contentDescription = null, tint = MaterialTheme.colorScheme.primary, modifier = Modifier.size(16.dp))
                                Spacer(Modifier.width(6.dp))
                                Text("${selectedYearMonth.year}年${selectedYearMonth.monthValue}月", style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold)
                                Spacer(Modifier.width(4.dp))
                                Icon(Icons.Rounded.ExpandMore, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.size(16.dp))
                            }
                        }
                        DropdownMenu(expanded = monthMenuOpen, onDismissRequest = { monthMenuOpen = false }) {
                            monthOptions.forEach { ym ->
                                DropdownMenuItem(
                                    text = { Text("${ym.year}年${ym.monthValue}月") },
                                    trailingIcon = if (ym == selectedYearMonth) {
                                        { Icon(Icons.Rounded.Check, contentDescription = null, modifier = Modifier.size(16.dp)) }
                                    } else null,
                                    onClick = {
                                        selectedYearMonth = ym
                                        monthMenuOpen = false
                                    }
                                )
                            }
                        }
                    }

                    Surface(
                        shape = CircleShape,
                        color = MaterialTheme.colorScheme.surfaceVariant,
                        modifier = Modifier.size(34.dp).clip(CircleShape).clickable { selectedYearMonth = selectedYearMonth.plusMonths(1) }
                    ) {
                        Box(contentAlignment = Alignment.Center) {
                            Icon(Icons.Rounded.ChevronRight, contentDescription = "下一月", modifier = Modifier.size(20.dp))
                        }
                    }
                }
            }
            StudentRecordScopeMode.SEMESTER -> {
                if (semesters.size > 1) {
                    Box(modifier = Modifier.fillMaxWidth()) {
                        OutlinedButton(onClick = { semMenuOpen = true }, modifier = Modifier.fillMaxWidth()) {
                            Text(selectedSemesterId?.let { sid -> semesters.firstOrNull { it.id == sid }?.name } ?: "当前学期")
                            Spacer(Modifier.width(4.dp))
                            Icon(Icons.Rounded.ExpandMore, contentDescription = null, modifier = Modifier.size(18.dp))
                        }
                        DropdownMenu(expanded = semMenuOpen, onDismissRequest = { semMenuOpen = false }) {
                            DropdownMenuItem(
                                text = { Text("当前学期") },
                                trailingIcon = if (selectedSemesterId == null) {
                                    { Icon(Icons.Rounded.Check, contentDescription = null, modifier = Modifier.size(16.dp)) }
                                } else null,
                                onClick = { selectedSemesterId = null; semMenuOpen = false }
                            )
                            semesters.forEach { sem ->
                                DropdownMenuItem(
                                    text = { Text(sem.name) },
                                    trailingIcon = if (selectedSemesterId == sem.id) {
                                        { Icon(Icons.Rounded.Check, contentDescription = null, modifier = Modifier.size(16.dp)) }
                                    } else null,
                                    onClick = { selectedSemesterId = sem.id; semMenuOpen = false }
                                )
                            }
                        }
                    }
                }
            }
            else -> Unit
        }

        error?.let { ErrorBanner(message = it) }

        when {
            loading -> {
                Box(Modifier.fillMaxWidth().height(260.dp), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator()
                }
            }
            groupedRecords.isEmpty() -> {
                SCCard {
                    Column(
                        modifier = Modifier.fillMaxWidth().padding(vertical = 28.dp),
                        horizontalAlignment = Alignment.CenterHorizontally,
                        verticalArrangement = Arrangement.spacedBy(6.dp)
                    ) {
                        Icon(Icons.Rounded.CheckCircle, contentDescription = null, tint = Color(0xFF16A34A).copy(alpha = 0.65f), modifier = Modifier.size(32.dp))
                        Text(
                            if (typeFilter == null) "${activeScopeSubtitle}暂无考勤记录" else "${activeScopeSubtitle}暂无「${typeFilter.typeName}」记录",
                            style = MaterialTheme.typography.bodySmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant
                        )
                    }
                }
            }
            else -> {
                // 记录列数控制栏：1列 / 2列 / 自定义列（记忆选择）
                val isCustomActive = effectiveColumns >= 3
                Row(
                    modifier = Modifier.fillMaxWidth(),
                    verticalAlignment = Alignment.CenterVertically,
                    horizontalArrangement = Arrangement.SpaceBetween
                ) {
                    Text(
                        if (effectiveColumns >= 2) "共 ${groupedRecords.size} 条 · 点卡片看详情" else "共 ${groupedRecords.size} 条记录",
                        style = MaterialTheme.typography.labelMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis,
                        modifier = Modifier.weight(1f)
                    )
                    Spacer(Modifier.width(8.dp))
                    Surface(
                        shape = RoundedCornerShape(9.dp),
                        color = MaterialTheme.colorScheme.surfaceVariant
                    ) {
                        Row(modifier = Modifier.padding(2.dp), horizontalArrangement = Arrangement.spacedBy(2.dp)) {
                            listOf(1, 2).forEach { cols ->
                                val active = effectiveColumns == cols
                                Surface(
                                    shape = RoundedCornerShape(7.dp),
                                    color = if (active) MaterialTheme.colorScheme.primary else Color.Transparent,
                                    modifier = Modifier.clip(RoundedCornerShape(7.dp)).clickable {
                                        scope.launch { SmartCampusApp.container.prefs.setStudentRecordsColumns(cols) }
                                    }
                                ) {
                                    Text(
                                        "${cols}列",
                                        style = MaterialTheme.typography.labelSmall,
                                        fontWeight = if (active) FontWeight.SemiBold else FontWeight.Normal,
                                        color = if (active) Color.White else MaterialTheme.colorScheme.onSurfaceVariant,
                                        modifier = Modifier.padding(horizontal = 8.dp, vertical = 5.dp)
                                    )
                                }
                            }
                            Box {
                                Surface(
                                    shape = RoundedCornerShape(7.dp),
                                    color = if (isCustomActive) MaterialTheme.colorScheme.primary else Color.Transparent,
                                    modifier = Modifier.clip(RoundedCornerShape(7.dp)).clickable { customColsMenuOpen = true }
                                ) {
                                    Row(
                                        modifier = Modifier.padding(horizontal = 8.dp, vertical = 5.dp),
                                        verticalAlignment = Alignment.CenterVertically,
                                        horizontalArrangement = Arrangement.spacedBy(2.dp)
                                    ) {
                                        Text(
                                            if (isCustomActive) "${effectiveColumns}列" else "自定义",
                                            style = MaterialTheme.typography.labelSmall,
                                            fontWeight = if (isCustomActive) FontWeight.SemiBold else FontWeight.Normal,
                                            color = if (isCustomActive) Color.White else MaterialTheme.colorScheme.onSurfaceVariant
                                        )
                                        Icon(
                                            Icons.Rounded.ExpandMore,
                                            contentDescription = null,
                                            tint = if (isCustomActive) Color.White else MaterialTheme.colorScheme.onSurfaceVariant,
                                            modifier = Modifier.size(13.dp)
                                        )
                                    }
                                }
                                DropdownMenu(expanded = customColsMenuOpen, onDismissRequest = { customColsMenuOpen = false }) {
                                    listOf(3, 4, 5, 6).forEach { cols ->
                                        DropdownMenuItem(
                                            text = { Text("${cols}列") },
                                            trailingIcon = if (effectiveColumns == cols) {
                                                { Icon(Icons.Rounded.Check, contentDescription = null, modifier = Modifier.size(16.dp)) }
                                            } else null,
                                            onClick = {
                                                customColsMenuOpen = false
                                                scope.launch { SmartCampusApp.container.prefs.setStudentRecordsColumns(cols) }
                                            }
                                        )
                                    }
                                    HorizontalDivider()
                                    DropdownMenuItem(
                                        text = { Text("输入列数 (1-8)...") },
                                        leadingIcon = { Icon(Icons.Rounded.Tune, contentDescription = null, modifier = Modifier.size(16.dp)) },
                                        onClick = {
                                            customColsMenuOpen = false
                                            customColsInput = if (effectiveColumns >= 3) effectiveColumns.toString() else "3"
                                            showCustomColsDialog = true
                                        }
                                    )
                                }
                            }
                        }
                    }
                }

                fun shortDateLabel(dateKey: String, includeWeekday: Boolean): String {
                    val md = if (dateKey.length == 10) dateKey.substring(5) else dateKey
                    if (!includeWeekday) return md
                    val d = SCDateFormatter.parseLocalDate(dateKey) ?: return md
                    val wd = when (d.dayOfWeek) {
                        DayOfWeek.MONDAY -> "周一"
                        DayOfWeek.TUESDAY -> "周二"
                        DayOfWeek.WEDNESDAY -> "周三"
                        DayOfWeek.THURSDAY -> "周四"
                        DayOfWeek.FRIDAY -> "周五"
                        DayOfWeek.SATURDAY -> "周六"
                        DayOfWeek.SUNDAY -> "周日"
                    }
                    return "$md $wd"
                }

                if (effectiveColumns == 1) {
                    LazyColumn(
                        modifier = Modifier.fillMaxWidth().height(380.dp),
                        verticalArrangement = Arrangement.spacedBy(8.dp)
                    ) {
                        items(groupedRecords, key = { it.id }) { group ->
                            val rec = group.primary
                            SCCard {
                                Row(
                                    modifier = Modifier.fillMaxWidth(),
                                    verticalAlignment = Alignment.CenterVertically,
                                    horizontalArrangement = Arrangement.spacedBy(6.dp)
                                ) {
                                    Text(formatRecordDateWithWeekday(group.dateKey), style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold)
                                    StatusBadge(rec.titleText, statusColor(rec.badgeColorKey))
                                    rec.sourceBadgeText?.let { src ->
                                        Surface(shape = RoundedCornerShape(999.dp), color = MaterialTheme.colorScheme.surfaceVariant) {
                                            Text(
                                                src,
                                                style = MaterialTheme.typography.labelSmall,
                                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                                                modifier = Modifier.padding(horizontal = 6.dp, vertical = 2.dp)
                                            )
                                        }
                                    }
                                    rec.approvalStatus?.takeIf { it.isNotBlank() }?.let { ap ->
                                        StatusBadge(
                                            when (ap) {
                                                "pending" -> "待审"
                                                "approved" -> "已批"
                                                else -> "驳回"
                                            },
                                            statusColor(ap)
                                        )
                                    }
                                    Spacer(Modifier.weight(1f))
                                    rec.displayTimeText?.let { t ->
                                        Text(t, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                                    }
                                }
                                Spacer(Modifier.height(5.dp))
                                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                                    Text(
                                        "课节：${group.periodDetailText.ifBlank { "全天" }}",
                                        style = MaterialTheme.typography.bodySmall,
                                        fontWeight = FontWeight.Medium,
                                        modifier = Modifier.weight(1f, fill = false)
                                    )
                                    if (group.periodIds.size > 1 && !group.periodDetailText.contains("节)")) {
                                        Text("共${group.periodIds.size}节", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                                    }
                                }
                                rec.destination?.trim()?.takeIf { it.isNotEmpty() }?.let { dest ->
                                    Spacer(Modifier.height(2.dp))
                                    Text("去向：$dest", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                                }
                                group.remark?.takeIf { it.isNotBlank() }?.let { remark ->
                                    Spacer(Modifier.height(2.dp))
                                    Text("备注/事由：$remark", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                                }
                            }
                        }
                    }
                } else {
                    val gridSpacing = if (effectiveColumns >= 4) 6.dp else 8.dp
                    val isUltraDense = effectiveColumns >= 4
                    LazyColumn(
                        modifier = Modifier.fillMaxWidth().height(380.dp),
                        verticalArrangement = Arrangement.spacedBy(gridSpacing)
                    ) {
                        items(groupedRecords.chunked(effectiveColumns)) { rowItems ->
                            Row(
                                modifier = Modifier.fillMaxWidth(),
                                horizontalArrangement = Arrangement.spacedBy(gridSpacing)
                            ) {
                                rowItems.forEach { group ->
                                    val rec = group.primary
                                    val accent = statusColor(rec.badgeColorKey)
                                    if (effectiveColumns == 2) {
                                        Surface(
                                            shape = RoundedCornerShape(11.dp),
                                            color = MaterialTheme.colorScheme.surface,
                                            border = BorderStroke(1.dp, accent.copy(alpha = 0.24f)),
                                            modifier = Modifier
                                                .weight(1f)
                                                .clip(RoundedCornerShape(11.dp))
                                                .clickable { inspectedGroup = group }
                                        ) {
                                            Column(modifier = Modifier.padding(9.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                                                Row(modifier = Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.SpaceBetween) {
                                                    Text(
                                                        shortDateLabel(group.dateKey, includeWeekday = true),
                                                        style = MaterialTheme.typography.labelMedium,
                                                        fontWeight = FontWeight.SemiBold,
                                                        maxLines = 1,
                                                        overflow = TextOverflow.Ellipsis,
                                                        modifier = Modifier.weight(1f)
                                                    )
                                                    Spacer(Modifier.width(4.dp))
                                                    StatusBadge(rec.titleText, accent)
                                                }
                                                Text(
                                                    group.periodDetailText.ifBlank { "全天" },
                                                    style = MaterialTheme.typography.labelSmall,
                                                    fontWeight = FontWeight.Medium,
                                                    maxLines = 2,
                                                    overflow = TextOverflow.Ellipsis
                                                )
                                                Row(
                                                    modifier = Modifier.fillMaxWidth(),
                                                    verticalAlignment = Alignment.CenterVertically,
                                                    horizontalArrangement = Arrangement.spacedBy(4.dp)
                                                ) {
                                                    rec.sourceBadgeText?.let { src ->
                                                        Surface(shape = RoundedCornerShape(999.dp), color = MaterialTheme.colorScheme.surfaceVariant) {
                                                            Text(
                                                                src,
                                                                fontSize = 10.sp,
                                                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                                                                modifier = Modifier.padding(horizontal = 5.dp, vertical = 1.dp)
                                                            )
                                                        }
                                                    }
                                                    rec.approvalStatus?.takeIf { it.isNotBlank() }?.let { ap ->
                                                        Text(
                                                            when (ap) {
                                                                "pending" -> "待审"
                                                                "approved" -> "已批"
                                                                else -> "驳回"
                                                            },
                                                            fontSize = 10.sp,
                                                            fontWeight = FontWeight.SemiBold,
                                                            color = statusColor(ap)
                                                        )
                                                    }
                                                    Spacer(Modifier.weight(1f))
                                                    if (group.periodIds.size > 1) {
                                                        Text("${group.periodIds.size}节", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                                                    }
                                                }
                                                group.remark?.takeIf { it.isNotBlank() }?.let { rm ->
                                                    Text(rm, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant, maxLines = 1, overflow = TextOverflow.Ellipsis)
                                                }
                                            }
                                        }
                                    } else {
                                        val shortPeriod = if (group.periodIds.size > 1 && isUltraDense) {
                                            "${group.periodIds.size}节"
                                        } else {
                                            group.periodDetailText.ifBlank { "全天" }
                                        }
                                        Surface(
                                            shape = RoundedCornerShape(9.dp),
                                            color = accent.copy(alpha = 0.08f),
                                            border = BorderStroke(1.dp, accent.copy(alpha = 0.28f)),
                                            modifier = Modifier
                                                .weight(1f)
                                                .clip(RoundedCornerShape(9.dp))
                                                .clickable { inspectedGroup = group }
                                        ) {
                                            Column(
                                                modifier = Modifier.padding(if (isUltraDense) 6.dp else 7.dp),
                                                verticalArrangement = Arrangement.spacedBy(if (isUltraDense) 2.dp else 3.dp)
                                            ) {
                                                Row(modifier = Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                                                    Text(
                                                        shortDateLabel(group.dateKey, includeWeekday = !isUltraDense),
                                                        fontSize = if (isUltraDense) 10.sp else 11.sp,
                                                        fontWeight = FontWeight.SemiBold,
                                                        maxLines = 1,
                                                        overflow = TextOverflow.Ellipsis,
                                                        modifier = Modifier.weight(1f)
                                                    )
                                                    if (rec.approvalStatus == "pending" || rec.approvalStatus == "rejected") {
                                                        Box(modifier = Modifier.size(5.dp).clip(CircleShape).background(statusColor(rec.approvalStatus)))
                                                    }
                                                }
                                                Text(
                                                    rec.titleText,
                                                    fontSize = if (isUltraDense) 10.sp else 11.sp,
                                                    fontWeight = FontWeight.Bold,
                                                    color = accent,
                                                    maxLines = 1,
                                                    overflow = TextOverflow.Ellipsis
                                                )
                                                Text(
                                                    shortPeriod,
                                                    fontSize = if (isUltraDense) 9.5.sp else 10.sp,
                                                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                                                    maxLines = if (isUltraDense) 1 else 2,
                                                    overflow = TextOverflow.Ellipsis
                                                )
                                            }
                                        }
                                    }
                                }
                                repeat(effectiveColumns - rowItems.size) {
                                    Spacer(Modifier.weight(1f))
                                }
                            }
                        }
                    }
                }
            }
        }
        Spacer(Modifier.height(14.dp))
    }

    if (showCustomColsDialog) {
        AlertDialog(
            onDismissRequest = { showCustomColsDialog = false },
            title = { Text("自定义列数") },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text("请输入每行显示的列数（1 到 8 列），设置后将自动记忆。", style = MaterialTheme.typography.bodySmall)
                    OutlinedTextField(
                        value = customColsInput,
                        onValueChange = { s -> customColsInput = s.filter { it.isDigit() }.take(2) },
                        label = { Text("列数 (1 - 8)") },
                        singleLine = true,
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
                        modifier = Modifier.fillMaxWidth()
                    )
                }
            },
            confirmButton = {
                TextButton(onClick = {
                    val parsed = customColsInput.trim().toIntOrNull()
                    if (parsed != null && parsed in 1..8) {
                        scope.launch { SmartCampusApp.container.prefs.setStudentRecordsColumns(parsed) }
                        showCustomColsDialog = false
                    }
                }) { Text("确定") }
            },
            dismissButton = {
                TextButton(onClick = { showCustomColsDialog = false }) { Text("取消") }
            }
        )
    }

    inspectedGroup?.let { group ->
        val rec = group.primary
        AlertDialog(
            onDismissRequest = { inspectedGroup = null },
            title = { Text("记录详情") },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        Text(formatRecordDateWithWeekday(group.dateKey), style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                        StatusBadge(rec.titleText, statusColor(rec.badgeColorKey))
                        rec.approvalStatus?.takeIf { it.isNotBlank() }?.let { ap ->
                            StatusBadge(
                                when (ap) {
                                    "pending" -> "待审"
                                    "approved" -> "已批"
                                    else -> "驳回"
                                },
                                statusColor(ap)
                            )
                        }
                    }
                    rec.sourceBadgeText?.let { src ->
                        Text("来源：$src${rec.displayTimeText?.let { " · $it" } ?: ""}", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                    Text(
                        "课节：${group.periodDetailText.ifBlank { "全天" }}${if (group.periodIds.size > 1 && !group.periodDetailText.contains("节)")) "（共${group.periodIds.size}节）" else ""}",
                        style = MaterialTheme.typography.bodySmall,
                        fontWeight = FontWeight.Medium
                    )
                    rec.destination?.trim()?.takeIf { it.isNotEmpty() }?.let { dest ->
                        Text("去向：$dest", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                    group.remark?.takeIf { it.isNotBlank() }?.let { remark ->
                        Text("备注/事由：$remark", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                }
            },
            confirmButton = {
                TextButton(onClick = { inspectedGroup = null }) { Text("关闭") }
            }
        )
    }

    if (showDayPicker) {
        val pickerState = rememberDatePickerState(
            initialSelectedDateMillis = selectedDay.atStartOfDay(ZoneId.of("UTC")).toInstant().toEpochMilli()
        )
        DatePickerDialog(
            onDismissRequest = { showDayPicker = false },
            confirmButton = {
                TextButton(onClick = {
                    pickerState.selectedDateMillis?.let { millis ->
                        selectedDay = Instant.ofEpochMilli(millis).atZone(ZoneId.of("UTC")).toLocalDate()
                    }
                    showDayPicker = false
                }) { Text("确定") }
            },
            dismissButton = {
                TextButton(onClick = { showDayPicker = false }) { Text("取消") }
            }
        ) {
            DatePicker(state = pickerState)
        }
    }
}

private data class StatDetailSpanLine(
    val fullText: String,
    val compactText: String
)

private data class StatDetailStudentDisplay(
    val summaryHeadline: String,
    val remark: String?,
    val spanLines: List<StatDetailSpanLine>
) {
    private data class DayEntry(
        val dateKey: String,
        val batchKey: String?,
        val sourceLabel: String,
        val periodLabel: String,
        val periodCount: Int,
        val hasExplicitPeriods: Boolean,
        val remark: String?
    )

    private data class SpanBucket(
        val startDate: String,
        var endDate: String,
        val batchKey: String?,
        val sourceLabel: String,
        var periodLabel: String,
        var dayCount: Int,
        var totalPeriods: Int,
        val perDayPeriodCounts: MutableSet<Int>,
        var hasExplicitPeriods: Boolean
    )

    companion object {
        fun build(
            student: NonPresentDetailStudent,
            scopeKey: String,
            classPeriods: List<ClassPeriod>,
            timeSlots: List<TimeSlot>
        ): StatDetailStudentDisplay {
            val rawRecords = student.records
            if (rawRecords.isEmpty()) {
                return StatDetailStudentDisplay(
                    summaryHeadline = PeriodFormatter.compactDetailText(student.detail),
                    remark = null,
                    spanLines = emptyList()
                )
            }

            // 1. 按同日同批次分组
            val grouped = linkedMapOf<String, MutableList<AttendanceRecord>>()
            rawRecords.forEach { rec ->
                val dk = rec.dateKey
                val src = normalizedSourceTag(rec)
                val scene = rec.scene ?: "regular"
                val key = when {
                    !rec.leaveBatchId.isNullOrBlank() ->
                        "$dk|batch:${rec.leaveBatchId}|$scene|${rec.leaveTypeId ?: 0}|${rec.status}"
                    rec.periodId != null && src != "点名" ->
                        "$dk|periods|$scene|${rec.leaveTypeId ?: 0}|${rec.status}|$src"
                    else -> {
                        val idsKey = rec.extractedPeriodIds(classPeriods).joinToString(",")
                        val optKey = rec.detailsOption ?: rec.detailsDisplayLabel ?: rec.rollCallType ?: ""
                        "$dk|single|$scene|${rec.leaveTypeId ?: 0}|${rec.status}|$src|$idsKey|$optKey|${rec.detailsText.orEmpty()}"
                    }
                }
                grouped.getOrPut(key) { mutableListOf() }.add(rec)
            }

            val dayEntries = grouped.values.mapNotNull { recs ->
                val first = recs.firstOrNull() ?: return@mapNotNull null
                val dk = first.dateKey
                val sourceLabel = normalizedSourceTag(first)

                val periodIdSet = linkedSetOf<Int>()
                val detailNames = mutableListOf<String>()
                var explicitOptionPeriods = 0
                var foundRemark: String? = null

                recs.forEach { r ->
                    periodIdSet.addAll(r.extractedPeriodIds(classPeriods))
                    r.detailsPeriodNames.forEach { n ->
                        val t = n.trim()
                        if (t.isNotEmpty() && t !in detailNames) detailNames.add(t)
                    }
                    r.detailsOptionPeriods?.let { op ->
                        if (op > explicitOptionPeriods) explicitOptionPeriods = op
                    }
                    if (foundRemark == null) {
                        foundRemark = listOf(r.reason, r.note, r.detailsText)
                            .firstNotNullOfOrNull { it?.trim()?.takeIf { s -> s.isNotEmpty() } }
                    }
                }

                val sortedPeriodIds = periodIdSet.sorted()
                val (periodCount, hasExplicit) = when {
                    sortedPeriodIds.isNotEmpty() -> sortedPeriodIds.size to true
                    detailNames.isNotEmpty() -> detailNames.size to true
                    explicitOptionPeriods > 0 -> explicitOptionPeriods to true
                    sourceLabel == "点名" -> recs.size to true
                    else -> 0 to false
                }

                val periodLabel = buildDayPeriodLabel(
                    primary = first,
                    sourceLabel = sourceLabel,
                    periodIds = sortedPeriodIds,
                    detailNames = detailNames,
                    classPeriods = classPeriods,
                    timeSlots = timeSlots
                )

                DayEntry(
                    dateKey = dk,
                    batchKey = first.leaveBatchId?.takeIf { it.isNotBlank() },
                    sourceLabel = sourceLabel,
                    periodLabel = periodLabel,
                    periodCount = periodCount,
                    hasExplicitPeriods = hasExplicit,
                    remark = foundRemark
                )
            }.sortedWith(compareBy<DayEntry> { it.dateKey }.thenBy { it.sourceLabel })

            val studentRemark = dayEntries.firstNotNullOfOrNull { it.remark }

            // 2. 跨天连续日期折叠
            val buckets = mutableListOf<SpanBucket>()
            dayEntries.forEach { entry ->
                val last = buckets.lastOrNull()
                val isNextDay = last != null && isConsecutiveDate(last.endDate, entry.dateKey)
                val sameBatch = last != null && last.batchKey != null && last.batchKey == entry.batchKey
                val sameVisual = last != null && last.sourceLabel == entry.sourceLabel && last.periodLabel == entry.periodLabel
                if (last != null && isNextDay && (sameBatch || (last.batchKey == null && entry.batchKey == null && sameVisual))) {
                    last.endDate = entry.dateKey
                    last.dayCount += 1
                    last.totalPeriods += entry.periodCount
                    if (entry.periodCount > 0) last.perDayPeriodCounts.add(entry.periodCount)
                    last.hasExplicitPeriods = last.hasExplicitPeriods || entry.hasExplicitPeriods
                    if (last.periodLabel != entry.periodLabel && !sameVisual) {
                        last.periodLabel = "多时段"
                    }
                } else {
                    buckets.add(
                        SpanBucket(
                            startDate = entry.dateKey,
                            endDate = entry.dateKey,
                            batchKey = entry.batchKey,
                            sourceLabel = entry.sourceLabel,
                            periodLabel = entry.periodLabel,
                            dayCount = 1,
                            totalPeriods = entry.periodCount,
                            perDayPeriodCounts = if (entry.periodCount > 0) mutableSetOf(entry.periodCount) else mutableSetOf(),
                            hasExplicitPeriods = entry.hasExplicitPeriods
                        )
                    )
                }
            }

            val uniqueDays = dayEntries.map { it.dateKey }.filter { it.isNotEmpty() }.distinct().size
            val totalPeriods = dayEntries.sumOf { it.periodCount }
            val anyExplicit = dayEntries.any { it.hasExplicitPeriods }

            val summaryHeadline = when {
                scopeKey == "today" -> dayEntries.joinToString(" | ") { e ->
                    val suffix = if (e.hasExplicitPeriods && e.periodCount > 1 && !e.periodLabel.contains("节)")) {
                        " (${e.periodCount}节)"
                    } else ""
                    if (e.periodLabel.isEmpty()) e.sourceLabel else "${e.sourceLabel} · ${e.periodLabel}$suffix"
                }
                uniqueDays > 0 && anyExplicit && totalPeriods > 0 -> "${uniqueDays}天 · ${totalPeriods}节"
                uniqueDays > 0 -> "${uniqueDays}天"
                else -> PeriodFormatter.compactDetailText(student.detail)
            }

            if (scopeKey == "today" && buckets.size <= 1) {
                return StatDetailStudentDisplay(
                    summaryHeadline = summaryHeadline,
                    remark = studentRemark,
                    spanLines = emptyList()
                )
            }

            val spanLines = buckets.map { b ->
                val startShort = shortMonthDay(b.startDate)
                val endShort = shortMonthDay(b.endDate)
                val datePart = if (b.dayCount > 1 && startShort != endShort) {
                    "$startShort 至 $endShort（${b.dayCount}天）"
                } else startShort

                val periodSuffix = when {
                    b.dayCount > 1 && b.hasExplicitPeriods -> {
                        val perDay = b.perDayPeriodCounts.singleOrNull()
                        when {
                            perDay != null && perDay > 1 -> " (每天${perDay}节)"
                            b.totalPeriods > 0 -> " (共${b.totalPeriods}节)"
                            else -> ""
                        }
                    }
                    b.hasExplicitPeriods && b.totalPeriods > 1 && !b.periodLabel.contains("节)") ->
                        " (${b.totalPeriods}节)"
                    else -> ""
                }

                val detailPart = if (b.periodLabel.isEmpty()) "" else " · ${b.periodLabel}$periodSuffix"
                val full = "$datePart · ${b.sourceLabel}$detailPart"
                val compact = if (b.dayCount > 1) {
                    "$datePart · ${b.sourceLabel}"
                } else {
                    "$datePart · ${b.periodLabel.ifEmpty { b.sourceLabel }}"
                }
                StatDetailSpanLine(fullText = full, compactText = compact)
            }

            return StatDetailStudentDisplay(
                summaryHeadline = summaryHeadline,
                remark = studentRemark,
                spanLines = spanLines
            )
        }

        private fun normalizedSourceTag(rec: AttendanceRecord): String = when {
            rec.isSelfApplied || rec.sourceType == "self_applied" || rec.sourceType == "leave_request" -> "自请"
            rec.sourceType == "roll_call" -> "点名"
            else -> "登记"
        }

        private fun buildDayPeriodLabel(
            primary: AttendanceRecord,
            sourceLabel: String,
            periodIds: List<Int>,
            detailNames: List<String>,
            classPeriods: List<ClassPeriod>,
            timeSlots: List<TimeSlot>
        ): String {
            if (sourceLabel == "点名") {
                val rcType = primary.rollCallType?.trim().orEmpty()
                val rcTime = primary.rollCallTime?.trim().orEmpty()
                if (rcType.isNotEmpty()) {
                    return if (rcTime.isEmpty()) rcType else "$rcType ($rcTime)"
                }
                return "点名记录"
            }
            if (periodIds.isNotEmpty() && classPeriods.isNotEmpty()) {
                val summary = PeriodFormatter.formatDetailedPeriodSummary(
                    periodIds = periodIds,
                    periods = classPeriods,
                    timeSlots = timeSlots,
                    preferredSlotName = primary.detailsTimeSlotName
                )
                if (summary.isNotEmpty()) return summary
            }
            if (detailNames.isNotEmpty()) {
                val merged = PeriodFormatter.mergePeriodNames(detailNames, separator = "、")
                if (merged.isNotEmpty()) return merged
            }
            for (candidate in listOf(
                primary.detailsTimeSlotName,
                primary.detailsDisplayLabel,
                primary.detailsOptionLabel,
                primary.periodNameSnapshot
            )) {
                val trimmed = candidate?.trim().orEmpty()
                if (trimmed.isNotEmpty()) {
                    val compacted = PeriodFormatter.compactCalendarOption(trimmed).first
                    if (compacted.isNotEmpty()) return compacted
                }
            }
            return when (primary.detailsOption) {
                "morning_half" -> "上午"
                "afternoon_half" -> "下午"
                "full_day", "all_day" -> "全天"
                "morning_exercise" -> "早操"
                "evening_exercise" -> "晚操"
                else -> "全天"
            }
        }

        private fun isConsecutiveDate(d1: String, d2: String): Boolean {
            val date1 = runCatching { LocalDate.parse(d1) }.getOrNull() ?: return false
            val date2 = runCatching { LocalDate.parse(d2) }.getOrNull() ?: return false
            return date1.plusDays(1) == date2
        }

        private fun shortMonthDay(ymd: String): String =
            if (ymd.length == 10) ymd.substring(5) else ymd
    }
}

@OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)
@Composable
private fun AttendanceExportModal(semesters: List<Semester>, onDismiss: () -> Unit) {
    val context = LocalContext.current
    val repo = SmartCampusApp.container.repository
    val scope = rememberCoroutineScope()

    var exportScope by remember { mutableStateOf("month") }
    var exportFormat by remember { mutableStateOf("count") }
    var studentRange by remember { mutableStateOf("all") }
    var exporting by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    ModalBottomSheet(onDismissRequest = onDismiss) {
        Column(modifier = Modifier.fillMaxWidth().padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Text("导出考勤 Excel 报表", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
            error?.let { ErrorBanner(message = it) }

            Text("时间范围", style = MaterialTheme.typography.labelMedium)
            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                listOf("today" to "今日", "week" to "本周", "month" to "本月", "semester" to "本学期").forEach { (k, label) ->
                    FilterChip(selected = exportScope == k, onClick = { exportScope = k }, label = { Text(label) })
                }
            }

            Text("报表格式", style = MaterialTheme.typography.labelMedium)
            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                listOf("count" to "汇总统计表", "detail" to "逐条明细表").forEach { (k, label) ->
                    FilterChip(selected = exportFormat == k, onClick = { exportFormat = k }, label = { Text(label) })
                }
            }

            Text("学生范围", style = MaterialTheme.typography.labelMedium)
            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                listOf("all" to "全部学生", "with_records" to "仅含有考勤记录").forEach { (k, label) ->
                    FilterChip(selected = studentRange == k, onClick = { studentRange = k }, label = { Text(label) })
                }
            }

            Button(
                enabled = !exporting,
                modifier = Modifier.fillMaxWidth().height(48.dp),
                onClick = {
                    scope.launch {
                        exporting = true; error = null
                        runCatching {
                            val file = repo.downloadExportExcel(
                                scope = exportScope,
                                semesterId = semesters.firstOrNull { it.isCurrent }?.id,
                                exportFormat = exportFormat,
                                studentRange = studentRange
                            )
                            shareExcelFile(context, file)
                            onDismiss()
                        }.onFailure { error = it.localizedMessage ?: "导出失败" }
                        exporting = false
                    }
                }
            ) {
                Text(if (exporting) "正在生成报表..." else "生成并调用系统分享")
            }
            Spacer(Modifier.height(16.dp))
        }
    }
}

private fun shareExcelFile(context: Context, file: File) {
    val uri = FileProvider.getUriForFile(context, "${BuildConfig.APPLICATION_ID}.fileprovider", file)
    val intent = Intent(Intent.ACTION_SEND).apply {
        type = "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
        putExtra(Intent.EXTRA_STREAM, uri)
        addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
    }
    context.startActivity(Intent.createChooser(intent, "分享考勤报表"))
}

// MARK: - Leave Approvals Screen

private enum class ApprovalTimeScope(val label: String) {
    RECENT_30("近30天"),
    WEEK("教学周"),
    MONTH("按月"),
    SEMESTER("本学期"),
    ALL("全部")
}

private data class ApprovalDateRange(
    val from: String?,
    val to: String?,
    val summary: String
)

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun LeaveApprovalsScreen(
    openCurrentWeekTrigger: Int = 0,
    onBack: (() -> Unit)? = null,
    showTopBar: Boolean = true
) {
    val repo = SmartCampusApp.container.repository
    val scope = rememberCoroutineScope()
    val pageSize = 20
    val filters = listOf("pending" to "待审批", "approved" to "已通过", "rejected" to "已驳回", "all" to "全部")

    var statusFilter by remember { mutableStateOf("pending") }
    var timeScope by remember { mutableStateOf(ApprovalTimeScope.WEEK) }

    var semesters by remember { mutableStateOf<List<Semester>>(emptyList()) }
    var semestersLoaded by remember { mutableStateOf(false) }
    var leaveTypes by remember { mutableStateOf<List<LeaveType>>(emptyList()) }
    var selectedLeaveTypeOverrides by remember { mutableStateOf<Map<Int, Int>>(emptyMap()) }
    var selectedWeek by remember { mutableIntStateOf(1) }
    var selectedMonth by remember { mutableStateOf(YearMonth.now()) }
    var weekMenuOpen by remember { mutableStateOf(false) }
    var monthMenuOpen by remember { mutableStateOf(false) }

    var searchText by remember { mutableStateOf("") }
    var debouncedSearch by remember { mutableStateOf("") }

    var requests by remember { mutableStateOf<List<LeaveRequestGroup>>(emptyList()) }
    var currentPage by remember { mutableIntStateOf(1) }
    var totalCount by remember { mutableIntStateOf(0) }
    var hasMore by remember { mutableStateOf(false) }
    var loading by remember { mutableStateOf(false) }
    var loadingMore by remember { mutableStateOf(false) }
    var requestGen by remember { mutableIntStateOf(0) }
    var error by remember { mutableStateOf<String?>(null) }

    var rejectingTarget by remember { mutableStateOf<LeaveRequestGroup?>(null) }
    var rejectReason by remember { mutableStateOf("") }
    var scopeTarget by remember { mutableStateOf<LeaveRequestGroup?>(null) }
    var previewImage by remember { mutableStateOf<String?>(null) }
    var processingActions by remember { mutableStateOf<Map<Int, String>>(emptyMap()) }
    val snackbarHostState = remember { SnackbarHostState() }
    val haptic = LocalHapticFeedback.current

    val activeLeaveTypes = remember(leaveTypes) {
        val active = leaveTypes.filter { it.isActive }
        val leaveOnly = active.filter { it.mappedAttendanceStatus == "leave" }
        leaveOnly.ifEmpty { active }
    }

    val currentSemester = remember(semesters) {
        semesters.firstOrNull { it.isCurrent } ?: semesters.firstOrNull()
    }
    val totalWeeks = (currentSemester?.totalWeeks ?: 20).coerceAtLeast(1)
    val semesterBounds = remember(currentSemester, totalWeeks) {
        val start = SCDateFormatter.parseLocalDate(currentSemester?.startDate) ?: return@remember null
        val end = start.plusDays(totalWeeks.toLong() * 7L - 1L)
        start to end
    }
    val availableMonths = remember(semesterBounds) {
        val list = mutableListOf<YearMonth>()
        if (semesterBounds != null) {
            var cursor = YearMonth.from(semesterBounds.first)
            val endMonth = YearMonth.from(semesterBounds.second)
            while (!cursor.isAfter(endMonth)) {
                list.add(cursor)
                cursor = cursor.plusMonths(1)
            }
        }
        if (list.isEmpty()) {
            val now = YearMonth.now()
            for (i in 5 downTo 0) list.add(now.minusMonths(i.toLong()))
        }
        list.toList()
    }

    fun weekBounds(week: Int): Pair<LocalDate, LocalDate>? {
        val bounds = semesterBounds ?: return null
        val clamped = week.coerceIn(1, totalWeeks)
        val ws = bounds.first.plusDays((clamped - 1L) * 7L)
        return ws to ws.plusDays(6)
    }

    fun weekLabel(week: Int): String {
        val wb = weekBounds(week)
        return if (wb != null) {
            "第${week}周 (${wb.first.monthValue}/${wb.first.dayOfMonth}-${wb.second.monthValue}/${wb.second.dayOfMonth})"
        } else {
            "第${week}周"
        }
    }

    fun monthLabel(ym: YearMonth): String = "${ym.year}年${ym.monthValue}月"

    val activeRange = remember(statusFilter, timeScope, selectedWeek, selectedMonth, semesterBounds, currentSemester) {
        val today = LocalDate.now()
        when (timeScope) {
            ApprovalTimeScope.ALL -> ApprovalDateRange(
                from = null,
                to = null,
                summary = if (statusFilter == "pending") "全部待审批（不限时间，防漏批）" else "全部历史记录"
            )
            ApprovalTimeScope.RECENT_30 -> {
                val rawFrom = today.minusDays(29)
                if (semesterBounds != null) {
                    val effectiveFrom = if (today.isAfter(semesterBounds.second)) {
                        maxOf(semesterBounds.first, semesterBounds.second.minusDays(29))
                    } else {
                        maxOf(semesterBounds.first, rawFrom)
                    }
                    ApprovalDateRange(
                        from = effectiveFrom.toString(),
                        to = semesterBounds.second.toString(),
                        summary = "本学期近30天"
                    )
                } else {
                    ApprovalDateRange(from = rawFrom.toString(), to = null, summary = "近30天")
                }
            }
            ApprovalTimeScope.WEEK -> {
                val wb = weekBounds(selectedWeek)
                if (wb != null) {
                    ApprovalDateRange(wb.first.toString(), wb.second.toString(), weekLabel(selectedWeek))
                } else {
                    ApprovalDateRange(today.minusDays(6).toString(), today.toString(), "近7天")
                }
            }
            ApprovalTimeScope.MONTH -> {
                ApprovalDateRange(
                    from = selectedMonth.atDay(1).toString(),
                    to = selectedMonth.atEndOfMonth().toString(),
                    summary = monthLabel(selectedMonth)
                )
            }
            ApprovalTimeScope.SEMESTER -> {
                if (semesterBounds != null) {
                    ApprovalDateRange(
                        from = semesterBounds.first.toString(),
                        to = semesterBounds.second.toString(),
                        summary = currentSemester?.name ?: "本学期"
                    )
                } else {
                    ApprovalDateRange(null, null, "本学期")
                }
            }
        }
    }

    fun loadFirstPage(
        targetStatus: String = statusFilter,
        targetRange: ApprovalDateRange = activeRange,
        targetSearch: String = debouncedSearch
    ) {
        requestGen += 1
        val gen = requestGen
        scope.launch {
            loading = true
            loadingMore = false
            error = null
            runCatching {
                repo.fetchLeaveRequestsPage(
                    status = targetStatus,
                    dateFrom = targetRange.from,
                    dateTo = targetRange.to,
                    search = targetSearch,
                    page = 1,
                    perPage = pageSize
                )
            }.onSuccess { res ->
                if (gen == requestGen) {
                    requests = res.items
                    currentPage = res.currentPage
                    totalCount = res.total
                    hasMore = res.hasMore
                }
            }.onFailure {
                if (gen == requestGen) error = it.localizedMessage
            }
            if (gen == requestGen) loading = false
        }
    }

    fun loadMore() {
        if (!hasMore || loading || loadingMore) return
        val gen = requestGen
        val nextPage = currentPage + 1
        val targetStatus = statusFilter
        val targetRange = activeRange
        val targetSearch = debouncedSearch
        scope.launch {
            loadingMore = true
            runCatching {
                repo.fetchLeaveRequestsPage(
                    status = targetStatus,
                    dateFrom = targetRange.from,
                    dateTo = targetRange.to,
                    search = targetSearch,
                    page = nextPage,
                    perPage = pageSize
                )
            }.onSuccess { res ->
                if (gen == requestGen) {
                    val existing = requests.map { "${it.id}_${it.status}" }.toHashSet()
                    val appended = res.items.filter { "${it.id}_${it.status}" !in existing }
                    requests = requests + appended
                    currentPage = res.currentPage
                    totalCount = res.total
                    hasMore = res.hasMore
                }
            }.onFailure {
                if (gen == requestGen) error = it.localizedMessage
            }
            if (gen == requestGen) loadingMore = false
        }
    }

    // 初始化当前学期、假类列表与默认教学周/月份
    LaunchedEffect(Unit) {
        val list = runCatching { repo.fetchSemesters() }.getOrDefault(emptyList())
        leaveTypes = runCatching { repo.fetchLeaveTypes() }.getOrDefault(emptyList())
        semesters = list
        val cur = list.firstOrNull { it.isCurrent } ?: list.firstOrNull()
        val start = SCDateFormatter.parseLocalDate(cur?.startDate)
        val weeks = (cur?.totalWeeks ?: 20).coerceAtLeast(1)
        val today = LocalDate.now()
        if (start != null) {
            val diffDays = ChronoUnit.DAYS.between(start, today).toInt()
            selectedWeek = (diffDays / 7 + 1).coerceIn(1, weeks)
            val end = start.plusDays(weeks.toLong() * 7L - 1L)
            val nowYm = YearMonth.from(today)
            val startYm = YearMonth.from(start)
            val endYm = YearMonth.from(end)
            selectedMonth = when {
                nowYm.isBefore(startYm) -> startYm
                nowYm.isAfter(endYm) -> endYm
                else -> nowYm
            }
        } else if (timeScope == ApprovalTimeScope.WEEK) {
            timeScope = ApprovalTimeScope.ALL
        }
        semestersLoaded = true
    }

    // 从看板「待审批请假」卡片进入时，强制切换到「待审批 + 当前教学周」
    LaunchedEffect(openCurrentWeekTrigger, semestersLoaded) {
        if (openCurrentWeekTrigger <= 0 || !semestersLoaded) return@LaunchedEffect
        statusFilter = "pending"
        if (semesterBounds != null) {
            val diffDays = ChronoUnit.DAYS.between(semesterBounds.first, LocalDate.now()).toInt()
            selectedWeek = (diffDays / 7 + 1).coerceIn(1, totalWeeks)
            timeScope = ApprovalTimeScope.WEEK
        }
    }

    // 搜索防抖 350ms
    LaunchedEffect(searchText) {
        delay(350)
        val trimmed = searchText.trim()
        if (trimmed != debouncedSearch) {
            debouncedSearch = trimmed
        }
    }

    // 任一筛选条件就绪或变动时拉取第 1 页
    LaunchedEffect(semestersLoaded, statusFilter, activeRange.from, activeRange.to, debouncedSearch) {
        if (!semestersLoaded) return@LaunchedEffect
        loadFirstPage(statusFilter, activeRange, debouncedSearch)
    }

    Scaffold(
        snackbarHost = { SnackbarHost(snackbarHostState) },
        topBar = {
            if (showTopBar) {
                TopAppBar(
                    title = { Text("请假审批") },
                    navigationIcon = {
                        if (onBack != null) {
                            IconButton(onClick = onBack) {
                                Icon(Icons.AutoMirrored.Rounded.ArrowBack, contentDescription = "返回")
                            }
                        }
                    },
                    actions = { IconButton(onClick = { loadFirstPage() }) { Icon(Icons.Rounded.Refresh, contentDescription = "刷新") } }
                )
            }
        }
    ) { padding ->
        Column(modifier = Modifier.fillMaxSize().padding(padding).padding(horizontal = 16.dp)) {
            SingleChoiceSegmentedButtonRow(modifier = Modifier.fillMaxWidth().padding(top = 6.dp, bottom = 4.dp)) {
                filters.forEachIndexed { idx, (key, label) ->
                    SegmentedButton(
                        selected = statusFilter == key,
                        onClick = {
                            statusFilter = key
                        },
                        shape = SegmentedButtonDefaults.itemShape(idx, filters.size)
                    ) { Text(label) }
                }
            }

            // 时间筛选胶囊条
            Row(
                modifier = Modifier
                    .fillMaxWidth()
                    .horizontalScroll(rememberScrollState()),
                horizontalArrangement = Arrangement.spacedBy(8.dp)
            ) {
                ApprovalTimeScope.entries.forEach { item ->
                    if (item == ApprovalTimeScope.WEEK && semesterBounds == null) return@forEach
                    FilterChip(
                        selected = timeScope == item,
                        onClick = {
                            timeScope = item
                        },
                        label = { Text(item.label, style = MaterialTheme.typography.labelMedium) }
                    )
                }
            }

            // 教学周 / 月份二级切换器
            if (timeScope == ApprovalTimeScope.WEEK && semesterBounds != null) {
                Surface(
                    shape = RoundedCornerShape(10.dp),
                    color = MaterialTheme.colorScheme.surfaceVariant.copy(alpha = 0.6f),
                    modifier = Modifier.fillMaxWidth().padding(bottom = 6.dp)
                ) {
                    Row(
                        modifier = Modifier.fillMaxWidth().padding(horizontal = 4.dp, vertical = 2.dp),
                        verticalAlignment = Alignment.CenterVertically
                    ) {
                        IconButton(enabled = selectedWeek > 1, onClick = { selectedWeek -= 1 }) {
                            Icon(Icons.Rounded.ChevronLeft, contentDescription = "上一周")
                        }
                        Box(modifier = Modifier.weight(1f), contentAlignment = Alignment.Center) {
                            TextButton(onClick = { weekMenuOpen = true }) {
                                Text(weekLabel(selectedWeek), fontWeight = FontWeight.SemiBold)
                                Icon(Icons.Rounded.ExpandMore, contentDescription = null, modifier = Modifier.size(18.dp))
                            }
                            DropdownMenu(expanded = weekMenuOpen, onDismissRequest = { weekMenuOpen = false }) {
                                (1..totalWeeks).forEach { w ->
                                    DropdownMenuItem(
                                        text = { Text(weekLabel(w), fontWeight = if (w == selectedWeek) FontWeight.Bold else FontWeight.Normal) },
                                        onClick = { selectedWeek = w; weekMenuOpen = false }
                                    )
                                }
                            }
                        }
                        IconButton(enabled = selectedWeek < totalWeeks, onClick = { selectedWeek += 1 }) {
                            Icon(Icons.Rounded.ChevronRight, contentDescription = "下一周")
                        }
                    }
                }
            } else if (timeScope == ApprovalTimeScope.MONTH) {
                val monthIdx = availableMonths.indexOf(selectedMonth).let { if (it >= 0) it else availableMonths.lastIndex.coerceAtLeast(0) }
                Surface(
                    shape = RoundedCornerShape(10.dp),
                    color = MaterialTheme.colorScheme.surfaceVariant.copy(alpha = 0.6f),
                    modifier = Modifier.fillMaxWidth().padding(bottom = 6.dp)
                ) {
                    Row(
                        modifier = Modifier.fillMaxWidth().padding(horizontal = 4.dp, vertical = 2.dp),
                        verticalAlignment = Alignment.CenterVertically
                    ) {
                        IconButton(enabled = monthIdx > 0, onClick = { selectedMonth = availableMonths[monthIdx - 1] }) {
                            Icon(Icons.Rounded.ChevronLeft, contentDescription = "上一月")
                        }
                        Box(modifier = Modifier.weight(1f), contentAlignment = Alignment.Center) {
                            TextButton(onClick = { monthMenuOpen = true }) {
                                Text(monthLabel(selectedMonth), fontWeight = FontWeight.SemiBold)
                                Icon(Icons.Rounded.ExpandMore, contentDescription = null, modifier = Modifier.size(18.dp))
                            }
                            DropdownMenu(expanded = monthMenuOpen, onDismissRequest = { monthMenuOpen = false }) {
                                availableMonths.forEach { ym ->
                                    DropdownMenuItem(
                                        text = { Text(monthLabel(ym), fontWeight = if (ym == selectedMonth) FontWeight.Bold else FontWeight.Normal) },
                                        onClick = { selectedMonth = ym; monthMenuOpen = false }
                                    )
                                }
                            }
                        }
                        IconButton(enabled = monthIdx + 1 < availableMonths.size, onClick = { selectedMonth = availableMonths[monthIdx + 1] }) {
                            Icon(Icons.Rounded.ChevronRight, contentDescription = "下一月")
                        }
                    }
                }
            }

            OutlinedTextField(
                value = searchText,
                onValueChange = { searchText = it },
                placeholder = { Text("搜索学生姓名、学号或班级") },
                leadingIcon = { Icon(Icons.Rounded.Search, contentDescription = null) },
                singleLine = true,
                modifier = Modifier.fillMaxWidth()
            )

            Row(
                modifier = Modifier.fillMaxWidth().padding(top = 6.dp, bottom = 6.dp),
                verticalAlignment = Alignment.CenterVertically
            ) {
                Text(activeRange.summary, style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.weight(1f))
                Text(
                    text = if (loading && requests.isEmpty()) "加载中..." else "共 $totalCount 条",
                    style = MaterialTheme.typography.labelMedium,
                    fontWeight = FontWeight.SemiBold,
                    color = MaterialTheme.colorScheme.primary
                )
            }

            error?.let { ErrorBanner(message = it, onRetry = { loadFirstPage() }); Spacer(Modifier.height(8.dp)) }

            if (loading && requests.isEmpty()) {
                Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
            } else if (requests.isEmpty()) {
                Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                    Text(
                        if (statusFilter == "pending") "暂无待审批请假" else "当前筛选范围暂无记录",
                        color = MaterialTheme.colorScheme.onSurfaceVariant
                    )
                }
            } else {
                LazyColumn(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    items(requests, key = { "${it.id}_${it.status}" }) { req ->
                        val pendingSubs = req.records.filter { it.approvalStatus == "pending" }
                        SCCard {
                            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                                Column(modifier = Modifier.weight(1f)) {
                                    Text("${req.studentName}  ${req.className.orEmpty()}", fontWeight = FontWeight.Bold)
                                    req.studentNo?.let { Text("学号：$it", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant) }
                                }
                                StatusBadge(req.leaveTypeName, statusColor(req.leaveTypeColor))
                                StatusBadge(req.statusDisplayName, statusColor(req.status))
                            }
                            Spacer(Modifier.height(8.dp))
                            Text(req.dateRangeText, style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Medium)
                            req.periodLabel?.takeIf { it.isNotBlank() }?.let {
                                Text("节次/时段：$it", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                            }
                            req.eveningStudyLabel?.takeIf { it.isNotBlank() }?.let {
                                Text("夜自习：$it" + (req.destination?.let { d -> " · 去向：$d" }.orEmpty()), style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.primary)
                            }
                            req.displayReason?.takeIf { it.isNotBlank() }?.let {
                                Spacer(Modifier.height(6.dp))
                                Surface(shape = RoundedCornerShape(8.dp), color = MaterialTheme.colorScheme.surfaceVariant.copy(alpha = 0.65f)) {
                                    Text("事由：$it", style = MaterialTheme.typography.bodySmall, modifier = Modifier.fillMaxWidth().padding(10.dp))
                                }
                            }
                            if (req.images.isNotEmpty()) {
                                Spacer(Modifier.height(8.dp))
                                Row(modifier = Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                    req.images.forEach { path ->
                                        RemoteMediaThumb(pathOrUrl = path, modifier = Modifier.size(64.dp), onClick = { previewImage = path })
                                    }
                                }
                            }

                            if (req.status == "pending") {
                                val chosenTypeId = selectedLeaveTypeOverrides[req.id] ?: req.leaveTypeId
                                val chosenType = activeLeaveTypes.firstOrNull { it.id == chosenTypeId }
                                val isTypeChanged = chosenTypeId != null && req.leaveTypeId != null && chosenTypeId != req.leaveTypeId
                                var typeMenuOpen by remember(req.id) { mutableStateOf(false) }

                                Spacer(Modifier.height(10.dp))
                                HorizontalDivider()
                                Spacer(Modifier.height(8.dp))

                                // 参考 Web 端 ApprovalScopePanel：审批时直接可改「批准为」假类（病假/事假/特殊等）
                                Row(
                                    modifier = Modifier.fillMaxWidth(),
                                    verticalAlignment = Alignment.CenterVertically,
                                    horizontalArrangement = Arrangement.spacedBy(8.dp)
                                ) {
                                    if (activeLeaveTypes.isNotEmpty()) {
                                        Text(
                                            "批准为",
                                            style = MaterialTheme.typography.labelMedium,
                                            color = MaterialTheme.colorScheme.onSurfaceVariant
                                        )
                                        Box {
                                            Surface(
                                                shape = RoundedCornerShape(8.dp),
                                                color = if (isTypeChanged) MaterialTheme.colorScheme.primary.copy(alpha = 0.14f)
                                                else MaterialTheme.colorScheme.surfaceVariant,
                                                modifier = Modifier.clip(RoundedCornerShape(8.dp)).clickable { typeMenuOpen = true }
                                            ) {
                                                Row(
                                                    modifier = Modifier.padding(horizontal = 10.dp, vertical = 6.dp),
                                                    verticalAlignment = Alignment.CenterVertically,
                                                    horizontalArrangement = Arrangement.spacedBy(4.dp)
                                                ) {
                                                    Text(
                                                        chosenType?.name ?: req.leaveTypeName,
                                                        style = MaterialTheme.typography.labelMedium,
                                                        fontWeight = FontWeight.SemiBold,
                                                        color = if (isTypeChanged) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSurface
                                                    )
                                                    Icon(
                                                        Icons.Rounded.ExpandMore,
                                                        contentDescription = "切换假类",
                                                        modifier = Modifier.size(16.dp),
                                                        tint = if (isTypeChanged) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSurfaceVariant
                                                    )
                                                }
                                            }
                                            DropdownMenu(expanded = typeMenuOpen, onDismissRequest = { typeMenuOpen = false }) {
                                                activeLeaveTypes.forEach { lt ->
                                                    DropdownMenuItem(
                                                        text = {
                                                            Text(
                                                                lt.name,
                                                                fontWeight = if (lt.id == chosenTypeId) FontWeight.Bold else FontWeight.Normal
                                                            )
                                                        },
                                                        leadingIcon = if (lt.id == chosenTypeId) {
                                                            { Icon(Icons.Rounded.Check, contentDescription = null, modifier = Modifier.size(16.dp)) }
                                                        } else null,
                                                        onClick = {
                                                            selectedLeaveTypeOverrides = selectedLeaveTypeOverrides + (req.id to lt.id)
                                                            typeMenuOpen = false
                                                        }
                                                    )
                                                }
                                            }
                                        }
                                        if (isTypeChanged) {
                                            Text(
                                                "已改假类",
                                                style = MaterialTheme.typography.labelSmall,
                                                fontWeight = FontWeight.SemiBold,
                                                color = MaterialTheme.colorScheme.primary
                                            )
                                        }
                                    }

                                    Spacer(Modifier.weight(1f))

                                    val processingAction = processingActions[req.id]
                                    val isProcessing = processingAction != null

                                    if (pendingSubs.size > 1) {
                                        OutlinedButton(
                                            enabled = !isProcessing,
                                            onClick = {
                                                haptic.performHapticFeedback(HapticFeedbackType.TextHandleMove)
                                                scopeTarget = req
                                            },
                                            contentPadding = PaddingValues(horizontal = 10.dp, vertical = 4.dp)
                                        ) {
                                            Icon(Icons.Rounded.Checklist, contentDescription = null, modifier = Modifier.size(15.dp))
                                            Spacer(Modifier.width(4.dp))
                                            Text("选范围(${pendingSubs.size})", style = MaterialTheme.typography.labelMedium)
                                        }
                                    }
                                }

                                val rowProcessingAction = processingActions[req.id]
                                val rowIsProcessing = rowProcessingAction != null

                                Spacer(Modifier.height(8.dp))
                                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                    OutlinedButton(
                                        enabled = !rowIsProcessing,
                                        onClick = {
                                            haptic.performHapticFeedback(HapticFeedbackType.TextHandleMove)
                                            rejectReason = ""
                                            rejectingTarget = req
                                        },
                                        colors = ButtonDefaults.outlinedButtonColors(contentColor = Color(0xFFDC2626)),
                                        border = BorderStroke(1.dp, Color(0xFFDC2626).copy(alpha = 0.55f)),
                                        modifier = Modifier.weight(1f)
                                    ) {
                                        if (rowProcessingAction == "reject") {
                                            CircularProgressIndicator(modifier = Modifier.size(15.dp), strokeWidth = 2.dp, color = Color(0xFFDC2626))
                                            Spacer(Modifier.width(6.dp))
                                            Text("驳回中...")
                                        } else {
                                            Icon(Icons.Rounded.Close, contentDescription = null, modifier = Modifier.size(16.dp))
                                            Spacer(Modifier.width(4.dp))
                                            Text("驳回")
                                        }
                                    }

                                    Button(
                                        enabled = !rowIsProcessing,
                                        colors = ButtonDefaults.buttonColors(containerColor = Color(0xFF16A34A)),
                                        modifier = Modifier.weight(1f),
                                        onClick = {
                                            if (req.id in processingActions) return@Button
                                            haptic.performHapticFeedback(HapticFeedbackType.LongPress)
                                            scope.launch {
                                                processingActions = processingActions + (req.id to "approve")
                                                error = null
                                                runCatching {
                                                    repo.approveLeaveRequest(req.id, recordIds = null, leaveTypeId = chosenTypeId)
                                                }.onSuccess {
                                                    selectedLeaveTypeOverrides = selectedLeaveTypeOverrides - req.id
                                                    loadFirstPage()
                                                    snackbarHostState.showSnackbar("已批准 ${req.studentName} 的请假")
                                                }.onFailure { error = it.localizedMessage }
                                                processingActions = processingActions - req.id
                                            }
                                        }
                                    ) {
                                        if (rowProcessingAction == "approve") {
                                            CircularProgressIndicator(modifier = Modifier.size(15.dp), strokeWidth = 2.dp, color = Color.White)
                                            Spacer(Modifier.width(6.dp))
                                            Text("批准中...")
                                        } else {
                                            Icon(Icons.Rounded.Check, contentDescription = null, modifier = Modifier.size(16.dp))
                                            Spacer(Modifier.width(4.dp))
                                            Text(
                                                if (isTypeChanged) "按${chosenType?.name ?: "新类型"}批准" else "批准通过",
                                                maxLines = 1,
                                                overflow = TextOverflow.Ellipsis
                                            )
                                        }
                                    }
                                }
                            } else {
                                req.approverName?.let {
                                    Spacer(Modifier.height(6.dp))
                                    Text("审批人：$it · ${SCDateFormatter.displayDateTime(req.approvedAt ?: req.createdAt)}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                                }
                            }
                        }
                    }

                    // 方案 A：滚到底部自动加载下一页 + 总数进度提示
                    item(key = "load_more_footer") {
                        LaunchedEffect(requests.size, hasMore, loading, loadingMore) {
                            if (hasMore && !loading && !loadingMore) {
                                loadMore()
                            }
                        }
                        Box(
                            modifier = Modifier.fillMaxWidth().padding(vertical = 12.dp),
                            contentAlignment = Alignment.Center
                        ) {
                            when {
                                loadingMore -> Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                    CircularProgressIndicator(modifier = Modifier.size(16.dp), strokeWidth = 2.dp)
                                    Text("正在加载更多...", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                                }
                                hasMore -> TextButton(onClick = { loadMore() }) {
                                    Text("点击加载更多（已显示 ${requests.size} / $totalCount 条）")
                                }
                                else -> Text(
                                    "已显示全部 $totalCount 条记录",
                                    style = MaterialTheme.typography.bodySmall,
                                    color = MaterialTheme.colorScheme.onSurfaceVariant
                                )
                            }
                        }
                    }
                }
            }
        }
    }

    rejectingTarget?.let { target ->
        AlertDialog(
            onDismissRequest = { rejectingTarget = null },
            title = { Text("驳回 ${target.studentName} 的请假申请") },
            text = {
                OutlinedTextField(
                    value = rejectReason,
                    onValueChange = { rejectReason = it },
                    label = { Text("驳回原因（可选）") },
                    modifier = Modifier.fillMaxWidth()
                )
            },
            confirmButton = {
                TextButton(onClick = {
                    val reasonText = rejectReason
                    rejectingTarget = null
                    haptic.performHapticFeedback(HapticFeedbackType.LongPress)
                    scope.launch {
                        processingActions = processingActions + (target.id to "reject")
                        error = null
                        runCatching { repo.rejectLeaveRequest(target.id, reasonText) }
                            .onSuccess {
                                loadFirstPage()
                                snackbarHostState.showSnackbar("已驳回 ${target.studentName} 的请假")
                            }
                            .onFailure { error = it.localizedMessage }
                        processingActions = processingActions - target.id
                    }
                }) { Text("确认驳回", color = Color(0xFFDC2626)) }
            },
            dismissButton = { TextButton(onClick = { rejectingTarget = null }) { Text("取消") } }
        )
    }

    scopeTarget?.let { target ->
        val pendingSubs = target.records.filter { it.approvalStatus == "pending" }
        val groupedByDate = remember(target.id) {
            pendingSubs.groupBy { SCDateFormatter.normalizeDateKey(it.date).ifBlank { "未知日期" } }.toSortedMap()
        }
        var selectedIds by remember(target.id) { mutableStateOf(pendingSubs.map { it.id }.toSet()) }
        var chosenLeaveTypeId by remember(target.id) {
            mutableStateOf(selectedLeaveTypeOverrides[target.id] ?: target.leaveTypeId ?: activeLeaveTypes.firstOrNull()?.id)
        }
        var scopeTypeMenuOpen by remember(target.id) { mutableStateOf(false) }
        var scopeRejectReason by remember(target.id) { mutableStateOf("") }

        AlertDialog(
            onDismissRequest = { scopeTarget = null },
            title = { Text("选择审批范围 · ${target.studentName}") },
            text = {
                Column(
                    modifier = Modifier.verticalScroll(rememberScrollState()),
                    verticalArrangement = Arrangement.spacedBy(10.dp)
                ) {
                    if (activeLeaveTypes.isNotEmpty()) {
                        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            Text("批准为", style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                            Box(modifier = Modifier.weight(1f)) {
                                OutlinedButton(
                                    onClick = { scopeTypeMenuOpen = true },
                                    modifier = Modifier.fillMaxWidth(),
                                    contentPadding = PaddingValues(horizontal = 10.dp, vertical = 6.dp)
                                ) {
                                    Text(
                                        activeLeaveTypes.firstOrNull { it.id == chosenLeaveTypeId }?.name ?: target.leaveTypeName,
                                        modifier = Modifier.weight(1f)
                                    )
                                    Icon(Icons.Rounded.ExpandMore, contentDescription = null, modifier = Modifier.size(16.dp))
                                }
                                DropdownMenu(expanded = scopeTypeMenuOpen, onDismissRequest = { scopeTypeMenuOpen = false }) {
                                    activeLeaveTypes.forEach { lt ->
                                        DropdownMenuItem(
                                            text = { Text(lt.name) },
                                            leadingIcon = if (lt.id == chosenLeaveTypeId) {
                                                { Icon(Icons.Rounded.Check, contentDescription = null, modifier = Modifier.size(16.dp)) }
                                            } else null,
                                            onClick = { chosenLeaveTypeId = lt.id; scopeTypeMenuOpen = false }
                                        )
                                    }
                                }
                            }
                        }
                    }

                    Text(
                        "已选 ${selectedIds.size}/${pendingSubs.size} 项 · 未选部分会继续保留为待审批。",
                        style = MaterialTheme.typography.labelSmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant
                    )

                    groupedByDate.forEach { (dateKey, recs) ->
                        val dayIds = recs.map { it.id }
                        val allChecked = dayIds.all { it in selectedIds }
                        Surface(
                            shape = RoundedCornerShape(8.dp),
                            color = MaterialTheme.colorScheme.surfaceVariant.copy(alpha = 0.45f)
                        ) {
                            Column(modifier = Modifier.fillMaxWidth().padding(8.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                                Row(
                                    verticalAlignment = Alignment.CenterVertically,
                                    modifier = Modifier.fillMaxWidth().clickable {
                                        selectedIds = if (allChecked) selectedIds - dayIds.toSet() else selectedIds + dayIds
                                    }
                                ) {
                                    Checkbox(checked = allChecked, onCheckedChange = {
                                        selectedIds = if (it) selectedIds + dayIds else selectedIds - dayIds.toSet()
                                    })
                                    Text(dateKey, style = MaterialTheme.typography.labelLarge, fontWeight = FontWeight.SemiBold)
                                }
                                recs.forEach { sub ->
                                    val checked = sub.id in selectedIds
                                    Row(
                                        verticalAlignment = Alignment.CenterVertically,
                                        modifier = Modifier.fillMaxWidth().padding(start = 18.dp).clickable {
                                            selectedIds = if (checked) selectedIds - sub.id else selectedIds + sub.id
                                        }
                                    ) {
                                        Checkbox(checked = checked, onCheckedChange = {
                                            selectedIds = if (it) selectedIds + sub.id else selectedIds - sub.id
                                        })
                                        Text(
                                            sub.label ?: "全天",
                                            style = MaterialTheme.typography.bodySmall
                                        )
                                    }
                                }
                            }
                        }
                    }

                    OutlinedTextField(
                        value = scopeRejectReason,
                        onValueChange = { scopeRejectReason = it },
                        label = { Text("驳回原因（驳回选中项时填写）") },
                        singleLine = true,
                        modifier = Modifier.fillMaxWidth()
                    )
                }
            },
            confirmButton = {
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    TextButton(
                        enabled = selectedIds.isNotEmpty(),
                        colors = ButtonDefaults.textButtonColors(contentColor = Color(0xFFDC2626)),
                        onClick = {
                            val ids = selectedIds.toList()
                            val reason = scopeRejectReason.trim()
                            scopeTarget = null
                            scope.launch {
                                runCatching { repo.rejectLeaveRequest(target.id, reason, ids) }
                                    .onSuccess {
                                        selectedLeaveTypeOverrides = selectedLeaveTypeOverrides - target.id
                                        loadFirstPage()
                                    }
                                    .onFailure { error = it.localizedMessage }
                            }
                        }
                    ) { Text("驳回选中") }

                    Button(
                        enabled = selectedIds.isNotEmpty(),
                        colors = ButtonDefaults.buttonColors(containerColor = Color(0xFF16A34A)),
                        onClick = {
                            val ids = selectedIds.toList()
                            val ltId = chosenLeaveTypeId
                            scopeTarget = null
                            scope.launch {
                                runCatching { repo.approveLeaveRequest(target.id, ids, ltId) }
                                    .onSuccess {
                                        selectedLeaveTypeOverrides = selectedLeaveTypeOverrides - target.id
                                        loadFirstPage()
                                    }
                                    .onFailure { error = it.localizedMessage }
                            }
                        }
                    ) { Text("批准选中(${selectedIds.size})") }
                }
            },
            dismissButton = { TextButton(onClick = { scopeTarget = null }) { Text("关闭") } }
        )
    }

    previewImage?.let { FullScreenImageDialog(pathOrUrl = it, onDismiss = { previewImage = null }) }
}

// MARK: - Student List & Detail Management

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun StudentListScreen(onOpenStudent: (StudentListItem) -> Unit) {
    val repo = SmartCampusApp.container.repository
    val scope = rememberCoroutineScope()

    var classes by remember { mutableStateOf<List<ClassOptionItem>>(emptyList()) }
    var selectedClassId by remember { mutableStateOf<Int?>(null) }
    var students by remember { mutableStateOf<List<StudentListItem>>(emptyList()) }
    var searchText by remember { mutableStateOf("") }
    var loading by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var classMenuOpen by remember { mutableStateOf(false) }

    fun load() {
        scope.launch {
            loading = true; error = null
            runCatching { repo.fetchStudents(selectedClassId) }
                .onSuccess { students = it }
                .onFailure { error = it.localizedMessage }
            loading = false
        }
    }

    LaunchedEffect(Unit) {
        classes = runCatching { repo.fetchClasses() }.getOrDefault(emptyList())
        load()
    }
    LaunchedEffect(selectedClassId) { if (classes.isNotEmpty()) load() }

    val filtered = remember(students, searchText) {
        val q = searchText.trim().lowercase()
        if (q.isEmpty()) students
        else students.filter {
            it.name.lowercase().contains(q) || (it.studentNo ?: "").lowercase().contains(q) || (it.className ?: "").lowercase().contains(q)
        }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("学生管理（${filtered.size}）") },
                actions = { IconButton(onClick = { load() }) { Icon(Icons.Rounded.Refresh, contentDescription = "刷新") } }
            )
        }
    ) { padding ->
        Column(modifier = Modifier.fillMaxSize().padding(padding).padding(horizontal = 16.dp)) {
            if (classes.size > 1) {
                Box(modifier = Modifier.padding(top = 8.dp)) {
                    OutlinedButton(onClick = { classMenuOpen = true }, modifier = Modifier.fillMaxWidth()) {
                        Text(classes.firstOrNull { it.id == selectedClassId }?.name ?: "全部管辖班级")
                    }
                    DropdownMenu(expanded = classMenuOpen, onDismissRequest = { classMenuOpen = false }) {
                        DropdownMenuItem(text = { Text("全部管辖班级") }, onClick = { selectedClassId = null; classMenuOpen = false })
                        classes.forEach { c ->
                            DropdownMenuItem(text = { Text(c.name) }, onClick = { selectedClassId = c.id; classMenuOpen = false })
                        }
                    }
                }
            }
            Spacer(Modifier.height(8.dp))
            OutlinedTextField(
                value = searchText,
                onValueChange = { searchText = it },
                placeholder = { Text("搜索姓名、学号或班级") },
                leadingIcon = { Icon(Icons.Rounded.Search, contentDescription = null) },
                singleLine = true,
                modifier = Modifier.fillMaxWidth()
            )
            Spacer(Modifier.height(10.dp))
            error?.let { ErrorBanner(message = it, onRetry = { load() }); Spacer(Modifier.height(8.dp)) }

            if (loading && students.isEmpty()) {
                Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
            } else {
                LazyColumn(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    items(filtered, key = { it.id }) { s ->
                        SCCard(onClick = { onOpenStudent(s) }) {
                            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                                Box(
                                    modifier = Modifier.size(40.dp).clip(CircleShape).background(MaterialTheme.colorScheme.primaryContainer),
                                    contentAlignment = Alignment.Center
                                ) {
                                    Text(s.name.take(1), fontWeight = FontWeight.Bold, color = MaterialTheme.colorScheme.primary)
                                }
                                Column(modifier = Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                                    Row(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.CenterVertically) {
                                        Text(s.name, fontWeight = FontWeight.SemiBold)
                                        if (s.isBoarding) StatusBadge("住宿", MaterialTheme.colorScheme.primary)
                                        if (s.isClassAdmin) StatusBadge("考勤员", Color(0xFF2563EB))
                                        if (s.hasActiveSuspension) StatusBadge("停宿中", Color(0xFF9333EA))
                                    }
                                    Text("${s.className ?: "-"} · 学号 ${s.studentNo ?: "-"}", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                                }
                                Icon(Icons.Rounded.ChevronRight, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant)
                            }
                        }
                    }
                    item { Spacer(Modifier.height(12.dp)) }
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun StudentDetailManageScreen(student: StudentListItem, onBack: () -> Unit) {
    val repo = SmartCampusApp.container.repository
    val scope = rememberCoroutineScope()

    var currentStudent by remember(student.id) { mutableStateOf(student) }
    var isClassAdmin by remember(student.id) { mutableStateOf(student.isClassAdmin) }
    var suspensions by remember { mutableStateOf<List<BoardingSuspension>>(emptyList()) }
    var records by remember { mutableStateOf<List<AttendanceRecord>>(emptyList()) }
    var parentAccounts by remember { mutableStateOf<List<com.litotime.smartcampus.data.model.StudentParentAccount>>(emptyList()) }
    var parentAccountsError by remember { mutableStateOf<String?>(null) }
    var loadingParents by remember { mutableStateOf(false) }
    var resetParentTarget by remember { mutableStateOf<com.litotime.smartcampus.data.model.StudentParentAccount?>(null) }
    var resetParentPwd by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var successMessage by remember { mutableStateOf<String?>(null) }

    var showEditBasicInfo by remember { mutableStateOf(false) }
    var showCreateSuspension by remember { mutableStateOf(false) }
    var revokeTarget by remember { mutableStateOf<BoardingSuspension?>(null) }
    var revokeReason by remember { mutableStateOf("") }

    fun load(boarding: Boolean = currentStudent.isBoarding) {
        scope.launch {
            suspensions = if (boarding) {
                runCatching { repo.fetchSuspensions(currentStudent.id) }.getOrDefault(emptyList())
            } else {
                emptyList()
            }
            records = runCatching { repo.fetchStudentRecords(currentStudent.id, "month") }.getOrDefault(emptyList())
            loadingParents = true
            runCatching { repo.fetchStudentParents(currentStudent.id) }
                .onSuccess {
                    parentAccounts = it
                    parentAccountsError = null
                }
                .onFailure {
                    parentAccounts = emptyList()
                    parentAccountsError = it.message ?: "无法加载父母端账号"
                }
            loadingParents = false
        }
    }

    LaunchedEffect(student.id) { load(student.isBoarding) }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text(currentStudent.name) },
                navigationIcon = { IconButton(onClick = onBack) { Icon(Icons.AutoMirrored.Rounded.ArrowBack, contentDescription = "返回") } },
                actions = {
                    TextButton(onClick = {
                        error = null
                        successMessage = null
                        showEditBasicInfo = true
                    }) {
                        Text("编辑", fontWeight = FontWeight.SemiBold)
                    }
                }
            )
        }
    ) { padding ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(padding)
                .verticalScroll(rememberScrollState())
                .padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(14.dp)
        ) {
            error?.let { ErrorBanner(message = it) }
            successMessage?.let { msg ->
                Surface(
                    shape = RoundedCornerShape(12.dp),
                    color = Color(0xFFDCFCE7),
                    modifier = Modifier.fillMaxWidth()
                ) {
                    Row(
                        modifier = Modifier.padding(horizontal = 12.dp, vertical = 10.dp),
                        verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.spacedBy(8.dp)
                    ) {
                        Icon(Icons.Rounded.CheckCircle, contentDescription = null, tint = Color(0xFF16A34A), modifier = Modifier.size(18.dp))
                        Text(msg, style = MaterialTheme.typography.bodySmall, color = Color(0xFF166534), fontWeight = FontWeight.Medium)
                    }
                }
            }

            SCCard {
                Row(modifier = Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                    Text("基础信息", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                    OutlinedButton(
                        onClick = {
                            error = null
                            successMessage = null
                            showEditBasicInfo = true
                        },
                        contentPadding = PaddingValues(horizontal = 12.dp, vertical = 6.dp)
                    ) {
                        Icon(Icons.Rounded.Edit, contentDescription = null, modifier = Modifier.size(15.dp))
                        Spacer(Modifier.width(4.dp))
                        Text("修改信息", style = MaterialTheme.typography.labelMedium)
                    }
                }
                Spacer(Modifier.height(8.dp))
                Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    Text("姓名：${currentStudent.name}", style = MaterialTheme.typography.bodyMedium)
                    Text("学号：${currentStudent.studentNo ?: "-"}", style = MaterialTheme.typography.bodyMedium)
                    Text("性别：${currentStudent.genderDisplayText}", style = MaterialTheme.typography.bodyMedium)
                    Text("班级：${currentStudent.className ?: "-"}", style = MaterialTheme.typography.bodyMedium)
                    Text("住宿状态：${if (currentStudent.isBoarding) "住宿生" else "走读生"}", style = MaterialTheme.typography.bodyMedium)
                    Text("账号 (Email)：${currentStudent.email?.takeIf { it.isNotBlank() } ?: "未填写"}", style = MaterialTheme.typography.bodyMedium)
                    Text("家长电话：${currentStudent.parentContact?.takeIf { it.isNotBlank() } ?: "未填写"}", style = MaterialTheme.typography.bodyMedium)
                    Text("家长邮箱：${currentStudent.parentEmail?.takeIf { it.isNotBlank() } ?: "未填写"}", style = MaterialTheme.typography.bodyMedium)
                }
            }

            SCCard {
                Text("父母端账号", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold)
                Spacer(Modifier.height(8.dp))
                val pErr = parentAccountsError
                if (pErr != null) {
                    Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        Text(
                            "无法读取父母端账号（请确认后端服务已更新并完成数据库迁移）",
                            style = MaterialTheme.typography.bodySmall,
                            color = MaterialTheme.colorScheme.error,
                            fontWeight = FontWeight.Medium
                        )
                        Text(
                            pErr,
                            style = MaterialTheme.typography.labelSmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant
                        )
                        TextButton(
                            onClick = { load(currentStudent.isBoarding) },
                            contentPadding = PaddingValues(horizontal = 0.dp, vertical = 2.dp)
                        ) {
                            Text("重试加载")
                        }
                    }
                } else if (loadingParents && parentAccounts.isEmpty()) {
                    Text(
                        "正在加载父母端账号...",
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant
                    )
                } else if (parentAccounts.isEmpty()) {
                    val hasEmail = !currentStudent.parentEmail.isNullOrBlank()
                    Text(
                        if (hasEmail) {
                            "已有家长邮箱但尚未同步到父母端账号，请点击上方「修改信息」保存一次即可自动生成。"
                        } else {
                            "暂未关联父母端账号，请在基础信息中录入家长邮箱后自动生成。"
                        },
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant
                    )
                } else {
                    parentAccounts.forEachIndexed { idx, pa ->
                        Row(
                            modifier = Modifier.fillMaxWidth().padding(vertical = 6.dp),
                            verticalAlignment = Alignment.CenterVertically,
                            horizontalArrangement = Arrangement.spacedBy(8.dp)
                        ) {
                            Column(modifier = Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                                Text(pa.email, style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.Medium)
                                Row(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.CenterVertically) {
                                    Text(
                                        if (pa.isStaffAccount) "教职工复用账号" else "独立家长账号",
                                        style = MaterialTheme.typography.labelSmall,
                                        color = MaterialTheme.colorScheme.onSurfaceVariant
                                    )
                                    StatusBadge(
                                        text = if (pa.pushReady) "推送就绪" else "站内通知",
                                        color = if (pa.pushReady) Color(0xFF16A34A) else Color(0xFF64748B)
                                    )
                                }
                            }
                            if (pa.canResetPassword) {
                                OutlinedButton(
                                    onClick = {
                                        resetParentPwd = ""
                                        resetParentTarget = pa
                                    },
                                    contentPadding = PaddingValues(horizontal = 10.dp, vertical = 4.dp)
                                ) {
                                    Text("重置密码", style = MaterialTheme.typography.labelSmall)
                                }
                            }
                        }
                        if (idx != parentAccounts.lastIndex) HorizontalDivider()
                    }
                }
            }

            SCCard {
                Text("班级职务权限", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold)
                Spacer(Modifier.height(6.dp))
                Row(modifier = Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                    Text("班级考勤员（可代管考勤与审批）", modifier = Modifier.weight(1f))
                    Switch(checked = isClassAdmin, onCheckedChange = {
                        scope.launch {
                            runCatching { isClassAdmin = repo.toggleClassAdmin(currentStudent.id) }
                                .onSuccess { currentStudent = currentStudent.copy(isClassAdmin = isClassAdmin) }
                                .onFailure { error = it.localizedMessage }
                        }
                    })
                }
            }

            if (currentStudent.isBoarding) {
                SCCard {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text("临时停宿管理", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                        OutlinedButton(onClick = { showCreateSuspension = true }) {
                            Icon(Icons.Rounded.Nightlight, contentDescription = null, modifier = Modifier.size(16.dp))
                            Spacer(Modifier.width(4.dp))
                            Text("办理停宿")
                        }
                    }
                    Spacer(Modifier.height(8.dp))
                    if (suspensions.isEmpty()) {
                        Text("暂无停宿记录", color = MaterialTheme.colorScheme.onSurfaceVariant, style = MaterialTheme.typography.bodySmall)
                    } else {
                        suspensions.forEach { item ->
                            Column(modifier = Modifier.fillMaxWidth().padding(vertical = 6.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                                Row(verticalAlignment = Alignment.CenterVertically) {
                                    Text("${item.startDateDisplay} 至 ${item.endDateDisplay}", fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
                                    StatusBadge(if (item.isRevoked) "已撤销" else "生效中", if (item.isRevoked) Color(0xFF64748B) else Color(0xFF9333EA))
                                }
                                Text("原因：${item.reason}", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                                item.destination?.takeIf { it.isNotBlank() }?.let { Text("去向：$it", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.primary) }
                                if (!item.isRevoked) {
                                    TextButton(onClick = { revokeReason = ""; revokeTarget = item }) {
                                        Text("撤销停宿许可", color = Color(0xFFDC2626))
                                    }
                                }
                            }
                            HorizontalDivider()
                        }
                    }
                }
            }

            SCCard {
                Text("本月考勤情况", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold)
                Spacer(Modifier.height(8.dp))
                if (records.isEmpty()) {
                    Text("本月暂无考勤记录", color = MaterialTheme.colorScheme.onSurfaceVariant, style = MaterialTheme.typography.bodySmall)
                } else {
                    records.forEach { rec ->
                        Row(modifier = Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            StatusBadge(rec.titleText, statusColor(rec.badgeColorKey))
                            Column {
                                Text(rec.dateKey, style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.Medium)
                                if (rec.detailText.isNotBlank()) Text(rec.detailText, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                            }
                        }
                    }
                }
            }
        }
    }

    if (showEditBasicInfo) {
        EditStudentBasicInfoSheet(
            student = currentStudent,
            onDismiss = { showEditBasicInfo = false },
            onSaved = { updated ->
                currentStudent = updated
                showEditBasicInfo = false
                successMessage = "基础信息已保存"
                load(boarding = updated.isBoarding)
            }
        )
    }

    resetParentTarget?.let { target ->
        AlertDialog(
            onDismissRequest = { resetParentTarget = null },
            title = { Text("重置家长密码") },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text(
                        "家长账号：${target.email}\n留空将重置为系统默认初始密码；或输入至少 6 位新密码。",
                        style = MaterialTheme.typography.bodySmall
                    )
                    OutlinedTextField(
                        value = resetParentPwd,
                        onValueChange = { resetParentPwd = it },
                        label = { Text("新密码（留空使用系统默认）") },
                        singleLine = true,
                        modifier = Modifier.fillMaxWidth()
                    )
                }
            },
            confirmButton = {
                TextButton(onClick = {
                    val pwd = resetParentPwd.trim()
                    if (pwd.isNotEmpty() && pwd.length < 6) {
                        error = "新密码长度不能少于 6 位"
                        return@TextButton
                    }
                    resetParentTarget = null
                    scope.launch {
                        runCatching {
                            repo.resetStudentParentPassword(currentStudent.id, target.id, pwd.ifEmpty { null })
                        }.onSuccess { msg ->
                            error = null
                            successMessage = msg
                        }.onFailure { err ->
                            error = err.localizedMessage ?: "重置失败"
                        }
                    }
                }) { Text("确认重置") }
            },
            dismissButton = {
                TextButton(onClick = { resetParentTarget = null }) { Text("取消") }
            }
        )
    }

    if (showCreateSuspension) {
        var startStr by remember { mutableStateOf(SCDateFormatter.todayString()) }
        var endStr by remember { mutableStateOf(SCDateFormatter.todayString()) }
        var reason by remember { mutableStateOf("") }
        var dest by remember { mutableStateOf("") }
        AlertDialog(
            onDismissRequest = { showCreateSuspension = false },
            title = { Text("办理暂停住宿 - ${currentStudent.name}") },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    OutlinedTextField(value = startStr, onValueChange = { startStr = it }, label = { Text("开始日期 (YYYY-MM-DD)") }, singleLine = true)
                    OutlinedTextField(value = endStr, onValueChange = { endStr = it }, label = { Text("结束日期 (YYYY-MM-DD)") }, singleLine = true)
                    OutlinedTextField(value = reason, onValueChange = { reason = it }, label = { Text("原因（必填）") })
                    OutlinedTextField(value = dest, onValueChange = { dest = it }, label = { Text("去向（可选）") }, singleLine = true)
                }
            },
            confirmButton = {
                TextButton(enabled = reason.isNotBlank(), onClick = {
                    showCreateSuspension = false
                    scope.launch {
                        runCatching { repo.createSuspension(currentStudent.id, startStr.trim(), endStr.trim(), reason, dest) }
                            .onSuccess { load() }
                            .onFailure { error = it.localizedMessage }
                    }
                }) { Text("确认办理") }
            },
            dismissButton = { TextButton(onClick = { showCreateSuspension = false }) { Text("取消") } }
        )
    }

    revokeTarget?.let { target ->
        AlertDialog(
            onDismissRequest = { revokeTarget = null },
            title = { Text("撤销暂停住宿许可") },
            text = {
                OutlinedTextField(value = revokeReason, onValueChange = { revokeReason = it }, label = { Text("撤销原因（必填）") })
            },
            confirmButton = {
                TextButton(enabled = revokeReason.isNotBlank(), onClick = {
                    val r = revokeReason
                    revokeTarget = null
                    scope.launch {
                        runCatching { repo.revokeSuspension(target.id, r) }
                            .onSuccess { load() }
                            .onFailure { error = it.localizedMessage }
                    }
                }) { Text("确认撤销", color = Color(0xFFDC2626)) }
            },
            dismissButton = { TextButton(onClick = { revokeTarget = null }) { Text("取消") } }
        )
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun EditStudentBasicInfoSheet(
    student: StudentListItem,
    onDismiss: () -> Unit,
    onSaved: (StudentListItem) -> Unit
) {
    val repo = SmartCampusApp.container.repository
    val scope = rememberCoroutineScope()
    val sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true)

    var name by remember(student.id) { mutableStateOf(student.name) }
    var studentNo by remember(student.id) { mutableStateOf(student.studentNo.orEmpty()) }
    var gender by remember(student.id) { mutableStateOf(student.normalizedGender) }
    var isBoarding by remember(student.id) { mutableStateOf(student.isBoarding) }
    var email by remember(student.id) { mutableStateOf(student.email.orEmpty()) }
    var parentContact by remember(student.id) { mutableStateOf(student.parentContact.orEmpty()) }
    var parentEmail by remember(student.id) { mutableStateOf(student.parentEmail.orEmpty()) }
    var newPassword by remember(student.id) { mutableStateOf("") }

    var submitting by remember { mutableStateOf(false) }
    var formError by remember { mutableStateOf<String?>(null) }

    val genderOptions = listOf("male" to "男", "female" to "女", "other" to "其他")
    val canSubmit = !submitting && name.isNotBlank() && studentNo.isNotBlank() && email.isNotBlank()

    ModalBottomSheet(
        onDismissRequest = { if (!submitting) onDismiss() },
        sheetState = sheetState
    ) {
        Column(
            modifier = Modifier
                .fillMaxWidth()
                .imePadding()
                .verticalScroll(rememberScrollState())
                .padding(horizontal = 20.dp, vertical = 8.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp)
        ) {
            Text("编辑学生基础信息", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)

            formError?.let { ErrorBanner(message = it) }

            OutlinedTextField(
                value = name,
                onValueChange = { name = it },
                label = { Text("姓名（必填）") },
                singleLine = true,
                modifier = Modifier.fillMaxWidth()
            )
            OutlinedTextField(
                value = studentNo,
                onValueChange = { studentNo = it },
                label = { Text("学号（必填）") },
                singleLine = true,
                modifier = Modifier.fillMaxWidth()
            )

            Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                Text("性别", style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                SingleChoiceSegmentedButtonRow(modifier = Modifier.fillMaxWidth()) {
                    genderOptions.forEachIndexed { idx, (code, label) ->
                        SegmentedButton(
                            selected = gender == code,
                            onClick = { gender = code },
                            shape = SegmentedButtonDefaults.itemShape(idx, genderOptions.size)
                        ) { Text(label) }
                    }
                }
            }

            Row(modifier = Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Column(modifier = Modifier.weight(1f)) {
                    Text("住宿状态", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.Medium)
                    Text(if (isBoarding) "住宿生" else "走读生", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                }
                Switch(checked = isBoarding, onCheckedChange = { isBoarding = it })
            }

            OutlinedTextField(
                value = parentContact,
                onValueChange = { parentContact = it },
                label = { Text("家长联系电话") },
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone),
                singleLine = true,
                modifier = Modifier.fillMaxWidth()
            )
            OutlinedTextField(
                value = parentEmail,
                onValueChange = { parentEmail = it },
                label = { Text("家长邮箱（多个请用逗号分隔）") },
                supportingText = { Text("最多支持 10 个邮箱，留空表示清除") },
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email),
                minLines = 1,
                maxLines = 3,
                modifier = Modifier.fillMaxWidth()
            )

            HorizontalDivider()

            OutlinedTextField(
                value = email,
                onValueChange = { email = it },
                label = { Text("学生登录账号 Email（必填）") },
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email),
                singleLine = true,
                modifier = Modifier.fillMaxWidth()
            )
            OutlinedTextField(
                value = newPassword,
                onValueChange = { newPassword = it },
                label = { Text("重置密码（留空则不修改）") },
                supportingText = { Text("如需重置密码，请输入至少 6 位新密码") },
                visualTransformation = PasswordVisualTransformation(),
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password),
                singleLine = true,
                modifier = Modifier.fillMaxWidth()
            )

            Row(
                modifier = Modifier.fillMaxWidth().padding(top = 4.dp, bottom = 20.dp),
                horizontalArrangement = Arrangement.spacedBy(12.dp)
            ) {
                OutlinedButton(
                    onClick = onDismiss,
                    enabled = !submitting,
                    modifier = Modifier.weight(1f)
                ) { Text("取消") }

                Button(
                    enabled = canSubmit,
                    modifier = Modifier.weight(1f),
                    onClick = {
                        val trimmedName = name.trim()
                        val trimmedNo = studentNo.trim()
                        val trimmedEmail = email.trim()
                        val trimmedContact = parentContact.trim()
                        val trimmedParentEmail = parentEmail.trim()
                        val trimmedPwd = newPassword.trim()

                        if (trimmedName.isEmpty() || trimmedNo.isEmpty() || trimmedEmail.isEmpty()) {
                            formError = "姓名、学号与学生账号（Email）不能为空"
                            return@Button
                        }
                        if (trimmedPwd.isNotEmpty() && trimmedPwd.length < 6) {
                            formError = "重置密码长度不能少于 6 位"
                            return@Button
                        }

                        submitting = true
                        formError = null
                        scope.launch {
                            runCatching {
                                repo.updateStudentBasicInfo(
                                    studentId = student.id,
                                    name = trimmedName,
                                    studentNo = trimmedNo,
                                    gender = gender,
                                    isBoarding = isBoarding,
                                    email = trimmedEmail,
                                    parentContact = trimmedContact,
                                    parentEmail = trimmedParentEmail,
                                    password = trimmedPwd.ifEmpty { null }
                                )
                            }.onSuccess { normalizedParentEmail ->
                                submitting = false
                                onSaved(
                                    student.copy(
                                        name = trimmedName,
                                        studentNo = trimmedNo,
                                        gender = gender,
                                        isBoarding = isBoarding,
                                        email = trimmedEmail,
                                        parentContact = trimmedContact.ifEmpty { null },
                                        parentEmail = normalizedParentEmail ?: trimmedParentEmail.ifEmpty { null }
                                    )
                                )
                            }.onFailure {
                                submitting = false
                                formError = it.localizedMessage ?: "保存失败，请检查输入信息"
                            }
                        }
                    }
                ) {
                    if (submitting) {
                        CircularProgressIndicator(modifier = Modifier.size(16.dp), strokeWidth = 2.dp, color = MaterialTheme.colorScheme.onPrimary)
                        Spacer(Modifier.width(6.dp))
                        Text("保存中...")
                    } else {
                        Text("保存修改")
                    }
                }
            }
        }
    }
}
