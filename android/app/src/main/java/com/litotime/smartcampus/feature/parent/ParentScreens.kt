package com.litotime.smartcampus.feature.parent

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
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
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.Description
import androidx.compose.material.icons.rounded.Refresh
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
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
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.litotime.smartcampus.SmartCampusApp
import com.litotime.smartcampus.core.designsystem.ErrorBanner
import com.litotime.smartcampus.core.designsystem.SCCard
import com.litotime.smartcampus.core.designsystem.StatMetricCard
import com.litotime.smartcampus.core.designsystem.StatusBadge
import com.litotime.smartcampus.core.designsystem.statusColor
import com.litotime.smartcampus.core.util.SCDateFormatter
import com.litotime.smartcampus.data.model.AppNotificationItem
import com.litotime.smartcampus.data.model.ParentChildOverview
import com.litotime.smartcampus.data.model.ParentChildSummary
import com.litotime.smartcampus.data.model.ParentLeaveRequestItem
import com.litotime.smartcampus.data.model.ParentRecordItem
import com.litotime.smartcampus.data.model.User
import kotlinx.coroutines.launch

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ParentFeedScreen(
    user: User,
    onOpenChildDetail: (Int) -> Unit
) {
    val container = SmartCampusApp.container
    val repo = container.repository
    val scope = rememberCoroutineScope()

    var children by remember { mutableStateOf<List<ParentChildSummary>>(emptyList()) }
    var selectedChildId by remember { mutableStateOf<Int?>(null) }
    var items by remember { mutableStateOf<List<AppNotificationItem>>(emptyList()) }
    var unreadOnly by remember { mutableStateOf(false) }
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<String?>(null) }

    fun loadAll() {
        scope.launch {
            loading = true
            error = null
            runCatching {
                val c = repo.fetchParentChildren()
                val page = repo.fetchNotifications()
                children = c
                if (c.size == 1) selectedChildId = c.first().id
                items = page.items
                container.pushManager.setUnreadCount(page.unreadCount)
            }.onFailure {
                error = it.localizedMessage ?: "加载失败"
            }
            loading = false
        }
    }

    LaunchedEffect(user.id) { loadAll() }

    val filtered = remember(items, unreadOnly, selectedChildId, children.size) {
        items.filter { item ->
            if (unreadOnly && !item.isUnread) return@filter false
            val sid = selectedChildId
            if (sid != null && children.size > 1 && item.studentId != null) {
                return@filter item.studentId == sid
            }
            true
        }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("孩子动态") },
                actions = {
                    if (items.any { it.isUnread }) {
                        TextButton(onClick = {
                            scope.launch {
                                runCatching { repo.markAllNotificationsRead() }
                                loadAll()
                            }
                        }) { Text("全部已读") }
                    }
                    IconButton(onClick = { loadAll() }) {
                        Icon(Icons.Rounded.Refresh, contentDescription = "刷新")
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
            verticalArrangement = Arrangement.spacedBy(12.dp)
        ) {
            error?.let { ErrorBanner(message = it, onRetry = { loadAll() }) }

            if (children.isNotEmpty()) {
                Row(
                    modifier = Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()),
                    horizontalArrangement = Arrangement.spacedBy(8.dp)
                ) {
                    if (children.size > 1) {
                        ChildFilterChip(
                            title = "全部孩子",
                            subtitle = "${children.size}人",
                            selected = selectedChildId == null,
                            onClick = { selectedChildId = null }
                        )
                    }
                    children.forEach { child ->
                        ChildFilterChip(
                            title = child.name,
                            subtitle = child.className ?: "-",
                            badgeCount = child.todayAnomalyCount + child.pendingLeaveCount,
                            selected = selectedChildId == child.id,
                            onClick = { selectedChildId = child.id }
                        )
                    }
                }
            }

            SingleChoiceSegmentedButtonRow(modifier = Modifier.fillMaxWidth()) {
                listOf(false to "全部动态", true to "仅看未读").forEachIndexed { idx, (onlyUnread, label) ->
                    SegmentedButton(
                        selected = unreadOnly == onlyUnread,
                        onClick = { unreadOnly = onlyUnread },
                        shape = SegmentedButtonDefaults.itemShape(idx, 2)
                    ) { Text(label) }
                }
            }

            if (loading && items.isEmpty()) {
                Box(modifier = Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator()
                }
            } else if (filtered.isEmpty()) {
                Box(modifier = Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                    Text(
                        if (unreadOnly) "暂无未读动态" else "暂无孩子请假或考勤标记推送",
                        color = MaterialTheme.colorScheme.onSurfaceVariant
                    )
                }
            } else {
                LazyColumn(
                    modifier = Modifier.weight(1f),
                    verticalArrangement = Arrangement.spacedBy(10.dp)
                ) {
                    items(filtered, key = { it.id }) { item ->
                        val (badgeText, badgeColor) = parentEventBadge(item.type)
                        SCCard(onClick = {
                            scope.launch {
                                if (item.isUnread) {
                                    runCatching { repo.markNotificationRead(item.id) }
                                    container.pushManager.refreshUnreadCount()
                                    items = items.map { if (it.id == item.id) it.copy(readAt = "read") else it }
                                }
                                item.studentId?.let { onOpenChildDetail(it) }
                            }
                        }) {
                            Row(
                                verticalAlignment = Alignment.Top,
                                horizontalArrangement = Arrangement.spacedBy(10.dp)
                            ) {
                                if (item.isUnread) {
                                    Box(
                                        modifier = Modifier
                                            .padding(top = 6.dp)
                                            .size(8.dp)
                                            .clip(CircleShape)
                                            .background(MaterialTheme.colorScheme.primary)
                                    )
                                }
                                Column(
                                    modifier = Modifier.weight(1f),
                                    verticalArrangement = Arrangement.spacedBy(4.dp)
                                ) {
                                    Row(
                                        modifier = Modifier.fillMaxWidth(),
                                        verticalAlignment = Alignment.CenterVertically,
                                        horizontalArrangement = Arrangement.SpaceBetween
                                    ) {
                                        StatusBadge(text = badgeText, color = badgeColor)
                                        Text(
                                            SCDateFormatter.displayDateTime(item.createdAt),
                                            style = MaterialTheme.typography.labelSmall,
                                            color = MaterialTheme.colorScheme.onSurfaceVariant
                                        )
                                    }
                                    Text(
                                        item.title,
                                        style = MaterialTheme.typography.titleSmall,
                                        fontWeight = if (item.isUnread) FontWeight.Bold else FontWeight.Medium
                                    )
                                    Text(
                                        item.body,
                                        style = MaterialTheme.typography.bodySmall,
                                        color = MaterialTheme.colorScheme.onSurfaceVariant
                                    )
                                }
                                IconButton(onClick = {
                                    scope.launch {
                                        runCatching { repo.deleteNotification(item.id) }
                                        items = items.filterNot { it.id == item.id }
                                        container.pushManager.refreshUnreadCount()
                                    }
                                }) {
                                    Icon(
                                        Icons.Rounded.Delete,
                                        contentDescription = "删除",
                                        tint = MaterialTheme.colorScheme.onSurfaceVariant
                                    )
                                }
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
fun ParentChildAttendanceScreen(
    initialStudentId: Int? = null,
    onBack: (() -> Unit)? = null
) {
    val repo = SmartCampusApp.container.repository
    val scope = rememberCoroutineScope()

    var children by remember { mutableStateOf<List<ParentChildSummary>>(emptyList()) }
    var selectedChildId by remember(initialStudentId) { mutableStateOf(initialStudentId) }
    var periodScope by remember { mutableStateOf("month") }
    var detailTab by remember { mutableIntStateOf(0) } // 0 = 考勤标记, 1 = 请假申请
    var overview by remember { mutableStateOf<ParentChildOverview?>(null) }
    var records by remember { mutableStateOf<List<ParentRecordItem>>(emptyList()) }
    var leaveRequests by remember { mutableStateOf<List<ParentLeaveRequestItem>>(emptyList()) }
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<String?>(null) }

    val scopes = listOf("today" to "今日", "week" to "本周", "month" to "本月", "semester" to "本学期")

    fun loadChildDetails(childId: Int, sc: String) {
        scope.launch {
            loading = true
            error = null
            runCatching {
                overview = repo.fetchParentChildOverview(childId, sc)
                records = repo.fetchParentChildRecords(childId, sc)
                leaveRequests = repo.fetchParentChildLeaveRequests(childId)
            }.onFailure {
                error = it.localizedMessage ?: "加载孩子考勤失败"
            }
            loading = false
        }
    }

    fun loadAll() {
        scope.launch {
            loading = true
            error = null
            runCatching {
                val list = repo.fetchParentChildren()
                children = list
                val targetId = when {
                    initialStudentId != null && list.any { it.id == initialStudentId } -> initialStudentId
                    selectedChildId != null && list.any { it.id == selectedChildId } -> selectedChildId
                    else -> list.firstOrNull()?.id
                }
                selectedChildId = targetId
                if (targetId != null) {
                    overview = repo.fetchParentChildOverview(targetId, periodScope)
                    records = repo.fetchParentChildRecords(targetId, periodScope)
                    leaveRequests = repo.fetchParentChildLeaveRequests(targetId)
                }
            }.onFailure {
                error = it.localizedMessage ?: "加载失败"
            }
            loading = false
        }
    }

    LaunchedEffect(initialStudentId) { loadAll() }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("孩子考勤与请假") },
                navigationIcon = {
                    if (onBack != null) {
                        IconButton(onClick = onBack) {
                            Icon(Icons.AutoMirrored.Rounded.ArrowBack, contentDescription = "返回")
                        }
                    }
                },
                actions = {
                    IconButton(onClick = { loadAll() }) {
                        Icon(Icons.Rounded.Refresh, contentDescription = "刷新")
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

            if (children.size > 1) {
                Row(
                    modifier = Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()),
                    horizontalArrangement = Arrangement.spacedBy(8.dp)
                ) {
                    children.forEach { child ->
                        ChildFilterChip(
                            title = child.name,
                            subtitle = child.className ?: "-",
                            selected = child.id == selectedChildId,
                            onClick = {
                                selectedChildId = child.id
                                loadChildDetails(child.id, periodScope)
                            }
                        )
                    }
                }
            }

            val activeChild = children.firstOrNull { it.id == selectedChildId }
            if (activeChild != null) {
                SCCard {
                    Row(
                        verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.spacedBy(12.dp)
                    ) {
                        Box(
                            modifier = Modifier
                                .size(48.dp)
                                .clip(CircleShape)
                                .background(MaterialTheme.colorScheme.primaryContainer),
                            contentAlignment = Alignment.Center
                        ) {
                            Text(
                                activeChild.name.take(1),
                                style = MaterialTheme.typography.titleMedium,
                                fontWeight = FontWeight.Bold,
                                color = MaterialTheme.colorScheme.primary
                            )
                        }
                        Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
                            Row(
                                verticalAlignment = Alignment.CenterVertically,
                                horizontalArrangement = Arrangement.spacedBy(6.dp)
                            ) {
                                Text(activeChild.name, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                                if (activeChild.isBoarding) StatusBadge("住宿生", Color(0xFF9333EA))
                                if (activeChild.isGraduated) StatusBadge("已毕业", Color(0xFF64748B))
                            }
                            Text(
                                "${activeChild.className ?: "-"} · 学号 ${activeChild.studentNo ?: "-"}",
                                style = MaterialTheme.typography.bodySmall,
                                color = MaterialTheme.colorScheme.onSurfaceVariant
                            )
                            activeChild.teacherName?.takeIf { it.isNotBlank() && it != "-" }?.let { t ->
                                Text("班主任：$t", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                            }
                        }
                    }
                }
            } else if (!loading) {
                SCCard {
                    Text(
                        "暂未关联到在校学生，请联系班主任核实学生档案中的家长邮箱。",
                        style = MaterialTheme.typography.bodyMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant
                    )
                }
            }

            if (selectedChildId != null) {
                SingleChoiceSegmentedButtonRow(modifier = Modifier.fillMaxWidth()) {
                    scopes.forEachIndexed { idx, (key, label) ->
                        SegmentedButton(
                            selected = periodScope == key,
                            onClick = {
                                periodScope = key
                                selectedChildId?.let { loadChildDetails(it, key) }
                            },
                            shape = SegmentedButtonDefaults.itemShape(idx, scopes.size)
                        ) { Text(label, style = MaterialTheme.typography.labelSmall) }
                    }
                }

                val ov = overview
                if (ov != null) {
                    val metricCards = buildList {
                        add(
                            Triple(
                                if (periodScope == "today") (if (ov.stats.isRestDay) "今日休息" else "今日出勤") else "正常出勤",
                                if (periodScope == "today") {
                                    if (ov.stats.isRestDay) "休息" else if (ov.stats.present >= 1) "正常" else "异常"
                                } else "${ov.stats.present}/${ov.stats.workingDays}天",
                                Color(0xFF16A34A)
                            )
                        )
                        if (ov.pendingRequests > 0) {
                            add(Triple("待审请假", "${ov.pendingRequests}条", Color(0xFFEA580C)))
                        }
                        ov.leaveTypes.forEach { lt ->
                            val cnt = ov.stats.leaveCounts[lt.slug] ?: 0
                            val unit = ov.stats.displayUnits[lt.slug] ?: lt.displayUnit
                            add(Triple(lt.name, "$cnt$unit", statusColor(lt.resolvedColorKey)))
                        }
                    }
                    metricCards.chunked(2).forEach { pair ->
                        Row(modifier = Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                            pair.forEach { (title, value, color) ->
                                StatMetricCard(
                                    title = title,
                                    value = value,
                                    icon = if (title.contains("出勤")) Icons.Rounded.CheckCircle else if (title.contains("待审")) Icons.Rounded.Schedule else Icons.Rounded.Description,
                                    color = color,
                                    modifier = Modifier.weight(1f)
                                )
                            }
                            if (pair.size == 1) Spacer(Modifier.weight(1f))
                        }
                    }
                } else if (loading) {
                    Box(modifier = Modifier.fillMaxWidth().padding(vertical = 24.dp), contentAlignment = Alignment.Center) {
                        CircularProgressIndicator()
                    }
                }

                SingleChoiceSegmentedButtonRow(modifier = Modifier.fillMaxWidth()) {
                    listOf("考勤标记 (${records.size})", "请假申请 (${leaveRequests.size})").forEachIndexed { idx, label ->
                        SegmentedButton(
                            selected = detailTab == idx,
                            onClick = { detailTab = idx },
                            shape = SegmentedButtonDefaults.itemShape(idx, 2)
                        ) { Text(label) }
                    }
                }

                if (detailTab == 0) {
                    SCCard {
                        Text("考勤与异常标记明细", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold)
                        Spacer(Modifier.height(8.dp))
                        if (records.isEmpty()) {
                            Text("当前周期内无异常考勤标记", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                        } else {
                            records.forEachIndexed { idx, rec ->
                                Column(modifier = Modifier.fillMaxWidth().padding(vertical = 6.dp), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                        StatusBadge(rec.typeName, statusColor(rec.color ?: rec.status))
                                        Text(rec.date, style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold)
                                        rec.recordTime?.takeIf { it.isNotBlank() }?.let {
                                            Text(it, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                                        }
                                        Spacer(Modifier.weight(1f))
                                        Text(rec.sourceDisplayText, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                                    }
                                    rec.detailLabel?.takeIf { it.isNotBlank() }?.let {
                                        Text(it, style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                                    }
                                    (rec.reason ?: rec.note)?.takeIf { it.isNotBlank() }?.let {
                                        Text("备注：$it", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                                    }
                                }
                                if (idx != records.lastIndex) HorizontalDivider()
                            }
                        }
                    }
                } else {
                    SCCard {
                        Text("孩子请假申请记录", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold)
                        Spacer(Modifier.height(8.dp))
                        if (leaveRequests.isEmpty()) {
                            Text("暂无自主请假申请记录", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                        } else {
                            leaveRequests.forEachIndexed { idx, req ->
                                Column(modifier = Modifier.fillMaxWidth().padding(vertical = 6.dp), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                        Text(req.typeName, style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                                        StatusBadge(req.statusDisplayText, statusColor(req.status))
                                        Spacer(Modifier.weight(1f))
                                        Text(req.dateRangeText, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                                    }
                                    req.periodLabel?.takeIf { it.isNotBlank() }?.let {
                                        Text("时段：$it", style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                                    }
                                    req.eveningStudyLabel?.takeIf { it.isNotBlank() }?.let { es ->
                                        val dest = req.destination?.takeIf { it.isNotBlank() }?.let { "（去向：$it）" }.orEmpty()
                                        Text("夜自习：$es$dest", style = MaterialTheme.typography.labelMedium, color = Color(0xFF9333EA))
                                    }
                                    req.reason?.takeIf { it.isNotBlank() }?.let {
                                        Text("原因：$it", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                                    }
                                    req.createdAt?.takeIf { it.isNotBlank() }?.let { created ->
                                        Text(
                                            "申请时间：${SCDateFormatter.displayDateTime(created)}",
                                            style = MaterialTheme.typography.labelSmall,
                                            color = MaterialTheme.colorScheme.onSurfaceVariant
                                        )
                                    }
                                    req.approverName?.takeIf { it.isNotBlank() }?.let { approver ->
                                        val approvedTime = req.approvedAt?.takeIf { it.isNotBlank() }?.let { " · ${SCDateFormatter.displayDateTime(it)}" }.orEmpty()
                                        val rej = req.rejectionReason?.takeIf { it.isNotBlank() }?.let { " · 驳回理由：$it" }.orEmpty()
                                        Text("审批人：$approver$approvedTime$rej", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                                    }
                                }
                                if (idx != leaveRequests.lastIndex) HorizontalDivider()
                            }
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun ChildFilterChip(
    title: String,
    subtitle: String,
    badgeCount: Int = 0,
    selected: Boolean,
    onClick: () -> Unit
) {
    Surface(
        shape = RoundedCornerShape(12.dp),
        color = if (selected) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.surface,
        shadowElevation = 1.dp,
        modifier = Modifier.clickable(onClick = onClick)
    ) {
        Row(
            modifier = Modifier.padding(horizontal = 12.dp, vertical = 8.dp),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(8.dp)
        ) {
            Column {
                Text(
                    title,
                    style = MaterialTheme.typography.labelLarge,
                    fontWeight = FontWeight.SemiBold,
                    color = if (selected) Color.White else MaterialTheme.colorScheme.onSurface
                )
                Text(
                    subtitle,
                    style = MaterialTheme.typography.labelSmall,
                    color = if (selected) Color.White.copy(alpha = 0.85f) else MaterialTheme.colorScheme.onSurfaceVariant
                )
            }
            if (badgeCount > 0) {
                Surface(
                    shape = CircleShape,
                    color = if (selected) Color.White else Color(0xFFDC2626)
                ) {
                    Text(
                        badgeCount.toString(),
                        style = MaterialTheme.typography.labelSmall,
                        fontWeight = FontWeight.Bold,
                        color = if (selected) MaterialTheme.colorScheme.primary else Color.White,
                        modifier = Modifier.padding(horizontal = 6.dp, vertical = 2.dp)
                    )
                }
            }
        }
    }
}

private fun parentEventBadge(type: String): Pair<String, Color> = when (type) {
    "parent_leave_submitted" -> "请假申请" to Color(0xFF2563EB)
    "parent_leave_approved" -> "审批通过" to Color(0xFF16A34A)
    "parent_leave_rejected" -> "审批驳回" to Color(0xFFDC2626)
    "parent_attendance_marked" -> "考勤标记" to Color(0xFFEA580C)
    "parent_evening_study_alert" -> "夜自习/住宿" to Color(0xFF9333EA)
    else -> "消息提醒" to Color(0xFF64748B)
}
