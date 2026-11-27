import SwiftUI

/// 班级考勤员（is_class_admin）专属工作台：
/// 1. 「考勤情况」：完整汇总本班当日请假申请（批准/待审）、手工登记、点名标记与夜自习情况，支持 1列/2列 彩色卡片切换（记忆选择）与类型筛选；
/// 2. 「全班名单」：按全部/有记录/正常筛选与搜索全班学生，支持点选维护单个学生的当日考勤。
struct ClassAttendanceAdminView: View {
    let user: User

    private enum WorkbenchTab: String, CaseIterable, Identifiable {
        case summary = "考勤情况"
        case roster = "全班名单"
        var id: String { rawValue }
    }

    private enum RosterFilter: String, CaseIterable, Identifiable {
        case all = "全部"
        case hasRecords = "有记录"
        case normal = "正常"
        var id: String { rawValue }
    }

    @AppStorage("smartcampus.class_admin_summary_columns") private var summaryColumns: Int = 2
    @AppStorage("smartcampus.class_admin_roster_columns") private var rosterColumns: Int = 2

    @State private var activeTab: WorkbenchTab = .summary
    @State private var selectedDate: Date = Date()
    @State private var showDatePickerSheet = false

    @State private var students: [AttendanceOverviewStudent] = []
    @State private var calendarDayRecords: [CalendarSummaryRecord] = []
    @State private var hasCalendarSummaryData = false
    @State private var leaveTypes: [LeaveType] = []
    @State private var classPeriods: [ClassPeriod] = []
    @State private var timeSlots: [TimeSlot] = []

    @State private var selectedTypeFilter: String? = nil
    @State private var rosterFilter: RosterFilter = .all
    @State private var rosterSearch: String = ""

    @State private var inspectedItem: ClassAdminSummaryItem?
    @State private var editingStudent: AttendanceOverviewStudent?
    @State private var previewImagePath: String?

    @State private var isLoading = false
    @State private var errorMessage: String?

    private var gregorian: Calendar {
        var cal = Calendar(identifier: .gregorian)
        cal.locale = Locale(identifier: "zh_CN")
        return cal
    }

    private var selectedDateKey: String {
        SCDateFormatter.yyyyMMdd.string(from: selectedDate)
    }

    private var selectedMonthKey: String {
        SCDateFormatter.yyyyMM.string(from: selectedDate)
    }

    private var isToday: Bool {
        gregorian.isDateInToday(selectedDate)
    }

    private var regularPeriods: [ClassPeriod] {
        let filtered = classPeriods.filter { $0.isActiveFlag && !$0.isEveningStudy }
        return filtered.isEmpty ? classPeriods : filtered
    }

    private var activeLeaveTypes: [LeaveType] {
        leaveTypes.filter(\.isActiveFlag)
    }

    /// 完整汇总条目：优先使用服务端 calendar-summary（与班主任端同源），老后端或 403 时自动用 overview 本地聚合兜底
    private var allSummaryItems: [ClassAdminSummaryItem] {
        if hasCalendarSummaryData {
            return calendarDayRecords
                .filter { !$0.isNormalPresentRecord && $0.approvalStatus != "rejected" }
                .map { buildItem(from: $0) }
        }
        return buildFallbackItemsFromOverview()
    }

    private var filteredSummaryItems: [ClassAdminSummaryItem] {
        guard let selectedTypeFilter else { return allSummaryItems }
        if selectedTypeFilter == "__pending__" {
            return allSummaryItems.filter { $0.approvalStatus == "pending" }
        }
        return allSummaryItems.filter { $0.typeName == selectedTypeFilter }
    }

    private var distinctSummaryStudentCount: Int {
        Set(allSummaryItems.map { $0.studentId.map(String.init) ?? $0.studentShortLabel }).count
    }

    private var pendingSummaryCount: Int {
        allSummaryItems.filter { $0.approvalStatus == "pending" }.count
    }

    private var typeFilterOptions: [(name: String, count: Int, colorKey: String)] {
        var counts: [String: Int] = [:]
        var colors: [String: String] = [:]
        for item in allSummaryItems {
            counts[item.typeName, default: 0] += 1
            if colors[item.typeName] == nil {
                colors[item.typeName] = item.colorKey
            }
        }
        var result: [(name: String, count: Int, colorKey: String)] = []
        var seen = Set<String>()
        for lt in activeLeaveTypes {
            let clean = lt.name.trimmingCharacters(in: .whitespacesAndNewlines)
            if !clean.isEmpty, let c = counts[clean], c > 0, seen.insert(clean).inserted {
                result.append((clean, c, lt.resolvedColorKey))
            }
        }
        for item in allSummaryItems {
            if seen.insert(item.typeName).inserted {
                result.append((item.typeName, counts[item.typeName] ?? 0, colors[item.typeName] ?? item.colorKey))
            }
        }
        return result
    }

    private var rosterHasRecordCount: Int {
        students.filter { st in
            (st.attendance ?? []).contains { !$0.isNormalPresentRecord && $0.approvalStatus != "rejected" }
        }.count
    }

    private var rosterNormalCount: Int {
        max(0, students.count - rosterHasRecordCount)
    }

    private var filteredRoster: [AttendanceOverviewStudent] {
        let q = rosterSearch.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        return students.filter { st in
            let hasRec = (st.attendance ?? []).contains { !$0.isNormalPresentRecord && $0.approvalStatus != "rejected" }
            switch rosterFilter {
            case .all: break
            case .hasRecords: if !hasRec { return false }
            case .normal: if hasRec { return false }
            }
            guard !q.isEmpty else { return true }
            return st.displayName.lowercased().contains(q)
                || (st.studentNo ?? "").lowercased().contains(q)
        }
    }

