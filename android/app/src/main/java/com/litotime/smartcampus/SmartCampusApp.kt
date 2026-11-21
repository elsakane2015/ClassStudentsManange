package com.litotime.smartcampus

import android.app.Application
import com.litotime.smartcampus.core.auth.SessionManager
import com.litotime.smartcampus.core.config.ServerConfigManager
import com.litotime.smartcampus.core.database.OfflineRollCallDatabase
import com.litotime.smartcampus.core.network.ApiClient
import com.litotime.smartcampus.core.push.PushNotificationManager
import com.litotime.smartcampus.core.storage.AppPreferences
import com.litotime.smartcampus.data.repository.SmartCampusRepository

class AppContainer(app: Application) {
    val prefs = AppPreferences(app)
    val offlineDb = OfflineRollCallDatabase.create(app)
    val serverConfig = ServerConfigManager(prefs)
    val apiClient = ApiClient(app, serverConfig, prefs)
    val pushManager = PushNotificationManager(app, apiClient, serverConfig, prefs)
    val repository = SmartCampusRepository(apiClient)
    val sessionManager = SessionManager(apiClient, serverConfig, prefs, pushManager)
}

class SmartCampusApp : Application() {
    override fun onCreate() {
        super.onCreate()
        container = AppContainer(this)
        container.pushManager.ensureNotificationChannel()
    }

    companion object {
        lateinit var container: AppContainer
            private set
    }
}
