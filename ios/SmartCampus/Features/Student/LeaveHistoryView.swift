import SwiftUI

struct LeaveHistoryView: View {
    let user: User

    @State private var statusFilter: String = "all"
    @State private var ownerScope: OwnerScope = .all
    @State private var searchText: String = ""
    @State private var requests: [LeaveRequestGroup] = []
    @State private var isLoading = false
    @State private var errorMessage: String?
    @State private var cancelTarget: LeaveRequestGroup?
    @State private var previewImagePath: String?

    enum OwnerScope: String, CaseIterable, Identifiable {
        case all = "全班"
        case mine = "仅看我的"
        var id: String { rawValue }
    }

    private let filters: [(key: String, label: String)] = [
        ("all", "全部"),
        ("pending", "待审批"),
        ("approved", "已通过"),
        ("rejected", "已驳回")
    ]

    private var myStudentId: Int? { user.student?.id }

    private func isOwnRequest(_ req: LeaveRequestGroup) -> Bool {
        guard let myId = myStudentId else { return true }
        let reqSid = req.studentId ?? req.student?.id
        guard let reqSid else { return true }
        return reqSid == myId
    }

    /// 班级考勤员或列表中出现非本人记录时，醒目展示学生姓名与学号并提供范围/搜索筛选
    private var showStudentIdentity: Bool {
        if user.isClassAdminStudent { return true }
        return requests.contains { !isOwnRequest($0) }
    }

