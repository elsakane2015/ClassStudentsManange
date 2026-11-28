package com.litotime.smartcampus.feature.rollcall

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.AddCircle
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.CloudDone
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.GridView
import androidx.compose.material.icons.rounded.ManageAccounts
import androidx.compose.material.icons.rounded.Refresh
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
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
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.hapticfeedback.HapticFeedbackType
import androidx.compose.ui.platform.LocalHapticFeedback
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.litotime.smartcampus.SmartCampusApp
import com.litotime.smartcampus.core.database.RollCallDraftEntity
import com.litotime.smartcampus.core.designsystem.ErrorBanner
import com.litotime.smartcampus.core.designsystem.SCCard
import com.litotime.smartcampus.core.designsystem.StatusBadge
import com.litotime.smartcampus.core.designsystem.statusColor
import com.litotime.smartcampus.core.util.SCDateFormatter
import com.litotime.smartcampus.data.model.ClassOptionItem
import com.litotime.smartcampus.data.model.LeaveType
import com.litotime.smartcampus.data.model.RollCall
import com.litotime.smartcampus.data.model.RollCallAdminItem
import com.litotime.smartcampus.data.model.RollCallRecord
import com.litotime.smartcampus.data.model.RollCallType
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.Category
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.PlayArrow
import androidx.compose.material3.Checkbox
import androidx.compose.material3.FilterChip
import androidx.compose.material3.Switch
import com.litotime.smartcampus.data.model.ClassPeriod
import com.litotime.smartcampus.data.model.RollCallPeriodNamingHelper
import com.litotime.smartcampus.data.model.User
import kotlinx.coroutines.launch

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun RollCallHubContent(
    user: User,
    onOpenRollCall: (Int) -> Unit,
    onOpenTypesManage: () -> Unit,
    onOpenAdmins: () -> Unit
) {
    val repo = SmartCampusApp.container.repository
    val scope = rememberCoroutineScope()
    val scopes = listOf("today" to "今日", "week" to "本周", "month" to "本月", "semester" to "本学期")

    var types by remember { mutableStateOf<List<RollCallType>>(emptyList()) }
    var periods by remember { mutableStateOf<List<ClassPeriod>>(emptyList()) }
    var inProgress by remember { mutableStateOf<List<RollCall>>(emptyList()) }
    var history by remember { mutableStateOf<List<RollCall>>(emptyList()) }
    var historyScope by remember { mutableStateOf("month") }
    var loading by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var showStartDialog by remember { mutableStateOf(false) }

    fun loadAll() {
        scope.launch {
            loading = true
            error = null
            runCatching {
                types = repo.fetchRollCallTypes().filter { it.isActive }
                periods = runCatching { repo.fetchClassPeriods().filter { !it.isEveningStudy && it.isActive } }.getOrDefault(emptyList())
                inProgress = runCatching { repo.fetchInProgressRollCalls() }.getOrDefault(emptyList())
                history = runCatching { repo.fetchRollCallHistory(historyScope) }.getOrDefault(emptyList())
            }.onFailure { error = it.localizedMessage }
            loading = false
        }
    }

    LaunchedEffect(Unit) { loadAll() }
    LaunchedEffect(historyScope) {
        history = runCatching { repo.fetchRollCallHistory(historyScope) }.getOrDefault(emptyList())
    }

    LazyColumn(
        modifier = Modifier.fillMaxSize(),
        contentPadding = PaddingValues(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp)
    ) {
        error?.let {
            item { ErrorBanner(message = it, onRetry = { loadAll() }) }
        }

        item {
            Surface(
                shape = RoundedCornerShape(16.dp),
                color = MaterialTheme.colorScheme.primary,
                modifier = Modifier.fillMaxWidth().clickable(enabled = user.isTeacherOrAdmin || types.isNotEmpty()) { showStartDialog = true }
            ) {
                Row(
                    modifier = Modifier.padding(16.dp),
                    verticalAlignment = Alignment.CenterVertically,
                    horizontalArrangement = Arrangement.spacedBy(12.dp)
                ) {
                    Icon(Icons.Rounded.AddCircle, contentDescription = null, tint = Color.White, modifier = Modifier.size(30.dp))
                    Column(modifier = Modifier.weight(1f)) {
                        Text("发起新点名", color = Color.White, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                        Text(
                            if (user.isTeacherOrAdmin) "支持选择已有类型或按第1、2…节新建课程点名"
                            else if (types.isEmpty()) "请先由班主任配置点名类型"
                            else "选择点名类型立即开始走动点名（支持离线防丢）",
                            color = Color.White.copy(alpha = 0.85f),
                            style = MaterialTheme.typography.bodySmall
                        )
                    }
                    IconButton(onClick = { loadAll() }) {
                        Icon(Icons.Rounded.Refresh, contentDescription = "刷新", tint = Color.White)
                    }
                }
            }
        }

        if (user.isTeacherOrAdmin) {
            item {
                Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    OutlinedButton(onClick = onOpenTypesManage, modifier = Modifier.weight(1f)) {
                        Icon(Icons.Rounded.Category, contentDescription = null, modifier = Modifier.size(18.dp))
                        Spacer(Modifier.width(6.dp))
                        Text("管理课程点名", maxLines = 1, overflow = TextOverflow.Ellipsis)
                    }
                    OutlinedButton(onClick = onOpenAdmins, modifier = Modifier.weight(1f)) {
                        Icon(Icons.Rounded.ManageAccounts, contentDescription = null, modifier = Modifier.size(18.dp))
                        Spacer(Modifier.width(6.dp))
                        Text("班级点名员", maxLines = 1, overflow = TextOverflow.Ellipsis)
                    }
                }
            }
        }

        if (inProgress.isNotEmpty()) {
            item { Text("进行中的点名（${inProgress.size}）", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold) }
            items(inProgress, key = { "in_${it.id}" }) { rc ->
                RollCallSummaryCard(
                    rc = rc,
                    onClick = { onOpenRollCall(rc.id) },
                    secondaryLabel = "取消",
                    onSecondary = {
                        scope.launch {
                            runCatching { repo.cancelRollCall(rc.id) }
                            loadAll()
                        }
                    }
                )
            }
        }

        item {
            Spacer(Modifier.height(4.dp))
            Text("点名历史", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold)
            Spacer(Modifier.height(6.dp))
            SingleChoiceSegmentedButtonRow(modifier = Modifier.fillMaxWidth()) {
                scopes.forEachIndexed { idx, (key, label) ->
                    SegmentedButton(
                        selected = historyScope == key,
                        onClick = { historyScope = key },
                        shape = SegmentedButtonDefaults.itemShape(idx, scopes.size)
                    ) { Text(label) }
                }
            }
        }

        if (loading && history.isEmpty()) {
            item { Box(Modifier.fillMaxWidth().padding(24.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator() } }
        } else if (history.isEmpty()) {
            item { Text("该周期内暂无点名记录", color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.padding(16.dp)) }
        } else {
            items(history, key = { "hist_${it.id}" }) { rc ->
                RollCallSummaryCard(
                    rc = rc,
                    onClick = { onOpenRollCall(rc.id) },
                    secondaryLabel = if (rc.status == "cancelled") "恢复" else if (user.isTeacherOrAdmin) "删除" else null,
                    onSecondary = {
                        scope.launch {
                            if (rc.status == "cancelled") runCatching { repo.restoreRollCall(rc.id) }
                            else runCatching { repo.deleteRollCall(rc.id) }
                            loadAll()
                        }
                    }
                )
            }
        }
    }

    if (showStartDialog) {
        StartRollCallDialog(
            user = user,
            types = types,
            initialPeriods = periods,
            onDismiss = { showStartDialog = false },
            onCreated = { createdId ->
                showStartDialog = false
                loadAll()
                onOpenRollCall(createdId)
            }
        )
    }
}

@Composable
private fun RollCallSummaryCard(
    rc: RollCall,
    onClick: () -> Unit,
    secondaryLabel: String?,
    onSecondary: () -> Unit
) {
    SCCard(onClick = onClick) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(
                rc.typeName + (rc.className?.let { " · $it" }.orEmpty()),
                style = MaterialTheme.typography.titleSmall,
                fontWeight = FontWeight.SemiBold,
                modifier = Modifier.weight(1f)
            )
            StatusBadge(rc.statusDisplayName, statusColor(rc.status))
        }
        Spacer(Modifier.height(6.dp))
        Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            Text("总 ${rc.totalStudents}", style = MaterialTheme.typography.labelMedium)
            Text("到 ${rc.presentCount}", style = MaterialTheme.typography.labelMedium, color = Color(0xFF16A34A))
            Text("假 ${rc.onLeaveCount}", style = MaterialTheme.typography.labelMedium, color = Color(0xFF2563EB))
            Text("缺 ${rc.absentCount}", style = MaterialTheme.typography.labelMedium, color = if (rc.absentCount > 0) Color(0xFFDC2626) else MaterialTheme.colorScheme.onSurfaceVariant)
        }
        Spacer(Modifier.height(4.dp))
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(
                rc.rollCallTime.orEmpty(),
                style = MaterialTheme.typography.labelSmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = Modifier.weight(1f)
            )
            if (secondaryLabel != null) {
                TextButton(onClick = onSecondary) { Text(secondaryLabel) }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)
