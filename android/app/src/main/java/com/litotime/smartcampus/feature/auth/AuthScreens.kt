package com.litotime.smartcampus.feature.auth

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.AccountBalance
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.Dns
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material.icons.rounded.Shield
import androidx.compose.material.icons.rounded.Visibility
import androidx.compose.material.icons.rounded.VisibilityOff
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.litotime.smartcampus.SmartCampusApp
import com.litotime.smartcampus.core.designsystem.ErrorBanner
import com.litotime.smartcampus.core.designsystem.SCCard
import kotlinx.coroutines.launch

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ServerSetupScreen(
    isInitialSetup: Boolean,
    onDone: () -> Unit
) {
    val container = SmartCampusApp.container
    val activeUrl by container.serverConfig.activeBaseUrl.collectAsStateWithLifecycle()
    val savedServers by container.serverConfig.savedServers.collectAsStateWithLifecycle()
    val scope = rememberCoroutineScope()

    var inputUrl by remember(activeUrl) { mutableStateOf(activeUrl.orEmpty()) }
    var isProbing by remember { mutableStateOf(false) }
    var errorMessage by remember { mutableStateOf<String?>(null) }

    fun testAndConnect() {
        if (inputUrl.isBlank() || isProbing) return
        scope.launch {
            isProbing = true
            errorMessage = null
            runCatching {
                val res = container.serverConfig.probeServer(inputUrl)
                container.sessionManager.switchServer(res.baseUrl, res.schoolName)
                onDone()
            }.onFailure {
                errorMessage = it.localizedMessage ?: "连接失败"
            }
            isProbing = false
        }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text(if (isInitialSetup) "配置学校服务器" else "切换学校服务器") },
                navigationIcon = {
                    if (!isInitialSetup) {
                        IconButton(onClick = onDone) {
                            Icon(Icons.AutoMirrored.Rounded.ArrowBack, contentDescription = "返回")
                        }
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
            verticalArrangement = Arrangement.spacedBy(16.dp)
        ) {
            SCCard {
                Row(
                    verticalAlignment = Alignment.CenterVertically,
                    horizontalArrangement = Arrangement.spacedBy(12.dp)
                ) {
                    Icon(
                        Icons.Rounded.AccountBalance,
                        contentDescription = null,
                        tint = MaterialTheme.colorScheme.primary,
                        modifier = Modifier.size(40.dp)
                    )
                    Column {
                        Text("连接学校服务器", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                        Text(
                            "输入学校智慧校园系统的域名或局域网地址",
                            style = MaterialTheme.typography.bodySmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant
                        )
                    }
                }

                Spacer(Modifier.height(14.dp))

                OutlinedTextField(
                    value = inputUrl,
                    onValueChange = { inputUrl = it },
                    modifier = Modifier.fillMaxWidth(),
                    label = { Text("服务器地址") },
                    placeholder = { Text("例如 attendance.school.edu.cn 或 192.168.1.10:8080") },
                    singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri, imeAction = ImeAction.Go),
                    keyboardActions = KeyboardActions(onGo = { testAndConnect() })
                )

                errorMessage?.let {
                    Spacer(Modifier.height(10.dp))
                    ErrorBanner(message = it)
                }

                Spacer(Modifier.height(14.dp))

                Button(
                    onClick = { testAndConnect() },
                    enabled = !isProbing && inputUrl.isNotBlank(),
                    modifier = Modifier.fillMaxWidth().height(48.dp),
                    shape = RoundedCornerShape(12.dp)
                ) {
                    if (isProbing) {
                        CircularProgressIndicator(modifier = Modifier.size(18.dp), strokeWidth = 2.dp, color = Color.White)
                        Spacer(Modifier.width(8.dp))
                    }
                    Text(if (isProbing) "正在探测学校服务..." else "验证并连接", fontWeight = FontWeight.SemiBold)
                }

                Spacer(Modifier.height(8.dp))
                Text(
                    "支持自动补全 HTTPS / HTTP 协议；切换学校服务器后将退出当前登录态。",
                    style = MaterialTheme.typography.labelSmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant
                )
            }

            if (savedServers.isNotEmpty()) {
                SCCard {
                    Text("已保存的学校站点", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold)
                    Spacer(Modifier.height(8.dp))
                    savedServers.forEach { server ->
                        Row(
                            modifier = Modifier
                                .fillMaxWidth()
                                .clickable {
                                    scope.launch {
                                        isProbing = true
                                        val probed = runCatching { container.serverConfig.probeServer(server.baseUrl) }.getOrNull()
                                        container.sessionManager.switchServer(
                                            probed?.baseUrl ?: server.baseUrl,
                                            probed?.schoolName ?: server.schoolName
                                        )
                                        isProbing = false
                                        onDone()
                                    }
                                }
                                .padding(vertical = 10.dp),
                            verticalAlignment = Alignment.CenterVertically
                        ) {
                            Column(modifier = Modifier.weight(1f)) {
                                Text(server.schoolName, style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.SemiBold)
                                Text(
                                    server.baseUrl,
                                    style = MaterialTheme.typography.bodySmall,
                                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                                    maxLines = 1,
                                    overflow = TextOverflow.Ellipsis
                                )
                            }
                            if (activeUrl == server.baseUrl) {
                                Icon(Icons.Rounded.CheckCircle, contentDescription = "当前", tint = MaterialTheme.colorScheme.primary)
                            }
                            IconButton(onClick = { scope.launch { container.serverConfig.removeServer(server.baseUrl) } }) {
                                Icon(Icons.Rounded.Delete, contentDescription = "删除", tint = MaterialTheme.colorScheme.onSurfaceVariant)
                            }
                        }
                    }
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun LoginScreen() {
    val container = SmartCampusApp.container
    val schoolName by container.serverConfig.activeSchoolName.collectAsStateWithLifecycle()
    val activeUrl by container.serverConfig.activeBaseUrl.collectAsStateWithLifecycle()
    val scope = rememberCoroutineScope()

    var account by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var passwordVisible by remember { mutableStateOf(false) }
    var isLoading by remember { mutableStateOf(false) }
    var errorMessage by remember { mutableStateOf<String?>(null) }
    var showServerModal by remember { mutableStateOf(false) }

    fun performLogin() {
        if (account.isBlank() || password.isBlank() || isLoading) return
        scope.launch {
            isLoading = true
            errorMessage = null
            runCatching {
                container.sessionManager.login(account, password)
            }.onFailure {
                errorMessage = it.localizedMessage ?: "登录失败"
            }
            isLoading = false
        }
    }

    Scaffold { padding ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(padding)
                .verticalScroll(rememberScrollState())
                .padding(horizontal = 20.dp, vertical = 32.dp),
            horizontalAlignment = Alignment.CenterHorizontally
        ) {
            Spacer(Modifier.height(16.dp))
            Icon(
                Icons.Rounded.Shield,
                contentDescription = null,
                tint = MaterialTheme.colorScheme.primary,
                modifier = Modifier.size(68.dp)
            )
            Spacer(Modifier.height(12.dp))
            Text(schoolName, style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Bold)
            Text("智慧校园考勤系统", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
            Spacer(Modifier.height(12.dp))

            Surface(
                shape = RoundedCornerShape(999.dp),
                color = MaterialTheme.colorScheme.primaryContainer,
                modifier = Modifier.clickable { showServerModal = true }
            ) {
                Row(
                    modifier = Modifier.padding(horizontal = 14.dp, vertical = 7.dp),
                    verticalAlignment = Alignment.CenterVertically,
                    horizontalArrangement = Arrangement.spacedBy(6.dp)
                ) {
                    Icon(Icons.Rounded.Dns, contentDescription = null, tint = MaterialTheme.colorScheme.primary, modifier = Modifier.size(16.dp))
                    Text(
                        activeUrl ?: "未配置服务器",
                        style = MaterialTheme.typography.labelMedium,
                        color = MaterialTheme.colorScheme.onPrimaryContainer,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis
                    )
                    Text("切换", style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.Bold, color = MaterialTheme.colorScheme.primary)
                }
            }

            Spacer(Modifier.height(28.dp))

            SCCard {
                errorMessage?.let {
                    ErrorBanner(message = it)
                    Spacer(Modifier.height(12.dp))
                }

                OutlinedTextField(
                    value = account,
                    onValueChange = { account = it },
                    modifier = Modifier.fillMaxWidth(),
                    label = { Text("邮箱 / 学号") },
                    placeholder = { Text("输入邮箱或学号") },
                    leadingIcon = { Icon(Icons.Rounded.Person, contentDescription = null) },
                    singleLine = true,
                    keyboardOptions = KeyboardOptions(imeAction = ImeAction.Next)
                )

                Spacer(Modifier.height(12.dp))

                OutlinedTextField(
                    value = password,
                    onValueChange = { password = it },
                    modifier = Modifier.fillMaxWidth(),
                    label = { Text("密码") },
                    placeholder = { Text("输入登录密码") },
                    leadingIcon = { Icon(Icons.Rounded.Lock, contentDescription = null) },
                    trailingIcon = {
                        IconButton(onClick = { passwordVisible = !passwordVisible }) {
                            Icon(
                                if (passwordVisible) Icons.Rounded.VisibilityOff else Icons.Rounded.Visibility,
                                contentDescription = "显示或隐藏密码"
                            )
                        }
                    },
                    singleLine = true,
                    visualTransformation = if (passwordVisible) VisualTransformation.None else PasswordVisualTransformation(),
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password, imeAction = ImeAction.Done),
                    keyboardActions = KeyboardActions(onDone = { performLogin() })
                )

                Spacer(Modifier.height(18.dp))

                Button(
                    onClick = { performLogin() },
                    enabled = !isLoading && account.isNotBlank() && password.isNotBlank(),
                    modifier = Modifier.fillMaxWidth().height(50.dp),
                    shape = RoundedCornerShape(12.dp)
                ) {
                    if (isLoading) {
                        CircularProgressIndicator(modifier = Modifier.size(18.dp), strokeWidth = 2.dp, color = Color.White)
                        Spacer(Modifier.width(8.dp))
                    }
                    Text(if (isLoading) "登录中..." else "登录", fontWeight = FontWeight.SemiBold)
                }
            }

            Spacer(Modifier.height(16.dp))
            Text(
                "支持学生、班主任、值班教师及管理人员统一登录",
                style = MaterialTheme.typography.labelSmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant
            )
        }
    }

    if (showServerModal) {
        ModalBottomSheet(onDismissRequest = { showServerModal = false }) {
            BoxSheetWrapper {
                ServerSetupScreen(isInitialSetup = false, onDone = { showServerModal = false })
            }
        }
    }
}

@Composable
private fun BoxSheetWrapper(content: @Composable () -> Unit) {
    Column(modifier = Modifier.fillMaxWidth().height(560.dp)) {
        content()
    }
}
