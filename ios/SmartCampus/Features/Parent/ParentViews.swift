import SwiftUI

/// 父母端首页：孩子请假申请与被标记的实时动态推送流
struct ParentFeedView: View {
    let user: User
    var onOpenChildDetail: ((Int) -> Void)? = nil

    @State private var children: [ParentChildSummary] = []
    @State private var selectedChildId: Int?
    @State private var items: [AppNotificationItem] = []
    @State private var filterUnreadOnly = false
    @State private var isLoading = false
    @State private var errorMessage: String?

    private var filteredItems: [AppNotificationItem] {
        items.filter { item in
            if filterUnreadOnly && !item.isUnread { return false }
            if let sid = selectedChildId, children.count > 1, let itemSid = item.studentId {
                return itemSid == sid
            }
            return true
        }
    }

    var body: some View {
        NavigationStack {
            List {
                if let errorMessage {
                    Section {
                        ErrorBannerView(message: errorMessage) {
                            Task { await loadAll() }
                        }
                    }
                }

                if !children.isEmpty {
                    Section {
                        ScrollView(.horizontal, showsIndicators: false) {
                            HStack(spacing: 10) {
                                if children.count > 1 {
                                    childChip(title: "全部孩子", subtitle: "\(children.count) 人", isSelected: selectedChildId == nil) {
                                        selectedChildId = nil
                                    }
                                }
                                ForEach(children) { child in
                                    childChip(
                                        title: child.name,
                                        subtitle: child.className ?? "-",
                                        badgeCount: (child.todayAnomalyCount?.value ?? 0) + (child.pendingLeaveCount?.value ?? 0),
                                        isSelected: selectedChildId == child.id
                                    ) {
                                        selectedChildId = child.id
                                    }
                                }
                            }
                            .padding(.vertical, 4)
                        }
                    } header: {
                        Text("关联学生")
                    }
                }

                Section {
                    Picker("筛选", selection: $filterUnreadOnly) {
                        Text("全部动态").tag(false)
                        Text("仅看未读").tag(true)
                    }
                    .pickerStyle(.segmented)
                    .listRowBackground(Color.clear)
                    .listRowInsets(EdgeInsets(top: 4, leading: 0, bottom: 4, trailing: 0))
                }

                Section("实时推送动态") {
                    if isLoading && items.isEmpty {
                        ProgressView("加载孩子动态...")
                            .frame(maxWidth: .infinity, alignment: .center)
                            .padding(.vertical, 16)
                    } else if filteredItems.isEmpty {
                        ContentUnavailableView(
                            filterUnreadOnly ? "暂无未读动态" : "暂无孩子动态",
                            systemImage: "bell.badge",
                            description: Text("孩子提交请假、审批结果或被标记考勤异常时，将实时推送到这里")
                        )
                    } else {
                        ForEach(filteredItems) { item in
                            Button {
                                Task { await handleTap(item) }
                            } label: {
                                HStack(alignment: .top, spacing: 10) {
                                    Circle()
                                        .fill(item.isUnread ? SCTheme.primary : Color.clear)
                                        .frame(width: 8, height: 8)
                                        .padding(.top, 6)

                                    VStack(alignment: .leading, spacing: 6) {
                                        HStack(spacing: 6) {
                                            StatusBadge(text: eventBadgeLabel(item.type), color: eventBadgeColor(item.type))
                                            Spacer()
                                            Text(SCDateFormatter.displayDateTime(item.createdAt))
                                                .font(.caption2)
                                                .foregroundStyle(.tertiary)
                                        }
                                        Text(item.title)
                                            .font(.subheadline.weight(item.isUnread ? .semibold : .regular))
                                            .foregroundStyle(.primary)
                                        Text(item.body)
                                            .font(.footnote)
                                            .foregroundStyle(.secondary)
                                            .fixedSize(horizontal: false, vertical: true)
                                    }
                                }
                                .padding(.vertical, 4)
                            }
                            .swipeActions(edge: .trailing) {
                                Button(role: .destructive) {
                                    Task { await deleteItem(item) }
                                } label: {
                                    Label("删除", systemImage: "trash")
                                }
                            }
                        }
                    }
                }
            }
            .listStyle(.insetGrouped)
            .navigationTitle("孩子动态")
            .toolbar {
                ToolbarItem(placement: .topBarTrailing) {
                    if items.contains(where: { $0.isUnread }) {
                        Button("全部已读") {
                            Task { await markAllRead() }
                        }
                    }
                }
            }
            .safeRefreshable {
                await loadAll()
            }
            .task {
                await loadAll()
            }
        }
    }

