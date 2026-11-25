import SwiftUI

struct StudentDashboardView: View {
    let user: User

    @State private var scope: String = "month"
    @State private var stats: StudentStatsResponse?
    @State private var leaveTypes: [LeaveType] = []
    @State private var classPeriods: [ClassPeriod] = []
    @State private var timeSlots: [TimeSlot] = []
    @State private var displayedMonth: Date = Date()
    @State private var selectedDate: Date = Date()
    @State private var monthRecords: [AttendanceRecord] = []
    @State private var isLoading = false
    @State private var errorMessage: String?

    @State private var detailSheetTarget: DetailTarget?
    @State private var showNotifications = false
    @State private var todayClassAnomalyStudents: Int?
    @State private var todayClassPendingCount: Int = 0

    struct DetailTarget: Identifiable {
        let id = UUID()
        let title: String
        let statusKey: String
    }

    private let scopes: [(key: String, label: String)] = [
        ("today", "今日"),
        ("week", "本周"),
        ("month", "本月"),
        ("semester", "本学期")
    ]

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: 16) {
                    if let errorMessage {
                        ErrorBannerView(message: errorMessage) {
                            Task { await loadAll() }
                        }
                    }

                    // Class Admin Quick Banner
                    if user.isClassAdminStudent {
                        VStack(spacing: 10) {
                            NavigationLink {
                                ClassAttendanceAdminView(user: user)
                            } label: {
                                HStack(spacing: 12) {
                                    Image(systemName: "person.badge.shield.checkmark.fill")
                                        .font(.title2)
                                        .foregroundStyle(.white)
                                    VStack(alignment: .leading, spacing: 2) {
                                        Text("班级考勤员工作台")
                                            .font(.headline)
                                            .foregroundStyle(.white)
                                        Text(classWorkbenchSubtitle)
                                            .font(.caption)
                                            .foregroundStyle(.white.opacity(0.88))
                                    }
                                    Spacer()
                                    Image(systemName: "chevron.right")
                                        .foregroundStyle(.white.opacity(0.85))
                                }
                                .padding(16)
                                .background(
                                    LinearGradient(
                                        colors: [SCTheme.primary, SCTheme.primary.opacity(0.75)],
                                        startPoint: .leading,
                                        endPoint: .trailing
                                    )
                                )
                                .clipShape(RoundedRectangle(cornerRadius: 16, style: .continuous))
                            }

                            if user.isRollCallAdminStudent {
                                NavigationLink {
                                    LeaveApprovalsView(
                                        user: user,
                                        openCurrentWeekTrigger: 1,
                                        embedInNavigationStack: false
                                    )
                                } label: {
                                    HStack(spacing: 12) {
                                        Image(systemName: "checkmark.seal.fill")
                                            .font(.title3)
                                            .foregroundStyle(.indigo)
                                        VStack(alignment: .leading, spacing: 2) {
                                            Text("本班请假审批")
                                                .font(.subheadline.weight(.semibold))
                                                .foregroundStyle(.primary)
                                            Text("查看并审批本班同学的请假申请（含姓名与学号）")
                                                .font(.caption)
                                                .foregroundStyle(.secondary)
                                        }
                                        Spacer()
                                        Image(systemName: "chevron.right")
                                            .font(.caption)
                                            .foregroundStyle(.tertiary)
                                    }
                                    .padding(14)
                                    .background(SCTheme.cardBackground)
                                    .clipShape(RoundedRectangle(cornerRadius: 14, style: .continuous))
                                }
                            }
                        }
                    }

                    // Scope Picker
                    Picker("统计周期", selection: $scope) {
                        ForEach(scopes, id: \.key) { item in
                            Text(item.label).tag(item.key)
                        }
                    }
                    .pickerStyle(.segmented)

                    // Metrics Grid
                    if let stats {
                        LazyVGrid(columns: [GridItem(.flexible()), GridItem(.flexible())], spacing: 12) {
                            Button {
                                detailSheetTarget = DetailTarget(title: "出勤记录", statusKey: "present")
                            } label: {
                                StatMetricCard(
                                    title: stats.isRestDay && scope == "today" ? "今日休息日" : "出勤统计",
                                    value: "\(stats.present)",
                                    icon: "checkmark.circle.fill",
                                    color: .green,
                                    subtitle: "工作日 \(stats.workingDays) 天"
                                )
                            }
                            .buttonStyle(.plain)

                            ForEach(activeStatTypes) { lt in
                                let count = stats.leaveCounts[lt.slug] ?? 0
                                let unit = stats.leaveConfigs[lt.slug]?.displayUnit ?? lt.displayUnit ?? "次"
                                Button {
                                    detailSheetTarget = DetailTarget(title: "\(lt.name)明细", statusKey: lt.slug)
                                } label: {
                                    StatMetricCard(
                                        title: lt.name,
                                        value: "\(count)",
                                        icon: "doc.text.fill",
                                        color: SCTheme.color(for: lt.color),
                                        subtitle: "单位：\(unit)"
                                    )
                                }
                                .buttonStyle(.plain)
                            }
                        }
                    } else if isLoading {
                        ProgressView("加载考勤统计...")
                            .padding(.vertical, 24)
                    }

                    // Native Monthly Calendar Card
                    SCCard {
                        VStack(spacing: 14) {
                            HStack {
                                Button {
                                    shiftMonth(by: -1)
                                } label: {
                                    Image(systemName: "chevron.left.circle.fill")
                                        .font(.title3)
                                        .foregroundStyle(SCTheme.primary)
                                }
                                Spacer()
                                Text(SCDateFormatter.cnYearMonth.string(from: displayedMonth))
                                    .font(.headline)
                                Spacer()
                                Button {
                                    shiftMonth(by: 1)
                                } label: {
                                    Image(systemName: "chevron.right.circle.fill")
                                        .font(.title3)
                                        .foregroundStyle(SCTheme.primary)
                                }
                            }

                            StudentMonthCalendarGrid(
                                month: displayedMonth,
                                selectedDate: $selectedDate,
                                recordsByDate: recordsByDate
                            )
                        }
                    }

                    // Selected Date Records
                    SCCard {
                        VStack(alignment: .leading, spacing: 12) {
                            HStack {
                                Text(SCDateFormatter.cnMonthDay.string(from: selectedDate))
                                    .font(.headline)
                                Spacer()
                                let dayRecords = recordsForSelectedDate
                                StatusBadge(
                                    text: dayRecords.isEmpty ? "全勤 / 无异常" : "\(dayRecords.count) 条记录",
                                    color: dayRecords.isEmpty ? .green : .orange
                                )
                            }

                            let dayRecords = recordsForSelectedDate
                            if dayRecords.isEmpty {
                                HStack(spacing: 8) {
                                    Image(systemName: "checkmark.seal.fill")
                                        .foregroundStyle(.green)
                                    Text("当日暂无请假或考勤异常记录")
                                        .font(.subheadline)
                                        .foregroundStyle(.secondary)
                                }
                                .padding(.vertical, 8)
                            } else {
                                ForEach(dayRecords) { rec in
                                    VStack(alignment: .leading, spacing: 6) {
                                        HStack {
                                            StatusBadge(text: rec.titleText, color: SCTheme.color(for: rec.badgeColorKey))
                                            if let approval = rec.approvalStatus {
                                                StatusBadge(
                                                    text: approval == "pending" ? "待审批" : (approval == "approved" ? "已批准" : "已驳回"),
                                                    color: SCTheme.color(for: approval)
                                                )
                                            }
                                            Spacer()
                                            if let time = rec.recordTime, !time.isEmpty {
                                                Text(time)
                                                    .font(.caption)
                                                    .foregroundStyle(.secondary)
                                            }
                                        }

                                        let detail = formattedRecordDetail(rec)
                                        if !detail.isEmpty {
                                            Text(detail)
                                                .font(.subheadline)
                                                .foregroundStyle(.primary)
                                        }

                                        if let esLabel = rec.eveningStudyLabel, !esLabel.isEmpty {
                                            Text("夜自习：\(esLabel)")
                                                .font(.caption)
                                                .foregroundStyle(.indigo)
                                        }

                                        if let reason = rec.reason ?? rec.note, !reason.isEmpty {
                                            Text("说明：\(reason)")
                                                .font(.caption)
                                                .foregroundStyle(.secondary)
                                        }
                                    }
                                    .padding(12)
                                    .frame(maxWidth: .infinity, alignment: .leading)
                                    .background(Color(.tertiarySystemFill).opacity(0.6))
                                    .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
                                }
                            }
                        }
                    }
                }
                .padding()
            }
            .background(SCTheme.background.ignoresSafeArea())
            .navigationTitle("我的考勤")
            .toolbar {
                ToolbarItem(placement: .topBarTrailing) {
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
            .onChange(of: displayedMonth) { _, _ in
                Task { await loadMonthCalendar() }
            }
            .sheet(item: $detailSheetTarget) { target in
                StudentStatusDetailSheet(scope: scope, title: target.title, statusKey: target.statusKey)
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

    private var activeStatTypes: [LeaveType] {
        leaveTypes.filter { $0.isActiveFlag }
    }

    private var recordsByDate: [String: [AttendanceRecord]] {
        Dictionary(grouping: monthRecords, by: \.dateKey)
    }

    private var recordsForSelectedDate: [AttendanceRecord] {
        let key = SCDateFormatter.yyyyMMdd.string(from: selectedDate)
        return recordsByDate[key] ?? []
    }

    private func formattedRecordDetail(_ rec: AttendanceRecord) -> String {
        if let periodIds = rec.details?.value?.periodIds, !periodIds.isEmpty {
            let formatted = PeriodFormatter.formatPeriodNames(
                periodIds: periodIds,
                periods: classPeriods,
                timeSlots: timeSlots
            )
            if !formatted.isEmpty { return formatted }
        }
        return rec.resolvedDetailText
    }

    private func shiftMonth(by value: Int) {
        if let next = Calendar.current.date(byAdding: .month, value: value, to: displayedMonth) {
            displayedMonth = next
        }
    }

    private var classWorkbenchSubtitle: String {
        guard let count = todayClassAnomalyStudents else {
            return "查看今日全班请假/标记情况与考勤维护"
        }
        if count == 0 {
            return "今日全班出勤正常 · 点击查看完整考勤情况"
        }
        if todayClassPendingCount > 0 {
            return "今日 \(count) 人有请假/标记（\(todayClassPendingCount) 条待审批）· 点看完整明细"
        }
        return "今日 \(count) 人有请假/标记 · 点击查看完整明细"
    }

    private func loadAll() async {
        isLoading = true
        errorMessage = nil
        defer { isLoading = false }

        async let typesReq = AttendanceRepository.fetchLeaveTypes()
        async let periodsReq = AttendanceRepository.fetchClassPeriods()
        async let slotsReq = AttendanceRepository.fetchTimeSlots()

        do {
            leaveTypes = try await typesReq
            classPeriods = (try? await periodsReq) ?? []
            timeSlots = (try? await slotsReq) ?? []
            await loadStats()
            await loadMonthCalendar()
            await loadTodayClassOverviewSummary()
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    private func loadTodayClassOverviewSummary() async {
        guard user.isClassAdminStudent else { return }
        let todayKey = SCDateFormatter.todayString()
        guard let overview = try? await AttendanceRepository.fetchAttendanceOverview(date: todayKey, includeEvening: true) else {
            return
        }
        let roster = overview.flatMap { $0.classes ?? [] }.flatMap { $0.students ?? [] }
        var anomalyStudents = 0
        var pendingBatches = Set<String>()
        for st in roster {
            let valid = (st.attendance ?? []).filter { !$0.isNormalPresentRecord && $0.approvalStatus != "rejected" }
            if !valid.isEmpty {
                anomalyStudents += 1
                for rec in valid where rec.approvalStatus == "pending" {
                    let key = rec.leaveBatchId?.isEmpty == false ? "\(st.id)-\(rec.leaveBatchId!)" : "\(st.id)-\(rec.id)"
                    pendingBatches.insert(key)
                }
            }
        }
        todayClassAnomalyStudents = anomalyStudents
        todayClassPendingCount = pendingBatches.count
    }

    private func loadStats() async {
        do {
            stats = try await AttendanceRepository.fetchStudentStats(scope: scope)
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    private func loadMonthCalendar() async {
        let cal = Calendar.current
        guard let range = cal.range(of: .day, in: .month, for: displayedMonth),
              let firstDay = cal.date(from: cal.dateComponents([.year, .month], from: displayedMonth)),
              let lastDay = cal.date(byAdding: .day, value: range.count - 1, to: firstDay) else {
            return
        }
        let start = SCDateFormatter.yyyyMMdd.string(from: firstDay)
        let end = SCDateFormatter.yyyyMMdd.string(from: lastDay)
        if let res = try? await AttendanceRepository.fetchStudentCalendar(start: start, end: end) {
            monthRecords = res.attendance.filter { !$0.isNormalPresentRecord }
        }
    }
}

private struct StudentMonthCalendarGrid: View {
    let month: Date
    @Binding var selectedDate: Date
    let recordsByDate: [String: [AttendanceRecord]]

    private let weekdays = ["日", "一", "二", "三", "四", "五", "六"]
    private let columns = Array(repeating: GridItem(.flexible(), spacing: 4), count: 7)

    var body: some View {
        VStack(spacing: 8) {
            LazyVGrid(columns: columns, spacing: 4) {
                ForEach(weekdays, id: \.self) { day in
                    Text(day)
                        .font(.caption2.weight(.semibold))
                        .foregroundStyle(.secondary)
                        .frame(maxWidth: .infinity)
                }
            }

            LazyVGrid(columns: columns, spacing: 6) {
                ForEach(calendarCells.indices, id: \.self) { idx in
                    if let date = calendarCells[idx] {
                        let dateKey = SCDateFormatter.yyyyMMdd.string(from: date)
                        let dayRecords = recordsByDate[dateKey] ?? []
                        let isSelected = Calendar.current.isDate(date, inSameDayAs: selectedDate)
                        let isToday = Calendar.current.isDateInToday(date)

                        Button {
                            selectedDate = date
                        } label: {
                            VStack(spacing: 3) {
                                Text("\(Calendar.current.component(.day, from: date))")
                                    .font(.subheadline.weight(isSelected || isToday ? .bold : .regular))
                                    .foregroundStyle(isSelected ? .white : (isToday ? SCTheme.primary : .primary))
                                    .frame(width: 32, height: 32)
                                    .background(
                                        Circle()
                                            .fill(isSelected ? SCTheme.primary : (isToday ? SCTheme.primary.opacity(0.14) : Color.clear))
                                    )

                                HStack(spacing: 2) {
                                    ForEach(dayRecords.prefix(3)) { rec in
                                        Circle()
                                            .fill(SCTheme.color(for: rec.badgeColorKey))
                                            .frame(width: 5, height: 5)
                                    }
                                }
                                .frame(height: 6)
                            }
                        }
                        .buttonStyle(.plain)
                    } else {
                        Color.clear.frame(height: 40)
                    }
                }
            }
        }
    }

    private var calendarCells: [Date?] {
        let cal = Calendar.current
        guard let firstOfMonth = cal.date(from: cal.dateComponents([.year, .month], from: month)),
              let dayRange = cal.range(of: .day, in: .month, for: firstOfMonth) else {
            return []
        }

        let firstWeekday = cal.component(.weekday, from: firstOfMonth) // 1 = Sun
        var cells: [Date?] = Array(repeating: nil, count: max(0, firstWeekday - 1))
        for day in dayRange {
            if let date = cal.date(byAdding: .day, value: day - 1, to: firstOfMonth) {
                cells.append(date)
            }
        }
        return cells
    }
}

private struct StudentStatusDetailSheet: View {
    let scope: String
    let title: String
    let statusKey: String
    @Environment(\.dismiss) private var dismiss

    @State private var response: StudentDetailsResponse?
    @State private var isLoading = true
    @State private var errorMessage: String?

    var body: some View {
        NavigationStack {
            Group {
                if isLoading {
                    ProgressView("加载记录...")
                } else if let errorMessage {
                    ErrorBannerView(message: errorMessage).padding()
                } else if let response, response.records.isEmpty {
                    ContentUnavailableView(
                        response.message ?? "暂无记录",
                        systemImage: "tray",
                        description: Text("该统计周期内没有相关异常记录")
                    )
                } else if let response {
                    List(response.records) { item in
                        VStack(alignment: .leading, spacing: 6) {
                            HStack {
                                Text(item.typeName ?? title)
                                    .font(.subheadline.weight(.semibold))
                                Spacer()
                                Text(item.date ?? "")
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                            }
                            if let label = item.detailLabel, !label.isEmpty {
                                Text(label)
                                    .font(.footnote)
                                    .foregroundStyle(.primary)
                            }
                            if let note = item.note, !note.isEmpty {
                                Text("备注：\(note)")
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                            }
                        }
                        .padding(.vertical, 4)
                    }
                }
            }
            .navigationTitle(title)
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .confirmationAction) {
                    Button("完成") { dismiss() }
                }
            }
            .task {
                isLoading = true
                defer { isLoading = false }
                do {
                    response = try await AttendanceRepository.fetchStudentDetails(scope: scope, status: statusKey)
                } catch {
                    errorMessage = error.localizedDescription
                }
            }
        }
    }
}
