import SwiftUI

struct LeaveHistoryView: View {
    let user: User

    @State private var statusFilter: String = "all"
    @State private var requests: [LeaveRequestGroup] = []
    @State private var isLoading = false
    @State private var errorMessage: String?
    @State private var cancelTarget: LeaveRequestGroup?
    @State private var previewImagePath: String?

    private let filters: [(key: String, label: String)] = [
        ("all", "全部"),
        ("pending", "待审批"),
        ("approved", "已通过"),
        ("rejected", "已驳回")
    ]

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
                        ProgressView("加载请假记录...")
                            .frame(maxWidth: .infinity, maxHeight: .infinity)
                    } else if let errorMessage, requests.isEmpty {
                        ErrorBannerView(message: errorMessage) {
                            Task { await loadRequests() }
                        }
                        .padding()
                        Spacer()
                    } else if requests.isEmpty {
                        ContentUnavailableView(
                            "暂无请假记录",
                            systemImage: "doc.text.magnifyingglass",
                            description: Text("提交的请假申请及审批状态将显示在这里")
                        )
                    } else {
                        ScrollView {
                            LazyVStack(spacing: 12) {
                                ForEach(requests) { req in
                                    LeaveRecordCard(
                                        request: req,
                                        canCancel: req.effectiveStatus == "pending",
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
            .navigationTitle("请假记录")
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
    let canCancel: Bool
    let onCancel: () -> Void
    let onImageTap: (String) -> Void

    var body: some View {
        SCCard {
            VStack(alignment: .leading, spacing: 10) {
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

                if let reason = request.reason, !reason.isEmpty {
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
                            .buttonStyle(.bordered)
                    }
                }
            }
        }
    }
}
