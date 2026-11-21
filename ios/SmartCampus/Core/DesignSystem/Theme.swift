import SwiftUI

enum SCTheme {
    static let primary = Color("AccentColor")
    static let background = Color(.systemGroupedBackground)
    static let cardBackground = Color(.secondarySystemGroupedBackground)

    /// 映射后端配置的色值字符串（对齐 Web 端 colorStyles）
    static func color(for key: String?) -> Color {
        switch key?.lowercased() {
        case "green", "emerald", "present", "approved", "completed":
            return .green
        case "blue", "sky", "excused":
            return .blue
        case "indigo":
            return .indigo
        case "purple":
            return .purple
        case "cyan", "teal":
            return .cyan
        case "red", "rose", "absent", "rejected", "cancelled":
            return .red
        case "yellow", "amber", "late", "pending":
            return .orange
        case "orange", "early_leave":
            return .orange
        case "pink":
            return .pink
        default:
            return .secondary
        }
    }
}

struct SCCard<Content: View>: View {
    var padding: CGFloat = 16
    @ViewBuilder let content: () -> Content

    var body: some View {
        content()
            .padding(padding)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(SCTheme.cardBackground)
            .clipShape(RoundedRectangle(cornerRadius: 16, style: .continuous))
            .shadow(color: Color.black.opacity(0.04), radius: 8, x: 0, y: 2)
    }
}

struct StatusBadge: View {
    let text: String
    let color: Color

    var body: some View {
        Text(text)
            .font(.caption.weight(.semibold))
            .foregroundStyle(color)
            .padding(.horizontal, 9)
            .padding(.vertical, 4)
            .background(color.opacity(0.14))
            .clipShape(Capsule())
    }
}

struct StatMetricCard: View {
    let title: String
    let value: String
    let icon: String
    let color: Color
    var subtitle: String? = nil

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            HStack {
                Image(systemName: icon)
                    .font(.subheadline.weight(.semibold))
                    .foregroundStyle(color)
                    .frame(width: 28, height: 28)
                    .background(color.opacity(0.14))
                    .clipShape(RoundedRectangle(cornerRadius: 8, style: .continuous))
                Spacer()
            }
            Text(value)
                .font(.title2.bold().monospacedDigit())
                .foregroundStyle(.primary)
            Text(title)
                .font(.caption)
                .foregroundStyle(.secondary)
                .lineLimit(1)
            if let subtitle, !subtitle.isEmpty {
                Text(subtitle)
                    .font(.caption2)
                    .foregroundStyle(.tertiary)
                    .lineLimit(1)
            }
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(SCTheme.cardBackground)
        .clipShape(RoundedRectangle(cornerRadius: 16, style: .continuous))
        .shadow(color: Color.black.opacity(0.03), radius: 6, x: 0, y: 2)
    }
}

struct ErrorBannerView: View {
    let message: String
    var onRetry: (() -> Void)? = nil

    var body: some View {
        HStack(spacing: 10) {
            Image(systemName: "exclamationmark.triangle.fill")
                .foregroundStyle(.red)
            Text(message)
                .font(.footnote)
                .foregroundStyle(.primary)
                .frame(maxWidth: .infinity, alignment: .leading)
            if let onRetry {
                Button("重试", action: onRetry)
                    .font(.footnote.weight(.semibold))
            }
        }
        .padding(12)
        .background(Color.red.opacity(0.1))
        .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
    }
}

struct RemoteMediaImage: View {
    let pathOrURL: String
    var height: CGFloat = 90

    var body: some View {
        if let url = ServerConfigManager.shared.resolveMediaURL(pathOrURL) {
            AsyncImage(url: url) { phase in
                switch phase {
                case .empty:
                    ProgressView()
                        .frame(maxWidth: .infinity, minHeight: height)
                        .background(Color(.tertiarySystemFill))
                case .success(let image):
                    image
                        .resizable()
                        .scaledToFill()
                case .failure:
                    VStack(spacing: 4) {
                        Image(systemName: "photo.badge.exclamationmark")
                            .foregroundStyle(.secondary)
                        Text("加载失败")
                            .font(.caption2)
                            .foregroundStyle(.secondary)
                    }
                    .frame(maxWidth: .infinity, minHeight: height)
                    .background(Color(.tertiarySystemFill))
                @unknown default:
                    EmptyView()
                }
            }
        } else {
            Color(.tertiarySystemFill)
        }
    }
}
