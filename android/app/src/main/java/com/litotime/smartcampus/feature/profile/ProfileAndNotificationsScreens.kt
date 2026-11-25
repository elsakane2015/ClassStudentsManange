package com.litotime.smartcampus.feature.profile

import android.Manifest
import android.os.Build
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
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
import androidx.compose.material.icons.automirrored.rounded.Logout
import androidx.compose.material.icons.rounded.AccountBalance
import androidx.compose.material.icons.rounded.ChevronRight
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.Key
import androidx.compose.material.icons.rounded.Notifications
import androidx.compose.material.icons.rounded.PhoneAndroid
import androidx.compose.material.icons.rounded.Refresh
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Switch
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
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.litotime.smartcampus.BuildConfig
import com.litotime.smartcampus.SmartCampusApp
import com.litotime.smartcampus.core.designsystem.ErrorBanner
import com.litotime.smartcampus.core.designsystem.SCCard
import com.litotime.smartcampus.core.designsystem.StatusBadge
import com.litotime.smartcampus.core.util.SCDateFormatter
import com.litotime.smartcampus.data.model.AppNotificationItem
import com.litotime.smartcampus.data.model.MobileDeviceItem
import com.litotime.smartcampus.data.model.TeacherWechatConfigState
import com.litotime.smartcampus.data.model.User
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ProfileScreen(
    user: User,
    onOpenNotifications: () -> Unit,
    onOpenServerSetup: () -> Unit,
    onOpenParentPortal: ((Int?) -> Unit)? = null
) {
    val container = SmartCampusApp.container
    val schoolName by container.serverConfig.activeSchoolName.collectAsStateWithLifecycle()
    val activeUrl by container.serverConfig.activeBaseUrl.collectAsStateWithLifecycle()
    val unreadCount by container.pushManager.unreadCount.collectAsStateWithLifecycle()
    val gatewayConfigured by container.pushManager.pushGatewayConfigured.collectAsStateWithLifecycle()
    val scope = rememberCoroutineScope()

    var hasNotifPerm by remember { mutableStateOf(container.pushManager.hasNotificationPermission()) }
    var devices by remember { mutableStateOf<List<MobileDeviceItem>>(emptyList()) }
    var isSyncingPush by remember { mutableStateOf(false) }
    var pushSyncFeedback by remember { mutableStateOf<String?>(null) }
    var wechatState by remember { mutableStateOf<TeacherWechatConfigState?>(null) }
    var wechatPushEnabled by remember { mutableStateOf(true) }
    var togglingWechatPush by remember { mutableStateOf(false) }
    var bindingPushStates by remember { mutableStateOf<Map<Int, Boolean>>(emptyMap()) }
    var togglingBindingIds by remember { mutableStateOf<Set<Int>>(emptySet()) }
    var wechatFeedback by remember { mutableStateOf<String?>(null) }
    var wechatFeedbackIsError by remember { mutableStateOf(false) }
    var showChangePassword by remember { mutableStateOf(false) }
    var showLogoutDialog by remember { mutableStateOf(false) }

    val permissionLauncher = rememberLauncherForActivityResult(
        ActivityResultContracts.RequestPermission()
    ) { granted ->
        hasNotifPerm = granted || container.pushManager.hasNotificationPermission()
        scope.launch {
            container.pushManager.syncDeviceWithBackend()
            devices = runCatching { container.repository.fetchDevices() }.getOrDefault(devices)
        }
    }

    LaunchedEffect(Unit) {
        hasNotifPerm = container.pushManager.hasNotificationPermission()
        container.pushManager.refreshUnreadCount()
        devices = runCatching { container.repository.fetchDevices() }.getOrDefault(emptyList())
        if (user.role == "teacher") {
            runCatching { container.repository.fetchTeacherWechatConfig() }.getOrNull()?.let { st ->
                wechatState = st
                st.config?.let { wechatPushEnabled = it.pushEnabled }
                bindingPushStates = st.bindings.associate { it.id to it.pushEnabled }
            }
        }
    }

    Scaffold(
        topBar = { TopAppBar(title = { Text("我的") }) }
    ) { padding ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(padding)
                .verticalScroll(rememberScrollState())
                .padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(14.dp)
        ) {
            // User Header Card
            SCCard {
                Row(
                    verticalAlignment = Alignment.CenterVertically,
                    horizontalArrangement = Arrangement.spacedBy(14.dp)
                ) {
                    Box(
                        modifier = Modifier
                            .size(56.dp)
                            .clip(CircleShape)
                            .background(MaterialTheme.colorScheme.primaryContainer),
                        contentAlignment = Alignment.Center
                    ) {
                        Text(
                            user.name.take(1),
                            style = MaterialTheme.typography.titleLarge,
                            fontWeight = FontWeight.Bold,
                            color = MaterialTheme.colorScheme.primary
                        )
                    }
                    Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
                        Text(user.name, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                        Text(user.roleDisplayName, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                        val sub = user.student?.studentNo?.takeIf { it.isNotBlank() }?.let { "学号：$it" }
                            ?: user.email.orEmpty()
                        if (sub.isNotBlank()) {
                            Text(sub, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                        }
                    }
                }
            }

            // Linked Children Card (for pure parents AND staff members who are also parents)
            if (user.hasLinkedChildren && user.parentChildren.isNotEmpty()) {
                SCCard {
                    Text(
                        if (user.isParent) "已关联的孩子" else "我的孩子（家长视图）",
                        style = MaterialTheme.typography.titleSmall,
                        fontWeight = FontWeight.Bold
                    )
                    Spacer(Modifier.height(8.dp))
                    user.parentChildren.forEachIndexed { idx, child ->
                        Row(
                            modifier = Modifier
                                .fillMaxWidth()
                                .let { mod ->
                                    if (!user.isParent && onOpenParentPortal != null) {
                                        mod.clickable { onOpenParentPortal(child.id) }
                                    } else mod
                                }
                                .padding(vertical = 8.dp),
                            verticalAlignment = Alignment.CenterVertically,
                            horizontalArrangement = Arrangement.spacedBy(10.dp)
                        ) {
                            Column(modifier = Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                                Text(child.name, style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                                Text(
                                    "${child.className ?: "-"} · 学号 ${child.studentNo ?: "-"} · 班主任 ${child.teacherName ?: "-"}",
                                    style = MaterialTheme.typography.labelSmall,
                                    color = MaterialTheme.colorScheme.onSurfaceVariant
                                )
                            }
                            if (child.isBoarding) {
                                StatusBadge("住宿", Color(0xFF9333EA))
                            }
                            if (!user.isParent && onOpenParentPortal != null) {
                                Icon(Icons.Rounded.ChevronRight, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant)
                            }
                        }
                        if (idx != user.parentChildren.lastIndex) HorizontalDivider()
                    }
                }
            }

            // Notifications & Push Card
            SCCard {
                Text("消息与推送", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold)
                Spacer(Modifier.height(8.dp))

                Row(
                    modifier = Modifier
                        .fillMaxWidth()
                        .clickable(onClick = onOpenNotifications)
                        .padding(vertical = 10.dp),
                    verticalAlignment = Alignment.CenterVertically
                ) {
                    Icon(Icons.Rounded.Notifications, contentDescription = null, tint = MaterialTheme.colorScheme.primary)
                    Spacer(Modifier.width(12.dp))
                    Text("消息通知中心", modifier = Modifier.weight(1f), style = MaterialTheme.typography.bodyMedium)
                    if (unreadCount > 0) {
                        Surface(shape = CircleShape, color = Color(0xFFDC2626)) {
                            Text(
                                unreadCount.toString(),
                                color = Color.White,
                                style = MaterialTheme.typography.labelSmall,
                                modifier = Modifier.padding(horizontal = 8.dp, vertical = 2.dp)
                            )
                        }
                        Spacer(Modifier.width(6.dp))
                    }
                    Icon(Icons.Rounded.ChevronRight, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant)
                }

                HorizontalDivider()

                Row(
                    modifier = Modifier.fillMaxWidth().padding(vertical = 10.dp),
                    verticalAlignment = Alignment.CenterVertically
                ) {
                    Text("系统通知权限", modifier = Modifier.weight(1f), style = MaterialTheme.typography.bodyMedium)
                    StatusBadge(
                        text = if (hasNotifPerm) "已开启" else "未授权",
                        color = if (hasNotifPerm) Color(0xFF16A34A) else Color(0xFFEA580C)
                    )
                }

                Row(
                    modifier = Modifier.fillMaxWidth().padding(vertical = 6.dp),
                    verticalAlignment = Alignment.CenterVertically
                ) {
                    Text("推送通道状态", modifier = Modifier.weight(1f), style = MaterialTheme.typography.bodyMedium)
                    Text(
                        if (gatewayConfigured) "系统推送已就绪" else "站内消息模式（待配推送证书）",
                        style = MaterialTheme.typography.labelMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant
                    )
                }

                Spacer(Modifier.height(6.dp))
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    if (!hasNotifPerm && Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
                        OutlinedButton(onClick = { permissionLauncher.launch(Manifest.permission.POST_NOTIFICATIONS) }) {
                            Text("申请通知权限")
                        }
                    }
                    OutlinedButton(
                        enabled = !isSyncingPush,
                        onClick = {
                            scope.launch {
                                isSyncingPush = true
                                pushSyncFeedback = null
                                hasNotifPerm = container.pushManager.hasNotificationPermission()
                                container.pushManager.syncDeviceWithBackend()
                                container.pushManager.refreshUnreadCount()
                                devices = runCatching { container.repository.fetchDevices() }.getOrDefault(devices)
                                isSyncingPush = false
                                pushSyncFeedback = if (gatewayConfigured && devices.any { !it.pushToken.isNullOrBlank() }) {
                                    "已同步 · 锁屏推送已就绪"
                                } else {
                                    "已同步 · 当前为仅站内通知模式"
                                }
                                delay(3000)
                                pushSyncFeedback = null
                            }
                        }
                    ) {
                        if (isSyncingPush) {
                            CircularProgressIndicator(modifier = Modifier.size(14.dp), strokeWidth = 2.dp)
                            Spacer(Modifier.width(6.dp))
                            Text("正在同步...")
                        } else {
                            Icon(Icons.Rounded.Refresh, contentDescription = null, modifier = Modifier.size(16.dp))
                            Spacer(Modifier.width(6.dp))
                            Text("同步设备状态")
                        }
                    }
                }
                pushSyncFeedback?.let { feedback ->
                    Spacer(Modifier.height(6.dp))
                    Text(
                        text = feedback,
                        style = MaterialTheme.typography.labelMedium,
                        color = Color(0xFF16A34A),
                        fontWeight = FontWeight.Medium
                    )
                }
            }

            // WeChat Official Account Push Switch (Teacher Only)
            val wc = wechatState
            if (user.role == "teacher" && wc != null && wc.isAvailable) {
                SCCard {
                    Text("微信公众号推送", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold)
                    Spacer(Modifier.height(8.dp))
                    val cfg = wc.config
                    if (cfg != null) {
                        Row(
                            modifier = Modifier.fillMaxWidth(),
                            verticalAlignment = Alignment.CenterVertically,
                            horizontalArrangement = Arrangement.spacedBy(10.dp)
                        ) {
                            Column(modifier = Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                                Text("启用公众号推送", style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.Medium)
                                Text(
                                    if (wechatPushEnabled) {
                                        "学生提交请假时，自动推送微信模板消息"
                                    } else {
                                        "已暂停微信推送（保留原配置与绑定，随时可开启）"
                                    },
                                    style = MaterialTheme.typography.labelSmall,
                                    color = MaterialTheme.colorScheme.onSurfaceVariant
                                )
                            }
                            if (togglingWechatPush) {
                                CircularProgressIndicator(modifier = Modifier.size(18.dp), strokeWidth = 2.dp)
                            }
                            Switch(
                                checked = wechatPushEnabled,
                                enabled = !togglingWechatPush,
                                onCheckedChange = { nextEnabled ->
                                    if (togglingWechatPush) return@Switch
                                    val previous = wechatPushEnabled
                                    wechatPushEnabled = nextEnabled
                                    togglingWechatPush = true
                                    wechatFeedback = null
                                    wechatFeedbackIsError = false
                                    scope.launch {
                                        runCatching {
                                            container.repository.toggleTeacherWechatPush(nextEnabled)
                                        }.onSuccess { (finalState, msg) ->
                                            wechatPushEnabled = finalState
                                            wechatFeedback = msg
                                            wechatFeedbackIsError = false
                                            togglingWechatPush = false
                                            delay(2500)
                                            if (wechatFeedback == msg) wechatFeedback = null
                                        }.onFailure { err ->
                                            wechatPushEnabled = previous
                                            wechatFeedbackIsError = true
                                            wechatFeedback = "切换失败：${err.localizedMessage ?: "请稍后重试"}"
                                            togglingWechatPush = false
                                        }
                                    }
                                }
                            )
                        }
                        Spacer(Modifier.height(8.dp))
                        HorizontalDivider()
                        Spacer(Modifier.height(8.dp))
                        Row(
                            modifier = Modifier.fillMaxWidth(),
                            verticalAlignment = Alignment.CenterVertically,
                            horizontalArrangement = Arrangement.spacedBy(8.dp)
                        ) {
                            Text(
                                if (cfg.useSystem) "系统公众号" else "自定义测试公众号",
                                style = MaterialTheme.typography.labelMedium,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                                modifier = Modifier.weight(1f)
                            )
                            StatusBadge(
                                text = if (cfg.isVerified) "已验证" else "未验证",
                                color = if (cfg.isVerified) Color(0xFF16A34A) else Color(0xFFEA580C)
                            )
                            Text(
                                "已绑定 ${wc.bindingCount} 人",
                                style = MaterialTheme.typography.labelMedium,
                                color = MaterialTheme.colorScheme.onSurfaceVariant
                            )
                        }

                        if (wc.bindings.isNotEmpty()) {
                            Spacer(Modifier.height(8.dp))
                            HorizontalDivider()
                            Spacer(Modifier.height(6.dp))
                            Text(
                                if (wechatPushEnabled) {
                                    "已绑定用户独立开关（${wc.bindingCount}人）"
                                } else {
                                    "已绑定用户独立开关（总开关关闭时暂不推送）"
                                },
                                style = MaterialTheme.typography.labelSmall,
                                color = MaterialTheme.colorScheme.onSurfaceVariant
                            )
                            Spacer(Modifier.height(4.dp))
                            wc.bindings.forEach { binding ->
                                val bEnabled = bindingPushStates[binding.id] ?: binding.pushEnabled
                                val bBusy = binding.id in togglingBindingIds
                                Row(
                                    modifier = Modifier.fillMaxWidth().padding(vertical = 4.dp),
                                    verticalAlignment = Alignment.CenterVertically,
                                    horizontalArrangement = Arrangement.spacedBy(8.dp)
                                ) {
                                    Column(modifier = Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                                        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                                            Text(binding.userName, style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.Medium)
                                            if (binding.roleDisplayText.isNotEmpty()) {
                                                Surface(shape = RoundedCornerShape(999.dp), color = MaterialTheme.colorScheme.surfaceVariant) {
                                                    Text(
                                                        binding.roleDisplayText,
                                                        style = MaterialTheme.typography.labelSmall,
                                                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                                                        modifier = Modifier.padding(horizontal = 6.dp, vertical = 1.dp)
                                                    )
                                                }
                                            }
                                        }
                                        val subParts = buildList {
                                            binding.nickname?.takeIf { it.isNotBlank() && it != "-" }?.let { add(it) }
                                            if (bEnabled && !wechatPushEnabled) add("待总开关开启")
                                        }
                                        if (subParts.isNotEmpty()) {
                                            Text(
                                                subParts.joinToString(" · "),
                                                style = MaterialTheme.typography.labelSmall,
                                                color = if (bEnabled && !wechatPushEnabled) Color(0xFFEA580C) else MaterialTheme.colorScheme.onSurfaceVariant
                                            )
                                        }
                                    }
                                    if (bBusy) {
                                        CircularProgressIndicator(modifier = Modifier.size(16.dp), strokeWidth = 2.dp)
                                    }
                                    Switch(
                                        checked = bEnabled,
                                        enabled = !bBusy,
                                        onCheckedChange = { nextEnabled ->
                                            if (binding.id in togglingBindingIds) return@Switch
                                            val previous = bEnabled
                                            bindingPushStates = bindingPushStates + (binding.id to nextEnabled)
                                            togglingBindingIds = togglingBindingIds + binding.id
                                            wechatFeedback = null
                                            wechatFeedbackIsError = false
                                            scope.launch {
                                                runCatching {
                                                    container.repository.toggleTeacherWechatBindingPush(binding.id, nextEnabled)
                                                }.onSuccess { (finalState, _) ->
                                                    bindingPushStates = bindingPushStates + (binding.id to finalState)
                                                    val msg = "${binding.userName}：${if (finalState) "已开启微信推送" else "已关闭微信推送"}"
                                                    wechatFeedback = msg
                                                    wechatFeedbackIsError = false
                                                    togglingBindingIds = togglingBindingIds - binding.id
                                                    delay(2500)
                                                    if (wechatFeedback == msg) wechatFeedback = null
                                                }.onFailure { err ->
                                                    bindingPushStates = bindingPushStates + (binding.id to previous)
                                                    wechatFeedbackIsError = true
                                                    wechatFeedback = "切换失败：${err.localizedMessage ?: "请稍后重试"}"
                                                    togglingBindingIds = togglingBindingIds - binding.id
                                                }
                                            }
                                        }
                                    )
                                }
                            }
                        }

                        wechatFeedback?.let { fb ->
                            Spacer(Modifier.height(6.dp))
                            Text(
                                text = fb,
                                style = MaterialTheme.typography.labelMedium,
                                color = if (wechatFeedbackIsError) Color(0xFFDC2626) else Color(0xFF16A34A),
                                fontWeight = FontWeight.Medium
                            )
                        }
                    } else {
                        Row(
                            modifier = Modifier.fillMaxWidth(),
                            verticalAlignment = Alignment.CenterVertically
                        ) {
                            Text("配置状态", modifier = Modifier.weight(1f), style = MaterialTheme.typography.bodyMedium)
                            Text("未配置", style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                        }
                        Spacer(Modifier.height(4.dp))
                        Text(
                            "请在网页端「微信推送」完成配置并验证后，即可在此随时开启或关闭公众号推送。",
                            style = MaterialTheme.typography.labelSmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant
                        )
                    }
                }
            }

            // Server & Security Card
            SCCard {
                Text("学校服务器与安全", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold)
                Spacer(Modifier.height(8.dp))

                Row(
                    modifier = Modifier
                        .fillMaxWidth()
                        .clickable(onClick = onOpenServerSetup)
                        .padding(vertical = 10.dp),
                    verticalAlignment = Alignment.CenterVertically
                ) {
                    Icon(Icons.Rounded.AccountBalance, contentDescription = null, tint = MaterialTheme.colorScheme.primary)
                    Spacer(Modifier.width(12.dp))
                    Column(modifier = Modifier.weight(1f)) {
                        Text(schoolName, style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.Medium)
                        Text(
                            activeUrl.orEmpty(),
                            style = MaterialTheme.typography.labelSmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                            maxLines = 1,
                            overflow = TextOverflow.Ellipsis
                        )
                    }
                    Icon(Icons.Rounded.ChevronRight, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant)
                }

                HorizontalDivider()

                Row(
                    modifier = Modifier
                        .fillMaxWidth()
                        .clickable { showChangePassword = true }
                        .padding(vertical = 12.dp),
                    verticalAlignment = Alignment.CenterVertically
                ) {
                    Icon(Icons.Rounded.Key, contentDescription = null, tint = MaterialTheme.colorScheme.primary)
                    Spacer(Modifier.width(12.dp))
                    Text("修改密码", modifier = Modifier.weight(1f), style = MaterialTheme.typography.bodyMedium)
                    Icon(Icons.Rounded.ChevronRight, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant)
                }
            }

            // Devices List
            if (devices.isNotEmpty()) {
                SCCard {
                    Text("当前账号已登录设备", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold)
                    Spacer(Modifier.height(8.dp))
                    devices.forEach { d ->
                        Row(
                            modifier = Modifier.fillMaxWidth().padding(vertical = 6.dp),
                            verticalAlignment = Alignment.CenterVertically,
                            horizontalArrangement = Arrangement.spacedBy(10.dp)
                        ) {
                            Icon(Icons.Rounded.PhoneAndroid, contentDescription = null, tint = MaterialTheme.colorScheme.primary)
                            Column(modifier = Modifier.weight(1f)) {
                                Text(d.deviceName ?: d.platform.uppercase(), style = MaterialTheme.typography.bodyMedium)
                                Text(
                                    "${d.osVersion ?: d.platform} · ${SCDateFormatter.displayDateTime(d.lastActiveAt)}",
                                    style = MaterialTheme.typography.labelSmall,
                                    color = MaterialTheme.colorScheme.onSurfaceVariant
                                )
                            }
                            StatusBadge(
                                text = if (!d.pushToken.isNullOrBlank()) "锁屏推送" else "仅站内通知",
                                color = if (!d.pushToken.isNullOrBlank()) Color(0xFF16A34A) else Color(0xFF2563EB)
                            )
                        }
                    }
                }
            }

            Button(
                onClick = { showLogoutDialog = true },
                colors = ButtonDefaults.buttonColors(containerColor = Color(0xFFDC2626)),
                modifier = Modifier.fillMaxWidth().height(48.dp),
                shape = RoundedCornerShape(12.dp)
            ) {
                Icon(Icons.AutoMirrored.Rounded.Logout, contentDescription = null)
                Spacer(Modifier.width(8.dp))
                Text("退出登录", fontWeight = FontWeight.SemiBold)
            }

            Text(
                text = "智慧校园 ${BuildConfig.VERSION_NAME}(${BuildConfig.VERSION_CODE})",
                style = MaterialTheme.typography.labelMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                textAlign = TextAlign.Center,
                modifier = Modifier.fillMaxWidth().padding(top = 6.dp, bottom = 12.dp)
            )
        }
    }

    if (showChangePassword) {
        ChangePasswordDialog(onDismiss = { showChangePassword = false })
    }

    if (showLogoutDialog) {
        AlertDialog(
            onDismissRequest = { showLogoutDialog = false },
            title = { Text("确认退出登录？") },
            text = { Text("退出后将解绑当前手机的推送通知，需要重新登录。") },
            confirmButton = {
                TextButton(onClick = {
                    showLogoutDialog = false
                    scope.launch { container.sessionManager.logout() }
                }) {
                    Text("退出登录", color = Color(0xFFDC2626))
                }
            },
            dismissButton = {
                TextButton(onClick = { showLogoutDialog = false }) { Text("取消") }
            }
        )
    }
}

@Composable
private fun ChangePasswordDialog(onDismiss: () -> Unit) {
    val scope = rememberCoroutineScope()
    var current by remember { mutableStateOf("") }
    var next by remember { mutableStateOf("") }
    var confirm by remember { mutableStateOf("") }
    var loading by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("修改密码") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                error?.let { Text(it, color = Color(0xFFDC2626), style = MaterialTheme.typography.bodySmall) }
                OutlinedTextField(
                    value = current,
                    onValueChange = { current = it },
                    label = { Text("当前密码") },
                    visualTransformation = PasswordVisualTransformation(),
                    singleLine = true
                )
                OutlinedTextField(
                    value = next,
                    onValueChange = { next = it },
                    label = { Text("新密码（至少6位）") },
                    visualTransformation = PasswordVisualTransformation(),
                    singleLine = true
                )
                OutlinedTextField(
                    value = confirm,
                    onValueChange = { confirm = it },
                    label = { Text("确认新密码") },
                    visualTransformation = PasswordVisualTransformation(),
                    singleLine = true
                )
            }
        },
        confirmButton = {
            TextButton(
                enabled = !loading && current.isNotEmpty() && next.length >= 6 && next == confirm,
                onClick = {
                    scope.launch {
                        loading = true
                        error = null
                        runCatching {
                            SmartCampusApp.container.repository.changePassword(current, next, confirm)
                            onDismiss()
                        }.onFailure {
                            error = it.localizedMessage ?: "修改失败"
                        }
                        loading = false
                    }
                }
            ) {
                Text(if (loading) "保存中..." else "保存")
            }
        },
        dismissButton = {
            TextButton(onClick = onDismiss) { Text("取消") }
        }
    )
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun NotificationsScreen(onBack: () -> Unit) {
    val container = SmartCampusApp.container
    val scope = rememberCoroutineScope()
    var items by remember { mutableStateOf<List<AppNotificationItem>>(emptyList()) }
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<String?>(null) }

    fun load() {
        scope.launch {
            loading = true
            error = null
            runCatching {
                val page = container.repository.fetchNotifications()
                items = page.items
                container.pushManager.setUnreadCount(page.unreadCount)
            }.onFailure {
                error = it.localizedMessage ?: "加载失败"
            }
            loading = false
        }
    }

    LaunchedEffect(Unit) { load() }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("消息通知") },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Rounded.ArrowBack, contentDescription = "返回")
                    }
                },
                actions = {
                    if (items.any { it.isUnread }) {
                        TextButton(onClick = {
                            scope.launch {
                                runCatching { container.repository.markAllNotificationsRead() }
                                load()
                            }
                        }) {
                            Text("全部已读")
                        }
                    }
                }
            )
        }
    ) { padding ->
        Column(modifier = Modifier.fillMaxSize().padding(padding).padding(16.dp)) {
            error?.let {
                ErrorBanner(message = it, onRetry = { load() })
                Spacer(Modifier.height(12.dp))
            }

            if (loading && items.isEmpty()) {
                Box(modifier = Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator()
                }
            } else if (items.isEmpty()) {
                Box(modifier = Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                    Text("暂无通知消息", color = MaterialTheme.colorScheme.onSurfaceVariant)
                }
            } else {
                LazyColumn(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    items(items, key = { it.id }) { item ->
                        SCCard(onClick = {
                            scope.launch {
                                if (item.isUnread) {
                                    runCatching { container.repository.markNotificationRead(item.id) }
                                    container.pushManager.refreshUnreadCount()
                                }
                                container.pushManager.handleDeepLink(item.route, item.type, item.studentId)
                                onBack()
                            }
                        }) {
                            Row(verticalAlignment = Alignment.Top, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                                if (item.isUnread) {
                                    Box(
                                        modifier = Modifier
                                            .padding(top = 6.dp)
                                            .size(8.dp)
                                            .clip(CircleShape)
                                            .background(MaterialTheme.colorScheme.primary)
                                    )
                                }
                                Column(modifier = Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                                    Text(
                                        item.title,
                                        style = MaterialTheme.typography.titleSmall,
                                        fontWeight = if (item.isUnread) FontWeight.Bold else FontWeight.Medium
                                    )
                                    Text(item.body, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                                    Text(
                                        SCDateFormatter.displayDateTime(item.createdAt),
                                        style = MaterialTheme.typography.labelSmall,
                                        color = MaterialTheme.colorScheme.onSurfaceVariant.copy(alpha = 0.7f)
                                    )
                                }
                                IconButton(onClick = {
                                    scope.launch {
                                        runCatching { container.repository.deleteNotification(item.id) }
                                        items = items.filterNot { it.id == item.id }
                                        container.pushManager.refreshUnreadCount()
                                    }
                                }) {
                                    Icon(Icons.Rounded.Delete, contentDescription = "删除", tint = MaterialTheme.colorScheme.onSurfaceVariant)
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}
