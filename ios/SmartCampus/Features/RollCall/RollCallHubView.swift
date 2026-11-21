import SwiftUI

struct RollCallHubView: View {
    let user: User

    var body: some View {
        NavigationStack {
            RollCallHubContentView(user: user)
                .navigationTitle("课堂点名")
        }
    }
}

struct RollCallHubContentView: View {
    let user: User

    @State private var rollCallTypes: [RollCallType] = []
    @State private var inProgressCalls: [RollCall] = []
    @State private var historyCalls: [RollCall] = []
    @State private var historyScope: String = "month"
    @State private var isLoading = false
    @State private var errorMessage: String?

    @State private var showStartSheet = false
    @State private var activeRollCallToNavigate: RollCall?

    private let scopes: [(key: String, label: String)] = [
        ("today", "今日"),
        ("week", "本周"),
        ("month", "本月"),
        ("semester", "本学期")
    ]

    var body: some View {
        List {
            if let errorMessage {
                Section {
                    ErrorBannerView(message: errorMessage) {
                        Task { await loadAll() }
                    }
                }
            }

            // Quick Actions
            Section {
                Button {
                    showStartSheet = true
                } label: {
                    HStack(spacing: 12) {
                        Image(systemName: "plus.circle.fill")
                            .font(.title2)
                            .foregroundStyle(.white)
                        VStack(alignment: .leading, spacing: 2) {
                            Text("发起新点名")
                                .font(.headline)
                                .foregroundStyle(.white)
                            Text("选择点名类型立即开始走动点名（支持离线防丢）")
                                .font(.caption)
                                .foregroundStyle(.white.opacity(0.85))
                        }
                        Spacer()
                        Image(systemName: "chevron.right")
                            .foregroundStyle(.white.opacity(0.85))
                    }
                    .padding(.vertical, 6)
                }
                .listRowBackground(SCTheme.primary)
                .disabled(rollCallTypes.isEmpty)

                if user.isTeacherOrAdmin {
                    NavigationLink {
                        RollCallAdminsView()
                    } label: {
                        Label("管理班级点名员", systemImage: "person.badge.key")
                    }
                }
            }

            // In-Progress Section
            if !inProgressCalls.isEmpty {
                Section("进行中的点名（\(inProgressCalls.count)）") {
                    ForEach(inProgressCalls) { rc in
                        NavigationLink {
                            RollCallOperationView(rollCallId: rc.id)
                        } label: {
                            RollCallRowView(rollCall: rc)
                        }
                        .swipeActions(edge: .trailing) {
                            Button(role: .destructive) {
                                Task { await cancelRollCall(rc.id) }
                            } label: {
                                Label("取消", systemImage: "xmark.circle")
                            }
                        }
                    }
                }
            }

            // History Section
            Section {
                Picker("历史范围", selection: $historyScope) {
                    ForEach(scopes, id: \.key) { item in
                        Text(item.label).tag(item.key)
                    }
                }
                .pickerStyle(.segmented)
                .listRowInsets(EdgeInsets(top: 8, leading: 12, bottom: 8, trailing: 12))

                if isLoading && historyCalls.isEmpty {
                    HStack {
                        Spacer()
                        ProgressView("加载点名记录...")
                        Spacer()
                    }
                } else if historyCalls.isEmpty {
                    Text("该周期内暂无点名记录")
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                        .frame(maxWidth: .infinity, alignment: .center)
                        .padding(.vertical, 12)
                } else {
                    ForEach(historyCalls) { rc in
                        NavigationLink {
                            RollCallOperationView(rollCallId: rc.id)
                        } label: {
                            RollCallRowView(rollCall: rc)
                        }
                        .swipeActions(edge: .trailing) {
                            if rc.status == "cancelled" {
                                Button {
                                    Task { await restoreRollCall(rc.id) }
                                } label: {
                                    Label("恢复", systemImage: "arrow.uturn.backward")
                                }
                                .tint(.blue)
                            }
                            if user.isTeacherOrAdmin {
                                Button(role: .destructive) {
                                    Task { await deleteRollCall(rc.id) }
                                } label: {
                                    Label("删除", systemImage: "trash")
                                }
                            }
                        }
                    }
                }
            } header: {
                Text("点名历史")
            }
        }
        .listStyle(.insetGrouped)
        .refreshable {
            await loadAll()
        }
        .onChange(of: historyScope) { _, _ in
            Task { await loadHistory() }
        }
        .sheet(isPresented: $showStartSheet) {
            StartRollCallSheet(types: rollCallTypes) { created in
                Task { @MainActor in
                    try? await Task.sleep(for: .milliseconds(200))
                    activeRollCallToNavigate = created
                    await loadAll()
                }
            }
        }
        .navigationDestination(item: $activeRollCallToNavigate) { created in
            RollCallOperationView(rollCallId: created.id)
        }
        .onAppear {
            if !rollCallTypes.isEmpty || !inProgressCalls.isEmpty || !historyCalls.isEmpty {
                Task { await loadAll() }
            }
        }
        .task {
            await loadAll()
        }
    }

