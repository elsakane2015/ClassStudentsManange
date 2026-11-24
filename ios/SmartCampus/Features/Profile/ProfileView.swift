import SwiftUI

struct ProfileView: View {
    let user: User

    @State private var serverConfig = ServerConfigManager.shared
    @State private var pushManager = PushNotificationManager.shared
    @State private var showChangePassword = false
    @State private var showServerSwitch = false
    @State private var showLogoutAlert = false
    @State private var copiedTokenNotice = false
    @State private var isRefreshingPush = false
    @State private var pushRefreshFeedback: String?
    @State private var devices: [MobileDeviceItem] = []
    @State private var wechatConfigRes: TeacherWechatConfigResponse?
    @State private var wechatPushEnabled = true
    @State private var isTogglingWechatPush = false
    @State private var wechatToggleFeedback: String?
    @State private var wechatToggleIsError = false

    var body: some View {
        NavigationStack {
            List {
                // User Header
                Section {
                    HStack(spacing: 14) {
                        ZStack {
                            Circle()
                                .fill(SCTheme.primary.opacity(0.15))
                                .frame(width: 56, height: 56)
                            Text(String(user.name.prefix(1)))
                                .font(.title2.bold())
                                .foregroundStyle(SCTheme.primary)
                        }

                        VStack(alignment: .leading, spacing: 4) {
                            Text(user.name)
                                .font(.headline)
                            Text(user.roleDisplayName)
                                .font(.caption)
                                .foregroundStyle(.secondary)
                            if let studentNo = user.student?.studentNo, !studentNo.isEmpty {
                                Text("学号：\(studentNo)")
                                    .font(.caption2)
                                    .foregroundStyle(.tertiary)
                            } else if let email = user.email {
                                Text(email)
                                    .font(.caption2)
                                    .foregroundStyle(.tertiary)
                            }
                        }
                    }
                    .padding(.vertical, 6)
                }

                // Notifications & Push
                Section("消息与推送") {
                    NavigationLink {
                        NotificationsView()
                    } label: {
                        HStack {
                            Label("消息通知中心", systemImage: "bell.badge")
                            Spacer()
                            if pushManager.unreadCount > 0 {
                                Text("\(pushManager.unreadCount)")
                                    .font(.caption.bold())
                                    .foregroundStyle(.white)
                                    .padding(.horizontal, 8)
                                    .padding(.vertical, 2)
                                    .background(Color.red)
                                    .clipShape(Capsule())
                            }
                        }
                    }

                    HStack {
                        Label("系统通知权限", systemImage: "iphone.radiowaves.left.and.right")
                        Spacer()
                        Text(authStatusText)
                            .font(.caption)
                            .foregroundStyle(pushManager.authorizationStatus == .authorized ? .green : .orange)
                    }

                    HStack {
                        Label("APNs 推送通道", systemImage: "paperplane.circle")
                        Spacer()
                        Text(pushGatewayStatusText)
                            .font(.caption)
                            .foregroundStyle(pushManager.apnsDeviceToken != nil ? .green : .secondary)
                    }

                    if let token = pushManager.apnsDeviceToken {
                        Button {
                            UIPasteboard.general.string = token
                            copiedTokenNotice = true
                            Task {
                                try? await Task.sleep(for: .seconds(2))
                                copiedTokenNotice = false
                            }
                        } label: {
                            HStack {
                                Label(copiedTokenNotice ? "已复制 APNs Token" : "复制本机 APNs Token", systemImage: copiedTokenNotice ? "checkmark.circle.fill" : "doc.on.doc")
                                    .foregroundStyle(copiedTokenNotice ? .green : SCTheme.primary)
                                Spacer()
                                Text("\(String(token.prefix(8)))...\(String(token.suffix(6)))")
                                    .font(.caption2.monospaced())
                                    .foregroundStyle(.secondary)
                            }
                        }
                    } else if let regErr = pushManager.lastRegistrationError {
                        Text("APNs 注册提示：\(regErr)")
                            .font(.caption2)
                            .foregroundStyle(.orange)
                    }

                    Button {
                        Task {
                            isRefreshingPush = true
                            pushRefreshFeedback = nil
                            await pushManager.requestPermissionAndRegister()
                            await pushManager.refreshUnreadCount()
                            await loadDevices()
                            isRefreshingPush = false
                            if pushManager.apnsDeviceToken != nil && pushManager.pushGatewayConfiguredOnServer {
                                pushRefreshFeedback = "已同步 · 锁屏推送已就绪"
                            } else if pushManager.apnsDeviceToken != nil {
                                pushRefreshFeedback = "已同步本机 Token"
                            } else {
                                pushRefreshFeedback = "已刷新 · 当前为仅站内通知模式"
                            }
                            try? await Task.sleep(for: .seconds(3))
                            pushRefreshFeedback = nil
                        }
                    } label: {
                        HStack {
                            if isRefreshingPush {
                                ProgressView()
                                    .controlSize(.small)
                                    .padding(.trailing, 4)
                                Text("正在同步推送状态...")
                                    .foregroundStyle(.secondary)
                            } else if let feedback = pushRefreshFeedback {
                                Label(feedback, systemImage: "checkmark.circle.fill")
                                    .foregroundStyle(.green)
                            } else {
                                Label("刷新推送注册状态", systemImage: "arrow.clockwise")
                            }
                        }
                    }
                    .disabled(isRefreshingPush)
                }

                // WeChat Official Account Push Switch (Teacher Only)
                if user.role == "teacher", let wechat = wechatConfigRes, wechat.isAvailable {
                    Section {
                        if let cfg = wechat.config {
                            Toggle(isOn: Binding(
                                get: { wechatPushEnabled },
                                set: { nextVal in
                                    Task { await updateWechatPushEnabled(nextVal) }
                                }
                            )) {
                                VStack(alignment: .leading, spacing: 3) {
                                    HStack(spacing: 6) {
                                        Label("微信公众号推送", systemImage: "message.badge.waveform")
                                        if isTogglingWechatPush {
                                            ProgressView()
                                                .controlSize(.mini)
                                        }
                                    }
                                    Text(wechatPushEnabled
                                         ? "学生提交请假时，自动推送微信模板消息"
                                         : "已暂停微信推送（保留原配置与绑定，随时可开启）")
                                        .font(.caption2)
                                        .foregroundStyle(.secondary)
                                }
                            }
                            .tint(SCTheme.primary)
                            .disabled(isTogglingWechatPush)

                            HStack(spacing: 8) {
                                Text(cfg.isUseSystem ? "系统公众号" : "自定义测试公众号")
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                                Spacer()
                                StatusBadge(
                                    text: cfg.isVerifiedFlag ? "已验证" : "未验证",
                                    color: cfg.isVerifiedFlag ? .green : .orange
                                )
                                Text("已绑定 \(wechat.bindings?.count ?? 0) 人")
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                            }

                            if let fb = wechatToggleFeedback {
                                Text(fb)
                                    .font(.caption)
                                    .foregroundStyle(wechatToggleIsError ? .red : .green)
                            }
                        } else {
                            HStack {
                                Label("微信公众号推送", systemImage: "message.badge.waveform")
                                Spacer()
                                Text("未配置")
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                            }
                            Text("请在网页端「微信推送」完成配置并验证后，即可在此随时开启或关闭公众号推送。")
                                .font(.caption2)
                                .foregroundStyle(.secondary)
                        }
                    } header: {
                        Text("微信公众号推送")
                    }
                }

                // Server & Account
                Section("学校服务器与安全") {
                    Button {
                        showServerSwitch = true
                    } label: {
                        HStack {
                            Label("当前学校", systemImage: "building.columns")
                            Spacer()
                            VStack(alignment: .trailing, spacing: 2) {
                                Text(serverConfig.activeSchoolName)
                                    .font(.subheadline)
                                    .foregroundStyle(.primary)
                                Text(serverConfig.activeBaseURL ?? "")
                                    .font(.caption2)
                                    .foregroundStyle(.secondary)
                                    .lineLimit(1)
                            }
                        }
                    }

                    Button {
                        showChangePassword = true
                    } label: {
                        Label("修改密码", systemImage: "key")
                    }
                }

                if !devices.isEmpty {
                    Section("当前账号已登录设备") {
                        ForEach(devices) { device in
                            HStack {
                                Image(systemName: device.platform == "ios" ? "iphone" : "smartphone")
                                    .foregroundStyle(SCTheme.primary)
                                VStack(alignment: .leading, spacing: 2) {
                                    Text(device.deviceName ?? device.platform.uppercased())
                                        .font(.subheadline)
                                    Text("\(device.osVersion ?? device.platform) · 最近活跃 \(SCDateFormatter.displayDateTime(device.lastActiveAt))")
                                        .font(.caption2)
                                        .foregroundStyle(.secondary)
                                }
                                Spacer()
                                if let token = device.pushToken, !token.isEmpty {
                                    StatusBadge(text: "锁屏推送", color: .green)
                                } else {
                                    StatusBadge(text: "仅站内通知", color: .blue)
                                }
                            }
                        }
                    }
                }

                Section {
                    Button(role: .destructive) {
                        showLogoutAlert = true
                    } label: {
                        HStack {
                            Spacer()
                            Label("退出登录", systemImage: "rectangle.portrait.and.arrow.right")
                                .font(.headline)
                            Spacer()
                        }
                    }
                } footer: {
                    HStack {
                        Spacer()
                        Text("智慧校园 \(appVersionDisplay)")
                            .font(.caption.monospacedDigit())
                            .foregroundStyle(.secondary)
                        Spacer()
                    }
                    .padding(.top, 12)
                }
            }
            .navigationTitle("我的")
            .sheet(isPresented: $showChangePassword) {
                ChangePasswordSheet()
            }
            .sheet(isPresented: $showServerSwitch) {
                NavigationStack {
                    ServerSetupView(isInitialSetup: false)
                }
            }
            .alert("确定要退出登录吗？", isPresented: $showLogoutAlert) {
                Button("取消", role: .cancel) {}
                Button("退出登录", role: .destructive) {
                    Task { await SessionManager.shared.logout() }
                }
            } message: {
                Text("退出后将解绑当前手机的推送通知，需要重新登录才能查看考勤信息。")
            }
            .task {
                await pushManager.refreshAuthorizationStatus()
                await pushManager.refreshUnreadCount()
                await loadDevices()
                await loadWechatConfig()
            }
            .onChange(of: pushManager.lastSyncedAt) { _, _ in
                Task { await loadDevices() }
            }
        }
    }

