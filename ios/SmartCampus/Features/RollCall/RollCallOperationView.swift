import SwiftUI

/// 原生走动点名面板：支持 1~4 列自由缩放、触感震动反馈、SwiftData 断网防丢草稿、一键全勤与完成后修改
struct RollCallOperationView: View {
    let rollCallId: Int

    @AppStorage("smartcampus.rollcall_columns") private var columnCount: Int = 2
    @State private var rollCall: RollCall?
    @State private var records: [RollCallRecord] = []
    @State private var searchText: String = ""
    @State private var statusFilter: String? = nil // nil = 全部, "present", "on_leave", "pending", "absent"
    @State private var isLoading = false
    @State private var isSubmitting = false
    @State private var errorMessage: String?
    @State private var hapticCounter = 0
    @State private var hasRestoredOfflineDraft = false

    @State private var confirmComplete = false
    @State private var editingCompletedRecord: RollCallRecord?
    @State private var leaveTypes: [LeaveType] = []

    private var isInProgress: Bool { rollCall?.status == "in_progress" }
    private var canModifyCompleted: Bool { rollCall?.canModifyRecords?.value == true }
    private var selectableLeaveTypes: [LeaveType] {
        leaveTypes.filter { $0.isActiveFlag && $0.mappedAttendanceStatus == "leave" }
    }

    private var filteredRecords: [RollCallRecord] {
        let q = searchText.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        return records.filter { rec in
            if let f = statusFilter, rec.status != f {
                return false
            }
            guard !q.isEmpty else { return true }
            let name = (rec.student?.name ?? "").lowercased()
            let no = (rec.student?.studentNo ?? "").lowercased()
            return name.contains(q) || no.contains(q)
        }
    }

    private var activeFilterLabel: String? {
        switch statusFilter {
        case "present": return "已到"
        case "on_leave": return "请假"
        case "pending": return "未点"
        case "absent": return "缺勤"
        default: return nil
        }
    }

    private func toggleStatusFilter(_ target: String?) {
        withAnimation(.snappy(duration: 0.2)) {
            if target == nil || statusFilter == target {
                statusFilter = nil
            } else {
                statusFilter = target
            }
        }
    }

    private var presentCount: Int { records.filter { $0.status == "present" }.count }
    private var onLeaveCount: Int { records.filter { $0.status == "on_leave" }.count }
    private var absentCount: Int { records.filter { $0.status == "absent" }.count }
    private var pendingCount: Int { records.filter { $0.status == "pending" }.count }