    private func childChip(
        title: String,
        subtitle: String,
        badgeCount: Int = 0,
        isSelected: Bool,
        action: @escaping () -> Void
    ) -> some View {
        Button(action: action) {
            HStack(spacing: 8) {
                VStack(alignment: .leading, spacing: 2) {
                    Text(title)
                        .font(.subheadline.weight(.semibold))
                        .foregroundStyle(isSelected ? .white : .primary)
                    Text(subtitle)
                        .font(.caption2)
                        .foregroundStyle(isSelected ? .white.opacity(0.85) : .secondary)
                }
                if badgeCount > 0 {
                    Text("\(badgeCount)")
                        .font(.caption2.bold())
                        .foregroundStyle(isSelected ? SCTheme.primary : .white)
                        .padding(.horizontal, 6)
                        .padding(.vertical, 2)
                        .background(isSelected ? Color.white : Color.red)
                        .clipShape(Capsule())
                }
            }
            .padding(.horizontal, 12)
            .padding(.vertical, 8)
            .background(isSelected ? SCTheme.primary : Color(.tertiarySystemFill))
            .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
        }
        .buttonStyle(.plain)
    }

    private func eventBadgeLabel(_ type: String) -> String {
        switch type {
        case "parent_leave_submitted": return "请假申请"
        case "parent_leave_approved": return "审批通过"
        case "parent_leave_rejected": return "审批驳回"
        case "parent_attendance_marked": return "考勤标记"
        case "parent_evening_study_alert": return "夜自习/住宿"
        default: return "消息提醒"
        }
    }

    private func eventBadgeColor(_ type: String) -> Color {
        switch type {
        case "parent_leave_submitted": return .blue
        case "parent_leave_approved": return .green
        case "parent_leave_rejected": return .red
        case "parent_attendance_marked": return .orange
        case "parent_evening_study_alert": return .purple
        default: return .secondary
        }
    }