    private var filteredRequests: [LeaveRequestGroup] {
        let q = searchText.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        return requests.filter { req in
            if showStudentIdentity && ownerScope == .mine && !isOwnRequest(req) {
                return false
            }
            guard !q.isEmpty else { return true }
            let name = (req.student?.name ?? "").lowercased()
            let no = (req.student?.studentNo ?? "").lowercased()
            let reason = (req.reason ?? "").lowercased()
            return name.contains(q) || no.contains(q) || reason.contains(q)
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
                .padding(.top, 8)
                .padding(.bottom, showStudentIdentity ? 6 : 8)
                .background(SCTheme.background)

                if showStudentIdentity {
                    HStack(spacing: 8) {
                        HStack(spacing: 4) {
                            ForEach(OwnerScope.allCases) { scope in
                                let active = ownerScope == scope
                                Button {
                                    withAnimation(.snappy(duration: 0.18)) {
                                        ownerScope = scope
                                    }
                                } label: {
                                    Text(scope.rawValue)
                                        .font(.caption.weight(active ? .semibold : .regular))
                                        .padding(.horizontal, 10)
                                        .padding(.vertical, 6)
                                        .background(active ? SCTheme.primary : Color(.secondarySystemGroupedBackground))
                                        .foregroundStyle(active ? .white : .primary)
                                        .clipShape(Capsule())
                                }
                                .buttonStyle(.plain)
                            }
                        }

                        HStack(spacing: 5) {
                            Image(systemName: "magnifyingglass")
                                .font(.caption)
                                .foregroundStyle(.secondary)
                            TextField("搜姓名/学号", text: $searchText)
                                .font(.caption)
                                .textInputAutocapitalization(.never)
                                .autocorrectionDisabled(true)
                        }
                        .padding(.horizontal, 9)
                        .padding(.vertical, 6)
                        .background(Color(.secondarySystemGroupedBackground))
                        .clipShape(Capsule())
                    }
                    .padding(.horizontal)
                    .padding(.bottom, 8)
                    .background(SCTheme.background)
                }

                Group {
                    if isLoading && requests.isEmpty {
                        ProgressView("加载请假记录...")
                            .frame(maxWidth: .infinity, maxHeight: .infinity)
                    } else if let errorMessage, requests.isEmpty {
                        ErrorBannerView(message: errorMessage) {
                            Task { await loadRequests() }
                        }
                        .padding()
                        Spacer()
                    } else if filteredRequests.isEmpty {
                        ContentUnavailableView(
                            "暂无请假记录",
                            systemImage: "doc.text.magnifyingglass",
                            description: Text(showStudentIdentity
                                ? "当前筛选条件下暂无请假申请记录"
                                : "提交的请假申请及审批状态将显示在这里")
                        )
                    } else {
                        ScrollView {
                            LazyVStack(spacing: 12) {
                                ForEach(filteredRequests) { req in
                                    let isMine = isOwnRequest(req)
                                    LeaveRecordCard(
                                        request: req,
                                        showStudentIdentity: showStudentIdentity,
                                        isOwnRequest: isMine,
                                        canCancel: req.effectiveStatus == "pending" && isMine,
                                        onCancel: { cancelTarget = req },
                                        onImageTap: { path in previewImagePath = path }
                                    )
                                }
                            }
                            .padding()
                        }
                    }
                }
            }
            .background(SCTheme.background.ignoresSafeArea())
            .navigationTitle(showStudentIdentity ? "班级请假记录" : "请假记录")
            .refreshable {
                await loadRequests()
            }
            .onChange(of: statusFilter) { _, _ in
                Task { await loadRequests() }
            }
            .alert("撤销请假申请", isPresented: Binding(
                get: { cancelTarget != nil },
                set: { if !$0 { cancelTarget = nil } }
            )) {
                Button("再想想", role: .cancel) {}
                Button("确认撤销", role: .destructive) {
                    if let target = cancelTarget {
                        Task { await cancelRequest(target) }
                    }
                }
            } message: {
                Text("撤销后该条待审批申请将被删除，确认继续？")
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

    private func cancelRequest(_ req: LeaveRequestGroup) async {
        do {
            try await LeaveRepository.cancelLeaveRequest(id: req.id)
            requests.removeAll { $0.id == req.id }
        } catch {
            errorMessage = error.localizedDescription
        }
    }
}

struct ImagePreviewPayload: Identifiable {
    let id = UUID()
    let path: String
}

struct FullScreenImageSheet: View {
    let pathOrURL: String
    @Environment(\.dismiss) private var dismiss

    var body: some View {
        NavigationStack {
            ZStack {
                Color.black.ignoresSafeArea()
                if let url = ServerConfigManager.shared.resolveMediaURL(pathOrURL) {
                    AsyncImage(url: url) { phase in
                        switch phase {
                        case .empty: ProgressView().tint(.white)
                        case .success(let image):
                            image.resizable().scaledToFit()
                        case .failure:
                            VStack(spacing: 8) {
                                Image(systemName: "photo.badge.exclamationmark")
                                    .font(.largeTitle)
                                Text("图片加载失败")
                            }
                            .foregroundStyle(.white.opacity(0.8))
                        @unknown default: EmptyView()
                        }
                    }
                }
            }
            .toolbar {
                ToolbarItem(placement: .confirmationAction) {
                    Button("关闭") { dismiss() }
                        .tint(.white)
                }
            }
        }
    }
}

private struct LeaveRecordCard: View {
    let request: LeaveRequestGroup
    let showStudentIdentity: Bool
    let isOwnRequest: Bool
    let canCancel: Bool
    let onCancel: () -> Void
    let onImageTap: (String) -> Void

    var body: some View {
        SCCard {
            VStack(alignment: .leading, spacing: 10) {
                if showStudentIdentity {
                    HStack(alignment: .firstTextBaseline, spacing: 6) {
                        Image(systemName: "person.fill")
                            .font(.caption)
                            .foregroundStyle(SCTheme.primary)
                        Text(request.student?.name ?? "学生 #\(request.studentId ?? request.id)")
                            .font(.subheadline.weight(.bold))
                            .foregroundStyle(.primary)
                        if let no = request.student?.studentNo, !no.isEmpty {
                            Text(no)
                                .font(.caption.monospacedDigit())
                                .foregroundStyle(.secondary)
                        }
                        if isOwnRequest {
                            Text("我")
                                .font(.caption2.weight(.semibold))
                                .padding(.horizontal, 6)
                                .padding(.vertical, 2)
                                .background(SCTheme.primary.opacity(0.14))
                                .foregroundStyle(SCTheme.primary)
                                .clipShape(Capsule())
                        }
                        Spacer()
                    }
                }

                HStack {
                    StatusBadge(
                        text: request.leaveType?.name ?? "请假",
                        color: SCTheme.color(for: request.leaveType?.color ?? "blue")
                    )
                    StatusBadge(
                        text: request.statusDisplayName,
                        color: SCTheme.color(for: request.effectiveStatus)
                    )
                    Spacer()
                    if let days = request.totalDays?.value, days > 0 {
                        Text("\(days) 天")
                            .font(.caption.weight(.semibold))
                            .foregroundStyle(.secondary)
                    }
                }

                Label(request.displayDateRange, systemImage: "calendar")
                    .font(.subheadline.weight(.medium))

                if let label = request.halfDayLabel ?? request.periodLabel, !label.isEmpty {
                    Label("节次/时段：\(label)", systemImage: "clock")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }

                if let es = request.eveningStudyLabel, !es.isEmpty {
                    Label("夜自习：\(es)" + (request.destination.map { "（去向：\($0)）" } ?? ""), systemImage: "moon.stars")
                        .font(.caption)
                        .foregroundStyle(.indigo)
                }

                if let reason = request.displayReason, !reason.isEmpty {
                    Text("事由：\(reason)")
                        .font(.footnote)
                        .foregroundStyle(.primary)
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

                HStack {
                    if let approver = request.approverName, !approver.isEmpty {
                        Text("审批人：\(approver)")
                            .font(.caption2)
                            .foregroundStyle(.secondary)
                    } else if let created = request.createdAt {
                        Text("提交于 \(SCDateFormatter.displayDateTime(created))")
                            .font(.caption2)
                            .foregroundStyle(.tertiary)
                    }
                    Spacer()
                    if canCancel {
                        Button("撤销申请", role: .destructive, action: onCancel)
                            .font(.caption.weight(.semibold))
                            .buttonStyle(SCActionButtonStyle(variant: .soft(.red), cornerRadius: 9, verticalPadding: 6, horizontalPadding: 11))
                    }
                }
            }
        }
    }
}
