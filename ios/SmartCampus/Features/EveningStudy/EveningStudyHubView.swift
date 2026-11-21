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

    private var canTake: Bool { forceTakeView && user.canTakeEveningStudy }

    var body: some View {
        List {
            if let errorMessage {
                Section {
                    ErrorBannerView(message: errorMessage) {
                        Task { await loadClassesAndSummary() }
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
        .navigationDestination(item: Binding(
            get: { activeSessionPayload.map { EveningStudyNavPayload(payload: $0) } },
            set: { if $0 == nil { activeSessionPayload = nil; Task { await loadClassesAndSummary() } } }
        )) { nav in
            EveningStudyTakeView(
                initialPayload: nav.payload,
                statuses: statuses,
                canModify: canTake
            )
        }
        .sheet(isPresented: $showHistorySheet) {
            NavigationStack {
                EveningStudyHistoryView(isTakeView: forceTakeView, statuses: statuses, canModify: canTake)
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
    let isTakeView: Bool
    let statuses: [EveningStudyStatus]
    let canModify: Bool
    @Environment(\.dismiss) private var dismiss

    @State private var sessions: [EveningStudySession] = []
    @State private var isLoading = true
    @State private var selectedPayload: EveningStudySessionPayload?

    var body: some View {
        Group {
            if isLoading {
                ProgressView("加载夜自习历史...")
            } else if sessions.isEmpty {
                ContentUnavailableView("暂无历史记录", systemImage: "moon.zzz")
            } else {
                List(sessions) { s in
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
                            Text("\(s.dateDisplay) · 正常 \(s.normalCount) / 异常 \(s.exceptionCount) / 总 \(s.totalStudents)")
                                .font(.caption)
                                .foregroundStyle(.secondary)
                        }
                        .padding(.vertical, 4)
                    }
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
        .navigationDestination(item: Binding(
            get: { selectedPayload.map { EveningStudyNavPayload(payload: $0) } },
            set: { if $0 == nil { selectedPayload = nil } }
        )) { nav in
            EveningStudyTakeView(initialPayload: nav.payload, statuses: statuses, canModify: canModify)
        }
        .task {
            isLoading = true
            defer { isLoading = false }
            if let page = try? await EveningStudyRepository.fetchHistory(isTakeView: isTakeView) {
                sessions = page.data
            }
        }
    }
}
