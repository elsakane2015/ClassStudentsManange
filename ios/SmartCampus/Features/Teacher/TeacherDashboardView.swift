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

    // Calendar & Selected Date Panel (Scheme A)
    @State private var calendarExpanded: Bool = true
    @State private var calendarViewMode: CalendarViewMode = .month
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
                // Keep anchor synced if user taps a trailing/leading day in another month
                let cal = Calendar.current
                if !cal.isDate(newDate, equalTo: calendarAnchorDate, toGranularity: .month) {
                    calendarAnchorDate = newDate
                }
                Task { await loadOverview() }
            }
            .sheet(item: $selectedStudent) { student in
                StudentAttendanceActionSheet(
                    student: student,
                    date: selectedDate,
                    leaveTypes: leaveTypes,
                    timeSlots: timeSlots,
                    classPeriods: classPeriods
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
                                HStack(alignment: .center, spacing: 8) {
                                    Circle()
                                        .fill(SCTheme.color(for: rec.status))
                                        .frame(width: 8, height: 8)

                                    VStack(alignment: .leading, spacing: 2) {
                                        HStack(spacing: 4) {
                                            if !rec.approvalPrefix.isEmpty {
                                                Text(rec.approvalPrefix)
                                                    .font(.caption.weight(.bold))
                                                    .foregroundStyle(SCTheme.color(for: rec.approvalStatus))
                                            }
                                            Text(rec.typeWithOption)
                                                .font(.subheadline.weight(.semibold))
                                                .foregroundStyle(SCTheme.color(for: rec.status))
                                                .lineLimit(2)
                                        }
                                        Text(rec.studentShortLabel)
                                            .font(.caption)
                                            .foregroundStyle(.primary)
                                    }

                                    Spacer()

                                    if let time = rec.time, !time.isEmpty {
                                        Text(time)
                                            .font(.caption2.monospacedDigit())
                                            .foregroundStyle(.secondary)
                                    }
                                }
                                .padding(10)
                                .background(Color(.tertiarySystemFill).opacity(0.65))
                                .clipShape(RoundedRectangle(cornerRadius: 10, style: .continuous))
                                .onTapGesture {
                                    // 快速定位该学生进行补录/修改
                                    if let matched = overview
                                        .flatMap({ $0.classes ?? [] })
                                        .flatMap({ $0.students ?? [] })
                                        .first(where: { $0.displayName == rec.studentName }) {
                                        selectedStudent = matched
                                    }
                                }
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
                                    Button {
                                        selectedStudent = student
                                    } label: {
                                        HStack {
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
                                            Spacer()
                                            if let recs = student.attendance, !recs.isEmpty {
                                                HStack(spacing: 4) {
                                                    ForEach(recs.prefix(2)) { r in
                                                        StatusBadge(text: r.titleText, color: SCTheme.color(for: r.badgeColorKey))
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
                            onToggleDept: {
                                if expandedDepts.contains(dept.id) {
                                    expandedDepts.remove(dept.id)
                                } else {
                                    expandedDepts.insert(dept.id)
                                }
                            },
                            onSelectStudent: { student in
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
                    merged[k] = v
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
                        let distinctStatuses = Array(Set(records.map(\.status))).sorted().prefix(2)
                        ForEach(distinctStatuses, id: \.self) { st in
                            Circle()
                                .fill(SCTheme.color(for: st))
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
                    let abnormalCount = students.filter { ($0.attendanceSummary?.hasRecords?.value ?? false) }.count

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
                                Button {
                                    onSelectStudent(student)
                                } label: {
                                    HStack {
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
                                        Spacer()
                                        if let recs = student.attendance, !recs.isEmpty {
                                            HStack(spacing: 4) {
                                                ForEach(recs.prefix(2)) { r in
                                                    StatusBadge(text: r.titleText, color: SCTheme.color(for: r.badgeColorKey))
                                                }
                                                if recs.count > 2 {
                                                    Text("+\(recs.count - 2)")
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

/// 单个学生的当日记录维护（手动补录/修改 + 查看该生历史 + 删除记录）
struct StudentAttendanceActionSheet: View {
    let student: AttendanceOverviewStudent
    let date: Date
    let leaveTypes: [LeaveType]
    let timeSlots: [TimeSlot]
    let classPeriods: [ClassPeriod]
    let onChanged: () -> Void

    @Environment(\.dismiss) private var dismiss
    @State private var historyScope: String = "month"
    @State private var historyRecords: [AttendanceRecord] = []
    @State private var isLoadingHistory = false

    // Manual Mark State
    @State private var selectedStatus: String = "leave"
    @State private var selectedLeaveTypeId: Int?
    @State private var selectedPeriodIds: Set<Int> = []
    @State private var noteText: String = ""
    @State private var isSaving = false
    @State private var errorMessage: String?

    var body: some View {
        NavigationStack {
            Form {
                if let errorMessage {
                    Section {
                        ErrorBannerView(message: errorMessage)
                    }
                }

                // Manual Record Entry
                Section("为 \(student.displayName) 补录/登记考勤（\(SCDateFormatter.yyyyMMdd.string(from: date))）") {
                    Picker("考勤状态", selection: $selectedStatus) {
                        Text("出勤").tag("present")
                        Text("请假").tag("leave")
                        Text("旷课").tag("absent")
                        Text("迟到").tag("late")
                        Text("早退").tag("early_leave")
                    }

                    if selectedStatus != "present" && !leaveTypes.isEmpty {
                        Picker("对应类型", selection: $selectedLeaveTypeId) {
                            Text("默认").tag(Int?.none)
                            ForEach(leaveTypes.filter(\.isActiveFlag)) { lt in
                                Text(lt.name).tag(Int?.some(lt.id))
                            }
                        }
                    }

                    if selectedStatus != "present" && !classPeriods.isEmpty {
                        VStack(alignment: .leading, spacing: 8) {
                            Text("选择节次（留空代表全天）")
                                .font(.caption)
                                .foregroundStyle(.secondary)
                            LazyVGrid(columns: [GridItem(.adaptive(minimum: 72), spacing: 6)], spacing: 6) {
                                ForEach(classPeriods) { p in
                                    let on = selectedPeriodIds.contains(p.id)
                                    Button {
                                        if on { selectedPeriodIds.remove(p.id) } else { selectedPeriodIds.insert(p.id) }
                                    } label: {
                                        Text(p.name)
                                            .font(.caption2.weight(.medium))
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

                    TextField("备注（可选）", text: $noteText)

                    Button {
                        Task { await saveManualRecord() }
                    } label: {
                        HStack {
                            Spacer()
                            if isSaving { ProgressView().tint(.white) }
                            Text("保存考勤记录").font(.headline)
                            Spacer()
                        }
                    }
                    .listRowBackground(SCTheme.primary)
                    .foregroundStyle(.white)
                    .disabled(isSaving)
                }

                // History Records
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
                                            .font(.caption)
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
                    Text("历史异常考勤（左滑可删除）")
                }
            }
            .navigationTitle(student.displayName)
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .confirmationAction) {
                    Button("完成") { dismiss() }
                }
            }
            .onChange(of: historyScope) { _, _ in
                Task { await loadHistory() }
            }
            .task {
                await loadHistory()
            }
        }
    }

    private func loadHistory() async {
        isLoadingHistory = true
        defer { isLoadingHistory = false }
        if let list = try? await AttendanceRepository.fetchStudentRecords(studentId: student.id, scope: historyScope) {
            historyRecords = list
        }
    }

    private func saveManualRecord() async {
        isSaving = true
        errorMessage = nil
        defer { isSaving = false }

        var record: [String: Any] = [
            "student_id": student.id,
            "status": selectedStatus
        ]
        if let ltId = selectedLeaveTypeId {
            record["leave_type_id"] = ltId
        }
        var details: [String: Any] = [:]
        if !selectedPeriodIds.isEmpty {
            details["period_ids"] = Array(selectedPeriodIds).sorted()
            details["is_custom"] = true
        }
        if !noteText.trimmingCharacters(in: .whitespaces).isEmpty {
            details["text"] = noteText.trimmingCharacters(in: .whitespaces)
        }
        if !details.isEmpty {
            record["details"] = details
        }

        do {
            try await AttendanceRepository.submitBulkAttendance(
                date: SCDateFormatter.yyyyMMdd.string(from: date),
                records: [record]
            )
            onChanged()
            await loadHistory()
            noteText = ""
            selectedPeriodIds.removeAll()
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    private func deleteRecord(_ rec: AttendanceRecord) async {
        do {
            try await AttendanceRepository.deleteAttendanceRecord(
                studentId: student.id,
                date: rec.dateKey,
                recordId: rec.id,
                periodId: rec.periodId,
                leaveBatchId: rec.leaveBatchId
            )
            historyRecords.removeAll { $0.id == rec.id }
            onChanged()
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
