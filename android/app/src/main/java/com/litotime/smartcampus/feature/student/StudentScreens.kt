package com.litotime.smartcampus.feature.student

import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.BorderStroke
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
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.automirrored.rounded.FactCheck
import androidx.compose.material.icons.rounded.AddAPhoto
import androidx.compose.material.icons.rounded.CalendarMonth
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.ChevronLeft
import androidx.compose.material.icons.rounded.ChevronRight
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.Description
import androidx.compose.material.icons.rounded.ExpandMore
import androidx.compose.material.icons.rounded.Notifications
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material.icons.rounded.PhotoLibrary
import androidx.compose.material.icons.rounded.Refresh
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material.icons.rounded.VerifiedUser
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
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
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.rememberDatePickerState
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
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
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
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
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import java.io.File
import java.time.DayOfWeek
import java.time.Instant
import java.time.LocalDate
import java.time.YearMonth
import java.time.ZoneId

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun StudentDashboardScreen(
    user: User,
    onOpenNotifications: () -> Unit,
    onOpenClassAttendanceAdmin: () -> Unit,
    onOpenClassLeaveApprovals: () -> Unit = {}
) {
    val repo = SmartCampusApp.container.repository
    val scope = rememberCoroutineScope()

    val scopes = listOf("today" to "今日", "week" to "本周", "month" to "本月", "semester" to "本学期")
    var selectedScope by remember { mutableStateOf("month") }
    var stats by remember { mutableStateOf<StudentStats?>(null) }
    var leaveTypes by remember { mutableStateOf<List<LeaveType>>(emptyList()) }
    var classPeriods by remember { mutableStateOf<List<ClassPeriod>>(emptyList()) }
    var timeSlots by remember { mutableStateOf<List<TimeSlot>>(emptyList()) }
    var displayedMonth by remember { mutableStateOf(YearMonth.now()) }
    var selectedDate by remember { mutableStateOf(LocalDate.now()) }
    var monthRecords by remember { mutableStateOf<List<AttendanceRecord>>(emptyList()) }
    var loading by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var detailSheet by remember { mutableStateOf<Pair<String, String>?>(null) } // title to statusKey
    var todayClassAnomalyStudents by remember { mutableStateOf<Int?>(null) }
    var todayClassPendingCount by remember { mutableStateOf(0) }

    fun loadStats() {
        scope.launch {
            runCatching { repo.fetchStudentStats(selectedScope) }
                .onSuccess { stats = it }
                .onFailure { error = it.localizedMessage }
        }
    }

    fun loadCalendar() {
        scope.launch {
            val start = displayedMonth.atDay(1).format(SCDateFormatter.yyyyMMdd)
            val end = displayedMonth.atEndOfMonth().format(SCDateFormatter.yyyyMMdd)
            runCatching { repo.fetchStudentCalendar(start, end) }
                .onSuccess { list -> monthRecords = list.filterNot { it.isNormalPresentRecord } }
        }
    }

    fun loadAll() {
        scope.launch {
            loading = true
            error = null
            runCatching {
                leaveTypes = repo.fetchLeaveTypes().filter { it.isActive }
                classPeriods = runCatching { repo.fetchClassPeriods() }.getOrDefault(emptyList())
                timeSlots = runCatching { repo.fetchTimeSlots() }.getOrDefault(emptyList())
                stats = repo.fetchStudentStats(selectedScope)
                val start = displayedMonth.atDay(1).format(SCDateFormatter.yyyyMMdd)
                val end = displayedMonth.atEndOfMonth().format(SCDateFormatter.yyyyMMdd)
                monthRecords = runCatching { repo.fetchStudentCalendar(start, end) }
                    .getOrDefault(emptyList())
                    .filterNot { it.isNormalPresentRecord }
                if (user.isClassAdminStudent) {
                    val todayKey = LocalDate.now().format(SCDateFormatter.yyyyMMdd)
                    runCatching { repo.fetchAttendanceOverview(todayKey, includeEvening = true) }.getOrNull()?.let { ov ->
                        val roster = ov.flatMap { it.classes }.flatMap { it.students }
                        var anomalyCount = 0
                        val pendingBatches = mutableSetOf<String>()
                        roster.forEach { st ->
                            val valid = st.attendance.filter { !it.isNormalPresentRecord && it.approvalStatus != "rejected" }
                            if (valid.isNotEmpty()) {
                                anomalyCount += 1
                                valid.filter { it.approvalStatus == "pending" }.forEach { rec ->
                                    val bKey = rec.leaveBatchId?.takeIf { it.isNotBlank() }?.let { "${st.id}-$it" } ?: "${st.id}-${rec.id}"
                                    pendingBatches.add(bKey)
                                }
                            }
                        }
                        todayClassAnomalyStudents = anomalyCount
                        todayClassPendingCount = pendingBatches.size
                    }
                }
            }.onFailure { error = it.localizedMessage ?: "加载失败" }
            loading = false
        }
    }

    LaunchedEffect(Unit) { loadAll() }
    LaunchedEffect(selectedScope) { if (leaveTypes.isNotEmpty()) loadStats() }
    LaunchedEffect(displayedMonth) { if (leaveTypes.isNotEmpty()) loadCalendar() }

    val recordsByDate = remember(monthRecords) { monthRecords.groupBy { it.dateKey } }
    val selectedDayRecords = recordsByDate[selectedDate.format(SCDateFormatter.yyyyMMdd)].orEmpty()

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("我的考勤") },
                actions = {
                    IconButton(onClick = { loadAll() }) {
                        Icon(Icons.Rounded.Refresh, contentDescription = "刷新")
                    }
                    IconButton(onClick = onOpenNotifications) {
                        Icon(Icons.Rounded.Notifications, contentDescription = "通知")
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
            error?.let { ErrorBanner(message = it, onRetry = { loadAll() }) }

            if (user.isClassAdminStudent) {
                Surface(
                    shape = RoundedCornerShape(16.dp),
                    color = MaterialTheme.colorScheme.primary,
                    modifier = Modifier.fillMaxWidth().clickable(onClick = onOpenClassAttendanceAdmin)
                ) {
                    Row(
                        modifier = Modifier.padding(16.dp),
                        verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.spacedBy(12.dp)
                    ) {
                        Icon(Icons.Rounded.VerifiedUser, contentDescription = null, tint = Color.White, modifier = Modifier.size(30.dp))
                        Column(modifier = Modifier.weight(1f)) {
                            Text("班级考勤员工作台", color = Color.White, style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold)
                            val subtitleText = when (val cnt = todayClassAnomalyStudents) {
                                null -> "查看今日全班请假/标记情况与考勤维护"
                                0 -> "今日全班出勤正常 · 点击查看完整考勤情况"
                                else -> if (todayClassPendingCount > 0) {
                                    "今日 $cnt 人有请假/标记（$todayClassPendingCount 条待审批）· 点看完整明细"
                                } else {
                                    "今日 $cnt 人有请假/标记 · 点击查看完整明细"
                                }
                            }
                            Text(subtitleText, color = Color.White.copy(alpha = 0.88f), style = MaterialTheme.typography.bodySmall)
                        }
                        Icon(Icons.Rounded.ChevronRight, contentDescription = null, tint = Color.White)
                    }
                }

                if (user.isRollCallAdminStudent) {
                    SCCard(onClick = onOpenClassLeaveApprovals) {
                        Row(
                            verticalAlignment = Alignment.CenterVertically,
                            horizontalArrangement = Arrangement.spacedBy(12.dp)
                        ) {
                            Icon(
                                Icons.AutoMirrored.Rounded.FactCheck,
                                contentDescription = null,
                                tint = MaterialTheme.colorScheme.primary,
                                modifier = Modifier.size(24.dp)
                            )
                            Column(modifier = Modifier.weight(1f)) {
                                Text("本班请假审批", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.SemiBold)
                                Text(
                                    "查看并审批本班同学的请假申请（含姓名与学号）",
                                    style = MaterialTheme.typography.bodySmall,
                                    color = MaterialTheme.colorScheme.onSurfaceVariant
                                )
                            }
                            Icon(Icons.Rounded.ChevronRight, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant)
                        }
                    }
                }
            }

            SingleChoiceSegmentedButtonRow(modifier = Modifier.fillMaxWidth()) {
                scopes.forEachIndexed { idx, (key, label) ->
                    SegmentedButton(
                        selected = selectedScope == key,
                        onClick = { selectedScope = key },
                        shape = SegmentedButtonDefaults.itemShape(idx, scopes.size)
                    ) { Text(label) }
                }
            }

            if (stats != null) {
                val st = stats!!
                val itemsList = buildList {
                    add(Triple(if (st.isRestDay && selectedScope == "today") "今日休息日" else "出勤统计", "${st.present}", Pair("present", Color(0xFF16A34A))))
                    leaveTypes.forEach { lt ->
                        val c = st.leaveCounts[lt.slug] ?: 0
                        add(Triple(lt.name, "$c", Pair(lt.slug, statusColor(lt.color))))
                    }
                }
                itemsList.chunked(2).forEach { rowItems ->
                    Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                        rowItems.forEach { (title, value, pair) ->
                            val (statusKey, color) = pair
                            StatMetricCard(
                                title = title,
                                value = value,
                                icon = if (statusKey == "present") Icons.Rounded.CheckCircle else Icons.Rounded.Description,
                                color = color,
                                subtitle = if (statusKey == "present") "工作日 ${st.workingDays} 天" else "点击查看明细",
                                modifier = Modifier.weight(1f),
                                onClick = { detailSheet = title to statusKey }
                            )
                        }
                        if (rowItems.size == 1) Spacer(Modifier.weight(1f))
                    }
                }
            } else if (loading) {
                Box(Modifier.fillMaxWidth().padding(24.dp), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator()
                }
            }

            // Native Month Calendar
            SCCard {
                Row(
                    modifier = Modifier.fillMaxWidth(),
                    verticalAlignment = Alignment.CenterVertically,
                    horizontalArrangement = Arrangement.SpaceBetween
                ) {
                    IconButton(onClick = { displayedMonth = displayedMonth.minusMonths(1) }) {
                        Icon(Icons.Rounded.ChevronLeft, contentDescription = "上月")
                    }
                    Text(
                        displayedMonth.atDay(1).format(SCDateFormatter.cnYearMonth),
                        style = MaterialTheme.typography.titleMedium,
                        fontWeight = FontWeight.Bold
                    )
                    IconButton(onClick = { displayedMonth = displayedMonth.plusMonths(1) }) {
                        Icon(Icons.Rounded.ChevronRight, contentDescription = "下月")
                    }
                }
                Spacer(Modifier.height(6.dp))
                StudentMonthCalendar(
                    month = displayedMonth,
                    selectedDate = selectedDate,
                    recordsByDate = recordsByDate,
                    onSelectDate = { selectedDate = it }
                )
            }

            // Selected Day Details
            SCCard {
                Row(modifier = Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                    Text(
                        selectedDate.format(SCDateFormatter.cnMonthDay),
                        style = MaterialTheme.typography.titleSmall,
                        fontWeight = FontWeight.Bold,
                        modifier = Modifier.weight(1f)
                    )
                    StatusBadge(
                        text = if (selectedDayRecords.isEmpty()) "全勤 / 无异常" else "${selectedDayRecords.size} 条记录",
                        color = if (selectedDayRecords.isEmpty()) Color(0xFF16A34A) else Color(0xFFEA580C)
                    )
                }
                Spacer(Modifier.height(10.dp))
                if (selectedDayRecords.isEmpty()) {
                    Text("当日暂无请假或考勤异常记录", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                } else {
                    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        selectedDayRecords.forEach { rec ->
                            Surface(
                                shape = RoundedCornerShape(12.dp),
                                color = MaterialTheme.colorScheme.surfaceVariant.copy(alpha = 0.65f),
                                modifier = Modifier.fillMaxWidth()
                            ) {
                                Column(modifier = Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                                    Row(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.CenterVertically) {
                                        StatusBadge(rec.titleText, statusColor(rec.badgeColorKey))
                                        rec.approvalStatus?.let { ap ->
                                            StatusBadge(
                                                when (ap) {
                                                    "pending" -> "待审批"
                                                    "approved" -> "已批准"
                                                    else -> "已驳回"
                                                },
                                                statusColor(ap)
                                            )
                                        }
                                        Spacer(Modifier.weight(1f))
                                        rec.recordTime?.takeIf { it.isNotBlank() }?.let {
                                            Text(it, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                                        }
                                    }
                                    val periodDisplay = if (rec.periodIdsInDetails.isNotEmpty()) {
                                        PeriodFormatter.formatPeriodNames(rec.periodIdsInDetails, classPeriods, timeSlots)
                                            .ifBlank { rec.detailText }
                                    } else rec.detailText
                                    if (periodDisplay.isNotBlank()) {
                                        Text(periodDisplay, style = MaterialTheme.typography.bodySmall)
                                    }
                                    rec.eveningStudyLabel?.takeIf { it.isNotBlank() }?.let {
                                        Text("夜自习：$it", style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.primary)
                                    }
                                    (rec.reason ?: rec.note)?.takeIf { it.isNotBlank() }?.let {
                                        Text("说明：$it", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    detailSheet?.let { (title, statusKey) ->
        StudentStatusDetailModal(
            title = title,
            scopeKey = selectedScope,
            statusKey = statusKey,
            onDismiss = { detailSheet = null }
        )
    }
}

@Composable
private fun StudentMonthCalendar(
    month: YearMonth,
    selectedDate: LocalDate,
    recordsByDate: Map<String, List<AttendanceRecord>>,
    onSelectDate: (LocalDate) -> Unit
) {
    val weekdays = listOf("日", "一", "二", "三", "四", "五", "六")
    val firstDay = month.atDay(1)
    val leadingBlanks = firstDay.dayOfWeek.value % 7 // Sunday = 0
    val daysInMonth = month.lengthOfMonth()
    val today = LocalDate.now()

    val cells = buildList<LocalDate?> {
        repeat(leadingBlanks) { add(null) }
        for (d in 1..daysInMonth) add(month.atDay(d))
    }

    Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Row(modifier = Modifier.fillMaxWidth()) {
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
        cells.chunked(7).forEach { week ->
            Row(modifier = Modifier.fillMaxWidth()) {
                for (i in 0 until 7) {
                    val date = week.getOrNull(i)
                    if (date == null) {
                        Spacer(Modifier.weight(1f).height(44.dp))
                    } else {
                        val isSelected = date == selectedDate
                        val isToday = date == today
                        val dayRecs = recordsByDate[date.format(SCDateFormatter.yyyyMMdd)].orEmpty()
                        Column(
                            modifier = Modifier
                                .weight(1f)
                                .clip(RoundedCornerShape(10.dp))
                                .clickable { onSelectDate(date) }
                                .padding(vertical = 4.dp),
                            horizontalAlignment = Alignment.CenterHorizontally
                        ) {
                            Box(
                                modifier = Modifier
                                    .size(30.dp)
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
                                    "${date.dayOfMonth}",
                                    style = MaterialTheme.typography.bodySmall,
                                    fontWeight = if (isSelected || isToday) FontWeight.Bold else FontWeight.Normal,
                                    color = when {
                                        isSelected -> Color.White
                                        isToday -> MaterialTheme.colorScheme.primary
                                        else -> MaterialTheme.colorScheme.onSurface
                                    }
                                )
                            }
                            Row(
                                modifier = Modifier.height(7.dp),
                                horizontalArrangement = Arrangement.spacedBy(2.dp),
                                verticalAlignment = Alignment.CenterVertically
                            ) {
                                dayRecs.take(3).forEach { r ->
                                    Box(
                                        modifier = Modifier
                                            .size(5.dp)
                                            .clip(CircleShape)
                                            .background(statusColor(r.badgeColorKey))
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

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun StudentStatusDetailModal(
    title: String,
    scopeKey: String,
    statusKey: String,
    onDismiss: () -> Unit
) {
    var records by remember { mutableStateOf<List<StudentDetailItem>>(emptyList()) }
    var loading by remember { mutableStateOf(true) }

    LaunchedEffect(Unit) {
        loading = true
        records = runCatching {
            SmartCampusApp.container.repository.fetchStudentDetails(scopeKey, statusKey)
        }.getOrDefault(emptyList())
        loading = false
    }

    ModalBottomSheet(onDismissRequest = onDismiss) {
        Column(modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 8.dp)) {
            Text(title, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
            Spacer(Modifier.height(12.dp))
            if (loading) {
                Box(Modifier.fillMaxWidth().padding(24.dp), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator()
                }
            } else if (records.isEmpty()) {
                Text("该统计周期内暂无相关记录", color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.padding(vertical = 20.dp))
            } else {
                LazyColumn(
                    modifier = Modifier.height(340.dp),
                    verticalArrangement = Arrangement.spacedBy(8.dp)
                ) {
                    items(records, key = { it.id }) { item ->
                        SCCard {
                            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
                                Text(item.typeName.ifBlank { title }, fontWeight = FontWeight.SemiBold)
                                Text(item.date, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                            }
                            if (item.detailLabel.isNotBlank()) {
                                Spacer(Modifier.height(4.dp))
                                Text(item.detailLabel, style = MaterialTheme.typography.bodySmall)
                            }
                            item.note?.takeIf { it.isNotBlank() }?.let {
                                Text("备注：$it", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
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
fun LeaveRequestFormScreen(user: User) {
    val context = LocalContext.current
    val repo = SmartCampusApp.container.repository
    val scope = rememberCoroutineScope()

    val isBoarding = user.student?.isBoarding == true
    val gender = user.student?.gender

    var leaveTypes by remember { mutableStateOf<List<LeaveType>>(emptyList()) }
    var timeSlots by remember { mutableStateOf<List<TimeSlot>>(emptyList()) }
    var regularPeriods by remember { mutableStateOf<List<ClassPeriod>>(emptyList()) }
    var eveningPeriods by remember { mutableStateOf<List<ClassPeriod>>(emptyList()) }
    var eveningStatuses by remember { mutableStateOf<List<EveningStudyStatus>>(emptyList()) }
    var imgSettings by remember { mutableStateOf(LeaveImageSettings()) }

    var selectedSlug by remember { mutableStateOf("") }
    var startDate by remember { mutableStateOf(LocalDate.now()) }
    var endDate by remember { mutableStateOf(LocalDate.now()) }
    var selectedSlotId by remember { mutableStateOf<Int?>(null) }
    var selectedPeriodIds by remember { mutableStateOf<Set<Int>>(emptySet()) }
    var selectedOptionKey by remember { mutableStateOf("") }
    var customDetailText by remember { mutableStateOf("") }
    var timeInputText by remember { mutableStateOf("08:00") }
    var reason by remember { mutableStateOf("") }
    var selectedEveningStatusId by remember { mutableStateOf<Int?>(null) }
    var destination by remember { mutableStateOf("") }
    val uploadedPaths = remember { mutableStateListOf<String>() }

    var uploadingImage by remember { mutableStateOf(false) }
    var submitting by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var showSuccess by remember { mutableStateOf(false) }
    var pickingDateFor by remember { mutableStateOf<String?>(null) } // "start" or "end"
    var typeMenuOpen by remember { mutableStateOf(false) }
    var cameraTempUri by remember { mutableStateOf<Uri?>(null) }

    val requestableTypes = remember(leaveTypes, gender) {
        leaveTypes.filter { lt ->
            lt.isActive && lt.studentRequestable && (lt.genderRestriction == "all" || lt.genderRestriction == gender)
        }
    }
    val selectedType = requestableTypes.firstOrNull { it.slug == selectedSlug }
    val selectablePeriods = remember(regularPeriods, eveningPeriods, isBoarding) {
        regularPeriods.filter { !it.isBoardingOnly || isBoarding } + if (isBoarding) eveningPeriods else emptyList()
    }
    val hasEveningSelected = remember(selectedPeriodIds, eveningPeriods) {
        val esIds = eveningPeriods.map { it.id }.toSet()
        selectedPeriodIds.any { it in esIds }
    }

    fun uploadUri(uri: Uri) {
        if (uploadedPaths.size >= imgSettings.maxCount) return
        scope.launch {
            uploadingImage = true
            error = null
            runCatching {
                val path = repo.uploadLeaveImage(uri, imgSettings.maxSizeMB)
                uploadedPaths.add(path)
            }.onFailure {
                error = "图片上传失败：${it.localizedMessage}"
            }
            uploadingImage = false
        }
    }

    val photoPicker = rememberLauncherForActivityResult(
        ActivityResultContracts.PickMultipleVisualMedia(maxItems = 5)
    ) { uris ->
        uris.take((imgSettings.maxCount - uploadedPaths.size).coerceAtLeast(0)).forEach { uploadUri(it) }
    }

    val cameraLauncher = rememberLauncherForActivityResult(
        ActivityResultContracts.TakePicture()
    ) { success ->
        if (success) {
            cameraTempUri?.let { uploadUri(it) }
        }
    }

    LaunchedEffect(Unit) {
        runCatching {
            leaveTypes = repo.fetchLeaveTypes()
            timeSlots = runCatching { repo.fetchTimeSlots() }.getOrDefault(emptyList())
            regularPeriods = runCatching { repo.fetchClassPeriods() }.getOrDefault(emptyList())
            imgSettings = runCatching { repo.fetchLeaveImageSettings() }.getOrDefault(LeaveImageSettings())
            if (isBoarding) {
                eveningPeriods = runCatching { repo.fetchEveningPeriods() }.getOrDefault(emptyList())
                eveningStatuses = runCatching {
                    repo.fetchEveningStatuses(studentRequestableOnly = true).filter { it.isActive && it.studentRequestable }
                }.getOrDefault(emptyList())
                selectedEveningStatusId = eveningStatuses.firstOrNull()?.id
            }
            val first = leaveTypes.firstOrNull { lt ->
                lt.isActive && lt.studentRequestable && (lt.genderRestriction == "all" || lt.genderRestriction == gender)
            }
            if (selectedSlug.isEmpty() && first != null) selectedSlug = first.slug
        }.onFailure {
            error = it.localizedMessage
        }
    }

    Scaffold(
        topBar = { TopAppBar(title = { Text("请假申请") }) }
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

            // 1. Leave Type
            SCCard {
                Text("请假类型", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold)
                Spacer(Modifier.height(8.dp))
                Box {
                    OutlinedButton(
                        onClick = { typeMenuOpen = true },
                        modifier = Modifier.fillMaxWidth()
                    ) {
                        Text(selectedType?.name ?: "请选择请假类型", modifier = Modifier.weight(1f))
                        Icon(Icons.Rounded.ChevronRight, contentDescription = null)
                    }
                    DropdownMenu(expanded = typeMenuOpen, onDismissRequest = { typeMenuOpen = false }) {
                        requestableTypes.forEach { lt ->
                            DropdownMenuItem(
                                text = { Text(lt.name) },
                                onClick = {
                                    selectedSlug = lt.slug
                                    selectedOptionKey = ""
                                    typeMenuOpen = false
                                }
                            )
                        }
                    }
                }
                selectedType?.description?.takeIf { it.isNotBlank() }?.let {
                    Spacer(Modifier.height(6.dp))
                    Text(it, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                }
                if ((selectedType?.limitDays ?: 0) > 0 && (selectedType?.limitTimes ?: 0) > 0) {
                    Spacer(Modifier.height(4.dp))
                    Text(
                        "限制：${selectedType?.limitDays} 天内最多申请 ${selectedType?.limitTimes} 次",
                        style = MaterialTheme.typography.labelSmall,
                        color = Color(0xFFEA580C)
                    )
                }
            }

            // 2. Date Range
            SCCard {
                Text("请假日期", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold)
                Spacer(Modifier.height(10.dp))
                Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    OutlinedButton(
                        onClick = { pickingDateFor = "start" },
                        modifier = Modifier.weight(1f)
                    ) {
                        Icon(Icons.Rounded.CalendarMonth, contentDescription = null, modifier = Modifier.size(16.dp))
                        Spacer(Modifier.width(6.dp))
                        Text("起 ${startDate.format(SCDateFormatter.yyyyMMdd)}", style = MaterialTheme.typography.labelMedium)
                    }
                    OutlinedButton(
                        onClick = { pickingDateFor = "end" },
                        modifier = Modifier.weight(1f)
                    ) {
                        Icon(Icons.Rounded.CalendarMonth, contentDescription = null, modifier = Modifier.size(16.dp))
                        Spacer(Modifier.width(6.dp))
                        Text("止 ${endDate.format(SCDateFormatter.yyyyMMdd)}", style = MaterialTheme.typography.labelMedium)
                    }
                }
            }

            // 3. Dynamic Leave Type Config
            selectedType?.let { lt ->
                when (lt.inputType) {
                    "duration_select" -> if (lt.options.isNotEmpty()) {
                        SCCard {
                            Text("时长选项", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold)
                            Spacer(Modifier.height(8.dp))
                            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                lt.options.forEach { opt ->
                                    FilterChip(
                                        selected = selectedOptionKey == opt.key,
                                        onClick = { selectedOptionKey = if (selectedOptionKey == opt.key) "" else opt.key },
                                        label = { Text(opt.label) }
                                    )
                                }
                            }
                        }
                    }
                    "time" -> SCCard {
                        OutlinedTextField(
                            value = timeInputText,
                            onValueChange = { timeInputText = it },
                            label = { Text("具体时间（HH:mm）") },
                            singleLine = true,
                            modifier = Modifier.fillMaxWidth()
                        )
                    }
                    "text" -> SCCard {
                        OutlinedTextField(
                            value = customDetailText,
                            onValueChange = { customDetailText = it },
                            label = { Text(lt.placeholder ?: "补充说明") },
                            modifier = Modifier.fillMaxWidth()
                        )
                    }
                }
            }

            // 4. Time Slots & Periods
            if (timeSlots.isNotEmpty() || selectablePeriods.isNotEmpty()) {
                SCCard {
                    Text("时段与节次", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold)
                    if (timeSlots.isNotEmpty()) {
                        Spacer(Modifier.height(8.dp))
                        Row(
                            modifier = Modifier.horizontalScroll(rememberScrollState()),
                            horizontalArrangement = Arrangement.spacedBy(8.dp)
                        ) {
                            timeSlots.forEach { slot ->
                                FilterChip(
                                    selected = selectedSlotId == slot.id,
                                    onClick = {
                                        if (selectedSlotId == slot.id) {
                                            selectedSlotId = null
                                            selectedPeriodIds = emptySet()
                                        } else {
                                            selectedSlotId = slot.id
                                            val validIds = selectablePeriods.map { it.id }.toSet()
                                            selectedPeriodIds = slot.periodIds.filter { it in validIds }.toSet()
                                        }
                                    },
                                    label = { Text(slot.name) }
                                )
                            }
                        }
                    }

                    if (selectablePeriods.isNotEmpty()) {
                        Spacer(Modifier.height(8.dp))
                        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                            selectablePeriods.forEach { p ->
                                FilterChip(
                                    selected = p.id in selectedPeriodIds,
                                    onClick = {
                                        selectedPeriodIds = if (p.id in selectedPeriodIds) {
                                            selectedPeriodIds - p.id
                                        } else {
                                            selectedPeriodIds + p.id
                                        }
                                    },
                                    label = { Text(p.name) }
                                )
                            }
                        }
                    }

                    Spacer(Modifier.height(6.dp))
                    Text(
                        if (selectedPeriodIds.isEmpty()) "未选节次默认按全天申请"
                        else "已选：" + PeriodFormatter.formatPeriodNames(selectedPeriodIds.toList(), selectablePeriods, timeSlots),
                        style = MaterialTheme.typography.labelSmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant
                    )
                }
            }

            // 5. Evening Study Extra Fields (Boarding)
            if (hasEveningSelected) {
                SCCard {
                    Text("夜自习去向（住宿生必填）", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold)
                    Spacer(Modifier.height(8.dp))
                    FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        eveningStatuses.forEach { st ->
                            FilterChip(
                                selected = selectedEveningStatusId == st.id,
                                onClick = { selectedEveningStatusId = st.id },
                                label = { Text(st.name) }
                            )
                        }
                    }
                    Spacer(Modifier.height(8.dp))
                    OutlinedTextField(
                        value = destination,
                        onValueChange = { destination = it },
                        label = { Text("具体去向（如回家、竞赛训练等）") },
                        modifier = Modifier.fillMaxWidth()
                    )
                }
            }

            // 6. Reason
            SCCard {
                OutlinedTextField(
                    value = reason,
                    onValueChange = { reason = it },
                    label = { Text("请假事由") },
                    placeholder = { Text("请简要说明请假原因...") },
                    minLines = 3,
                    modifier = Modifier.fillMaxWidth()
                )
            }

            // 7. Images
            if (imgSettings.enabled) {
                SCCard {
                    Text("证明材料（最多 ${imgSettings.maxCount} 张，自动压缩）", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold)
                    if (uploadedPaths.isNotEmpty()) {
                        Spacer(Modifier.height(10.dp))
                        Row(
                            modifier = Modifier.horizontalScroll(rememberScrollState()),
                            horizontalArrangement = Arrangement.spacedBy(10.dp)
                        ) {
                            uploadedPaths.forEach { path ->
                                Box(modifier = Modifier.size(80.dp)) {
                                    RemoteMediaThumb(pathOrUrl = path, modifier = Modifier.fillMaxSize())
                                    IconButton(
                                        onClick = {
                                            uploadedPaths.remove(path)
                                            scope.launch { runCatching { repo.deleteLeaveImage(path) } }
                                        },
                                        modifier = Modifier
                                            .align(Alignment.TopEnd)
                                            .size(24.dp)
                                            .clip(CircleShape)
                                            .background(Color(0xFFDC2626))
                                    ) {
                                        Icon(Icons.Rounded.Close, contentDescription = "移除", tint = Color.White, modifier = Modifier.size(14.dp))
                                    }
                                }
                            }
                        }
                    }

                    if (uploadedPaths.size < imgSettings.maxCount) {
                        Spacer(Modifier.height(10.dp))
                        Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                            OutlinedButton(
                                enabled = !uploadingImage,
                                onClick = {
                                    photoPicker.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly))
                                },
                                modifier = Modifier.weight(1f)
                            ) {
                                Icon(Icons.Rounded.PhotoLibrary, contentDescription = null, modifier = Modifier.size(18.dp))
                                Spacer(Modifier.width(6.dp))
                                Text("相册选择")
                            }
                            OutlinedButton(
                                enabled = !uploadingImage,
                                onClick = {
                                    val photoFile = File(context.cacheDir, "cam_${System.currentTimeMillis()}.jpg")
                                    val uri = FileProvider.getUriForFile(context, "${BuildConfig.APPLICATION_ID}.fileprovider", photoFile)
                                    cameraTempUri = uri
                                    cameraLauncher.launch(uri)
                                },
                                modifier = Modifier.weight(1f)
                            ) {
                                Icon(Icons.Rounded.AddAPhoto, contentDescription = null, modifier = Modifier.size(18.dp))
                                Spacer(Modifier.width(6.dp))
                                Text("现场拍照")
                            }
                        }
                    }
                    if (uploadingImage) {
                        Spacer(Modifier.height(8.dp))
                        Text("正在压缩并上传图片...", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.primary)
                    }
                }
            }

            // 8. Submit
            Button(
                enabled = !submitting && !uploadingImage && selectedSlug.isNotBlank(),
                modifier = Modifier.fillMaxWidth().height(50.dp),
                shape = RoundedCornerShape(12.dp),
                onClick = {
                    if (hasEveningSelected) {
                        if (selectedEveningStatusId == null) {
                            error = "请选择夜自习申请状态"; return@Button
                        }
                        if (destination.isBlank()) {
                            error = "请填写夜自习期间具体去向"; return@Button
                        }
                    }
                    scope.launch {
                        submitting = true
                        error = null
                        val trimmedReason = reason.trim()
                        val trimmedCustomText = customDetailText.trim()
                        val effectiveReason = trimmedReason.ifEmpty { trimmedCustomText }
                        val effectiveDetailText = trimmedCustomText.ifEmpty { trimmedReason }
                        val payload = buildJsonObject {
                            put("type", selectedSlug)
                            put("start_date", startDate.format(SCDateFormatter.yyyyMMdd))
                            put("end_date", endDate.format(SCDateFormatter.yyyyMMdd))
                            put("reason", effectiveReason)
                            selectedSlotId?.let { put("time_slot_id", it) }
                            if (selectedPeriodIds.isNotEmpty()) {
                                put("sessions", JsonArray(selectedPeriodIds.sorted().map { JsonPrimitive(it) }))
                            }
                            if (uploadedPaths.isNotEmpty()) {
                                put("images", JsonArray(uploadedPaths.map { JsonPrimitive(it) }))
                            }
                            if (selectedOptionKey == "am" || selectedOptionKey == "pm") {
                                put("half_day", selectedOptionKey)
                            }
                            val hasDetails = selectedOptionKey.isNotBlank() ||
                                selectedType?.inputType == "time" ||
                                (selectedType?.inputType == "text" && effectiveDetailText.isNotEmpty())
                            if (hasDetails) {
                                put("details", buildJsonObject {
                                    if (selectedOptionKey.isNotBlank()) put("option", selectedOptionKey)
                                    if (selectedType?.inputType == "time") put("time", timeInputText.trim())
                                    if (selectedType?.inputType == "text" && effectiveDetailText.isNotEmpty()) put("text", effectiveDetailText)
                                })
                            }
                            if (hasEveningSelected) {
                                selectedEveningStatusId?.let { put("evening_study_status_id", it) }
                                put("destination", destination.trim())
                            }
                        }
                        runCatching { repo.submitLeaveRequest(payload) }
                            .onSuccess {
                                showSuccess = true
                                selectedSlotId = null
                                selectedPeriodIds = emptySet()
                                customDetailText = ""
                                reason = ""
                                destination = ""
                                uploadedPaths.clear()
                            }
                            .onFailure { error = it.localizedMessage ?: "提交失败" }
                        submitting = false
                    }
                }
            ) {
                if (submitting) {
                    CircularProgressIndicator(modifier = Modifier.size(18.dp), strokeWidth = 2.dp, color = Color.White)
                    Spacer(Modifier.width(8.dp))
                }
                Text(if (submitting) "提交中..." else "提交请假申请", fontWeight = FontWeight.SemiBold)
            }
        }
    }

    pickingDateFor?.let { target ->
        val initDate = if (target == "start") startDate else endDate
        val state = rememberDatePickerState(
            initialSelectedDateMillis = initDate.atStartOfDay(ZoneId.of("UTC")).toInstant().toEpochMilli()
        )
        DatePickerDialog(
            onDismissRequest = { pickingDateFor = null },
            confirmButton = {
                TextButton(onClick = {
                    state.selectedDateMillis?.let { ms ->
                        val picked = Instant.ofEpochMilli(ms).atZone(ZoneId.of("UTC")).toLocalDate()
                        if (target == "start") {
                            startDate = picked
                            if (endDate.isBefore(picked)) endDate = picked
                        } else {
                            endDate = if (picked.isBefore(startDate)) startDate else picked
                        }
                    }
                    pickingDateFor = null
                }) { Text("确定") }
            },
            dismissButton = { TextButton(onClick = { pickingDateFor = null }) { Text("取消") } }
        ) {
            DatePicker(state = state)
        }
    }

    if (showSuccess) {
        AlertDialog(
            onDismissRequest = { showSuccess = false },
            title = { Text("请假申请已提交") },
            text = { Text("申请已发送给班主任与班级管理员，审批结果会通过通知提醒您。") },
            confirmButton = { TextButton(onClick = { showSuccess = false }) { Text("好的") } }
        )
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun LeaveHistoryScreen(
    user: User,
    showTopBar: Boolean = true,
    defaultOwnerScope: String = "all"
) {
    val repo = SmartCampusApp.container.repository
    val scope = rememberCoroutineScope()
    val filters = listOf("all" to "全部", "pending" to "待审批", "approved" to "已通过", "rejected" to "已驳回")

    var statusFilter by remember { mutableStateOf("all") }
    var ownerScope by remember(defaultOwnerScope) { mutableStateOf(defaultOwnerScope) } // "all" | "mine"
    var searchText by remember { mutableStateOf("") }
    var requests by remember { mutableStateOf<List<LeaveRequestGroup>>(emptyList()) }
    var loading by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var cancelTarget by remember { mutableStateOf<LeaveRequestGroup?>(null) }
    var previewImage by remember { mutableStateOf<String?>(null) }

    val myStudentId = user.student?.id
    fun isOwnRequest(req: LeaveRequestGroup): Boolean {
        if (myStudentId == null || req.studentId == null) return true
        return req.studentId == myStudentId
    }

    val showStudentIdentity = remember(user.isClassAdminStudent, requests, myStudentId) {
        user.isClassAdminStudent || requests.any { !isOwnRequest(it) }
    }

    val filteredRequests = remember(requests, showStudentIdentity, ownerScope, searchText, myStudentId) {
        val q = searchText.trim().lowercase()
        requests.filter { req ->
            if (showStudentIdentity && ownerScope == "mine" && !isOwnRequest(req)) return@filter false
            if (q.isEmpty()) true
            else req.studentName.lowercase().contains(q)
                || (req.studentNo ?: "").lowercase().contains(q)
                || (req.reason ?: "").lowercase().contains(q)
        }
    }

    fun load() {
        scope.launch {
            loading = true
            error = null
            runCatching { repo.fetchLeaveRequests(statusFilter) }
                .onSuccess { requests = it }
                .onFailure { error = it.localizedMessage }
            loading = false
        }
    }

    LaunchedEffect(statusFilter) { load() }

    Scaffold(
        topBar = {
            if (showTopBar) {
                TopAppBar(
                    title = { Text(if (showStudentIdentity) "班级请假记录" else "请假记录") },
                    actions = { IconButton(onClick = { load() }) { Icon(Icons.Rounded.Refresh, contentDescription = "刷新") } }
                )
            }
        }
    ) { padding ->
        Column(modifier = Modifier.fillMaxSize().padding(padding).padding(horizontal = 16.dp)) {
            SingleChoiceSegmentedButtonRow(modifier = Modifier.fillMaxWidth().padding(vertical = 8.dp)) {
                filters.forEachIndexed { idx, (key, label) ->
                    SegmentedButton(
                        selected = statusFilter == key,
                        onClick = { statusFilter = key },
                        shape = SegmentedButtonDefaults.itemShape(idx, filters.size)
                    ) { Text(label) }
                }
            }

            if (showStudentIdentity) {
                Row(
                    modifier = Modifier.fillMaxWidth().padding(bottom = 8.dp),
                    verticalAlignment = Alignment.CenterVertically,
                    horizontalArrangement = Arrangement.spacedBy(8.dp)
                ) {
                    listOf("all" to "全班", "mine" to "仅看我的").forEach { (key, label) ->
                        FilterChip(
                            selected = ownerScope == key,
                            onClick = { ownerScope = key },
                            label = { Text(label, style = MaterialTheme.typography.labelMedium) }
                        )
                    }
                    OutlinedTextField(
                        value = searchText,
                        onValueChange = { searchText = it },
                        placeholder = { Text("搜姓名/学号", style = MaterialTheme.typography.labelMedium) },
                        leadingIcon = { Icon(Icons.Rounded.Search, contentDescription = null, modifier = Modifier.size(16.dp)) },
                        singleLine = true,
                        modifier = Modifier.weight(1f)
                    )
                }
            }

            error?.let {
                ErrorBanner(message = it, onRetry = { load() })
                Spacer(Modifier.height(10.dp))
            }

            if (loading && requests.isEmpty()) {
                Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
            } else if (filteredRequests.isEmpty()) {
                Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                    Text("暂无请假记录", color = MaterialTheme.colorScheme.onSurfaceVariant)
                }
            } else {
                LazyColumn(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    items(filteredRequests, key = { it.id }) { req ->
                        val isMine = isOwnRequest(req)
                        SCCard {
                            if (showStudentIdentity) {
                                Row(
                                    verticalAlignment = Alignment.CenterVertically,
                                    horizontalArrangement = Arrangement.spacedBy(6.dp),
                                    modifier = Modifier.padding(bottom = 8.dp)
                                ) {
                                    Icon(
                                        Icons.Rounded.Person,
                                        contentDescription = null,
                                        tint = MaterialTheme.colorScheme.primary,
                                        modifier = Modifier.size(16.dp)
                                    )
                                    Text(
                                        req.studentName,
                                        style = MaterialTheme.typography.titleSmall,
                                        fontWeight = FontWeight.Bold
                                    )
                                    req.studentNo?.takeIf { it.isNotBlank() }?.let { no ->
                                        Text(
                                            no,
                                            style = MaterialTheme.typography.labelMedium,
                                            color = MaterialTheme.colorScheme.onSurfaceVariant
                                        )
                                    }
                                    if (isMine) {
                                        StatusBadge("我", MaterialTheme.colorScheme.primary)
                                    }
                                }
                            }
                            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                                StatusBadge(req.leaveTypeName, statusColor(req.leaveTypeColor))
                                StatusBadge(req.statusDisplayName, statusColor(req.status))
                                Spacer(Modifier.weight(1f))
                                if (req.totalDays > 0) {
                                    Text("${req.totalDays} 天", style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                                }
                            }
                            Spacer(Modifier.height(8.dp))
                            Text(req.dateRangeText, style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.SemiBold)
                            req.periodLabel?.takeIf { it.isNotBlank() }?.let {
                                Text("节次/时段：$it", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                            }
                            req.eveningStudyLabel?.takeIf { it.isNotBlank() }?.let {
                                Text("夜自习：$it" + (req.destination?.let { d -> "（去向：$d）" }.orEmpty()), style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.primary)
                            }
                            req.displayReason?.takeIf { it.isNotBlank() }?.let {
                                Spacer(Modifier.height(4.dp))
                                Text("事由：$it", style = MaterialTheme.typography.bodySmall)
                            }
                            if (req.images.isNotEmpty()) {
                                Spacer(Modifier.height(8.dp))
                                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                    req.images.forEach { path ->
                                        RemoteMediaThumb(pathOrUrl = path, modifier = Modifier.size(64.dp), onClick = { previewImage = path })
                                    }
                                }
                            }
                            Spacer(Modifier.height(8.dp))
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Column(modifier = Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                                    req.createdAt?.takeIf { it.isNotBlank() }?.let { created ->
                                        Text(
                                            "申请时间：${SCDateFormatter.displayDateTime(created)}",
                                            style = MaterialTheme.typography.labelSmall,
                                            color = MaterialTheme.colorScheme.onSurfaceVariant
                                        )
                                    }
                                    req.approverName?.takeIf { it.isNotBlank() }?.let { approver ->
                                        val approvedTime = req.approvedAt?.takeIf { it.isNotBlank() }?.let { " · ${SCDateFormatter.displayDateTime(it)}" }.orEmpty()
                                        Text(
                                            "审批人：$approver$approvedTime",
                                            style = MaterialTheme.typography.labelSmall,
                                            color = MaterialTheme.colorScheme.onSurfaceVariant
                                        )
                                    }
                                }
                                if (req.status == "pending" && isMine) {
                                    OutlinedButton(
                                        onClick = { cancelTarget = req },
                                        colors = ButtonDefaults.outlinedButtonColors(contentColor = Color(0xFFDC2626))
                                    ) { Text("撤销申请") }
                                }
                            }
                        }
                    }
                    item { Spacer(Modifier.height(12.dp)) }
                }
            }
        }
    }

    cancelTarget?.let { target ->
        AlertDialog(
            onDismissRequest = { cancelTarget = null },
            title = { Text("撤销请假申请") },
            text = { Text("确认撤销该条待审批请假申请吗？") },
            confirmButton = {
                TextButton(onClick = {
                    cancelTarget = null
                    scope.launch {
                        runCatching { repo.cancelLeaveRequest(target.id) }
                            .onSuccess {
                                requests = requests.filterNot { it.id == target.id }
                                SmartCampusApp.container.pushManager.refreshUnreadCount()
                            }
                            .onFailure { error = it.localizedMessage }
                    }
                }) { Text("确认撤销", color = Color(0xFFDC2626)) }
            },
            dismissButton = { TextButton(onClick = { cancelTarget = null }) { Text("取消") } }
        )
    }

    previewImage?.let { FullScreenImageDialog(pathOrUrl = it, onDismiss = { previewImage = null }) }
}

private data class ClassAdminSummaryItem(
    val id: String,
    val studentId: Int?,
    val studentShortLabel: String,
    val studentName: String,
    val studentNo: String?,
    val typeName: String,
    val colorKey: String,
    val approvalPrefix: String,
    val approvalStatus: String?,
    val sourceLabel: String?,
    val periodText: String,
    val note: String?,
    val destination: String?,
    val timeText: String?,
    val images: List<String>
)

@OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)
@Composable
fun ClassAttendanceAdminScreen(user: User, onBack: () -> Unit) {
    val repo = SmartCampusApp.container.repository
    val scope = rememberCoroutineScope()
    val today = remember { LocalDate.now() }

    val summaryColumns by SmartCampusApp.container.prefs.classAdminSummaryColumnsFlow.collectAsStateWithLifecycle(initialValue = 2)
    val rosterColumns by SmartCampusApp.container.prefs.classAdminRosterColumnsFlow.collectAsStateWithLifecycle(initialValue = 2)

    var activeTab by remember { mutableStateOf("summary") } // "summary" | "roster"
    var selectedDate by remember { mutableStateOf(today) }
    var showDatePicker by remember { mutableStateOf(false) }

    var students by remember { mutableStateOf<List<OverviewStudent>>(emptyList()) }
    var calendarDayRecords by remember { mutableStateOf<List<CalendarSummaryRecord>>(emptyList()) }
    var hasCalendarSummaryData by remember { mutableStateOf(false) }
    var leaveTypes by remember { mutableStateOf<List<LeaveType>>(emptyList()) }
    var classPeriods by remember { mutableStateOf<List<ClassPeriod>>(emptyList()) }
    var timeSlots by remember { mutableStateOf<List<TimeSlot>>(emptyList()) }

    var selectedTypeFilter by remember { mutableStateOf<String?>(null) }
    var typeMenuOpen by remember { mutableStateOf(false) }
    var rosterFilter by remember { mutableStateOf("all") } // "all" | "has_records" | "normal"
    var rosterSearch by remember { mutableStateOf("") }

    var inspectedItem by remember { mutableStateOf<ClassAdminSummaryItem?>(null) }
    var editingStudent by remember { mutableStateOf<OverviewStudent?>(null) }
    var previewImage by remember { mutableStateOf<String?>(null) }

    var loading by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    val selectedDateKey = remember(selectedDate) { selectedDate.format(SCDateFormatter.yyyyMMdd) }
    val selectedMonthKey = remember(selectedDate) { YearMonth.from(selectedDate).toString() }
    val regularPeriods = remember(classPeriods) {
        val f = classPeriods.filter { it.isActive && !it.isEveningStudy }
        f.ifEmpty { classPeriods }
    }
    val activeLeaveTypes = remember(leaveTypes) { leaveTypes.filter { it.isActive } }

    fun shortStudentTitle(s: OverviewStudent): String =
        if (!s.studentNo.isNullOrBlank() && s.studentNo.length >= 2) "${s.studentNo.takeLast(2)} ${s.name}" else s.name

    fun formatCnDate(d: LocalDate): String {
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

    fun matchedStudent(sid: Int?, sno: String?, name: String): OverviewStudent? {
        sid?.let { id -> students.firstOrNull { it.id == id }?.let { return it } }
        sno?.takeIf { it.isNotBlank() }?.let { no -> students.firstOrNull { it.studentNo == no }?.let { return it } }
        return students.firstOrNull { it.name == name }
    }

    fun normalizedTypeName(rec: CalendarSummaryRecord): String {
        val clean = rec.type.trim()
        if (clean.isNotEmpty() && clean != "未知") return clean
        return when (rec.status) {
            "absent" -> "旷课"
            "late" -> "迟到"
            "early_leave" -> "早退"
            "excused", "leave" -> "请假"
            else -> "考勤"
        }
    }

    val allSummaryItems = remember(
        hasCalendarSummaryData, calendarDayRecords, students, regularPeriods, timeSlots
    ) {
        if (hasCalendarSummaryData) {
            calendarDayRecords
                .filter { !it.isNormalPresentRecord && it.approvalStatus != "rejected" }
                .map { rec ->
                    val typeName = normalizedTypeName(rec)
                    val compact = rec.compactDisplay
                    val rawOpt = compact.first.trim()
                    val st = matchedStudent(rec.studentId, rec.studentNo, rec.studentName)
                    val idSet = rec.allRecordIds.toSet()
                    val validRaw = st?.attendance?.filterNot { it.isNormalPresentRecord }.orEmpty()
                    val matchingRaw = validRaw.filter { it.id in idSet }.ifEmpty {
                        val isEvening = rec.option?.contains("夜自习") == true
                        validRaw.filter { item ->
                            ((item.scene ?: "regular") == "evening_study") == isEvening &&
                                (item.titleText == typeName || item.status == rec.status) &&
                                (rec.approvalStatus == null || item.approvalStatus == rec.approvalStatus)
                        }
                    }

                    var resolvedNote = compact.second?.trim()?.takeIf { it.isNotEmpty() }
                    if (resolvedNote == null) {
                        for (r in matchingRaw) {
                            val cand = listOf(r.reason, r.note, r.detailsText)
                                .firstNotNullOfOrNull { it?.trim()?.takeIf { s -> s.isNotEmpty() && s != rawOpt } }
                            if (cand != null) {
                                resolvedNote = cand
                                break
                            }
                        }
                    }

                    val dest = matchingRaw.firstNotNullOfOrNull { it.destination?.trim()?.takeIf { s -> s.isNotEmpty() } }
                    val imgs = matchingRaw.flatMap { it.images }.filter { it.isNotBlank() }
                    val srcLabel = if (rec.isSelfApplied) "自请" else matchingRaw.firstOrNull()?.sourceBadgeText
                    val periodText = if (rawOpt.isNotEmpty()) {
                        rawOpt
                    } else {
                        val allIds = matchingRaw.flatMap { it.extractedPeriodIds(regularPeriods) }.distinct().sorted()
                        if (allIds.isNotEmpty()) {
                            val detailed = PeriodFormatter.formatDetailedPeriodSummary(
                                allIds, regularPeriods, timeSlots, matchingRaw.firstOrNull()?.detailsTimeSlotName
                            )
                            if (detailed.isNotBlank()) {
                                if (allIds.size > 1 && !detailed.contains("节)")) "$detailed (${allIds.size}节)" else detailed
                            } else "全天"
                        } else "全天"
                    }

                    ClassAdminSummaryItem(
                        id = "cal-${rec.id}",
                        studentId = rec.studentId ?: st?.id,
                        studentShortLabel = rec.studentShortLabel,
                        studentName = rec.studentName,
                        studentNo = rec.studentNo ?: st?.studentNo,
                        typeName = typeName,
                        colorKey = rec.displayColorKey,
                        approvalPrefix = rec.approvalPrefix,
                        approvalStatus = rec.approvalStatus,
                        sourceLabel = srcLabel,
                        periodText = periodText,
                        note = resolvedNote,
                        destination = dest,
                        timeText = rec.time?.takeIf { it.isNotBlank() },
                        images = imgs
                    )
                }
        } else {
            val built = mutableListOf<ClassAdminSummaryItem>()
            students.forEach { st ->
                val valid = st.attendance.filter { !it.isNormalPresentRecord && it.approvalStatus != "rejected" }
                if (valid.isEmpty()) return@forEach
                val groups = DayRecordGroup.build(valid, regularPeriods, timeSlots, groupByDate = false)
                groups.forEach { group ->
                    val primary = group.primary
                    val isSelf = primary.isSelfApplied || primary.sourceType == "self_applied" || primary.sourceType == "leave_request"
                    val apPrefix = if (!isSelf) "" else when (primary.approvalStatus) {
                        "pending" -> "待审:"
                        "approved" -> "批准:"
                        "rejected" -> "驳回:"
                        else -> ""
                    }
                    val periodText = if (group.isEveningStudy) {
                        val dest = primary.destination?.trim().orEmpty()
                        val es = primary.eveningStudyStatusName ?: primary.eveningStudyLabel.orEmpty()
                        when {
                            dest.isNotEmpty() -> "夜自习 · $dest"
                            es.isNotEmpty() -> "夜自习 · $es"
                            else -> "夜自习"
                        }
                    } else {
                        val base = group.periodDetailText.trim()
                        when {
                            base.isEmpty() -> "全天"
                            group.periodIds.size > 1 && !base.contains("节)") -> "$base (${group.periodIds.size}节)"
                            else -> base
                        }
                    }
                    built.add(
                        ClassAdminSummaryItem(
                            id = "ov-${st.id}-${group.id}",
                            studentId = st.id,
                            studentShortLabel = shortStudentTitle(st),
                            studentName = st.name,
                            studentNo = st.studentNo,
                            typeName = primary.titleText,
                            colorKey = primary.badgeColorKey,
                            approvalPrefix = apPrefix,
                            approvalStatus = primary.approvalStatus,
                            sourceLabel = primary.sourceBadgeText,
                            periodText = periodText,
                            note = group.remark,
                            destination = group.records.firstNotNullOfOrNull { it.destination?.trim()?.takeIf { s -> s.isNotEmpty() } },
                            timeText = primary.displayTimeText,
                            images = group.records.flatMap { it.images }.filter { it.isNotBlank() }
                        )
                    )
                }
            }
            built.sortedWith(
                compareByDescending<ClassAdminSummaryItem> { it.approvalStatus == "pending" }
                    .thenBy { it.studentNo ?: it.studentShortLabel }
            )
        }
    }

    val distinctStudentCount = remember(allSummaryItems) {
        allSummaryItems.map { it.studentId?.toString() ?: it.studentShortLabel }.distinct().size
    }
    val pendingSummaryCount = remember(allSummaryItems) {
        allSummaryItems.count { it.approvalStatus == "pending" }
    }
    val typeFilterOptions = remember(allSummaryItems, activeLeaveTypes) {
        val counts = mutableMapOf<String, Int>()
        val colors = mutableMapOf<String, String>()
        allSummaryItems.forEach { item ->
            counts[item.typeName] = (counts[item.typeName] ?: 0) + 1
            if (item.typeName !in colors) colors[item.typeName] = item.colorKey
        }
        val list = mutableListOf<Triple<String, Int, String>>()
        val seen = mutableSetOf<String>()
        activeLeaveTypes.forEach { lt ->
            val n = lt.name.trim()
            val c = counts[n] ?: 0
            if (n.isNotEmpty() && c > 0 && seen.add(n)) {
                list.add(Triple(n, c, lt.resolvedColorKey))
            }
        }
        allSummaryItems.forEach { item ->
            if (seen.add(item.typeName)) {
                list.add(Triple(item.typeName, counts[item.typeName] ?: 0, colors[item.typeName] ?: item.colorKey))
            }
        }
        list
    }
    val filteredSummaryItems = remember(allSummaryItems, selectedTypeFilter) {
        val f = selectedTypeFilter ?: return@remember allSummaryItems
        if (f == "__pending__") allSummaryItems.filter { it.approvalStatus == "pending" }
        else allSummaryItems.filter { it.typeName == f }
    }

    val rosterHasRecordCount = remember(students) {
        students.count { s -> s.attendance.any { !it.isNormalPresentRecord && it.approvalStatus != "rejected" } }
    }
    val rosterNormalCount = (students.size - rosterHasRecordCount).coerceAtLeast(0)
    val filteredRoster = remember(students, rosterFilter, rosterSearch) {
        val q = rosterSearch.trim().lowercase()
        students.filter { s ->
            val hasRec = s.attendance.any { !it.isNormalPresentRecord && it.approvalStatus != "rejected" }
            when (rosterFilter) {
                "has_records" -> if (!hasRec) return@filter false
                "normal" -> if (hasRec) return@filter false
            }
            if (q.isEmpty()) true else s.name.lowercase().contains(q) || (s.studentNo ?: "").lowercase().contains(q)
        }
    }

    fun loadData() {
        scope.launch {
            loading = true
            error = null
            val dk = selectedDateKey
            val mk = selectedMonthKey
            val ovRes = runCatching { repo.fetchAttendanceOverview(dk, includeEvening = true) }.getOrNull()
            val sumRes = runCatching { repo.fetchCalendarSummary(mk) }.getOrNull()

            if (ovRes != null) {
                students = ovRes.flatMap { it.classes }.flatMap { it.students }
                    .sortedBy { it.studentNo.orEmpty() }
            } else if (sumRes == null) {
                error = "加载本班考勤数据失败，请刷新重试"
            }

            if (sumRes != null) {
                hasCalendarSummaryData = true
                calendarDayRecords = sumRes[dk].orEmpty()
            } else {
                hasCalendarSummaryData = false
                calendarDayRecords = emptyList()
            }
            loading = false
        }
    }

    LaunchedEffect(Unit) {
        leaveTypes = runCatching { repo.fetchLeaveTypes() }.getOrDefault(emptyList())
        classPeriods = runCatching { repo.fetchClassPeriods() }.getOrDefault(emptyList())
        timeSlots = runCatching { repo.fetchTimeSlots() }.getOrDefault(emptyList())
    }
    LaunchedEffect(selectedDateKey) {
        selectedTypeFilter = null
        loadData()
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("班级考勤情况") },
                navigationIcon = {
                    IconButton(onClick = onBack) { Icon(Icons.AutoMirrored.Rounded.ArrowBack, contentDescription = "返回") }
                },
                actions = {
                    IconButton(onClick = { loadData() }, enabled = !loading) {
                        if (loading) {
                            CircularProgressIndicator(modifier = Modifier.size(18.dp), strokeWidth = 2.dp)
                        } else {
                            Icon(Icons.Rounded.Refresh, contentDescription = "刷新")
                        }
                    }
                }
            )
        }
    ) { padding ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(padding)
                .padding(horizontal = 16.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp)
        ) {
            // 1. 顶部日期切换栏
            Row(
                modifier = Modifier.fillMaxWidth().padding(top = 6.dp),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.spacedBy(8.dp)
            ) {
                Surface(
                    shape = CircleShape,
                    color = MaterialTheme.colorScheme.surfaceVariant,
                    modifier = Modifier.size(34.dp).clip(CircleShape).clickable { selectedDate = selectedDate.minusDays(1) }
                ) {
                    Box(contentAlignment = Alignment.Center) {
                        Icon(Icons.Rounded.ChevronLeft, contentDescription = "前一天", modifier = Modifier.size(20.dp))
                    }
                }
                Surface(
                    shape = RoundedCornerShape(10.dp),
                    color = MaterialTheme.colorScheme.surface,
                    border = BorderStroke(1.dp, MaterialTheme.colorScheme.outlineVariant),
                    modifier = Modifier.weight(1f).clip(RoundedCornerShape(10.dp)).clickable { showDatePicker = true }
                ) {
                    Row(
                        modifier = Modifier.padding(vertical = 8.dp, horizontal = 10.dp),
                        verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.Center
                    ) {
                        Icon(Icons.Rounded.CalendarMonth, contentDescription = null, tint = MaterialTheme.colorScheme.primary, modifier = Modifier.size(16.dp))
                        Spacer(Modifier.width(6.dp))
                        Text(formatCnDate(selectedDate), style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold)
                        Spacer(Modifier.width(4.dp))
                        Icon(Icons.Rounded.ExpandMore, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.size(16.dp))
                    }
                }
                Surface(
                    shape = CircleShape,
                    color = MaterialTheme.colorScheme.surfaceVariant,
                    modifier = Modifier.size(34.dp).clip(CircleShape).clickable { selectedDate = selectedDate.plusDays(1) }
                ) {
                    Box(contentAlignment = Alignment.Center) {
                        Icon(Icons.Rounded.ChevronRight, contentDescription = "后一天", modifier = Modifier.size(20.dp))
                    }
                }
                if (selectedDate != today) {
                    Surface(
                        shape = CircleShape,
                        color = MaterialTheme.colorScheme.primary.copy(alpha = 0.14f),
                        modifier = Modifier.size(34.dp).clip(CircleShape).clickable { selectedDate = today }
                    ) {
                        Box(contentAlignment = Alignment.Center) {
                            Text("今", style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.SemiBold, color = MaterialTheme.colorScheme.primary)
                        }
                    }
                }
            }

            // 2. 双分段：考勤情况 / 全班名单
            SingleChoiceSegmentedButtonRow(modifier = Modifier.fillMaxWidth()) {
                listOf("summary" to "考勤情况", "roster" to "全班名单").forEachIndexed { idx, (k, label) ->
                    SegmentedButton(
                        selected = activeTab == k,
                        onClick = { activeTab = k },
                        shape = SegmentedButtonDefaults.itemShape(idx, 2)
                    ) { Text(label) }
                }
            }

            error?.let { ErrorBanner(message = it, onRetry = { loadData() }) }

            if (activeTab == "summary") {
                // 概览统计卡
                SCCard {
                    Row(modifier = Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                        Column(modifier = Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                            Text(
                                if (selectedDate == today) "今日班级考勤概况" else "$selectedDateKey 考勤概况",
                                style = MaterialTheme.typography.titleSmall,
                                fontWeight = FontWeight.Bold
                            )
                            Text(
                                when {
                                    allSummaryItems.isEmpty() && loading -> "正在刷新数据..."
                                    allSummaryItems.isEmpty() -> "当日暂无请假或异常标记，全班出勤正常"
                                    pendingSummaryCount > 0 -> "共 ${distinctStudentCount} 人有记录 · 合计 ${allSummaryItems.size} 条（其中 ${pendingSummaryCount} 条待审批）"
                                    else -> "共 ${distinctStudentCount} 人有记录 · 合计 ${allSummaryItems.size} 条"
                                },
                                style = MaterialTheme.typography.labelSmall,
                                color = if (allSummaryItems.isEmpty() && !loading) Color(0xFF16A34A) else MaterialTheme.colorScheme.onSurfaceVariant
                            )
                        }
                        if (students.isNotEmpty()) {
                            Text("全班 ${students.size} 人", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                        }
                    }

                    if (typeFilterOptions.isNotEmpty() || pendingSummaryCount > 0) {
                        Spacer(Modifier.height(8.dp))
                        Row(
                            modifier = Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()),
                            horizontalArrangement = Arrangement.spacedBy(6.dp)
                        ) {
                            if (pendingSummaryCount > 0) {
                                val active = selectedTypeFilter == "__pending__"
                                Surface(
                                    shape = RoundedCornerShape(999.dp),
                                    color = if (active) Color(0xFFEA580C) else Color(0xFFEA580C).copy(alpha = 0.12f),
                                    modifier = Modifier.clip(RoundedCornerShape(999.dp)).clickable {
                                        selectedTypeFilter = if (active) null else "__pending__"
                                    }
                                ) {
                                    Text(
                                        "待审 $pendingSummaryCount",
                                        style = MaterialTheme.typography.labelSmall,
                                        fontWeight = FontWeight.SemiBold,
                                        color = if (active) Color.White else Color(0xFFEA580C),
                                        modifier = Modifier.padding(horizontal = 9.dp, vertical = 4.dp)
                                    )
                                }
                            }
                            typeFilterOptions.forEach { (name, cnt, colorKey) ->
                                val tint = statusColor(colorKey)
                                val active = selectedTypeFilter == name
                                Surface(
                                    shape = RoundedCornerShape(999.dp),
                                    color = if (active) tint else tint.copy(alpha = 0.12f),
                                    modifier = Modifier.clip(RoundedCornerShape(999.dp)).clickable {
                                        selectedTypeFilter = if (active) null else name
                                    }
                                ) {
                                    Text(
                                        "$name $cnt",
                                        style = MaterialTheme.typography.labelSmall,
                                        fontWeight = FontWeight.SemiBold,
                                        color = if (active) Color.White else tint,
                                        modifier = Modifier.padding(horizontal = 9.dp, vertical = 4.dp)
                                    )
                                }
                            }
                        }
                    }
                }

                // 下拉类型筛选 + 1列/2列切换
                Row(
                    modifier = Modifier.fillMaxWidth(),
                    verticalAlignment = Alignment.CenterVertically,
                    horizontalArrangement = Arrangement.SpaceBetween
                ) {
                    Box {
                        val currentLabel = when (val f = selectedTypeFilter) {
                            null -> "全部 (${allSummaryItems.size})"
                            "__pending__" -> "待审批 ($pendingSummaryCount)"
                            else -> "$f (${typeFilterOptions.firstOrNull { it.first == f }?.second ?: filteredSummaryItems.size})"
                        }
                        Surface(
                            shape = RoundedCornerShape(999.dp),
                            color = MaterialTheme.colorScheme.surface,
                            border = BorderStroke(1.dp, MaterialTheme.colorScheme.outlineVariant),
                            modifier = Modifier.clip(RoundedCornerShape(999.dp)).clickable { typeMenuOpen = true }
                        ) {
                            Row(
                                modifier = Modifier.padding(horizontal = 11.dp, vertical = 6.dp),
                                verticalAlignment = Alignment.CenterVertically,
                                horizontalArrangement = Arrangement.spacedBy(4.dp)
                            ) {
                                Text(currentLabel, style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.SemiBold)
                                Icon(Icons.Rounded.ExpandMore, contentDescription = null, modifier = Modifier.size(16.dp), tint = MaterialTheme.colorScheme.onSurfaceVariant)
                            }
                        }
                        DropdownMenu(expanded = typeMenuOpen, onDismissRequest = { typeMenuOpen = false }) {
                            DropdownMenuItem(
                                text = { Text("全部 (${allSummaryItems.size})") },
                                trailingIcon = if (selectedTypeFilter == null) {
                                    { Icon(Icons.Rounded.Check, contentDescription = null, modifier = Modifier.size(16.dp)) }
                                } else null,
                                onClick = { selectedTypeFilter = null; typeMenuOpen = false }
                            )
                            if (pendingSummaryCount > 0) {
                                DropdownMenuItem(
                                    text = { Text("仅看待审批 ($pendingSummaryCount)") },
                                    trailingIcon = if (selectedTypeFilter == "__pending__") {
                                        { Icon(Icons.Rounded.Check, contentDescription = null, modifier = Modifier.size(16.dp)) }
                                    } else null,
                                    onClick = { selectedTypeFilter = "__pending__"; typeMenuOpen = false }
                                )
                            }
                            if (typeFilterOptions.isNotEmpty()) {
                                HorizontalDivider()
                                typeFilterOptions.forEach { (name, cnt, _) ->
                                    DropdownMenuItem(
                                        text = { Text("$name ($cnt)") },
                                        trailingIcon = if (selectedTypeFilter == name) {
                                            { Icon(Icons.Rounded.Check, contentDescription = null, modifier = Modifier.size(16.dp)) }
                                        } else null,
                                        onClick = { selectedTypeFilter = name; typeMenuOpen = false }
                                    )
                                }
                            }
                        }
                    }

                    ClassAdminColumnToggle(
                        selectedColumns = summaryColumns,
                        onSelect = { cols -> scope.launch { SmartCampusApp.container.prefs.setClassAdminSummaryColumns(cols) } }
                    )
                }

                // 1列 / 2列 彩色记录卡片列表
                if (loading && allSummaryItems.isEmpty()) {
                    Box(Modifier.fillMaxWidth().weight(1f), contentAlignment = Alignment.Center) {
                        CircularProgressIndicator()
                    }
                } else if (filteredSummaryItems.isEmpty()) {
                    SCCard {
                        Column(
                            modifier = Modifier.fillMaxWidth().padding(vertical = 32.dp),
                            horizontalAlignment = Alignment.CenterHorizontally,
                            verticalArrangement = Arrangement.spacedBy(8.dp)
                        ) {
                            Icon(Icons.Rounded.CheckCircle, contentDescription = null, tint = Color(0xFF16A34A).copy(alpha = 0.7f), modifier = Modifier.size(34.dp))
                            Text(
                                if (selectedTypeFilter == null) "当日暂无请假或考勤标记记录" else "当前筛选下暂无考勤记录",
                                style = MaterialTheme.typography.bodySmall,
                                color = MaterialTheme.colorScheme.onSurfaceVariant
                            )
                        }
                    }
                } else if (summaryColumns == 2) {
                    LazyColumn(
                        modifier = Modifier.weight(1f),
                        verticalArrangement = Arrangement.spacedBy(10.dp),
                        contentPadding = PaddingValues(bottom = 20.dp)
                    ) {
                        items(filteredSummaryItems.chunked(2)) { pair ->
                            Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                                pair.forEach { item ->
                                    ClassAdminColoredRecordCard(
                                        item = item,
                                        isCompactTwoColumn = true,
                                        modifier = Modifier.weight(1f),
                                        onClick = { inspectedItem = item }
                                    )
                                }
                                if (pair.size == 1) Spacer(Modifier.weight(1f))
                            }
                        }
                    }
                } else {
                    LazyColumn(
                        modifier = Modifier.weight(1f),
                        verticalArrangement = Arrangement.spacedBy(10.dp),
                        contentPadding = PaddingValues(bottom = 20.dp)
                    ) {
                        items(filteredSummaryItems, key = { it.id }) { item ->
                            ClassAdminColoredRecordCard(
                                item = item,
                                isCompactTwoColumn = false,
                                modifier = Modifier.fillMaxWidth(),
                                onClick = { inspectedItem = item }
                            )
                        }
                    }
                }
            } else {
                // 全班名单标签
                Row(
                    modifier = Modifier.fillMaxWidth(),
                    verticalAlignment = Alignment.CenterVertically,
                    horizontalArrangement = Arrangement.spacedBy(8.dp)
                ) {
                    OutlinedTextField(
                        value = rosterSearch,
                        onValueChange = { rosterSearch = it },
                        placeholder = { Text("搜索姓名或学号", style = MaterialTheme.typography.bodySmall) },
                        leadingIcon = { Icon(Icons.Rounded.Search, contentDescription = null, modifier = Modifier.size(18.dp)) },
                        singleLine = true,
                        shape = RoundedCornerShape(10.dp),
                        modifier = Modifier.weight(1f)
                    )
                    ClassAdminColumnToggle(
                        selectedColumns = rosterColumns,
                        onSelect = { cols -> scope.launch { SmartCampusApp.container.prefs.setClassAdminRosterColumns(cols) } }
                    )
                }

                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    listOf(
                        Triple("all", "全部", students.size),
                        Triple("has_records", "有记录", rosterHasRecordCount),
                        Triple("normal", "正常", rosterNormalCount)
                    ).forEach { (key, label, cnt) ->
                        val active = rosterFilter == key
                        Surface(
                            shape = RoundedCornerShape(999.dp),
                            color = if (active) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.surfaceVariant,
                            modifier = Modifier.clip(RoundedCornerShape(999.dp)).clickable { rosterFilter = key }
                        ) {
                            Text(
                                "$label $cnt",
                                style = MaterialTheme.typography.labelSmall,
                                fontWeight = if (active) FontWeight.SemiBold else FontWeight.Normal,
                                color = if (active) Color.White else MaterialTheme.colorScheme.onSurface,
                                modifier = Modifier.padding(horizontal = 10.dp, vertical = 6.dp)
                            )
                        }
                    }
                }

                if (loading && students.isEmpty()) {
                    Box(Modifier.fillMaxWidth().weight(1f), contentAlignment = Alignment.Center) {
                        CircularProgressIndicator()
                    }
                } else if (rosterColumns == 2) {
                    LazyColumn(
                        modifier = Modifier.weight(1f),
                        verticalArrangement = Arrangement.spacedBy(8.dp),
                        contentPadding = PaddingValues(bottom = 20.dp)
                    ) {
                        items(filteredRoster.chunked(2)) { pair ->
                            Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                pair.forEach { st ->
                                    val badges = remember(st.attendance, regularPeriods, timeSlots) {
                                        DayAttendanceBadgeGroup.build(
                                            st.attendance.filter { it.approvalStatus != "rejected" },
                                            regularPeriods,
                                            timeSlots
                                        )
                                    }
                                    SCCard(modifier = Modifier.weight(1f), onClick = { editingStudent = st }) {
                                        Row(modifier = Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                                            Text(shortStudentTitle(st), style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
                                            Icon(Icons.Rounded.ChevronRight, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.size(15.dp))
                                        }
                                        Spacer(Modifier.height(4.dp))
                                        if (badges.isEmpty()) {
                                            Text("正常出勤", style = MaterialTheme.typography.labelSmall, color = Color(0xFF16A34A))
                                        } else {
                                            Column(verticalArrangement = Arrangement.spacedBy(3.dp)) {
                                                badges.take(2).forEach { g ->
                                                    StatusBadge(g.badgeText, statusColor(g.colorKey))
                                                }
                                                if (badges.size > 2) {
                                                    Text("+${badges.size - 2} 项", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                                                }
                                            }
                                        }
                                    }
                                }
                                if (pair.size == 1) Spacer(Modifier.weight(1f))
                            }
                        }
                    }
                } else {
                    LazyColumn(
                        modifier = Modifier.weight(1f),
                        verticalArrangement = Arrangement.spacedBy(8.dp),
                        contentPadding = PaddingValues(bottom = 20.dp)
                    ) {
                        items(filteredRoster, key = { it.id }) { st ->
                            val badges = remember(st.attendance, regularPeriods, timeSlots) {
                                DayAttendanceBadgeGroup.build(
                                    st.attendance.filter { it.approvalStatus != "rejected" },
                                    regularPeriods,
                                    timeSlots
                                )
                            }
                            SCCard(onClick = { editingStudent = st }) {
                                Row(verticalAlignment = Alignment.CenterVertically) {
                                    Column(modifier = Modifier.weight(1f)) {
                                        Text(shortStudentTitle(st), style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                                        st.studentNo?.takeIf { it.isNotBlank() }?.let {
                                            Text(it, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                                        }
                                    }
                                    if (badges.isEmpty()) {
                                        Text("正常出勤", style = MaterialTheme.typography.labelSmall, color = Color(0xFF16A34A))
                                    } else {
                                        Column(horizontalAlignment = Alignment.End, verticalArrangement = Arrangement.spacedBy(3.dp)) {
                                            badges.take(2).forEach { g ->
                                                StatusBadge(g.badgeText, statusColor(g.colorKey))
                                            }
                                            if (badges.size > 2) {
                                                Text("+${badges.size - 2} 项", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                                            }
                                        }
                                    }
                                    Spacer(Modifier.width(4.dp))
                                    Icon(Icons.Rounded.ChevronRight, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.size(18.dp))
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    if (showDatePicker) {
        val pickerState = rememberDatePickerState(
            initialSelectedDateMillis = selectedDate.atStartOfDay(ZoneId.of("UTC")).toInstant().toEpochMilli()
        )
        DatePickerDialog(
            onDismissRequest = { showDatePicker = false },
            confirmButton = {
                TextButton(onClick = {
                    pickerState.selectedDateMillis?.let { ms ->
                        selectedDate = Instant.ofEpochMilli(ms).atZone(ZoneId.of("UTC")).toLocalDate()
                    }
                    showDatePicker = false
                }) { Text("确定") }
            },
            dismissButton = {
                TextButton(onClick = { showDatePicker = false }) { Text("取消") }
            }
        ) {
            DatePicker(state = pickerState)
        }
    }

    inspectedItem?.let { item ->
        val sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true)
        val matched = matchedStudent(item.studentId, item.studentNo, item.studentName)
        ModalBottomSheet(onDismissRequest = { inspectedItem = null }, sheetState = sheetState) {
            Column(
                modifier = Modifier
                    .fillMaxWidth()
                    .verticalScroll(rememberScrollState())
                    .padding(horizontal = 16.dp, vertical = 8.dp),
                verticalArrangement = Arrangement.spacedBy(12.dp)
            ) {
                Row(modifier = Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                    Text("考勤详情", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                    IconButton(onClick = { inspectedItem = null }) {
                        Icon(Icons.Rounded.Close, contentDescription = "关闭")
                    }
                }
                ClassAdminColoredRecordCard(item = item, isCompactTwoColumn = false, modifier = Modifier.fillMaxWidth(), onClick = {})
                SCCard {
                    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        ClassAdminDetailRow("日期", selectedDateKey)
                        ClassAdminDetailRow("学生", item.studentNo?.takeIf { it.isNotBlank() }?.let { "${item.studentName}（$it）" } ?: item.studentName)
                        ClassAdminDetailRow("考勤类型", item.typeName)
                        item.approvalStatus?.takeIf { it.isNotBlank() }?.let { ap ->
                            ClassAdminDetailRow("审批状态", when (ap) { "approved" -> "已批准"; "pending" -> "待审批"; else -> "已驳回" })
                        }
                        item.sourceLabel?.let { ClassAdminDetailRow("记录来源", it) }
                        ClassAdminDetailRow("课节/时段", item.periodText)
                        item.destination?.takeIf { it.isNotBlank() }?.let { ClassAdminDetailRow("去向", it) }
                        item.note?.takeIf { it.isNotBlank() }?.let { ClassAdminDetailRow("事由/备注", it) }
                        item.timeText?.takeIf { it.isNotBlank() }?.let { ClassAdminDetailRow("记录时间", it) }
                    }
                }
                if (item.images.isNotEmpty()) {
                    Row(
                        modifier = Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()),
                        horizontalArrangement = Arrangement.spacedBy(8.dp)
                    ) {
                        item.images.forEach { img ->
                            RemoteMediaThumb(
                                pathOrUrl = img,
                                modifier = Modifier.size(72.dp),
                                onClick = {
                                    inspectedItem = null
                                    previewImage = img
                                }
                            )
                        }
                    }
                }
                if (matched != null) {
                    OutlinedButton(
                        onClick = {
                            inspectedItem = null
                            editingStudent = matched
                        },
                        modifier = Modifier.fillMaxWidth()
                    ) {
                        Text("维护该生当日考勤")
                    }
                }
                Spacer(Modifier.height(16.dp))
            }
        }
    }

    editingStudent?.let { st ->
        ClassAdminStudentEditModal(
            student = st,
            dateKey = selectedDateKey,
            leaveTypes = activeLeaveTypes,
            classPeriods = regularPeriods,
            timeSlots = timeSlots,
            onDismiss = { editingStudent = null },
            onChanged = { loadData() }
        )
    }

    previewImage?.let { FullScreenImageDialog(pathOrUrl = it, onDismiss = { previewImage = null }) }
}

@Composable
private fun ClassAdminColumnToggle(
    selectedColumns: Int,
    onSelect: (Int) -> Unit
) {
    Surface(
        shape = RoundedCornerShape(9.dp),
        color = MaterialTheme.colorScheme.surfaceVariant
    ) {
        Row(modifier = Modifier.padding(2.dp), horizontalArrangement = Arrangement.spacedBy(2.dp)) {
            listOf(1, 2).forEach { cols ->
                val active = selectedColumns == cols
                Surface(
                    shape = RoundedCornerShape(7.dp),
                    color = if (active) MaterialTheme.colorScheme.primary else Color.Transparent,
                    modifier = Modifier.clip(RoundedCornerShape(7.dp)).clickable { onSelect(cols) }
                ) {
                    Text(
                        "${cols}列",
                        style = MaterialTheme.typography.labelSmall,
                        fontWeight = if (active) FontWeight.SemiBold else FontWeight.Normal,
                        color = if (active) Color.White else MaterialTheme.colorScheme.onSurfaceVariant,
                        modifier = Modifier.padding(horizontal = 9.dp, vertical = 5.dp)
                    )
                }
            }
        }
    }
}

@Composable
private fun ClassAdminColoredRecordCard(
    item: ClassAdminSummaryItem,
    isCompactTwoColumn: Boolean,
    modifier: Modifier = Modifier,
    onClick: () -> Unit
) {
    val accent = statusColor(item.colorKey)
    Surface(
        shape = RoundedCornerShape(12.dp),
        color = accent.copy(alpha = 0.08f),
        border = BorderStroke(1.dp, accent.copy(alpha = 0.28f)),
        modifier = modifier
            .clip(RoundedCornerShape(12.dp))
            .clickable(onClick = onClick)
    ) {
        Column(
            modifier = Modifier.padding(if (isCompactTwoColumn) 11.dp else 13.dp),
            verticalArrangement = Arrangement.spacedBy(if (isCompactTwoColumn) 6.dp else 7.dp)
        ) {
            Row(
                modifier = Modifier.fillMaxWidth(),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.spacedBy(4.dp)
            ) {
                Box(modifier = Modifier.size(if (isCompactTwoColumn) 7.dp else 8.dp).clip(CircleShape).background(accent))
                if (item.approvalPrefix.isNotEmpty()) {
                    Text(
                        item.approvalPrefix,
                        style = if (isCompactTwoColumn) MaterialTheme.typography.labelMedium else MaterialTheme.typography.bodySmall,
                        fontWeight = FontWeight.Bold,
                        color = statusColor(item.approvalStatus),
                        maxLines = 1
                    )
                }
                Text(
                    item.typeName,
                    style = if (isCompactTwoColumn) MaterialTheme.typography.labelMedium else MaterialTheme.typography.bodySmall,
                    fontWeight = FontWeight.Bold,
                    color = accent,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                    modifier = Modifier.weight(1f)
                )
                if (!isCompactTwoColumn && item.approvalPrefix.isEmpty() && !item.sourceLabel.isNullOrBlank()) {
                    Surface(shape = RoundedCornerShape(999.dp), color = accent.copy(alpha = 0.14f)) {
                        Text(
                            item.sourceLabel,
                            style = MaterialTheme.typography.labelSmall,
                            color = accent,
                            modifier = Modifier.padding(horizontal = 6.dp, vertical = 2.dp)
                        )
                    }
                }
                item.timeText?.takeIf { it.isNotBlank() }?.let { t ->
                    Text(t, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                }
            }

            Text(
                item.periodText,
                style = if (isCompactTwoColumn) MaterialTheme.typography.bodySmall else MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurface,
                maxLines = if (isCompactTwoColumn) 3 else Int.MAX_VALUE,
                overflow = TextOverflow.Ellipsis
            )

            if (isCompactTwoColumn) {
                Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Text(
                        item.studentShortLabel,
                        style = MaterialTheme.typography.bodySmall,
                        fontWeight = FontWeight.Bold,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis
                    )
                    item.note?.takeIf { it.isNotBlank() }?.let { note ->
                        Text(
                            note,
                            style = MaterialTheme.typography.labelSmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                            maxLines = 2,
                            overflow = TextOverflow.Ellipsis
                        )
                    }
                }
            } else {
                Row(
                    modifier = Modifier.fillMaxWidth(),
                    verticalAlignment = Alignment.CenterVertically,
                    horizontalArrangement = Arrangement.spacedBy(6.dp)
                ) {
                    Text(
                        item.studentShortLabel,
                        style = MaterialTheme.typography.bodySmall,
                        fontWeight = FontWeight.Bold
                    )
                    item.note?.takeIf { it.isNotBlank() }?.let { note ->
                        Text(
                            "· $note",
                            style = MaterialTheme.typography.labelMedium,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                            modifier = Modifier.weight(1f)
                        )
                    } ?: Spacer(Modifier.weight(1f))
                    Icon(
                        Icons.Rounded.ChevronRight,
                        contentDescription = null,
                        tint = MaterialTheme.colorScheme.onSurfaceVariant,
                        modifier = Modifier.size(16.dp)
                    )
                }
            }
        }
    }
}

@Composable
private fun ClassAdminDetailRow(label: String, value: String) {
    Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
        Text(label, style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.width(68.dp))
        Text(value, style = MaterialTheme.typography.bodySmall, modifier = Modifier.weight(1f))
    }
}

@OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)
@Composable
private fun ClassAdminStudentEditModal(
    student: OverviewStudent,
    dateKey: String,
    leaveTypes: List<LeaveType>,
    classPeriods: List<ClassPeriod>,
    timeSlots: List<TimeSlot>,
    onDismiss: () -> Unit,
    onChanged: () -> Unit
) {
    val repo = SmartCampusApp.container.repository
    val scope = rememberCoroutineScope()
    val sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true)

    var dayRecords by remember(student.id, dateKey) {
        mutableStateOf(student.attendance.filter { !it.isNormalPresentRecord && it.approvalStatus != "rejected" })
    }
    var selectedLeaveTypeId by remember(leaveTypes) { mutableStateOf(leaveTypes.firstOrNull()?.id) }
    var selectedPeriods by remember { mutableStateOf<Set<Int>>(emptySet()) }
    var note by remember { mutableStateOf("") }
    var saving by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    val groupedDayRecords = remember(dayRecords, classPeriods, timeSlots) {
        DayRecordGroup.build(dayRecords, classPeriods, timeSlots, groupByDate = false)
    }
    val selectedType = remember(leaveTypes, selectedLeaveTypeId) {
        leaveTypes.firstOrNull { it.id == selectedLeaveTypeId }
    }

    fun refreshDay() {
        scope.launch {
            runCatching { repo.fetchAttendanceOverview(dateKey, includeEvening = true) }.getOrNull()?.let { ov ->
                ov.flatMap { it.classes }.flatMap { it.students }
                    .firstOrNull { it.id == student.id }
                    ?.let { fresh ->
                        dayRecords = fresh.attendance.filter { !it.isNormalPresentRecord && it.approvalStatus != "rejected" }
                    }
            }
        }
    }

    ModalBottomSheet(onDismissRequest = onDismiss, sheetState = sheetState) {
        Column(
            modifier = Modifier
                .fillMaxWidth()
                .verticalScroll(rememberScrollState())
                .padding(horizontal = 16.dp, vertical = 8.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp)
        ) {
            Text("${student.name} · 考勤维护（$dateKey）", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
            error?.let { ErrorBanner(message = it) }

            Text("$dateKey 当日已有记录（${groupedDayRecords.size}）", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold)
            if (groupedDayRecords.isEmpty()) {
                Text("当日暂无异常记录，当前为正常出勤", style = MaterialTheme.typography.bodySmall, color = Color(0xFF16A34A))
            } else {
                groupedDayRecords.forEach { group ->
                    val rec = group.primary
                    SCCard {
                        Row(modifier = Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                            StatusBadge(rec.titleText, statusColor(rec.badgeColorKey))
                            rec.approvalStatus?.takeIf { it.isNotBlank() }?.let { ap ->
                                StatusBadge(when (ap) { "pending" -> "待审"; "approved" -> "已批"; else -> "驳回" }, statusColor(ap))
                            }
                            Spacer(Modifier.weight(1f))
                            IconButton(onClick = {
                                scope.launch {
                                    error = null
                                    runCatching {
                                        val deletedRollCallIds = mutableSetOf<Int>()
                                        val deletedSelfBatches = mutableSetOf<String>()
                                        group.records.forEach { item ->
                                            if (item.sourceType == "roll_call" && item.sourceId != null && !deletedRollCallIds.add(item.sourceId)) return@forEach
                                            val isSelf = item.isSelfApplied || item.sourceType == "self_applied"
                                            if (isSelf && !item.leaveBatchId.isNullOrBlank()) {
                                                val k = "${item.leaveBatchId}_${item.scene ?: "regular"}"
                                                if (!deletedSelfBatches.add(k)) return@forEach
                                            }
                                            repo.deleteAttendanceRecord(
                                                studentId = student.id,
                                                date = item.dateKey.ifBlank { dateKey },
                                                recordId = item.id,
                                                periodId = item.periodId,
                                                leaveBatchId = item.leaveBatchId,
                                                sourceType = if (isSelf) "self_applied" else item.sourceType,
                                                scene = item.scene ?: "regular",
                                                deleteScope = if (isSelf) "scene_date" else "record"
                                            )
                                        }
                                        val removed = group.recordIdSet
                                        dayRecords = dayRecords.filterNot { it.id in removed }
                                        onChanged()
                                        refreshDay()
                                    }.onFailure { error = it.localizedMessage }
                                }
                            }) {
                                Icon(Icons.Rounded.Delete, contentDescription = "撤销", tint = Color(0xFFDC2626))
                            }
                        }
                        Text("课节：${group.periodDetailText.ifBlank { "全天" }}", style = MaterialTheme.typography.bodySmall)
                        group.remark?.takeIf { it.isNotBlank() }?.let {
                            Text("备注：$it", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                        }
                    }
                }
            }

            HorizontalDivider()
            Text("补录考勤标记", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold)

            if (leaveTypes.isNotEmpty()) {
                FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    leaveTypes.forEach { lt ->
                        FilterChip(
                            selected = selectedLeaveTypeId == lt.id,
                            onClick = { selectedLeaveTypeId = lt.id },
                            label = { Text(lt.name) }
                        )
                    }
                }
            }

            if (classPeriods.isNotEmpty()) {
                Row(modifier = Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                    Text("选择节次（留空代表全天）", style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.weight(1f))
                    if (selectedPeriods.isNotEmpty()) {
                        TextButton(onClick = { selectedPeriods = emptySet() }) { Text("清空(全天)") }
                    }
                }
                FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    classPeriods.forEach { p ->
                        val on = p.id in selectedPeriods
                        FilterChip(
                            selected = on,
                            onClick = { selectedPeriods = if (on) selectedPeriods - p.id else selectedPeriods + p.id },
                            label = { Text(p.name) }
                        )
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
                enabled = !saving && selectedType != null,
                modifier = Modifier.fillMaxWidth(),
                onClick = {
                    val lt = selectedType ?: return@Button
                    scope.launch {
                        saving = true
                        error = null
                        runCatching {
                            repo.submitBulkAttendance(
                                date = dateKey,
                                studentId = student.id,
                                status = lt.mappedAttendanceStatus,
                                leaveTypeId = lt.id,
                                periodIds = selectedPeriods.sorted(),
                                note = note
                            )
                            note = ""
                            selectedPeriods = emptySet()
                            onChanged()
                            refreshDay()
                        }.onFailure { error = it.localizedMessage }
                        saving = false
                    }
                }
            ) {
                Text(if (saving) "保存中..." else "保存考勤标记")
            }
            Spacer(Modifier.height(18.dp))
        }
    }
}
