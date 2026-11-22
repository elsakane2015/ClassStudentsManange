import SwiftUI
import UIKit

struct TeacherDashboardView: View {
    let user: User
    var onOpenApprovals: () -> Void = {}

    @State private var scope: String = "today"
    @State private var semesters: [Semester] = []
    @State private var selectedSemesterId: Int?
    @State private var stats: TeacherStatsResponse?
    @State private var leaveTypes: [LeaveType] = []
    @State private var timeSlots: [TimeSlot] = []
    @State private var classPeriods: [ClassPeriod] = []

    // Calendar & Selected Date Panel (Scheme A: default to compact weekly calendar)
    @State private var calendarExpanded: Bool = true
    @State private var calendarViewMode: CalendarViewMode = .week
    @State private var calendarAnchorDate: Date = Date()
    @State private var selectedDate: Date = Date()
    @State private var calendarSummary: [String: [CalendarSummaryRecord]] = [:]
    @State private var isLoadingCalendar: Bool = false
    @State private var dayPanelMode: DayPanelMode = .summary
    @State private var classSearchText: String = ""

    // Overview for Selected Date (used for both teacher's class roster & admin tree)
    @State private var overview: [AttendanceOverviewDepartment] = []
    @State private var expandedDepts: Set<String> = []
    @State private var expandedClasses: Set<Int> = []

    @State private var isLoading = false
    @State private var errorMessage: String?

    @State private var selectedStudent: AttendanceOverviewStudent?
    @State private var focusedRecordId: Int? = nil
    @State private var openSwipeRecordId: Int? = nil
    @State private var busySummaryRecordIds: Set<Int> = []
    @State private var quickToastMessage: String? = nil
    @State private var hapticCounter: Int = 0
    @State private var statDetailTarget: StatDetailTarget?
    @State private var showExportSheet = false
    @State private var showNotifications = false

    enum CalendarViewMode: String, CaseIterable {
        case month = "月视图"
        case week = "周视图"
    }

    enum DayPanelMode: String, CaseIterable {
        case summary = "异常汇总"
        case roster = "全班登记"
    }

    struct StatDetailTarget: Identifiable {
        let id = UUID()
        let title: String
        let status: String?
        let leaveTypeId: Int?
    }

    private let scopes: [(key: String, label: String)] = [
        ("today", "今日"),
        ("week", "本周"),
        ("month", "本月"),
        ("semester", "本学期")
    ]

    private var activeSemester: Semester? {
        semesters.first(where: \.isCurrentFlag) ?? semesters.first
    }

    private var canViewCalendarSummary: Bool {
        user.hasPermission("attendance.calendar_summary") || user.isTeacherOrAdmin
    }

    private var selectedDateKey: String {
        SCDateFormatter.yyyyMMdd.string(from: selectedDate)
    }

    private var selectedDaySummaryRecords: [CalendarSummaryRecord] {
        calendarSummary[selectedDateKey] ?? []
    }

    private var activeLeaveTypes: [LeaveType] {
        leaveTypes.filter(\.isActiveFlag)
    }

    private var regularPeriods: [ClassPeriod] {
        classPeriods.filter { ($0.scene ?? "regular") != "evening_study" }
    }