    private var authStatusText: String {
        switch pushManager.authorizationStatus {
        case .authorized, .provisional, .ephemeral: return "已开启"
        case .denied: return "已关闭（可在系统设置开启）"
        case .notDetermined: return "未申请"
        @unknown default: return "未知"
        }
    }

    private var pushGatewayStatusText: String {
        let envLabel = pushManager.apnsEnvironment == "development" ? "开发" : "生产"
        if pushManager.pushGatewayConfiguredOnServer && pushManager.apnsDeviceToken != nil {
            return "锁屏推送已就绪（\(envLabel)）"
        }
        if pushManager.apnsDeviceToken != nil {
            return "本机已拿 Token（\(envLabel)）"
        }
        return "站内消息模式"
    }

    private var appVersionDisplay: String {
        let shortVer = Bundle.main.infoDictionary?["CFBundleShortVersionString"] as? String ?? "1.0.0"
        let buildVer = Bundle.main.infoDictionary?["CFBundleVersion"] as? String ?? "1"
        return "\(shortVer)(\(buildVer))"
    }

    private func loadDevices() async {
        if let list = try? await NotificationRepository.fetchDevices() {
            devices = list
        }
    }

    private func loadWechatConfig() async {
        guard user.role == "teacher" else { return }
        if let res = try? await NotificationRepository.fetchTeacherWechatConfig() {
            wechatConfigRes = res
            if let cfg = res.config {
                wechatPushEnabled = cfg.isPushEnabled
            }
        }
    }

