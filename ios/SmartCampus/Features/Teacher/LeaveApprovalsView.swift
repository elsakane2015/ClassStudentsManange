import SwiftUI

/// 审批页时间筛选模式
private enum ApprovalTimeScope: String, CaseIterable, Identifiable {
    case recent30
    case week
    case month
    case semester
    case all

    var id: String { rawValue }

    var label: String {
        switch self {
        case .recent30: return "近30天"
        case .week: return "教学周"
        case .month: return "按月"
        case .semester: return "本学期"
        case .all: return "全部"
        }
    }
}

private struct ApprovalMonthOption: Hashable, Identifiable {
    let year: Int
    let month: Int

    var id: String { String(format: "%04d-%02d", year, month) }
    var displayTitle: String { "\(year)年\(month)月" }
}

/// 教师 / 管理员 / 班级考勤员 请假审批流（服务端分页滚动加载 + 本学期近30天/教学周/月份筛选 + 服务端搜索 + 一键/分项审批）
struct LeaveApprovalsView: View {
    let user: User

    private let pageSize = 20

    @State private var statusFilter: String = "pending"
    @State private var timeScope: ApprovalTimeScope = .all
    @State private var lastHistoryScope: ApprovalTimeScope = .recent30

    @State private var semesters: [Semester] = []
    @State private var selectedWeek: Int = 1
    @State private var selectedMonth: ApprovalMonthOption = {
        let comps = Calendar.current.dateComponents([.year, .month], from: Date())
        return ApprovalMonthOption(year: comps.year ?? 2026, month: comps.month ?? 1)
    }()

    @State private var searchText: String = ""
    @State private var debouncedSearch: String = ""
    @State private var searchTask: Task<Void, Never>?

    @State private var requests: [LeaveRequestGroup] = []
    @State private var currentPage: Int = 1
    @State private var totalCount: Int = 0
    @State private var hasMore: Bool = false
    @State private var isLoading = false
    @State private var isLoadingMore = false
    @State private var requestGeneration: Int = 0
    @State private var errorMessage: String?

    @State private var rejectingRequest: LeaveRequestGroup?
    @State private var rejectionReason: String = ""
    @State private var partialApprovalRequest: LeaveRequestGroup?
    @State private var previewImagePath: String?
    @State private var hapticSuccess = 0

    private let filters: [(key: String, label: String)] = [
        ("pending", "待审批"),
        ("approved", "已通过"),
        ("rejected", "已驳回"),
        ("all", "全部")
    ]

    private var calendar: Calendar {
        var cal = Calendar(identifier: .gregorian)
        cal.timeZone = TimeZone(identifier: "Asia/Shanghai") ?? .current
        return cal
    }

    private var currentSemester: Semester? {
        semesters.first(where: { $0.isCurrentFlag }) ?? semesters.first
    }

    private var semesterTotalWeeks: Int {
        max(1, currentSemester?.totalWeeks ?? 20)
    }

    private var semesterBounds: (start: Date, end: Date)? {
        guard let rawStart = currentSemester?.startDate,
              let start = SCDateFormatter.parseDate(rawStart),
              let end = calendar.date(byAdding: .day, value: semesterTotalWeeks * 7 - 1, to: start) else {
            return nil
        }
        return (start, end)
    }

    private var availableMonths: [ApprovalMonthOption] {
        var list: [ApprovalMonthOption] = []
        if let bounds = semesterBounds {
            var comps = calendar.dateComponents([.year, .month], from: bounds.start)
            comps.day = 1
            let endComps = calendar.dateComponents([.year, .month], from: bounds.end)
            while let cursor = calendar.date(from: comps),
                  let y = comps.year, let m = comps.month,
                  let ey = endComps.year, let em = endComps.month,
                  (y < ey || (y == ey && m <= em)) {
                list.append(ApprovalMonthOption(year: y, month: m))
                if let next = calendar.date(byAdding: .month, value: 1, to: cursor) {
                    comps = calendar.dateComponents([.year, .month], from: next)
                    comps.day = 1
                } else {
                    break
                }
            }
        }
        if list.isEmpty {
            let now = Date()
            for offset in stride(from: 5, through: 0, by: -1) {
                if let d = calendar.date(byAdding: .month, value: -offset, to: now) {
                    let c = calendar.dateComponents([.year, .month], from: d)
                    if let y = c.year, let m = c.month {
                        list.append(ApprovalMonthOption(year: y, month: m))
                    }
                }
            }
        }
        return list
    }

