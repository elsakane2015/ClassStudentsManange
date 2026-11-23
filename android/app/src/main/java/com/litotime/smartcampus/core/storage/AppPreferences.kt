package com.litotime.smartcampus.core.storage

import android.content.Context
import androidx.datastore.core.DataStore
import androidx.datastore.preferences.core.Preferences
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.intPreferencesKey
import androidx.datastore.preferences.core.stringPreferencesKey
import androidx.datastore.preferences.preferencesDataStore
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.map
import kotlinx.serialization.Serializable
import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json
import java.util.UUID

private val Context.dataStore: DataStore<Preferences> by preferencesDataStore(name = "smartcampus_prefs")

@Serializable
data class SavedServer(
    val baseUrl: String,
    val schoolName: String,
    val lastConnectedAt: Long = System.currentTimeMillis()
)

class AppPreferences(private val context: Context) {
    private val json = Json { ignoreUnknownKeys = true }

    private object Keys {
        val ACTIVE_BASE_URL = stringPreferencesKey("active_base_url")
        val ACTIVE_SCHOOL_NAME = stringPreferencesKey("active_school_name")
        val SAVED_SERVERS = stringPreferencesKey("saved_servers")
        val AUTH_TOKEN = stringPreferencesKey("auth_token")
        val DEVICE_IDENTIFIER = stringPreferencesKey("device_identifier")
        val PUSH_TOKEN = stringPreferencesKey("push_token")
        val ROLL_CALL_COLUMNS = intPreferencesKey("roll_call_columns")
        val ROSTER_COLUMNS = intPreferencesKey("roster_columns")
    }

    val activeBaseUrlFlow: Flow<String?> = context.dataStore.data.map { it[Keys.ACTIVE_BASE_URL] }
    val activeSchoolNameFlow: Flow<String> = context.dataStore.data.map { it[Keys.ACTIVE_SCHOOL_NAME] ?: "智慧校园" }
    val savedServersFlow: Flow<List<SavedServer>> = context.dataStore.data.map { prefs ->
        val raw = prefs[Keys.SAVED_SERVERS] ?: return@map emptyList()
        runCatching { json.decodeFromString<List<SavedServer>>(raw) }.getOrDefault(emptyList())
    }
    val rollCallColumnsFlow: Flow<Int> = context.dataStore.data.map { (it[Keys.ROLL_CALL_COLUMNS] ?: 2).coerceIn(1, 4) }
    val rosterColumnsFlow: Flow<Int> = context.dataStore.data.map { (it[Keys.ROSTER_COLUMNS] ?: 1).coerceIn(1, 2) }

    suspend fun getActiveBaseUrl(): String? = context.dataStore.data.first()[Keys.ACTIVE_BASE_URL]

    suspend fun getActiveSchoolName(): String = context.dataStore.data.first()[Keys.ACTIVE_SCHOOL_NAME] ?: "智慧校园"

    suspend fun getToken(): String? = context.dataStore.data.first()[Keys.AUTH_TOKEN]

    suspend fun saveToken(token: String) {
        context.dataStore.edit { it[Keys.AUTH_TOKEN] = token }
    }

    suspend fun clearToken() {
        context.dataStore.edit { it.remove(Keys.AUTH_TOKEN) }
    }

    suspend fun getOrCreateDeviceIdentifier(): String {
        val existing = context.dataStore.data.first()[Keys.DEVICE_IDENTIFIER]
        if (!existing.isNullOrBlank()) return existing
        val generated = UUID.randomUUID().toString().lowercase()
        context.dataStore.edit { it[Keys.DEVICE_IDENTIFIER] = generated }
        return generated
    }

    suspend fun getPushToken(): String? = context.dataStore.data.first()[Keys.PUSH_TOKEN]

    suspend fun savePushToken(token: String?) {
        context.dataStore.edit {
            if (token.isNullOrBlank()) it.remove(Keys.PUSH_TOKEN) else it[Keys.PUSH_TOKEN] = token
        }
    }

    suspend fun setRollCallColumns(columns: Int) {
        context.dataStore.edit { it[Keys.ROLL_CALL_COLUMNS] = columns.coerceIn(1, 4) }
    }

    suspend fun setRosterColumns(columns: Int) {
        context.dataStore.edit { it[Keys.ROSTER_COLUMNS] = columns.coerceIn(1, 2) }
    }

    suspend fun applyServer(baseUrl: String, schoolName: String) {
        context.dataStore.edit { prefs ->
            prefs[Keys.ACTIVE_BASE_URL] = baseUrl
            prefs[Keys.ACTIVE_SCHOOL_NAME] = schoolName
            val existing = prefs[Keys.SAVED_SERVERS]?.let {
                runCatching { json.decodeFromString<List<SavedServer>>(it) }.getOrDefault(emptyList())
            }.orEmpty()
            val updated = buildList {
                add(SavedServer(baseUrl = baseUrl, schoolName = schoolName))
                addAll(existing.filterNot { it.baseUrl == baseUrl })
            }.take(10)
            prefs[Keys.SAVED_SERVERS] = json.encodeToString(updated)
        }
    }

    suspend fun removeSavedServer(baseUrl: String) {
        context.dataStore.edit { prefs ->
            val existing = prefs[Keys.SAVED_SERVERS]?.let {
                runCatching { json.decodeFromString<List<SavedServer>>(it) }.getOrDefault(emptyList())
            }.orEmpty()
            val remaining = existing.filterNot { it.baseUrl == baseUrl }
            prefs[Keys.SAVED_SERVERS] = json.encodeToString(remaining)
            if (prefs[Keys.ACTIVE_BASE_URL] == baseUrl) {
                val next = remaining.firstOrNull()
                if (next != null) {
                    prefs[Keys.ACTIVE_BASE_URL] = next.baseUrl
                    prefs[Keys.ACTIVE_SCHOOL_NAME] = next.schoolName
                } else {
                    prefs.remove(Keys.ACTIVE_BASE_URL)
                    prefs[Keys.ACTIVE_SCHOOL_NAME] = "智慧校园"
                }
            }
        }
    }
}
