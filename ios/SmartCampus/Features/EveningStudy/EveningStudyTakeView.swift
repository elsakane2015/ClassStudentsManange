import SwiftUI

/// 夜自习单班巡查点名操作面板
struct EveningStudyTakeView: View {
    let initialPayload: EveningStudySessionPayload
    let statuses: [EveningStudyStatus]
    let canModify: Bool
    let canDelete: Bool
    let onDeleted: ((String) -> Void)?
    @Environment(\.dismiss) private var dismiss

    @State private var session: EveningStudySession
    @State private var records: [EveningStudyRecordItem]
    @State private var searchText: String = ""
    @State private var notes: String
    @State private var isSaving = false
    @State private var showDeleteConfirm = false
    @State private var errorMessage: String?
    @State private var statusBanner: String?
    @State private var hapticTrigger = 0

    init(
        initialPayload: EveningStudySessionPayload,
        statuses: [EveningStudyStatus],
        canModify: Bool,
        canDelete: Bool = false,
        onDeleted: ((String) -> Void)? = nil
    ) {
        self.initialPayload = initialPayload
        self.statuses = statuses
        self.canModify = canModify
        self.canDelete = canDelete
        self.onDeleted = onDeleted
        _session = State(initialValue: initialPayload.session)
        _records = State(initialValue: initialPayload.records)
        _notes = State(initialValue: initialPayload.session.notes ?? "")
    }

    private var editable: Bool { canModify && !session.isCompleted }

    private var filteredIndices: [Int] {
        let q = searchText.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        if q.isEmpty { return Array(records.indices) }
        return records.indices.filter { idx in
            let r = records[idx]
            let name = (r.student?.name ?? "").lowercased()
            let no = (r.student?.studentNo ?? "").lowercased()
            return name.contains(q) || no.contains(q)
        }
    }

    var body: some View {
        VStack(spacing: 0) {
            // Session Header
            VStack(spacing: 8) {
                HStack {
                    VStack(alignment: .leading, spacing: 2) {
                        Text(session.schoolClass?.name ?? "夜自习点名")
                            .font(.headline)
                        Text("\(session.dateDisplay) · \(session.periodNameSnapshot ?? "夜自习") · 共 \(records.count) 人")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                    Spacer()
                    StatusBadge(
                        text: session.isCompleted ? "已完成" : "点名中",
                        color: session.isCompleted ? .green : .orange
                    )
                }

                HStack(spacing: 8) {
                    Image(systemName: "magnifyingglass")
                        .foregroundStyle(.secondary)
                    TextField("搜索学生姓名/学号", text: $searchText)
                        .textInputAutocapitalization(.never)
                        .autocorrectionDisabled(true)
                }
                .padding(.horizontal, 10)
                .padding(.vertical, 8)
                .background(Color(.tertiarySystemFill))
                .clipShape(RoundedRectangle(cornerRadius: 10, style: .continuous))

                if let statusBanner {
                    Text(statusBanner)
                        .font(.caption)
                        .foregroundStyle(.green)
                        .frame(maxWidth: .infinity, alignment: .leading)
                }
                if let errorMessage {
                    ErrorBannerView(message: errorMessage)
                }
            }
            .padding()
            .background(SCTheme.cardBackground)

            // Student List
            List {
                ForEach(filteredIndices, id: \.self) { idx in
                    let rec = records[idx]
                    let isSuspended = rec.boardingSuspension != nil
                    VStack(alignment: .leading, spacing: 10) {
                        HStack {
                            Text(rec.student?.name ?? "学生 #\(rec.studentId)")
                                .font(.subheadline.weight(.semibold))
                            if let no = rec.student?.studentNo {
                                Text(no)
                                    .font(.caption)
                                    .foregroundStyle(.secondary)
                            }
                            Spacer()
                            if isSuspended {
                                StatusBadge(text: "暂停住宿", color: .purple)
                            } else if rec.isSelfApplied?.value == true {
                                StatusBadge(
                                    text: rec.approvalStatus == "approved" ? "请假已批" : "请假待批",
                                    color: rec.approvalStatus == "approved" ? .blue : .orange
                                )
                            }
                        }

                        if let dest = rec.destination, !dest.isEmpty {
                            Text("去向：\(dest)")
                                .font(.caption)
                                .foregroundStyle(.indigo)
                        }

                        // Status Picker Pills
                        ScrollView(.horizontal, showsIndicators: false) {
                            HStack(spacing: 8) {
                                ForEach(statuses) { st in
                                    let isSelected = rec.eveningStudyStatusId == st.id
                                    let tint = SCTheme.color(for: st.color)
                                    Button {
                                        guard editable && !isSuspended else { return }
                                        records[idx].eveningStudyStatusId = st.id
                                        hapticTrigger += 1
                                    } label: {
                                        Text(st.name)
                                            .font(.caption.weight(.semibold))
                                            .padding(.horizontal, 12)
                                            .padding(.vertical, 7)
                                            .background(isSelected ? tint : tint.opacity(0.12))
                                            .foregroundStyle(isSelected ? .white : tint)
                                            .clipShape(Capsule())
                                    }
                                    .buttonStyle(.plain)
                                    .disabled(!editable || isSuspended)
                                }
                            }
                        }

                        if editable && !isSuspended {
                            TextField("备注 / 去向说明（可选）", text: Binding(
                                get: { records[idx].note ?? "" },
                                set: { records[idx].note = $0 }
                            ))
                            .font(.caption)
                            .padding(.horizontal, 10)
                            .padding(.vertical, 6)
                            .background(Color(.tertiarySystemFill))
                            .clipShape(RoundedRectangle(cornerRadius: 8, style: .continuous))
                        }
                    }
                    .padding(.vertical, 6)
                }

                if editable {
                    Section("本次巡查备注") {
                        TextField("输入班级夜自习整体备注（可选）", text: $notes, axis: .vertical)
                            .lineLimit(2...4)
                    }
                }
            }
            .listStyle(.insetGrouped)

            // Bottom Action Bar
            if canModify {
                VStack(spacing: 0) {
                    Divider()
                    HStack(spacing: 12) {
                        if session.isCompleted {
                            Button {
                                Task { await reopen() }
                            } label: {
                                HStack {
                                    Spacer()
                                    if isSaving { ProgressView() }
                                    Label("重新打开以修改", systemImage: "lock.open")
                                        .font(.headline)
                                    Spacer()
                                }
                                .padding(.vertical, 10)
                            }
                            .buttonStyle(.bordered)
                            .disabled(isSaving)
                        } else {
                            Button {
                                Task { await saveOnly() }
                            } label: {
                                Text("暂存")
                                    .font(.headline)
                                    .padding(.horizontal, 16)
                                    .padding(.vertical, 10)
                            }
                            .buttonStyle(.bordered)
                            .disabled(isSaving)

                            Button {
                                Task { await complete() }
                            } label: {
                                HStack {
                                    Spacer()
                                    if isSaving { ProgressView().tint(.white) }
                                    Text("完成并提交")
                                        .font(.headline)
                                    Spacer()
                                }
                                .padding(.vertical, 10)
                            }
                            .buttonStyle(.borderedProminent)
                            .disabled(isSaving)
                        }
                    }
                    .padding()
                    .background(SCTheme.cardBackground)
                }
            }
        }
        .navigationTitle("夜自习巡查")
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            if canDelete {
                ToolbarItem(placement: .topBarTrailing) {
                    Button(role: .destructive) {
                        showDeleteConfirm = true
                    } label: {
                        Image(systemName: "trash")
                            .foregroundStyle(.red)
                    }
                    .disabled(isSaving)
                }
            }
        }
        .alert("删除本场夜自习点名？", isPresented: $showDeleteConfirm) {
            Button("取消", role: .cancel) {}
            Button("确认删除", role: .destructive) {
                Task { await deleteCurrentSession() }
            }
        } message: {
            Text("确定删除「\(session.schoolClass?.name ?? "该班级")」的本场夜自习点名吗？该场产生的学生点名标记记录将一并删除（学生请假记录会保留）。")
        }
        .sensoryFeedback(.selection, trigger: hapticTrigger)
    }