    var body: some View {
        ScrollView {
            VStack(spacing: 12) {
                dateNavigatorBar

                Picker("视图模式", selection: $activeTab) {
                    ForEach(WorkbenchTab.allCases) { tab in
                        Text(tab.rawValue).tag(tab)
                    }
                }
                .pickerStyle(.segmented)

                if let errorMessage {
                    ErrorBannerView(message: errorMessage) {
                        Task { await loadData() }
                    }
                }

                if activeTab == .summary {
                    summaryTabContent
                } else {
                    rosterTabContent
                }
            }
            .padding()
        }
        .background(SCTheme.background.ignoresSafeArea())
        .navigationTitle("班级考勤情况")
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            ToolbarItem(placement: .topBarTrailing) {
                Button {
                    Task { await loadData() }
                } label: {
                    if isLoading {
                        ProgressView().controlSize(.small)
                    } else {
                        Image(systemName: "arrow.clockwise")
                    }
                }
                .disabled(isLoading)
            }
        }
        .safeRefreshable {
            await loadData()
        }
        .onChange(of: selectedDateKey) { _, _ in
            selectedTypeFilter = nil
            Task { await loadData() }
        }
        .sheet(isPresented: $showDatePickerSheet) {
            NavigationStack {
                VStack {
                    DatePicker("选择日期", selection: $selectedDate, displayedComponents: .date)
                        .datePickerStyle(.graphical)
                        .environment(\.locale, Locale(identifier: "zh_CN"))
                        .padding()
                    Spacer()
                }
                .navigationTitle("选择查看日期")
                .navigationBarTitleDisplayMode(.inline)
                .toolbar {
                    ToolbarItem(placement: .cancellationAction) {
                        Button("今天") {
                            selectedDate = Date()
                            showDatePickerSheet = false
                        }
                    }
                    ToolbarItem(placement: .confirmationAction) {
                        Button("确定") { showDatePickerSheet = false }
                    }
                }
            }
            .presentationDetents([.medium, .large])
        }
        .sheet(item: $inspectedItem) { item in
            ClassAdminSummaryDetailSheet(
                item: item,
                dateKey: selectedDateKey,
                onImageTap: { path in
                    inspectedItem = nil
                    previewImagePath = path
                },
                onEditStudent: matchedStudent(for: item).map { student in
                    {
                        inspectedItem = nil
                        editingStudent = student
                    }
                }
            )
            .presentationDetents([.medium, .large])
        }
        .sheet(item: $editingStudent) { student in
            ClassAdminStudentEditSheet(
                student: student,
                dateKey: selectedDateKey,
                leaveTypes: activeLeaveTypes,
                classPeriods: regularPeriods,
                timeSlots: timeSlots,
                onChanged: {
                    Task { await loadData() }
                }
            )
        }
        .sheet(item: Binding(
            get: { previewImagePath.map { ClassAdminImagePreview(path: $0) } },
            set: { previewImagePath = $0?.path }
        )) { payload in
            FullScreenImageSheet(pathOrURL: payload.path)
        }
        .task {
            await loadInitialConfigAndData()
        }
    }

    // MARK: - Date Navigator

    private var dateNavigatorBar: some View {
        HStack(spacing: 8) {
            Button {
                if let prev = gregorian.date(byAdding: .day, value: -1, to: selectedDate) {
                    selectedDate = prev
                }
            } label: {
                Image(systemName: "chevron.left")
                    .font(.subheadline.weight(.semibold))
                    .frame(width: 34, height: 34)
                    .background(SCTheme.cardBackground)
                    .clipShape(Circle())
            }
            .buttonStyle(.plain)

            Button {
                showDatePickerSheet = true
            } label: {
                HStack(spacing: 6) {
                    Image(systemName: "calendar")
                        .font(.caption)
                        .foregroundStyle(SCTheme.primary)
                    Text(SCDateFormatter.cnMonthDay.string(from: selectedDate))
                        .font(.subheadline.weight(.semibold))
                        .foregroundStyle(.primary)
                    Image(systemName: "chevron.down")
                        .font(.caption2)
                        .foregroundStyle(.secondary)
                }
                .frame(maxWidth: .infinity)
                .padding(.vertical, 8)
                .background(SCTheme.cardBackground)
                .clipShape(RoundedRectangle(cornerRadius: 11, style: .continuous))
            }
            .buttonStyle(.plain)

            Button {
                if let next = gregorian.date(byAdding: .day, value: 1, to: selectedDate) {
                    selectedDate = next
                }
            } label: {
                Image(systemName: "chevron.right")
                    .font(.subheadline.weight(.semibold))
                    .frame(width: 34, height: 34)
                    .background(SCTheme.cardBackground)
                    .clipShape(Circle())
            }
            .buttonStyle(.plain)

            if !isToday {
                Button {
                    selectedDate = Date()
                } label: {
                    Text("今")
                        .font(.caption.weight(.semibold))
                        .frame(width: 32, height: 32)
                        .background(SCTheme.primary.opacity(0.14))
                        .foregroundStyle(SCTheme.primary)
                        .clipShape(Circle())
                }
                .buttonStyle(.plain)
            }
        }
    }

    // MARK: - Summary Tab (考勤情况：1列 / 2列彩色卡片)

    @ViewBuilder
    private var summaryTabContent: some View {
        // 1. 整体概览统计卡
        SCCard {
            VStack(alignment: .leading, spacing: 10) {
                HStack(alignment: .center) {
                    VStack(alignment: .leading, spacing: 2) {
                        Text(isToday ? "今日班级考勤概况" : "\(selectedDateKey) 考勤概况")
                            .font(.subheadline.weight(.bold))
                        if allSummaryItems.isEmpty {
                            Text(isLoading ? "正在刷新数据..." : "当日暂无请假或异常标记，全班出勤正常")
                                .font(.caption)
                                .foregroundStyle(isLoading ? Color.secondary : Color.green)
                        } else {
                            Text("共 \(distinctSummaryStudentCount) 人有记录 · 合计 \(allSummaryItems.count) 条" + (pendingSummaryCount > 0 ? "（其中 \(pendingSummaryCount) 条待审批）" : ""))
                                .font(.caption)
                                .foregroundStyle(.secondary)
                        }
                    }
                    Spacer()
                    if !students.isEmpty {
                        Text("全班 \(students.count) 人")
                            .font(.caption.weight(.medium))
                            .foregroundStyle(.secondary)
                    }
                }

                if !typeFilterOptions.isEmpty || pendingSummaryCount > 0 {
                    ScrollView(.horizontal, showsIndicators: false) {
                        HStack(spacing: 6) {
                            if pendingSummaryCount > 0 {
                                let active = selectedTypeFilter == "__pending__"
                                Button {
                                    withAnimation(.snappy(duration: 0.18)) {
                                        selectedTypeFilter = active ? nil : "__pending__"
                                    }
                                } label: {
                                    Text("待审 \(pendingSummaryCount)")
                                        .font(.caption2.weight(.semibold))
                                        .padding(.horizontal, 9)
                                        .padding(.vertical, 5)
                                        .background(active ? Color.orange : Color.orange.opacity(0.13))
                                        .foregroundStyle(active ? .white : .orange)
                                        .clipShape(Capsule())
                                }
                                .buttonStyle(.plain)
                            }
                            ForEach(typeFilterOptions, id: \.name) { opt in
                                let tint = SCTheme.color(for: opt.colorKey)
                                let active = selectedTypeFilter == opt.name
                                Button {
                                    withAnimation(.snappy(duration: 0.18)) {
                                        selectedTypeFilter = active ? nil : opt.name
                                    }
                                } label: {
                                    Text("\(opt.name) \(opt.count)")
                                        .font(.caption2.weight(.semibold))
                                        .padding(.horizontal, 9)
                                        .padding(.vertical, 5)
                                        .background(active ? tint : tint.opacity(0.12))
                                        .foregroundStyle(active ? .white : tint)
                                        .clipShape(Capsule())
                                }
                                .buttonStyle(.plain)
                            }
                        }
                    }
                }
            }
        }

        // 2. 筛选下拉 + 1列/2列切换控制栏
        HStack(spacing: 8) {
            Menu {
                Button {
                    selectedTypeFilter = nil
                } label: {
                    if selectedTypeFilter == nil {
                        Label("全部 (\(allSummaryItems.count))", systemImage: "checkmark")
                    } else {
                        Text("全部 (\(allSummaryItems.count))")
                    }
                }
                if pendingSummaryCount > 0 {
                    Button {
                        selectedTypeFilter = "__pending__"
                    } label: {
                        if selectedTypeFilter == "__pending__" {
                            Label("仅看待审批 (\(pendingSummaryCount))", systemImage: "checkmark")
                        } else {
                            Text("仅看待审批 (\(pendingSummaryCount))")
                        }
                    }
                }
                if !typeFilterOptions.isEmpty {
                    Divider()
                    ForEach(typeFilterOptions, id: \.name) { opt in
                        Button {
                            selectedTypeFilter = opt.name
                        } label: {
                            if selectedTypeFilter == opt.name {
                                Label("\(opt.name) (\(opt.count))", systemImage: "checkmark")
                            } else {
                                Text("\(opt.name) (\(opt.count))")
                            }
                        }
                    }
                }
            } label: {
                let currentLabel: String = {
                    guard let f = selectedTypeFilter else { return "全部 (\(allSummaryItems.count))" }
                    if f == "__pending__" { return "待审批 (\(pendingSummaryCount))" }
                    let cnt = typeFilterOptions.first(where: { $0.name == f })?.count ?? filteredSummaryItems.count
                    return "\(f) (\(cnt))"
                }()
                HStack(spacing: 5) {
                    Text(currentLabel)
                        .font(.caption.weight(.semibold))
                        .foregroundStyle(.primary)
                    Image(systemName: "chevron.down")
                        .font(.caption2.weight(.semibold))
                        .foregroundStyle(.secondary)
                }
                .padding(.horizontal, 11)
                .padding(.vertical, 6)
                .background(SCTheme.cardBackground)
                .clipShape(Capsule())
            }

            Spacer()

            columnsSwitchControl(selection: $summaryColumns)
        }

        // 3. 记录卡片区（1列 / 2列均采用彩色浅底+细描边参考样式）
        if isLoading && allSummaryItems.isEmpty {
            ProgressView("加载本班当日考勤情况...")
                .frame(maxWidth: .infinity)
                .padding(.vertical, 40)
        } else if filteredSummaryItems.isEmpty {
            VStack(spacing: 8) {
                Image(systemName: "checkmark.circle")
                    .font(.system(size: 34))
                    .foregroundStyle(.green.opacity(0.75))
                Text(selectedTypeFilter == nil ? "当日暂无请假或考勤标记记录" : "当前筛选下暂无考勤记录")
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
            }
            .frame(maxWidth: .infinity)
            .padding(.vertical, 44)
            .background(SCTheme.cardBackground)
            .clipShape(RoundedRectangle(cornerRadius: 14, style: .continuous))
        } else if summaryColumns == 2 {
            LazyVGrid(
                columns: [GridItem(.flexible(), spacing: 10), GridItem(.flexible(), spacing: 10)],
                spacing: 10
            ) {
                ForEach(filteredSummaryItems) { item in
                    Button {
                        inspectedItem = item
                    } label: {
                        ClassAdminRecordCard(item: item, isCompactTwoColumn: true)
                    }
                    .buttonStyle(.plain)
                }
            }
        } else {
            LazyVStack(spacing: 10) {
                ForEach(filteredSummaryItems) { item in
                    Button {
                        inspectedItem = item
                    } label: {
                        ClassAdminRecordCard(item: item, isCompactTwoColumn: false)
                    }
                    .buttonStyle(.plain)
                }
            }
        }
    }

    // MARK: - Roster Tab (全班名单)

    @ViewBuilder
    private var rosterTabContent: some View {
        VStack(spacing: 10) {
            HStack(spacing: 8) {
                HStack(spacing: 6) {
                    Image(systemName: "magnifyingglass")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                    TextField("搜索姓名或学号", text: $rosterSearch)
                        .font(.subheadline)
                        .textInputAutocapitalization(.never)
                        .autocorrectionDisabled(true)
                    if !rosterSearch.isEmpty {
                        Button { rosterSearch = "" } label: {
                            Image(systemName: "xmark.circle.fill")
                                .font(.caption)
                                .foregroundStyle(.secondary)
                        }
                        .buttonStyle(.plain)
                    }
                }
                .padding(.horizontal, 10)
                .padding(.vertical, 8)
                .background(SCTheme.cardBackground)
                .clipShape(RoundedRectangle(cornerRadius: 10, style: .continuous))

                columnsSwitchControl(selection: $rosterColumns)
            }

            HStack(spacing: 6) {
                ForEach(RosterFilter.allCases) { f in
                    let cnt: Int = {
                        switch f {
                        case .all: return students.count
                        case .hasRecords: return rosterHasRecordCount
                        case .normal: return rosterNormalCount
                        }
                    }()
                    let selected = rosterFilter == f
                    Button {
                        withAnimation(.snappy(duration: 0.18)) {
                            rosterFilter = f
                        }
                    } label: {
                        Text("\(f.rawValue) \(cnt)")
                            .font(.caption.weight(selected ? .semibold : .regular))
                            .padding(.horizontal, 10)
                            .padding(.vertical, 6)
                            .background(selected ? SCTheme.primary : SCTheme.cardBackground)
                            .foregroundStyle(selected ? .white : .primary)
                            .clipShape(Capsule())
                    }
                    .buttonStyle(.plain)
                }
                Spacer()
            }

            if isLoading && students.isEmpty {
                ProgressView("加载全班名单...")
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 36)
            } else if filteredRoster.isEmpty {
                Text("暂无符合条件的同学")
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 36)
                    .background(SCTheme.cardBackground)
                    .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
            } else if rosterColumns == 2 {
                LazyVGrid(
                    columns: [GridItem(.flexible(), spacing: 10), GridItem(.flexible(), spacing: 10)],
                    spacing: 10
                ) {
                    ForEach(filteredRoster) { student in
                        let badges = DayAttendanceBadgeGroup.build(
                            from: (student.attendance ?? []).filter { $0.approvalStatus != "rejected" },
                            classPeriods: regularPeriods,
                            timeSlots: timeSlots
                        )
                        Button {
                            editingStudent = student
                        } label: {
                            VStack(alignment: .leading, spacing: 6) {
                                HStack(spacing: 4) {
                                    Text(shortStudentTitle(student))
                                        .font(.subheadline.weight(.semibold))
                                        .foregroundStyle(.primary)
                                        .lineLimit(1)
                                    Spacer(minLength: 2)
                                    Image(systemName: "chevron.right")
                                        .font(.caption2)
                                        .foregroundStyle(.tertiary)
                                }
                                if badges.isEmpty {
                                    Text("正常出勤")
                                        .font(.caption2.weight(.medium))
                                        .foregroundStyle(.green)
                                } else {
                                    VStack(alignment: .leading, spacing: 3) {
                                        ForEach(badges.prefix(2)) { g in
                                            StatusBadge(text: g.badgeText, color: SCTheme.color(for: g.colorKey))
                                        }
                                        if badges.count > 2 {
                                            Text("+\(badges.count - 2) 项")
                                                .font(.caption2)
                                                .foregroundStyle(.secondary)
                                        }
                                    }
                                }
                            }
                            .frame(maxWidth: .infinity, minHeight: 68, alignment: .topLeading)
                            .padding(10)
                            .background(SCTheme.cardBackground)
                            .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
                        }
                        .buttonStyle(.plain)
                    }
                }
            } else {
                LazyVStack(spacing: 8) {
                    ForEach(filteredRoster) { student in
                        let badges = DayAttendanceBadgeGroup.build(
                            from: (student.attendance ?? []).filter { $0.approvalStatus != "rejected" },
                            classPeriods: regularPeriods,
                            timeSlots: timeSlots
                        )
                        Button {
                            editingStudent = student
                        } label: {
                            HStack(alignment: .center, spacing: 10) {
                                VStack(alignment: .leading, spacing: 2) {
                                    Text(shortStudentTitle(student))
                                        .font(.subheadline.weight(.semibold))
                                        .foregroundStyle(.primary)
                                    if let no = student.studentNo, !no.isEmpty {
                                        Text(no)
                                            .font(.caption2)
                                            .foregroundStyle(.secondary)
                                    }
                                }
                                Spacer(minLength: 8)
                                if badges.isEmpty {
                                    Text("正常出勤")
                                        .font(.caption.weight(.medium))
                                        .foregroundStyle(.green)
                                } else {
                                    VStack(alignment: .trailing, spacing: 4) {
                                        ForEach(badges.prefix(2)) { g in
                                            StatusBadge(text: g.badgeText, color: SCTheme.color(for: g.colorKey))
                                        }
                                        if badges.count > 2 {
                                            Text("+\(badges.count - 2) 项")
                                                .font(.caption2)
                                                .foregroundStyle(.secondary)
                                        }
                                    }
                                }
                                Image(systemName: "chevron.right")
                                    .font(.caption2)
                                    .foregroundStyle(.tertiary)
                            }
                            .padding(12)
                            .background(SCTheme.cardBackground)
                            .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
                        }
                        .buttonStyle(.plain)
                    }
                }
            }
        }
    }

    private func columnsSwitchControl(selection: Binding<Int>) -> some View {
        HStack(spacing: 2) {
            ForEach([1, 2], id: \.self) { cols in
                let active = selection.wrappedValue == cols
                Button {
                    withAnimation(.snappy(duration: 0.18)) {
                        selection.wrappedValue = cols
                    }
                } label: {
                    Text("\(cols)列")
                        .font(.caption2.weight(active ? .semibold : .regular))
                        .padding(.horizontal, 9)
                        .padding(.vertical, 5)
                        .background(active ? SCTheme.primary : Color.clear)
                        .foregroundStyle(active ? .white : .secondary)
                        .clipShape(RoundedRectangle(cornerRadius: 7, style: .continuous))
                }
                .buttonStyle(.plain)
            }
        }
        .padding(2)
        .background(Color(.tertiarySystemFill))
        .clipShape(RoundedRectangle(cornerRadius: 9, style: .continuous))
    }

    // MARK: - Data Loading & Synthesis

    private func loadInitialConfigAndData() async {
        async let typesReq = AttendanceRepository.fetchLeaveTypes()
        async let periodsReq = AttendanceRepository.fetchClassPeriods()
        async let slotsReq = AttendanceRepository.fetchTimeSlots()
        leaveTypes = (try? await typesReq) ?? []
        classPeriods = (try? await periodsReq) ?? []
        timeSlots = (try? await slotsReq) ?? []
        await loadData()
    }

    private func loadData() async {
        isLoading = true
        errorMessage = nil
        defer { isLoading = false }

        let dk = selectedDateKey
        let mk = selectedMonthKey

        async let overviewTask: [AttendanceOverviewDepartment]? = try? await AttendanceRepository.fetchAttendanceOverview(
            date: dk,
            includeEvening: true
        )
        async let summaryTask: [String: [CalendarSummaryRecord]]? = try? await AttendanceRepository.fetchCalendarSummary(month: mk)

        let overviewRes = await overviewTask
        let summaryRes = await summaryTask

        if let overviewRes {
            students = overviewRes
                .flatMap { $0.classes ?? [] }
                .flatMap { $0.students ?? [] }
                .sorted { ($0.studentNo ?? "") < ($1.studentNo ?? "") }
        } else if summaryRes == nil {
            errorMessage = "加载本班考勤数据失败，请下拉刷新重试"
        }

        if let summaryRes {
            hasCalendarSummaryData = true
            calendarDayRecords = summaryRes[dk] ?? []
        } else {
            hasCalendarSummaryData = false
            calendarDayRecords = []
        }
    }

    private func shortStudentTitle(_ s: AttendanceOverviewStudent) -> String {
        if let no = s.studentNo, no.count >= 2 {
            return "\(no.suffix(2)) \(s.displayName)"
        }
        return s.displayName
    }

    private func matchedStudent(for item: ClassAdminSummaryItem) -> AttendanceOverviewStudent? {
        if let sid = item.studentId, let found = students.first(where: { $0.id == sid }) {
            return found
        }
        if let no = item.studentNo, !no.isEmpty, let found = students.first(where: { $0.studentNo == no }) {
            return found
        }
        return students.first(where: { $0.displayName == item.studentName })
    }

    private func matchedStudent(for rec: CalendarSummaryRecord) -> AttendanceOverviewStudent? {
        if let sid = rec.studentId, let found = students.first(where: { $0.id == sid }) {
            return found
        }
        if let no = rec.studentNo, !no.isEmpty, let found = students.first(where: { $0.studentNo == no }) {
            return found
        }
        return students.first(where: { $0.displayName == rec.studentName })
    }

    private func normalizedTypeName(for rec: CalendarSummaryRecord) -> String {
        let clean = rec.type.trimmingCharacters(in: .whitespacesAndNewlines)
        if !clean.isEmpty && clean != "未知" { return clean }
        switch rec.status {
        case "absent": return "旷课"
        case "late": return "迟到"
        case "early_leave": return "早退"
        case "excused", "leave": return "请假"
        default: return "考勤"
        }
    }

    private func buildItem(from rec: CalendarSummaryRecord) -> ClassAdminSummaryItem {
        let typeName = normalizedTypeName(for: rec)
        let compact = rec.compactDisplay
        let rawOpt = compact.option.trimmingCharacters(in: .whitespacesAndNewlines)
        let student = matchedStudent(for: rec)
        let matchingRawRecords = findUnderlyingRawRecords(for: rec, student: student, typeName: typeName)

        // 兜底补全事由/备注、去向、附件图与节次
        var note = compact.note?.trimmingCharacters(in: .whitespacesAndNewlines)
        if note?.isEmpty != false {
            for r in matchingRawRecords {
                for candidate in [r.reason, r.note, r.details?.value?.text] {
                    if let t = candidate?.trimmingCharacters(in: .whitespacesAndNewlines),
                       !t.isEmpty,
                       t != rawOpt {
                        note = t
                        break
                    }
                }
                if note?.isEmpty == false { break }
            }
        }

        let destination = matchingRawRecords.compactMap { $0.destination?.trimmingCharacters(in: .whitespacesAndNewlines) }
            .first(where: { !$0.isEmpty })
        let images = matchingRawRecords.flatMap { $0.images ?? [] }.filter { !$0.isEmpty }
        let sourceLabel: String? = {
            if rec.isSelfApplied?.value == true { return "自请" }
            if let s = matchingRawRecords.first?.sourceBadgeText { return s }
            return nil
        }()

        let periodText: String = {
            if !rawOpt.isEmpty { return rawOpt }
            let allIds = Array(Set(matchingRawRecords.flatMap { $0.extractedPeriodIds(classPeriods: regularPeriods) })).sorted()
            if !allIds.isEmpty {
                let detailed = PeriodFormatter.formatDetailedPeriodSummary(
                    periodIds: allIds,
                    periods: regularPeriods,
                    timeSlots: timeSlots,
                    preferredSlotName: matchingRawRecords.first?.details?.value?.timeSlotName
                )
                if !detailed.isEmpty {
                    return allIds.count > 1 && !detailed.contains("节)") ? "\(detailed) (\(allIds.count)节)" : detailed
                }
            }
            return "全天"
        }()

        return ClassAdminSummaryItem(
            id: "cal-\(rec.id)",
            studentId: rec.studentId ?? student?.id,
            studentShortLabel: rec.studentShortLabel,
            studentName: rec.studentName,
            studentNo: rec.studentNo ?? student?.studentNo,
            typeName: typeName,
            colorKey: rec.displayColorKey,
            approvalPrefix: rec.approvalPrefix,
            approvalStatus: rec.approvalStatus,
            sourceLabel: sourceLabel,
            periodText: periodText,
            note: note?.isEmpty == false ? note : nil,
            destination: destination,
            timeText: rec.time?.isEmpty == false ? rec.time : nil,
            images: images
        )
    }

    private func findUnderlyingRawRecords(
        for rec: CalendarSummaryRecord,
        student: AttendanceOverviewStudent?,
        typeName: String
    ) -> [AttendanceRecord] {
        guard let attendance = student?.attendance, !attendance.isEmpty else { return [] }
        let valid = attendance.filter { !$0.isNormalPresentRecord }
        let idSet = Set(rec.allRecordIds)
        let byIds = valid.filter { idSet.contains($0.id) }
        if !byIds.isEmpty { return byIds }
        let isEvening = rec.option?.contains("夜自习") == true
        return valid.filter { item in
            let itemEvening = (item.scene ?? "regular") == "evening_study"
            guard itemEvening == isEvening else { return false }
            let typeOk = item.titleText == typeName || item.status == rec.status
            let apOk = rec.approvalStatus == nil || item.approvalStatus == rec.approvalStatus
            return typeOk && apOk
        }
    }

    /// 当老后端暂未放行 calendar-summary 给班级考勤员时，使用 overview(include_evening=1) 本地聚合成完全同构的完整卡片数据
    private func buildFallbackItemsFromOverview() -> [ClassAdminSummaryItem] {
        var result: [ClassAdminSummaryItem] = []
        for st in students {
            let valid = (st.attendance ?? []).filter { !$0.isNormalPresentRecord && $0.approvalStatus != "rejected" }
            guard !valid.isEmpty else { continue }

            let groups = DayRecordGroup.build(
                from: valid,
                classPeriods: regularPeriods,
                timeSlots: timeSlots,
                groupByDate: false
            )
            for group in groups {
                let primary = group.primary
                let isSelf = (primary.isSelfApplied?.value == true) || primary.sourceType == "self_applied" || primary.sourceType == "leave_request"
                let apPrefix: String = {
                    guard isSelf else { return "" }
                    switch primary.approvalStatus {
                    case "pending": return "待审:"
                    case "approved": return "批准:"
                    case "rejected": return "驳回:"
                    default: return ""
                    }
                }()

                let periodText: String = {
                    if group.isEveningStudy {
                        let es = primary.eveningStudyStatus?.name ?? primary.requestedEveningStatus?.name ?? primary.eveningStudyLabel
                        if let dest = primary.destination, !dest.isEmpty {
                            return "夜自习 · \(dest)"
                        }
                        if let es, !es.isEmpty {
                            return "夜自习 · \(es)"
                        }
                        return "夜自习"
                    }
                    let base = group.periodDetailText.trimmingCharacters(in: .whitespacesAndNewlines)
                    if base.isEmpty { return "全天" }
                    if group.periodIds.count > 1 && !base.contains("节)") {
                        return "\(base) (\(group.periodIds.count)节)"
                    }
                    return base
                }()

                let images = group.records.flatMap { $0.images ?? [] }.filter { !$0.isEmpty }
                let dest = group.records.compactMap { $0.destination?.trimmingCharacters(in: .whitespacesAndNewlines) }.first(where: { !$0.isEmpty })

                result.append(ClassAdminSummaryItem(
                    id: "ov-\(st.id)-\(group.id)",
                    studentId: st.id,
                    studentShortLabel: shortStudentTitle(st),
                    studentName: st.displayName,
                    studentNo: st.studentNo,
                    typeName: primary.titleText,
                    colorKey: primary.badgeColorKey,
                    approvalPrefix: apPrefix,
                    approvalStatus: primary.approvalStatus,
                    sourceLabel: primary.sourceBadgeText,
                    periodText: periodText,
                    note: group.remark,
                    destination: dest,
                    timeText: primary.displayTimeText,
                    images: images
                ))
            }
        }
        return result.sorted { lhs, rhs in
            if (lhs.approvalStatus == "pending") != (rhs.approvalStatus == "pending") {
                return lhs.approvalStatus == "pending"
            }
            return (lhs.studentNo ?? lhs.studentShortLabel) < (rhs.studentNo ?? rhs.studentShortLabel)
        }
    }
}