@Composable
private fun StartRollCallDialog(
    user: User,
    types: List<RollCallType>,
    initialPeriods: List<ClassPeriod>,
    onDismiss: () -> Unit,
    onCreated: (Int) -> Unit
) {
    val repo = SmartCampusApp.container.repository
    val scope = rememberCoroutineScope()
    var mode by remember { mutableStateOf(if (types.isEmpty() && user.isTeacherOrAdmin) 1 else 0) } // 0 = 已有类型, 1 = 新建课程点名
    var selectedTypeId by remember { mutableStateOf(types.firstOrNull()?.id ?: 0) }
    var notes by remember { mutableStateOf("") }
    var menuOpen by remember { mutableStateOf(false) }

    // 新建课程点名状态
    var classes by remember { mutableStateOf<List<ClassOptionItem>>(emptyList()) }
    var selectedClassId by remember { mutableStateOf(0) }
    var classMenuOpen by remember { mutableStateOf(false) }
    var periods by remember { mutableStateOf(initialPeriods) }
    var selectedPeriodIds by remember { mutableStateOf<Set<Int>>(emptySet()) }
    var courseName by remember { mutableStateOf("") }
    var lastAutoName by remember { mutableStateOf("") }
    var absentStatus by remember { mutableStateOf("absent") }
    var absentMenuOpen by remember { mutableStateOf(false) }

    var creating by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(user.isTeacherOrAdmin) {
        if (user.isTeacherOrAdmin) {
            classes = runCatching { repo.fetchClasses() }.getOrDefault(emptyList())
            if (selectedClassId == 0) selectedClassId = classes.firstOrNull()?.id ?: 0
            if (periods.isEmpty()) {
                periods = runCatching { repo.fetchClassPeriods().filter { !it.isEveningStudy && it.isActive } }.getOrDefault(emptyList())
            }
        }
    }

    val selectedType = types.firstOrNull { it.id == selectedTypeId }
    val absentOptions = listOf("absent" to "旷课", "late" to "迟到", "leave" to "请假")
    // 方案 B：当前班级已有固定活动（早读/早操/晚操）置灰不可选，正课第1~N节每次重新自由选择
    val occupiedFixedIds = remember(types, selectedClassId, periods) {
        RollCallPeriodNamingHelper.occupiedFixedPeriodIds(types, selectedClassId, null, periods)
    }
    LaunchedEffect(occupiedFixedIds) {
        val cleaned = selectedPeriodIds - occupiedFixedIds
        if (cleaned != selectedPeriodIds) {
            selectedPeriodIds = cleaned
            val trimmed = courseName.trim()
            if (trimmed.isEmpty() || trimmed == lastAutoName) {
                val suggested = RollCallPeriodNamingHelper.suggestedName(cleaned, periods)
                courseName = suggested
                lastAutoName = suggested
            }
        }
    }
    val canSubmit = !creating && if (mode == 0) selectedTypeId > 0 else (selectedClassId > 0 && courseName.isNotBlank())

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("发起新点名") },
        text = {
            Column(
                modifier = Modifier.verticalScroll(rememberScrollState()),
                verticalArrangement = Arrangement.spacedBy(10.dp)
            ) {
                error?.let { Text(it, color = Color(0xFFDC2626), style = MaterialTheme.typography.bodySmall) }

                if (user.isTeacherOrAdmin) {
                    SingleChoiceSegmentedButtonRow(modifier = Modifier.fillMaxWidth()) {
                        listOf("已有类型", "本次课程点名").forEachIndexed { idx, label ->
                            SegmentedButton(
                                selected = mode == idx,
                                onClick = { mode = idx },
                                shape = SegmentedButtonDefaults.itemShape(idx, 2)
                            ) { Text(label, maxLines = 1, overflow = TextOverflow.Ellipsis) }
                        }
                    }
                }

                if (mode == 0) {
                    if (types.isEmpty()) {
                        Text("暂无已有活动类型，请切换到「本次课程点名」发起。", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    } else {
                        Box {
                            OutlinedButton(onClick = { menuOpen = true }, modifier = Modifier.fillMaxWidth()) {
                                Text(selectedType?.let { "${it.name}${it.className?.let { c -> "（$c）" }.orEmpty()}" } ?: "选择点名类型")
                            }
                            DropdownMenu(expanded = menuOpen, onDismissRequest = { menuOpen = false }) {
                                types.forEach { t ->
                                    DropdownMenuItem(
                                        text = { Text("${t.name}${t.className?.let { c -> "（$c）" }.orEmpty()}") },
                                        onClick = { selectedTypeId = t.id; menuOpen = false }
                                    )
                                }
                            }
                        }
                        selectedType?.periodSummary(periods)?.let { summary ->
                            Text("关联节次：$summary", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.primary)
                        }
                    }
                } else {
                    Text("选择本次课程节次（可多选，用于考勤统计）", style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.SemiBold)
                    if (periods.isEmpty()) {
                        Text("暂未读取到节次配置，可直接输入课程名称。", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    } else {
                        FlowRow(
                            horizontalArrangement = Arrangement.spacedBy(6.dp),
                            verticalArrangement = Arrangement.spacedBy(4.dp)
                        ) {
                            periods.forEach { p ->
                                val isDisabled = p.id in occupiedFixedIds
                                val chosen = !isDisabled && p.id in selectedPeriodIds
                                FilterChip(
                                    selected = chosen,
                                    enabled = !isDisabled,
                                    onClick = {
                                        if (isDisabled) return@FilterChip
                                        val next = if (chosen) selectedPeriodIds - p.id else selectedPeriodIds + p.id
                                        selectedPeriodIds = next
                                        val trimmed = courseName.trim()
                                        if (trimmed.isEmpty() || trimmed == lastAutoName) {
                                            val suggested = RollCallPeriodNamingHelper.suggestedName(next, periods)
                                            courseName = suggested
                                            lastAutoName = suggested
                                        }
                                    },
                                    label = {
                                        Text(
                                            if (isDisabled) "${p.name} · 已有" else p.name,
                                            style = MaterialTheme.typography.labelMedium
                                        )
                                    }
                                )
                            }
                        }
                        Text(
                            "已选 ${selectedPeriodIds.size} 个节次。早读/早操/晚操等本班已有固定活动不可重复选；本次选择仅用于当前点名，不会加入常用类型，明天或下次可重新自由选择。",
                            style = MaterialTheme.typography.labelSmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant
                        )
                    }

                    OutlinedTextField(
                        value = courseName,
                        onValueChange = { courseName = it },
                        label = { Text("活动名称（如：第1-2节点名）") },
                        singleLine = true,
                        modifier = Modifier.fillMaxWidth()
                    )

                    if (classes.size > 1) {
                        Box {
                            OutlinedButton(onClick = { classMenuOpen = true }, modifier = Modifier.fillMaxWidth()) {
                                Text("班级：" + (classes.firstOrNull { it.id == selectedClassId }?.name ?: "选择班级"))
                            }
                            DropdownMenu(expanded = classMenuOpen, onDismissRequest = { classMenuOpen = false }) {
                                classes.forEach { cls ->
                                    DropdownMenuItem(text = { Text(cls.name) }, onClick = { selectedClassId = cls.id; classMenuOpen = false })
                                }
                            }
                        }
                    } else if (classes.size == 1) {
                        Text("所属班级：${classes[0].name}", style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }

                    Box {
                        OutlinedButton(onClick = { absentMenuOpen = true }, modifier = Modifier.fillMaxWidth()) {
                            Text("未到标记：" + (absentOptions.firstOrNull { it.first == absentStatus }?.second ?: "旷课"))
                        }
                        DropdownMenu(expanded = absentMenuOpen, onDismissRequest = { absentMenuOpen = false }) {
                            absentOptions.forEach { (k, label) ->
                                DropdownMenuItem(text = { Text(label) }, onClick = { absentStatus = k; absentMenuOpen = false })
                            }
                        }
                    }
                }

                OutlinedTextField(
                    value = notes,
                    onValueChange = { notes = it },
                    label = { Text("备注（可选）") },
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth()
                )
            }
        },
        confirmButton = {
            TextButton(
                enabled = canSubmit,
                onClick = {
                    scope.launch {
                        creating = true
                        error = null
                        runCatching {
                            val targetTypeId = if (mode == 0) {
                                selectedTypeId
                            } else {
                                val cleanName = courseName.trim()
                                val orderedPeriods = periods.filter { it.id in selectedPeriodIds }.map { it.id }
                                // 本次课程点名：使用 isActive = false 的单次事件类型，不污染「已有类型」列表
                                repo.createRollCallType(
                                    classId = selectedClassId,
                                    name = cleanName,
                                    absentStatus = absentStatus,
                                    periodIds = orderedPeriods,
                                    isActive = false
                                ).id
                            }
                            val rc = repo.startRollCall(
                                typeId = targetTypeId,
                                rollCallTime = SCDateFormatter.nowRollCallDateTime(),
                                notes = notes
                            )
                            onCreated(rc.id)
                        }.onFailure { error = it.localizedMessage ?: "创建失败" }
                        creating = false
                    }
                }
            ) { Text(if (creating) "创建中..." else if (mode == 0) "立即开始" else "发起本次点名") }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text("取消") } }
    )
}

