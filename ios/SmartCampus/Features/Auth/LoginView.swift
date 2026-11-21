import SwiftUI

struct LoginView: View {
    @State private var serverConfig = ServerConfigManager.shared
    @State private var account: String = ""
    @State private var password: String = ""
    @State private var showPassword: Bool = false
    @State private var isLoading: Bool = false
    @State private var errorMessage: String?
    @State private var showServerSheet: Bool = false

    @FocusState private var focusedField: Field?
    private enum Field { case account, password }

    var body: some View {
        ScrollView {
            VStack(spacing: 28) {
                // Header
                VStack(spacing: 12) {
                    Image(systemName: "shield.lefthalf.filled.badge.checkmark")
                        .font(.system(size: 64))
                        .foregroundStyle(SCTheme.primary)
                        .padding(.top, 28)

                    Text(serverConfig.activeSchoolName)
                        .font(.title2.bold())
                        .multilineTextAlignment(.center)

                    Text("智慧校园考勤系统")
                        .font(.subheadline)
                        .foregroundStyle(.secondary)

                    Button {
                        showServerSheet = true
                    } label: {
                        HStack(spacing: 6) {
                            Image(systemName: "network")
                                .font(.caption)
                            Text(serverConfig.activeBaseURL ?? "未配置服务器")
                                .font(.caption)
                                .lineLimit(1)
                            Image(systemName: "chevron.right")
                                .font(.caption2)
                        }
                        .padding(.horizontal, 12)
                        .padding(.vertical, 6)
                        .background(SCTheme.primary.opacity(0.1))
                        .foregroundStyle(SCTheme.primary)
                        .clipShape(Capsule())
                    }
                }

                // Form Card
                SCCard(padding: 20) {
                    VStack(spacing: 16) {
                        if let errorMessage {
                            ErrorBannerView(message: errorMessage)
                        }

                        VStack(alignment: .leading, spacing: 6) {
                            Text("邮箱 / 学号")
                                .font(.subheadline.weight(.medium))
                                .foregroundStyle(.secondary)
                            HStack(spacing: 10) {
                                Image(systemName: "person.text.rectangle")
                                    .foregroundStyle(.secondary)
                                TextField("输入邮箱或学号", text: $account)
                                    .textInputAutocapitalization(.never)
                                    .autocorrectionDisabled(true)
                                    .textContentType(.username)
                                    .focused($focusedField, equals: .account)
                                    .submitLabel(.next)
                                    .onSubmit { focusedField = .password }
                            }
                            .padding(12)
                            .background(Color(.tertiarySystemFill))
                            .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
                        }

                        VStack(alignment: .leading, spacing: 6) {
                            Text("密码")
                                .font(.subheadline.weight(.medium))
                                .foregroundStyle(.secondary)
                            HStack(spacing: 10) {
                                Image(systemName: "lock")
                                    .foregroundStyle(.secondary)
                                Group {
                                    if showPassword {
                                        TextField("输入登录密码", text: $password)
                                    } else {
                                        SecureField("输入登录密码", text: $password)
                                    }
                                }
                                .textInputAutocapitalization(.never)
                                .autocorrectionDisabled(true)
                                .textContentType(.password)
                                .focused($focusedField, equals: .password)
                                .submitLabel(.go)
                                .onSubmit {
                                    Task { await performLogin() }
                                }

                                Button {
                                    showPassword.toggle()
                                } label: {
                                    Image(systemName: showPassword ? "eye.slash" : "eye")
                                        .foregroundStyle(.secondary)
                                }
                            }
                            .padding(12)
                            .background(Color(.tertiarySystemFill))
                            .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
                        }

                        Button {
                            Task { await performLogin() }
                        } label: {
                            HStack(spacing: 8) {
                                if isLoading {
                                    ProgressView()
                                        .tint(.white)
                                }
                                Text(isLoading ? "登录中..." : "登录")
                                    .font(.headline)
                            }
                            .frame(maxWidth: .infinity)
                            .padding(.vertical, 13)
                        }
                        .buttonStyle(.borderedProminent)
                        .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
                        .disabled(isLoading || account.trimmingCharacters(in: .whitespaces).isEmpty || password.isEmpty)
                        .padding(.top, 4)
                    }
                }
                .padding(.horizontal)

                Text("支持学生、班主任、值班教师及管理人员统一登录")
                    .font(.caption)
                    .foregroundStyle(.tertiary)
            }
        }
        .background(SCTheme.background.ignoresSafeArea())
        .sheet(isPresented: $showServerSheet) {
            NavigationStack {
                ServerSetupView(isInitialSetup: false)
            }
        }
    }

    private func performLogin() async {
        focusedField = nil
        errorMessage = nil
        isLoading = true
        defer { isLoading = false }

        do {
            try await SessionManager.shared.login(account: account, password: password)
        } catch {
            errorMessage = error.localizedDescription
        }
    }
}