    private func loadAll() async {
        isLoading = true
        errorMessage = nil
        defer { isLoading = false }

        do {
            async let childrenTask = ParentRepository.fetchChildren()
            async let notifTask = NotificationRepository.fetchNotifications()
            let (fetchedChildren, notifPage) = try await (childrenTask, notifTask)
            children = fetchedChildren
            if fetchedChildren.count == 1 {
                selectedChildId = fetchedChildren.first?.id
            }
            items = notifPage.data
            PushNotificationManager.shared.unreadCount = notifPage.unreadCount
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    private func handleTap(_ item: AppNotificationItem) async {
        if item.isUnread {
            try? await NotificationRepository.markRead(id: item.id)
            await PushNotificationManager.shared.refreshUnreadCount()
            if let idx = items.firstIndex(where: { $0.id == item.id }) {
                await loadAll()
                _ = idx
            }
        }
        if let sid = item.studentId {
            onOpenChildDetail?(sid)
        }
    }

    private func markAllRead() async {
        try? await NotificationRepository.markAllRead()
        await PushNotificationManager.shared.refreshUnreadCount()
        await loadAll()
    }

    private func deleteItem(_ item: AppNotificationItem) async {
        try? await NotificationRepository.deleteNotification(id: item.id)
        items.removeAll { $0.id == item.id }
        await PushNotificationManager.shared.refreshUnreadCount()
    }
}

/// 孩子考勤与请假详情页（供纯家长 Tab 与兼任家长的教职工共同复用）
struct ParentChildAttendanceView: View {
    let user: User
    var initialStudentId: Int? = nil
    var embedInNavigationStack: Bool = true

    @State private var children: [ParentChildSummary] = []
    @State private var selectedChildId: Int?
    @State private var scope: String = "month"
    @State private var sectionTab: SectionTab = .records
    @State private var overview: ParentChildOverviewResponse?
    @State private var leaveRequests: [ParentLeaveRequestItem] = []
    @State private var records: [ParentRecordItem] = []
    @State private var isLoading = false
    @State private var errorMessage: String?

    enum SectionTab: String, CaseIterable {
        case records = "考勤标记"
        case leaves = "请假申请"
    }

    private let scopes: [(key: String, label: String)] = [
        ("today", "今日"),
        ("week", "本周"),
        ("month", "本月"),
        ("semester", "本学期")
    ]

    var body: some View {
        Group {
            if embedInNavigationStack {
                NavigationStack { content }
            } else {
                content
            }
        }
    }

    private var content: some View {
        ScrollView {
            VStack(spacing: 16) {
                if let errorMessage {
                    ErrorBannerView(message: errorMessage) {
                        Task { await loadChildrenAndDetail() }
                    }
                }

                // Child selector
                if children.count > 1 {
                    ScrollView(.horizontal, showsIndicators: false) {
                        HStack(spacing: 10) {
                            ForEach(children) { child in
                                let active = child.id == selectedChildId
                                Button {
                                    selectedChildId = child.id
                                    Task { await loadSelectedChildData() }
                                } label: {
                                    HStack(spacing: 8) {
                                        Text(child.name)
                                            .font(.subheadline.weight(.semibold))
                                        Text(child.className ?? "")
                                            .font(.caption2)
                                    }
                                    .padding(.horizontal, 14)
                                    .padding(.vertical, 8)
                                    .background(active ? SCTheme.primary : SCTheme.cardBackground)
                                    .foregroundStyle(active ? .white : .primary)
                                    .clipShape(Capsule())
                                }
                                .buttonStyle(.plain)
                            }
                        }
                    }
                }

                // Active Child Info Card
                if let child = children.first(where: { $0.id == selectedChildId }) {
                    SCCard {
                        HStack(spacing: 12) {
                            ZStack {
                                Circle()
                                    .fill(SCTheme.primary.opacity(0.15))
                                    .frame(width: 48, height: 48)
                                Text(String(child.name.prefix(1)))
                                    .font(.title3.bold())
                                    .foregroundStyle(SCTheme.primary)
                            }
                            VStack(alignment: .leading, spacing: 4) {
                                HStack(spacing: 6) {
                                    Text(child.name).font(.headline)
                                    if child.isBoardingFlag {
                                        StatusBadge(text: "住宿生", color: .purple)
                                    }
                                    if child.isGraduatedFlag {
                                        StatusBadge(text: "已毕业", color: .gray)
                                    }
                                }
                                Text("\(child.className ?? "-") · 学号 \(child.studentNo ?? "-")")
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                                if let teacher = child.teacherName, !teacher.isEmpty, teacher != "-" {
                                    Text("班主任：\(teacher)")
                                        .font(.caption2)
                                        .foregroundStyle(.tertiary)
                                }
                            }
                            Spacer()
                        }
                    }
                } else if !isLoading && children.isEmpty {
                    ContentUnavailableView(
                        "暂未关联学生",
                        systemImage: "person.2.slash",
                        description: Text("请联系班主任在学生信息中录入您的家长邮箱")
                    )
                }

                if selectedChildId != nil {
                    Picker("统计周期", selection: $scope) {
                        ForEach(scopes, id: \.key) { item in
                            Text(item.label).tag(item.key)
                        }
                    }
                    .pickerStyle(.segmented)
                    .onChange(of: scope) { _, _ in
                        Task { await loadSelectedChildData() }
                    }

                    if let ov = overview {
                        LazyVGrid(columns: [GridItem(.flexible()), GridItem(.flexible())], spacing: 12) {
                            StatMetricCard(
                                title: scope == "today"
                                    ? (ov.stats.isRestDay ? "今日休息日" : "今日出勤")
                                    : "正常出勤（天）",
                                value: scope == "today"
                                    ? (ov.stats.isRestDay ? "休息" : (ov.stats.present >= 1 ? "出勤" : "缺勤"))
                                    : "\(ov.stats.present)/\(ov.stats.workingDays)",
                                icon: "checkmark.circle.fill",
                                color: .green
                            )

                            if let pending = ov.stats.pendingRequests, pending > 0 {
                                StatMetricCard(
                                    title: "待审批请假",
                                    value: "\(pending)",
                                    icon: "clock.badge.exclamationmark",
                                    color: .orange,
                                    subtitle: "条申请待处理"
                                )
                            }

                            ForEach(ov.leaveTypes) { lt in
                                let cnt = ov.stats.leaveCounts[lt.slug] ?? 0
                                let unit = ov.stats.leaveConfigs[lt.slug]?.displayUnit ?? lt.displayUnit ?? "节"
                                StatMetricCard(
                                    title: lt.name,
                                    value: "\(cnt)\(unit)",
                                    icon: "doc.text.fill",
                                    color: SCTheme.color(for: lt.resolvedColorKey)
                                )
                            }
                        }
                    } else if isLoading {
                        ProgressView("加载考勤统计...")
                            .padding(.vertical, 20)
                    }

                    Picker("明细切换", selection: $sectionTab) {
                        ForEach(SectionTab.allCases, id: \.self) { tab in
                            Text(tab.rawValue).tag(tab)
                        }
                    }
                    .pickerStyle(.segmented)

                    if sectionTab == .records {
                        SCCard {
                            VStack(alignment: .leading, spacing: 12) {
                                Text("考勤与异常标记明细")
                                    .font(.headline)
                                if records.isEmpty {
                                    Text("当前周期内无异常考勤标记")
                                        .font(.footnote)
                                        .foregroundStyle(.secondary)
                                        .padding(.vertical, 8)
                                } else {
                                    ForEach(records) { rec in
                                        VStack(alignment: .leading, spacing: 4) {
                                            HStack(spacing: 8) {
                                                StatusBadge(
                                                    text: rec.typeName ?? rec.status ?? "考勤",
                                                    color: SCTheme.color(for: rec.color ?? rec.status)
                                                )
                                                Text(rec.date ?? "-")
                                                    .font(.subheadline.weight(.medium))
                                                if let t = rec.recordTime, !t.isEmpty {
                                                    Text(t).font(.caption).foregroundStyle(.secondary)
                                                }
                                                Spacer()
                                                Text(rec.sourceDisplayText)
                                                    .font(.caption2)
                                                    .foregroundStyle(.tertiary)
                                            }
                                            if let detail = rec.detailLabel, !detail.isEmpty {
                                                Text(detail).font(.caption).foregroundStyle(.secondary)
                                            }
                                            if let remark = rec.reason ?? rec.note, !remark.isEmpty {
                                                Text("备注：\(remark)").font(.caption).foregroundStyle(.secondary)
                                            }
                                        }
                                        .padding(.vertical, 4)
                                        if rec.id != records.last?.id {
                                            Divider()
                                        }
                                    }
                                }
                            }
                        }
                    } else {
                        SCCard {
                            VStack(alignment: .leading, spacing: 12) {
                                Text("孩子请假申请记录")
                                    .font(.headline)
                                if leaveRequests.isEmpty {
                                    Text("暂无自主请假申请记录")
                                        .font(.footnote)
                                        .foregroundStyle(.secondary)
                                        .padding(.vertical, 8)
                                } else {
                                    ForEach(leaveRequests) { req in
                                        VStack(alignment: .leading, spacing: 5) {
                                            HStack(spacing: 8) {
                                                Text(req.typeName ?? "请假")
                                                    .font(.subheadline.weight(.semibold))
                                                StatusBadge(
                                                    text: req.statusDisplayText,
                                                    color: SCTheme.color(for: req.effectiveStatus)
                                                )
                                                Spacer()
                                                Text(req.dateRangeText ?? req.startDate ?? "-")
                                                    .font(.caption)
                                                    .foregroundStyle(.secondary)
                                            }
                                            if let period = req.periodLabel, !period.isEmpty {
                                                Text("时段：\(period)").font(.caption).foregroundStyle(.secondary)
                                            }
                                            if let evening = req.eveningStudyLabel, !evening.isEmpty {
                                                Text("夜自习：\(evening)\(req.destination.map { "（去向：\($0)）" } ?? "")")
                                                    .font(.caption)
                                                    .foregroundStyle(.purple)
                                            }
                                            if let reason = req.reason, !reason.isEmpty {
                                                Text("原因：\(reason)").font(.caption).foregroundStyle(.secondary)
                                            }
                                            if let created = req.createdAt, !created.isEmpty {
                                                Text("申请时间：\(SCDateFormatter.displayDateTime(created))")
                                                    .font(.caption2)
                                                    .foregroundStyle(.secondary)
                                            }
                                            if let approver = req.approverName, !approver.isEmpty {
                                                let approvedTime = (req.approvedAt?.isEmpty == false)
                                                    ? " · \(SCDateFormatter.displayDateTime(req.approvedAt))"
                                                    : ""
                                                Text("审批人：\(approver)\(approvedTime)\(req.rejectionReason.map { " · 驳回原因：\($0)" } ?? "")")
                                                    .font(.caption2)
                                                    .foregroundStyle(.tertiary)
                                            }
                                        }
                                        .padding(.vertical, 4)
                                        if req.id != leaveRequests.last?.id {
                                            Divider()
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
            .padding()
        }
        .background(SCTheme.background.ignoresSafeArea())
        .navigationTitle("孩子考勤")
        .navigationBarTitleDisplayMode(.inline)
        .safeRefreshable {
            await loadChildrenAndDetail()
        }
        .task(id: initialStudentId) {
            if let initId = initialStudentId {
                selectedChildId = initId
            }
            await loadChildrenAndDetail()
        }
    }

    private func loadChildrenAndDetail() async {
        isLoading = true
        errorMessage = nil
        do {
            let list = try await ParentRepository.fetchChildren()
            children = list
            if let initId = initialStudentId, list.contains(where: { $0.id == initId }) {
                selectedChildId = initId
            } else if selectedChildId == nil || !list.contains(where: { $0.id == selectedChildId }) {
                selectedChildId = list.first?.id
            }
            isLoading = false
            await loadSelectedChildData()
        } catch {
            errorMessage = error.localizedDescription
            isLoading = false
        }
    }

    private func loadSelectedChildData() async {
        guard let childId = selectedChildId else { return }
        isLoading = true
        errorMessage = nil
        defer { isLoading = false }

        do {
            async let ovTask = ParentRepository.fetchChildOverview(studentId: childId, scope: scope)
            async let leaveTask = ParentRepository.fetchChildLeaveRequests(studentId: childId)
            async let recTask = ParentRepository.fetchChildRecords(studentId: childId, scope: scope)
            let (ov, leaves, recs) = try await (ovTask, leaveTask, recTask)
            overview = ov
            leaveRequests = leaves
            records = recs
        } catch {
            errorMessage = error.localizedDescription
        }
    }
}
