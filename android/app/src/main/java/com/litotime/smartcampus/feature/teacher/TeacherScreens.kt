package com.litotime.smartcampus.feature.teacher

import android.content.Context
import android.content.Intent
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
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
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.ChevronLeft
import androidx.compose.material.icons.rounded.ChevronRight
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.EditNote
import androidx.compose.material.icons.rounded.ExpandMore
import androidx.compose.material.icons.rounded.FileDownload
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material.icons.rounded.Inbox
import androidx.compose.material.icons.rounded.Nightlight
import androidx.compose.material.icons.rounded.Notifications
import androidx.compose.material.icons.rounded.Refresh
import androidx.compose.material.icons.rounded.Search
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
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.FileProvider
import com.litotime.smartcampus.BuildConfig
import com.litotime.smartcampus.SmartCampusApp
import com.litotime.smartcampus.core.designsystem.ErrorBanner
import com.litotime.smartcampus.core.designsystem.FullScreenImageDialog
import com.litotime.smartcampus.core.designsystem.RemoteMediaThumb
import com.litotime.smartcampus.core.designsystem.SCCard
import com.litotime.smartcampus.core.designsystem.StatMetricCard
import com.litotime.smartcampus.core.designsystem.StatusBadge
import com.litotime.smartcampus.core.designsystem.statusColor
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
    var classPeriods by remember { mutableStateOf<List<ClassPeriod>>(emptyList()) }

    // Scheme A: Attendance Calendar + Linked Day Panel
    var calendarExpanded by remember { mutableStateOf(true) }
    var isWeekMode by remember { mutableStateOf(false) }
    var anchorDate by remember { mutableStateOf(LocalDate.now()) }
    var selectedDate by remember { mutableStateOf(LocalDate.now()) }
    var calendarSummary by remember { mutableStateOf<Map<String, List<CalendarSummaryRecord>>>(emptyMap()) }
    var loadingCalendar by remember { mutableStateOf(false) }
    var dayPanelMode by remember { mutableStateOf("summary") } // "summary" | "roster"
    var rosterSearch by remember { mutableStateOf("") }

    var overview by remember { mutableStateOf<List<OverviewDepartment>>(emptyList()) }
    var expandedDepts by remember { mutableStateOf<Set<String>>(emptySet()) }
    var expandedClasses by remember { mutableStateOf<Set<Int>>(emptySet()) }
    var loading by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    var selectedStudent by remember { mutableStateOf<OverviewStudent?>(null) }
    var statDetailTarget by remember { mutableStateOf<Triple<String, String?, Int?>?>(null) }
    var showExportModal by remember { mutableStateOf(false) }
    var semMenuOpen by remember { mutableStateOf(false) }

    val activeSemester = remember(semesters) { semesters.firstOrNull { it.isCurrent } ?: semesters.firstOrNull() }
    val canViewCalendarSummary = user.hasPermission("attendance.calendar_summary") || user.isTeacherOrAdmin
    val selectedDateKey = selectedDate.format(SCDateFormatter.yyyyMMdd)
    val selectedDayRecords = calendarSummary[selectedDateKey].orEmpty()
    val isSelectedHoliday = activeSemester?.holidays?.contains(selectedDateKey) == true

    val filteredRoster = remember(overview, rosterSearch) {
        val all = overview.flatMap { it.classes }.flatMap { it.students }
        val q = rosterSearch.trim().lowercase()
        if (q.isEmpty()) all
        else all.filter { it.name.lowercase().contains(q) || (it.studentNo ?: "").lowercase().contains(q) }
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
                runCatching { repo.fetchCalendarSummary(m) }.getOrNull()?.let { merged.putAll(it) }
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
        if (YearMonth.from(selectedDate) != YearMonth.from(anchorDate) && !isWeekMode) {
            anchorDate = selectedDate
        }
        loadOverview()
    }

    Scaffold(
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
                        onClick = { statDetailTarget = Triple("出勤明细", "present", null) }
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
                        onClick = { statDetailTarget = Triple("缺勤明细", "absent", null) }
                    )
                }

                if (st.leavesBreakdown.isNotEmpty()) {
                    SCCard {
                        Text("各类考勤异常分布", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold)
                        Spacer(Modifier.height(8.dp))
                        Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                            st.leavesBreakdown.entries.sortedBy { it.key }.forEach { (name, summaryText) ->
                                val matched = leaveTypes.firstOrNull { it.name == name }
                                val tint = statusColor(matched?.color ?: "blue")
                                Surface(
                                    shape = RoundedCornerShape(10.dp),
                                    color = tint.copy(alpha = 0.1f),
                                    modifier = Modifier.fillMaxWidth().clickable {
                                        statDetailTarget = Triple("${name}明细", matched?.slug, matched?.id)
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
                Row(modifier = Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                    Column(modifier = Modifier.weight(1f)) {
                        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                            Text(selectedDate.format(SCDateFormatter.cnMonthDay), style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold)
                            if (isSelectedHoliday) StatusBadge("休", Color(0xFFDC2626))
                        }
                        Text(
                            if (selectedDayRecords.isEmpty()) "当日暂无异常考勤" else "当日共 ${selectedDayRecords.size} 条异常考勤",
                            style = MaterialTheme.typography.labelSmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant
                        )
                    }
                    Button(
                        onClick = { dayPanelMode = if (dayPanelMode == "summary") "roster" else "summary" },
                        contentPadding = PaddingValues(horizontal = 12.dp, vertical = 6.dp),
                        shape = RoundedCornerShape(10.dp)
                    ) {
                        Icon(Icons.Rounded.EditNote, contentDescription = null, modifier = Modifier.size(16.dp))
                        Spacer(Modifier.width(4.dp))
                        Text(if (dayPanelMode == "summary") "登记/维护" else "看异常汇总", style = MaterialTheme.typography.labelMedium)
                    }
                }

                Spacer(Modifier.height(10.dp))

                SingleChoiceSegmentedButtonRow(modifier = Modifier.fillMaxWidth()) {
                    listOf("summary" to "异常汇总", "roster" to "全班登记").forEachIndexed { idx, (k, label) ->
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
                        Text("当前账号无日历异常摘要权限，可切换「全班登记」查看维护。", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    } else if (loadingCalendar && selectedDayRecords.isEmpty()) {
                        Box(Modifier.fillMaxWidth().padding(16.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
                    } else if (selectedDayRecords.isEmpty()) {
                        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.padding(vertical = 8.dp)) {
                            Icon(Icons.Rounded.CheckCircle, contentDescription = null, tint = Color(0xFF16A34A), modifier = Modifier.size(18.dp))
                            Text("该日期暂无请假、缺勤、迟到或早退记录", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                        }
                    } else {
                        Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                            selectedDayRecords.forEach { rec ->
                                Surface(
                                    shape = RoundedCornerShape(10.dp),
                                    color = MaterialTheme.colorScheme.surfaceVariant.copy(alpha = 0.65f),
                                    modifier = Modifier.fillMaxWidth().clickable {
                                        overview.flatMap { it.classes }.flatMap { it.students }
                                            .firstOrNull { it.name == rec.studentName }
                                            ?.let { selectedStudent = it }
                                    }
                                ) {
                                    Row(
                                        modifier = Modifier.padding(10.dp),
                                        verticalAlignment = Alignment.CenterVertically,
                                        horizontalArrangement = Arrangement.spacedBy(8.dp)
                                    ) {
                                        Box(modifier = Modifier.size(8.dp).clip(CircleShape).background(statusColor(rec.status)))
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
                                                    color = statusColor(rec.status)
                                                )
                                            }
                                            Text(rec.studentShortLabel, style = MaterialTheme.typography.labelMedium)
                                        }
                                        rec.time?.takeIf { it.isNotBlank() }?.let {
                                            Text(it, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                                        }
                                    }
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
                    if (filteredRoster.isEmpty()) {
                        Text("暂无匹配的学生数据", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    } else {
                        filteredRoster.forEach { s ->
                            Row(
                                modifier = Modifier
                                    .fillMaxWidth()
                                    .clickable { selectedStudent = s }
                                    .padding(vertical = 9.dp),
                                verticalAlignment = Alignment.CenterVertically
                            ) {
                                Column(modifier = Modifier.weight(1f)) {
                                    Text(s.name, style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.Medium)
                                    s.studentNo?.let { Text(it, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant) }
                                }
                                if (s.attendance.isEmpty()) {
                                    Text("正常出勤", style = MaterialTheme.typography.labelSmall, color = Color(0xFF16A34A))
                                } else {
                                    Row(horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                                        s.attendance.take(2).forEach { r -> StatusBadge(r.titleText, statusColor(r.badgeColorKey)) }
                                    }
                                }
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
                                    val abnormalCount = cls.students.count { it.hasRecords }
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
                                            Row(
                                                modifier = Modifier
                                                    .fillMaxWidth()
                                                    .clickable { selectedStudent = s }
                                                    .padding(start = 20.dp, end = 8.dp, top = 8.dp, bottom = 8.dp),
                                                verticalAlignment = Alignment.CenterVertically
                                            ) {
                                                Column(modifier = Modifier.weight(1f)) {
                                                    Text(s.name, style = MaterialTheme.typography.bodyMedium)
                                                    s.studentNo?.let { Text(it, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant) }
                                                }
                                                if (s.attendance.isEmpty()) {
                                                    Text("正常出勤", style = MaterialTheme.typography.labelSmall, color = Color(0xFF16A34A))
                                                } else {
                                                    Row(horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                                                        s.attendance.take(2).forEach { r -> StatusBadge(r.titleText, statusColor(r.badgeColorKey)) }
                                                    }
                                                }
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
            classPeriods = classPeriods,
            onDismiss = { selectedStudent = null },
            onChanged = {
                loadStats()
                loadCalendarSummary()
                loadOverview()
            }
        )
    }

    statDetailTarget?.let { (title, statusKey, leaveTypeId) ->
        TeacherStatDetailModal(
            title = title,
            scopeKey = selectedScope,
            semesterId = selectedSemesterId,
            statusKey = statusKey,
            leaveTypeId = leaveTypeId,
            onDismiss = { statDetailTarget = null }
        )
    }

    if (showExportModal) {
        AttendanceExportModal(semesters = semesters, onDismiss = { showExportModal = false })
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
        semester?.startDate?.take(10)?.let { raw ->
            runCatching { LocalDate.parse(raw).with(TemporalAdjusters.previousOrSame(DayOfWeek.MONDAY)) }.getOrNull()
        }
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
                                        dayRecs.map { it.status }.distinct().take(2).forEach { st ->
                                            Box(modifier = Modifier.size(4.dp).clip(CircleShape).background(statusColor(st)))
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
    classPeriods: List<ClassPeriod>,
    onDismiss: () -> Unit,
    onChanged: () -> Unit
) {
    val repo = SmartCampusApp.container.repository
    val scope = rememberCoroutineScope()
    val statusList = listOf("present" to "出勤", "leave" to "请假", "absent" to "旷课", "late" to "迟到", "early_leave" to "早退")

    var selectedStatus by remember { mutableStateOf("leave") }
    var selectedLeaveTypeId by remember { mutableStateOf<Int?>(null) }
    var selectedPeriods by remember { mutableStateOf<Set<Int>>(emptySet()) }
    var note by remember { mutableStateOf("") }
    var history by remember { mutableStateOf<List<AttendanceRecord>>(emptyList()) }
    var saving by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    fun loadHistory() {
        scope.launch {
            history = runCatching { repo.fetchStudentRecords(student.id, "month") }.getOrDefault(emptyList())
        }
    }

    LaunchedEffect(student.id) { loadHistory() }

    ModalBottomSheet(onDismissRequest = onDismiss) {
        Column(
            modifier = Modifier
                .fillMaxWidth()
                .verticalScroll(rememberScrollState())
                .padding(horizontal = 16.dp, vertical = 8.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp)
        ) {
            Text("${student.name} · 考勤补录（${date.format(SCDateFormatter.yyyyMMdd)}）", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
            error?.let { ErrorBanner(message = it) }

            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                statusList.forEach { (k, label) ->
                    FilterChip(selected = selectedStatus == k, onClick = { selectedStatus = k }, label = { Text(label) })
                }
            }

            if (selectedStatus != "present" && leaveTypes.isNotEmpty()) {
                Text("考勤类型", style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    leaveTypes.forEach { lt ->
                        FilterChip(
                            selected = selectedLeaveTypeId == lt.id,
                            onClick = { selectedLeaveTypeId = if (selectedLeaveTypeId == lt.id) null else lt.id },
                            label = { Text(lt.name) }
                        )
                    }
                }
            }

            if (selectedStatus != "present" && classPeriods.isNotEmpty()) {
                Text("选择节次（留空代表全天）", style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    classPeriods.forEach { p ->
                        FilterChip(
                            selected = p.id in selectedPeriods,
                            onClick = { selectedPeriods = if (p.id in selectedPeriods) selectedPeriods - p.id else selectedPeriods + p.id },
                            label = { Text(p.name) }
                        )
                    }
                }
            }

            OutlinedTextField(value = note, onValueChange = { note = it }, label = { Text("备注（可选）") }, singleLine = true, modifier = Modifier.fillMaxWidth())

            Button(
                enabled = !saving,
                modifier = Modifier.fillMaxWidth(),
                onClick = {
                    scope.launch {
                        saving = true; error = null
                        runCatching {
                            repo.submitBulkAttendance(
                                date = date.format(SCDateFormatter.yyyyMMdd),
                                studentId = student.id,
                                status = selectedStatus,
                                leaveTypeId = selectedLeaveTypeId,
                                periodIds = selectedPeriods.toList(),
                                note = note
                            )
                            note = ""; selectedPeriods = emptySet()
                            onChanged()
                            loadHistory()
                        }.onFailure { error = it.localizedMessage }
                        saving = false
                    }
                }
            ) { Text(if (saving) "保存中..." else "保存考勤记录") }

            HorizontalDivider()
            Text("本月异常记录（点垃圾桶可删除）", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold)
            if (history.isEmpty()) {
                Text("暂无异常记录", color = MaterialTheme.colorScheme.onSurfaceVariant)
            } else {
                history.forEach { rec ->
                    Row(modifier = Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                        StatusBadge(rec.titleText, statusColor(rec.badgeColorKey))
                        Spacer(Modifier.width(8.dp))
                        Column(modifier = Modifier.weight(1f)) {
                            Text(rec.dateKey, style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.Medium)
                            if (rec.detailText.isNotBlank()) Text(rec.detailText, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                        }
                        IconButton(onClick = {
                            scope.launch {
                                runCatching {
                                    repo.deleteAttendanceRecord(student.id, rec.dateKey, rec.id, rec.periodId, rec.leaveBatchId)
                                    history = history.filterNot { it.id == rec.id }
                                    onChanged()
                                }.onFailure { error = it.localizedMessage }
                            }
                        }) {
                            Icon(Icons.Rounded.Delete, contentDescription = "删除", tint = Color(0xFFDC2626))
                        }
                    }
                }
            }
            Spacer(Modifier.height(24.dp))
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun TeacherStatDetailModal(
    title: String,
    scopeKey: String,
    semesterId: Int?,
    statusKey: String?,
    leaveTypeId: Int?,
    onDismiss: () -> Unit
) {
    val repo = SmartCampusApp.container.repository
    var presentList by remember { mutableStateOf<List<PresentDetailStudent>>(emptyList()) }
    var otherList by remember { mutableStateOf<List<NonPresentDetailStudent>>(emptyList()) }
    var loading by remember { mutableStateOf(true) }

    LaunchedEffect(Unit) {
        if (statusKey == "present") {
            presentList = runCatching { repo.fetchPresentDetails(scopeKey, semesterId) }.getOrDefault(emptyList())
        } else {
            otherList = runCatching { repo.fetchNonPresentDetails(scopeKey, statusKey, leaveTypeId, semesterId) }.getOrDefault(emptyList())
        }
        loading = false
    }

    ModalBottomSheet(onDismissRequest = onDismiss) {
        Column(modifier = Modifier.fillMaxWidth().padding(16.dp)) {
            Text(title, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
            Spacer(Modifier.height(10.dp))
            if (loading) {
                Box(Modifier.fillMaxWidth().padding(24.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
            } else if (statusKey == "present") {
                LazyColumn(modifier = Modifier.height(360.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    items(presentList, key = { it.id }) { s ->
                        SCCard {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Column(modifier = Modifier.weight(1f)) {
                                    Text(s.name, fontWeight = FontWeight.SemiBold)
                                    Text("${s.className.orEmpty()} · ${s.studentNo.orEmpty()}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                                }
                                StatusBadge(String.format(Locale.US, "%.1f%%", s.attendanceRate), if (s.attendanceRate >= 95) Color(0xFF16A34A) else Color(0xFFEA580C))
                            }
                        }
                    }
                }
            } else if (otherList.isEmpty()) {
                Text("暂无异常明细", color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.padding(vertical = 20.dp))
            } else {
                LazyColumn(modifier = Modifier.height(360.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    items(otherList, key = { it.id }) { s ->
                        SCCard {
                            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                                Text(s.name, fontWeight = FontWeight.SemiBold)
                                Text(s.className.orEmpty(), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                            }
                            s.detail?.takeIf { it.isNotBlank() }?.let {
                                Spacer(Modifier.height(4.dp))
                                Text(it, style = MaterialTheme.typography.bodySmall, color = Color(0xFFEA580C))
                            }
                        }
                    }
                }
            }
            Spacer(Modifier.height(16.dp))
        }
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
                listOf("all" to "全部学生", "with_records" to "仅含异常记录").forEach { (k, label) ->
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
fun LeaveApprovalsScreen() {
    val repo = SmartCampusApp.container.repository
    val scope = rememberCoroutineScope()
    val pageSize = 20
    val filters = listOf("pending" to "待审批", "approved" to "已通过", "rejected" to "已驳回", "all" to "全部")

    var statusFilter by remember { mutableStateOf("pending") }
    var timeScope by remember { mutableStateOf(ApprovalTimeScope.ALL) }
    var lastHistoryScope by remember { mutableStateOf(ApprovalTimeScope.RECENT_30) }

    var semesters by remember { mutableStateOf<List<Semester>>(emptyList()) }
    var semestersLoaded by remember { mutableStateOf(false) }
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
    var partialTarget by remember { mutableStateOf<LeaveRequestGroup?>(null) }
    var previewImage by remember { mutableStateOf<String?>(null) }

    val currentSemester = remember(semesters) {
        semesters.firstOrNull { it.isCurrent } ?: semesters.firstOrNull()
    }
    val totalWeeks = (currentSemester?.totalWeeks ?: 20).coerceAtLeast(1)
    val semesterBounds = remember(currentSemester, totalWeeks) {
        val raw = currentSemester?.startDate?.take(10) ?: return@remember null
        val start = runCatching { LocalDate.parse(raw) }.getOrNull() ?: return@remember null
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

    // 初始化当前学期与默认教学周/月份
    LaunchedEffect(Unit) {
        val list = runCatching { repo.fetchSemesters() }.getOrDefault(emptyList())
        semesters = list
        val cur = list.firstOrNull { it.isCurrent } ?: list.firstOrNull()
        val rawStart = cur?.startDate?.take(10)
        val start = rawStart?.let { runCatching { LocalDate.parse(it) }.getOrNull() }
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
        }
        semestersLoaded = true
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
        topBar = {
            TopAppBar(
                title = { Text("请假审批") },
                actions = { IconButton(onClick = { loadFirstPage() }) { Icon(Icons.Rounded.Refresh, contentDescription = "刷新") } }
            )
        }
    ) { padding ->
        Column(modifier = Modifier.fillMaxSize().padding(padding).padding(horizontal = 16.dp)) {
            SingleChoiceSegmentedButtonRow(modifier = Modifier.fillMaxWidth().padding(top = 6.dp, bottom = 4.dp)) {
                filters.forEachIndexed { idx, (key, label) ->
                    SegmentedButton(
                        selected = statusFilter == key,
                        onClick = {
                            val prev = statusFilter
                            statusFilter = key
                            if (key == "pending") {
                                timeScope = ApprovalTimeScope.ALL
                            } else if (prev == "pending") {
                                timeScope = lastHistoryScope
                            }
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
                            if (statusFilter != "pending") {
                                lastHistoryScope = item
                            }
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
                            req.reason?.takeIf { it.isNotBlank() }?.let {
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
                                Spacer(Modifier.height(10.dp))
                                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                    OutlinedButton(
                                        onClick = { rejectReason = ""; rejectingTarget = req },
                                        colors = ButtonDefaults.outlinedButtonColors(contentColor = Color(0xFFDC2626)),
                                        modifier = Modifier.weight(1f)
                                    ) { Text("驳回") }

                                    if (pendingSubs.size > 1) {
                                        OutlinedButton(onClick = { partialTarget = req }) { Text("分项批") }
                                    }

                                    Button(
                                        colors = ButtonDefaults.buttonColors(containerColor = Color(0xFF16A34A)),
                                        modifier = Modifier.weight(1f),
                                        onClick = {
                                            scope.launch {
                                                runCatching { repo.approveLeaveRequest(req.id) }
                                                    .onSuccess { loadFirstPage() }
                                                    .onFailure { error = it.localizedMessage }
                                            }
                                        }
                                    ) { Text("批准通过") }
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
                    scope.launch {
                        runCatching { repo.rejectLeaveRequest(target.id, reasonText) }
                            .onSuccess { loadFirstPage() }
                            .onFailure { error = it.localizedMessage }
                    }
                }) { Text("确认驳回", color = Color(0xFFDC2626)) }
            },
            dismissButton = { TextButton(onClick = { rejectingTarget = null }) { Text("取消") } }
        )
    }

    partialTarget?.let { target ->
        val pendingSubs = target.records.filter { it.approvalStatus == "pending" }
        var selectedIds by remember(target.id) { mutableStateOf(pendingSubs.map { it.id }.toSet()) }
        AlertDialog(
            onDismissRequest = { partialTarget = null },
            title = { Text("选择要批准的节次") },
            text = {
                Column(modifier = Modifier.verticalScroll(rememberScrollState())) {
                    pendingSubs.forEach { sub ->
                        val checked = sub.id in selectedIds
                        Row(
                            verticalAlignment = Alignment.CenterVertically,
                            modifier = Modifier.fillMaxWidth().clickable {
                                selectedIds = if (checked) selectedIds - sub.id else selectedIds + sub.id
                            }
                        ) {
                            Checkbox(checked = checked, onCheckedChange = {
                                selectedIds = if (it) selectedIds + sub.id else selectedIds - sub.id
                            })
                            Text("${sub.date} · ${sub.label ?: "全天"}")
                        }
                    }
                }
            },
            confirmButton = {
                TextButton(
                    enabled = selectedIds.isNotEmpty(),
                    onClick = {
                        val ids = selectedIds.toList()
                        partialTarget = null
                        scope.launch {
                            runCatching { repo.approveLeaveRequest(target.id, ids) }
                                .onSuccess { loadFirstPage() }
                                .onFailure { error = it.localizedMessage }
                        }
                    }
                ) { Text("批准选中（${selectedIds.size}）") }
            },
            dismissButton = { TextButton(onClick = { partialTarget = null }) { Text("取消") } }
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
                Text("本月异常考勤记录", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold)
                Spacer(Modifier.height(8.dp))
                if (records.isEmpty()) {
                    Text("本月暂无异常考勤记录", color = MaterialTheme.colorScheme.onSurfaceVariant, style = MaterialTheme.typography.bodySmall)
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
                val boardingChangedToTrue = !currentStudent.isBoarding && updated.isBoarding
                currentStudent = updated
                showEditBasicInfo = false
                successMessage = "基础信息已保存"
                if (boardingChangedToTrue) {
                    load(boarding = true)
                } else if (!updated.isBoarding) {
                    suspensions = emptyList()
                }
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