    var body: some View {
        VStack(spacing: 0) {
            // Summary Header Bar
            VStack(spacing: 10) {
                HStack(spacing: 8) {
                    SummaryChip(
                        title: "总人数",
                        value: records.count,
                        color: .primary,
                        isSelected: statusFilter == nil
                    ) {
                        toggleStatusFilter(nil)
                    }
                    SummaryChip(
                        title: "已到",
                        value: presentCount,
                        color: .green,
                        isSelected: statusFilter == "present"
                    ) {
                        toggleStatusFilter("present")
                    }
                    SummaryChip(
                        title: "请假",
                        value: onLeaveCount,
                        color: .blue,
                        isSelected: statusFilter == "on_leave"
                    ) {
                        toggleStatusFilter("on_leave")
                    }
                    if isInProgress {
                        SummaryChip(
                            title: "未点",
                            value: pendingCount,
                            color: .orange,
                            isSelected: statusFilter == "pending"
                        ) {
                            toggleStatusFilter("pending")
                        }
                    } else {
                        SummaryChip(
                            title: "缺勤",
                            value: absentCount,
                            color: .red,
                            isSelected: statusFilter == "absent"
                        ) {
                            toggleStatusFilter("absent")
                        }
                    }
                }

                if hasRestoredOfflineDraft && isInProgress {
                    HStack(spacing: 6) {
                        Image(systemName: "icloud.and.arrow.up")
                            .foregroundStyle(.indigo)
                        Text("已自动恢复本地离线暂存的点名进度")
                            .font(.caption)
                            .foregroundStyle(.indigo)
                        Spacer()
                    }
                    .padding(.horizontal, 10)
                    .padding(.vertical, 6)
                    .background(Color.indigo.opacity(0.1))
                    .clipShape(RoundedRectangle(cornerRadius: 8, style: .continuous))
                }

                // Controls: Search + Column Switcher + Mark All Present
                HStack(spacing: 8) {
                    HStack(spacing: 6) {
                        Image(systemName: "magnifyingglass")
                            .foregroundStyle(.secondary)
                        TextField("搜索姓名/学号", text: $searchText)
                            .textInputAutocapitalization(.never)
                            .autocorrectionDisabled(true)
                    }
                    .padding(.horizontal, 10)
                    .padding(.vertical, 8)
                    .background(Color(.tertiarySystemFill))
                    .clipShape(RoundedRectangle(cornerRadius: 10, style: .continuous))

                    Menu {
                        ForEach(1...4, id: \.self) { cols in
                            Button {
                                columnCount = cols
                            } label: {
                                Label("\(cols) 列视图", systemImage: columnCount == cols ? "checkmark" : "square.grid.2x2")
                            }
                        }
                    } label: {
                        Image(systemName: "square.grid.2x2")
                            .padding(9)
                            .background(Color(.tertiarySystemFill))
                            .clipShape(RoundedRectangle(cornerRadius: 10, style: .continuous))
                    }

                    if isInProgress {
                        Button("一键全勤") {
                            markAllPresent()
                        }
                        .font(.caption.weight(.semibold))
                        .buttonStyle(.borderedProminent)
                        .tint(.green)
                    }
                }
            }
            .padding()
            .background(SCTheme.cardBackground)

            if let errorMessage {
                ErrorBannerView(message: errorMessage) {
                    Task { await loadDetail() }
                }
                .padding()
            }

            // Student Grid
            if isLoading && records.isEmpty {
                Spacer()
                ProgressView("加载点名名单...")
                Spacer()
            } else if filteredRecords.isEmpty {
                Spacer()
                VStack(spacing: 10) {
                    Image(systemName: "person.crop.circle.badge.questionmark")
                        .font(.title)
                        .foregroundStyle(.secondary)
                    Text(activeFilterLabel.map { "当前筛选「\($0)」暂无学生" } ?? "暂无匹配的学生")
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                    if statusFilter != nil || !searchText.isEmpty {
                        Button("查看全部") {
                            statusFilter = nil
                            searchText = ""
                        }
                        .font(.caption.weight(.semibold))
                        .buttonStyle(.bordered)
                    }
                }
                .padding()
                Spacer()
            } else {
                ScrollView {
                    let gridCols = Array(repeating: GridItem(.flexible(), spacing: 10), count: max(1, min(4, columnCount)))
                    LazyVGrid(columns: gridCols, spacing: 10) {
                        ForEach(filteredRecords) { rec in
                            RollCallStudentTile(
                                record: rec,
                                compact: columnCount >= 3
                            ) {
                                handleTap(rec)
                            }
                        }
                    }
                    .padding()
                }
                .safeRefreshable {
                    await loadDetail()
                }
                .background(SCTheme.background)
            }

            // Bottom Submit Bar (In Progress)
            if isInProgress {
                VStack(spacing: 0) {
                    Divider()
                    HStack {
                        VStack(alignment: .leading, spacing: 2) {
                            Text("已到 \(presentCount) / \(records.count)")
                                .font(.subheadline.weight(.semibold))
                            Text(pendingCount > 0 ? "剩余 \(pendingCount) 位未点学生提交后记缺勤" : "全员状态已确认")
                                .font(.caption2)
                                .foregroundStyle(.secondary)
                        }
                        Spacer()
                        Button {
                            confirmComplete = true
                        } label: {
                            HStack(spacing: 6) {
                                if isSubmitting { ProgressView().tint(.white) }
                                Text("完成并提交")
                                    .font(.headline)
                            }
                            .padding(.horizontal, 20)
                            .padding(.vertical, 12)
                        }
                        .buttonStyle(.borderedProminent)
                        .disabled(isSubmitting)
                    }
                    .padding()
                    .background(SCTheme.cardBackground)
                }
            }
        }
        .navigationTitle(rollCall?.rollCallType?.name ?? "课堂点名")
        .navigationBarTitleDisplayMode(.inline)
        .sensoryFeedback(.impact(flexibility: .soft), trigger: hapticCounter)
        .alert("确认完成本次点名？", isPresented: $confirmComplete) {
            Button("取消", role: .cancel) {}
            Button("确认提交") {
                Task { await submitComplete() }
            }
        } message: {
            Text("已到 \(presentCount) 人，请假 \(onLeaveCount) 人，未点 \(pendingCount) 人（提交后自动记为缺勤）。")
        }
        .confirmationDialog(
            "修改 \(editingCompletedRecord?.student?.name ?? "") 的点名状态",
            isPresented: Binding(
                get: { editingCompletedRecord != nil },
                set: { if !$0 { editingCompletedRecord = nil } }
            ),
            titleVisibility: .visible,
            presenting: editingCompletedRecord
        ) { targetRecord in
            Button("标记为已到") {
                Task { await updateCompleted(targetRecord, to: "present") }
            }
            Button("标记为缺勤", role: .destructive) {
                Task { await updateCompleted(targetRecord, to: "absent") }
            }
            if selectableLeaveTypes.isEmpty {
                Button("标记为请假") {
                    Task { await updateCompleted(targetRecord, to: "on_leave") }
                }
            } else {
                ForEach(selectableLeaveTypes) { lt in
                    Button("标记为请假 · \(lt.name)") {
                        Task { await updateCompleted(targetRecord, to: "on_leave", leaveTypeId: lt.id) }
                    }
                }
            }
            Button("取消", role: .cancel) {}
        }
        .task {
            await loadDetail()
        }
    }

