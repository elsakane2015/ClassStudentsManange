import SwiftUI

struct NotificationsView: View {
    @Environment(\.dismiss) private var dismiss
    @State private var items: [AppNotificationItem] = []
    @State private var isLoading = false
    @State private var errorMessage: String?

    var body: some View {
        Group {
            if isLoading && items.isEmpty {
                ProgressView("加载消息通知...")
                    .frame(maxWidth: .infinity, maxHeight: .infinity)
            } else if let errorMessage, items.isEmpty {
                VStack(spacing: 12) {
                    ErrorBannerView(message: errorMessage) {
                        Task { await loadNotifications() }
                    }
                    .padding()
                }
            } else if items.isEmpty {
                ContentUnavailableView(
                    "暂无通知消息",
                    systemImage: "bell.slash",
                    description: Text("请假审批、考勤变更提醒将实时汇总显示在这里")
                )
            } else {
                List {
                    ForEach(items) { item in
                        Button {
                            Task { await handleTap(item) }
                        } label: {
                            HStack(alignment: .top, spacing: 12) {
                                Circle()
                                    .fill(item.isUnread ? SCTheme.primary : Color.clear)
                                    .frame(width: 8, height: 8)
                                    .padding(.top, 6)

                                VStack(alignment: .leading, spacing: 6) {
                                    HStack {
                                        Text(item.title)
                                            .font(.subheadline.weight(item.isUnread ? .semibold : .regular))
                                            .foregroundStyle(.primary)
                                        Spacer()
                                        Text(SCDateFormatter.displayDateTime(item.createdAt))
                                            .font(.caption2)
                                            .foregroundStyle(.tertiary)
                                    }

                                    Text(item.body)
                                        .font(.footnote)
                                        .foregroundStyle(.secondary)
                                        .lineLimit(3)
                                }
                            }
                            .padding(.vertical, 4)
                        }
                        .swipeActions(edge: .trailing) {
                            Button(role: .destructive) {
                                Task { await delete(item) }
                            } label: {
                                Label("删除", systemImage: "trash")
                            }
                        }
                    }
                }
                .listStyle(.insetGrouped)
            }
        }
        .navigationTitle("消息通知")
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            ToolbarItem(placement: .topBarTrailing) {
                if items.contains(where: { $0.isUnread }) {
                    Button("全部已读") {
                        Task { await markAllRead() }
                    }
                }
            }
        }
        .refreshable {
            await loadNotifications()
        }
        .task {
            await loadNotifications()
        }
    }

    private func loadNotifications() async {
        isLoading = true
        errorMessage = nil
        defer { isLoading = false }

        do {
            let res = try await NotificationRepository.fetchNotifications()
            items = res.data
            PushNotificationManager.shared.unreadCount = res.unreadCount
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    private func handleTap(_ item: AppNotificationItem) async {
        if item.isUnread {
            try? await NotificationRepository.markRead(id: item.id)
            await PushNotificationManager.shared.refreshUnreadCount()
        }
        if item.route != nil || !item.type.isEmpty {
            PushNotificationManager.shared.handleDeepLink(route: item.route, type: item.type)
            dismiss()
        } else {
            await loadNotifications()
        }
    }

    private func markAllRead() async {
        try? await NotificationRepository.markAllRead()
        await loadNotifications()
        await PushNotificationManager.shared.refreshUnreadCount()
    }

    private func delete(_ item: AppNotificationItem) async {
        try? await NotificationRepository.deleteNotification(id: item.id)
        items.removeAll { $0.id == item.id }
        await PushNotificationManager.shared.refreshUnreadCount()
    }
}
