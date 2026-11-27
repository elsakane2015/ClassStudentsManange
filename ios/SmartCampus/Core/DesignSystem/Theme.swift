import SwiftUI
import UIKit

enum SCTheme {
    static let primary = Color("AccentColor")
    static let background = Color(.systemGroupedBackground)
    static let cardBackground = Color(.secondarySystemGroupedBackground)

    /// 映射后端配置的色值字符串（对齐 Web 端 colorStyles）
    static func color(for key: String?) -> Color {
        switch key?.lowercased() {
        case "green", "emerald", "present", "approved", "completed":
            return .green
        case "blue", "sky", "excused", "leave":
            return .blue
        case "indigo":
            return .indigo
        case "purple":
            return .purple
        case "cyan", "teal":
            return .cyan
        case "red", "rose", "absent", "rejected", "cancelled":
            return .red
        case "yellow", "amber", "late", "pending", "in_progress":
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

/// 带清晰按压缩放、深色压态与触感反馈的通用操作按钮样式（解决 SwiftUI List / Form 内按钮无按压效果的问题）
struct SCActionButtonStyle: ButtonStyle {
    enum Variant {
        case filled(Color)
        case soft(Color)
    }

    let variant: Variant
    var cornerRadius: CGFloat = 10
    var verticalPadding: CGFloat = 9
    var horizontalPadding: CGFloat = 12
    var haptic: UIImpactFeedbackGenerator.FeedbackStyle? = .light

    @Environment(\.isEnabled) private var isEnabled

    func makeBody(configuration: Configuration) -> some View {
        let pressed = configuration.isPressed && isEnabled

        configuration.label
            .padding(.horizontal, horizontalPadding)
            .padding(.vertical, verticalPadding)
            .foregroundStyle(foregroundColor)
            .background(backgroundView(pressed: pressed))
            .overlay(borderView(pressed: pressed))
            .clipShape(RoundedRectangle(cornerRadius: cornerRadius, style: .continuous))
            .scaleEffect(pressed ? 0.95 : 1.0)
            .opacity(isEnabled ? (pressed ? 0.88 : 1.0) : 0.48)
            .contentShape(RoundedRectangle(cornerRadius: cornerRadius, style: .continuous))
            .animation(.spring(response: 0.2, dampingFraction: 0.72), value: pressed)
            .onChange(of: configuration.isPressed) { _, nowPressed in
                if nowPressed, isEnabled, let style = haptic {
                    UIImpactFeedbackGenerator(style: style).impactOccurred()
                }
            }
    }

    private var foregroundColor: Color {
        switch variant {
        case .filled:
            return .white
        case .soft(let tint):
            return tint
        }
    }

    @ViewBuilder
    private func backgroundView(pressed: Bool) -> some View {
        switch variant {
        case .filled(let tint):
            ZStack {
                tint
                if pressed {
                    Color.black.opacity(0.18)
                }
            }
        case .soft(let tint):
            tint.opacity(pressed ? 0.22 : 0.11)
        }
    }

    @ViewBuilder
    private func borderView(pressed: Bool) -> some View {
        switch variant {
        case .filled:
            EmptyView()
        case .soft(let tint):
            RoundedRectangle(cornerRadius: cornerRadius, style: .continuous)
                .stroke(tint.opacity(pressed ? 0.48 : 0.25), lineWidth: 1)
        }
    }
}

extension View {
    /// 隔离 SwiftUI `.refreshable` 视图重排或回弹导致的 Task 级联取消，
    /// 避免下拉刷新中途被系统取消并弹出「网络请求失败：已取消」。
    func safeRefreshable(_ action: @escaping @MainActor () async -> Void) -> some View {
        refreshable {
            await Task { @MainActor in
                await action()
            }.value
        }
    }
}