    private func updateWechatPushEnabled(_ nextEnabled: Bool) async {
        guard !isTogglingWechatPush else { return }
        let previous = wechatPushEnabled
        wechatPushEnabled = nextEnabled
        isTogglingWechatPush = true
        wechatToggleFeedback = nil
        wechatToggleIsError = false

        do {
            let res = try await NotificationRepository.toggleTeacherWechatPush(enabled: nextEnabled)
            let finalState = res.pushEnabled?.value ?? nextEnabled
            wechatPushEnabled = finalState
            wechatToggleFeedback = res.message ?? (finalState ? "已开启公众号推送" : "已关闭公众号推送")
            wechatToggleIsError = false
            isTogglingWechatPush = false
            let shown = wechatToggleFeedback
            try? await Task.sleep(for: .seconds(2.5))
            if wechatToggleFeedback == shown {
                wechatToggleFeedback = nil
            }
        } catch {
            wechatPushEnabled = previous
            wechatToggleIsError = true
            wechatToggleFeedback = "切换失败：\(error.localizedDescription)"
            isTogglingWechatPush = false
        }
    }
}

private struct ChangePasswordSheet: View {
    @Environment(\.dismiss) private var dismiss
    @State private var currentPassword = ""
    @State private var newPassword = ""
    @State private var confirmPassword = ""
    @State private var isSubmitting = false
    @State private var errorMessage: String?
    @State private var successMessage: String?

    var body: some View {
        NavigationStack {
            Form {
                if let errorMessage {
                    Section {
                        Text(errorMessage).foregroundStyle(.red).font(.footnote)
                    }
                }
                if let successMessage {
                    Section {
                        Text(successMessage).foregroundStyle(.green).font(.footnote)
                    }
                }

                Section("密码信息") {
                    SecureField("当前密码", text: $currentPassword)
                    SecureField("新密码（至少 6 位）", text: $newPassword)
                    SecureField("确认新密码", text: $confirmPassword)
                }

                Section {
                    Button {
                        Task { await submit() }
                    } label: {
                        HStack {
                            Spacer()
                            if isSubmitting { ProgressView() }
                            Text("保存新密码").font(.headline)
                            Spacer()
                        }
                    }
                    .disabled(isSubmitting || currentPassword.isEmpty || newPassword.count < 6 || newPassword != confirmPassword)
                }
            }
            .navigationTitle("修改密码")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("取消") { dismiss() }
                }
            }
        }
    }

    private func submit() async {
        errorMessage = nil
        successMessage = nil
        isSubmitting = true
        defer { isSubmitting = false }

        do {
            try await NotificationRepository.changePassword(
                currentPassword: currentPassword,
                newPassword: newPassword,
                confirmPassword: confirmPassword
            )
            successMessage = "密码修改成功"
            try? await Task.sleep(for: .seconds(0.8))
            dismiss()
        } catch {
            errorMessage = error.localizedDescription
        }
    }
}