    private func buildRecordsPayload() -> [[String: Any]] {
        records.compactMap { rec -> [String: Any]? in
            guard let statusId = rec.eveningStudyStatusId ?? statuses.first(where: \.isDefaultFlag)?.id else {
                return nil
            }
            var dict: [String: Any] = [
                "id": rec.id,
                "status_id": statusId
            ]
            if let dest = rec.destination, !dest.isEmpty { dict["destination"] = dest }
            if let note = rec.note, !note.isEmpty { dict["note"] = note }
            return dict
        }
    }

    private func saveOnly() async {
        isSaving = true
        errorMessage = nil
        statusBanner = nil
        defer { isSaving = false }

        do {
            let payload = try await EveningStudyRepository.updateSessionRecords(
                sessionId: session.id,
                records: buildRecordsPayload()
            )
            session = payload.session
            records = payload.records
            statusBanner = "已暂存最新点名记录"
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    private func complete() async {
        isSaving = true
        errorMessage = nil
        statusBanner = nil
        defer { isSaving = false }

        do {
            let payload = try await EveningStudyRepository.completeSession(
                sessionId: session.id,
                notes: notes,
                records: buildRecordsPayload()
            )
            session = payload.session
            records = payload.records
            statusBanner = "夜自习点名已完成"
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    private func reopen() async {
        isSaving = true
        errorMessage = nil
        statusBanner = nil
        defer { isSaving = false }

        do {
            let payload = try await EveningStudyRepository.reopenSession(sessionId: session.id)
            session = payload.session
            records = payload.records
            statusBanner = "已重新打开，可继续修改"
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    private func deleteCurrentSession() async {
        isSaving = true
        errorMessage = nil
        statusBanner = nil
        defer { isSaving = false }

        do {
            let res = try await EveningStudyRepository.deleteSession(sessionId: session.id)
            let count = res.deletedRecordCount ?? 0
            let msg = count > 0
                ? "已删除本场点名及 \(count) 条学生点名记录"
                : (res.message ?? "点名记录已删除")
            onDeleted?(msg)
            dismiss()
        } catch {
            errorMessage = error.localizedDescription
        }
    }
}
