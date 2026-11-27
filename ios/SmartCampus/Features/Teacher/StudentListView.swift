import SwiftUI

struct StudentListView: View {
    let user: User

    @State private var classes: [ClassOptionItem] = []
    @State private var selectedClassId: Int?
    @State private var students: [StudentListItem] = []
    @State private var searchText: String = ""
    @State private var isLoading = false
    @State private var errorMessage: String?

    private var filteredStudents: [StudentListItem] {
        let q = searchText.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        guard !q.isEmpty else { return students }
        return students.filter { s in
            s.name.lowercased().contains(q)
                || (s.studentNo ?? "").lowercased().contains(q)
                || (s.className ?? "").lowercased().contains(q)
        }
    }

    var body: some View {
        NavigationStack {
            List {
                if let errorMessage {
                    Section {
                        ErrorBannerView(message: errorMessage) {
                            Task { await loadStudents() }
                        }
                    }
                }

                if classes.count > 1 {
                    Section {
                        Picker("按班级筛选", selection: $selectedClassId) {
                            Text("全部管辖班级").tag(Int?.none)
                            ForEach(classes) { cls in
                                Text(cls.name).tag(Int?.some(cls.id))
                            }
                        }
                    }
                }

                Section("学生名单（\(filteredStudents.count)）") {
                    if isLoading && students.isEmpty {
                        ProgressView("加载学生名单...")
                    } else if filteredStudents.isEmpty {
                        Text("暂无学生数据")
                            .foregroundStyle(.secondary)
                    } else {
                        ForEach(filteredStudents) { student in
                            NavigationLink {
                                StudentDetailManageView(student: student) {
                                    Task { await loadStudents() }
                                }
                            } label: {
                                HStack(spacing: 12) {
                                    ZStack {
                                        Circle()
                                            .fill(SCTheme.primary.opacity(0.12))
                                            .frame(width: 40, height: 40)
                                        Text(String(student.name.prefix(1)))
                                            .font(.subheadline.bold())
                                            .foregroundStyle(SCTheme.primary)
                                    }

                                    VStack(alignment: .leading, spacing: 3) {
                                        HStack(spacing: 6) {
                                            Text(student.name)
                                                .font(.subheadline.weight(.semibold))
                                            if student.isBoardingFlag {
                                                StatusBadge(text: "住宿", color: .indigo)
                                            }
                                            if student.isClassAdminFlag {
                                                StatusBadge(text: "考勤员", color: .blue)
                                            }
                                            if student.activeBoardingSuspension != nil {
                                                StatusBadge(text: "停宿中", color: .purple)
                                            }
                                        }
                                        Text("\(student.className ?? "-") · 学号 \(student.studentNo ?? "-")")
                                            .font(.caption)
                                            .foregroundStyle(.secondary)
                                    }
                                }
                                .padding(.vertical, 2)
                            }
                        }
                    }
                }
            }
            .listStyle(.insetGrouped)
            .navigationTitle("学生管理")
            .searchable(text: $searchText, prompt: "搜索姓名、学号或班级")
            .safeRefreshable {
                await loadStudents()
            }
            .onChange(of: selectedClassId) { _, _ in
                Task { await loadStudents() }
            }
            .task {
                if classes.isEmpty {
                    classes = (try? await AttendanceRepository.fetchClasses()) ?? []
                }
                await loadStudents()
            }
        }
    }

    private func loadStudents() async {
        isLoading = true
        errorMessage = nil
        defer { isLoading = false }

        do {
            let res = try await EveningStudyRepository.fetchStudents(classId: selectedClassId, perPage: 200)
            students = res.data
        } catch {
            errorMessage = error.localizedDescription
        }
    }
}

/// 单个学生详情管理：基础信息编辑、班级考勤员开关、临时停宿许可办理与撤销、个人考勤记录查看
struct StudentDetailManageView: View {
    let onUpdated: () -> Void

    @State private var currentStudent: StudentListItem
    @State private var isClassAdmin: Bool
    @State private var suspensions: [BoardingSuspension] = []
    @State private var records: [AttendanceRecord] = []
    @State private var parentAccounts: [StudentParentAccount] = []
    @State private var parentAccountsError: String?
    @State private var resetParentTarget: StudentParentAccount?
    @State private var resetParentCustomPwd: String = ""
    @State private var isLoading = false
    @State private var errorMessage: String?
    @State private var successBanner: String?