    private func weekBounds(for week: Int) -> (start: Date, end: Date)? {
        guard let bounds = semesterBounds else { return nil }
        let clamped = min(max(1, week), semesterTotalWeeks)
        guard let ws = calendar.date(byAdding: .day, value: (clamped - 1) * 7, to: bounds.start),
              let we = calendar.date(byAdding: .day, value: 6, to: ws) else {
            return nil
        }
        return (ws, we)
    }

    private func weekLabel(for week: Int) -> String {
        if let wb = weekBounds(for: week) {
            return "第\(week)周 (\(SCDateFormatter.cnMonthDay.string(from: wb.start))-\(SCDateFormatter.cnMonthDay.string(from: wb.end)))"
        }
        return "第\(week)周"
    }

    private var activeDateRange: (from: String?, to: String?, summary: String) {
        let today = calendar.startOfDay(for: Date())
        switch timeScope {
        case .all:
            return (nil, nil, statusFilter == "pending" ? "全部待审批（不限时间，防漏批）" : "全部历史记录")
        case .recent30:
            let rawFrom = calendar.date(byAdding: .day, value: -29, to: today) ?? today
            if let bounds = semesterBounds {
                let effectiveFrom: Date
                if today > bounds.end {
                    let tailFrom = calendar.date(byAdding: .day, value: -29, to: bounds.end) ?? bounds.start
                    effectiveFrom = max(bounds.start, tailFrom)
                } else {
                    effectiveFrom = max(bounds.start, rawFrom)
                }
                return (
                    SCDateFormatter.yyyyMMdd.string(from: effectiveFrom),
                    SCDateFormatter.yyyyMMdd.string(from: bounds.end),
                    "本学期近30天"
                )
            }
            return (SCDateFormatter.yyyyMMdd.string(from: rawFrom), nil, "近30天")
        case .week:
            if let wb = weekBounds(for: selectedWeek) {
                return (
                    SCDateFormatter.yyyyMMdd.string(from: wb.start),
                    SCDateFormatter.yyyyMMdd.string(from: wb.end),
                    weekLabel(for: selectedWeek)
                )
            }
            let weekStart = calendar.date(byAdding: .day, value: -6, to: today) ?? today
            return (SCDateFormatter.yyyyMMdd.string(from: weekStart), SCDateFormatter.yyyyMMdd.string(from: today), "近7天")
        case .month:
            var comps = DateComponents()
            comps.year = selectedMonth.year
            comps.month = selectedMonth.month
            comps.day = 1
            if let firstDay = calendar.date(from: comps),
               let range = calendar.range(of: .day, in: .month, for: firstDay),
               let lastDay = calendar.date(byAdding: .day, value: range.count - 1, to: firstDay) {
                return (
                    SCDateFormatter.yyyyMMdd.string(from: firstDay),
                    SCDateFormatter.yyyyMMdd.string(from: lastDay),
                    selectedMonth.displayTitle
                )
            }
            return (nil, nil, selectedMonth.displayTitle)
        case .semester:
            if let bounds = semesterBounds {
                return (
                    SCDateFormatter.yyyyMMdd.string(from: bounds.start),
                    SCDateFormatter.yyyyMMdd.string(from: bounds.end),
                    currentSemester?.name ?? "本学期"
                )
            }
            return (nil, nil, "本学期")
        }
    }

