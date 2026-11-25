package com.litotime.smartcampus.core.push

import android.Manifest
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import com.litotime.smartcampus.BuildConfig
import com.litotime.smartcampus.MainActivity
import com.litotime.smartcampus.R
import com.litotime.smartcampus.core.config.ServerConfigManager
import com.litotime.smartcampus.core.network.ApiClient
import com.litotime.smartcampus.core.storage.AppPreferences
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.booleanOrNull
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.intOrNull
import kotlinx.serialization.json.jsonPrimitive
import kotlinx.serialization.json.put
import java.util.Locale

enum class PushDestination {
    LEAVE_APPROVALS,
    LEAVE_HISTORY,
    STUDENT_DASHBOARD,
    NOTIFICATIONS,
    PARENT_FEED,
    PARENT_CHILD_ATTENDANCE,
    PARENT_CHILD_LEAVES
}

/**
 * Android 原生推送与通知中心终态管理器：
 * - 完整实现 Android 13+ 通知权限检测、NotificationChannel 创建、Deep Link Intent 跳转与后端设备注册/解绑；
 * - 预留 `onNewPushToken(token)` 供日后接入 Firebase FCM 或国内厂商推送 SDK 一行对接，当前免密钥可平滑运行。
 */
class PushNotificationManager(
    private val context: Context,
    private val apiClient: ApiClient,
    private val serverConfig: ServerConfigManager,
    private val prefs: AppPreferences
) {
    companion object {
        const val CHANNEL_ID = "smartcampus_general"
        const val EXTRA_ROUTE = "smartcampus_route"
        const val EXTRA_TYPE = "smartcampus_type"
        const val EXTRA_STUDENT_ID = "smartcampus_student_id"
    }

    private val _unreadCount = MutableStateFlow(0)
    val unreadCount: StateFlow<Int> = _unreadCount.asStateFlow()

    private val _pushGatewayConfigured = MutableStateFlow(false)
    val pushGatewayConfigured: StateFlow<Boolean> = _pushGatewayConfigured.asStateFlow()

    private val _pendingDestination = MutableStateFlow<PushDestination?>(null)
    val pendingDestination: StateFlow<PushDestination?> = _pendingDestination.asStateFlow()

    private val _pendingStudentId = MutableStateFlow<Int?>(null)
    val pendingStudentId: StateFlow<Int?> = _pendingStudentId.asStateFlow()

    fun ensureNotificationChannel() {
        val manager = context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        val channel = NotificationChannel(
            CHANNEL_ID,
            "校园考勤与请假提醒",
            NotificationManager.IMPORTANCE_HIGH
        ).apply {
            description = "接收学生请假待审批、审批结果及考勤异常提醒"
        }
        manager.createNotificationChannel(channel)
    }

    fun hasNotificationPermission(): Boolean {
        return if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            ContextCompat.checkSelfPermission(
                context,
                Manifest.permission.POST_NOTIFICATIONS
            ) == PackageManager.PERMISSION_GRANTED
        } else {
            NotificationManagerCompat.from(context).areNotificationsEnabled()
        }
    }

    fun setUnreadCount(count: Int) {
        _unreadCount.value = count.coerceAtLeast(0)
    }

    fun consumePendingDestination() {
        _pendingDestination.value = null
        _pendingStudentId.value = null
    }

    fun handleDeepLink(route: String?, type: String?, studentId: Int? = null) {
        _pendingStudentId.value = studentId
        _pendingDestination.value = when (route ?: type) {
            "parent_child_leaves", "parent_leave_submitted", "parent_leave_approved", "parent_leave_rejected" ->
                PushDestination.PARENT_CHILD_LEAVES
            "parent_child_attendance", "parent_attendance_marked", "parent_evening_study_alert" ->
                PushDestination.PARENT_CHILD_ATTENDANCE
            "leave_approvals", "leave_submitted" -> PushDestination.LEAVE_APPROVALS
            "leave_history", "leave_approved", "leave_rejected" -> PushDestination.LEAVE_HISTORY
            "student_dashboard", "attendance_alert" -> PushDestination.STUDENT_DASHBOARD
            else -> PushDestination.NOTIFICATIONS
        }
    }

    fun handleIntent(intent: Intent?) {
        if (intent == null) return
        val route = intent.getStringExtra(EXTRA_ROUTE)
        val type = intent.getStringExtra(EXTRA_TYPE)
        val sidRaw = intent.getIntExtra(EXTRA_STUDENT_ID, -1)
        val sid = if (sidRaw > 0) sidRaw else null
        if (!route.isNullOrBlank() || !type.isNullOrBlank()) {
            handleDeepLink(route, type, sid)
        }
    }

    /** 供 FCM / 厂商推送收到远程消息时直接唤起系统通知栏弹窗 */
    fun showSystemNotification(notificationId: Int, title: String, body: String, route: String?, type: String?) {
        if (!hasNotificationPermission()) return
        ensureNotificationChannel()

        val launchIntent = Intent(context, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_CLEAR_TOP
            putExtra(EXTRA_ROUTE, route)
            putExtra(EXTRA_TYPE, type)
        }
        val pendingIntent = PendingIntent.getActivity(
            context,
            notificationId,
            launchIntent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        val notification = NotificationCompat.Builder(context, CHANNEL_ID)
            .setSmallIcon(R.drawable.ic_launcher_foreground)
            .setContentTitle(title)
            .setContentText(body)
            .setStyle(NotificationCompat.BigTextStyle().bigText(body))
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setAutoCancel(true)
            .setContentIntent(pendingIntent)
            .build()

        runCatching {
            NotificationManagerCompat.from(context).notify(notificationId, notification)
        }
    }

    /** 日后接入 FCM / 厂商通道获取到推送 Token 时调用此方法即可自动完成上报 */
    suspend fun onNewPushToken(token: String) {
        prefs.savePushToken(token)
        syncDeviceWithBackend(pushTokenOverride = token)
    }

    suspend fun syncDeviceWithBackend(pushTokenOverride: String? = null) {
        if (!serverConfig.isConfigured || prefs.getToken().isNullOrBlank()) return

        val deviceId = prefs.getOrCreateDeviceIdentifier()
        val pushToken = pushTokenOverride ?: prefs.getPushToken()
        val payload = buildJsonObject {
            put("platform", "android")
            put("push_provider", "fcm")
            put("device_identifier", deviceId)
            if (!pushToken.isNullOrBlank()) put("push_token", pushToken)
            put("device_name", "${Build.MANUFACTURER} ${Build.MODEL}".trim())
            put("app_version", BuildConfig.VERSION_NAME)
            put("os_version", "Android ${Build.VERSION.RELEASE}")
            put("locale", Locale.getDefault().toLanguageTag())
            put("push_enabled", hasNotificationPermission())
        }

        runCatching {
            val res = apiClient.requestElement(
                path = "/devices/register",
                method = "POST",
                jsonBody = payload.toString()
            ) as? JsonObject
            _pushGatewayConfigured.value = res?.get("push_gateway_configured")?.jsonPrimitive?.booleanOrNull ?: false
        }
    }

    suspend fun unregisterCurrentDevice() {
        if (!serverConfig.isConfigured || prefs.getToken().isNullOrBlank()) return
        val deviceId = prefs.getOrCreateDeviceIdentifier()
        val payload = buildJsonObject {
            put("platform", "android")
            put("device_identifier", deviceId)
        }
        runCatching {
            apiClient.requestRaw(
                path = "/devices/unregister",
                method = "POST",
                jsonBody = payload.toString()
            )
        }
    }

    suspend fun refreshUnreadCount() {
        if (!serverConfig.isConfigured || prefs.getToken().isNullOrBlank()) {
            _unreadCount.value = 0
            return
        }
        runCatching {
            val res = apiClient.requestElement("/notifications/unread-count") as? JsonObject
            _unreadCount.value = res?.get("unread_count")?.jsonPrimitive?.intOrNull ?: 0
        }
    }
}