    private func loadAll() async {
        isLoading = true
        errorMessage = nil
        defer { isLoading = false }

        do {
            async let typesReq = RollCallRepository.fetchRollCallTypes()
            async let activeReq = RollCallRepository.fetchInProgress()
            async let histReq = RollCallRepository.fetchHistory(scope: historyScope)

            rollCallTypes = try await typesReq.filter(\.isActiveFlag)
            inProgressCalls = (try? await activeReq) ?? []
            historyCalls = (try? await histReq)?.data ?? []
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    private func loadHistory() async {
        if let res = try? await RollCallRepository.fetchHistory(scope: historyScope) {
            historyCalls = res.data
        }
    }

    private func cancelRollCall(_ id: Int) async {
        do {
            try await RollCallRepository.cancelRollCall(id: id)
            await loadAll()
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    private func restoreRollCall(_ id: Int) async {
        do {
            try await RollCallRepository.restoreRollCall(id: id)
            await loadAll()
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    private func deleteRollCall(_ id: Int) async {
        do {
            try await RollCallRepository.deleteRollCall(id: id)
            historyCalls.removeAll { $0.id == id }
        } catch {
            errorMessage = error.localizedDescription
        }
    }
}

private struct RollCallRowView: View {
    let rollCall: RollCall

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack {
                Text(rollCall.rollCallType?.name ?? "课堂点名")
                    .font(.subheadline.weight(.semibold))
                if let cls = rollCall.schoolClass?.name {
                    Text("· \(cls)")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
                Spacer()
                StatusBadge(text: rollCall.statusDisplayName, color: SCTheme.color(for: rollCall.status))
            }

            HStack(spacing: 12) {
                Label("总 \(rollCall.totalStudents)", systemImage: "person.2")
                Label("到 \(rollCall.presentCount)", systemImage: "checkmark.circle")
                    .foregroundStyle(.green)
                Label("假 \(rollCall.onLeaveCount)", systemImage: "doc.text")
                    .foregroundStyle(.blue)
                Label("缺 \(rollCall.absentCount)", systemImage: "xmark.circle")
                    .foregroundStyle(rollCall.absentCount > 0 ? .red : .secondary)
            }
            .font(.caption)

            if let time = rollCall.rollCallTime {
                Text(time)
                    .font(.caption2)
                    .foregroundStyle(.tertiary)
            }
        }
        .padding(.vertical, 4)
    }
}

private struct StartRollCallSheet: View {
    let types: [RollCallType]
    let onCreated: (RollCall) -> Void
    @Environment(\.dismiss) private var dismiss

    @State private var selectedTypeId: Int = 0
    @State private var rollCallDate: Date = Date()
    @State private var notes: String = ""
    @State private var isCreating = false
    @State private var errorMessage: String?

    var body: some View {
        NavigationStack {
            Form {
                if let errorMessage {
                    Section {
                        ErrorBannerView(message: errorMessage)
                    }
                }

                Section("点名设置") {
                    Picker("点名类型", selection: $selectedTypeId) {
                        ForEach(types) { t in
                            let classSuffix = t.schoolClass.map { "（\($0.name)）" } ?? ""
                            Text("\(t.name)\(classSuffix)").tag(t.id)
                        }
                    }

                    DatePicker("点名时间", selection: $rollCallDate)
                    TextField("备注（可选）", text: $notes)
                }

                Section {
                    Button {
                        Task { await create() }
                    } label: {
                        HStack {
                            Spacer()
                            if isCreating { ProgressView().tint(.white) }
                            Text("立即开始点名").font(.headline)
                            Spacer()
                        }
                    }
                    .listRowBackground(SCTheme.primary)
                    .foregroundStyle(.white)
                    .disabled(isCreating || selectedTypeId == 0)
                }
            }
            .navigationTitle("发起点名")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("取消") { dismiss() }
                }
            }
            .onAppear {
                if selectedTypeId == 0, let first = types.first {
                    selectedTypeId = first.id
                }
            }
        }
    }

    private func create() async {
        isCreating = true
        errorMessage = nil
        defer { isCreating = false }

        let fmt = DateFormatter()
        fmt.dateFormat = "yyyy-MM-dd HH:mm:ss"
        fmt.locale = Locale(identifier: "en_US_POSIX")
        let timeStr = fmt.string(from: rollCallDate)

        do {
            let created = try await RollCallRepository.startRollCall(
                rollCallTypeId: selectedTypeId,
                rollCallTime: timeStr,
                notes: notes
            )
            dismiss()
            onCreated(created)
        } catch {
            errorMessage = error.localizedDescription
        }
    }
}

struct RollCallAdminsView: View {
    @State private var classes: [ClassOptionItem] = []
    @State private var selectedClassId: Int = 0
    @State private var admins: [RollCallAdminItem] = []
    @State private var isLoading = false
    @State private var errorMessage: String?

    var body: some View {
        List {
            if let errorMessage {
                Section {
                    ErrorBannerView(message: errorMessage)
                }
            }

            if !classes.isEmpty {
                Section("选择班级") {
                    Picker("班级", selection: $selectedClassId) {
                        ForEach(classes) { cls in
                            Text(cls.name).tag(cls.id)
                        }
                    }
                }
            }

            Section("已授权点名员") {
                if isLoading {
                    ProgressView("加载中...")
                } else if admins.isEmpty {
                    Text("该班级暂未指定学生点名员")
                        .foregroundStyle(.secondary)
                } else {
                    ForEach(admins) { admin in
                        VStack(alignment: .leading, spacing: 4) {
                            HStack {
                                Text(admin.student?.name ?? "学生 #\(admin.studentId)")
                                    .font(.subheadline.weight(.semibold))
                                Spacer()
                                if admin.canModifyRecords?.value == true {
                                    StatusBadge(text: "可修改历史", color: .indigo)
                                }
                            }
                            if let authTypes = admin.authorizedTypes, !authTypes.isEmpty {
                                Text("授权点名：" + authTypes.map(\.name).joined(separator: "、"))
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                            }
                        }
                        .swipeActions {
                            Button(role: .destructive) {
                                Task { await removeAdmin(admin.id) }
                            } label: {
                                Label("移除", systemImage: "trash")
                            }
                        }
                    }
                }
            }
        }
        .navigationTitle("班级点名员")
        .navigationBarTitleDisplayMode(.inline)
        .onChange(of: selectedClassId) { _, _ in
            Task { await loadAdmins() }
        }
        .task {
            if let list = try? await AttendanceRepository.fetchClasses() {
                classes = list
                if let first = list.first {
                    selectedClassId = first.id
                }
            }
        }
    }

    private func loadAdmins() async {
        guard selectedClassId > 0 else { return }
        isLoading = true
        defer { isLoading = false }
        do {
            admins = try await RollCallRepository.fetchRollCallAdmins(classId: selectedClassId)
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    private func removeAdmin(_ id: Int) async {
        do {
            try await RollCallRepository.deleteRollCallAdmin(id: id)
            admins.removeAll { $0.id == id }
        } catch {
            errorMessage = error.localizedDescription
        }
    }
}