    private func loadDetail() async {
        isLoading = true
        errorMessage = nil
        defer { isLoading = false }

        do {
            async let detailReq = RollCallRepository.fetchRollCallDetail(id: rollCallId)
            let shouldFetchLeaveTypes = leaveTypes.isEmpty
            var detail = try await detailReq
            // 兼容未升级的旧服务端：首次打开点名若触发同步可能导致 records 缺少 student 关系，自动补拉一次
            if let recs = detail.records, !recs.isEmpty, recs.contains(where: { $0.student == nil }) {
                if let refetched = try? await RollCallRepository.fetchRollCallDetail(id: rollCallId) {
                    detail = refetched
                }
            }
            if shouldFetchLeaveTypes, let fetchedTypes = try? await AttendanceRepository.fetchLeaveTypes() {
                leaveTypes = fetchedTypes
            }
            rollCall = detail
            var loadedRecords = (detail.records ?? []).sorted {
                ($0.student?.studentNo ?? "").localizedStandardCompare($1.student?.studentNo ?? "") == .orderedAscending
            }

            // 恢复本地 SwiftData 离线草稿（仅对进行中的点名生效，且不覆盖后端最新同步的请假状态）
            if detail.status == "in_progress" {
                let drafts = OfflineRollCallStore.shared.loadDrafts(for: rollCallId)
                if !drafts.isEmpty {
                    var restoredAny = false
                    for idx in loadedRecords.indices {
                        if loadedRecords[idx].status == "on_leave" { continue }
                        if let draft = drafts[loadedRecords[idx].studentId] {
                            loadedRecords[idx].status = draft.status
                            loadedRecords[idx].markedAt = draft.markedAtISO
                            loadedRecords[idx].markedAtLocal = draft.markedAtLocal
                            restoredAny = true
                        }
                    }
                    hasRestoredOfflineDraft = restoredAny
                }
            }
            records = loadedRecords
        } catch {
            guard !error.isCancellationError else { return }
            errorMessage = error.localizedDescription
        }
    }

    private func handleTap(_ record: RollCallRecord) {
        if isInProgress {
            // 请假状态受保护，防止走动误点
            guard record.status != "on_leave" else { return }
            guard let idx = records.firstIndex(where: { $0.id == record.id }) else { return }

            let nextPresent = records[idx].status != "present"
            let now = Date()
            let iso = SCDateFormatter.iso8601Basic.string(from: now)
            let local = SCDateFormatter.hhmm.string(from: now)
            let nextStatus = nextPresent ? "present" : "pending"

            records[idx].status = nextStatus
            records[idx].markedAt = nextPresent ? iso : nil
            records[idx].markedAtLocal = nextPresent ? local : nil
            hapticCounter += 1

            OfflineRollCallStore.shared.saveMark(
                rollCallId: rollCallId,
                studentId: record.studentId,
                status: nextStatus,
                markedAtISO: nextPresent ? iso : nil,
                markedAtLocal: nextPresent ? local : nil
            )
        } else if canModifyCompleted {
            editingCompletedRecord = record
        }
    }

    private func markAllPresent() {
        let now = Date()
        let iso = SCDateFormatter.iso8601Basic.string(from: now)
        let local = SCDateFormatter.hhmm.string(from: now)

        for idx in records.indices where records[idx].status == "pending" {
            records[idx].status = "present"
            records[idx].markedAt = iso
            records[idx].markedAtLocal = local
            OfflineRollCallStore.shared.saveMark(
                rollCallId: rollCallId,
                studentId: records[idx].studentId,
                status: "present",
                markedAtISO: iso,
                markedAtLocal: local
            )
        }
        hapticCounter += 1
    }

