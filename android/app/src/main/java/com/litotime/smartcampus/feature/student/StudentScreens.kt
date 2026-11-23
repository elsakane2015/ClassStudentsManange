package com.litotime.smartcampus.feature.student

import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
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
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.ChevronLeft
import androidx.compose.material.icons.rounded.ChevronRight
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.Description
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
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateMapOf
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
import androidx.compose.ui.unit.dp
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
import com.litotime.smartcampus.core.util.PeriodFormatter
import com.litotime.smartcampus.core.util.SCDateFormatter
import com.litotime.smartcampus.data.model.*
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import java.io.File
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
                            Text("点击快速记录与维护本班日常考勤", color = Color.White.copy(alpha = 0.85f), style = MaterialTheme.typography.bodySmall)
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
                        val payload = buildJsonObject {
                            put("type", selectedSlug)
                            put("start_date", startDate.format(SCDateFormatter.yyyyMMdd))
                            put("end_date", endDate.format(SCDateFormatter.yyyyMMdd))
                            put("reason", reason)
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
                                (selectedType?.inputType == "text" && customDetailText.isNotBlank())
                            if (hasDetails) {
                                put("details", buildJsonObject {
                                    if (selectedOptionKey.isNotBlank()) put("option", selectedOptionKey)
                                    if (selectedType?.inputType == "time") put("time", timeInputText.trim())
                                    if (selectedType?.inputType == "text" && customDetailText.isNotBlank()) put("text", customDetailText.trim())
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
fun LeaveHistoryScreen(user: User) {
    val repo = SmartCampusApp.container.repository
    val scope = rememberCoroutineScope()
    val filters = listOf("all" to "全部", "pending" to "待审批", "approved" to "已通过", "rejected" to "已驳回")

    var statusFilter by remember { mutableStateOf("all") }
    var ownerScope by remember { mutableStateOf("all") } // "all" | "mine"
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
            TopAppBar(
                title = { Text(if (showStudentIdentity) "班级请假记录" else "请假记录") },
                actions = { IconButton(onClick = { load() }) { Icon(Icons.Rounded.Refresh, contentDescription = "刷新") } }
            )
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
                            req.reason?.takeIf { it.isNotBlank() }?.let {
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
                                Text(
                                    req.approverName?.let { "审批人：$it" } ?: "提交于 ${SCDateFormatter.displayDateTime(req.createdAt)}",
                                    style = MaterialTheme.typography.labelSmall,
                                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                                    modifier = Modifier.weight(1f)
                                )
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
                            .onSuccess { requests = requests.filterNot { it.id == target.id } }
                            .onFailure { error = it.localizedMessage }
                    }
                }) { Text("确认撤销", color = Color(0xFFDC2626)) }
            },
            dismissButton = { TextButton(onClick = { cancelTarget = null }) { Text("取消") } }
        )
    }

    previewImage?.let { FullScreenImageDialog(pathOrUrl = it, onDismiss = { previewImage = null }) }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ClassAttendanceAdminScreen(user: User, onBack: () -> Unit) {
    val repo = SmartCampusApp.container.repository
    val scope = rememberCoroutineScope()
    val statusChoices = listOf(
        Triple("present", "出勤", Color(0xFF16A34A)),
        Triple("late", "迟到", Color(0xFFEA580C)),
        Triple("absent", "旷课", Color(0xFFDC2626)),
        Triple("leave", "请假", Color(0xFF2563EB)),
        Triple("early_leave", "早退", Color(0xFF9333EA))
    )

    var selectedDate by remember { mutableStateOf(LocalDate.now()) }
    var students by remember { mutableStateOf<List<OverviewStudent>>(emptyList()) }
    val drafts = remember { mutableStateMapOf<Int, String>() }
    var loading by remember { mutableStateOf(false) }
    var saving by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var savedMsg by remember { mutableStateOf(false) }

    fun load() {
        scope.launch {
            loading = true
            error = null
            runCatching {
                val overview = repo.fetchAttendanceOverview(selectedDate.format(SCDateFormatter.yyyyMMdd))
                val list = overview.flatMap { it.classes }.flatMap { it.students }
                students = list
                drafts.clear()
                list.forEach { s -> drafts[s.id] = s.attendance.firstOrNull()?.status ?: "present" }
            }.onFailure { error = it.localizedMessage }
            loading = false
        }
    }

    LaunchedEffect(selectedDate) { load() }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("班级考勤维护") },
                navigationIcon = {
                    IconButton(onClick = onBack) { Icon(Icons.AutoMirrored.Rounded.ArrowBack, contentDescription = "返回") }
                },
                actions = {
                    TextButton(
                        enabled = !saving && students.isNotEmpty(),
                        onClick = {
                            scope.launch {
                                saving = true
                                error = null
                                runCatching {
                                    repo.submitBatchAttendance(
                                        classId = user.student?.classId,
                                        date = selectedDate.format(SCDateFormatter.yyyyMMdd),
                                        statusMap = drafts.toMap()
                                    )
                                    savedMsg = true
                                }.onFailure { error = it.localizedMessage }
                                saving = false
                            }
                        }
                    ) { Text(if (saving) "保存中" else "保存", fontWeight = FontWeight.Bold) }
                }
            )
        }
    ) { padding ->
        Column(modifier = Modifier.fillMaxSize().padding(padding).padding(horizontal = 16.dp)) {
            Row(modifier = Modifier.fillMaxWidth().padding(vertical = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                Text("日期：${selectedDate.format(SCDateFormatter.yyyyMMdd)}", modifier = Modifier.weight(1f), fontWeight = FontWeight.SemiBold)
                OutlinedButton(onClick = { students.forEach { drafts[it.id] = "present" } }) { Text("全员出勤") }
            }
            error?.let { ErrorBanner(message = it, onRetry = { load() }) }
            if (loading && students.isEmpty()) {
                Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
            } else {
                LazyColumn(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    items(students, key = { it.id }) { s ->
                        SCCard {
                            Text("${s.name}  ${s.studentNo.orEmpty()}", fontWeight = FontWeight.SemiBold)
                            Spacer(Modifier.height(8.dp))
                            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                                statusChoices.forEach { (key, label, color) ->
                                    val selected = (drafts[s.id] ?: "present") == key
                                    Surface(
                                        shape = RoundedCornerShape(8.dp),
                                        color = if (selected) color else MaterialTheme.colorScheme.surfaceVariant,
                                        modifier = Modifier.weight(1f).clickable { drafts[s.id] = key }
                                    ) {
                                        Text(
                                            label,
                                            color = if (selected) Color.White else MaterialTheme.colorScheme.onSurface,
                                            style = MaterialTheme.typography.labelSmall,
                                            textAlign = TextAlign.Center,
                                            modifier = Modifier.padding(vertical = 7.dp)
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

    if (savedMsg) {
        AlertDialog(
            onDismissRequest = { savedMsg = false },
            title = { Text("考勤已保存") },
            confirmButton = { TextButton(onClick = { savedMsg = false }) { Text("好的") } }
        )
    }
}