// MARK: - Models & Cards

private struct ClassAdminSummaryItem: Identifiable, Hashable {
    let id: String
    let studentId: Int?
    let studentShortLabel: String
    let studentName: String
    let studentNo: String?
    let typeName: String
    let colorKey: String
    let approvalPrefix: String
    let approvalStatus: String?
    let sourceLabel: String?
    let periodText: String
    let note: String?
    let destination: String?
    let timeText: String?
    let images: [String]
}

private struct ClassAdminImagePreview: Identifiable {
    let id = UUID()
    let path: String
}

/// 1列 / 2列统一彩色浅底描边卡片（完全参考班主任端 2 列样式规范）
private struct ClassAdminRecordCard: View {
    let item: ClassAdminSummaryItem
    let isCompactTwoColumn: Bool

    private var accentColor: Color {
        SCTheme.color(for: item.colorKey)
    }

    var body: some View {
        VStack(alignment: .leading, spacing: isCompactTwoColumn ? 6 : 7) {
            // 1. 顶栏：彩色圆点 + 批准:/待审: + 彩色类型 + 右侧时间
            HStack(spacing: 4) {
                Circle()
                    .fill(accentColor)
                    .frame(width: isCompactTwoColumn ? 7 : 8, height: isCompactTwoColumn ? 7 : 8)
                if !item.approvalPrefix.isEmpty {
                    Text(item.approvalPrefix)
                        .font(isCompactTwoColumn ? .caption.weight(.bold) : .subheadline.weight(.bold))
                        .foregroundStyle(SCTheme.color(for: item.approvalStatus))
                }
                Text(item.typeName)
                    .font(isCompactTwoColumn ? .caption.weight(.bold) : .subheadline.weight(.bold))
                    .foregroundStyle(accentColor)
                    .lineLimit(1)
                if !isCompactTwoColumn, let src = item.sourceLabel, item.approvalPrefix.isEmpty {
                    Text(src)
                        .font(.caption2.weight(.medium))
                        .padding(.horizontal, 6)
                        .padding(.vertical, 2)
                        .background(accentColor.opacity(0.12))
                        .foregroundStyle(accentColor)
                        .clipShape(Capsule())
                }
                Spacer(minLength: 4)
                if let time = item.timeText, !time.isEmpty {
                    Text(time)
                        .font(.caption.monospacedDigit())
                        .foregroundStyle(.secondary)
                }
            }

            // 2. 完整合并课节/时段
            Text(item.periodText)
                .font(isCompactTwoColumn ? .footnote : .subheadline)
                .foregroundStyle(.primary)
                .lineLimit(isCompactTwoColumn ? 3 : nil)
                .fixedSize(horizontal: false, vertical: true)

            if isCompactTwoColumn {
                Spacer(minLength: 4)
            }

            // 3. 学生姓名（学号后两位 + 姓名）与 4. 事由/备注
            if isCompactTwoColumn {
                VStack(alignment: .leading, spacing: 3) {
                    Text(item.studentShortLabel)
                        .font(.subheadline.weight(.bold))
                        .foregroundStyle(.primary)
                        .lineLimit(1)
                    if let note = item.note, !note.isEmpty {
                        Text(note)
                            .font(.caption)
                            .foregroundStyle(.secondary)
                            .lineLimit(2)
                    }
                }
            } else {
                HStack(alignment: .firstTextBaseline, spacing: 8) {
                    Text(item.studentShortLabel)
                        .font(.subheadline.weight(.bold))
                        .foregroundStyle(.primary)
                    if let note = item.note, !note.isEmpty {
                        Text("· \(note)")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                            .fixedSize(horizontal: false, vertical: true)
                    }
                    Spacer(minLength: 4)
                    Image(systemName: "chevron.right")
                        .font(.caption2)
                        .foregroundStyle(.tertiary)
                }
            }
        }
        .frame(maxWidth: .infinity, minHeight: isCompactTwoColumn ? 108 : nil, alignment: .topLeading)
        .padding(isCompactTwoColumn ? 11 : 13)
        .background(accentColor.opacity(0.08))
        .overlay(
            RoundedRectangle(cornerRadius: 12, style: .continuous)
                .stroke(accentColor.opacity(0.28), lineWidth: 1)
        )
        .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
        .contentShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
    }
}

