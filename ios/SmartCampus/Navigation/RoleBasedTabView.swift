import SwiftUI

/// 根据登录用户角色与 RBAC 权限动态组装 iOS 原生底部 TabView，并响应推送 Deep Link 路由
struct RoleBasedTabView: View {
    let user: User

    @State private var pushManager = PushNotificationManager.shared
    @State private var selectedTab: AppTab = .home
    @State private var approvalsWeekTrigger: Int = 0
    @State private var parentFocusedStudentId: Int? = nil

    enum AppTab: Hashable {
        case home
        case parentAttendance
        case leaveRequest
        case leaveHistory
        case approvals
        case rollCall
        case eveningStudy
        case students
        case profile
    }

    var body: some View {
        Group {
            if user.isParent {
                parentTabs
            } else if user.isStudent {
                studentTabs
            } else if user.isDutyTeacher {
                dutyTeacherTabs
            } else {
                teacherAdminTabs
            }
        }
        .onAppear {
            selectedTab = defaultTab
        }
        .onChange(of: pushManager.pendingDestination) { _, destination in
            guard let destination else { return }
            route(to: destination)
            pushManager.pendingDestination = nil
        }
    }

    private var defaultTab: AppTab {
        if user.isDutyTeacher { return .eveningStudy }
        return .home
    }

    private var parentTabs: some View {
        TabView(selection: $selectedTab) {
            ParentFeedView(
                user: user,
                onOpenChildDetail: { studentId in
                    parentFocusedStudentId = studentId
                    selectedTab = .parentAttendance
                }
            )
            .tabItem {
                Label("动态", systemImage: "bell.badge")
            }
            .badge(pushManager.unreadCount)
            .tag(AppTab.home)

            ParentChildAttendanceView(
                user: user,
                initialStudentId: parentFocusedStudentId
            )
            .tabItem {
                Label("孩子考勤", systemImage: "calendar.badge.checkmark")
            }
            .tag(AppTab.parentAttendance)

            ProfileView(user: user)
                .tabItem {
                    Label("我的", systemImage: "person.crop.circle")
                }
                .tag(AppTab.profile)
        }
    }

    private var studentTabs: some View {
        TabView(selection: $selectedTab) {
            StudentDashboardView(user: user)
                .tabItem {
                    Label("考勤", systemImage: "calendar.badge.checkmark")
                }
                .tag(AppTab.home)

            LeaveRequestFormView(user: user)
                .tabItem {
                    Label("请假", systemImage: "square.and.pencil")
                }
                .tag(AppTab.leaveRequest)

            LeaveHistoryView(user: user)
                .tabItem {
                    Label("记录", systemImage: "list.bullet.clipboard")
                }
                .tag(AppTab.leaveHistory)

            if user.isRollCallAdminStudent {
                RollCallHubView(user: user)
                    .tabItem {
                        Label("点名", systemImage: "checklist")
                    }
                    .tag(AppTab.rollCall)
            } else if user.isClassAdminStudent {
                LeaveApprovalsView(user: user)
                    .tabItem {
                        Label("审批", systemImage: "checkmark.seal")
                    }
                    .tag(AppTab.approvals)
            }

            ProfileView(user: user)
                .tabItem {
                    Label("我的", systemImage: "person.crop.circle")
                }
                .badge(pushManager.unreadCount)
                .tag(AppTab.profile)
        }
    }

    private var dutyTeacherTabs: some View {
        TabView(selection: $selectedTab) {
            EveningStudyHubView(user: user, forceTakeView: true)
                .tabItem {
                    Label("夜自习点名", systemImage: "moon.stars")
                }
                .tag(AppTab.eveningStudy)

            ProfileView(user: user)
                .tabItem {
                    Label("我的", systemImage: "person.crop.circle")
                }
                .badge(pushManager.unreadCount)
                .tag(AppTab.profile)
        }
    }

    private var teacherAdminTabs: some View {
        TabView(selection: $selectedTab) {
            TeacherDashboardView(
                user: user,
                onOpenApprovals: {
                    approvalsWeekTrigger += 1
                    selectedTab = .approvals
                }
            )
                .tabItem {
                    Label("看板", systemImage: "chart.bar.doc.horizontal")
                }
                .tag(AppTab.home)

            AttendanceOperationsHubView(user: user)
                .tabItem {
                    Label("点名", systemImage: "checklist")
                }
                .tag(AppTab.rollCall)

            if user.hasPermission("leave_requests.approve") || user.isTeacherOrAdmin {
                LeaveApprovalsView(user: user, openCurrentWeekTrigger: approvalsWeekTrigger)
                    .tabItem {
                        Label("审批", systemImage: "checkmark.seal")
                    }
                    .tag(AppTab.approvals)
            }

            StudentListView(user: user)
                .tabItem {
                    Label("学生", systemImage: "person.3")
                }
                .tag(AppTab.students)

            ProfileView(user: user)
                .tabItem {
                    Label("我的", systemImage: "person.crop.circle")
                }
                .badge(pushManager.unreadCount)
                .tag(AppTab.profile)
        }
    }

    private func route(to destination: PushDestination) {
        switch destination {
        case .parentFeed(let studentId):
            parentFocusedStudentId = studentId
            selectedTab = user.isParent ? .home : .profile
        case .parentChildAttendance(let studentId), .parentChildLeaves(let studentId):
            parentFocusedStudentId = studentId
            selectedTab = user.isParent ? .parentAttendance : .profile
        case .leaveApprovals:
            if user.isTeacherOrAdmin || user.isClassAdminStudent {
                selectedTab = .approvals
            } else if user.isParent {
                selectedTab = .home
            } else {
                selectedTab = .leaveHistory
            }
        case .leaveHistory:
            if user.isParent {
                selectedTab = .parentAttendance
            } else {
                selectedTab = user.isStudent ? .leaveHistory : .approvals
            }
        case .studentDashboard:
            selectedTab = .home
        case .notifications:
            selectedTab = user.isParent ? .home : .profile
        }
    }
}

/// 教师/管理员端统一“点名”Tab：通过原生分段控件在「课堂点名」与「夜自习」之间无缝切换
struct AttendanceOperationsHubView: View {
    let user: User
    @State private var segment: Segment = .rollCall

    enum Segment: String, CaseIterable {
        case rollCall = "课堂点名"
        case eveningStudy = "夜自习"
    }

    var body: some View {
        let showEvening = user.hasPermission("evening_study.view") || user.canTakeEveningStudy || user.isTeacherOrAdmin

        if showEvening {
            NavigationStack {
                VStack(spacing: 0) {
                    Picker("点名模式", selection: $segment) {
                        ForEach(Segment.allCases, id: \.self) { seg in
                            Text(seg.rawValue).tag(seg)
                        }
                    }
                    .pickerStyle(.segmented)
                    .padding(.horizontal)
                    .padding(.vertical, 8)
                    .background(SCTheme.background)

                    if segment == .rollCall {
                        RollCallHubContentView(user: user)
                    } else {
                        EveningStudyContentView(user: user, forceTakeView: user.canTakeEveningStudy)
                    }
                }
                .navigationTitle(segment == .rollCall ? "课堂点名" : "夜自习管理")
                .navigationBarTitleDisplayMode(.inline)
            }
        } else {
            RollCallHubView(user: user)
        }
    }
}
