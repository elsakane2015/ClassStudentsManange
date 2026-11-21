package com.litotime.smartcampus.core.auth

import android.os.Build
import com.litotime.smartcampus.core.config.ServerConfigManager
import com.litotime.smartcampus.core.network.ApiClient
import com.litotime.smartcampus.core.network.ApiException
import com.litotime.smartcampus.core.push.PushNotificationManager
import com.litotime.smartcampus.core.storage.AppPreferences
import com.litotime.smartcampus.core.util.asObjectOrNull
import com.litotime.smartcampus.core.util.str
import com.litotime.smartcampus.data.model.User
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put

class SessionManager(
    private val api: ApiClient,
    private val serverConfig: ServerConfigManager,
    private val prefs: AppPreferences,
    private val pushManager: PushNotificationManager
) {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Main.immediate)

    private val _currentUser = MutableStateFlow<User?>(null)
    val currentUser: StateFlow<User?> = _currentUser.asStateFlow()

    private val _isBootstrapping = MutableStateFlow(true)
    val isBootstrapping: StateFlow<Boolean> = _isBootstrapping.asStateFlow()

    init {
        api.onUnauthorized = {
            scope.launch { clearLocalSession() }
        }
    }

    suspend fun bootstrap() {
        _isBootstrapping.value = true
        try {
            serverConfig.loadInitial()
            val base = serverConfig.activeBaseUrl.value
            if (base.isNullOrBlank()) {
                _currentUser.value = null
                return
            }

            runCatching {
                val probed = serverConfig.probeServer(base)
                serverConfig.applyServer(probed.baseUrl, probed.schoolName)
            }

            val token = prefs.getToken()
            if (token.isNullOrBlank()) {
                _currentUser.value = null
                return
            }

            val meObj = runCatching { api.requestElement("/me").asObjectOrNull() }.getOrNull()
            if (meObj != null) {
                val user = User.fromJson(meObj)
                _currentUser.value = user
                pushManager.setUnreadCount(user.unreadNotificationsCount)
                scope.launch {
                    pushManager.syncDeviceWithBackend()
                    pushManager.refreshUnreadCount()
                }
            } else {
                prefs.clearToken()
                _currentUser.value = null
            }
        } finally {
            _isBootstrapping.value = false
        }
    }

    suspend fun login(account: String, password: String) {
        val body = buildJsonObject {
            put("email", account.trim())
            put("password", password)
            put("device_name", "Android - ${Build.MANUFACTURER} ${Build.MODEL}".trim())
        }
        val resObj = api.requestElement(
            path = "/auth/login",
            method = "POST",
            jsonBody = body.toString(),
            requiresAuth = false
        ).asObjectOrNull() ?: throw ApiException("登录返回数据异常")

        val token = resObj.str("access_token") ?: throw ApiException("未获取到登录凭证")
        prefs.saveToken(token)

        val meObj = api.requestElement("/me").asObjectOrNull()
            ?: throw ApiException("无法获取当前用户信息")
        val user = User.fromJson(meObj)
        _currentUser.value = user
        pushManager.setUnreadCount(user.unreadNotificationsCount)

        scope.launch {
            pushManager.syncDeviceWithBackend()
            pushManager.refreshUnreadCount()
        }
    }

    suspend fun logout() {
        pushManager.unregisterCurrentDevice()
        runCatching { api.requestRaw("/auth/logout", method = "POST") }
        clearLocalSession()
    }

    suspend fun switchServer(baseUrl: String, schoolName: String) {
        if (_currentUser.value != null) {
            logout()
        }
        serverConfig.applyServer(baseUrl, schoolName)
    }

    private suspend fun clearLocalSession() {
        prefs.clearToken()
        _currentUser.value = null
        pushManager.setUnreadCount(0)
        pushManager.consumePendingDestination()
    }
}
