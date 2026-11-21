import SwiftUI

struct EveningStudyHubView: View {
    let user: User
    let forceTakeView: Bool

    var body: some View {
        NavigationStack {
            EveningStudyContentView(user: user, forceTakeView: forceTakeView)
                .navigationTitle(forceTakeView ? "夜自习点名" : "夜自习情况")
        }
    }
}

struct EveningStudyContentView: View {
    let user: User
    let forceTakeView: Bool

    @State private var selectedDate: Date = Date()
    @State private var periods: [ClassPeriod] = []
    @State private var selectedPeriodId: Int = 0
    @State private var statuses: [EveningStudyStatus] = []
    @State private var classes: [EveningStudyClassItem] = []
    @State private var summary: EveningStudySummaryResponse?
    @State private var isLoading = false
    @State private var errorMessage: String?

    @State private var activeSessionPayload: EveningStudySessionPayload?
    @State private var showHistorySheet = false
    @State private var deletingSession: EveningStudySession?
    @State private var deleteNotice: String?

    private var canTake: Bool { forceTakeView && user.canTakeEveningStudy }

    private func canDeleteSession(_ session: EveningStudySession) -> Bool {
        canTake || session.createdBy == user.id || session.creator?.id == user.id
    }

    var body: some View {
        List {
            if let errorMessage {
                Section {
                    ErrorBannerView(message: errorMessage) {
                        Task { await loadClassesAndSummary() }
                    }
                }
            }

            if let deleteNotice {
                Section {
                    HStack(spacing: 8) {
                        Image(systemName: "checkmark.circle.fill")
                            .foregroundStyle(.green)
                        Text(deleteNotice)
                            .font(.subheadline)
                    }
                }
            }

            // Filter Bar
            Section {
                DatePicker("日期", selection: $selectedDate, displayedComponents: .date)

                if !periods.isEmpty {
                    Picker("夜自习节次", selection: $selectedPeriodId) {
                        ForEach(periods) { p in
                            Text(p.name).tag(p.id)
                        }
                    }
                }

                Button {
                    showHistorySheet = true
                } label: {
                    Label("查看夜自习历史记录", systemImage: "clock.arrow.circlepath")
                }
            }

            // Summary Card
            if let overall = summary?.overall {
                Section("当日总览") {
                    HStack(spacing: 10) {
                        MiniMetric(label: "已点班级", value: "\(overall.startedClassCount)/\(overall.classCount)", color: .indigo)
                        MiniMetric(label: "已完成", value: "\(overall.completedClassCount)", color: .green)
                        MiniMetric(label: "应到住宿", value: "\(overall.expectedCount)", color: .blue)
                        MiniMetric(label: "实到人数", value: "\(overall.presentCount)", color: .teal)
                    }
                    .padding(.vertical, 4)

                    if let counts = overall.statusCounts, !counts.isEmpty {
                        ScrollView(.horizontal, showsIndicators: false) {
                            HStack(spacing: 8) {
                                ForEach(counts) { item in
                                    StatusBadge(text: "\(item.name) \(item.count)", color: SCTheme.color(for: item.color))
                                }
                            }
                        }
                    }
                }
            }

            // Class List
            Section("住宿班级（\(classes.count)）") {
                if isLoading && classes.isEmpty {
                    ProgressView("加载夜自习班级...")
                } else if classes.isEmpty {
                    Text("当前日期与节次暂无可查看的住宿班级")
                        .foregroundStyle(.secondary)
                } else {
                    ForEach(classes) { cls in
                        Button {
                            Task { await openClassSession(cls) }
                        } label: {
                            VStack(alignment: .leading, spacing: 8) {
                                HStack {
                                    VStack(alignment: .leading, spacing: 2) {
                                        Text(cls.name)
                                            .font(.headline)
                                            .foregroundStyle(.primary)
                                        if let dept = cls.department?.name {
                                            Text("\(dept) · 住宿生 \(cls.boardingCount) 人")
                                                .font(.caption)
                                                .foregroundStyle(.secondary)
                                        } else {
                                            Text("住宿生 \(cls.boardingCount) 人")
                                                .font(.caption)
                                                .foregroundStyle(.secondary)
                                        }
                                    }
                                    Spacer()
                                    if let session = cls.session {
                                        StatusBadge(
                                            text: session.isCompleted ? "已完成" : "点名中",
                                            color: session.isCompleted ? .green : .orange
                                        )
                                    } else {
                                        StatusBadge(
                                            text: canTake ? "开始点名" : "未点名",
                                            color: canTake ? SCTheme.primary : .gray
                                        )
                                    }
                                }

                                if let counts = cls.session?.statusCounts, !counts.isEmpty {
                                    ScrollView(.horizontal, showsIndicators: false) {
                                        HStack(spacing: 6) {
                                            ForEach(counts) { item in
                                                StatusBadge(
                                                    text: "\(item.name) \(item.count)",
                                                    color: SCTheme.color(for: item.color)
                                                )
                                            }
                                        }
                                    }
                                }
                            }
                            .padding(.vertical, 4)
                        }
                        .disabled(cls.session == nil && !canTake)
                        .swipeActions(edge: .trailing, allowsFullSwipe: false) {
                            if let session = cls.session, canDeleteSession(session) {
                                Button(role: .destructive) {
                                    deletingSession = session
                                } label: {
                                    Label("删除", systemImage: "trash")
                                }
                            }
                        }
                    }
                }
            }
        }
        .listStyle(.insetGrouped)
        .refreshable {
            await loadClassesAndSummary()
        }
        .onChange(of: selectedDate) { _, _ in
            Task { await loadClassesAndSummary() }
        }
        .onChange(of: selectedPeriodId) { _, _ in
            Task { await loadClassesAndSummary() }
        }
        .alert("删除夜自习点名？", isPresented: Binding(
            get: { deletingSession != nil },
            set: { if !$0 { deletingSession = nil } }
        )) {
            Button("取消", role: .cancel) {}
            Button("确认删除", role: .destructive) {
                if let target = deletingSession {
                    Task { await deleteClassSession(target) }
                }
            }
        } message: {
            Text("确定删除「\(deletingSession?.schoolClass?.name ?? "该班级")」的本场夜自习点名吗？该场产生的学生点名标记记录将一并删除（学生请假记录会保留）。")
        }
        .navigationDestination(item: Binding(
            get: { activeSessionPayload.map { EveningStudyNavPayload(payload: $0) } },
            set: { if $0 == nil { activeSessionPayload = nil; Task { await loadClassesAndSummary() } } }
        )) { nav in
            EveningStudyTakeView(
                initialPayload: nav.payload,
                statuses: statuses,
                canModify: canTake,
                canDelete: canDeleteSession(nav.payload.session),
                onDeleted: { msg in
                    deleteNotice = msg
                    activeSessionPayload = nil
                    Task { await loadClassesAndSummary() }
                }
            )
        }
        .sheet(isPresented: $showHistorySheet) {
            NavigationStack {
                EveningStudyHistoryView(
                    currentUserId: user.id,
                    isTakeView: forceTakeView,
                    statuses: statuses,
                    canModify: canTake,
                    onChanged: {
                        Task { await loadClassesAndSummary() }
                    }
                )
            }
        }
        .task {
            await loadBase()
        }
    }

