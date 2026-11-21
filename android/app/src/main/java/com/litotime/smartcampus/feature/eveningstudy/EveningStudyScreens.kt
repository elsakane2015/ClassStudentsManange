package com.litotime.smartcampus.feature.eveningstudy

import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
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
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.CalendarMonth
import androidx.compose.material.icons.rounded.History
import androidx.compose.material.icons.rounded.LockOpen
import androidx.compose.material.icons.rounded.Refresh
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DatePicker
import androidx.compose.material3.DatePickerDialog
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.rememberDatePickerState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.hapticfeedback.HapticFeedbackType
import androidx.compose.ui.platform.LocalHapticFeedback
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.litotime.smartcampus.SmartCampusApp
import com.litotime.smartcampus.core.designsystem.ErrorBanner
import com.litotime.smartcampus.core.designsystem.SCCard
import com.litotime.smartcampus.core.designsystem.StatusBadge
import com.litotime.smartcampus.core.designsystem.statusColor
import com.litotime.smartcampus.core.util.SCDateFormatter
import com.litotime.smartcampus.data.model.*
import kotlinx.coroutines.launch
import java.time.Instant
import java.time.LocalDate
import java.time.ZoneId

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun EveningStudyHubContent(
    user: User,
    forceTakeView: Boolean,
    onOpenSession: (EveningStudySessionPayload, List<EveningStudyStatus>, Boolean) -> Unit
) {
    val repo = SmartCampusApp.container.repository
    val scope = rememberCoroutineScope()
    val canTake = forceTakeView && user.canTakeEveningStudy

    var selectedDate by remember { mutableStateOf(LocalDate.now()) }
    var periods by remember { mutableStateOf<List<ClassPeriod>>(emptyList()) }
    var selectedPeriodId by remember { mutableStateOf(0) }
    var statuses by remember { mutableStateOf<List<EveningStudyStatus>>(emptyList()) }
    var classes by remember { mutableStateOf<List<EveningStudyClassItem>>(emptyList()) }
    var summary by remember { mutableStateOf<EveningStudySummary?>(null) }
    var loading by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    var showDatePicker by remember { mutableStateOf(false) }
    var periodMenuOpen by remember { mutableStateOf(false) }
    var showHistoryModal by remember { mutableStateOf(false) }

    fun loadClasses() {
        if (selectedPeriodId <= 0) return
        scope.launch {
            loading = true
            error = null
            val dateStr = selectedDate.format(SCDateFormatter.yyyyMMdd)
            runCatching {
                classes = repo.fetchEveningClasses(dateStr, selectedPeriodId, forceTakeView)
                summary = runCatching { repo.fetchEveningSummary(dateStr, selectedPeriodId, forceTakeView) }.getOrNull()
            }.onFailure { error = it.localizedMessage }
            loading = false
        }
    }

    LaunchedEffect(Unit) {
        loading = true
        runCatching {
            periods = repo.fetchEveningPeriods()
            statuses = repo.fetchEveningStatuses().filter { it.isActive }
            periods.firstOrNull()?.let { selectedPeriodId = it.id }
        }.onFailure { error = it.localizedMessage }
        loading = false
        loadClasses()
    }

    LaunchedEffect(selectedDate, selectedPeriodId) { loadClasses() }

    LazyColumn(
        modifier = Modifier.fillMaxSize(),
        contentPadding = PaddingValues(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp)
    ) {
        error?.let { item { ErrorBanner(message = it, onRetry = { loadClasses() }) } }

        item {
            SCCard {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                    OutlinedButton(onClick = { showDatePicker = true }, modifier = Modifier.weight(1f)) {
                        Icon(Icons.Rounded.CalendarMonth, contentDescription = null, modifier = Modifier.size(16.dp))
                        Spacer(Modifier.width(6.dp))
                        Text(selectedDate.format(SCDateFormatter.yyyyMMdd))
                    }
                    if (periods.isNotEmpty()) {
                        Box(modifier = Modifier.weight(1f)) {
                            OutlinedButton(onClick = { periodMenuOpen = true }, modifier = Modifier.fillMaxWidth()) {
                                Text(periods.firstOrNull { it.id == selectedPeriodId }?.name ?: "选择节次")
                            }
                            DropdownMenu(expanded = periodMenuOpen, onDismissRequest = { periodMenuOpen = false }) {
                                periods.forEach { p ->
                                    DropdownMenuItem(text = { Text(p.name) }, onClick = { selectedPeriodId = p.id; periodMenuOpen = false })
                                }
                            }
                        }
                    }
                }
                Spacer(Modifier.height(8.dp))
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    OutlinedButton(onClick = { showHistoryModal = true }, modifier = Modifier.weight(1f)) {
                        Icon(Icons.Rounded.History, contentDescription = null, modifier = Modifier.size(16.dp))
                        Spacer(Modifier.width(6.dp))
                        Text("历史记录")
                    }
                    OutlinedButton(onClick = { loadClasses() }) {
                        Icon(Icons.Rounded.Refresh, contentDescription = "刷新", modifier = Modifier.size(18.dp))
                    }
                }
            }
        }

        summary?.let { s ->
            item {
                SCCard {
                    Text("当日总览", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold)
                    Spacer(Modifier.height(10.dp))
                    Row(modifier = Modifier.fillMaxWidth()) {
                        EveningMiniMetric("已点班", "${s.startedClassCount}/${s.classCount}", MaterialTheme.colorScheme.primary, Modifier.weight(1f))
                        EveningMiniMetric("已完成", "${s.completedClassCount}", Color(0xFF16A34A), Modifier.weight(1f))
                        EveningMiniMetric("应到住宿", "${s.expectedCount}", Color(0xFF2563EB), Modifier.weight(1f))
                        EveningMiniMetric("实到人数", "${s.presentCount}", Color(0xFF0891B2), Modifier.weight(1f))
                    }
                    if (s.statusCounts.isNotEmpty()) {
                        Spacer(Modifier.height(10.dp))
                        Row(
                            modifier = Modifier.horizontalScroll(rememberScrollState()),
                            horizontalArrangement = Arrangement.spacedBy(6.dp)
                        ) {
                            s.statusCounts.forEach { st ->
                                StatusBadge("${st.name} ${st.count}", statusColor(st.color))
                            }
                        }
                    }
                }
            }
        }

        item { Text("住宿班级（${classes.size}）", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold) }

        if (loading && classes.isEmpty()) {
            item { Box(Modifier.fillMaxWidth().padding(24.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator() } }
        } else if (classes.isEmpty()) {
            item { Text("暂无可查看的住宿班级", color = MaterialTheme.colorScheme.onSurfaceVariant) }
        } else {
            items(classes, key = { it.id }) { cls ->
                val enabled = cls.session != null || canTake
                SCCard(onClick = if (enabled) {
                    {
                        scope.launch {
                            error = null
                            runCatching {
                                val payload = if (cls.session != null) {
                                    repo.fetchEveningSession(cls.session.id)
                                } else {
                                    repo.startEveningSession(selectedDate.format(SCDateFormatter.yyyyMMdd), selectedPeriodId, cls.id)
                                }
                                onOpenSession(payload, statuses, canTake)
                            }.onFailure { error = it.localizedMessage }
                        }
                    }
                } else null) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Column(modifier = Modifier.weight(1f)) {
                            Text(cls.name, style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold)
                            Text(
                                (cls.departmentName?.let { "$it · " }.orEmpty()) + "住宿生 ${cls.boardingCount} 人",
                                style = MaterialTheme.typography.bodySmall,
                                color = MaterialTheme.colorScheme.onSurfaceVariant
                            )
                        }
                        val session = cls.session
                        if (session != null) {
                            StatusBadge(if (session.isCompleted) "已完成" else "点名中", if (session.isCompleted) Color(0xFF16A34A) else Color(0xFFEA580C))
                        } else {
                            StatusBadge(if (canTake) "开始点名" else "未点名", if (canTake) MaterialTheme.colorScheme.primary else Color(0xFF64748B))
                        }
                    }
                    cls.session?.statusCounts?.takeIf { it.isNotEmpty() }?.let { counts ->
                        Spacer(Modifier.height(8.dp))
                        Row(modifier = Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                            counts.forEach { st -> StatusBadge("${st.name} ${st.count}", statusColor(st.color)) }
                        }
                    }
                }
            }
        }
    }

    if (showDatePicker) {
        val state = rememberDatePickerState(
            initialSelectedDateMillis = selectedDate.atStartOfDay(ZoneId.of("UTC")).toInstant().toEpochMilli()
        )
        DatePickerDialog(
            onDismissRequest = { showDatePicker = false },
            confirmButton = {
                TextButton(onClick = {
                    state.selectedDateMillis?.let {
                        selectedDate = Instant.ofEpochMilli(it).atZone(ZoneId.of("UTC")).toLocalDate()
                    }
                    showDatePicker = false
                }) { Text("确定") }
            },
            dismissButton = { TextButton(onClick = { showDatePicker = false }) { Text("取消") } }
        ) { DatePicker(state = state) }
    }

    if (showHistoryModal) {
        EveningStudyHistoryModal(
            isTakeView = forceTakeView,
            statuses = statuses,
            canModify = canTake,
            onDismiss = { showHistoryModal = false },
            onSelectSession = { payload ->
                showHistoryModal = false
                onOpenSession(payload, statuses, canTake)
            }
        )
    }
}