    @State private var showEditBasicInfo = false
    @State private var showCreateSuspension = false
    @State private var revokingSuspension: BoardingSuspension?
    @State private var revokeReason: String = ""

    init(student: StudentListItem, onUpdated: @escaping () -> Void) {
        self.onUpdated = onUpdated
        _currentStudent = State(initialValue: student)
        _isClassAdmin = State(initialValue: student.isClassAdminFlag)
    }

    var body: some View {
        List {
            if let errorMessage {
                Section {
                    ErrorBannerView(message: errorMessage)
                }
            }

            if let successBanner {
                Section {
                    HStack(spacing: 8) {
                        Image(systemName: "checkmark.circle.fill")
                            .foregroundStyle(.green)
                        Text(successBanner)
                            .font(.subheadline)
                            .foregroundStyle(.primary)
                    }
                }
            }

            Section {
                LabeledContent("姓名", value: currentStudent.name)
                LabeledContent("学号", value: currentStudent.studentNo ?? "-")
                LabeledContent("性别", value: currentStudent.genderDisplayText)
                LabeledContent("班级", value: currentStudent.className ?? "-")
                LabeledContent("住宿状态", value: currentStudent.isBoardingFlag ? "住宿生" : "走读生")
                LabeledContent("账号 (Email)", value: (currentStudent.email?.isEmpty == false) ? (currentStudent.email ?? "-") : "未填写")
                LabeledContent("家长电话", value: (currentStudent.parentContact?.isEmpty == false) ? (currentStudent.parentContact ?? "-") : "未填写")
                LabeledContent("家长邮箱", value: (currentStudent.parentEmail?.isEmpty == false) ? (currentStudent.parentEmail ?? "-") : "未填写")

                Button {
                    successBanner = nil
                    errorMessage = nil
                    showEditBasicInfo = true
                } label: {
                    Label("修改基础信息", systemImage: "square.and.pencil")
                        .font(.subheadline.weight(.semibold))
                }
            } header: {
                Text("基础信息")
            }

            Section("父母端账号") {
                if let parentAccountsError {
                    VStack(alignment: .leading, spacing: 6) {
                        Text("无法读取父母端账号（请确认后端服务已更新并完成数据库迁移）")
                            .font(.caption.weight(.medium))
                            .foregroundStyle(.red)
                        Text(parentAccountsError)
                            .font(.caption2)
                            .foregroundStyle(.secondary)
                        Button("重试加载") {
                            Task { await loadDetail() }
                        }
                        .font(.caption.weight(.semibold))
                    }
                    .padding(.vertical, 2)
                } else if isLoading && parentAccounts.isEmpty {
                    ProgressView("正在加载父母端账号...")
                } else if parentAccounts.isEmpty {
                    if let email = currentStudent.parentEmail, !email.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
                        Text("已有家长邮箱但尚未同步到父母端账号，请点击上方“修改基础信息”保存一次即可自动生成。")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    } else {
                        Text("尚未关联父母端账号，请先在上方填写家长邮箱")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                } else {
                    ForEach(parentAccounts) { pa in
                        HStack(spacing: 10) {
                            VStack(alignment: .leading, spacing: 3) {
                                Text(pa.email)
                                    .font(.subheadline.weight(.medium))
                                HStack(spacing: 6) {
                                    Text(pa.isStaff ? "教职工复用账号" : "独立家长账号")
                                        .font(.caption2)
                                        .foregroundStyle(.secondary)
                                    StatusBadge(
                                        text: pa.isPushReady ? "推送就绪" : "站内通知",
                                        color: pa.isPushReady ? .green : .gray
                                    )
                                }
                            }
                            Spacer()
                            if pa.canReset {
                                Button("重置密码") {
                                    resetParentCustomPwd = ""
                                    resetParentTarget = pa
                                }
                                .font(.caption.weight(.semibold))
                                .buttonStyle(.bordered)
                            }
                        }
                        .padding(.vertical, 2)
                    }
                }
            }

            Section("班级职务权限") {
                Toggle("班级考勤员（可代管考勤与审批）", isOn: Binding(
                    get: { isClassAdmin },
                    set: { _ in Task { await toggleClassAdmin() } }
                ))
            }

            if currentStudent.isBoardingFlag {
                Section {
                    Button {
                        showCreateSuspension = true
                    } label: {
                        Label("办理临时暂停住宿许可", systemImage: "moon.zzz")
                    }

                    ForEach(suspensions) { item in
                        VStack(alignment: .leading, spacing: 6) {
                            HStack {
                                Text("\(item.startDateDisplay) 至 \(item.endDateDisplay)")
                                    .font(.subheadline.weight(.semibold))
                                Spacer()
                                StatusBadge(
                                    text: item.isRevoked ? "已撤销" : "生效中",
                                    color: item.isRevoked ? .gray : .purple
                                )
                            }
                            Text("原因：\(item.reason)")
                                .font(.caption)
                                .foregroundStyle(.secondary)
                            if let dest = item.destination, !dest.isEmpty {
                                Text("去向：\(dest)")
                                    .font(.caption)
                                    .foregroundStyle(.indigo)
                            }
                            if !item.isRevoked {
                                Button("撤销停宿许可", role: .destructive) {
                                    revokeReason = ""
                                    revokingSuspension = item
                                }
                                .font(.caption.weight(.semibold))
                                .padding(.top, 2)
                            }
                        }
                        .padding(.vertical, 4)
                    }
                } header: {
                    Text("临时停宿管理")
                }
            }

            Section("本月异常考勤记录") {
                if isLoading {
                    ProgressView("加载中...")
                } else if records.isEmpty {
                    Text("本月暂无异常考勤记录")
                        .foregroundStyle(.secondary)
                } else {
                    ForEach(records) { rec in
                        HStack {
                            StatusBadge(text: rec.titleText, color: SCTheme.color(for: rec.badgeColorKey))
                            VStack(alignment: .leading, spacing: 2) {
                                Text(rec.dateKey).font(.subheadline)
                                let detail = rec.resolvedDetailText
                                if !detail.isEmpty {
                                    Text(detail).font(.caption).foregroundStyle(.secondary)
                                }
                            }
                        }
                    }
                }
            }
        }
        .navigationTitle(currentStudent.name)
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            ToolbarItem(placement: .topBarTrailing) {
                Button("编辑") {
                    successBanner = nil
                    errorMessage = nil
                    showEditBasicInfo = true
                }
            }
        }
        .sheet(isPresented: $showEditBasicInfo) {
            EditStudentBasicInfoSheet(student: currentStudent) { updated in
                currentStudent = updated
                successBanner = "基础信息已保存"
                onUpdated()
                Task { await loadDetail() }
            }
        }
        .alert("重置家长密码", isPresented: Binding(
            get: { resetParentTarget != nil },
            set: { if !$0 { resetParentTarget = nil } }
        )) {
            TextField("留空将重置为系统默认密码（至少6位）", text: $resetParentCustomPwd)
                .textInputAutocapitalization(.never)
                .autocorrectionDisabled()
            Button("取消", role: .cancel) {}
            Button("确认重置") {
                if let target = resetParentTarget {
                    Task { await performResetParentPassword(target) }
                }
            }
        } message: {
            if let target = resetParentTarget {
                Text("将重置家长账号 \(target.email) 的登录密码。")
            }
        }
        .sheet(isPresented: $showCreateSuspension) {
            CreateSuspensionSheet(studentId: currentStudent.id, studentName: currentStudent.name) {
                Task {
                    await loadDetail()
                    onUpdated()
                }
            }
        }
        .alert("撤销暂停住宿许可", isPresented: Binding(
            get: { revokingSuspension != nil },
            set: { if !$0 { revokingSuspension = nil } }
        )) {
            TextField("请输入撤销原因", text: $revokeReason)
            Button("取消", role: .cancel) {}
            Button("确认撤销", role: .destructive) {
                if let target = revokingSuspension {
                    Task { await revoke(target) }
                }
            }
        }
        .task {
            await loadDetail()
        }
    }

    private func loadDetail() async {
        isLoading = true
        defer { isLoading = false }

        if currentStudent.isBoardingFlag {
            suspensions = (try? await EveningStudyRepository.fetchSuspensions(studentId: currentStudent.id)) ?? []
        } else {
            suspensions = []
        }
        records = (try? await AttendanceRepository.fetchStudentRecords(studentId: currentStudent.id, scope: "month")) ?? []
        do {
            parentAccounts = try await ParentRepository.fetchStudentParents(studentId: currentStudent.id)
            parentAccountsError = nil
        } catch {
            parentAccounts = []
            parentAccountsError = error.localizedDescription
        }
    }

    private func performResetParentPassword(_ target: StudentParentAccount) async {
        let trimmed = resetParentCustomPwd.trimmingCharacters(in: .whitespacesAndNewlines)
        if !trimmed.isEmpty && trimmed.count < 6 {
            errorMessage = "新密码长度不能少于 6 位"
            return
        }
        errorMessage = nil
        do {
            let msg = try await ParentRepository.resetStudentParentPassword(
                studentId: currentStudent.id,
                parentUserId: target.id,
                password: trimmed.isEmpty ? nil : trimmed
            )
            successBanner = msg
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    private func toggleClassAdmin() async {
        do {
            isClassAdmin = try await EveningStudyRepository.toggleClassAdmin(studentId: currentStudent.id)
            currentStudent.isClassAdmin = FlexibleBool(isClassAdmin)
            onUpdated()
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    private func revoke(_ item: BoardingSuspension) async {
        let reason = revokeReason.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !reason.isEmpty else {
            errorMessage = "请填写撤销原因"
            return
        }
        do {
            try await EveningStudyRepository.revokeSuspension(id: item.id, reason: reason)
            await loadDetail()
            onUpdated()
        } catch {
            errorMessage = error.localizedDescription
        }
    }
}

private struct EditStudentBasicInfoSheet: View {
    let student: StudentListItem
    let onSaved: (StudentListItem) -> Void
    @Environment(\.dismiss) private var dismiss

    @State private var name: String
    @State private var studentNo: String
    @State private var gender: String
    @State private var isBoarding: Bool
    @State private var email: String
    @State private var parentContact: String
    @State private var parentEmail: String
    @State private var newPassword: String = ""

    @State private var isSubmitting = false
    @State private var errorMessage: String?

    init(student: StudentListItem, onSaved: @escaping (StudentListItem) -> Void) {
        self.student = student
        self.onSaved = onSaved
        _name = State(initialValue: student.name)
        _studentNo = State(initialValue: student.studentNo ?? "")
        _gender = State(initialValue: student.normalizedGender)
        _isBoarding = State(initialValue: student.isBoardingFlag)
        _email = State(initialValue: student.email ?? "")
        _parentContact = State(initialValue: student.parentContact ?? "")
        _parentEmail = State(initialValue: student.parentEmail ?? "")
    }

    private var canSubmit: Bool {
        !isSubmitting
            && !name.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
            && !studentNo.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
            && !email.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
    }

    var body: some View {
        NavigationStack {
            Form {
                if let errorMessage {
                    Section {
                        ErrorBannerView(message: errorMessage)
                    }
                }

                Section("学生信息") {
                    TextField("姓名（必填）", text: $name)
                    TextField("学号（必填）", text: $studentNo)
                        .textInputAutocapitalization(.never)
                        .autocorrectionDisabled()
                    Picker("性别", selection: $gender) {
                        Text("男").tag("male")
                        Text("女").tag("female")
                        Text("其他").tag("other")
                    }
                    Toggle("住宿生", isOn: $isBoarding)
                }

                Section {
                    TextField("家长联系电话", text: $parentContact)
                        .keyboardType(.phonePad)
                    TextField("家长邮箱（多个请用逗号分隔）", text: $parentEmail, axis: .vertical)
                        .lineLimit(1...3)
                        .keyboardType(.emailAddress)
                        .textInputAutocapitalization(.never)
                        .autocorrectionDisabled()
                } header: {
                    Text("家长联系信息")
                } footer: {
                    Text("家长邮箱最多支持 10 个，多个地址请用逗号分隔，留空表示清除。")
                }

                Section {
                    TextField("学生登录账号 Email（必填）", text: $email)
                        .keyboardType(.emailAddress)
                        .textInputAutocapitalization(.never)
                        .autocorrectionDisabled()
                    SecureField("重置密码（留空则不修改，至少 6 位）", text: $newPassword)
                        .textInputAutocapitalization(.never)
                        .autocorrectionDisabled()
                } header: {
                    Text("登录账号与密码")
                }

                Section {
                    Button {
                        Task { await submit() }
                    } label: {
                        HStack {
                            Spacer()
                            if isSubmitting { ProgressView().tint(.white) }
                            Text(isSubmitting ? "保存中..." : "保存修改")
                                .font(.headline)
                            Spacer()
                        }
                    }
                    .listRowBackground(canSubmit ? SCTheme.primary : Color.gray.opacity(0.4))
                    .foregroundStyle(.white)
                    .disabled(!canSubmit)
                }
            }
            .navigationTitle("编辑学生信息")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("取消") { dismiss() }
                        .disabled(isSubmitting)
                }
            }
        }
    }

    private func submit() async {
        let trimmedName = name.trimmingCharacters(in: .whitespacesAndNewlines)
        let trimmedNo = studentNo.trimmingCharacters(in: .whitespacesAndNewlines)
        let trimmedEmail = email.trimmingCharacters(in: .whitespacesAndNewlines)
        let trimmedContact = parentContact.trimmingCharacters(in: .whitespacesAndNewlines)
        let trimmedParentEmail = parentEmail.trimmingCharacters(in: .whitespacesAndNewlines)
        let trimmedPassword = newPassword.trimmingCharacters(in: .whitespacesAndNewlines)

        guard !trimmedName.isEmpty, !trimmedNo.isEmpty, !trimmedEmail.isEmpty else {
            errorMessage = "姓名、学号与学生账号（Email）不能为空"
            return
        }
        if !trimmedPassword.isEmpty && trimmedPassword.count < 6 {
            errorMessage = "重置密码长度不能少于 6 位"
            return
        }

        isSubmitting = true
        errorMessage = nil
        defer { isSubmitting = false }

        do {
            let normalizedParentEmail = try await EveningStudyRepository.updateStudentBasicInfo(
                studentId: student.id,
                name: trimmedName,
                studentNo: trimmedNo,
                gender: gender,
                isBoarding: isBoarding,
                email: trimmedEmail,
                parentContact: trimmedContact,
                parentEmail: trimmedParentEmail,
                password: trimmedPassword.isEmpty ? nil : trimmedPassword
            )

            var updated = student
            updated.name = trimmedName
            updated.studentNo = trimmedNo
            updated.gender = gender
            updated.isBoarding = FlexibleBool(isBoarding)
            updated.email = trimmedEmail
            updated.parentContact = trimmedContact.isEmpty ? nil : trimmedContact
            updated.parentEmail = normalizedParentEmail ?? (trimmedParentEmail.isEmpty ? nil : trimmedParentEmail)

            dismiss()
            onSaved(updated)
        } catch {
            errorMessage = error.localizedDescription
        }
    }
}

private struct CreateSuspensionSheet: View {
    let studentId: Int
    let studentName: String
    let onCreated: () -> Void
    @Environment(\.dismiss) private var dismiss

    @State private var startDate = Date()
    @State private var endDate = Date()
    @State private var reason = ""
    @State private var destination = ""
    @State private var isSubmitting = false
    @State private var errorMessage: String?

    var body: some View {
        NavigationStack {
            Form {
                if let errorMessage {
                    Section { ErrorBannerView(message: errorMessage) }
                }

                Section("停宿时间与去向") {
                    DatePicker("开始日期", selection: $startDate, displayedComponents: .date)
                    DatePicker("结束日期", selection: $endDate, in: startDate..., displayedComponents: .date)
                    TextField("暂停住宿原因（必填）", text: $reason, axis: .vertical).lineLimit(2...4)
                    TextField("具体去向（可选，如回家）", text: $destination)
                }

                Section {
                    Button {
                        Task { await submit() }
                    } label: {
                        HStack {
                            Spacer()
                            if isSubmitting { ProgressView().tint(.white) }
                            Text("确认办理").font(.headline)
                            Spacer()
                        }
                    }
                    .listRowBackground(SCTheme.primary)
                    .foregroundStyle(.white)
                    .disabled(isSubmitting || reason.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty)
                }
            }
            .navigationTitle("办理暂停住宿 - \(studentName)")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("取消") { dismiss() }
                }
            }
            .onChange(of: startDate) { _, newVal in
                if endDate < newVal { endDate = newVal }
            }
        }
    }

    private func submit() async {
        isSubmitting = true
        errorMessage = nil
        defer { isSubmitting = false }

        do {
            _ = try await EveningStudyRepository.createSuspension(
                studentId: studentId,
                startDate: SCDateFormatter.yyyyMMdd.string(from: startDate),
                endDate: SCDateFormatter.yyyyMMdd.string(from: endDate),
                reason: reason.trimmingCharacters(in: .whitespacesAndNewlines),
                destination: destination
            )
            dismiss()
            onCreated()
        } catch {
            errorMessage = error.localizedDescription
        }
    }
}
