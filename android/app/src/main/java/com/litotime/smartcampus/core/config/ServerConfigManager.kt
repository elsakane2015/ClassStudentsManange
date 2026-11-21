package com.litotime.smartcampus.core.config

import com.litotime.smartcampus.core.network.ApiException
import com.litotime.smartcampus.core.storage.AppPreferences
import com.litotime.smartcampus.core.storage.SavedServer
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.withContext
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.jsonPrimitive
import okhttp3.HttpUrl.Companion.toHttpUrlOrNull
import okhttp3.OkHttpClient
import okhttp3.Request
import java.util.concurrent.TimeUnit

data class ServerProbeResult(
    val baseUrl: String,
    val schoolName: String
)

/**
 * 动态多校服务器地址管理器（不写死域名，运行时即时生效并自动改写媒体相对/localhost 路径）
 */
class ServerConfigManager(private val prefs: AppPreferences) {
    private val probeClient = OkHttpClient.Builder()
        .connectTimeout(8, TimeUnit.SECONDS)
        .readTimeout(10, TimeUnit.SECONDS)
        .build()

    private val json = Json { ignoreUnknownKeys = true }

    private val _activeBaseUrl = MutableStateFlow<String?>(null)
    val activeBaseUrl: StateFlow<String?> = _activeBaseUrl.asStateFlow()

    private val _activeSchoolName = MutableStateFlow("智慧校园")
    val activeSchoolName: StateFlow<String> = _activeSchoolName.asStateFlow()

    private val _savedServers = MutableStateFlow<List<SavedServer>>(emptyList())
    val savedServers: StateFlow<List<SavedServer>> = _savedServers.asStateFlow()

    val isConfigured: Boolean
        get() = !_activeBaseUrl.value.isNullOrBlank()

    suspend fun loadInitial() {
        _activeBaseUrl.value = prefs.getActiveBaseUrl()
        _activeSchoolName.value = prefs.getActiveSchoolName()
    }

    suspend fun collectSavedServers() {
        prefs.savedServersFlow.collect { _savedServers.value = it }
    }

    suspend fun applyServer(baseUrl: String, schoolName: String) {
        prefs.applyServer(baseUrl, schoolName)
        _activeBaseUrl.value = baseUrl
        _activeSchoolName.value = schoolName
    }

    suspend fun removeServer(baseUrl: String) {
        prefs.removeSavedServer(baseUrl)
        _activeBaseUrl.value = prefs.getActiveBaseUrl()
        _activeSchoolName.value = prefs.getActiveSchoolName()
    }

    suspend fun probeServer(rawInput: String): ServerProbeResult = withContext(Dispatchers.IO) {
        val normalized = normalizeBaseUrl(rawInput)
            ?: throw ApiException("服务器地址格式不正确，例如 https://school.example.com")

        val request = Request.Builder()
            .url("$normalized/api/school/info")
            .header("Accept", "application/json")
            .get()
            .build()

        val response = try {
            probeClient.newCall(request).execute()
        } catch (e: Exception) {
            throw ApiException("无法连接到服务器：${e.localizedMessage ?: "网络错误"}")
        }

        response.use { res ->
            if (!res.isSuccessful) {
                throw ApiException("学校服务器返回异常（HTTP ${res.code}）", res.code)
            }
            val bodyStr = res.body?.string().orEmpty()
            val schoolName = runCatching {
                val obj = json.parseToJsonElement(bodyStr) as? JsonObject
                obj?.get("name")?.jsonPrimitive?.contentOrNull?.trim()
            }.getOrNull().takeUnless { it.isNullOrBlank() }
                ?: normalized.toHttpUrlOrNull()?.host
                ?: "智慧校园"

            ServerProbeResult(baseUrl = normalized, schoolName = schoolName)
        }
    }

    fun resolveMediaUrl(pathOrUrl: String?): String? {
        val cleaned = pathOrUrl?.trim().orEmpty()
        if (cleaned.isEmpty()) return null
        val base = _activeBaseUrl.value ?: return cleaned

        if (cleaned.startsWith("http://", ignoreCase = true) || cleaned.startsWith("https://", ignoreCase = true)) {
            val parsed = cleaned.toHttpUrlOrNull()
            val baseParsed = base.toHttpUrlOrNull()
            if (parsed != null && baseParsed != null &&
                (parsed.host.equals("localhost", true) || parsed.host == "127.0.0.1")
            ) {
                return parsed.newBuilder()
                    .scheme(baseParsed.scheme)
                    .host(baseParsed.host)
                    .port(baseParsed.port)
                    .build()
                    .toString()
            }
            return cleaned
        }

        return when {
            cleaned.startsWith("/storage/") -> base + cleaned
            cleaned.startsWith("storage/") -> "$base/$cleaned"
            cleaned.startsWith("/") -> base + cleaned
            else -> "$base/storage/$cleaned"
        }
    }

    companion object {
        fun normalizeBaseUrl(raw: String): String? {
            var trimmed = raw.trim()
            if (trimmed.isEmpty()) return null

            if (!trimmed.startsWith("http://", ignoreCase = true) &&
                !trimmed.startsWith("https://", ignoreCase = true)
            ) {
                val hostPart = trimmed.substringBefore("/")
                val isLocalOrIp = hostPart.startsWith("localhost", true)
                    || hostPart.startsWith("127.")
                    || hostPart.startsWith("10.")
                    || hostPart.startsWith("192.168.")
                    || hostPart.startsWith("172.")
                    || hostPart.contains(":")
                trimmed = (if (isLocalOrIp) "http://" else "https://") + trimmed
            }

            trimmed = trimmed.trimEnd('/')
            if (trimmed.endsWith("/api", ignoreCase = true)) {
                trimmed = trimmed.dropLast(4).trimEnd('/')
            }

            val parsed = trimmed.toHttpUrlOrNull() ?: return null
            if (parsed.scheme != "http" && parsed.scheme != "https") return null
            return trimmed
        }
    }
}
