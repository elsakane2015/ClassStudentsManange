package com.litotime.smartcampus.core.network

import android.content.Context
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.net.Uri
import com.litotime.smartcampus.core.config.ServerConfigManager
import com.litotime.smartcampus.core.storage.AppPreferences
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.jsonPrimitive
import okhttp3.HttpUrl.Companion.toHttpUrlOrNull
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.MultipartBody
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import java.io.ByteArrayOutputStream
import java.io.File
import java.util.concurrent.TimeUnit

class ApiException(
    override val message: String,
    val statusCode: Int = 0
) : Exception(message)

/**
 * 动态 BaseURL + Bearer Token 原生网络客户端，内置图片自动压缩上传与 Excel 二进制文件下载
 */
class ApiClient(
    private val context: Context,
    private val serverConfig: ServerConfigManager,
    private val prefs: AppPreferences
) {
    val json: Json = Json {
        ignoreUnknownKeys = true
        isLenient = true
        coerceInputValues = true
        explicitNulls = false
    }

    private val client: OkHttpClient = OkHttpClient.Builder()
        .connectTimeout(20, TimeUnit.SECONDS)
        .readTimeout(40, TimeUnit.SECONDS)
        .writeTimeout(40, TimeUnit.SECONDS)
        .build()

    var onUnauthorized: (() -> Unit)? = null

    private val jsonMediaType = "application/json; charset=utf-8".toMediaType()

    suspend fun requestRaw(
        path: String,
        method: String = "GET",
        query: Map<String, String?> = emptyMap(),
        jsonBody: String? = null,
        requiresAuth: Boolean = true
    ): String = withContext(Dispatchers.IO) {
        val baseUrl = serverConfig.activeBaseUrl.value
            ?: throw ApiException("尚未配置学校服务器地址，请先连接学校服务器")

        val normalizedPath = if (path.startsWith("/")) path else "/$path"
        val urlBuilder = "$baseUrl/api$normalizedPath".toHttpUrlOrNull()?.newBuilder()
            ?: throw ApiException("无效的接口地址")

        query.forEach { (k, v) ->
            if (!v.isNullOrBlank()) {
                urlBuilder.addQueryParameter(k, v)
            }
        }

        val reqBuilder = Request.Builder()
            .url(urlBuilder.build())
            .header("Accept", "application/json")
            .header("X-Requested-With", "XMLHttpRequest")

        if (requiresAuth) {
            val token = prefs.getToken()
            if (!token.isNullOrBlank()) {
                reqBuilder.header("Authorization", "Bearer $token")
            }
        }

        val upper = method.uppercase()
        when (upper) {
            "GET" -> reqBuilder.get()
            "DELETE" -> {
                if (jsonBody != null) {
                    reqBuilder.delete(jsonBody.toRequestBody(jsonMediaType))
                } else {
                    reqBuilder.delete()
                }
            }
            "POST", "PUT", "PATCH" -> {
                val body = (jsonBody ?: "{}").toRequestBody(jsonMediaType)
                reqBuilder.method(upper, body)
            }
            else -> reqBuilder.method(upper, jsonBody?.toRequestBody(jsonMediaType))
        }

        val response = try {
            client.newCall(reqBuilder.build()).execute()
        } catch (e: Exception) {
            throw ApiException("网络连接失败：${e.localizedMessage ?: "请检查网络或服务器地址"}")
        }

        response.use { res ->
            val rawBody = res.body?.string().orEmpty()
            if (!res.isSuccessful) {
                val msg = extractErrorMessage(rawBody) ?: "请求失败（HTTP ${res.code}）"
                if (res.code == 401 && requiresAuth) {
                    onUnauthorized?.invoke()
                }
                throw ApiException(msg, res.code)
            }
            rawBody
        }
    }

    suspend fun requestElement(
        path: String,
        method: String = "GET",
        query: Map<String, String?> = emptyMap(),
        jsonBody: String? = null,
        requiresAuth: Boolean = true
    ): JsonElement {
        val raw = requestRaw(path, method, query, jsonBody, requiresAuth)
        if (raw.isBlank()) return JsonObject(emptyMap())
        return json.parseToJsonElement(raw)
    }

    suspend inline fun <reified T> request(
        path: String,
        method: String = "GET",
        query: Map<String, String?> = emptyMap(),
        jsonBody: String? = null,
        requiresAuth: Boolean = true
    ): T {
        val raw = requestRaw(path, method, query, jsonBody, requiresAuth)
        return json.decodeFromString(raw)
    }

    suspend fun uploadImage(
        path: String,
        imageUri: Uri,
        maxSizeMB: Int = 5
    ): JsonObject = withContext(Dispatchers.IO) {
        val baseUrl = serverConfig.activeBaseUrl.value
            ?: throw ApiException("尚未配置学校服务器地址")
        val bytes = compressUriToJpeg(imageUri, maxSizeMB.coerceAtLeast(1) * 1024 * 1024)
            ?: throw ApiException("无法读取所选图片，请重新选择")

        val normalizedPath = if (path.startsWith("/")) path else "/$path"
        val filename = "leave_${System.currentTimeMillis()}.jpg"
        val multipartBody = MultipartBody.Builder()
            .setType(MultipartBody.FORM)
            .addFormDataPart(
                "image",
                filename,
                bytes.toRequestBody("image/jpeg".toMediaType())
            )
            .build()

        val reqBuilder = Request.Builder()
            .url("$baseUrl/api$normalizedPath")
            .header("Accept", "application/json")
            .header("X-Requested-With", "XMLHttpRequest")
            .post(multipartBody)

        prefs.getToken()?.takeIf { it.isNotBlank() }?.let {
            reqBuilder.header("Authorization", "Bearer $it")
        }

        val response = try {
            client.newCall(reqBuilder.build()).execute()
        } catch (e: Exception) {
            throw ApiException("图片上传失败：${e.localizedMessage ?: "网络错误"}")
        }

        response.use { res ->
            val raw = res.body?.string().orEmpty()
            if (!res.isSuccessful) {
                throw ApiException(extractErrorMessage(raw) ?: "图片上传失败（HTTP ${res.code}）", res.code)
            }
            json.parseToJsonElement(raw) as? JsonObject
                ?: throw ApiException("图片上传返回数据格式异常")
        }
    }

    suspend fun downloadFile(
        path: String,
        query: Map<String, String?> = emptyMap(),
        suggestedFilename: String = "smartcampus_attendance.xlsx"
    ): File = withContext(Dispatchers.IO) {
        val baseUrl = serverConfig.activeBaseUrl.value
            ?: throw ApiException("尚未配置学校服务器地址")
        val normalizedPath = if (path.startsWith("/")) path else "/$path"
        val urlBuilder = "$baseUrl/api$normalizedPath".toHttpUrlOrNull()?.newBuilder()
            ?: throw ApiException("无效的下载地址")
        query.forEach { (k, v) -> if (!v.isNullOrBlank()) urlBuilder.addQueryParameter(k, v) }

        val reqBuilder = Request.Builder()
            .url(urlBuilder.build())
            .header("Accept", "*/*")
            .get()

        prefs.getToken()?.takeIf { it.isNotBlank() }?.let {
            reqBuilder.header("Authorization", "Bearer $it")
        }

        val response = try {
            client.newCall(reqBuilder.build()).execute()
        } catch (e: Exception) {
            throw ApiException("下载报表失败：${e.localizedMessage ?: "网络错误"}")
        }

        response.use { res ->
            if (!res.isSuccessful) {
                val raw = res.body?.string().orEmpty()
                throw ApiException(extractErrorMessage(raw) ?: "导出失败（HTTP ${res.code}）", res.code)
            }

            var filename = suggestedFilename
            val disp = res.header("Content-Disposition")
            if (disp != null && disp.contains("filename=")) {
                val parsed = disp.substringAfter("filename=").trim('"', '\'', ' ', ';')
                if (parsed.isNotBlank()) filename = parsed
            }

            val outDir = File(context.cacheDir, "exports").apply { mkdirs() }
            val target = File(outDir, filename)
            val bytes = res.body?.bytes() ?: throw ApiException("服务器未返回文件内容")
            target.writeBytes(bytes)
            target
        }
    }

    private fun extractErrorMessage(raw: String): String? {
        if (raw.isBlank()) return null
        return runCatching {
            val obj = json.parseToJsonElement(raw) as? JsonObject ?: return null
            val errors = obj["errors"] as? JsonObject
            if (errors != null) {
                for ((_, value) in errors) {
                    val arr = value as? JsonArray
                    val first = arr?.firstOrNull()?.jsonPrimitive?.contentOrNull
                    if (!first.isNullOrBlank()) return first
                }
            }
            obj["error"]?.jsonPrimitive?.contentOrNull?.takeIf { it.isNotBlank() }
                ?: obj["message"]?.jsonPrimitive?.contentOrNull?.takeIf { it.isNotBlank() }
        }.getOrNull()
    }

    private fun compressUriToJpeg(uri: Uri, maxBytes: Int): ByteArray? {
        val original = context.contentResolver.openInputStream(uri)?.use { stream ->
            BitmapFactory.decodeStream(stream)
        } ?: return null

        val maxDim = 1920
        val scaled = if (maxOf(original.width, original.height) > maxDim) {
            val ratio = maxDim.toFloat() / maxOf(original.width, original.height).toFloat()
            Bitmap.createScaledBitmap(
                original,
                (original.width * ratio).toInt().coerceAtLeast(1),
                (original.height * ratio).toInt().coerceAtLeast(1),
                true
            )
        } else {
            original
        }

        var quality = 85
        var outputBytes: ByteArray
        while (true) {
            val baos = ByteArrayOutputStream()
            scaled.compress(Bitmap.CompressFormat.JPEG, quality, baos)
            outputBytes = baos.toByteArray()
            if (outputBytes.size <= maxBytes || quality <= 25) break
            quality -= 15
        }
        return outputBytes
    }
}
