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
    @State private var classPeriods: [ClassPeriod] = []
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
                            Text(user.isTeacherOrAdmin
                                ? "支持选择已有类型或按第1、2…节新建课程点名"
                                : "选择点名类型立即开始走动点名（支持离线防丢）")
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
                .disabled(!user.isTeacherOrAdmin && rollCallTypes.isEmpty)

                if user.isTeacherOrAdmin {
                    NavigationLink {
                        RollCallTypesManageView(user: user) { started in
                            activeRollCallToNavigate = started
                        }
                    } label: {
                        Label("管理点名类型 / 课程点名", systemImage: "list.bullet.rectangle")
                    }

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
        .safeRefreshable {
            await loadAll()
        }
        .onChange(of: historyScope) { _, _ in
            Task { await loadHistory() }
        }
        .sheet(isPresented: $showStartSheet) {
            StartRollCallSheet(
                user: user,
                types: rollCallTypes,
                initialPeriods: classPeriods
            ) { created in
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
            async let periodsReq = AttendanceRepository.fetchClassPeriods()

            rollCallTypes = try await typesReq.filter(\.isActiveFlag)
            inProgressCalls = (try? await activeReq) ?? []
            historyCalls = (try? await histReq)?.data ?? []
            if let fetchedPeriods = try? await periodsReq {
                classPeriods = fetchedPeriods.filter { !$0.isEveningStudy && $0.isActiveFlag }
            }
        } catch {
            guard !error.isCancellationError else { return }
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
    enum StartMode: String, CaseIterable, Identifiable {
        case existing = "已有类型"
        case createCourse = "本次课程点名"
        var id: String { rawValue }
    }

    let user: User
    let types: [RollCallType]
    let initialPeriods: [ClassPeriod]
    let onCreated: (RollCall) -> Void
    @Environment(\.dismiss) private var dismiss

    @State private var mode: StartMode = .existing
    @State private var selectedTypeId: Int = 0
    @State private var rollCallDate: Date = Date()
    @State private var notes: String = ""

    // 新建课程/节次点名表单状态
    @State private var classes: [ClassOptionItem] = []
    @State private var selectedClassId: Int = 0
    @State private var periods: [ClassPeriod] = []
    @State private var selectedPeriodIds: Set<Int> = []
    @State private var leaveTypes: [LeaveType] = []
    @State private var courseName: String = ""
    @State private var lastAutoSuggestedName: String = ""
    @State private var absentStatus: String = "absent"
    @State private var linkedLeaveTypeId: Int? = nil

    @State private var isLoadingMeta = false
    @State private var isCreating = false
    @State private var errorMessage: String?

    private var selectedExistingType: RollCallType? {
        types.first(where: { $0.id == selectedTypeId })
    }

    private var orderedSelectedPeriodIds: [Int] {
        periods.filter { selectedPeriodIds.contains($0.id) }.map(\.id)
    }

    /// 方案 B：当前班级已有固定活动（早读/早操/晚操）置灰不可选，正课第1~N节每次自由选择
    private var occupiedFixedPeriodIds: Set<Int> {
        RollCallPeriodNamingHelper.occupiedFixedPeriodIds(
            in: types,
            classId: selectedClassId,
            periods: periods
        )
    }

    private var canSubmit: Bool {
        guard !isCreating else { return false }
        if mode == .existing {
            return selectedTypeId > 0
        }
        return selectedClassId > 0 && !courseName.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
    }

    var body: some View {
        NavigationStack {
            Form {
                if let errorMessage {
                    Section {
                        ErrorBannerView(message: errorMessage)
                    }
                }

                if user.isTeacherOrAdmin {
                    Section {
                        Picker("点名方式", selection: $mode) {
                            ForEach(StartMode.allCases) { m in
                                Text(m.rawValue).tag(m)
                            }
                        }
                        .pickerStyle(.segmented)
                        .listRowInsets(EdgeInsets(top: 6, leading: 12, bottom: 6, trailing: 12))
                    }
                }

                if mode == .existing {
                    Section("选择已有活动类型") {
                        if types.isEmpty {
                            VStack(alignment: .leading, spacing: 6) {
                                Text("暂无已有活动类型")
                                    .font(.subheadline)
                                    .foregroundStyle(.secondary)
                                if user.isTeacherOrAdmin {
                                    Button("切换到「本次课程点名」") {
                                        mode = .createCourse
                                    }
                                    .font(.caption.weight(.semibold))
                                }
                            }
                        } else {
                            Picker("点名类型", selection: $selectedTypeId) {
                                ForEach(types) { t in
                                    let classSuffix = t.schoolClass.map { "（\($0.name)）" } ?? ""
                                    Text("\(t.name)\(classSuffix)").tag(t.id)
                                }
                            }
                            if let chosen = selectedExistingType {
                                if let summary = chosen.periodSummary(using: periods.isEmpty ? initialPeriods : periods) {
                                    LabeledContent("关联节次", value: summary)
                                        .font(.caption)
                                }
                                LabeledContent("未到标记", value: chosen.absentStatusDisplayName)
                                    .font(.caption)
                            }
                        }
                    }
                } else {
                    Section {
                        if isLoadingMeta && periods.isEmpty {
                            ProgressView("加载节次配置...")
                        } else if periods.isEmpty {
                            Text("暂未读取到节次配置，您仍可直接输入课程名称创建点名")
                                .font(.caption)
                                .foregroundStyle(.secondary)
                        } else {
                            PeriodMultiSelectGrid(
                                periods: periods,
                                selectedIds: $selectedPeriodIds,
                                disabledPeriodIds: occupiedFixedPeriodIds
                            ) { nextIds in
                                syncAutoCourseName(for: nextIds)
                            }
                        }
                    } header: {
                        Text("选择本次课程节次（可多选，用于考勤统计）")
                    } footer: {
                        Text("已选 \(selectedPeriodIds.count) 个节次。早读/早操/晚操等本班已有固定活动不可重复勾选；本次选择仅针对当前点名生效，不会添加到已有类型，明天或下次点名可重新自由选择节次。")
                    }

                    Section("课程点名信息") {
                        TextField("活动名称（如：第1-2节点名 / 专业课点名）", text: $courseName)

                        if classes.count > 1 {
                            Picker("班级", selection: $selectedClassId) {
                                ForEach(classes) { cls in
                                    Text(cls.name).tag(cls.id)
                                }
                            }
                        } else if let onlyClass = classes.first {
                            LabeledContent("班级", value: onlyClass.name)
                        }

                        Picker("未到标记为", selection: $absentStatus) {
                            Text("旷课").tag("absent")
                            Text("迟到").tag("late")
                            Text("请假").tag("leave")
                        }

                        if !leaveTypes.isEmpty {
                            Picker("关联请假类型（可选）", selection: $linkedLeaveTypeId) {
                                Text("不关联").tag(Optional<Int>.none)
                                ForEach(leaveTypes) { lt in
                                    Text(lt.name).tag(Optional(lt.id))
                                }
                            }
                        }
                    }
                }

                Section("本次点名时间与备注") {
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
                            Text(mode == .existing ? "立即开始点名" : "发起本次课程点名")
                                .font(.headline)
                            Spacer()
                        }
                    }
                    .listRowBackground(canSubmit ? SCTheme.primary : Color.gray.opacity(0.35))
                    .foregroundStyle(.white)
                    .disabled(!canSubmit)
                }
            }
            .navigationTitle("发起点名")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("取消") { dismiss() }
                }
            }
            .onChange(of: selectedClassId) { _, _ in
                let occupied = occupiedFixedPeriodIds
                let cleaned = selectedPeriodIds.subtracting(occupied)
                if cleaned != selectedPeriodIds {
                    selectedPeriodIds = cleaned
                    syncAutoCourseName(for: cleaned)
                }
            }
            .task {
                periods = initialPeriods
                if selectedTypeId == 0, let first = types.first {
                    selectedTypeId = first.id
                }
                if types.isEmpty && user.isTeacherOrAdmin {
                    mode = .createCourse
                }
                if user.isTeacherOrAdmin {
                    await loadMeta()
                }
            }
        }
    }

    private func loadMeta() async {
        isLoadingMeta = true
        defer { isLoadingMeta = false }

        async let clsReq = AttendanceRepository.fetchClasses()
        async let periodReq = AttendanceRepository.fetchClassPeriods()
        async let leaveReq = AttendanceRepository.fetchLeaveTypes()

        if let fetchedClasses = try? await clsReq {
            classes = fetchedClasses
            if selectedClassId == 0, let first = fetchedClasses.first {
                selectedClassId = first.id
            }
        }
        if let fetchedPeriods = try? await periodReq {
            periods = fetchedPeriods.filter { !$0.isEveningStudy && $0.isActiveFlag }
        }
        if let fetchedLeaves = try? await leaveReq {
            leaveTypes = fetchedLeaves.filter(\.isActiveFlag)
        }
    }

    private func syncAutoCourseName(for nextIds: Set<Int>) {
        let trimmed = courseName.trimmingCharacters(in: .whitespacesAndNewlines)
        let shouldAutoFill = trimmed.isEmpty || trimmed == lastAutoSuggestedName
        let suggested = RollCallPeriodNamingHelper.suggestedName(for: nextIds, in: periods)
        if shouldAutoFill {
            courseName = suggested
            lastAutoSuggestedName = suggested
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
            let targetTypeId: Int
            if mode == .existing {
                targetTypeId = selectedTypeId
            } else {
                let cleanName = courseName.trimmingCharacters(in: .whitespacesAndNewlines)
                let targetPeriods = orderedSelectedPeriodIds
                // 本次课程点名：使用 is_active = false 的单次事件类型，不污染「已有类型」列表，
                // 明天或下次点名可重新自由选择任意一节或几节（后端自动优雅复用同规则临时类型）。
                let oneOffType = try await RollCallRepository.createRollCallType(
                    classId: selectedClassId,
                    name: cleanName,
                    absentStatus: absentStatus,
                    leaveTypeId: linkedLeaveTypeId,
                    periodIds: targetPeriods,
                    isActive: false
                )
                targetTypeId = oneOffType.id
            }

            let created = try await RollCallRepository.startRollCall(
                rollCallTypeId: targetTypeId,
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

/// 节次多选网格（方案 B：本班已有固定活动置灰不可选并标注「已有」，正课节次自由多选）
private struct PeriodMultiSelectGrid: View {
    let periods: [ClassPeriod]
    @Binding var selectedIds: Set<Int>
    var disabledPeriodIds: Set<Int> = []
    var onChanged: ((Set<Int>) -> Void)? = nil

    private let columns = [GridItem(.adaptive(minimum: 74), spacing: 8)]

    var body: some View {
        LazyVGrid(columns: columns, spacing: 8) {
            ForEach(periods) { period in
                let isDisabled = disabledPeriodIds.contains(period.id)
                let isSelected = !isDisabled && selectedIds.contains(period.id)
                Button {
                    guard !isDisabled else { return }
                    var next = selectedIds
                    if isSelected {
                        next.remove(period.id)
                    } else {
                        next.insert(period.id)
                    }
                    selectedIds = next
                    onChanged?(next)
                } label: {
                    VStack(spacing: 2) {
                        Text(period.name)
                            .font(.caption.weight(isSelected ? .semibold : .regular))
                            .lineLimit(1)
                            .minimumScaleFactor(0.8)
                        if isDisabled {
                            Text("已有")
                                .font(.system(size: 9, weight: .medium))
                                .foregroundStyle(.secondary)
                        }
                    }
                    .frame(maxWidth: .infinity, minHeight: 34)
                    .padding(.vertical, 6)
                    .padding(.horizontal, 6)
                    .background(
                        isDisabled
                            ? Color(.systemGray5)
                            : (isSelected ? SCTheme.primary.opacity(0.15) : Color(.tertiarySystemFill))
                    )
                    .foregroundStyle(isDisabled ? Color.secondary.opacity(0.75) : (isSelected ? SCTheme.primary : .primary))
                    .overlay(
                        RoundedRectangle(cornerRadius: 9, style: .continuous)
                            .stroke(
                                isDisabled ? Color(.systemGray4) : (isSelected ? SCTheme.primary : Color.clear),
                                lineWidth: isDisabled ? 0.8 : 1.4
                            )
                    )
                    .clipShape(RoundedRectangle(cornerRadius: 9, style: .continuous))
                }
                .buttonStyle(.plain)
                .disabled(isDisabled)
            }
        }
        .padding(.vertical, 4)
    }
}

/// 班主任 / 管理员点名活动类型管理页（新增/编辑/删除课程与节次点名，支持一键开始点名）
struct RollCallTypesManageView: View {
    let user: User
    var onStartRollCall: ((RollCall) -> Void)? = nil
    @Environment(\.dismiss) private var dismiss

    @State private var types: [RollCallType] = []
    @State private var classes: [ClassOptionItem] = []
    @State private var periods: [ClassPeriod] = []
    @State private var leaveTypes: [LeaveType] = []
    @State private var filterClassId: Int = 0 // 0 = 全部
    @State private var isLoading = false
    @State private var errorMessage: String?

    @State private var showFormSheet = false
    @State private var editingType: RollCallType? = nil
    @State private var startingTypeId: Int? = nil

    private var filteredTypes: [RollCallType] {
        guard filterClassId > 0 else { return types }
        return types.filter { $0.classId == filterClassId }
    }

    var body: some View {
        List {
            if let errorMessage {
                Section {
                    ErrorBannerView(message: errorMessage) {
                        Task { await loadAll() }
                    }
                }
            }

            if classes.count > 1 {
                Section {
                    Picker("筛选班级", selection: $filterClassId) {
                        Text("全部班级").tag(0)
                        ForEach(classes) { cls in
                            Text(cls.name).tag(cls.id)
                        }
                    }
                }
            }

            Section {
                if isLoading && types.isEmpty {
                    ProgressView("加载点名类型...")
                } else if filteredTypes.isEmpty {
                    VStack(alignment: .leading, spacing: 6) {
                        Text("暂无点名类型")
                            .font(.subheadline.weight(.medium))
                        Text("点击右上角「+」可新增第1、2…节课程点名或自定义活动点名。")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                    .padding(.vertical, 6)
                } else {
                    ForEach(filteredTypes) { item in
                        VStack(alignment: .leading, spacing: 6) {
                            HStack(spacing: 8) {
                                Text(item.name)
                                    .font(.subheadline.weight(.semibold))
                                if let cls = item.schoolClass?.name {
                                    Text(cls)
                                        .font(.caption2)
                                        .padding(.horizontal, 6)
                                        .padding(.vertical, 2)
                                        .background(SCTheme.primary.opacity(0.12))
                                        .foregroundStyle(SCTheme.primary)
                                        .clipShape(Capsule())
                                }
                                Spacer()
                                Button {
                                    Task { await quickStart(item) }
                                } label: {
                                    if startingTypeId == item.id {
                                        ProgressView().controlSize(.small)
                                    } else {
                                        Label("点名", systemImage: "play.fill")
                                            .font(.caption.weight(.semibold))
                                    }
                                }
                                .buttonStyle(.borderedProminent)
                                .tint(SCTheme.primary)
                                .controlSize(.small)
                                .disabled(startingTypeId != nil)
                            }

                            if let summary = item.periodSummary(using: periods) {
                                Label("关联节次：\(summary)", systemImage: "clock")
                                    .font(.caption)
                                    .foregroundStyle(.indigo)
                            }

                            HStack(spacing: 10) {
                                Text("未到标记：\(item.absentStatusDisplayName)")
                                if let lt = item.leaveType?.name {
                                    Text("· 关联假类：\(lt)")
                                }
                            }
                            .font(.caption2)
                            .foregroundStyle(.secondary)

                            if let desc = item.description, !desc.isEmpty {
                                Text(desc)
                                    .font(.caption2)
                                    .foregroundStyle(.tertiary)
                            }
                        }
                        .padding(.vertical, 4)
                        .contentShape(Rectangle())
                        .onTapGesture {
                            editingType = item
                            showFormSheet = true
                        }
                        .swipeActions(edge: .trailing) {
                            Button(role: .destructive) {
                                Task { await deleteType(item) }
                            } label: {
                                Label("删除", systemImage: "trash")
                            }
                            Button {
                                editingType = item
                                showFormSheet = true
                            } label: {
                                Label("编辑", systemImage: "pencil")
                            }
                            .tint(.indigo)
                        }
                    }
                }
            } header: {
                Text("已配置的点名类型（\(filteredTypes.count)）")
            } footer: {
                Text("点击任意行可编辑关联节次，左滑可删除；点右侧「点名」可直接发起本次走动点名。")
            }
        }
        .navigationTitle("点名类型与课程点名")
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            ToolbarItem(placement: .topBarTrailing) {
                Button {
                    editingType = nil
                    showFormSheet = true
                } label: {
                    Label("新增", systemImage: "plus")
                }
            }
        }
        .safeRefreshable {
            await loadAll()
        }
        .sheet(isPresented: $showFormSheet) {
            RollCallTypeFormSheet(
                user: user,
                editing: editingType,
                existingTypes: types,
                classes: classes,
                periods: periods,
                leaveTypes: leaveTypes,
                defaultClassId: filterClassId > 0 ? filterClassId : (classes.first?.id ?? 0)
            ) {
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
            async let classesReq = AttendanceRepository.fetchClasses()
            async let periodsReq = AttendanceRepository.fetchClassPeriods()
            async let leavesReq = AttendanceRepository.fetchLeaveTypes()

            types = try await typesReq
            if let cls = try? await classesReq { classes = cls }
            if let p = try? await periodsReq { periods = p.filter { !$0.isEveningStudy && $0.isActiveFlag } }
            if let l = try? await leavesReq { leaveTypes = l.filter(\.isActiveFlag) }
        } catch {
            guard !error.isCancellationError else { return }
            errorMessage = error.localizedDescription
        }
    }

    private func deleteType(_ item: RollCallType) async {
        do {
            try await RollCallRepository.deleteRollCallType(id: item.id)
            types.removeAll { $0.id == item.id }
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    private func quickStart(_ item: RollCallType) async {
        startingTypeId = item.id
        errorMessage = nil
        defer { startingTypeId = nil }

        let fmt = DateFormatter()
        fmt.dateFormat = "yyyy-MM-dd HH:mm:ss"
        fmt.locale = Locale(identifier: "en_US_POSIX")
        let timeStr = fmt.string(from: Date())

        do {
            let created = try await RollCallRepository.startRollCall(
                rollCallTypeId: item.id,
                rollCallTime: timeStr
            )
            dismiss()
            DispatchQueue.main.asyncAfter(deadline: .now() + 0.2) {
                onStartRollCall?(created)
            }
        } catch {
            errorMessage = error.localizedDescription
        }
    }
}

private struct RollCallTypeFormSheet: View {
    let user: User
    let editing: RollCallType?
    let existingTypes: [RollCallType]
    let classes: [ClassOptionItem]
    let periods: [ClassPeriod]
    let leaveTypes: [LeaveType]
    let defaultClassId: Int
    let onSaved: () -> Void
    @Environment(\.dismiss) private var dismiss

    @State private var name: String = ""
    @State private var lastAutoName: String = ""
    @State private var descriptionText: String = ""
    @State private var selectedClassId: Int = 0
    @State private var batchMode: Bool = false
    @State private var batchClassIds: Set<Int> = []
    @State private var absentStatus: String = "absent"
    @State private var leaveTypeId: Int? = nil
    @State private var selectedPeriodIds: Set<Int> = []
    @State private var isActive: Bool = true

    @State private var isSaving = false
    @State private var errorMessage: String?

    private var canBatchCreate: Bool {
        editing == nil && classes.count > 1 && user.isAdminTier
    }

    /// 方案 B：已占用的固定活动节次（编辑自身时不禁用自己）
    private var occupiedFixedPeriodIds: Set<Int> {
        if batchMode {
            guard !batchClassIds.isEmpty else { return [] }
            let sets = batchClassIds.map { cid in
                RollCallPeriodNamingHelper.occupiedFixedPeriodIds(
                    in: existingTypes,
                    classId: cid,
                    excludingTypeId: editing?.id,
                    periods: periods
                )
            }
            return sets.dropFirst().reduce(sets.first ?? []) { $0.intersection($1) }
        }
        return RollCallPeriodNamingHelper.occupiedFixedPeriodIds(
            in: existingTypes,
            classId: selectedClassId,
            excludingTypeId: editing?.id,
            periods: periods
        )
    }

    private var matchedExistingType: RollCallType? {
        guard !batchMode else { return nil }
        return RollCallPeriodNamingHelper.matchingExistingType(
            for: selectedPeriodIds,
            in: existingTypes,
            classId: selectedClassId,
            excludingTypeId: editing?.id
        )
    }

    private var canSubmit: Bool {
        guard !isSaving else { return false }
        guard !name.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty else { return false }
        if editing != nil { return true }
        if batchMode { return !batchClassIds.isEmpty }
        return selectedClassId > 0
    }

    var body: some View {
        NavigationStack {
            Form {
                if let errorMessage {
                    Section {
                        ErrorBannerView(message: errorMessage)
                    }
                }

                Section {
                    if periods.isEmpty {
                        Text("暂无节次配置，可先直接填写活动名称")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    } else {
                        PeriodMultiSelectGrid(
                            periods: periods,
                            selectedIds: $selectedPeriodIds,
                            disabledPeriodIds: occupiedFixedPeriodIds
                        ) { nextIds in
                            if editing == nil {
                                let trimmed = name.trimmingCharacters(in: .whitespacesAndNewlines)
                                if trimmed.isEmpty || trimmed == lastAutoName {
                                    let suggested = RollCallPeriodNamingHelper.suggestedName(for: nextIds, in: periods)
                                    name = suggested
                                    lastAutoName = suggested
                                }
                            }
                        }

                        if let dup = matchedExistingType {
                            HStack(spacing: 6) {
                                Image(systemName: "info.circle.fill")
                                    .foregroundStyle(.blue)
                                Text("本班已有同节次类型「\(dup.name)」，可直接复用原类型或改名另建。")
                                    .font(.caption)
                                    .foregroundStyle(.blue)
                            }
                            .padding(8)
                            .frame(maxWidth: .infinity, alignment: .leading)
                            .background(Color.blue.opacity(0.08))
                            .clipShape(RoundedRectangle(cornerRadius: 8, style: .continuous))
                        }
                    }
                } header: {
                    Text("关联节次（可多选，用于考勤统计）")
                } footer: {
                    Text("已选 \(selectedPeriodIds.count) 个节次；本班已有固定活动（如早读/早操/晚操）已置灰不可重复选择，正课节次可自由组合。")
                }

                Section("基本信息") {
                    TextField("活动名称（如：第1-2节点名、早操点名）", text: $name)
                    TextField("描述（可选）", text: $descriptionText)

                    if editing == nil {
                        if canBatchCreate {
                            Toggle("为多个班级批量创建", isOn: $batchMode)
                        }

                        if batchMode {
                            ForEach(classes) { cls in
                                Button {
                                    if batchClassIds.contains(cls.id) {
                                        batchClassIds.remove(cls.id)
                                    } else {
                                        batchClassIds.insert(cls.id)
                                    }
                                } label: {
                                    HStack {
                                        Image(systemName: batchClassIds.contains(cls.id) ? "checkmark.circle.fill" : "circle")
                                            .foregroundStyle(batchClassIds.contains(cls.id) ? SCTheme.primary : .secondary)
                                        Text(cls.name)
                                            .foregroundStyle(.primary)
                                    }
                                }
                            }
                        } else if classes.count > 1 {
                            Picker("所属班级", selection: $selectedClassId) {
                                ForEach(classes) { cls in
                                    Text(cls.name).tag(cls.id)
                                }
                            }
                        } else if let only = classes.first {
                            LabeledContent("所属班级", value: only.name)
                        }
                    } else if let clsName = editing?.schoolClass?.name {
                        LabeledContent("所属班级", value: clsName)
                    }
                }

                Section("考勤规则") {
                    Picker("未到标记为", selection: $absentStatus) {
                        Text("旷课").tag("absent")
                        Text("迟到").tag("late")
                        Text("请假").tag("leave")
                    }

                    if !leaveTypes.isEmpty {
                        Picker("关联请假类型（可选）", selection: $leaveTypeId) {
                            Text("不关联").tag(Optional<Int>.none)
                            ForEach(leaveTypes) { lt in
                                Text(lt.name).tag(Optional(lt.id))
                            }
                        }
                    }

                    Toggle("启用该点名类型", isOn: $isActive)
                }

                Section {
                    Button {
                        Task { await save() }
                    } label: {
                        HStack {
                            Spacer()
                            if isSaving { ProgressView().tint(.white) }
                            Text(editing != nil ? "保存修改" : (batchMode ? "为 \(batchClassIds.count) 个班级创建" : "保存创建"))
                                .font(.headline)
                            Spacer()
                        }
                    }
                    .listRowBackground(canSubmit ? SCTheme.primary : Color.gray.opacity(0.35))
                    .foregroundStyle(.white)
                    .disabled(!canSubmit)
                }
            }
            .navigationTitle(editing != nil ? "编辑点名类型" : "新增点名类型")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("取消") { dismiss() }
                }
            }
            .onChange(of: selectedClassId) { _, _ in
                let cleaned = selectedPeriodIds.subtracting(occupiedFixedPeriodIds)
                if cleaned != selectedPeriodIds {
                    selectedPeriodIds = cleaned
                }
            }
            .onAppear {
                if let e = editing {
                    name = e.name
                    descriptionText = e.description ?? ""
                    selectedClassId = e.classId ?? defaultClassId
                    absentStatus = ["absent", "late", "leave"].contains(e.absentStatus ?? "") ? (e.absentStatus ?? "absent") : "absent"
                    leaveTypeId = e.leaveTypeId
                    selectedPeriodIds = Set(e.periodIds)
                    isActive = e.isActiveFlag
                } else {
                    selectedClassId = defaultClassId
                    if defaultClassId > 0 {
                        batchClassIds = [defaultClassId]
                    }
                }
            }
        }
    }

    private func save() async {
        isSaving = true
        errorMessage = nil
        defer { isSaving = false }

        let orderedPeriodIds = periods.filter { selectedPeriodIds.contains($0.id) }.map(\.id)
        do {
            if let e = editing {
                _ = try await RollCallRepository.updateRollCallType(
                    id: e.id,
                    name: name,
                    description: descriptionText,
                    absentStatus: absentStatus,
                    leaveTypeId: leaveTypeId,
                    periodIds: orderedPeriodIds,
                    isActive: isActive
                )
            } else if batchMode {
                _ = try await RollCallRepository.batchCreateRollCallTypes(
                    classIds: Array(batchClassIds),
                    name: name,
                    description: descriptionText,
                    absentStatus: absentStatus,
                    leaveTypeId: leaveTypeId,
                    periodIds: orderedPeriodIds,
                    isActive: isActive
                )
            } else {
                _ = try await RollCallRepository.createRollCallType(
                    classId: selectedClassId,
                    name: name,
                    description: descriptionText,
                    absentStatus: absentStatus,
                    leaveTypeId: leaveTypeId,
                    periodIds: orderedPeriodIds,
                    isActive: isActive
                )
            }
            dismiss()
            onSaved()
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