    var body: some View {
        NavigationStack {
            VStack(spacing: 0) {
                // 1. 审批状态分段条
                Picker("审批状态", selection: $statusFilter) {
                    ForEach(filters, id: \.key) { item in
                        Text(item.label).tag(item.key)
                    }
                }
                .pickerStyle(.segmented)
                .padding(.horizontal)
                .padding(.top, 8)
                .padding(.bottom, 6)
                .background(SCTheme.background)

                // 2. 时间筛选胶囊条
                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(spacing: 8) {
                        ForEach(ApprovalTimeScope.allCases) { scope in
                            if scope == .week && semesterBounds == nil {
                                EmptyView()
                            } else {
                                let selected = timeScope == scope
                                Button {
                                    timeScope = scope
                                    if statusFilter != "pending" {
                                        lastHistoryScope = scope
                                    }
                                } label: {
                                    Text(scope.label)
                                        .font(.caption.weight(selected ? .semibold : .regular))
                                        .padding(.horizontal, 12)
                                        .padding(.vertical, 6)
                                        .background(selected ? SCTheme.primary : Color(.secondarySystemGroupedBackground))
                                        .foregroundStyle(selected ? .white : .primary)
                                        .clipShape(Capsule())
                                }
                                .buttonStyle(.plain)
                            }
                        }
                    }
                    .padding(.horizontal)
                    .padding(.vertical, 4)
                }
                .background(SCTheme.background)

                // 3. 教学周 / 月份二级切换器
                if timeScope == .week, semesterBounds != nil {
                    weekNavigatorBar
                        .padding(.horizontal)
                        .padding(.top, 4)
                        .padding(.bottom, 2)
                        .background(SCTheme.background)
                } else if timeScope == .month {
                    monthNavigatorBar
                        .padding(.horizontal)
                        .padding(.top, 4)
                        .padding(.bottom, 2)
                        .background(SCTheme.background)
                }

                // 4. 当前筛选范围与总数摘要
                HStack {
                    Text(activeDateRange.summary)
                        .font(.caption)
                        .foregroundStyle(.secondary)
                    Spacer()
                    if isLoading && requests.isEmpty {
                        Text("加载中...")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    } else {
                        Text("共 \(totalCount) 条")
                            .font(.caption.weight(.semibold))
                            .foregroundStyle(SCTheme.primary)
                    }
                }
                .padding(.horizontal)
                .padding(.vertical, 6)
                .background(SCTheme.background)

                Group {
                    if isLoading && requests.isEmpty {
                        ProgressView("加载请假审批列表...")
                            .frame(maxWidth: .infinity, maxHeight: .infinity)
                    } else if let errorMessage, requests.isEmpty {
                        ErrorBannerView(message: errorMessage) {
                            Task { await loadFirstPage() }
                        }
                        .padding()
                        Spacer()
                    } else if requests.isEmpty {
                        ContentUnavailableView(
                            statusFilter == "pending" ? "暂无待审批请假" : "当前范围暂无记录",
                            systemImage: "checkmark.seal",
                            description: Text(statusFilter == "pending"
                                ? "所有学生请假申请会第一时间推送到这里"
                                : "可切换教学周、按月或本学期查看更多历史审批")
                        )
                    } else {
                        List {
                            if let errorMessage {
                                ErrorBannerView(message: errorMessage) {
                                    Task { await loadFirstPage() }
                                }
                                .listRowInsets(EdgeInsets(top: 6, leading: 14, bottom: 6, trailing: 14))
                                .listRowBackground(Color.clear)
                                .listRowSeparator(.hidden)
                            }

                            ForEach(requests) { req in
                                ApprovalItemRow(
                                    request: req,
                                    onApproveAll: { Task { await approve(req, recordIds: nil) } },
                                    onPartialApprove: { partialApprovalRequest = req },
                                    onReject: {
                                        rejectionReason = ""
                                        rejectingRequest = req
                                    },
                                    onImageTap: { previewImagePath = $0 }
                                )
                                .listRowInsets(EdgeInsets(top: 8, leading: 14, bottom: 8, trailing: 14))
                                .listRowBackground(Color.clear)
                                .listRowSeparator(.hidden)
                                .swipeActions(edge: .leading, allowsFullSwipe: true) {
                                    if req.effectiveStatus == "pending" {
                                        Button {
                                            Task { await approve(req, recordIds: nil) }
                                        } label: {
                                            Label("通过", systemImage: "checkmark.circle.fill")
                                        }
                                        .tint(.green)
                                    }
                                }
                                .swipeActions(edge: .trailing) {
                                    if req.effectiveStatus == "pending" {
                                        Button(role: .destructive) {
                                            rejectionReason = ""
                                            rejectingRequest = req
                                        } label: {
                                            Label("驳回", systemImage: "xmark.circle.fill")
                                        }
                                    }
                                }
                            }

                            // 方案 A：滚到底部自动加载下一页 + 清晰总数反馈
                            loadMoreFooter
                                .listRowInsets(EdgeInsets(top: 10, leading: 14, bottom: 18, trailing: 14))
                                .listRowBackground(Color.clear)
                                .listRowSeparator(.hidden)
                                .onAppear {
                                    if hasMore && !isLoading && !isLoadingMore {
                                        Task { await loadMore() }
                                    }
                                }
                        }
                        .listStyle(.plain)
                        .background(SCTheme.background)
                    }
                }
            }
            .navigationTitle("请假审批")
            .searchable(text: $searchText, prompt: "搜索学生姓名、学号或班级")
            .sensoryFeedback(.success, trigger: hapticSuccess)
            .refreshable {
                await loadFirstPage()
            }
            .onChange(of: statusFilter) { oldValue, newValue in
                let targetScope: ApprovalTimeScope
                if newValue == "pending" {
                    targetScope = .all
                } else if oldValue == "pending" {
                    targetScope = lastHistoryScope
                } else {
                    targetScope = timeScope
                }
                if targetScope != timeScope {
                    timeScope = targetScope
                } else {
                    Task { await loadFirstPage() }
                }
            }
            .onChange(of: timeScope) { _, _ in
                Task { await loadFirstPage() }
            }
            .onChange(of: selectedWeek) { _, _ in
                if timeScope == .week {
                    Task { await loadFirstPage() }
                }
            }
            .onChange(of: selectedMonth) { _, _ in
                if timeScope == .month {
                    Task { await loadFirstPage() }
                }
            }
            .onChange(of: searchText) { _, newValue in
                searchTask?.cancel()
                searchTask = Task {
                    try? await Task.sleep(for: .milliseconds(350))
                    guard !Task.isCancelled else { return }
                    let trimmed = newValue.trimmingCharacters(in: .whitespacesAndNewlines)
                    if trimmed != debouncedSearch {
                        debouncedSearch = trimmed
                        await loadFirstPage()
                    }
                }
            }
            .alert("驳回请假申请", isPresented: Binding(
                get: { rejectingRequest != nil },
                set: { if !$0 { rejectingRequest = nil } }
            )) {
                TextField("请输入驳回原因（可选）", text: $rejectionReason)
                Button("取消", role: .cancel) {}
                Button("确认驳回", role: .destructive) {
                    if let target = rejectingRequest {
                        Task { await reject(target, reason: rejectionReason) }
                    }
                }
            } message: {
                Text("驳回后系统将通知 \(rejectingRequest?.student?.name ?? "该学生")。")
            }
            .sheet(item: $partialApprovalRequest) { req in
                PartialApprovalSheet(request: req) { selectedIds in
                    Task { await approve(req, recordIds: selectedIds) }
                }
            }
            .sheet(item: Binding(
                get: { previewImagePath.map { ImagePreviewPayload(path: $0) } },
                set: { previewImagePath = $0?.path }
            )) { payload in
                FullScreenImageSheet(pathOrURL: payload.path)
            }
            .task {
                if semesters.isEmpty {
                    await loadSemestersAndInitDefaults()
                }
                await loadFirstPage()
            }
        }
    }

    private var weekNavigatorBar: some View {
        HStack {
            Button {
                if selectedWeek > 1 { selectedWeek -= 1 }
            } label: {
                Image(systemName: "chevron.left")
                    .font(.subheadline.weight(.semibold))
                    .frame(width: 32, height: 28)
            }
            .disabled(selectedWeek <= 1)

            Spacer()

            Menu {
                ForEach(1...semesterTotalWeeks, id: \.self) { w in
                    Button {
                        selectedWeek = w
                    } label: {
                        HStack {
                            Text(weekLabel(for: w))
                            if w == selectedWeek {
                                Image(systemName: "checkmark")
                            }
                        }
                    }
                }
            } label: {
                HStack(spacing: 4) {
                    Text(weekLabel(for: selectedWeek))
                        .font(.subheadline.weight(.semibold))
                    Image(systemName: "chevron.down")
                        .font(.caption2)
                }
                .foregroundStyle(.primary)
            }

            Spacer()

            Button {
                if selectedWeek < semesterTotalWeeks { selectedWeek += 1 }
            } label: {
                Image(systemName: "chevron.right")
                    .font(.subheadline.weight(.semibold))
                    .frame(width: 32, height: 28)
            }
            .disabled(selectedWeek >= semesterTotalWeeks)
        }
        .padding(.horizontal, 8)
        .padding(.vertical, 6)
        .background(Color(.secondarySystemGroupedBackground))
        .clipShape(RoundedRectangle(cornerRadius: 10, style: .continuous))
    }

    private var monthNavigatorBar: some View {
        let months = availableMonths
        let idx = months.firstIndex(of: selectedMonth) ?? max(0, months.count - 1)
        return HStack {
            Button {
                if idx > 0 { selectedMonth = months[idx - 1] }
            } label: {
                Image(systemName: "chevron.left")
                    .font(.subheadline.weight(.semibold))
                    .frame(width: 32, height: 28)
            }
            .disabled(idx <= 0)

            Spacer()

            Menu {
                ForEach(months) { opt in
                    Button {
                        selectedMonth = opt
                    } label: {
                        HStack {
                            Text(opt.displayTitle)
                            if opt == selectedMonth {
                                Image(systemName: "checkmark")
                            }
                        }
                    }
                }
            } label: {
                HStack(spacing: 4) {
                    Text(selectedMonth.displayTitle)
                        .font(.subheadline.weight(.semibold))
                    Image(systemName: "chevron.down")
                        .font(.caption2)
                }
                .foregroundStyle(.primary)
            }

            Spacer()

            Button {
                if idx + 1 < months.count { selectedMonth = months[idx + 1] }
            } label: {
                Image(systemName: "chevron.right")
                    .font(.subheadline.weight(.semibold))
                    .frame(width: 32, height: 28)
            }
            .disabled(idx + 1 >= months.count)
        }
        .padding(.horizontal, 8)
        .padding(.vertical, 6)
        .background(Color(.secondarySystemGroupedBackground))
        .clipShape(RoundedRectangle(cornerRadius: 10, style: .continuous))
    }

    @ViewBuilder
    private var loadMoreFooter: some View {
        HStack {
            Spacer()
            if isLoadingMore {
                HStack(spacing: 8) {
                    ProgressView()
                    Text("正在加载更多...")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
            } else if hasMore {
                Button {
                    Task { await loadMore() }
                } label: {
                    Text("点击加载更多（已显示 \(requests.count) / \(totalCount) 条）")
                        .font(.caption.weight(.medium))
                        .foregroundStyle(SCTheme.primary)
                }
                .buttonStyle(.plain)
            } else {
                Text("已显示全部 \(totalCount) 条记录")
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }
            Spacer()
        }
    }

    private func loadSemestersAndInitDefaults() async {
        semesters = (try? await AttendanceRepository.fetchSemesters()) ?? []
        let today = calendar.startOfDay(for: Date())
        if let bounds = semesterBounds {
            let days = calendar.dateComponents([.day], from: bounds.start, to: today).day ?? 0
            selectedWeek = min(max(1, days / 7 + 1), semesterTotalWeeks)
        }
        let months = availableMonths
        let nowComps = calendar.dateComponents([.year, .month], from: today)
        let nowOpt = ApprovalMonthOption(year: nowComps.year ?? 2026, month: nowComps.month ?? 1)
        if months.contains(nowOpt) {
            selectedMonth = nowOpt
        } else if let last = months.last {
            selectedMonth = last
        }
    }

    private func loadFirstPage() async {
        requestGeneration += 1
        let gen = requestGeneration
        isLoading = true
        isLoadingMore = false
        errorMessage = nil

        let range = activeDateRange
        do {
            let res = try await LeaveRepository.fetchLeaveRequestsPage(
                status: statusFilter,
                dateFrom: range.from,
                dateTo: range.to,
                search: debouncedSearch,
                page: 1,
                perPage: pageSize
            )
            guard gen == requestGeneration else { return }
            requests = res.items
            currentPage = res.currentPage
            totalCount = res.total
            hasMore = res.hasMore
        } catch {
            guard gen == requestGeneration else { return }
            errorMessage = error.localizedDescription
        }
        if gen == requestGeneration {
            isLoading = false
        }
    }

    private func loadMore() async {
        guard hasMore && !isLoading && !isLoadingMore else { return }
        let gen = requestGeneration
        let nextPage = currentPage + 1
        isLoadingMore = true
        let range = activeDateRange

        do {
            let res = try await LeaveRepository.fetchLeaveRequestsPage(
                status: statusFilter,
                dateFrom: range.from,
                dateTo: range.to,
                search: debouncedSearch,
                page: nextPage,
                perPage: pageSize
            )
            guard gen == requestGeneration else { return }
            let existingKeys = Set(requests.map { "\($0.id)_\($0.effectiveStatus)" })
            let appended = res.items.filter { !existingKeys.contains("\($0.id)_\($0.effectiveStatus)") }
            requests.append(contentsOf: appended)
            currentPage = res.currentPage
            totalCount = res.total
            hasMore = res.hasMore
        } catch {
            guard gen == requestGeneration else { return }
            errorMessage = error.localizedDescription
        }
        if gen == requestGeneration {
            isLoadingMore = false
        }
    }

    private func approve(_ req: LeaveRequestGroup, recordIds: [Int]?) async {
        do {
            try await LeaveRepository.approveLeaveRequest(id: req.id, recordIds: recordIds)
            hapticSuccess += 1
            await loadFirstPage()
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    private func reject(_ req: LeaveRequestGroup, reason: String) async {
        do {
            try await LeaveRepository.rejectLeaveRequest(id: req.id, reason: reason)
            hapticSuccess += 1
            await loadFirstPage()
        } catch {
            errorMessage = error.localizedDescription
        }
    }
}

private struct ApprovalItemRow: View {
    let request: LeaveRequestGroup
    let onApproveAll: () -> Void
    let onPartialApprove: () -> Void
    let onReject: () -> Void
    let onImageTap: (String) -> Void

    private var pendingSubRecordsCount: Int {
        (request.records ?? []).filter { $0.approvalStatus == "pending" }.count
    }

    var body: some View {
        SCCard {
            VStack(alignment: .leading, spacing: 10) {
                HStack(alignment: .center) {
                    VStack(alignment: .leading, spacing: 2) {
                        HStack(spacing: 6) {
                            Text(request.student?.name ?? "学生")
                                .font(.headline)
                            if let cls = request.schoolClass?.name {
                                Text(cls)
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                            }
                        }
                        if let no = request.student?.studentNo {
                            Text("学号：\(no)")
                                .font(.caption2)
                                .foregroundStyle(.tertiary)
                        }
                    }
                    Spacer()
                    StatusBadge(
                        text: request.leaveType?.name ?? "请假",
                        color: SCTheme.color(for: request.leaveType?.color ?? "blue")
                    )
                    StatusBadge(
                        text: request.statusDisplayName,
                        color: SCTheme.color(for: request.effectiveStatus)
                    )
                }

                Label(request.displayDateRange, systemImage: "calendar")
                    .font(.subheadline.weight(.medium))

                if let period = request.halfDayLabel ?? request.periodLabel, !period.isEmpty {
                    Label("节次/时段：\(period)", systemImage: "clock")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }

                if let es = request.eveningStudyLabel, !es.isEmpty {
                    Label("夜自习：\(es)" + (request.destination.map { " · 去向：\($0)" } ?? ""), systemImage: "moon.stars")
                        .font(.caption)
                        .foregroundStyle(.indigo)
                }

                if let reason = request.reason, !reason.isEmpty {
                    Text("请假事由：\(reason)")
                        .font(.footnote)
                        .padding(10)
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .background(Color(.tertiarySystemFill).opacity(0.6))
                        .clipShape(RoundedRectangle(cornerRadius: 8, style: .continuous))
                }

                if let images = request.images, !images.isEmpty {
                    ScrollView(.horizontal, showsIndicators: false) {
                        HStack(spacing: 8) {
                            ForEach(images, id: \.self) { path in
                                Button {
                                    onImageTap(path)
                                } label: {
                                    RemoteMediaImage(pathOrURL: path, height: 64)
                                        .frame(width: 64, height: 64)
                                        .clipShape(RoundedRectangle(cornerRadius: 8, style: .continuous))
                                }
                                .buttonStyle(.plain)
                            }
                        }
                    }
                }

                if request.effectiveStatus == "pending" {
                    HStack(spacing: 10) {
                        Button(role: .destructive, action: onReject) {
                            Text("驳回")
                                .font(.subheadline.weight(.semibold))
                                .frame(maxWidth: .infinity)
                        }
                        .buttonStyle(.bordered)

                        if pendingSubRecordsCount > 1 {
                            Button(action: onPartialApprove) {
                                Text("分项批")
                                    .font(.subheadline.weight(.semibold))
                            }
                            .buttonStyle(.bordered)
                        }

                        Button(action: onApproveAll) {
                            Text("批准通过")
                                .font(.subheadline.weight(.semibold))
                                .frame(maxWidth: .infinity)
                        }
                        .buttonStyle(.borderedProminent)
                        .tint(.green)
                    }
                    .padding(.top, 2)
                } else if let approver = request.approverName {
                    Text("审批人：\(approver) · \(SCDateFormatter.displayDateTime(request.approvedAt ?? request.createdAt))")
                        .font(.caption2)
                        .foregroundStyle(.secondary)
                }
            }
        }
    }
}

private struct PartialApprovalSheet: View {
    let request: LeaveRequestGroup
    let onConfirm: ([Int]) -> Void
    @Environment(\.dismiss) private var dismiss

    @State private var selectedIds: Set<Int> = []

    private var pendingRecords: [LeaveSubRecord] {
        (request.records ?? []).filter { $0.approvalStatus == "pending" }
    }

    var body: some View {
        NavigationStack {
            List(pendingRecords) { sub in
                let isSelected = selectedIds.contains(sub.id)
                Button {
                    if isSelected { selectedIds.remove(sub.id) } else { selectedIds.insert(sub.id) }
                } label: {
                    HStack {
                        Image(systemName: isSelected ? "checkmark.circle.fill" : "circle")
                            .foregroundStyle(isSelected ? .green : .secondary)
                        VStack(alignment: .leading, spacing: 2) {
                            Text("\(sub.date ?? "") · \(sub.label ?? "全天")")
                                .foregroundStyle(.primary)
                            if sub.scene == "evening_study" {
                                Text("夜自习" + (sub.destination.map { " · 去向：\($0)" } ?? ""))
                                    .font(.caption)
                                    .foregroundStyle(.indigo)
                            }
                        }
                    }
                }
            }
            .navigationTitle("选择要批准的节次")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("取消") { dismiss() }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("批准选中（\(selectedIds.count)）") {
                        dismiss()
                        onConfirm(Array(selectedIds))
                    }
                    .disabled(selectedIds.isEmpty)
                }
            }
            .onAppear {
                selectedIds = Set(pendingRecords.map(\.id))
            }
        }
    }
}
