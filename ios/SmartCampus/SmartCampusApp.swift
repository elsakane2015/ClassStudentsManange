import SwiftUI
import UIKit

final class AppDelegate: NSObject, UIApplicationDelegate {
    func application(
        _ application: UIApplication,
        didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]? = nil
    ) -> Bool {
        _ = PushNotificationManager.shared
        return true
    }

    func application(
        _ application: UIApplication,
        didRegisterForRemoteNotificationsWithDeviceToken deviceToken: Data
    ) {
        Task { @MainActor in
            PushNotificationManager.shared.handleRegisteredDeviceToken(deviceToken)
        }
    }

    func application(
        _ application: UIApplication,
        didFailToRegisterForRemoteNotificationsWithError error: Error
    ) {
        Task { @MainActor in
            PushNotificationManager.shared.handleFailedRemoteRegistration(error)
        }
    }
}

@main
struct SmartCampusApp: App {
    @UIApplicationDelegateAdaptor(AppDelegate.self) private var appDelegate
    @State private var session = SessionManager.shared
    @State private var serverConfig = ServerConfigManager.shared

    var body: some Scene {
        WindowGroup {
            Group {
                if session.isBootstrapping {
                    LaunchSplashView()
                } else if !serverConfig.isConfigured {
                    NavigationStack {
                        ServerSetupView(isInitialSetup: true)
                    }
                } else if session.isAuthenticated, let user = session.currentUser {
                    RoleBasedTabView(user: user)
                } else {
                    NavigationStack {
                        LoginView()
                    }
                }
            }
            .task {
                await session.bootstrap()
            }
        }
    }
}

private struct LaunchSplashView: View {
    var body: some View {
        ZStack {
            SCTheme.background.ignoresSafeArea()
            VStack(spacing: 16) {
                Image(systemName: "graduationcap.circle.fill")
                    .font(.system(size: 72))
                    .foregroundStyle(SCTheme.primary)
                Text(ServerConfigManager.shared.activeSchoolName)
                    .font(.title2.bold())
                ProgressView("正在连接校园服务...")
                    .font(.footnote)
                    .foregroundStyle(.secondary)
            }
        }
    }
}
