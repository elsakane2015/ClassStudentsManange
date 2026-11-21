import SwiftUI

struct ProfileView: View {
    let user: User

    @State private var serverConfig = ServerConfigManager.shared
    @State private var pushManager = PushNotificationManager.shared
    @State private var showChangePassword = false
    @State private var showServerSwitch = false
    @State private var showLogoutAlert = false
    @State private var devices: [MobileDeviceItem] = []

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
                            .foregroundStyle(.secondary)
                    }

                    Button {
                        Task {
                            await pushManager.requestPermissionAndRegister()
                            await loadDevices()
                        }
                    } label: {
                        Label("刷新推送注册状态", systemImage: "arrow.clockwise")
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
                                    StatusBadge(text: "已注册", color: .green)
                                } else {
                                    StatusBadge(text: "站内通知", color: .blue)
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
        if pushManager.pushGatewayConfiguredOnServer && pushManager.apnsDeviceToken != nil {
            return "锁屏推送已就绪"
        }
        if pushManager.apnsDeviceToken != nil {
            return "设备已就绪（待服务端配证书）"
        }
        return "站内消息模式"
    }

    private func loadDevices() async {
        if let list = try? await NotificationRepository.fetchDevices() {
            devices = list
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