/// 点击考勤情况卡片后的完整详情弹窗
private struct ClassAdminSummaryDetailSheet: View {
    let item: ClassAdminSummaryItem
    let dateKey: String
    let onImageTap: (String) -> Void
    let onEditStudent: (() -> Void)?
    @Environment(\.dismiss) private var dismiss

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 14) {
                    ClassAdminRecordCard(item: item, isCompactTwoColumn: false)

                    SCCard {
                        VStack(alignment: .leading, spacing: 10) {
                            detailRow(title: "日期", value: dateKey)
                            detailRow(title: "学生", value: item.studentNo.map { "\(item.studentName)（\($0)）" } ?? item.studentName)
                            detailRow(title: "考勤类型", value: item.typeName)
                            if let ap = item.approvalStatus, !ap.isEmpty {
                                let label = ap == "approved" ? "已批准" : (ap == "pending" ? "待审批" : "已驳回")
                                detailRow(title: "审批状态", value: label)
                            }
                            if let src = item.sourceLabel {
                                detailRow(title: "记录来源", value: src)
                            }
                            detailRow(title: "课节 / 时段", value: item.periodText)
                            if let dest = item.destination, !dest.isEmpty {
                                detailRow(title: "去向", value: dest)
                            }
                            if let note = item.note, !note.isEmpty {
                                detailRow(title: "事由 / 备注", value: note)
                            }
                            if let time = item.timeText, !time.isEmpty {
                                detailRow(title: "记录时间", value: time)
                            }
                        }
                    }

                    if !item.images.isEmpty {
                        SCCard {
                            VStack(alignment: .leading, spacing: 8) {
                                Text("证明附件")
                                    .font(.caption.weight(.semibold))
                                    .foregroundStyle(.secondary)
                                ScrollView(.horizontal, showsIndicators: false) {
                                    HStack(spacing: 8) {
                                        ForEach(item.images, id: \.self) { path in
                                            Button {
                                                onImageTap(path)
                                            } label: {
                                                RemoteMediaImage(pathOrURL: path, height: 76)
                                                    .frame(width: 76, height: 76)
                                                    .clipShape(RoundedRectangle(cornerRadius: 8, style: .continuous))
                                            }
                                            .buttonStyle(.plain)
                                        }
                                    }
                                }
                            }
                        }
                    }

                    if let onEditStudent {
                        Button {
                            onEditStudent()
                        } label: {
                            HStack {
                                Spacer()
                                Label("维护该生当日考勤", systemImage: "square.and.pencil")
                                    .font(.subheadline.weight(.semibold))
                                Spacer()
                            }
                        }
                        .buttonStyle(SCActionButtonStyle(variant: .soft(SCTheme.primary), cornerRadius: 12, verticalPadding: 12))
                    }
                }
                .padding()
            }
            .background(SCTheme.background.ignoresSafeArea())
            .navigationTitle("考勤详情")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .confirmationAction) {
                    Button("关闭") { dismiss() }
                }
            }
        }
    }

    private func detailRow(title: String, value: String) -> some View {
        HStack(alignment: .top, spacing: 12) {
            Text(title)
                .font(.caption)
                .foregroundStyle(.secondary)
                .frame(width: 72, alignment: .leading)
            Text(value)
                .font(.subheadline)
                .foregroundStyle(.primary)
                .fixedSize(horizontal: false, vertical: true)
            Spacer(minLength: 0)
        }
    }
}