    private func loadBase() async {
        isLoading = true
        errorMessage = nil
        defer { isLoading = false }

        do {
            async let periodsReq = EveningStudyRepository.fetchPeriods()
            async let statusesReq = EveningStudyRepository.fetchStatuses()

            periods = try await periodsReq
            statuses = try await statusesReq.statuses.filter(\.isActiveFlag)

            if selectedPeriodId == 0, let first = periods.first {
                selectedPeriodId = first.id
            }
            await loadClassesAndSummary()
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    private func loadClassesAndSummary() async {
        guard selectedPeriodId > 0 else { return }
        isLoading = true
        errorMessage = nil
        defer { isLoading = false }

        let dateStr = SCDateFormatter.yyyyMMdd.string(from: selectedDate)
        do {
            async let clsReq = EveningStudyRepository.fetchClasses(date: dateStr, periodId: selectedPeriodId, isTakeView: forceTakeView)
            async let sumReq = EveningStudyRepository.fetchSummary(date: dateStr, periodId: selectedPeriodId, isTakeView: forceTakeView)
            classes = try await clsReq
            summary = try? await sumReq
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    private func openClassSession(_ cls: EveningStudyClassItem) async {
        errorMessage = nil
        do {
            if let existing = cls.session {
                activeSessionPayload = try await EveningStudyRepository.fetchSession(id: existing.id)
            } else if canTake {
                let dateStr = SCDateFormatter.yyyyMMdd.string(from: selectedDate)
                activeSessionPayload = try await EveningStudyRepository.startSession(
                    date: dateStr,
                    periodId: selectedPeriodId,
                    classId: cls.id
                )
            }
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    private func deleteClassSession(_ session: EveningStudySession) async {
        errorMessage = nil
        deleteNotice = nil
        do {
            let res = try await EveningStudyRepository.deleteSession(sessionId: session.id)
            let count = res.deletedRecordCount ?? 0
            deleteNotice = count > 0
                ? "已删除本场点名及 \(count) 条学生点名记录"
                : (res.message ?? "点名记录已删除")
            await loadClassesAndSummary()
        } catch {
            errorMessage = error.localizedDescription
        }
    }
}

private struct EveningStudyNavPayload: Hashable {
    let id: Int
    let payload: EveningStudySessionPayload
    init(payload: EveningStudySessionPayload) {
        self.id = payload.session.id
        self.payload = payload
    }
    static func == (lhs: EveningStudyNavPayload, rhs: EveningStudyNavPayload) -> Bool {
        lhs.id == rhs.id
    }
    func hash(into hasher: inout Hasher) {
        hasher.combine(id)
    }
}

private struct MiniMetric: View {
    let label: String
    let value: String
    let color: Color

    var body: some View {
        VStack(spacing: 2) {
            Text(value)
                .font(.headline.monospacedDigit())
                .foregroundStyle(color)
            Text(label)
                .font(.caption2)
                .foregroundStyle(.secondary)
        }
        .frame(maxWidth: .infinity)
    }
}

struct EveningStudyHistoryView: View {
    let currentUserId: Int
    let isTakeView: Bool
    let statuses: [EveningStudyStatus]
    let canModify: Bool
    let onChanged: () -> Void
    @Environment(\.dismiss) private var dismiss

    @State private var sessions: [EveningStudySession] = []
    @State private var isLoading = true
    @State private var isDeleting = false
    @State private var errorMessage: String?
    @State private var successMessage: String?
    @State private var deletingSession: EveningStudySession?
    @State private var selectedPayload: EveningStudySessionPayload?

    private func canDelete(_ s: EveningStudySession) -> Bool {
        canModify || s.createdBy == currentUserId || s.creator?.id == currentUserId
    }

    var body: some View {
        Group {
            if isLoading {
                ProgressView("加载夜自习历史...")
            } else if sessions.isEmpty {
                ContentUnavailableView("暂无历史记录", systemImage: "moon.zzz")
            } else {
                List {
                    if let errorMessage {
                        Section {
                            ErrorBannerView(message: errorMessage)
                        }
                    }
                    if let successMessage {
                        Section {
                            HStack(spacing: 8) {
                                Image(systemName: "checkmark.circle.fill")
                                    .foregroundStyle(.green)
                                Text(successMessage)
                                    .font(.subheadline)
                            }
                        }
                    }

                    Section {
                        ForEach(sessions) { s in
                            HStack(spacing: 10) {
                                Button {
                                    Task {
                                        if let payload = try? await EveningStudyRepository.fetchSession(id: s.id) {
                                            selectedPayload = payload
                                        }
                                    }
                                } label: {
                                    VStack(alignment: .leading, spacing: 6) {
                                        HStack {
                                            Text(s.schoolClass?.name ?? "班级 #\(s.classId)")
                                                .font(.subheadline.weight(.semibold))
                                                .foregroundStyle(.primary)
                                            if let period = s.periodNameSnapshot {
                                                Text("· \(period)")
                                                    .font(.caption)
                                                    .foregroundStyle(.secondary)
                                            }
                                            Spacer()
                                            StatusBadge(text: s.isCompleted ? "已完成" : "点名中", color: s.isCompleted ? .green : .orange)
                                        }
                                        HStack(spacing: 6) {
                                            Text("\(s.dateDisplay) · 正常 \(s.normalCount) / 异常 \(s.exceptionCount) / 总 \(s.totalStudents)")
                                                .font(.caption)
                                                .foregroundStyle(.secondary)
                                            if let creatorName = s.creator?.name, !creatorName.isEmpty {
                                                Text("· \(creatorName)")
                                                    .font(.caption)
                                                    .foregroundStyle(.tertiary)
                                            }
                                        }
                                    }
                                    .padding(.vertical, 4)
                                }
                                .buttonStyle(.plain)

                                if canDelete(s) {
                                    Button(role: .destructive) {
                                        deletingSession = s
                                    } label: {
                                        Image(systemName: "trash")
                                            .font(.subheadline)
                                            .foregroundStyle(.red)
                                            .padding(8)
                                    }
                                    .buttonStyle(.borderless)
                                    .disabled(isDeleting)
                                }
                            }
                            .swipeActions(edge: .trailing, allowsFullSwipe: false) {
                                if canDelete(s) {
                                    Button(role: .destructive) {
                                        deletingSession = s
                                    } label: {
                                        Label("删除", systemImage: "trash")
                                    }
                                }
                            }
                        }
                    }
                }
                .refreshable {
                    await loadHistory()
                }
            }
        }
        .navigationTitle("夜自习历史")
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            ToolbarItem(placement: .confirmationAction) {
                Button("关闭") { dismiss() }
            }
        }
        .alert("删除夜自习点名？", isPresented: Binding(
            get: { deletingSession != nil },
            set: { if !$0 { deletingSession = nil } }
        )) {
            Button("取消", role: .cancel) {}
            Button("确认删除", role: .destructive) {
                if let target = deletingSession {
                    Task { await deleteTarget(target) }
                }
            }
        } message: {
            Text("确定删除「\(deletingSession?.schoolClass?.name ?? "该班级")」的本场夜自习点名吗？该场产生的学生点名标记记录将一并删除（学生请假记录会保留）。")
        }
        .navigationDestination(item: Binding(
            get: { selectedPayload.map { EveningStudyNavPayload(payload: $0) } },
            set: { if $0 == nil { selectedPayload = nil } }
        )) { nav in
            EveningStudyTakeView(
                initialPayload: nav.payload,
                statuses: statuses,
                canModify: canModify,
                canDelete: canDelete(nav.payload.session),
                onDeleted: { msg in
                    successMessage = msg
                    selectedPayload = nil
                    Task {
                        await loadHistory()
                        onChanged()
                    }
                }
            )
        }
        .task {
            await loadHistory()
        }
    }

    private func loadHistory() async {
        isLoading = true
        errorMessage = nil
        defer { isLoading = false }
        do {
            let page = try await EveningStudyRepository.fetchHistory(isTakeView: isTakeView)
            sessions = page.data
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    private func deleteTarget(_ target: EveningStudySession) async {
        isDeleting = true
        errorMessage = nil
        successMessage = nil
        defer { isDeleting = false }

        do {
            let res = try await EveningStudyRepository.deleteSession(sessionId: target.id)
            sessions.removeAll { $0.id == target.id }
            let count = res.deletedRecordCount ?? 0
            successMessage = count > 0
                ? "已删除本场点名及 \(count) 条学生点名记录"
                : (res.message ?? "点名记录已删除")
            onChanged()
        } catch {
            errorMessage = error.localizedDescription
        }
    }
}
