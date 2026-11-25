import SwiftUI

struct LoginView: View {
    @State private var serverConfig = ServerConfigManager.shared
    @State private var account: String = ""
    @State private var password: String = ""
    @State private var showPassword: Bool = false
    @State private var isLoading: Bool = false
    @State private var errorMessage: String?
    @State private var showServerSheet: Bool = false
    @State private var showForgotPasswordSheet: Bool = false

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
                            HStack {
                                Text("密码")
                                    .font(.subheadline.weight(.medium))
                                    .foregroundStyle(.secondary)
                                Spacer()
                                Button("忘记密码？") {
                                    showForgotPasswordSheet = true
                                }
                                .font(.caption.weight(.medium))
                                .foregroundStyle(SCTheme.primary)
                            }
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

                Text("支持学生（学号/邮箱）、教职工及家长邮箱统一登录")
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
        .sheet(isPresented: $showForgotPasswordSheet) {
            ForgotPasswordSheet(initialEmail: account.contains("@") ? account : "") { resetEmail in
                account = resetEmail
                password = ""
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

private struct ForgotPasswordSheet: View {
    let initialEmail: String
    let onCompleted: (String) -> Void
    @Environment(\.dismiss) private var dismiss

    @State private var email: String
    @State private var code: String = ""
    @State private var newPassword: String = ""
    @State private var confirmPassword: String = ""
    @State private var codeSent = false
    @State private var isSending = false
    @State private var isSubmitting = false
    @State private var infoMessage: String?
    @State private var errorMessage: String?

    init(initialEmail: String, onCompleted: @escaping (String) -> Void) {
        self.initialEmail = initialEmail
        self.onCompleted = onCompleted
        _email = State(initialValue: initialEmail)
    }

    var body: some View {
        NavigationStack {
            Form {
                if let errorMessage {
                    Section { ErrorBannerView(message: errorMessage) }
                }
                if let infoMessage {
                    Section {
                        Text(infoMessage)
                            .font(.footnote)
                            .foregroundStyle(.green)
                    }
                }

                Section("1. 验证登录邮箱") {
                    TextField("输入家长/教师/学生登录邮箱", text: $email)
                        .keyboardType(.emailAddress)
                        .textInputAutocapitalization(.never)
                        .autocorrectionDisabled()

                    Button {
                        Task { await sendCode() }
                    } label: {
                        HStack {
                            Spacer()
                            if isSending { ProgressView().padding(.trailing, 4) }
                            Text(isSending ? "正在发送..." : (codeSent ? "重新获取验证码" : "发送 6 位邮箱验证码"))
                                .font(.subheadline.weight(.semibold))
                            Spacer()
                        }
                    }
                    .disabled(isSending || email.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty)
                }

                if codeSent {
                    Section("2. 输入验证码与新密码") {
                        TextField("6 位邮箱验证码", text: $code)
                            .keyboardType(.numberPad)
                        SecureField("新密码（至少 6 位）", text: $newPassword)
                        SecureField("确认新密码", text: $confirmPassword)
                    }

                    Section {
                        Button {
                            Task { await submitReset() }
                        } label: {
                            HStack {
                                Spacer()
                                if isSubmitting { ProgressView().tint(.white) }
                                Text(isSubmitting ? "提交中..." : "确认重置密码")
                                    .font(.headline)
                                Spacer()
                            }
                        }
                        .listRowBackground(SCTheme.primary)
                        .foregroundStyle(.white)
                        .disabled(isSubmitting || code.count != 6 || newPassword.count < 6 || newPassword != confirmPassword)
                    }
                }
            }
            .navigationTitle("找回密码")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("关闭") { dismiss() }
                }
            }
        }
    }

    private func sendCode() async {
        isSending = true
        errorMessage = nil
        infoMessage = nil
        defer { isSending = false }

        do {
            let msg = try await ParentRepository.sendPasswordResetCode(email: email)
            codeSent = true
            infoMessage = msg
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    private func submitReset() async {
        isSubmitting = true
        errorMessage = nil
        infoMessage = nil
        defer { isSubmitting = false }

        do {
            let msg = try await ParentRepository.confirmPasswordReset(
                email: email,
                code: code,
                password: newPassword,
                confirmPassword: confirmPassword
            )
            infoMessage = msg
            try? await Task.sleep(for: .seconds(0.8))
            onCompleted(email.trimmingCharacters(in: .whitespacesAndNewlines))
            dismiss()
        } catch {
            errorMessage = error.localizedDescription
        }
    }
}