/// 班级考勤员单生按节次维护弹窗（安全增量补录/撤销，避免旧版全班覆盖）
private struct ClassAdminStudentEditSheet: View {
    let student: AttendanceOverviewStudent
    let dateKey: String
    let leaveTypes: [LeaveType]
    let classPeriods: [ClassPeriod]
    let timeSlots: [TimeSlot]
    let onChanged: () -> Void
    @Environment(\.dismiss) private var dismiss

    @State private var dayRecords: [AttendanceRecord]
    @State private var selectedLeaveTypeId: Int?
    @State private var selectedPeriods: Set<Int> = []
    @State private var note: String = ""
    @State private var isSaving = false
    @State private var errorMessage: String?

    init(
        student: AttendanceOverviewStudent,
        dateKey: String,
        leaveTypes: [LeaveType],
        classPeriods: [ClassPeriod],
        timeSlots: [TimeSlot],
        onChanged: @escaping () -> Void
    ) {
        self.student = student
        self.dateKey = dateKey
        self.leaveTypes = leaveTypes
        self.classPeriods = classPeriods
        self.timeSlots = timeSlots
        self.onChanged = onChanged
        _dayRecords = State(initialValue: (student.attendance ?? []).filter { !$0.isNormalPresentRecord && $0.approvalStatus != "rejected" })
        _selectedLeaveTypeId = State(initialValue: leaveTypes.first?.id)
    }