/**
 * 原生走动点名面板：1~4 列自适应网格 + 原生震动反馈 + Room 本地断网防丢草稿 + 一键全勤 + 完成后修改
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun RollCallOperationScreen(rollCallId: Int, onBack: () -> Unit) {
    val container = SmartCampusApp.container
    val repo = container.repository
    val draftDao = container.offlineDb.draftDao()
    val haptic = LocalHapticFeedback.current
    val scope = rememberCoroutineScope()

    val columnCount by container.prefs.rollCallColumnsFlow.collectAsStateWithLifecycle(initialValue = 2)
    val currentUser by container.sessionManager.currentUser.collectAsStateWithLifecycle()
    val isTeacherOrAdmin = currentUser?.isTeacherOrAdmin == true
    var rollCall by remember { mutableStateOf<RollCall?>(null) }
    var records by remember { mutableStateOf<List<RollCallRecord>>(emptyList()) }
    var searchText by remember { mutableStateOf("") }
    var statusFilter by remember { mutableStateOf<String?>(null) } // null = 全部, "present", "on_leave", "pending", "absent"
    var loading by remember { mutableStateOf(true) }
    var submitting by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var restoredDraft by remember { mutableStateOf(false) }
    var colsMenuOpen by remember { mutableStateOf(false) }
    var confirmComplete by remember { mutableStateOf(false) }
    var editingCompleted by remember { mutableStateOf<RollCallRecord?>(null) }
    var inspectingLeaveRecord by remember { mutableStateOf<RollCallRecord?>(null) }
    var leaveTypes by remember { mutableStateOf<List<LeaveType>>(emptyList()) }

    val isInProgress = rollCall?.status == "in_progress"
    val canModifyCompleted = rollCall?.canModifyRecords == true
    val selectableLeaveTypes = remember(leaveTypes) {
        leaveTypes.filter { it.isActive && it.mappedAttendanceStatus == "leave" }
    }

    fun load() {
        scope.launch {
            loading = true
            error = null
            runCatching {
                var detail = repo.fetchRollCallDetail(rollCallId)
                // 兼容未升级的旧服务端：首次打开点名若触发同步可能导致 records 缺少 student 关系，自动补拉一次
                if (detail.records.isNotEmpty() && detail.records.any { it.studentNo.isNullOrBlank() && it.studentName == "学生 #${it.studentId}" }) {
                    runCatching { repo.fetchRollCallDetail(rollCallId) }.onSuccess { detail = it }
                }
                if (leaveTypes.isEmpty()) {
                    leaveTypes = runCatching { repo.fetchLeaveTypes() }.getOrDefault(emptyList())
                }
                rollCall = detail
                var list = detail.records.sortedBy { it.studentNo.orEmpty() }
                if (detail.status == "in_progress") {
                    val draftMap = draftDao.getByRollCall(rollCallId).associateBy { it.studentId }
                    if (draftMap.isNotEmpty()) {
                        var hit = false
                        list = list.map { rec ->
                            if (rec.status == "on_leave") return@map rec
                            val draft = draftMap[rec.studentId] ?: return@map rec
                            hit = true
                            rec.copy(status = draft.status, markedAt = draft.markedAtIso, markedAtLocal = draft.markedAtLocal)
                        }
                        restoredDraft = hit
                    }
                }
                records = list
            }.onFailure { error = it.localizedMessage }
            loading = false
        }
    }

    LaunchedEffect(rollCallId) { load() }

    val presentCount = records.count { it.status == "present" }
    val onLeaveCount = records.count { it.status == "on_leave" }
    val pendingCount = records.count { it.status == "pending" }
    val absentCount = records.count { it.status == "absent" }

    val filtered = remember(records, searchText, statusFilter) {
        val q = searchText.trim().lowercase()
        records.filter { rec ->
            if (statusFilter != null && rec.status != statusFilter) return@filter false
            if (q.isEmpty()) true
            else rec.studentName.lowercase().contains(q) || (rec.studentNo ?: "").lowercase().contains(q)
        }
    }

    fun toggleStatusFilter(target: String?) {
        statusFilter = if (target == null || statusFilter == target) null else target
    }

    val activeFilterLabel = when (statusFilter) {
        "present" -> "已到"
        "on_leave" -> "请假"
        "pending" -> "未点"
        "absent" -> "缺勤"
        else -> null
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text(rollCall?.typeName ?: "课堂点名") },
                navigationIcon = {
                    IconButton(onClick = onBack) { Icon(Icons.AutoMirrored.Rounded.ArrowBack, contentDescription = "返回") }
                },
                actions = {
                    Box {
                        IconButton(onClick = { colsMenuOpen = true }) {
                            Icon(Icons.Rounded.GridView, contentDescription = "切换列数")
                        }
                        DropdownMenu(expanded = colsMenuOpen, onDismissRequest = { colsMenuOpen = false }) {
                            (1..4).forEach { cols ->
                                DropdownMenuItem(
                                    text = { Text("$cols 列视图" + if (cols == columnCount) " ✓" else "") },
                                    onClick = {
                                        colsMenuOpen = false
                                        scope.launch { container.prefs.setRollCallColumns(cols) }
                                    }
                                )
                            }
                        }
                    }
                }
            )
        },
        bottomBar = {
            if (isInProgress) {
                Surface(tonalElevation = 3.dp, shadowElevation = 6.dp) {
                    Row(
                        modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 12.dp),
                        verticalAlignment = Alignment.CenterVertically
                    ) {
                        Column(modifier = Modifier.weight(1f)) {
                            Text("已到 $presentCount / ${records.size}", fontWeight = FontWeight.Bold)
                            Text(
                                if (pendingCount > 0) "剩余 $pendingCount 人未点，提交后记缺勤" else "全员状态已确认",
                                style = MaterialTheme.typography.labelSmall,
                                color = MaterialTheme.colorScheme.onSurfaceVariant
                            )
                        }
                        Button(
                            enabled = !submitting,
                            onClick = { confirmComplete = true },
                            shape = RoundedCornerShape(12.dp)
                        ) {
                            Text(if (submitting) "提交中..." else "完成并提交", fontWeight = FontWeight.SemiBold)
                        }
                    }
                }
            }
        }
    ) { padding ->
        Column(modifier = Modifier.fillMaxSize().padding(padding)) {
            // Top Summary & Search
            Surface(color = MaterialTheme.colorScheme.surface) {
                Column(modifier = Modifier.padding(horizontal = 16.dp, vertical = 10.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        RollCallMetricPill(
                            title = "总人数",
                            value = records.size,
                            color = MaterialTheme.colorScheme.onSurface,
                            highlightColor = MaterialTheme.colorScheme.primary,
                            selected = statusFilter == null,
                            onClick = { toggleStatusFilter(null) },
                            modifier = Modifier.weight(1f)
                        )
                        RollCallMetricPill(
                            title = "已到",
                            value = presentCount,
                            color = Color(0xFF16A34A),
                            highlightColor = Color(0xFF16A34A),
                            selected = statusFilter == "present",
                            onClick = { toggleStatusFilter("present") },
                            modifier = Modifier.weight(1f)
                        )
                        RollCallMetricPill(
                            title = "请假",
                            value = onLeaveCount,
                            color = Color(0xFF2563EB),
                            highlightColor = Color(0xFF2563EB),
                            selected = statusFilter == "on_leave",
                            onClick = { toggleStatusFilter("on_leave") },
                            modifier = Modifier.weight(1f)
                        )
                        if (isInProgress) {
                            RollCallMetricPill(
                                title = "未点",
                                value = pendingCount,
                                color = Color(0xFFEA580C),
                                highlightColor = Color(0xFFEA580C),
                                selected = statusFilter == "pending",
                                onClick = { toggleStatusFilter("pending") },
                                modifier = Modifier.weight(1f)
                            )
                        } else {
                            RollCallMetricPill(
                                title = "缺勤",
                                value = absentCount,
                                color = Color(0xFFDC2626),
                                highlightColor = Color(0xFFDC2626),
                                selected = statusFilter == "absent",
                                onClick = { toggleStatusFilter("absent") },
                                modifier = Modifier.weight(1f)
                            )
                        }
                    }

                    if (restoredDraft && isInProgress) {
                        Surface(
                            shape = RoundedCornerShape(8.dp),
                            color = MaterialTheme.colorScheme.primaryContainer
                        ) {
                            Row(
                                modifier = Modifier.fillMaxWidth().padding(horizontal = 10.dp, vertical = 6.dp),
                                verticalAlignment = Alignment.CenterVertically,
                                horizontalArrangement = Arrangement.spacedBy(6.dp)
                            ) {
                                Icon(Icons.Rounded.CloudDone, contentDescription = null, tint = MaterialTheme.colorScheme.primary, modifier = Modifier.size(16.dp))
                                Text("已自动恢复本地离线暂存的点名进度", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.primary)
                            }
                        }
                    }

                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        OutlinedTextField(
                            value = searchText,
                            onValueChange = { searchText = it },
                            placeholder = { Text("搜索姓名或学号") },
                            leadingIcon = { Icon(Icons.Rounded.Search, contentDescription = null) },
                            singleLine = true,
                            modifier = Modifier.weight(1f)
                        )
                        if (isInProgress) {
                            Button(
                                onClick = {
                                    val iso = SCDateFormatter.nowIso()
                                    val local = SCDateFormatter.nowHhmm()
                                    val updated = records.map { r ->
                                        if (r.status == "pending") r.copy(status = "present", markedAt = iso, markedAtLocal = local) else r
                                    }
                                    records = updated
                                    haptic.performHapticFeedback(HapticFeedbackType.LongPress)
                                    scope.launch {
                                        draftDao.upsertAll(
                                            updated.filter { it.status == "present" }.map {
                                                RollCallDraftEntity("${rollCallId}_${it.studentId}", rollCallId, it.studentId, "present", iso, local)
                                            }
                                        )
                                    }
                                },
                                colors = ButtonDefaults.buttonColors(containerColor = Color(0xFF16A34A)),
                                shape = RoundedCornerShape(10.dp)
                            ) { Text("全勤") }
                        }
                    }
                }
            }

            error?.let {
                ErrorBanner(message = it, onRetry = { load() }, modifier = Modifier.padding(16.dp))
            }

            if (loading && records.isEmpty()) {
                Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
            } else if (filtered.isEmpty()) {
                Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                    Column(horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        Text(
                            activeFilterLabel?.let { "当前筛选「$it」暂无学生" } ?: "暂无匹配的学生",
                            style = MaterialTheme.typography.bodyMedium,
                            color = MaterialTheme.colorScheme.onSurfaceVariant
                        )
                        if (statusFilter != null || searchText.isNotBlank()) {
                            OutlinedButton(onClick = { statusFilter = null; searchText = "" }) {
                                Text("查看全部")
                            }
                        }
                    }
                }
            } else {
                LazyVerticalGrid(
                    columns = GridCells.Fixed(columnCount.coerceIn(1, 4)),
                    contentPadding = PaddingValues(14.dp),
                    horizontalArrangement = Arrangement.spacedBy(10.dp),
                    verticalArrangement = Arrangement.spacedBy(10.dp),
                    modifier = Modifier.fillMaxSize()
                ) {
                    items(filtered, key = { it.id }) { rec ->
                        val compact = columnCount >= 3
                        val isPresent = rec.status == "present"
                        val isOnLeave = rec.status == "on_leave"
                        val isPendingLeave = isOnLeave && rec.isPendingApprovalLeave
                        val tileColor = when (rec.status) {
                            "present" -> Color(0xFF16A34A)
                            "on_leave" -> if (isPendingLeave) Color(0xFFEA580C) else Color(0xFF2563EB)
                            "absent" -> Color(0xFFDC2626)
                            else -> Color(0xFF64748B)
                        }
                        Surface(
                            shape = RoundedCornerShape(12.dp),
                            color = when {
                                isPresent -> Color(0xFF16A34A)
                                rec.status == "pending" -> MaterialTheme.colorScheme.surface
                                else -> tileColor.copy(alpha = 0.12f)
                            },
                            border = BorderStroke(1.2.dp, tileColor.copy(alpha = if (rec.status == "pending") 0.25f else 0.6f)),
                            modifier = Modifier.fillMaxWidth().clickable {
                                // 请假/考勤标记学生：点名员严格只读不可修改，点击统一弹出「请假与去向详情」查看完整事由/备注
                                if (isOnLeave) {
                                    inspectingLeaveRecord = rec
                                    return@clickable
                                }
                                if (isInProgress) {
                                    val nextPresent = rec.status != "present"
                                    val iso = if (nextPresent) SCDateFormatter.nowIso() else null
                                    val local = if (nextPresent) SCDateFormatter.nowHhmm() else null
                                    val nextStatus = if (nextPresent) "present" else "pending"
                                    records = records.map {
                                        if (it.id == rec.id) it.copy(status = nextStatus, markedAt = iso, markedAtLocal = local) else it
                                    }
                                    haptic.performHapticFeedback(HapticFeedbackType.TextHandleMove)
                                    scope.launch {
                                        draftDao.upsert(
                                            RollCallDraftEntity("${rollCallId}_${rec.studentId}", rollCallId, rec.studentId, nextStatus, iso, local)
                                        )
                                    }
                                } else if (canModifyCompleted) {
                                    editingCompleted = rec
                                }
                            }
                        ) {
                            Box(modifier = Modifier.fillMaxWidth().heightIn(min = if (compact) 68.dp else 82.dp)) {
                                rec.shortStudentNo?.let { shortNo ->
                                    Text(
                                        text = shortNo,
                                        fontSize = if (compact) 28.sp else 36.sp,
                                        lineHeight = if (compact) 28.sp else 36.sp,
                                        fontWeight = FontWeight.Black,
                                        color = when {
                                            isPresent -> Color.White.copy(alpha = 0.26f)
                                            isOnLeave -> tileColor.copy(alpha = 0.18f)
                                            rec.status == "absent" -> Color(0xFFDC2626).copy(alpha = 0.22f)
                                            else -> MaterialTheme.colorScheme.primary.copy(alpha = 0.16f)
                                        },
                                        maxLines = 1,
                                        modifier = Modifier
                                            .align(Alignment.BottomEnd)
                                            .padding(end = if (compact) 6.dp else 8.dp, bottom = if (compact) 1.dp else 2.dp)
                                    )
                                }
                                Column(
                                    modifier = Modifier.fillMaxWidth().padding(if (compact) 9.dp else 12.dp),
                                    verticalArrangement = Arrangement.spacedBy(3.dp)
                                ) {
                                    Row(
                                        verticalAlignment = Alignment.CenterVertically,
                                        modifier = Modifier.fillMaxWidth()
                                    ) {
                                        Text(
                                            rec.studentName,
                                            fontSize = if (compact) 13.sp else 15.sp,
                                            fontWeight = FontWeight.SemiBold,
                                            color = if (isPresent) Color.White else MaterialTheme.colorScheme.onSurface,
                                            maxLines = 1,
                                            overflow = TextOverflow.Ellipsis,
                                            modifier = Modifier.weight(1f)
                                        )
                                        if (isOnLeave) {
                                            Icon(
                                                Icons.Rounded.Lock,
                                                contentDescription = "请假已锁定",
                                                tint = tileColor,
                                                modifier = Modifier.size(if (compact) 11.dp else 13.dp)
                                            )
                                        }
                                    }
                                    if (!compact && !rec.studentNo.isNullOrBlank()) {
                                        Text(
                                            rec.studentNo,
                                            style = MaterialTheme.typography.labelSmall,
                                            color = if (isPresent) Color.White.copy(alpha = 0.85f) else MaterialTheme.colorScheme.onSurfaceVariant,
                                            maxLines = 1
                                        )
                                    }
                                    Row(
                                        verticalAlignment = Alignment.CenterVertically,
                                        horizontalArrangement = Arrangement.spacedBy(4.dp)
                                    ) {
                                        Text(
                                            when (rec.status) {
                                                "present" -> rec.markedAtLocal ?: "已到"
                                                "on_leave" -> rec.leaveTitleText
                                                "absent" -> "缺勤"
                                                else -> "未点"
                                            },
                                            style = MaterialTheme.typography.labelSmall,
                                            fontWeight = FontWeight.SemiBold,
                                            color = if (isPresent) Color.White else tileColor,
                                            maxLines = 1
                                        )
                                        if (isPendingLeave) {
                                            Surface(
                                                shape = RoundedCornerShape(6.dp),
                                                color = Color(0xFFEA580C).copy(alpha = 0.18f)
                                            ) {
                                                Text(
                                                    "待审批",
                                                    fontSize = 9.sp,
                                                    fontWeight = FontWeight.SemiBold,
                                                    color = Color(0xFFEA580C),
                                                    modifier = Modifier.padding(horizontal = 4.dp, vertical = 1.dp)
                                                )
                                            }
                                        }
                                        if (isPresent) {
                                            Icon(
                                                Icons.Rounded.CheckCircle,
                                                contentDescription = null,
                                                tint = Color.White,
                                                modifier = Modifier.size(if (compact) 12.dp else 13.dp)
                                            )
                                        }
                                    }
                                    if (isOnLeave) {
                                        Text(
                                            rec.leaveDetailDisplayText,
                                            fontSize = if (compact) 10.sp else 11.sp,
                                            lineHeight = if (compact) 13.sp else 15.sp,
                                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                                            maxLines = if (compact) 2 else 3,
                                            overflow = TextOverflow.Ellipsis
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

    if (confirmComplete) {
        AlertDialog(
            onDismissRequest = { confirmComplete = false },
            title = { Text("确认完成本次点名？") },
            text = { Text("已到 $presentCount 人，请假 $onLeaveCount 人，未点 $pendingCount 人（提交后自动记为缺勤）。") },
            confirmButton = {
                TextButton(onClick = {
                    confirmComplete = false
                    scope.launch {
                        submitting = true
                        error = null
                        runCatching {
                            repo.completeRollCall(rollCallId, records)
                            draftDao.clearByRollCall(rollCallId)
                            restoredDraft = false
                            if (statusFilter == "pending") statusFilter = null
                            load()
                        }.onFailure { error = it.localizedMessage }
                        submitting = false
                    }
                }) { Text("确认提交", fontWeight = FontWeight.Bold) }
            },
            dismissButton = { TextButton(onClick = { confirmComplete = false }) { Text("取消") } }
        )
    }

    inspectingLeaveRecord?.let { rec ->
        val accent = if (rec.isPendingApprovalLeave) Color(0xFFEA580C) else Color(0xFF2563EB)
        AlertDialog(
            onDismissRequest = { inspectingLeaveRecord = null },
            title = {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Icon(Icons.Rounded.Lock, contentDescription = null, tint = accent, modifier = Modifier.size(20.dp))
                    Text("学生请假与去向详情")
                }
            },
            text = {
                Column(
                    modifier = Modifier.verticalScroll(rememberScrollState()),
                    verticalArrangement = Arrangement.spacedBy(10.dp)
                ) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Column(modifier = Modifier.weight(1f)) {
                            Text(rec.studentName, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                            rec.studentNo?.takeIf { it.isNotBlank() }?.let {
                                Text("学号：$it", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                            }
                        }
                        StatusBadge(
                            if (rec.isPendingApprovalLeave) "待审批请假" else rec.leaveTitleText,
                            accent
                        )
                    }
                    Surface(
                        shape = RoundedCornerShape(10.dp),
                        color = accent.copy(alpha = 0.08f),
                        border = BorderStroke(1.dp, accent.copy(alpha = 0.25f))
                    ) {
                        Column(
                            modifier = Modifier.fillMaxWidth().padding(12.dp),
                            verticalArrangement = Arrangement.spacedBy(4.dp)
                        ) {
                            Text("请假事由 / 备注与去向", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                            Text(rec.leaveDetailDisplayText, style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.Medium)
                            Text(rec.leaveStatusSummaryText, style = MaterialTheme.typography.labelSmall, color = accent)
                        }
                    }
                    Text(
                        if (isTeacherOrAdmin)
                            "该生在当前节次已有请假申请或考勤标记，点名过程中自动锁定以防误操作。"
                        else
                            "该生在当前节次已有请假申请或考勤标记，点名员仅可查看去向，不可修改状态。",
                        style = MaterialTheme.typography.labelSmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant
                    )
                }
            },
            confirmButton = {
                TextButton(onClick = { inspectingLeaveRecord = null }) {
                    Text("知道了", fontWeight = FontWeight.Bold)
                }
            },
            dismissButton = if (!isInProgress && canModifyCompleted && isTeacherOrAdmin) {
                {
                    TextButton(onClick = {
                        inspectingLeaveRecord = null
                        editingCompleted = rec
                    }) {
                        Text("教师调整状态")
                    }
                }
            } else null
        )
    }

    editingCompleted?.let { rec ->
        AlertDialog(
            onDismissRequest = { editingCompleted = null },
            title = { Text("修改 ${rec.studentName} 点名状态") },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    OutlinedButton(
                        modifier = Modifier.fillMaxWidth(),
                        onClick = {
                            editingCompleted = null
                            scope.launch {
                                error = null
                                runCatching {
                                    repo.updateRollCallRecord(rollCallId, rec.id, "present")
                                    load()
                                }.onFailure { error = it.localizedMessage }
                            }
                        }
                    ) { Text("标记为已到") }

                    OutlinedButton(
                        modifier = Modifier.fillMaxWidth(),
                        colors = ButtonDefaults.outlinedButtonColors(contentColor = Color(0xFFDC2626)),
                        onClick = {
                            editingCompleted = null
                            scope.launch {
                                error = null
                                runCatching {
                                    repo.updateRollCallRecord(rollCallId, rec.id, "absent")
                                    load()
                                }.onFailure { error = it.localizedMessage }
                            }
                        }
                    ) { Text("标记为缺勤") }

                    if (selectableLeaveTypes.isEmpty()) {
                        OutlinedButton(
                            modifier = Modifier.fillMaxWidth(),
                            onClick = {
                                editingCompleted = null
                                scope.launch {
                                    error = null
                                    runCatching {
                                        repo.updateRollCallRecord(rollCallId, rec.id, "on_leave")
                                        load()
                                    }.onFailure { error = it.localizedMessage }
                                }
                            }
                        ) { Text("标记为请假") }
                    } else {
                        selectableLeaveTypes.forEach { lt ->
                            OutlinedButton(
                                modifier = Modifier.fillMaxWidth(),
                                onClick = {
                                    editingCompleted = null
                                    scope.launch {
                                        error = null
                                        runCatching {
                                            repo.updateRollCallRecord(rollCallId, rec.id, "on_leave", lt.id)
                                            load()
                                        }.onFailure { error = it.localizedMessage }
                                    }
                                }
                            ) { Text("标记为请假 · ${lt.name}") }
                        }
                    }
                }
            },
            confirmButton = {},
            dismissButton = { TextButton(onClick = { editingCompleted = null }) { Text("取消") } }
        )
    }
}

@Composable
private fun RollCallMetricPill(
    title: String,
    value: Int,
    color: Color,
    highlightColor: Color,
    selected: Boolean,
    onClick: () -> Unit,
    modifier: Modifier = Modifier
) {
    Surface(
        modifier = modifier.clip(RoundedCornerShape(10.dp)).clickable(onClick = onClick),
        shape = RoundedCornerShape(10.dp),
        color = highlightColor.copy(alpha = if (selected) 0.16f else 0.08f),
        border = if (selected) BorderStroke(1.6.dp, highlightColor) else null
    ) {
        Column(modifier = Modifier.padding(vertical = 6.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            Text("$value", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold, color = color)
            Text(
                title,
                style = MaterialTheme.typography.labelSmall,
                fontWeight = if (selected) FontWeight.Bold else FontWeight.Normal,
                color = if (selected) highlightColor else MaterialTheme.colorScheme.onSurfaceVariant
            )
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun RollCallAdminsScreen(onBack: () -> Unit) {
    val repo = SmartCampusApp.container.repository
    val scope = rememberCoroutineScope()
    var classes by remember { mutableStateOf<List<ClassOptionItem>>(emptyList()) }
    var selectedClassId by remember { mutableStateOf(0) }
    var admins by remember { mutableStateOf<List<RollCallAdminItem>>(emptyList()) }
    var menuOpen by remember { mutableStateOf(false) }

    LaunchedEffect(Unit) {
        classes = runCatching { repo.fetchClasses() }.getOrDefault(emptyList())
        classes.firstOrNull()?.let { selectedClassId = it.id }
    }

    LaunchedEffect(selectedClassId) {
        if (selectedClassId > 0) {
            admins = runCatching { repo.fetchRollCallAdmins(selectedClassId) }.getOrDefault(emptyList())
        }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("班级点名员") },
                navigationIcon = { IconButton(onClick = onBack) { Icon(Icons.AutoMirrored.Rounded.ArrowBack, contentDescription = "返回") } }
            )
        }
    ) { padding ->
        Column(modifier = Modifier.fillMaxSize().padding(padding).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            if (classes.isNotEmpty()) {
                Box {
                    OutlinedButton(onClick = { menuOpen = true }, modifier = Modifier.fillMaxWidth()) {
                        Text(classes.firstOrNull { it.id == selectedClassId }?.name ?: "选择班级")
                    }
                    DropdownMenu(expanded = menuOpen, onDismissRequest = { menuOpen = false }) {
                        classes.forEach { cls ->
                            DropdownMenuItem(text = { Text(cls.name) }, onClick = { selectedClassId = cls.id; menuOpen = false })
                        }
                    }
                }
            }
            if (admins.isEmpty()) {
                Text("该班级暂未指定学生点名员", color = MaterialTheme.colorScheme.onSurfaceVariant)
            } else {
                LazyColumn(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    items(admins, key = { it.id }) { admin ->
                        SCCard {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Column(modifier = Modifier.weight(1f)) {
                                    Text(admin.studentName, fontWeight = FontWeight.SemiBold)
                                    if (admin.authorizedTypeNames.isNotEmpty()) {
                                        Text("授权：" + admin.authorizedTypeNames.joinToString("、"), style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                                    }
                                }
                                IconButton(onClick = {
                                    scope.launch {
                                        runCatching { repo.deleteRollCallAdmin(admin.id) }
                                        admins = admins.filterNot { it.id == admin.id }
                                    }
                                }) {
                                    Icon(Icons.Rounded.Delete, contentDescription = "移除", tint = Color(0xFFDC2626))
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
fun RollCallTypesManageScreen(
    user: User,
    onStartRollCall: (Int) -> Unit,
    onBack: () -> Unit
) {
    val repo = SmartCampusApp.container.repository
    val scope = rememberCoroutineScope()

    var types by remember { mutableStateOf<List<RollCallType>>(emptyList()) }
    var classes by remember { mutableStateOf<List<ClassOptionItem>>(emptyList()) }
    var periods by remember { mutableStateOf<List<ClassPeriod>>(emptyList()) }
    var leaveTypes by remember { mutableStateOf<List<LeaveType>>(emptyList()) }
    var filterClassId by remember { mutableStateOf(0) }
    var classMenuOpen by remember { mutableStateOf(false) }
    var loading by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    var showEditor by remember { mutableStateOf(false) }
    var editingType by remember { mutableStateOf<RollCallType?>(null) }
    var startingTypeId by remember { mutableStateOf<Int?>(null) }

    fun loadAll() {
        scope.launch {
            loading = true
            error = null
            runCatching {
                types = repo.fetchRollCallTypes()
                classes = runCatching { repo.fetchClasses() }.getOrDefault(emptyList())
                periods = runCatching { repo.fetchClassPeriods().filter { !it.isEveningStudy && it.isActive } }.getOrDefault(emptyList())
                leaveTypes = runCatching { repo.fetchLeaveTypes().filter { it.isActive } }.getOrDefault(emptyList())
            }.onFailure { error = it.localizedMessage }
            loading = false
        }
    }

    LaunchedEffect(Unit) { loadAll() }

    val filteredTypes = if (filterClassId > 0) types.filter { it.classId == filterClassId } else types

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("点名类型与课程点名") },
                navigationIcon = { IconButton(onClick = onBack) { Icon(Icons.AutoMirrored.Rounded.ArrowBack, contentDescription = "返回") } },
                actions = {
                    IconButton(onClick = { editingType = null; showEditor = true }) {
                        Icon(Icons.Rounded.Add, contentDescription = "新增点名类型")
                    }
                }
            )
        }
    ) { padding ->
        Column(
            modifier = Modifier.fillMaxSize().padding(padding).padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp)
        ) {
            error?.let { ErrorBanner(message = it, onRetry = { loadAll() }) }

            if (classes.size > 1) {
                Box {
                    OutlinedButton(onClick = { classMenuOpen = true }, modifier = Modifier.fillMaxWidth()) {
                        Text(if (filterClassId == 0) "筛选班级：全部班级" else "筛选班级：${classes.firstOrNull { it.id == filterClassId }?.name.orEmpty()}")
                    }
                    DropdownMenu(expanded = classMenuOpen, onDismissRequest = { classMenuOpen = false }) {
                        DropdownMenuItem(text = { Text("全部班级") }, onClick = { filterClassId = 0; classMenuOpen = false })
                        classes.forEach { cls ->
                            DropdownMenuItem(text = { Text(cls.name) }, onClick = { filterClassId = cls.id; classMenuOpen = false })
                        }
                    }
                }
            }

            if (loading && types.isEmpty()) {
                Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
            } else if (filteredTypes.isEmpty()) {
                Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                    Text("暂无点名类型，点击右上角「+」可新增第1、2…节课程点名。", color = MaterialTheme.colorScheme.onSurfaceVariant)
                }
            } else {
                LazyColumn(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    items(filteredTypes, key = { it.id }) { item ->
                        SCCard {
                            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                Column(modifier = Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                                        Text(item.name, fontWeight = FontWeight.SemiBold, style = MaterialTheme.typography.titleSmall)
                                        item.className?.takeIf { it.isNotBlank() }?.let {
                                            StatusBadge(it, MaterialTheme.colorScheme.primary)
                                        }
                                    }
                                    item.periodSummary(periods)?.let { summary ->
                                        Text("关联节次：$summary", style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.primary)
                                    }
                                    Text(
                                        "未到标记：${item.absentStatusDisplayName}" + (item.leaveTypeName?.let { " · 关联假类：$it" }.orEmpty()),
                                        style = MaterialTheme.typography.labelSmall,
                                        color = MaterialTheme.colorScheme.onSurfaceVariant
                                    )
                                    item.description?.takeIf { it.isNotBlank() }?.let {
                                        Text(it, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                                    }
                                }
                                Button(
                                    enabled = startingTypeId == null,
                                    contentPadding = PaddingValues(horizontal = 10.dp, vertical = 6.dp),
                                    onClick = {
                                        scope.launch {
                                            startingTypeId = item.id
                                            error = null
                                            runCatching {
                                                val created = repo.startRollCall(item.id, SCDateFormatter.nowRollCallDateTime(), null)
                                                onStartRollCall(created.id)
                                            }.onFailure { error = it.localizedMessage }
                                            startingTypeId = null
                                        }
                                    }
                                ) {
                                    Icon(Icons.Rounded.PlayArrow, contentDescription = null, modifier = Modifier.size(16.dp))
                                    Spacer(Modifier.width(2.dp))
                                    Text("点名", style = MaterialTheme.typography.labelMedium)
                                }
                            }
                            Spacer(Modifier.height(6.dp))
                            Row(horizontalArrangement = Arrangement.End, modifier = Modifier.fillMaxWidth()) {
                                TextButton(onClick = { editingType = item; showEditor = true }) {
                                    Icon(Icons.Rounded.Edit, contentDescription = null, modifier = Modifier.size(15.dp))
                                    Spacer(Modifier.width(4.dp))
                                    Text("编辑")
                                }
                                TextButton(
                                    colors = ButtonDefaults.textButtonColors(contentColor = Color(0xFFDC2626)),
                                    onClick = {
                                        scope.launch {
                                            runCatching { repo.deleteRollCallType(item.id) }
                                                .onSuccess { types = types.filterNot { it.id == item.id } }
                                                .onFailure { error = it.localizedMessage }
                                        }
                                    }
                                ) {
                                    Icon(Icons.Rounded.Delete, contentDescription = null, modifier = Modifier.size(15.dp))
                                    Spacer(Modifier.width(4.dp))
                                    Text("删除")
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    if (showEditor) {
        RollCallTypeEditorDialog(
            user = user,
            editing = editingType,
            existingTypes = types,
            classes = classes,
            periods = periods,
            leaveTypes = leaveTypes,
            defaultClassId = if (filterClassId > 0) filterClassId else (classes.firstOrNull()?.id ?: 0),
            onDismiss = { showEditor = false; editingType = null },
            onSaved = {
                showEditor = false
                editingType = null
                loadAll()
            }
        )
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun RollCallTypeEditorDialog(
    user: User,
    editing: RollCallType?,
    existingTypes: List<RollCallType>,
    classes: List<ClassOptionItem>,
    periods: List<ClassPeriod>,
    leaveTypes: List<LeaveType>,
    defaultClassId: Int,
    onDismiss: () -> Unit,
    onSaved: () -> Unit
) {
    val repo = SmartCampusApp.container.repository
    val scope = rememberCoroutineScope()

    var name by remember(editing?.id) { mutableStateOf(editing?.name.orEmpty()) }
    var lastAutoName by remember(editing?.id) { mutableStateOf("") }
    var description by remember(editing?.id) { mutableStateOf(editing?.description.orEmpty()) }
    var selectedClassId by remember(editing?.id) { mutableStateOf(editing?.classId ?: defaultClassId) }
    var batchMode by remember(editing?.id) { mutableStateOf(false) }
    var batchClassIds by remember(editing?.id) { mutableStateOf(if (defaultClassId > 0) setOf(defaultClassId) else emptySet()) }
    var absentStatus by remember(editing?.id) { mutableStateOf(editing?.absentStatus ?: "absent") }
    var leaveTypeId by remember(editing?.id) { mutableStateOf(editing?.leaveTypeId) }
    var selectedPeriodIds by remember(editing?.id) { mutableStateOf(editing?.periodIds?.toSet() ?: emptySet()) }
    var isActive by remember(editing?.id) { mutableStateOf(editing?.isActive ?: true) }

    var classMenuOpen by remember { mutableStateOf(false) }
    var absentMenuOpen by remember { mutableStateOf(false) }
    var leaveMenuOpen by remember { mutableStateOf(false) }
    var saving by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    val absentOptions = listOf("absent" to "旷课", "late" to "迟到", "leave" to "请假")
    val canBatch = editing == null && classes.size > 1 && user.isAdminTier
    val occupiedFixedIds = remember(existingTypes, selectedClassId, batchMode, batchClassIds, editing?.id, periods) {
        if (batchMode) {
            if (batchClassIds.isEmpty()) emptySet()
            else {
                val sets = batchClassIds.map { cid ->
                    RollCallPeriodNamingHelper.occupiedFixedPeriodIds(existingTypes, cid, editing?.id, periods)
                }
                sets.drop(1).fold(sets.first()) { acc, s -> acc intersect s }
            }
        } else {
            RollCallPeriodNamingHelper.occupiedFixedPeriodIds(existingTypes, selectedClassId, editing?.id, periods)
        }
    }
    val matchedExistingType = remember(selectedPeriodIds, existingTypes, selectedClassId, batchMode, editing?.id) {
        if (batchMode) null
        else RollCallPeriodNamingHelper.matchingExistingType(selectedPeriodIds, existingTypes, selectedClassId, editing?.id)
    }
    LaunchedEffect(occupiedFixedIds) {
        val cleaned = selectedPeriodIds - occupiedFixedIds
        if (cleaned != selectedPeriodIds) {
            selectedPeriodIds = cleaned
        }
    }
    val canSubmit = !saving && name.isNotBlank() && (editing != null || (if (batchMode) batchClassIds.isNotEmpty() else selectedClassId > 0))

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(if (editing != null) "编辑点名类型" else "新增点名类型") },
        text = {
            Column(
                modifier = Modifier.verticalScroll(rememberScrollState()),
                verticalArrangement = Arrangement.spacedBy(10.dp)
            ) {
                error?.let { Text(it, color = Color(0xFFDC2626), style = MaterialTheme.typography.bodySmall) }

                Text("关联节次（可多选，用于考勤统计）", style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.SemiBold)
                if (periods.isEmpty()) {
                    Text("暂无节次配置", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                } else {
                    FlowRow(
                        horizontalArrangement = Arrangement.spacedBy(6.dp),
                        verticalArrangement = Arrangement.spacedBy(4.dp)
                    ) {
                        periods.forEach { p ->
                            val isDisabled = p.id in occupiedFixedIds
                            val chosen = !isDisabled && p.id in selectedPeriodIds
                            FilterChip(
                                selected = chosen,
                                enabled = !isDisabled,
                                onClick = {
                                    if (isDisabled) return@FilterChip
                                    val next = if (chosen) selectedPeriodIds - p.id else selectedPeriodIds + p.id
                                    selectedPeriodIds = next
                                    if (editing == null) {
                                        val trimmed = name.trim()
                                        if (trimmed.isEmpty() || trimmed == lastAutoName) {
                                            val suggested = RollCallPeriodNamingHelper.suggestedName(next, periods)
                                            name = suggested
                                            lastAutoName = suggested
                                        }
                                    }
                                },
                                label = {
                                    Text(
                                        if (isDisabled) "${p.name} · 已有" else p.name,
                                        style = MaterialTheme.typography.labelMedium
                                    )
                                }
                            )
                        }
                    }
                    matchedExistingType?.let { dup ->
                        Surface(
                            shape = RoundedCornerShape(8.dp),
                            color = Color(0xFF2563EB).copy(alpha = 0.08f),
                            border = BorderStroke(1.dp, Color(0xFF2563EB).copy(alpha = 0.25f))
                        ) {
                            Row(
                                modifier = Modifier.fillMaxWidth().padding(8.dp),
                                verticalAlignment = Alignment.CenterVertically,
                                horizontalArrangement = Arrangement.spacedBy(6.dp)
                            ) {
                                Icon(Icons.Rounded.Info, contentDescription = null, tint = Color(0xFF2563EB), modifier = Modifier.size(16.dp))
                                Text(
                                    "本班已有同节次类型「${dup.name}」，可复用原类型或改名另建。",
                                    style = MaterialTheme.typography.labelSmall,
                                    color = Color(0xFF2563EB)
                                )
                            }
                        }
                    }
                    Text(
                        "已选 ${selectedPeriodIds.size} 个节次；本班已有固定活动（如早读/早操/晚操）已置灰不可重复选，正课节次可自由组合。",
                        style = MaterialTheme.typography.labelSmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant
                    )
                }

                OutlinedTextField(
                    value = name,
                    onValueChange = { name = it },
                    label = { Text("活动名称（如：第1-2节点名）") },
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth()
                )
                OutlinedTextField(
                    value = description,
                    onValueChange = { description = it },
                    label = { Text("描述（可选）") },
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth()
                )

                if (editing == null) {
                    if (canBatch) {
                        Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.fillMaxWidth()) {
                            Text("为多个班级批量创建", modifier = Modifier.weight(1f), style = MaterialTheme.typography.bodySmall)
                            Switch(checked = batchMode, onCheckedChange = { batchMode = it })
                        }
                    }
                    if (batchMode) {
                        classes.forEach { cls ->
                            val checked = cls.id in batchClassIds
                            Row(
                                verticalAlignment = Alignment.CenterVertically,
                                modifier = Modifier.fillMaxWidth().clickable {
                                    batchClassIds = if (checked) batchClassIds - cls.id else batchClassIds + cls.id
                                }
                            ) {
                                Checkbox(checked = checked, onCheckedChange = {
                                    batchClassIds = if (it) batchClassIds + cls.id else batchClassIds - cls.id
                                })
                                Text(cls.name, style = MaterialTheme.typography.bodySmall)
                            }
                        }
                    } else if (classes.size > 1) {
                        Box {
                            OutlinedButton(onClick = { classMenuOpen = true }, modifier = Modifier.fillMaxWidth()) {
                                Text("班级：" + (classes.firstOrNull { it.id == selectedClassId }?.name ?: "选择班级"))
                            }
                            DropdownMenu(expanded = classMenuOpen, onDismissRequest = { classMenuOpen = false }) {
                                classes.forEach { cls ->
                                    DropdownMenuItem(text = { Text(cls.name) }, onClick = { selectedClassId = cls.id; classMenuOpen = false })
                                }
                            }
                        }
                    } else if (classes.size == 1) {
                        Text("所属班级：${classes[0].name}", style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                }

                Box {
                    OutlinedButton(onClick = { absentMenuOpen = true }, modifier = Modifier.fillMaxWidth()) {
                        Text("未到标记：" + (absentOptions.firstOrNull { it.first == absentStatus }?.second ?: "旷课"))
                    }
                    DropdownMenu(expanded = absentMenuOpen, onDismissRequest = { absentMenuOpen = false }) {
                        absentOptions.forEach { (k, label) ->
                            DropdownMenuItem(text = { Text(label) }, onClick = { absentStatus = k; absentMenuOpen = false })
                        }
                    }
                }

                if (leaveTypes.isNotEmpty()) {
                    Box {
                        OutlinedButton(onClick = { leaveMenuOpen = true }, modifier = Modifier.fillMaxWidth()) {
                            Text("关联假类：" + (leaveTypes.firstOrNull { it.id == leaveTypeId }?.name ?: "不关联"))
                        }
                        DropdownMenu(expanded = leaveMenuOpen, onDismissRequest = { leaveMenuOpen = false }) {
                            DropdownMenuItem(text = { Text("不关联") }, onClick = { leaveTypeId = null; leaveMenuOpen = false })
                            leaveTypes.forEach { lt ->
                                DropdownMenuItem(text = { Text(lt.name) }, onClick = { leaveTypeId = lt.id; leaveMenuOpen = false })
                            }
                        }
                    }
                }

                Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.fillMaxWidth()) {
                    Text("启用该点名类型", modifier = Modifier.weight(1f), style = MaterialTheme.typography.bodySmall)
                    Switch(checked = isActive, onCheckedChange = { isActive = it })
                }
            }
        },
        confirmButton = {
            TextButton(
                enabled = canSubmit,
                onClick = {
                    scope.launch {
                        saving = true
                        error = null
                        val orderedPeriods = periods.filter { it.id in selectedPeriodIds }.map { it.id }
                        runCatching {
                            if (editing != null) {
                                repo.updateRollCallType(
                                    id = editing.id,
                                    name = name,
                                    description = description,
                                    absentStatus = absentStatus,
                                    leaveTypeId = leaveTypeId,
                                    periodIds = orderedPeriods,
                                    isActive = isActive
                                )
                            } else if (batchMode) {
                                repo.batchCreateRollCallTypes(
                                    classIds = batchClassIds.toList(),
                                    name = name,
                                    description = description,
                                    absentStatus = absentStatus,
                                    leaveTypeId = leaveTypeId,
                                    periodIds = orderedPeriods,
                                    isActive = isActive
                                )
                            } else {
                                repo.createRollCallType(
                                    classId = selectedClassId,
                                    name = name,
                                    description = description,
                                    absentStatus = absentStatus,
                                    leaveTypeId = leaveTypeId,
                                    periodIds = orderedPeriods,
                                    isActive = isActive
                                )
                            }
                            onSaved()
                        }.onFailure { error = it.localizedMessage ?: "保存失败" }
                        saving = false
                    }
                }
            ) { Text(if (saving) "保存中..." else "保存") }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text("取消") } }
    )
}
