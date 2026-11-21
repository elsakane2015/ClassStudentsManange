import SwiftUI

/// 教师 / 管理员 / 班级管理员 请假审批流（支持一键通过、按节次部分审批、填写驳回理由、请假凭证大图查看）
struct LeaveApprovalsView: View {
    let user: User

    @State private var statusFilter: String = "pending"
    @State private var searchText: String = ""
    @State private var requests: [LeaveRequestGroup] = []
    @State private var isLoading = false
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

    private var filteredRequests: [LeaveRequestGroup] {
        let q = searchText.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        guard !q.isEmpty else { return requests }
        return requests.filter { r in
            let name = (r.student?.name ?? "").lowercased()
            let no = (r.student?.studentNo ?? "").lowercased()
            let cls = (r.schoolClass?.name ?? "").lowercased()
            return name.contains(q) || no.contains(q) || cls.contains(q)
        }
    }

    var body: some View {
        NavigationStack {
            VStack(spacing: 0) {
                Picker("审批状态", selection: $statusFilter) {
                    ForEach(filters, id: \.key) { item in
                        Text(item.label).tag(item.key)
                    }
                }
                .pickerStyle(.segmented)
                .padding(.horizontal)
                .padding(.vertical, 8)
                .background(SCTheme.background)

                Group {
                    if isLoading && requests.isEmpty {
                        ProgressView("加载请假审批列表...")
                            .frame(maxWidth: .infinity, maxHeight: .infinity)
                    } else if let errorMessage, requests.isEmpty {
                        ErrorBannerView(message: errorMessage) {
                            Task { await loadRequests() }
                        }
                        .padding()
                        Spacer()
                    } else if filteredRequests.isEmpty {
                        ContentUnavailableView(
                            statusFilter == "pending" ? "暂无待审批请假" : "暂无符合条件的记录",
                            systemImage: "checkmark.seal",
                            description: Text("所有学生请假申请会第一时间推送到这里")
                        )
                    } else {
                        List {
                            ForEach(filteredRequests) { req in
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
                await loadRequests()
            }
            .onChange(of: statusFilter) { _, _ in
                Task { await loadRequests() }
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
                await loadRequests()
            }
        }
    }

    private func loadRequests() async {
        isLoading = true
        errorMessage = nil
        defer { isLoading = false }

        do {
            requests = try await LeaveRepository.fetchLeaveRequests(status: statusFilter)
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    private func approve(_ req: LeaveRequestGroup, recordIds: [Int]?) async {
        do {
            try await LeaveRepository.approveLeaveRequest(id: req.id, recordIds: recordIds)
            hapticSuccess += 1
            await loadRequests()
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    private func reject(_ req: LeaveRequestGroup, reason: String) async {
        do {
            try await LeaveRepository.rejectLeaveRequest(id: req.id, reason: reason)
            hapticSuccess += 1
            await loadRequests()
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