    private var groupedDayRecords: [DayRecordGroup] {
        DayRecordGroup.build(from: dayRecords, classPeriods: classPeriods, timeSlots: timeSlots, groupByDate: false)
    }

    private var selectedLeaveType: LeaveType? {
        leaveTypes.first(where: { $0.id == selectedLeaveTypeId })
    }

    var body: some View {
        NavigationStack {
            Form {
                if let errorMessage {
                    Section {
                        ErrorBannerView(message: errorMessage)
                    }
                }

                Section("\(dateKey) 当日已有记录（\(groupedDayRecords.count)）") {
                    if groupedDayRecords.isEmpty {
                        Label("当日暂无异常记录，当前为正常出勤", systemImage: "checkmark.circle.fill")
                            .font(.caption)
                            .foregroundStyle(.green)
                    } else {
                        ForEach(groupedDayRecords) { group in
                            let rec = group.primary
                            VStack(alignment: .leading, spacing: 6) {
                                HStack(spacing: 6) {
                                    StatusBadge(text: rec.titleText, color: SCTheme.color(for: rec.badgeColorKey))
                                    if let ap = rec.approvalStatus, !ap.isEmpty {
                                        let label = ap == "pending" ? "待审" : (ap == "approved" ? "已批" : "驳回")
                                        StatusBadge(text: label, color: SCTheme.color(for: ap))
                                    }
                                    Spacer()
                                    Button(role: .destructive) {
                                        Task { await deleteGroup(group) }
                                    } label: {
                                        Label("撤销", systemImage: "trash")
                                            .font(.caption)
                                    }
                                    .buttonStyle(.borderless)
                                }
                                Text("课节：\(group.periodDetailText.isEmpty ? "全天" : group.periodDetailText)")
                                    .font(.caption.weight(.medium))
                                if let rm = group.remark, !rm.isEmpty {
                                    Text("备注：\(rm)")
                                        .font(.caption)
                                        .foregroundStyle(.secondary)
                                }
                            }
                            .padding(.vertical, 2)
                        }
                    }
                }

                Section("补录考勤标记") {
                    if !leaveTypes.isEmpty {
                        Picker("考勤类型", selection: $selectedLeaveTypeId) {
                            ForEach(leaveTypes) { lt in
                                Text(lt.name).tag(Int?.some(lt.id))
                            }
                        }
                    }
                    if !classPeriods.isEmpty {
                        VStack(alignment: .leading, spacing: 8) {
                            HStack {
                                Text("选择节次（留空代表全天）")
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                                Spacer()
                                if !selectedPeriods.isEmpty {
                                    Button("清空(全天)") { selectedPeriods.removeAll() }
                                        .font(.caption)
                                        .buttonStyle(.borderless)
                                }
                            }
                            LazyVGrid(columns: [GridItem(.adaptive(minimum: 72), spacing: 6)], spacing: 6) {
                                ForEach(classPeriods) { p in
                                    let on = selectedPeriods.contains(p.id)
                                    Button {
                                        if on { selectedPeriods.remove(p.id) } else { selectedPeriods.insert(p.id) }
                                    } label: {
                                        Text(p.name)
                                            .font(.caption.weight(on ? .semibold : .regular))
                                            .frame(maxWidth: .infinity)
                                            .padding(.vertical, 6)
                                            .background(on ? SCTheme.primary : Color(.tertiarySystemFill))
                                            .foregroundStyle(on ? .white : .primary)
                                            .clipShape(RoundedRectangle(cornerRadius: 8, style: .continuous))
                                    }
                                    .buttonStyle(.plain)
                                }
                            }
                        }
                        .padding(.vertical, 4)
                    }

                    TextField("备注（可选）", text: $note)

                    Button {
                        Task { await saveRecord() }
                    } label: {
                        HStack {
                            Spacer()
                            if isSaving { ProgressView().tint(.white) }
                            Text(isSaving ? "保存中..." : "保存考勤标记")
                                .font(.headline)
                            Spacer()
                        }
                    }
                    .listRowBackground(SCTheme.primary)
                    .foregroundStyle(.white)
                    .disabled(isSaving || selectedLeaveType == nil)
                }
            }
            .navigationTitle(student.displayName)
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("关闭") { dismiss() }
                }
            }
        }
    }

    private func saveRecord() async {
        guard let lt = selectedLeaveType else { return }
        isSaving = true
        errorMessage = nil
        defer { isSaving = false }

        let sortedIds = selectedPeriods.sorted()
        var payload: [String: Any] = [
            "student_id": student.id,
            "status": lt.mappedAttendanceStatus,
            "leave_type_id": lt.id
        ]
        var details: [String: Any] = [:]
        if !sortedIds.isEmpty {
            let names = sortedIds.compactMap { pid in classPeriods.first(where: { $0.id == pid })?.name }
            details["period_ids"] = sortedIds
            details["period_names"] = names
            let merged = PeriodFormatter.mergePeriodNames(names, separator: "、")
            details["option_label"] = names.count > 1 ? "\(merged) (\(names.count)节)" : merged
            details["is_custom"] = true
        }
        let trimmed = note.trimmingCharacters(in: .whitespacesAndNewlines)
        if !trimmed.isEmpty {
            details["text"] = trimmed
        }
        if !details.isEmpty {
            payload["details"] = details
        }

        do {
            try await AttendanceRepository.submitBulkAttendance(date: dateKey, records: [payload])
            note = ""
            selectedPeriods.removeAll()
            onChanged()
            await refreshDay()
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    private func deleteGroup(_ group: DayRecordGroup) async {
        errorMessage = nil
        do {
            var deletedRollCallIds = Set<Int>()
            var deletedSelfBatches = Set<String>()
            for rec in group.records {
                if rec.sourceType == "roll_call", let sid = rec.sourceId {
                    if !deletedRollCallIds.insert(sid).inserted { continue }
                }
                let isSelf = (rec.isSelfApplied?.value == true) || rec.sourceType == "self_applied"
                if isSelf, let batch = rec.leaveBatchId, !batch.isEmpty {
                    let key = "\(batch)_\(rec.scene ?? "regular")"
                    if !deletedSelfBatches.insert(key).inserted { continue }
                }
                try await AttendanceRepository.deleteAttendanceRecord(
                    studentId: student.id,
                    date: rec.dateKey.isEmpty ? dateKey : rec.dateKey,
                    recordId: rec.id,
                    periodId: rec.periodId,
                    leaveBatchId: rec.leaveBatchId,
                    sourceType: isSelf ? "self_applied" : rec.sourceType,
                    scene: rec.scene ?? "regular",
                    deleteScope: isSelf ? "scene_date" : "record"
                )
            }
            let removedIds = group.recordIdSet
            dayRecords.removeAll { removedIds.contains($0.id) }
            onChanged()
            await refreshDay()
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    private func refreshDay() async {
        if let overview = try? await AttendanceRepository.fetchAttendanceOverview(date: dateKey, includeEvening: true),
           let fresh = overview.flatMap({ $0.classes ?? [] }).flatMap({ $0.students ?? [] }).first(where: { $0.id == student.id }) {
            dayRecords = (fresh.attendance ?? []).filter { !$0.isNormalPresentRecord && $0.approvalStatus != "rejected" }
        }
    }
}