@Composable
private fun EveningMiniMetric(label: String, value: String, color: Color, modifier: Modifier = Modifier) {
    Column(modifier = modifier, horizontalAlignment = Alignment.CenterHorizontally) {
        Text(value, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold, color = color)
        Text(label, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun EveningStudyHistoryModal(
    isTakeView: Boolean,
    statuses: List<EveningStudyStatus>,
    canModify: Boolean,
    onDismiss: () -> Unit,
    onSelectSession: (EveningStudySessionPayload) -> Unit
) {
    val repo = SmartCampusApp.container.repository
    val scope = rememberCoroutineScope()
    var history by remember { mutableStateOf<List<EveningStudySession>>(emptyList()) }
    var loading by remember { mutableStateOf(true) }

    LaunchedEffect(Unit) {
        history = runCatching { repo.fetchEveningHistory(isTakeView) }.getOrDefault(emptyList())
        loading = false
    }

    ModalBottomSheet(onDismissRequest = onDismiss) {
        Column(modifier = Modifier.fillMaxWidth().padding(16.dp)) {
            Text("夜自习历史记录", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
            Spacer(Modifier.height(12.dp))
            if (loading) {
                Box(Modifier.fillMaxWidth().padding(24.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
            } else if (history.isEmpty()) {
                Text("暂无历史记录", color = MaterialTheme.colorScheme.onSurfaceVariant)
            } else {
                LazyColumn(modifier = Modifier.height(380.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    items(history, key = { it.id }) { s ->
                        SCCard(onClick = {
                            scope.launch {
                                runCatching { repo.fetchEveningSession(s.id) }.onSuccess(onSelectSession)
                            }
                        }) {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Text("${s.className ?: "班级 #${s.classId}"} · ${s.periodName.orEmpty()}", fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
                                StatusBadge(if (s.isCompleted) "已完成" else "点名中", if (s.isCompleted) Color(0xFF16A34A) else Color(0xFFEA580C))
                            }
                            Text(
                                "${s.dateDisplay} · 正常 ${s.normalCount} / 异常 ${s.exceptionCount} / 总 ${s.totalStudents}",
                                style = MaterialTheme.typography.labelSmall,
                                color = MaterialTheme.colorScheme.onSurfaceVariant
                            )
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
fun EveningStudyTakeScreen(
    initialPayload: EveningStudySessionPayload,
    statuses: List<EveningStudyStatus>,
    canModify: Boolean,
    onBack: () -> Unit
) {
    val repo = SmartCampusApp.container.repository
    val haptic = LocalHapticFeedback.current
    val scope = rememberCoroutineScope()

    var session by remember { mutableStateOf(initialPayload.session) }
    var records by remember { mutableStateOf(initialPayload.records) }
    var notes by remember { mutableStateOf(initialPayload.session.notes.orEmpty()) }
    var searchText by remember { mutableStateOf("") }
    var saving by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var banner by remember { mutableStateOf<String?>(null) }

    val editable = canModify && !session.isCompleted
    val fallbackStatusId = statuses.firstOrNull { it.isDefault }?.id ?: statuses.firstOrNull()?.id ?: 0

    val filtered = remember(records, searchText) {
        val q = searchText.trim().lowercase()
        if (q.isEmpty()) records
        else records.filter { it.studentName.lowercase().contains(q) || (it.studentNo ?: "").lowercase().contains(q) }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text(session.className ?: "夜自习巡查") },
                navigationIcon = { IconButton(onClick = onBack) { Icon(Icons.AutoMirrored.Rounded.ArrowBack, contentDescription = "返回") } }
            )
        },
        bottomBar = {
            if (canModify) {
                Surface(tonalElevation = 3.dp, shadowElevation = 6.dp) {
                    Row(
                        modifier = Modifier.fillMaxWidth().padding(14.dp),
                        horizontalArrangement = Arrangement.spacedBy(10.dp)
                    ) {
                        if (session.isCompleted) {
                            OutlinedButton(
                                enabled = !saving,
                                modifier = Modifier.fillMaxWidth().height(46.dp),
                                onClick = {
                                    scope.launch {
                                        saving = true; error = null; banner = null
                                        runCatching {
                                            val p = repo.reopenEveningSession(session.id)
                                            session = p.session; records = p.records; banner = "已重新打开，可继续修改"
                                        }.onFailure { error = it.localizedMessage }
                                        saving = false
                                    }
                                }
                            ) {
                                Icon(Icons.Rounded.LockOpen, contentDescription = null)
                                Spacer(Modifier.width(6.dp))
                                Text("重新打开以修改")
                            }
                        } else {
                            OutlinedButton(
                                enabled = !saving,
                                modifier = Modifier.weight(1f).height(46.dp),
                                onClick = {
                                    scope.launch {
                                        saving = true; error = null; banner = null
                                        runCatching {
                                            val p = repo.updateEveningRecords(session.id, records, fallbackStatusId)
                                            session = p.session; records = p.records; banner = "已暂存最新点名记录"
                                        }.onFailure { error = it.localizedMessage }
                                        saving = false
                                    }
                                }
                            ) { Text("暂存") }

                            Button(
                                enabled = !saving,
                                modifier = Modifier.weight(2f).height(46.dp),
                                onClick = {
                                    scope.launch {
                                        saving = true; error = null; banner = null
                                        runCatching {
                                            val p = repo.completeEveningSession(session.id, notes, records, fallbackStatusId)
                                            session = p.session; records = p.records; banner = "夜自习点名已完成"
                                        }.onFailure { error = it.localizedMessage }
                                        saving = false
                                    }
                                }
                            ) { Text(if (saving) "提交中..." else "完成并提交", fontWeight = FontWeight.SemiBold) }
                        }
                    }
                }
            }
        }
    ) { padding ->
        Column(modifier = Modifier.fillMaxSize().padding(padding)) {
            Surface(color = MaterialTheme.colorScheme.surface) {
                Column(modifier = Modifier.padding(horizontal = 16.dp, vertical = 10.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text(
                            "${session.dateDisplay} · ${session.periodName.orEmpty()} · 共 ${records.size} 人",
                            style = MaterialTheme.typography.bodySmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                            modifier = Modifier.weight(1f)
                        )
                        StatusBadge(if (session.isCompleted) "已完成" else "点名中", if (session.isCompleted) Color(0xFF16A34A) else Color(0xFFEA580C))
                    }
                    OutlinedTextField(
                        value = searchText,
                        onValueChange = { searchText = it },
                        placeholder = { Text("搜索姓名或学号") },
                        leadingIcon = { Icon(Icons.Rounded.Search, contentDescription = null) },
                        singleLine = true,
                        modifier = Modifier.fillMaxWidth()
                    )
                    banner?.let { Text(it, color = Color(0xFF16A34A), style = MaterialTheme.typography.labelMedium) }
                    error?.let { ErrorBanner(message = it) }
                }
            }

            LazyColumn(
                contentPadding = PaddingValues(16.dp),
                verticalArrangement = Arrangement.spacedBy(10.dp),
                modifier = Modifier.fillMaxSize()
            ) {
                itemsIndexed(filtered, key = { _, it -> it.id }) { _, rec ->
                    SCCard {
                        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                            Text(rec.studentName, fontWeight = FontWeight.SemiBold)
                            rec.studentNo?.let { Text(it, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant) }
                            Spacer(Modifier.weight(1f))
                            if (rec.isSuspended) {
                                StatusBadge("暂停住宿", Color(0xFF9333EA))
                            } else if (rec.isSelfApplied) {
                                StatusBadge(
                                    if (rec.approvalStatus == "approved") "请假已批" else "请假待批",
                                    if (rec.approvalStatus == "approved") Color(0xFF2563EB) else Color(0xFFEA580C)
                                )
                            }
                        }
                        rec.destination?.takeIf { it.isNotBlank() }?.let {
                            Spacer(Modifier.height(4.dp))
                            Text("去向：$it", style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.primary)
                        }
                        Spacer(Modifier.height(8.dp))
                        Row(
                            modifier = Modifier.horizontalScroll(rememberScrollState()),
                            horizontalArrangement = Arrangement.spacedBy(8.dp)
                        ) {
                            statuses.forEach { st ->
                                val selected = rec.eveningStudyStatusId == st.id
                                val tint = statusColor(st.color)
                                Surface(
                                    shape = RoundedCornerShape(999.dp),
                                    color = if (selected) tint else tint.copy(alpha = 0.12f),
                                    modifier = Modifier.clickable(enabled = editable && !rec.isSuspended) {
                                        records = records.map { item ->
                                            if (item.id == rec.id) item.copy(eveningStudyStatusId = st.id) else item
                                        }
                                        haptic.performHapticFeedback(HapticFeedbackType.TextHandleMove)
                                    }
                                ) {
                                    Text(
                                        st.name,
                                        color = if (selected) Color.White else tint,
                                        style = MaterialTheme.typography.labelMedium,
                                        fontWeight = FontWeight.SemiBold,
                                        modifier = Modifier.padding(horizontal = 12.dp, vertical = 7.dp)
                                    )
                                }
                            }
                        }
                        if (editable && !rec.isSuspended) {
                            Spacer(Modifier.height(8.dp))
                            OutlinedTextField(
                                value = rec.note.orEmpty(),
                                onValueChange = { txt ->
                                    records = records.map { item -> if (item.id == rec.id) item.copy(note = txt) else item }
                                },
                                placeholder = { Text("备注 / 去向说明（可选）") },
                                singleLine = true,
                                modifier = Modifier.fillMaxWidth()
                            )
                        }
                    }
                }

                if (editable) {
                    item {
                        SCCard {
                            OutlinedTextField(
                                value = notes,
                                onValueChange = { notes = it },
                                label = { Text("本次班级巡查备注（可选）") },
                                minLines = 2,
                                modifier = Modifier.fillMaxWidth()
                            )
                        }
                    }
                }
            }
        }
    }
}