    private func submitComplete() async {
        isSubmitting = true
        errorMessage = nil
        defer { isSubmitting = false }

        let payloadRecords: [[String: Any]] = records.map { r in
            [
                "student_id": r.studentId,
                "status": r.status,
                "marked_at": r.markedAt ?? NSNull()
            ]
        }

        do {
            let updated = try await RollCallRepository.completeRollCall(id: rollCallId, records: payloadRecords)
            OfflineRollCallStore.shared.clearDrafts(for: rollCallId)
            hasRestoredOfflineDraft = false
            if statusFilter == "pending" { statusFilter = nil }
            rollCall = updated
            await loadDetail()
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    private func updateCompleted(_ record: RollCallRecord, to status: String, leaveTypeId: Int? = nil) async {
        errorMessage = nil
        do {
            let updated = try await RollCallRepository.updateRecord(
                rollCallId: rollCallId,
                recordId: record.id,
                status: status,
                leaveTypeId: leaveTypeId
            )
            if let idx = records.firstIndex(where: { $0.id == record.id }) {
                records[idx].status = updated.status
                records[idx].leaveTypeId = updated.leaveTypeId
                records[idx].leaveDetail = updated.leaveDetail
                records[idx].leaveStatus = updated.leaveStatus
                records[idx].leaveType = updated.leaveType
                records[idx].markedAt = updated.markedAt
                records[idx].markedAtLocal = updated.markedAtLocal
            }
            hapticCounter += 1
        } catch {
            errorMessage = error.localizedDescription
        }
    }
}

private struct SummaryChip: View {
    let title: String
    let value: Int
    let color: Color
    let isSelected: Bool
    let onTap: () -> Void

    private var highlightColor: Color {
        color == .primary ? SCTheme.primary : color
    }

    var body: some View {
        Button(action: onTap) {
            VStack(spacing: 2) {
                Text("\(value)")
                    .font(.headline.monospacedDigit())
                    .foregroundStyle(color)
                Text(title)
                    .font(.caption2.weight(isSelected ? .bold : .regular))
                    .foregroundStyle(isSelected ? highlightColor : .secondary)
            }
            .frame(maxWidth: .infinity)
            .padding(.vertical, 6)
            .background(highlightColor.opacity(isSelected ? 0.16 : 0.08))
            .overlay(
                RoundedRectangle(cornerRadius: 10, style: .continuous)
                    .stroke(isSelected ? highlightColor : Color.clear, lineWidth: 1.6)
            )
            .clipShape(RoundedRectangle(cornerRadius: 10, style: .continuous))
        }
        .buttonStyle(.plain)
    }
}

private struct RollCallStudentTile: View {
    let record: RollCallRecord
    let compact: Bool
    let onTap: () -> Void

    private var tileColor: Color {
        switch record.status {
        case "present": return .green
        case "on_leave": return .blue
        case "absent": return .red
        default: return .gray
        }
    }

    private var statusText: String {
        switch record.status {
        case "present": return record.markedAtLocal ?? "已到"
        case "on_leave": return record.leaveType?.name ?? "请假"
        case "absent": return "缺勤"
        default: return "未点"
        }
    }

    private var watermarkColor: Color {
        switch record.status {
        case "present": return Color.white.opacity(0.26)
        case "on_leave": return Color.blue.opacity(0.22)
        case "absent": return Color.red.opacity(0.22)
        default: return SCTheme.primary.opacity(0.16)
        }
    }

    var body: some View {
        Button(action: onTap) {
            ZStack(alignment: .bottomTrailing) {
                if let shortNo = record.student?.shortStudentNo {
                    Text(shortNo)
                        .font(.system(size: compact ? 28 : 36, weight: .black, design: .rounded))
                        .monospacedDigit()
                        .foregroundStyle(watermarkColor)
                        .lineLimit(1)
                        .padding(.trailing, compact ? 6 : 8)
                        .padding(.bottom, compact ? 1 : 2)
                        .allowsHitTesting(false)
                }

                VStack(alignment: .leading, spacing: compact ? 4 : 6) {
                    Text(record.student?.name ?? "学生 #\(record.studentId)")
                        .font(compact ? .footnote.weight(.semibold) : .subheadline.weight(.semibold))
                        .foregroundStyle(record.status == "present" ? .white : .primary)
                        .lineLimit(1)
                        .minimumScaleFactor(0.8)

                    if !compact, let no = record.student?.studentNo, !no.isEmpty {
                        Text(no)
                            .font(.caption2)
                            .foregroundStyle(record.status == "present" ? .white.opacity(0.85) : .secondary)
                            .lineLimit(1)
                    }

                    HStack(spacing: 3) {
                        Text(statusText)
                            .font(.caption2.weight(.semibold))
                            .foregroundStyle(record.status == "present" ? .white.opacity(0.95) : tileColor)
                            .lineLimit(1)
                        if record.status == "present" {
                            Image(systemName: "checkmark.circle.fill")
                                .font(.system(size: compact ? 11 : 12))
                                .foregroundStyle(.white)
                        }
                    }
                }
                .padding(compact ? 10 : 12)
                .frame(maxWidth: .infinity, minHeight: compact ? 58 : 74, alignment: .topLeading)
            }
            .background(
                record.status == "present"
                    ? Color.green
                    : (record.status == "pending" ? SCTheme.cardBackground : tileColor.opacity(0.14))
            )
            .overlay(
                RoundedRectangle(cornerRadius: 12, style: .continuous)
                    .stroke(tileColor.opacity(record.status == "pending" ? 0.25 : 0.6), lineWidth: 1.2)
            )
            .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
        }
        .buttonStyle(.plain)
    }
}