    private var flattenedRosterStudents: [AttendanceOverviewStudent] {
        let all = overview.flatMap { $0.classes ?? [] }.flatMap { $0.students ?? [] }
        let q = classSearchText.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        guard !q.isEmpty else { return all }
        return all.filter { s in
            s.displayName.lowercased().contains(q)
                || (s.studentNo ?? "").lowercased().contains(q)
        }
    }

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: 16) {
                    if let errorMessage {
                        ErrorBannerView(message: errorMessage) {
                            Task { await loadAll() }
                        }
                    }

                    // 1. Scope & Semester Selector
                    VStack(spacing: 10) {
                        Picker("统计范围", selection: $scope) {
                            ForEach(scopes, id: \.key) { item in
                                Text(item.label).tag(item.key)
                            }
                        }
                        .pickerStyle(.segmented)

                        if scope == "semester" && !semesters.isEmpty {
                            HStack {
                                Label("选择学期", systemImage: "calendar.badge.clock")
                                    .font(.subheadline)
                                    .foregroundStyle(.secondary)
                                Spacer()
                                Picker("学期", selection: $selectedSemesterId) {
                                    Text("当前学期").tag(Int?.none)
                                    ForEach(semesters) { sem in
                                        Text(sem.name).tag(Int?.some(sem.id))
                                    }
                                }
                            }
                            .padding(.horizontal, 12)
                            .padding(.vertical, 8)
                            .background(SCTheme.cardBackground)
                            .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
                        }
                    }

                    // 2. Key Metrics Cards (aligned with Web dashboard formatting)
                    if let stats {
                        LazyVGrid(columns: [GridItem(.flexible()), GridItem(.flexible())], spacing: 12) {
                            StatMetricCard(
                                title: "学生总数",
                                value: formattedStudentTotalValue(stats),
                                icon: "person.3.fill",
                                color: SCTheme.primary,
                                subtitle: formattedStudentTotalSubtitle(stats)
                            )

                            Button {
                                statDetailTarget = StatDetailTarget(title: "出勤明细", status: "present", leaveTypeId: nil)
                            } label: {
                                StatMetricCard(
                                    title: scope == "today" ? "今日出勤" : "综合出勤率",
                                    value: formattedAttendanceValue(stats),
                                    icon: "checkmark.circle.fill",
                                    color: .green,
                                    subtitle: scope == "today" ? "出勤人数/总人数 · 点看明细" : "出勤率 · 点看明细"
                                )
                            }
                            .buttonStyle(.plain)

                            Button {
                                onOpenApprovals()
                            } label: {
                                let pending = stats.pendingRequests?.value ?? 0
                                StatMetricCard(
                                    title: "待审批请假",
                                    value: "\(pending)",
                                    icon: "tray.full.fill",
                                    color: pending > 0 ? .orange : .gray,
                                    subtitle: pending > 0 ? "点击前往审批 →" : "暂无待办"
                                )
                            }
                            .buttonStyle(.plain)

                            let absentVal = stats.periodStats?.absentPeriods?.value ?? 0
                            let lateVal = stats.periodStats?.latePeriods?.value ?? 0
                            Button {
                                statDetailTarget = StatDetailTarget(title: "缺勤明细", status: "absent", leaveTypeId: nil)
                            } label: {
                                StatMetricCard(
                                    title: "缺勤节次",
                                    value: "\(absentVal)",
                                    icon: "exclamationmark.triangle.fill",
                                    color: absentVal > 0 ? .red : .secondary,
                                    subtitle: "迟到 \(lateVal) 节 · 点看明细"
                                )
                            }
                            .buttonStyle(.plain)
                        }

                        // Leave Breakdown Pills
                        if let leavesDict = stats.details?.leaves?.dictionary, !leavesDict.isEmpty {
                            SCCard {
                                VStack(alignment: .leading, spacing: 10) {
                                    Text("各类考勤异常分布")
                                        .font(.subheadline.weight(.semibold))
                                    LazyVGrid(columns: [GridItem(.adaptive(minimum: 130), spacing: 8)], spacing: 8) {
                                        ForEach(leavesDict.sorted(by: { $0.key < $1.key }), id: \.key) { name, summaryText in
                                            let matchedType = leaveTypes.first { $0.name == name }
                                            Button {
                                                statDetailTarget = StatDetailTarget(
                                                    title: "\(name)明细",
                                                    status: matchedType?.slug,
                                                    leaveTypeId: matchedType?.id
                                                )
                                            } label: {
                                                HStack {
                                                    Text(name)
                                                        .font(.caption.weight(.medium))
                                                        .foregroundStyle(.primary)
                                                    Spacer()
                                                    Text(summaryText.value)
                                                        .font(.caption.bold())
                                                        .foregroundStyle(SCTheme.color(for: matchedType?.color ?? "blue"))
                                                }
                                                .padding(.horizontal, 10)
                                                .padding(.vertical, 8)
                                                .background(SCTheme.color(for: matchedType?.color ?? "blue").opacity(0.1))
                                                .clipShape(RoundedRectangle(cornerRadius: 10, style: .continuous))
                                            }
                                            .buttonStyle(.plain)
                                        }
                                    }
                                }
                            }
                        }
                    } else if isLoading {
                        ProgressView("加载看板统计...")
                            .padding(.vertical, 24)
                    }

                    // 3. Scheme A: Monthly/Weekly Attendance Calendar (with school week & holidays)
                    TeacherAttendanceCalendarCard(
                        isExpanded: $calendarExpanded,
                        viewMode: $calendarViewMode,
                        anchorDate: $calendarAnchorDate,
                        selectedDate: $selectedDate,
                        semester: activeSemester,
                        calendarSummary: calendarSummary,
                        isLoading: isLoadingCalendar
                    )

                    // 4. Linked Selected-Date Attendance Panel (Abnormal Summary <-> Class Roster)
                    selectedDateAttendanceCard

                    // 5. Admin-only Hierarchical Department/Class Overview (matching Web behavior)
                    if user.isAdminTier {
                        adminOverviewTreeCard
                    }
                }
                .padding()
            }
            .background(SCTheme.background.ignoresSafeArea())
            .navigationTitle("考勤看板")
            .toolbar {
                ToolbarItemGroup(placement: .topBarTrailing) {
                    if user.hasPermission("attendance.export") || user.isAdminTier || user.role == "teacher" {
                        Button {
                            showExportSheet = true
                        } label: {
                            Image(systemName: "square.and.arrow.up")
                        }
                    }
                    Button {
                        showNotifications = true
                    } label: {
                        Image(systemName: PushNotificationManager.shared.unreadCount > 0 ? "bell.badge.fill" : "bell")
                    }
                }
            }
            .refreshable {
                await loadAll()
            }
            .onChange(of: scope) { _, _ in
                Task { await loadStats() }
            }
            .onChange(of: selectedSemesterId) { _, _ in
                Task { await loadStats() }
            }
            .onChange(of: calendarAnchorDate) { _, _ in
                Task { await loadCalendarSummary() }
            }
            .onChange(of: calendarViewMode) { _, _ in
                Task { await loadCalendarSummary() }
            }
            .onChange(of: selectedDate) { _, newDate in
                openSwipeRecordId = nil
                // Keep anchor synced if user taps a trailing/leading day in another month
                let cal = Calendar.current
                if calendarViewMode == .month && !cal.isDate(newDate, equalTo: calendarAnchorDate, toGranularity: .month) {
                    calendarAnchorDate = newDate
                }
                Task { await loadOverview() }
            }
            .onChange(of: dayPanelMode) { _, _ in
                openSwipeRecordId = nil
            }
            .sensoryFeedback(.impact(flexibility: .soft), trigger: hapticCounter)
            .overlay(alignment: .bottom) {
                if let toast = quickToastMessage {
                    HStack(spacing: 6) {
                        Image(systemName: "checkmark.circle.fill")
                            .foregroundStyle(.green)
                        Text(toast)
                            .font(.subheadline.weight(.medium))
                    }
                    .padding(.horizontal, 14)
                    .padding(.vertical, 10)
                    .background(.ultraThinMaterial)
                    .clipShape(Capsule())
                    .shadow(color: .black.opacity(0.12), radius: 8, y: 3)
                    .padding(.bottom, 16)
                    .transition(.move(edge: .bottom).combined(with: .opacity))
                }
            }
            .sheet(item: $selectedStudent, onDismiss: {
                focusedRecordId = nil
            }) { student in
                StudentAttendanceActionSheet(
                    student: student,
                    date: selectedDate,
                    leaveTypes: leaveTypes,
                    timeSlots: timeSlots,
                    classPeriods: classPeriods,
                    initialFocusRecordId: focusedRecordId
                ) {
                    Task {
                        await loadStats()
                        await loadCalendarSummary()
                        await loadOverview()
                    }
                }
            }
            .sheet(item: $statDetailTarget) { target in
                TeacherStatDetailSheet(
                    scope: scope,
                    semesterId: selectedSemesterId,
                    target: target
                )
            }
            .sheet(isPresented: $showExportSheet) {
                AttendanceExportSheet(semesters: semesters)
            }
            .sheet(isPresented: $showNotifications) {
                NavigationStack {
                    NotificationsView()
                }
            }
            .task {
                await loadAll()
            }
        }
    }

    // MARK: - Selected Date Linked Card

    private var selectedDateAttendanceCard: some View {
        SCCard {
            VStack(alignment: .leading, spacing: 12) {
                HStack(alignment: .center) {
                    VStack(alignment: .leading, spacing: 2) {
                        HStack(spacing: 6) {
                            Text(SCDateFormatter.cnMonthDay.string(from: selectedDate))
                                .font(.headline)
                            if isHoliday(selectedDate) {
                                StatusBadge(text: "休", color: .red)
                            }
                        }
                        Text(selectedDaySummaryRecords.isEmpty ? "当日暂无异常考勤" : "当日共 \(selectedDaySummaryRecords.count) 条异常考勤")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                    Spacer()
                    Button {
                        withAnimation(.snappy) {
                            dayPanelMode = (dayPanelMode == .summary) ? .roster : .summary
                        }
                    } label: {
                        Label(
                            dayPanelMode == .summary ? "登记/维护" : "看异常汇总",
                            systemImage: dayPanelMode == .summary ? "square.and.pencil" : "list.bullet.rectangle"
                        )
                        .font(.caption.weight(.semibold))
                    }
                    .buttonStyle(.borderedProminent)
                    .controlSize(.small)
                }

                Picker("当日视图", selection: $dayPanelMode) {
                    ForEach(DayPanelMode.allCases, id: \.self) { mode in
                        Text(mode.rawValue).tag(mode)
                    }
                }
                .pickerStyle(.segmented)

                if dayPanelMode == .summary {
                    if !canViewCalendarSummary {
                        Text("当前账号无日历异常摘要权限，可切换「全班登记」查看或维护。")
                            .font(.footnote)
                            .foregroundStyle(.secondary)
                            .padding(.vertical, 6)
                    } else if isLoadingCalendar && selectedDaySummaryRecords.isEmpty {
                        HStack {
                            Spacer()
                            ProgressView("加载当日记录...")
                            Spacer()
                        }
                        .padding(.vertical, 12)
                    } else if selectedDaySummaryRecords.isEmpty {
                        HStack(spacing: 8) {
                            Image(systemName: "checkmark.seal.fill")
                                .foregroundStyle(.green)
                            Text("该日期暂无请假、缺勤、迟到或早退记录")
                                .font(.subheadline)
                                .foregroundStyle(.secondary)
                        }
                        .padding(.vertical, 8)
                    } else {
                        VStack(spacing: 8) {
                            ForEach(selectedDaySummaryRecords) { rec in
                                SummarySwipeableRecordRow(
                                    record: rec,
                                    activeLeaveTypes: activeLeaveTypes,
                                    isBusy: busySummaryRecordIds.contains(rec.id),
                                    isOpen: openSwipeRecordId == rec.id,
                                    onSetOpen: { open in
                                        withAnimation(.snappy(duration: 0.22)) {
                                            if open {
                                                openSwipeRecordId = rec.id
                                            } else if openSwipeRecordId == rec.id {
                                                openSwipeRecordId = nil
                                            }
                                        }
                                    },
                                    onTap: {
                                        openSummaryDetail(for: rec)
                                    },
                                    onQuickChangeType: { targetType in
                                        Task { await quickChangeSummaryRecord(rec, to: targetType) }
                                    },
                                    onDelete: {
                                        Task { await quickDeleteSummaryRecord(rec) }
                                    }
                                )
                            }
                        }
                    }
                } else {
                    // Full class roster for selectedDate (quick attendance marking/editing)
                    VStack(spacing: 8) {
                        HStack(spacing: 6) {
                            Image(systemName: "magnifyingglass")
                                .foregroundStyle(.secondary)
                            TextField("搜索学生姓名或学号以补录考勤", text: $classSearchText)
                                .textInputAutocapitalization(.never)
                                .autocorrectionDisabled(true)
                        }
                        .padding(.horizontal, 10)
                        .padding(.vertical, 8)
                        .background(Color(.tertiarySystemFill))
                        .clipShape(RoundedRectangle(cornerRadius: 10, style: .continuous))

                        if flattenedRosterStudents.isEmpty {
                            Text("暂无匹配的本班学生数据")
                                .font(.footnote)
                                .foregroundStyle(.secondary)
                                .padding(.vertical, 8)
                        } else {
                            LazyVStack(spacing: 0) {
                                ForEach(flattenedRosterStudents) { student in
                                    let badgeGroups = DayAttendanceBadgeGroup.build(
                                        from: student.attendance ?? [],
                                        classPeriods: classPeriods,
                                        timeSlots: timeSlots
                                    )
                                    Button {
                                        focusedRecordId = nil
                                        selectedStudent = student
                                    } label: {
                                        HStack(alignment: .center, spacing: 8) {
                                            VStack(alignment: .leading, spacing: 2) {
                                                Text(student.displayName)
                                                    .font(.subheadline.weight(.medium))
                                                    .foregroundStyle(.primary)
                                                if let no = student.studentNo {
                                                    Text(no)
                                                        .font(.caption2)
                                                        .foregroundStyle(.secondary)
                                                }
                                            }
                                            Spacer(minLength: 8)
                                            if !badgeGroups.isEmpty {
                                                VStack(alignment: .trailing, spacing: 4) {
                                                    ForEach(badgeGroups.prefix(2)) { g in
                                                        StatusBadge(
                                                            text: g.badgeText,
                                                            color: SCTheme.color(for: g.colorKey)
                                                        )
                                                    }
                                                    if badgeGroups.count > 2 {
                                                        Text("+\(badgeGroups.count - 2) 项")
                                                            .font(.caption2)
                                                            .foregroundStyle(.secondary)
                                                    }
                                                }
                                            } else {
                                                Text("正常出勤")
                                                    .font(.caption)
                                                    .foregroundStyle(.green)
                                            }
                                            Image(systemName: "chevron.right")
                                                .font(.caption2)
                                                .foregroundStyle(.tertiary)
                                        }
                                        .padding(.vertical, 9)
                                    }
                                    .buttonStyle(.plain)
                                    Divider()
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    // MARK: - Admin Hierarchical Overview Card

    private var adminOverviewTreeCard: some View {
        SCCard {
            VStack(alignment: .leading, spacing: 12) {
                HStack {
                    Text("全校 / 院系出勤总览")
                        .font(.headline)
                    Spacer()
                    Text(selectedDateKey)
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }

                if overview.isEmpty && !isLoading {
                    Text("当日暂无可查看的班级数据")
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                        .padding(.vertical, 8)
                } else {
                    ForEach(overview) { dept in
                        DepartmentOverviewSection(
                            department: dept,
                            isExpanded: expandedDepts.contains(dept.id),
                            expandedClasses: $expandedClasses,
                            classPeriods: classPeriods,
                            timeSlots: timeSlots,
                            onToggleDept: {
                                if expandedDepts.contains(dept.id) {
                                    expandedDepts.remove(dept.id)
                                } else {
                                    expandedDepts.insert(dept.id)
                                }
                            },
                            onSelectStudent: { student in
                                focusedRecordId = nil
                                selectedStudent = student
                            }
                        )
                    }
                }
            }
        }
    }

    // MARK: - Formatting Helpers (Aligned with Web Dashboard)

    private func formattedStudentTotalValue(_ s: TeacherStatsResponse) -> String {
        let school = s.schoolTotalStudents?.value ?? s.totalStudents?.value ?? 0
        if let cls = s.classTotalStudents?.value {
            let dept = s.departmentTotalStudents?.value ?? cls
            return "\(cls)/\(dept)/\(school)"
        }
        if let dept = s.departmentTotalStudents?.value {
            return "\(dept)/\(school)"
        }
        return "\(school)"
    }

    private func formattedStudentTotalSubtitle(_ s: TeacherStatsResponse) -> String? {
        if s.classTotalStudents != nil { return "班级 / 系部 / 全校" }
        if s.departmentTotalStudents != nil { return "系部 / 全校" }
        return "全校在籍"
    }

    private func formattedAttendanceValue(_ s: TeacherStatsResponse) -> String {
        if scope == "today" {
            let present = Int(s.presentStudentsCount?.value.rounded() ?? Double(s.presentCount?.value ?? 0))
            let total = s.classTotalStudents?.value
                ?? s.departmentTotalStudents?.value
                ?? s.totalStudents?.value
                ?? 0
            return "\(present)/\(total)"
        }
        return String(format: "%.1f%%", s.attendanceRate?.value ?? 0)
    }

    private func isHoliday(_ date: Date) -> Bool {
        guard let holidays = activeSemester?.holidays else { return false }
        return holidays.contains(SCDateFormatter.yyyyMMdd.string(from: date))
    }

    // MARK: - Data Loading

    private func loadAll() async {
        isLoading = true
        errorMessage = nil
        defer { isLoading = false }

        async let typesReq = AttendanceRepository.fetchLeaveTypes()
        async let semReq = AttendanceRepository.fetchSemesters()
        async let slotsReq = AttendanceRepository.fetchTimeSlots()
        async let periodsReq = AttendanceRepository.fetchClassPeriods()

        leaveTypes = (try? await typesReq) ?? []
        semesters = (try? await semReq) ?? []
        timeSlots = (try? await slotsReq) ?? []
        classPeriods = (try? await periodsReq) ?? []

        await loadStats()
        await loadCalendarSummary()
        await loadOverview()
    }

    private func loadStats() async {
        do {
            stats = try await AttendanceRepository.fetchTeacherStats(scope: scope, semesterId: selectedSemesterId)
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    private func loadCalendarSummary() async {
        guard canViewCalendarSummary else { return }
        isLoadingCalendar = true
        defer { isLoadingCalendar = false }

        // Determine month(s) visible in current month/week view
        let monthsToFetch = visibleMonthStrings()
        var merged: [String: [CalendarSummaryRecord]] = [:]
        for m in monthsToFetch {
            if let dict = try? await AttendanceRepository.fetchCalendarSummary(month: m) {
                for (k, v) in dict {
                    let filtered = v.filter { !$0.isNormalPresentRecord }
                    if !filtered.isEmpty {
                        merged[k] = filtered
                    }
                }
            }
        }
        calendarSummary = merged
    }

    private func visibleMonthStrings() -> [String] {
        var cal = Calendar(identifier: .gregorian)
        cal.firstWeekday = 2 // Monday
        if calendarViewMode == .month {
            return [SCDateFormatter.yyyyMM.string(from: calendarAnchorDate)]
        }
        guard let interval = cal.dateInterval(of: .weekOfYear, for: calendarAnchorDate),
              let end = cal.date(byAdding: .day, value: -1, to: interval.end) else {
            return [SCDateFormatter.yyyyMM.string(from: calendarAnchorDate)]
        }
        let m1 = SCDateFormatter.yyyyMM.string(from: interval.start)
        let m2 = SCDateFormatter.yyyyMM.string(from: end)
        return m1 == m2 ? [m1] : [m1, m2]
    }

    private func loadOverview() async {
        let dateStr = SCDateFormatter.yyyyMMdd.string(from: selectedDate)
        do {
            let list = try await AttendanceRepository.fetchAttendanceOverview(date: dateStr)
            overview = list
            if expandedDepts.isEmpty, let firstDept = list.first {
                expandedDepts.insert(firstDept.id)
                if let firstCls = firstDept.classes?.first {
                    expandedClasses.insert(firstCls.id)
                }
            }
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    // MARK: - Summary Row Quick Actions (Tap Detail / Long-Press Quick Edit / Swipe Delete)

    private func matchedStudent(for rec: CalendarSummaryRecord) -> AttendanceOverviewStudent? {
        let all = overview.flatMap { $0.classes ?? [] }.flatMap { $0.students ?? [] }
        if let sid = rec.studentId, let exact = all.first(where: { $0.id == sid }) {
            return exact
        }
        if let no = rec.studentNo, !no.isEmpty, let byNo = all.first(where: { $0.studentNo == no }) {
            return byNo
        }
        return all.first(where: { $0.displayName == rec.studentName })
    }

    private func openSummaryDetail(for rec: CalendarSummaryRecord) {
        if let matched = matchedStudent(for: rec) {
            focusedRecordId = rec.id
            selectedStudent = matched
        }
    }

    private func resolveUnderlyingRecords(
        for rec: CalendarSummaryRecord,
        student: AttendanceOverviewStudent
    ) async -> [AttendanceRecord] {
        let idSet = Set(rec.allRecordIds)
        var candidates = (student.attendance ?? []).filter { !$0.isNormalPresentRecord }
        var matched = candidates.filter { idSet.contains($0.id) }

        if matched.isEmpty {
            if let fetched = try? await AttendanceRepository.fetchStudentRecords(
                studentId: student.id,
                scope: "custom",
                startDate: selectedDateKey,
                endDate: selectedDateKey
            ) {
                candidates = fetched.filter { !$0.isNormalPresentRecord }
                matched = candidates.filter { idSet.contains($0.id) }
            }
        }

        // 兼容老版后端未返回 record_ids 时，将同批次/同类型合并展示的独立节次记录一并匹配
        if rec.recordIds == nil, matched.count == 1, let primary = matched.first,
           primary.periodId != nil, (primary.scene ?? "regular") != "evening_study",
           let opt = rec.option, (opt.contains("、") || opt.contains("-") || opt.contains("节)")) {
            let groupMatches = candidates.filter { item in
                item.periodId != nil
                    && (item.scene ?? "regular") == (primary.scene ?? "regular")
                    && item.leaveTypeId == primary.leaveTypeId
                    && item.status == primary.status
                    && item.approvalStatus == primary.approvalStatus
                    && item.leaveBatchId == primary.leaveBatchId
            }
            if !groupMatches.isEmpty {
                matched = groupMatches
            }
        }

        return matched
    }

    private func quickChangeSummaryRecord(_ rec: CalendarSummaryRecord, to targetType: LeaveType) async {
        openSwipeRecordId = nil
        guard !busySummaryRecordIds.contains(rec.id) else { return }

        guard let student = matchedStudent(for: rec) else {
            errorMessage = "未找到学生「\(rec.studentName)」，请下拉刷新后重试"
            return
        }

        busySummaryRecordIds.insert(rec.id)
        errorMessage = nil
        defer { busySummaryRecordIds.remove(rec.id) }

        let oldRecords = await resolveUnderlyingRecords(for: rec, student: student)

        if oldRecords.contains(where: { ($0.scene ?? "regular") == "evening_study" }) {
            openSummaryDetail(for: rec)
            return
        }

        // 合并提取原记录覆盖的节次与备注
        var mergedPeriodIds = Set<Int>()
        var existingNote: String? = nil
        for item in oldRecords {
            for pid in item.extractedPeriodIds(classPeriods: regularPeriods) {
                mergedPeriodIds.insert(pid)
            }
            if existingNote == nil,
               let text = item.details?.value?.text?.trimmingCharacters(in: .whitespacesAndNewlines),
               !text.isEmpty {
                existingNote = text
            }
        }

        // 若原记录是已相同类型且状态一致，无需重复提交
        let targetStatus = targetType.mappedAttendanceStatus
        if !oldRecords.isEmpty,
           oldRecords.allSatisfy({ $0.leaveTypeId == targetType.id && $0.status == targetStatus }) {
            return
        }

        let sortedPeriodIds = mergedPeriodIds.sorted()
        var payloadRecord: [String: Any] = [
            "student_id": student.id,
            "status": targetStatus,
            "leave_type_id": targetType.id
        ]

        var details: [String: Any] = [:]
        if !sortedPeriodIds.isEmpty {
            let names = sortedPeriodIds.compactMap { pid in
                regularPeriods.first(where: { $0.id == pid })?.name
            }
            details["period_ids"] = sortedPeriodIds
            details["period_names"] = names
            details["option_label"] = names.joined(separator: "、")
            details["is_custom"] = true
        }
        if let existingNote, !existingNote.isEmpty {
            details["text"] = existingNote
        }
        if !details.isEmpty {
            payloadRecord["details"] = details
        }

        do {
            try await AttendanceRepository.submitBulkAttendance(
                date: selectedDateKey,
                records: [payloadRecord]
            )

            // 清理不会被后端同位覆盖或自动替换点名旷课的旧记录
            var deletedRollCallIds = Set<Int>()
            var deletedSelfBatches = Set<String>()
            for old in oldRecords {
                let oldPeriodIds = old.extractedPeriodIds(classPeriods: regularPeriods)
                let sameTarget = (old.leaveTypeId == targetType.id)
                    && (old.status == targetStatus)
                    && (oldPeriodIds == sortedPeriodIds)
                    && (old.periodId == nil)
                let autoReplacedRollCall = (old.sourceType == "roll_call")
                    && (targetStatus == "leave")
                    && (sortedPeriodIds.isEmpty || !Set(oldPeriodIds).isDisjoint(with: Set(sortedPeriodIds)))

                if sameTarget || autoReplacedRollCall {
                    continue
                }
                if old.sourceType == "roll_call", let sid = old.sourceId {
                    if !deletedRollCallIds.insert(sid).inserted { continue }
                }
                let isSelfApplied = (old.isSelfApplied?.value == true) || (old.sourceType == "self_applied")
                if isSelfApplied, let batch = old.leaveBatchId, !batch.isEmpty {
                    let key = "\(batch)_\(old.scene ?? "regular")"
                    if !deletedSelfBatches.insert(key).inserted { continue }
                }

                try? await AttendanceRepository.deleteAttendanceRecord(
                    studentId: student.id,
                    date: old.dateKey.isEmpty ? selectedDateKey : old.dateKey,
                    recordId: old.id,
                    periodId: old.periodId,
                    leaveBatchId: old.leaveBatchId,
                    sourceType: isSelfApplied ? "self_applied" : old.sourceType,
                    scene: old.scene ?? "regular",
                    deleteScope: isSelfApplied ? "scene_date" : "record"
                )
            }

            hapticCounter += 1
            showQuickToast("已将 \(rec.studentName) 改为 \(targetType.name)")
            await loadStats()
            await loadCalendarSummary()
            await loadOverview()
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    private func quickDeleteSummaryRecord(_ rec: CalendarSummaryRecord) async {
        withAnimation(.snappy(duration: 0.2)) {
            openSwipeRecordId = nil
        }
        guard !busySummaryRecordIds.contains(rec.id) else { return }

        let student = matchedStudent(for: rec)
        guard let targetStudentId = student?.id ?? rec.studentId else {
            errorMessage = "未找到学生「\(rec.studentName)」，请下拉刷新后重试"
            return
        }

        busySummaryRecordIds.insert(rec.id)
        errorMessage = nil
        defer { busySummaryRecordIds.remove(rec.id) }

        do {
            let oldRecords: [AttendanceRecord]
            if let student {
                oldRecords = await resolveUnderlyingRecords(for: rec, student: student)
            } else {
                oldRecords = []
            }

            if !oldRecords.isEmpty {
                var deletedRollCallIds = Set<Int>()
                var deletedSelfBatches = Set<String>()
                for item in oldRecords {
                    if item.sourceType == "roll_call", let sid = item.sourceId {
                        if !deletedRollCallIds.insert(sid).inserted { continue }
                    }
                    let isSelfApplied = (item.isSelfApplied?.value == true) || (item.sourceType == "self_applied")
                    if isSelfApplied, let batch = item.leaveBatchId, !batch.isEmpty {
                        let key = "\(batch)_\(item.scene ?? "regular")"
                        if !deletedSelfBatches.insert(key).inserted { continue }
                    }

                    try await AttendanceRepository.deleteAttendanceRecord(
                        studentId: targetStudentId,
                        date: item.dateKey.isEmpty ? selectedDateKey : item.dateKey,
                        recordId: item.id,
                        periodId: item.periodId,
                        leaveBatchId: item.leaveBatchId,
                        sourceType: isSelfApplied ? "self_applied" : item.sourceType,
                        scene: item.scene ?? "regular",
                        deleteScope: isSelfApplied ? "scene_date" : "record"
                    )
                }
            } else {
                let isSelfApplied = rec.isSelfApplied?.value == true
                try await AttendanceRepository.deleteAttendanceRecord(
                    studentId: targetStudentId,
                    date: selectedDateKey,
                    recordId: rec.id,
                    sourceType: isSelfApplied ? "self_applied" : nil,
                    scene: (rec.option?.contains("夜自习") == true) ? "evening_study" : "regular",
                    deleteScope: isSelfApplied ? "scene_date" : "record"
                )
            }

            withAnimation(.snappy(duration: 0.22)) {
                calendarSummary[selectedDateKey]?.removeAll { $0.id == rec.id }
            }
            hapticCounter += 1
            showQuickToast("已删除 \(rec.studentName) 的考勤记录")
            await loadStats()
            await loadCalendarSummary()
            await loadOverview()
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    private func showQuickToast(_ text: String) {
        withAnimation(.snappy(duration: 0.22)) {
            quickToastMessage = text
        }
        Task {
            try? await Task.sleep(for: .seconds(2.2))
            await MainActor.run {
                if quickToastMessage == text {
                    withAnimation(.easeOut(duration: 0.25)) {
                        quickToastMessage = nil
                    }
                }
            }
        }
    }
}

// MARK: - Swipeable & Context-Menu Summary Record Row

private struct SummarySwipeableRecordRow: View {
    let record: CalendarSummaryRecord
    let activeLeaveTypes: [LeaveType]
    let isBusy: Bool
    let isOpen: Bool
    let onSetOpen: (Bool) -> Void
    let onTap: () -> Void
    let onQuickChangeType: (LeaveType) -> Void
    let onDelete: () -> Void

    private let actionWidth: CGFloat = 78

    @State private var dragDelta: CGFloat = 0
    @State private var dragAxis: DragAxis? = nil

    private enum DragAxis {
        case horizontal
        case vertical
    }

    private var currentOffset: CGFloat {
        let base: CGFloat = isOpen ? -actionWidth : 0
        return min(0, max(-actionWidth - 18, base + dragDelta))
    }

    private var isEveningStudyRecord: Bool {
        record.option?.contains("夜自习") == true
    }

    var body: some View {
        ZStack(alignment: .trailing) {
            // 底层红色删除按钮（仅在左滑揭开时展示，静止时完全隐藏避免透红）
            if currentOffset < -1 || isOpen {
                Button(role: .destructive) {
                    onDelete()
                } label: {
                    VStack(spacing: 3) {
                        if isBusy {
                            ProgressView()
                                .tint(.white)
                                .controlSize(.small)
                        } else {
                            Image(systemName: "trash.fill")
                                .font(.subheadline)
                            Text("删除")
                                .font(.caption2.weight(.semibold))
                        }
                    }
                    .foregroundStyle(.white)
                    .frame(width: actionWidth)
                    .frame(maxHeight: .infinity)
                    .background(Color.red)
                }
                .buttonStyle(.plain)
                .disabled(isBusy)
                .allowsHitTesting(isOpen && !isBusy)
            }

            // 前景记录卡片（不透明底色 + 浅灰叠层，兼顾原有视觉与遮挡底层）
            rowContent
                .background(
                    ZStack {
                        SCTheme.cardBackground
                        Color(.tertiarySystemFill).opacity(0.65)
                    }
                )
                .clipShape(RoundedRectangle(cornerRadius: 10, style: .continuous))
                .offset(x: currentOffset)
                .contentShape(Rectangle())
                .onTapGesture {
                    if isOpen {
                        onSetOpen(false)
                    } else if !isBusy {
                        onTap()
                    }
                }
                .contextMenu {
                    if !isEveningStudyRecord && !activeLeaveTypes.isEmpty {
                        Section("快捷修改考勤类型") {
                            ForEach(activeLeaveTypes) { lt in
                                let isCurrent = (lt.name == record.type)
                                Button {
                                    if !isCurrent {
                                        onQuickChangeType(lt)
                                    }
                                } label: {
                                    if isCurrent {
                                        Label("\(lt.name)（当前）", systemImage: "checkmark")
                                    } else {
                                        Label("改为 \(lt.name)", systemImage: iconName(for: lt))
                                    }
                                }
                                .disabled(isCurrent || isBusy)
                            }
                        }
                    }

                    Divider()

                    Button {
                        onTap()
                    } label: {
                        Label("详细编辑（节次 / 备注）", systemImage: "slider.horizontal.3")
                    }

                    Button(role: .destructive) {
                        onDelete()
                    } label: {
                        Label("删除此记录", systemImage: "trash")
                    }
                    .disabled(isBusy)
                }
                .simultaneousGesture(
                    DragGesture(minimumDistance: 14, coordinateSpace: .global)
                        .onChanged { value in
                            guard !isBusy else { return }
                            let tx = value.translation.width
                            let ty = value.translation.height
                            if dragAxis == nil {
                                if abs(tx) > abs(ty) * 1.25 {
                                    dragAxis = .horizontal
                                } else if abs(ty) > abs(tx) {
                                    dragAxis = .vertical
                                    if isOpen {
                                        onSetOpen(false)
                                    }
                                }
                            }
                            guard dragAxis == .horizontal else { return }
                            // 未展开时只响应向左滑；已展开时响应左右微调
                            if !isOpen && tx > 0 {
                                dragDelta = 0
                            } else {
                                dragDelta = tx
                            }
                        }
                        .onEnded { value in
                            defer {
                                dragAxis = nil
                                withAnimation(.snappy(duration: 0.22)) {
                                    dragDelta = 0
                                }
                            }
                            guard dragAxis == .horizontal, !isBusy else { return }
                            let base: CGFloat = isOpen ? -actionWidth : 0
                            let projected = base + value.predictedEndTranslation.width
                            let ended = base + value.translation.width
                            let shouldOpen = ended < -(actionWidth * 0.45) || projected < -(actionWidth * 0.7)
                            onSetOpen(shouldOpen)
                        }
                )
        }
        .clipShape(RoundedRectangle(cornerRadius: 10, style: .continuous))
    }

    private var rowContent: some View {
        HStack(alignment: .center, spacing: 8) {
            Circle()
                .fill(SCTheme.color(for: record.displayColorKey))
                .frame(width: 8, height: 8)

            VStack(alignment: .leading, spacing: 2) {
                HStack(spacing: 4) {
                    if !record.approvalPrefix.isEmpty {
                        Text(record.approvalPrefix)
                            .font(.caption.weight(.bold))
                            .foregroundStyle(SCTheme.color(for: record.approvalStatus))
                    }
                    Text(record.typeWithOption)
                        .font(.subheadline.weight(.semibold))
                        .foregroundStyle(SCTheme.color(for: record.displayColorKey))
                        .lineLimit(2)
                }
                Text(record.studentShortLabel)
                    .font(.caption)
                    .foregroundStyle(.primary)
            }

            Spacer()

            if isBusy {
                ProgressView()
                    .controlSize(.small)
            } else {
                if let time = record.time, !time.isEmpty {
                    Text(time)
                        .font(.caption2.monospacedDigit())
                        .foregroundStyle(.secondary)
                }
                Image(systemName: "chevron.right")
                    .font(.caption2)
                    .foregroundStyle(.tertiary)
            }
        }
        .padding(10)
    }

    private func iconName(for leaveType: LeaveType) -> String {
        switch leaveType.slug {
        case "absent": return "xmark.circle"
        case "late": return "clock.badge.exclamationmark"
        case "early_leave": return "figure.walk.departure"
        default:
            if leaveType.name.contains("病") { return "cross.case" }
            if leaveType.name.contains("生理") { return "heart" }
            return "doc.text"
        }
    }
}

// MARK: - Teacher Attendance Calendar Component (Monthly/Weekly + School Week + Holidays)

private struct TeacherAttendanceCalendarCard: View {
    @Binding var isExpanded: Bool
    @Binding var viewMode: TeacherDashboardView.CalendarViewMode
    @Binding var anchorDate: Date
    @Binding var selectedDate: Date
    let semester: Semester?
    let calendarSummary: [String: [CalendarSummaryRecord]]
    let isLoading: Bool

    private let weekdays = ["一", "二", "三", "四", "五", "六", "日"]

    private var mondayCalendar: Calendar {
        var cal = Calendar(identifier: .gregorian)
        cal.firstWeekday = 2 // Monday first (matching Web)
        cal.minimumDaysInFirstWeek = 1
        return cal
    }

    var body: some View {
        SCCard {
            VStack(spacing: 12) {
                // Collapsible Header
                HStack {
                    Button {
                        withAnimation(.snappy) { isExpanded.toggle() }
                    } label: {
                        HStack(spacing: 6) {
                            Image(systemName: isExpanded ? "chevron.down" : "chevron.right")
                                .font(.caption.bold())
                                .foregroundStyle(SCTheme.primary)
                            Text("考勤日历")
                                .font(.headline)
                                .foregroundStyle(.primary)
                        }
                    }
                    .buttonStyle(.plain)

                    Spacer()

                    if isLoading {
                        ProgressView()
                            .controlSize(.small)
                            .padding(.trailing, 4)
                    }

                    Button("今") {
                        let today = Date()
                        anchorDate = today
                        selectedDate = today
                    }
                    .font(.caption.weight(.bold))
                    .buttonStyle(.bordered)
                    .controlSize(.mini)

                    Picker("视图", selection: $viewMode) {
                        Text("月").tag(TeacherDashboardView.CalendarViewMode.month)
                        Text("周").tag(TeacherDashboardView.CalendarViewMode.week)
                    }
                    .pickerStyle(.segmented)
                    .frame(width: 88)
                }

                if isExpanded {
                    // Navigation Row
                    HStack {
                        Button { shift(by: -1) } label: {
                            Image(systemName: "chevron.left.circle.fill")
                                .font(.title3)
                                .foregroundStyle(SCTheme.primary)
                        }
                        Spacer()
                        Text(headerTitle)
                            .font(.subheadline.weight(.bold))
                        Spacer()
                        Button { shift(by: 1) } label: {
                            Image(systemName: "chevron.right.circle.fill")
                                .font(.title3)
                                .foregroundStyle(SCTheme.primary)
                        }
                    }

                    // 8-column Weekday Header (Week # + Mon..Sun)
                    HStack(spacing: 2) {
                        Text("周")
                            .font(.caption2.weight(.semibold))
                            .foregroundStyle(.secondary)
                            .frame(width: 24)
                        ForEach(weekdays, id: \.self) { wd in
                            Text(wd)
                                .font(.caption2.weight(.semibold))
                                .foregroundStyle(.secondary)
                                .frame(maxWidth: .infinity)
                        }
                    }

                    // Calendar Rows
                    let weeks = buildWeekRows()
                    VStack(spacing: 6) {
                        ForEach(weeks.indices, id: \.self) { rowIdx in
                            let rowDays = weeks[rowIdx]
                            HStack(spacing: 2) {
                                // School Week Number
                                Text(schoolWeekLabel(for: rowDays.first))
                                    .font(.caption2.bold().monospacedDigit())
                                    .foregroundStyle(.secondary)
                                    .frame(width: 24)

                                ForEach(rowDays, id: \.self) { day in
                                    calendarDayCell(day)
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    @ViewBuilder
    private func calendarDayCell(_ day: Date) -> some View {
        let cal = mondayCalendar
        let dateKey = SCDateFormatter.yyyyMMdd.string(from: day)
        let records = calendarSummary[dateKey] ?? []
        let isSelected = cal.isDate(day, inSameDayAs: selectedDate)
        let isToday = cal.isDateInToday(day)
        let inCurrentMonth = viewMode == .week || cal.isDate(day, equalTo: anchorDate, toGranularity: .month)
        let holiday = isHoliday(dateKey)

        Button {
            selectedDate = day
        } label: {
            VStack(spacing: 2) {
                ZStack(alignment: .topTrailing) {
                    Text("\(cal.component(.day, from: day))")
                        .font(.footnote.weight(isSelected || isToday ? .bold : .medium))
                        .foregroundStyle(
                            isSelected ? .white : (
                                holiday ? .red : (
                                    inCurrentMonth ? (isToday ? SCTheme.primary : .primary) : .secondary.opacity(0.45)
                                )
                            )
                        )
                        .frame(width: 28, height: 28)
                        .background(
                            Circle().fill(
                                isSelected ? SCTheme.primary : (
                                    isToday ? SCTheme.primary.opacity(0.15) : Color.clear
                                )
                            )
                        )

                    if holiday && !isSelected {
                        Text("休")
                            .font(.system(size: 8, weight: .bold))
                            .foregroundStyle(.red)
                            .offset(x: 4, y: -2)
                    }
                }

                // Dots + Count Badge
                if !records.isEmpty {
                    HStack(spacing: 2) {
                        let distinctColors = Array(Set(records.map(\.displayColorKey))).sorted().prefix(2)
                        ForEach(distinctColors, id: \.self) { ck in
                            Circle()
                                .fill(SCTheme.color(for: ck))
                                .frame(width: 4, height: 4)
                        }
                        Text("\(records.count)")
                            .font(.system(size: 9, weight: .bold).monospacedDigit())
                            .foregroundStyle(isSelected ? SCTheme.primary : .orange)
                    }
                    .frame(height: 11)
                } else {
                    Color.clear.frame(height: 11)
                }
            }
            .frame(maxWidth: .infinity)
            .padding(.vertical, 3)
            .background(
                RoundedRectangle(cornerRadius: 8, style: .continuous)
                    .fill(holiday && !isSelected ? Color.red.opacity(0.08) : Color.clear)
            )
        }
        .buttonStyle(.plain)
    }

    private var headerTitle: String {
        let base = SCDateFormatter.cnYearMonth.string(from: anchorDate)
        if viewMode == .week {
            let wk = schoolWeekLabel(for: anchorDate)
            return wk == "-" ? base : "\(base) · 第\(wk)周"
        }
        return base
    }

    private func shift(by value: Int) {
        let cal = mondayCalendar
        let component: Calendar.Component = (viewMode == .month) ? .month : .weekOfYear
        if let next = cal.date(byAdding: component, value: value, to: anchorDate) {
            anchorDate = next
            if viewMode == .week {
                selectedDate = next
            }
        }
    }

    private func isHoliday(_ dateKey: String) -> Bool {
        semester?.holidays?.contains(dateKey) ?? false
    }

    private func schoolWeekLabel(for date: Date?) -> String {
        guard let date,
              let sem = semester,
              let start = SCDateFormatter.parseDate(sem.startDate) else {
            return "-"
        }
        let cal = mondayCalendar
        guard let semStartWeek = cal.dateInterval(of: .weekOfYear, for: start)?.start,
              let targetWeek = cal.dateInterval(of: .weekOfYear, for: date)?.start else {
            return "-"
        }
        let days = cal.dateComponents([.day], from: semStartWeek, to: targetWeek).day ?? -999
        let diffWeeks = days >= 0 ? (days / 7) : ((days - 6) / 7)
        let totalWeeks = sem.totalWeeks ?? 20
        if diffWeeks < 0 || diffWeeks >= totalWeeks {
            return "-"
        }
        return "\(diffWeeks + 1)"
    }

    private func buildWeekRows() -> [[Date]] {
        let cal = mondayCalendar
        let startRange: Date
        let endRange: Date

        if viewMode == .week {
            guard let weekInt = cal.dateInterval(of: .weekOfYear, for: anchorDate),
                  let lastDay = cal.date(byAdding: .day, value: 6, to: weekInt.start) else {
                return []
            }
            startRange = weekInt.start
            endRange = lastDay
        } else {
            guard let monthInt = cal.dateInterval(of: .month, for: anchorDate),
                  let monthLast = cal.date(byAdding: .day, value: -1, to: monthInt.end),
                  let firstWeekStart = cal.dateInterval(of: .weekOfYear, for: monthInt.start)?.start,
                  let lastWeekEndExclusive = cal.dateInterval(of: .weekOfYear, for: monthLast)?.end,
                  let lastWeekEnd = cal.date(byAdding: .day, value: -1, to: lastWeekEndExclusive) else {
                return []
            }
            startRange = firstWeekStart
            endRange = lastWeekEnd
        }

        var rows: [[Date]] = []
        var cursor = startRange
        while cursor <= endRange {
            var week: [Date] = []
            for _ in 0..<7 {
                week.append(cursor)
                guard let next = cal.date(byAdding: .day, value: 1, to: cursor) else { break }
                cursor = next
            }
            if week.count == 7 {
                rows.append(week)
            }
        }
        return rows
    }
}

private struct DepartmentOverviewSection: View {
    let department: AttendanceOverviewDepartment
    let isExpanded: Bool
    @Binding var expandedClasses: Set<Int>
    let classPeriods: [ClassPeriod]
    let timeSlots: [TimeSlot]
    let onToggleDept: () -> Void
    let onSelectStudent: (AttendanceOverviewStudent) -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            Button(action: onToggleDept) {
                HStack {
                    Image(systemName: isExpanded ? "chevron.down" : "chevron.right")
                        .font(.caption.bold())
                        .foregroundStyle(SCTheme.primary)
                    Text(department.name)
                        .font(.subheadline.weight(.bold))
                        .foregroundStyle(.primary)
                    Spacer()
                    Text("\((department.classes ?? []).count) 个班")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
                .padding(.vertical, 6)
            }
            .buttonStyle(.plain)

            if isExpanded {
                ForEach(department.classes ?? []) { cls in
                    let clsExpanded = expandedClasses.contains(cls.id)
                    let students = cls.students ?? []
                    let abnormalCount = students.filter {
                        !(($0.attendance ?? []).filter { !$0.isNormalPresentRecord }).isEmpty
                    }.count

                    VStack(alignment: .leading, spacing: 6) {
                        Button {
                            if clsExpanded {
                                expandedClasses.remove(cls.id)
                            } else {
                                expandedClasses.insert(cls.id)
                            }
                        } label: {
                            HStack {
                                Image(systemName: clsExpanded ? "folder.fill" : "folder")
                                    .foregroundStyle(.indigo)
                                Text(cls.name)
                                    .font(.subheadline.weight(.semibold))
                                    .foregroundStyle(.primary)
                                Spacer()
                                if abnormalCount > 0 {
                                    StatusBadge(text: "异常 \(abnormalCount)", color: .orange)
                                } else {
                                    StatusBadge(text: "全勤", color: .green)
                                }
                                Text("\(students.count)人")
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                            }
                            .padding(10)
                            .background(Color(.tertiarySystemFill).opacity(0.7))
                            .clipShape(RoundedRectangle(cornerRadius: 10, style: .continuous))
                        }
                        .buttonStyle(.plain)

                        if clsExpanded {
                            ForEach(students) { student in
                                let badgeGroups = DayAttendanceBadgeGroup.build(
                                    from: student.attendance ?? [],
                                    classPeriods: classPeriods,
                                    timeSlots: timeSlots
                                )
                                Button {
                                    onSelectStudent(student)
                                } label: {
                                    HStack(alignment: .center, spacing: 8) {
                                        VStack(alignment: .leading, spacing: 2) {
                                            Text(student.displayName)
                                                .font(.subheadline)
                                                .foregroundStyle(.primary)
                                            if let no = student.studentNo {
                                                Text(no)
                                                    .font(.caption2)
                                                    .foregroundStyle(.secondary)
                                            }
                                        }
                                        Spacer(minLength: 8)
                                        if !badgeGroups.isEmpty {
                                            VStack(alignment: .trailing, spacing: 4) {
                                                ForEach(badgeGroups.prefix(2)) { g in
                                                    StatusBadge(
                                                        text: g.badgeText,
                                                        color: SCTheme.color(for: g.colorKey)
                                                    )
                                                }
                                                if badgeGroups.count > 2 {
                                                    Text("+\(badgeGroups.count - 2) 项")
                                                        .font(.caption2)
                                                        .foregroundStyle(.secondary)
                                                }
                                            }
                                        } else {
                                            Text("正常出勤")
                                                .font(.caption)
                                                .foregroundStyle(.green)
                                        }
                                        Image(systemName: "chevron.right")
                                            .font(.caption2)
                                            .foregroundStyle(.tertiary)
                                    }
                                    .padding(.horizontal, 12)
                                    .padding(.vertical, 8)
                                }
                                .buttonStyle(.plain)
                                Divider().padding(.leading, 12)
                            }
                        }
                    }
                    .padding(.leading, 8)
                }
            }
        }
    }
}

/// 单个学生的当日记录维护（三段式：当日已有标记 → 一步选型补录/修改 → 近期历史异常）
struct StudentAttendanceActionSheet: View {
    let student: AttendanceOverviewStudent
    let date: Date
    let leaveTypes: [LeaveType]
    let timeSlots: [TimeSlot]
    let classPeriods: [ClassPeriod]
    var initialFocusRecordId: Int? = nil
    let onChanged: () -> Void

    @Environment(\.dismiss) private var dismiss

    // 1. 当日已有标记
    @State private var dayRecords: [AttendanceRecord] = []
    @State private var isLoadingDayRecords = false
    @State private var recordPendingDelete: AttendanceRecord?

    // 2. 补录 / 修改表单（类 Web 一步点选后台配置的考勤类型）
    @State private var editingRecord: AttendanceRecord?
    @State private var selectedLeaveTypeId: Int?
    @State private var fallbackStatus: String = "leave"
    @State private var selectedPeriodIds: Set<Int> = []
    @State private var noteText: String = ""
    @State private var isSaving = false
    @State private var errorMessage: String?

    // 3. 近期历史异常
    @State private var historyScope: String = "month"
    @State private var historyRecords: [AttendanceRecord] = []
    @State private var isLoadingHistory = false

    private var dateKey: String {
        SCDateFormatter.yyyyMMdd.string(from: date)
    }

    private var activeLeaveTypes: [LeaveType] {
        leaveTypes.filter(\.isActiveFlag)
    }

    private var regularPeriods: [ClassPeriod] {
        let filtered = classPeriods.filter { $0.isActiveFlag && !$0.isEveningStudy }
        return filtered.isEmpty ? classPeriods : filtered
    }

    private var validTimeSlots: [(slot: TimeSlot, periodIds: [Int])] {
        let regularIdSet = Set(regularPeriods.map(\.id))
        return timeSlots.compactMap { slot in
            guard slot.isActive?.value ?? true else { return nil }
            let ids = (slot.periodIds ?? []).filter { regularIdSet.contains($0) }
            guard !ids.isEmpty else { return nil }
            return (slot, Array(Set(ids)).sorted())
        }
    }

    /// 已被当日其他记录占用的节次集合（用于节次网格小橙点提示）
    private var occupiedPeriodIds: Set<Int> {
        var result = Set<Int>()
        for rec in dayRecords where rec.id != editingRecord?.id {
            result.formUnion(rec.extractedPeriodIds(classPeriods: regularPeriods))
        }
        return result
    }

    private var selectedLeaveType: LeaveType? {
        activeLeaveTypes.first { $0.id == selectedLeaveTypeId }
    }

    var body: some View {
        NavigationStack {
            Form {
                if let errorMessage {
                    Section {
                        ErrorBannerView(message: errorMessage)
                    }
                }

                // SECTION 1: 当日已有标记（置顶、可一键修改或撤销）
                Section {
                    if isLoadingDayRecords && dayRecords.isEmpty {
                        ProgressView("加载当日标记...")
                    } else if dayRecords.isEmpty {
                        HStack(spacing: 8) {
                            Image(systemName: "checkmark.seal.fill")
                                .foregroundStyle(.green)
                            Text("当日暂无异常，当前状态为正常出勤")
                                .font(.subheadline)
                                .foregroundStyle(.secondary)
                        }
                        .padding(.vertical, 4)
                    } else {
                        ForEach(dayRecords) { rec in
                            let isFocused = (initialFocusRecordId == rec.id) || (editingRecord?.id == rec.id)
                            let periodText = rec.concisePeriodLabel(classPeriods: regularPeriods, timeSlots: timeSlots)
                            let remark = (rec.details?.value?.text ?? rec.reason ?? rec.note)?
                                .trimmingCharacters(in: .whitespacesAndNewlines)

                            VStack(alignment: .leading, spacing: 8) {
                                HStack(spacing: 6) {
                                    StatusBadge(text: rec.titleText, color: SCTheme.color(for: rec.badgeColorKey))
                                    if let ap = rec.approvalStatus, !ap.isEmpty {
                                        StatusBadge(
                                            text: ap == "pending" ? "待审" : (ap == "approved" ? "已批" : "驳回"),
                                            color: SCTheme.color(for: ap)
                                        )
                                    }
                                    if let src = rec.sourceBadgeText {
                                        Text(src)
                                            .font(.caption2.weight(.medium))
                                            .foregroundStyle(.secondary)
                                            .padding(.horizontal, 6)
                                            .padding(.vertical, 2)
                                            .background(Color(.tertiarySystemFill))
                                            .clipShape(Capsule())
                                    }
                                    Spacer()
                                    if let t = rec.displayTimeText {
                                        Text(t)
                                            .font(.caption2.monospacedDigit())
                                            .foregroundStyle(.secondary)
                                    }
                                }

                                HStack(spacing: 6) {
                                    Image(systemName: "clock")
                                        .font(.caption2)
                                        .foregroundStyle(.secondary)
                                    Text(periodText)
                                        .font(.subheadline.weight(.medium))
                                        .foregroundStyle(.primary)
                                }

                                if let remark, !remark.isEmpty {
                                    Text("备注：\(remark)")
                                        .font(.caption)
                                        .foregroundStyle(.secondary)
                                }

                                HStack(spacing: 10) {
                                    Button {
                                        beginEditing(rec)
                                    } label: {
                                        Label(editingRecord?.id == rec.id ? "修改中" : "修改", systemImage: "pencil")
                                            .font(.caption.weight(.semibold))
                                            .frame(maxWidth: .infinity)
                                    }
                                    .buttonStyle(.bordered)
                                    .tint(SCTheme.primary)
                                    .controlSize(.small)

                                    Button(role: .destructive) {
                                        recordPendingDelete = rec
                                    } label: {
                                        Label("撤销", systemImage: "trash")
                                            .font(.caption.weight(.semibold))
                                            .frame(maxWidth: .infinity)
                                    }
                                    .buttonStyle(.bordered)
                                    .tint(.red)
                                    .controlSize(.small)
                                }
                            }
                            .padding(.vertical, 4)
                            .listRowBackground(
                                isFocused
                                    ? SCTheme.primary.opacity(0.08)
                                    : Color(.secondarySystemGroupedBackground)
                            )
                        }
                    }
                } header: {
                    HStack {
                        Text("\(dateKey) 当日已有标记（\(dayRecords.count)）")
                        Spacer()
                        if isLoadingDayRecords {
                            ProgressView().controlSize(.mini)
                        }
                    }
                }

                // SECTION 2: 补录 / 修改考勤（类似 Web 端一步选考勤类型）
                Section {
                    if let editing = editingRecord {
                        HStack(spacing: 8) {
                            Image(systemName: "pencil.circle.fill")
                                .foregroundStyle(SCTheme.primary)
                            Text("正在修改：\(editing.titleText) · \(editing.concisePeriodLabel(classPeriods: regularPeriods, timeSlots: timeSlots))")
                                .font(.caption.weight(.semibold))
                                .foregroundStyle(SCTheme.primary)
                            Spacer()
                            Button("取消修改") {
                                cancelEditing()
                            }
                            .font(.caption.weight(.semibold))
                            .buttonStyle(.borderless)
                        }
                        .padding(.vertical, 2)
                    }

                    // 考勤类型一步点选
                    VStack(alignment: .leading, spacing: 8) {
                        Text("考勤类型（一步点选）")
                            .font(.caption)
                            .foregroundStyle(.secondary)

                        if !activeLeaveTypes.isEmpty {
                            LazyVGrid(columns: [GridItem(.adaptive(minimum: 80), spacing: 8)], spacing: 8) {
                                ForEach(activeLeaveTypes) { lt in
                                    let selected = selectedLeaveTypeId == lt.id
                                    let tint = SCTheme.color(for: lt.resolvedColorKey)
                                    Button {
                                        selectedLeaveTypeId = lt.id
                                    } label: {
                                        Text(lt.name)
                                            .font(.caption.weight(.semibold))
                                            .lineLimit(1)
                                            .frame(maxWidth: .infinity)
                                            .padding(.vertical, 8)
                                            .padding(.horizontal, 6)
                                            .background(selected ? tint : tint.opacity(0.12))
                                            .foregroundStyle(selected ? .white : tint)
                                            .clipShape(RoundedRectangle(cornerRadius: 10, style: .continuous))
                                    }
                                    .buttonStyle(.plain)
                                }
                            }
                        } else {
                            Picker("考勤状态", selection: $fallbackStatus) {
                                Text("请假").tag("leave")
                                Text("旷课").tag("absent")
                                Text("迟到").tag("late")
                                Text("早退").tag("early_leave")
                            }
                            .pickerStyle(.segmented)
                        }
                    }
                    .padding(.vertical, 4)

                    // 快捷时段 + 具体节次选择
                    if !regularPeriods.isEmpty {
                        VStack(alignment: .leading, spacing: 10) {
                            HStack {
                                Text("选择节次（留空代表全天）")
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                                Spacer()
                                if !selectedPeriodIds.isEmpty {
                                    Button("清空(全天)") {
                                        selectedPeriodIds.removeAll()
                                    }
                                    .font(.caption2.weight(.semibold))
                                    .buttonStyle(.borderless)
                                }
                            }

                            if !validTimeSlots.isEmpty {
                                ScrollView(.horizontal, showsIndicators: false) {
                                    HStack(spacing: 6) {
                                        ForEach(validTimeSlots, id: \.slot.id) { item in
                                            let slotSet = Set(item.periodIds)
                                            let isExact = selectedPeriodIds == slotSet
                                            Button {
                                                selectedPeriodIds = isExact ? [] : slotSet
                                            } label: {
                                                Text(item.slot.name)
                                                    .font(.caption2.weight(.semibold))
                                                    .padding(.horizontal, 10)
                                                    .padding(.vertical, 5)
                                                    .background(isExact ? SCTheme.primary : SCTheme.primary.opacity(0.1))
                                                    .foregroundStyle(isExact ? .white : SCTheme.primary)
                                                    .clipShape(Capsule())
                                            }
                                            .buttonStyle(.plain)
                                        }
                                    }
                                }
                            }

                            LazyVGrid(columns: [GridItem(.adaptive(minimum: 72), spacing: 6)], spacing: 6) {
                                ForEach(regularPeriods) { p in
                                    let on = selectedPeriodIds.contains(p.id)
                                    let occupied = occupiedPeriodIds.contains(p.id)
                                    Button {
                                        if on { selectedPeriodIds.remove(p.id) } else { selectedPeriodIds.insert(p.id) }
                                    } label: {
                                        ZStack(alignment: .topTrailing) {
                                            Text(p.name)
                                                .font(.caption2.weight(.medium))
                                                .frame(maxWidth: .infinity)
                                                .padding(.vertical, 7)
                                                .background(on ? SCTheme.primary : Color(.tertiarySystemFill))
                                                .foregroundStyle(on ? .white : .primary)
                                                .clipShape(RoundedRectangle(cornerRadius: 8, style: .continuous))
                                            if occupied {
                                                Circle()
                                                    .fill(Color.orange)
                                                    .frame(width: 6, height: 6)
                                                    .padding(3)
                                            }
                                        }
                                    }
                                    .buttonStyle(.plain)
                                }
                            }

                            if !occupiedPeriodIds.isEmpty {
                                HStack(spacing: 4) {
                                    Circle().fill(Color.orange).frame(width: 6, height: 6)
                                    Text("橙点表示该节次当日已有其他标记")
                                        .font(.caption2)
                                        .foregroundStyle(.secondary)
                                }
                            }
                        }
                        .padding(.vertical, 4)
                    }

                    TextField("备注（可选）", text: $noteText)

                    Button {
                        Task { await saveManualRecord() }
                    } label: {
                        HStack {
                            Spacer()
                            if isSaving { ProgressView().tint(.white) }
                            Text(editingRecord == nil ? "保存考勤记录" : "保存修改")
                                .font(.headline)
                            Spacer()
                        }
                    }
                    .listRowBackground(SCTheme.primary)
                    .foregroundStyle(.white)
                    .disabled(isSaving || (!activeLeaveTypes.isEmpty && selectedLeaveTypeId == nil))
                } header: {
                    Text(editingRecord == nil ? "为 \(student.displayName) 补录考勤" : "修改 \(student.displayName) 的考勤标记")
                }

                // SECTION 3: 近期历史异常（追溯参考）
                Section {
                    Picker("历史范围", selection: $historyScope) {
                        Text("今日").tag("today")
                        Text("本周").tag("week")
                        Text("本月").tag("month")
                        Text("本学期").tag("semester")
                    }
                    .pickerStyle(.segmented)

                    if isLoadingHistory {
                        ProgressView("加载记录...")
                    } else if historyRecords.isEmpty {
                        Text("该范围内无异常考勤记录")
                            .font(.subheadline)
                            .foregroundStyle(.secondary)
                    } else {
                        ForEach(historyRecords) { rec in
                            HStack {
                                VStack(alignment: .leading, spacing: 4) {
                                    HStack {
                                        StatusBadge(text: rec.titleText, color: SCTheme.color(for: rec.badgeColorKey))
                                        Text(rec.dateKey)
                                            .font(.caption.monospacedDigit())
                                            .foregroundStyle(.secondary)
                                    }
                                    let detail = rec.resolvedDetailText
                                    if !detail.isEmpty {
                                        Text(detail)
                                            .font(.caption)
                                            .foregroundStyle(.primary)
                                    }
                                }
                                Spacer()
                            }
                            .swipeActions {
                                Button(role: .destructive) {
                                    Task { await deleteRecord(rec) }
                                } label: {
                                    Label("删除", systemImage: "trash")
                                }
                            }
                        }
                    }
                } header: {
                    Text("近期历史异常（左滑可删除）")
                }
            }
            .navigationTitle(student.displayName)
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .confirmationAction) {
                    Button("完成") { dismiss() }
                }
            }
            .alert("确认撤销该条标记？", isPresented: Binding(
                get: { recordPendingDelete != nil },
                set: { if !$0 { recordPendingDelete = nil } }
            )) {
                Button("取消", role: .cancel) { recordPendingDelete = nil }
                Button("确认撤销", role: .destructive) {
                    if let target = recordPendingDelete {
                        recordPendingDelete = nil
                        Task { await deleteRecord(target) }
                    }
                }
            } message: {
                if let target = recordPendingDelete {
                    Text("将删除 \(dateKey)「\(target.titleText) · \(target.concisePeriodLabel(classPeriods: regularPeriods, timeSlots: timeSlots))」考勤记录。")
                }
            }
            .onChange(of: historyScope) { _, _ in
                Task { await loadHistory() }
            }
            .task {
                dayRecords = (student.attendance ?? []).filter { !$0.isNormalPresentRecord }
                if selectedLeaveTypeId == nil {
                    selectedLeaveTypeId = activeLeaveTypes.first?.id
                }
                await refreshDayRecords()
                await loadHistory()
            }
        }
    }

    private func beginEditing(_ rec: AttendanceRecord) {
        editingRecord = rec
        if let ltId = rec.leaveTypeId, activeLeaveTypes.contains(where: { $0.id == ltId }) {
            selectedLeaveTypeId = ltId
        } else if let bySlug = activeLeaveTypes.first(where: { $0.slug == rec.status || $0.name == rec.titleText }) {
            selectedLeaveTypeId = bySlug.id
        } else {
            selectedLeaveTypeId = activeLeaveTypes.first?.id
            fallbackStatus = rec.status == "present" ? "leave" : rec.status
        }
        selectedPeriodIds = Set(rec.extractedPeriodIds(classPeriods: regularPeriods))
        noteText = (rec.details?.value?.text ?? rec.note ?? rec.reason ?? "")
            .trimmingCharacters(in: .whitespacesAndNewlines)
    }

    private func cancelEditing() {
        editingRecord = nil
        selectedPeriodIds.removeAll()
        noteText = ""
    }

    private func refreshDayRecords() async {
        isLoadingDayRecords = true
        defer { isLoadingDayRecords = false }
        if let overview = try? await AttendanceRepository.fetchAttendanceOverview(date: dateKey) {
            if let fresh = overview
                .flatMap({ $0.classes ?? [] })
                .flatMap({ $0.students ?? [] })
                .first(where: { $0.id == student.id }) {
                dayRecords = (fresh.attendance ?? []).filter { !$0.isNormalPresentRecord }
            }
        }
    }

    private func loadHistory() async {
        isLoadingHistory = true
        defer { isLoadingHistory = false }
        if let list = try? await AttendanceRepository.fetchStudentRecords(studentId: student.id, scope: historyScope) {
            historyRecords = list.filter { !$0.isNormalPresentRecord }
        }
    }

    private func saveManualRecord() async {
        isSaving = true
        errorMessage = nil
        defer { isSaving = false }

        let targetStatus: String
        let targetLeaveTypeId: Int?
        if let lt = selectedLeaveType {
            targetStatus = lt.mappedAttendanceStatus
            targetLeaveTypeId = lt.id
        } else {
            targetStatus = fallbackStatus
            targetLeaveTypeId = nil
        }

        let sortedPeriodIds = Array(selectedPeriodIds).sorted()
        var record: [String: Any] = [
            "student_id": student.id,
            "status": targetStatus
        ]
        if let ltId = targetLeaveTypeId {
            record["leave_type_id"] = ltId
        }
        var details: [String: Any] = [:]
        if !sortedPeriodIds.isEmpty {
            details["period_ids"] = sortedPeriodIds
            if let matchedSlot = validTimeSlots.first(where: { $0.periodIds == sortedPeriodIds }) {
                details["time_slot_id"] = matchedSlot.slot.id
                details["time_slot_name"] = matchedSlot.slot.name
                details["is_custom"] = false
            } else {
                details["is_custom"] = true
            }
        }
        let trimmedNote = noteText.trimmingCharacters(in: .whitespacesAndNewlines)
        if !trimmedNote.isEmpty {
            details["text"] = trimmedNote
        }
        if !details.isEmpty {
            record["details"] = details
        }

        let oldRecord = editingRecord
        do {
            try await AttendanceRepository.submitBulkAttendance(
                date: dateKey,
                records: [record]
            )

            // 修改模式：若原记录不会被后端同位更新或点名转请假自动替换，则安全清理旧记录
            if let old = oldRecord {
                let oldPeriodIds = old.extractedPeriodIds(classPeriods: regularPeriods)
                let sameTarget = (old.leaveTypeId == targetLeaveTypeId)
                    && (old.status == targetStatus)
                    && (oldPeriodIds == sortedPeriodIds)
                    && (old.periodId == nil)
                let autoReplacedRollCall = (old.sourceType == "roll_call")
                    && (targetStatus == "leave")
                    && (targetLeaveTypeId != nil)
                    && (sortedPeriodIds.isEmpty || !Set(oldPeriodIds).isDisjoint(with: Set(sortedPeriodIds)))

                if !sameTarget && !autoReplacedRollCall {
                    let isSelfApplied = (old.isSelfApplied?.value == true) || (old.sourceType == "self_applied")
                    try? await AttendanceRepository.deleteAttendanceRecord(
                        studentId: student.id,
                        date: old.dateKey.isEmpty ? dateKey : old.dateKey,
                        recordId: old.id,
                        periodId: old.periodId,
                        leaveBatchId: old.leaveBatchId,
                        sourceType: isSelfApplied ? "self_applied" : old.sourceType,
                        scene: old.scene ?? "regular",
                        deleteScope: isSelfApplied ? "scene_date" : "record"
                    )
                }
            }

            editingRecord = nil
            noteText = ""
            selectedPeriodIds.removeAll()
            onChanged()
            await refreshDayRecords()
            await loadHistory()
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    private func deleteRecord(_ rec: AttendanceRecord) async {
        errorMessage = nil
        do {
            let isSelfApplied = (rec.isSelfApplied?.value == true) || (rec.sourceType == "self_applied")
            try await AttendanceRepository.deleteAttendanceRecord(
                studentId: student.id,
                date: rec.dateKey.isEmpty ? dateKey : rec.dateKey,
                recordId: rec.id,
                periodId: rec.periodId,
                leaveBatchId: rec.leaveBatchId,
                sourceType: isSelfApplied ? "self_applied" : rec.sourceType,
                scene: rec.scene ?? "regular",
                deleteScope: isSelfApplied ? "scene_date" : "record"
            )
            if editingRecord?.id == rec.id {
                cancelEditing()
            }
            dayRecords.removeAll { $0.id == rec.id }
            historyRecords.removeAll { $0.id == rec.id }
            onChanged()
            await refreshDayRecords()
            await loadHistory()
        } catch {
            errorMessage = error.localizedDescription
        }
    }
}

private struct TeacherStatDetailSheet: View {
    let scope: String
    let semesterId: Int?
    let target: TeacherDashboardView.StatDetailTarget
    @Environment(\.dismiss) private var dismiss

    @State private var presentList: [PresentDetailStudent] = []
    @State private var nonPresentList: [NonPresentDetailStudent] = []
    @State private var isLoading = true
    @State private var errorMessage: String?

    var body: some View {
        NavigationStack {
            Group {
                if isLoading {
                    ProgressView("加载学生明细...")
                } else if let errorMessage {
                    ErrorBannerView(message: errorMessage).padding()
                } else if target.status == "present" {
                    List(presentList) { s in
                        HStack {
                            VStack(alignment: .leading, spacing: 2) {
                                Text(s.name).font(.subheadline.weight(.semibold))
                                Text("\(s.className ?? "") · \(s.studentNo ?? "")")
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                            }
                            Spacer()
                            if let rate = s.attendanceRate?.value {
                                StatusBadge(text: String(format: "%.1f%%", rate), color: rate >= 95 ? .green : .orange)
                            }
                        }
                    }
                } else if nonPresentList.isEmpty {
                    ContentUnavailableView("暂无异常记录", systemImage: "checkmark.circle")
                } else {
                    List(nonPresentList) { s in
                        VStack(alignment: .leading, spacing: 4) {
                            HStack {
                                Text(s.name).font(.subheadline.weight(.semibold))
                                Spacer()
                                Text(s.className ?? "")
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                            }
                            if let detail = s.detail, !detail.isEmpty {
                                Text(detail)
                                    .font(.caption)
                                    .foregroundStyle(.orange)
                            }
                        }
                    }
                }
            }
            .navigationTitle(target.title)
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .confirmationAction) {
                    Button("关闭") { dismiss() }
                }
            }
            .task {
                isLoading = true
                defer { isLoading = false }
                do {
                    if target.status == "present" {
                        let res = try await AttendanceRepository.fetchPresentDetails(scope: scope, semesterId: semesterId)
                        presentList = res.students
                    } else {
                        nonPresentList = try await AttendanceRepository.fetchNonPresentDetails(
                            scope: scope,
                            status: target.status,
                            leaveTypeId: target.leaveTypeId,
                            semesterId: semesterId
                        )
                    }
                } catch {
                    errorMessage = error.localizedDescription
                }
            }
        }
    }
}

/// 导出 Excel 考勤报表并拉起 iOS 系统分享面板（微信/邮件/隔空投送/存储到文件）
struct AttendanceExportSheet: View {
    let semesters: [Semester]
    @Environment(\.dismiss) private var dismiss

    @State private var scope = "month"
    @State private var semesterId: Int?
    @State private var exportFormat = "count"
    @State private var studentRange = "all"
    @State private var isExporting = false
    @State private var errorMessage: String?
    @State private var exportedFileURL: URL?

    var body: some View {
        NavigationStack {
            Form {
                if let errorMessage {
                    Section {
                        ErrorBannerView(message: errorMessage)
                    }
                }

                Section("导出选项") {
                    Picker("时间范围", selection: $scope) {
                        Text("今日").tag("today")
                        Text("本周").tag("week")
                        Text("本月").tag("month")
                        Text("本学期").tag("semester")
                    }
                    if scope == "semester" && !semesters.isEmpty {
                        Picker("选择学期", selection: $semesterId) {
                            Text("当前学期").tag(Int?.none)
                            ForEach(semesters) { sem in
                                Text(sem.name).tag(Int?.some(sem.id))
                            }
                        }
                    }
                    Picker("报表格式", selection: $exportFormat) {
                        Text("汇总统计表").tag("count")
                        Text("逐条明细表").tag("detail")
                    }
                    Picker("学生范围", selection: $studentRange) {
                        Text("全部学生").tag("all")
                        Text("仅含异常记录学生").tag("with_records")
                    }
                }

                Section {
                    Button {
                        Task { await performExport() }
                    } label: {
                        HStack {
                            Spacer()
                            if isExporting { ProgressView().tint(.white) }
                            Label(isExporting ? "正在生成 Excel..." : "生成并导出报表", systemImage: "doc.badge.arrow.up")
                                .font(.headline)
                            Spacer()
                        }
                    }
                    .listRowBackground(SCTheme.primary)
                    .foregroundStyle(.white)
                    .disabled(isExporting)
                }

                if let exportedFileURL {
                    Section("导出就绪") {
                        ShareLink(item: exportedFileURL) {
                            Label("分享或保存 Excel 文件", systemImage: "square.and.arrow.up")
                                .font(.headline)
                                .foregroundStyle(SCTheme.primary)
                        }
                    }
                }
            }
            .navigationTitle("导出考勤报表")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("关闭") { dismiss() }
                }
            }
        }
    }

    private func performExport() async {
        isExporting = true
        errorMessage = nil
        exportedFileURL = nil
        defer { isExporting = false }

        do {
            exportedFileURL = try await AttendanceRepository.downloadExportExcel(
                scope: scope,
                semesterId: semesterId,
                exportFormat: exportFormat,
                studentRange: studentRange
            )
        } catch {
            errorMessage = error.localizedDescription
        }
    }
}
