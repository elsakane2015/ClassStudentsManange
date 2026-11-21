import SwiftUI

/// 动态学校服务器配置与切换页（支持多校独立部署，不写死任何固定域名）
struct ServerSetupView: View {
    var isInitialSetup: Bool = false
    @Environment(\.dismiss) private var dismiss

    @State private var serverConfig = ServerConfigManager.shared
    @State private var urlInput: String = ""
    @State private var isProbing = false
    @State private var errorMessage: String?
    @State private var verifiedSchool: (baseURL: String, schoolName: String)?

    var body: some View {
        Form {
            Section {
                VStack(alignment: .leading, spacing: 8) {
                    HStack(spacing: 12) {
                        Image(systemName: "building.columns.circle.fill")
                            .font(.system(size: 40))
                            .foregroundStyle(SCTheme.primary)
                        VStack(alignment: .leading, spacing: 2) {
                            Text("连接学校服务器")
                                .font(.headline)
                            Text("输入学校智慧校园考勤系统的访问域名或局域网地址")
                                .font(.caption)
                                .foregroundStyle(.secondary)
                        }
                    }
                    .padding(.vertical, 4)

                    TextField("例如：attendance.school.edu.cn 或 192.168.1.10:8080", text: $urlInput)
                        .textInputAutocapitalization(.never)
                        .autocorrectionDisabled(true)
                        .keyboardType(.URL)
                        .submitLabel(.go)
                        .padding(12)
                        .background(Color(.tertiarySystemFill))
                        .clipShape(RoundedRectangle(cornerRadius: 10, style: .continuous))
                        .onSubmit {
                            Task { await testAndConnect() }
                        }

                    if let errorMessage {
                        Text(errorMessage)
                            .font(.caption)
                            .foregroundStyle(.red)
                    }

                    if let verified = verifiedSchool {
                        HStack(spacing: 8) {
                            Image(systemName: "checkmark.circle.fill")
                                .foregroundStyle(.green)
                            VStack(alignment: .leading, spacing: 2) {
                                Text("已识别学校：\(verified.schoolName)")
                                    .font(.subheadline.weight(.semibold))
                                Text(verified.baseURL)
                                    .font(.caption2)
                                    .foregroundStyle(.secondary)
                            }
                        }
                        .padding(10)
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .background(Color.green.opacity(0.1))
                        .clipShape(RoundedRectangle(cornerRadius: 10, style: .continuous))
                    }

                    Button {
                        Task { await testAndConnect() }
                    } label: {
                        HStack {
                            if isProbing {
                                ProgressView()
                                    .tint(.white)
                            }
                            Text(isProbing ? "正在探测学校服务..." : "验证并连接")
                                .font(.headline)
                        }
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, 12)
                    }
                    .buttonStyle(.borderedProminent)
                    .disabled(isProbing || urlInput.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty)
                    .padding(.top, 4)
                }
            } header: {
                Text("服务器地址")
            } footer: {
                Text("支持自动补全 HTTPS/HTTP 协议；切换学校服务器后将退出当前账号并重新登录。")
            }

            if !serverConfig.savedServers.isEmpty {
                Section("已保存的学校站点") {
                    ForEach(serverConfig.savedServers) { server in
                        Button {
                            Task { await selectSavedServer(server) }
                        } label: {
                            HStack {
                                VStack(alignment: .leading, spacing: 4) {
                                    Text(server.schoolName)
                                        .font(.body.weight(.medium))
                                        .foregroundStyle(.primary)
                                    Text(server.baseURL)
                                        .font(.caption)
                                        .foregroundStyle(.secondary)
                                }
                                Spacer()
                                if serverConfig.activeBaseURL == server.baseURL {
                                    Image(systemName: "checkmark.circle.fill")
                                        .foregroundStyle(SCTheme.primary)
                                }
                            }
                        }
                    }
                    .onDelete { offsets in
                        for index in offsets {
                            serverConfig.removeSavedServer(serverConfig.savedServers[index])
                        }
                    }
                }
            }
        }
        .navigationTitle(isInitialSetup ? "配置学校服务器" : "切换学校服务器")
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            if !isInitialSetup {
                ToolbarItem(placement: .cancellationAction) {
                    Button("关闭") { dismiss() }
                }
            }
        }
        .onAppear {
            if urlInput.isEmpty, let active = serverConfig.activeBaseURL {
                urlInput = active
            }
        }
    }

    private func testAndConnect() async {
        errorMessage = nil
        verifiedSchool = nil
        isProbing = true
        defer { isProbing = false }

        do {
            let result = try await serverConfig.probeServer(urlInput)
            verifiedSchool = result
            await SessionManager.shared.switchServer(baseURL: result.baseURL, schoolName: result.schoolName)
            if !isInitialSetup {
                dismiss()
            }
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    private func selectSavedServer(_ server: SavedServer) async {
        errorMessage = nil
        isProbing = true
        defer { isProbing = false }

        if let probed = try? await serverConfig.probeServer(server.baseURL) {
            await SessionManager.shared.switchServer(baseURL: probed.baseURL, schoolName: probed.schoolName)
        } else {
            await SessionManager.shared.switchServer(baseURL: server.baseURL, schoolName: server.schoolName)
        }
        if !isInitialSetup {
            dismiss()
        }
    }
}
