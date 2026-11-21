package com.litotime.smartcampus

import android.Manifest
import android.content.Intent
import android.os.Build
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.School
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.litotime.smartcampus.core.designsystem.SmartCampusTheme
import com.litotime.smartcampus.feature.auth.LoginScreen
import com.litotime.smartcampus.feature.auth.ServerSetupScreen
import com.litotime.smartcampus.navigation.RoleBasedHomeScreen

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        SmartCampusApp.container.pushManager.handleIntent(intent)

        setContent {
            SmartCampusTheme {
                Surface(
                    modifier = Modifier.fillMaxSize(),
                    color = MaterialTheme.colorScheme.background
                ) {
                    SmartCampusRoot()
                }
            }
        }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        SmartCampusApp.container.pushManager.handleIntent(intent)
    }
}

@Composable
private fun SmartCampusRoot() {
    val container = SmartCampusApp.container
    val isBootstrapping by container.sessionManager.isBootstrapping.collectAsStateWithLifecycle()
    val currentUser by container.sessionManager.currentUser.collectAsStateWithLifecycle()
    val activeBaseUrl by container.serverConfig.activeBaseUrl.collectAsStateWithLifecycle()
    val activeSchoolName by container.serverConfig.activeSchoolName.collectAsStateWithLifecycle()

    val notifPermissionLauncher = rememberLauncherForActivityResult(
        ActivityResultContracts.RequestPermission()
    ) { _ ->
        // 授权结果变化后同步至后端设备表
    }

    LaunchedEffect(Unit) {
        container.sessionManager.bootstrap()
    }

    LaunchedEffect(Unit) {
        container.serverConfig.collectSavedServers()
    }

    LaunchedEffect(currentUser?.id) {
        if (currentUser != null && Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            if (!container.pushManager.hasNotificationPermission()) {
                notifPermissionLauncher.launch(Manifest.permission.POST_NOTIFICATIONS)
            }
        }
    }

    when {
        isBootstrapping -> SplashLoadingView(schoolName = activeSchoolName)
        activeBaseUrl.isNullOrBlank() -> ServerSetupScreen(isInitialSetup = true, onDone = {})
        currentUser != null -> RoleBasedHomeScreen(user = currentUser!!)
        else -> LoginScreen()
    }
}

@Composable
private fun SplashLoadingView(schoolName: String) {
    Column(
        modifier = Modifier.fillMaxSize(),
        verticalArrangement = Arrangement.Center,
        horizontalAlignment = Alignment.CenterHorizontally
    ) {
        Icon(
            imageVector = Icons.Rounded.School,
            contentDescription = null,
            tint = MaterialTheme.colorScheme.primary,
            modifier = Modifier.size(68.dp)
        )
        Spacer(Modifier.height(14.dp))
        Text(schoolName, style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
        Spacer(Modifier.height(16.dp))
        CircularProgressIndicator()
        Spacer(Modifier.height(10.dp))
        Text("正在连接校园服务...", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
    }
}
