package com.litotime.smartcampus.navigation

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.FactCheck
import androidx.compose.material.icons.rounded.Assessment
import androidx.compose.material.icons.rounded.CalendarMonth
import androidx.compose.material.icons.rounded.Checklist
import androidx.compose.material.icons.rounded.EditNote
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material.icons.rounded.History
import androidx.compose.material.icons.rounded.Nightlight
import androidx.compose.material.icons.rounded.Notifications
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material3.Badge
import androidx.compose.material3.BadgedBox
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SegmentedButton
import androidx.compose.material3.SegmentedButtonDefaults
import androidx.compose.material3.SingleChoiceSegmentedButtonRow
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.litotime.smartcampus.SmartCampusApp
import com.litotime.smartcampus.core.push.PushDestination
import com.litotime.smartcampus.data.model.EveningStudySessionPayload
import com.litotime.smartcampus.data.model.EveningStudyStatus
import com.litotime.smartcampus.data.model.StudentListItem
import com.litotime.smartcampus.data.model.User
import com.litotime.smartcampus.feature.auth.ServerSetupScreen
import com.litotime.smartcampus.feature.eveningstudy.EveningStudyHubContent
import com.litotime.smartcampus.feature.eveningstudy.EveningStudyTakeScreen
import com.litotime.smartcampus.feature.parent.ParentChildAttendanceScreen
import com.litotime.smartcampus.feature.parent.ParentFeedScreen
import com.litotime.smartcampus.feature.profile.NotificationsScreen
import com.litotime.smartcampus.feature.profile.ProfileScreen
import com.litotime.smartcampus.feature.rollcall.RollCallAdminsScreen
import com.litotime.smartcampus.feature.rollcall.RollCallHubContent
import com.litotime.smartcampus.feature.rollcall.RollCallOperationScreen
import com.litotime.smartcampus.feature.student.ClassAttendanceAdminScreen
import com.litotime.smartcampus.feature.student.LeaveHistoryScreen
import com.litotime.smartcampus.feature.student.LeaveRequestFormScreen
import com.litotime.smartcampus.feature.student.StudentDashboardScreen
import com.litotime.smartcampus.feature.teacher.LeaveApprovalsScreen
import com.litotime.smartcampus.feature.teacher.StudentDetailManageScreen
import com.litotime.smartcampus.feature.teacher.StudentListScreen
import com.litotime.smartcampus.feature.teacher.TeacherDashboardScreen

private enum class TabId(val label: String, val icon: ImageVector) {
    PARENT_FEED("动态", Icons.Rounded.Notifications),
    PARENT_ATTENDANCE("孩子考勤", Icons.Rounded.CalendarMonth),
    STUDENT_HOME("考勤", Icons.Rounded.CalendarMonth),
    LEAVE_REQUEST("请假", Icons.Rounded.EditNote),
    LEAVE_HISTORY("记录", Icons.Rounded.History),
    TEACHER_HOME("看板", Icons.Rounded.Assessment),
    ROLL_CALL("点名", Icons.Rounded.Checklist),
    EVENING_STUDY("夜自习", Icons.Rounded.Nightlight),
    APPROVALS("审批", Icons.AutoMirrored.Rounded.FactCheck),
    STUDENTS("学生", Icons.Rounded.Groups),
    PROFILE("我的", Icons.Rounded.Person)
}

private sealed interface SubRoute {
    data object Notifications : SubRoute
    data object ServerSetup : SubRoute
    data class ParentChildPortal(val studentId: Int?) : SubRoute
    data object ClassAttendanceAdmin : SubRoute
    data object ClassLeaveApprovals : SubRoute
    data class RollCallOperate(val rollCallId: Int) : SubRoute
    data object RollCallAdmins : SubRoute
    data class EveningStudyTake(
        val payload: EveningStudySessionPayload,
        val statuses: List<EveningStudyStatus>,
        val canModify: Boolean
    ) : SubRoute
    data class StudentManageDetail(val student: StudentListItem) : SubRoute
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun RoleBasedHomeScreen(user: User) {
    val container = SmartCampusApp.container
    val unreadCount by container.pushManager.unreadCount.collectAsStateWithLifecycle()
    val pendingDest by container.pushManager.pendingDestination.collectAsStateWithLifecycle()
    val pendingStudentId by container.pushManager.pendingStudentId.collectAsStateWithLifecycle()

    val tabs = remember(user) {
        when {
            user.isParent -> listOf(TabId.PARENT_FEED, TabId.PARENT_ATTENDANCE, TabId.PROFILE)
            user.isStudent -> buildList {
                val isDualAdmin = user.isClassAdminStudent && user.isRollCallAdminStudent
                add(TabId.STUDENT_HOME)
                add(TabId.LEAVE_REQUEST)
                if (isDualAdmin) {
                    add(TabId.APPROVALS)
                } else {
                    add(TabId.LEAVE_HISTORY)
                }
                if (user.isRollCallAdminStudent) {
                    add(TabId.ROLL_CALL)
                } else if (user.isClassAdminStudent) {
                    add(TabId.APPROVALS)
                }
                add(TabId.PROFILE)
            }
            user.isDutyTeacher -> listOf(TabId.EVENING_STUDY, TabId.PROFILE)
            else -> buildList {
                add(TabId.TEACHER_HOME)
                add(TabId.ROLL_CALL)
                if (user.hasPermission("leave_requests.approve") || user.isTeacherOrAdmin) {
                    add(TabId.APPROVALS)
                }
                add(TabId.STUDENTS)
                add(TabId.PROFILE)
            }
        }
    }

    var selectedTab by remember(user.id) { mutableStateOf(tabs.first()) }
    var subRoute by remember { mutableStateOf<SubRoute?>(null) }
    var approvalsWeekTrigger by remember { mutableIntStateOf(0) }
    var classAdminLeaveSegIdx by remember { mutableIntStateOf(0) }
    var parentFocusedStudentId by remember { mutableStateOf<Int?>(null) }

    BackHandler(enabled = subRoute != null) {
        subRoute = null
    }

    LaunchedEffect(pendingDest) {
        val dest = pendingDest ?: return@LaunchedEffect
        val sid = pendingStudentId
        subRoute = null
        when (dest) {
            PushDestination.PARENT_FEED -> {
                parentFocusedStudentId = sid
                if (user.isParent) selectedTab = TabId.PARENT_FEED
                else subRoute = SubRoute.ParentChildPortal(sid)
            }
            PushDestination.PARENT_CHILD_ATTENDANCE, PushDestination.PARENT_CHILD_LEAVES -> {
                parentFocusedStudentId = sid
                if (user.isParent) selectedTab = TabId.PARENT_ATTENDANCE
                else subRoute = SubRoute.ParentChildPortal(sid)
            }
            PushDestination.LEAVE_APPROVALS -> {
                if (TabId.APPROVALS in tabs) {
                    classAdminLeaveSegIdx = 0
                    approvalsWeekTrigger += 1
                    selectedTab = TabId.APPROVALS
                } else {
                    selectedTab = tabs.first()
                }
            }
            PushDestination.LEAVE_HISTORY -> {
                if (user.isClassAdminStudent && user.isRollCallAdminStudent && TabId.APPROVALS in tabs) {
                    classAdminLeaveSegIdx = 1
                    selectedTab = TabId.APPROVALS
                } else {
                    selectedTab = if (TabId.LEAVE_HISTORY in tabs) TabId.LEAVE_HISTORY
                    else if (TabId.APPROVALS in tabs) TabId.APPROVALS
                    else tabs.first()
                }
            }
            PushDestination.STUDENT_DASHBOARD -> {
                selectedTab = tabs.first()
            }
            PushDestination.NOTIFICATIONS -> {
                if (user.isParent) {
                    selectedTab = TabId.PARENT_FEED
                } else {
                    subRoute = SubRoute.Notifications
                    selectedTab = TabId.PROFILE
                }
            }
        }
        container.pushManager.consumePendingDestination()
    }

    // 二级全屏子路由
    when (val currentSub = subRoute) {
        is SubRoute.Notifications -> {
            NotificationsScreen(onBack = { subRoute = null })
            return
        }
        is SubRoute.ServerSetup -> {
            ServerSetupScreen(isInitialSetup = false, onDone = { subRoute = null })
            return
        }
        is SubRoute.ParentChildPortal -> {
            ParentChildAttendanceScreen(
                initialStudentId = currentSub.studentId,
                onBack = { subRoute = null }
            )
            return
        }
        is SubRoute.ClassAttendanceAdmin -> {
            ClassAttendanceAdminScreen(user = user, onBack = { subRoute = null })
            return
        }
        is SubRoute.ClassLeaveApprovals -> {
            LeaveApprovalsScreen(openCurrentWeekTrigger = 1, onBack = { subRoute = null })
            return
        }
        is SubRoute.RollCallOperate -> {
            RollCallOperationScreen(rollCallId = currentSub.rollCallId, onBack = { subRoute = null })
            return
        }
        is SubRoute.RollCallAdmins -> {
            RollCallAdminsScreen(onBack = { subRoute = null })
            return
        }
        is SubRoute.EveningStudyTake -> {
            EveningStudyTakeScreen(
                initialPayload = currentSub.payload,
                statuses = currentSub.statuses,
                canModify = currentSub.canModify,
                onBack = { subRoute = null }
            )
            return
        }
        is SubRoute.StudentManageDetail -> {
            StudentDetailManageScreen(
                student = currentSub.student,
                onBack = { subRoute = null }
            )
            return
        }
        null -> Unit
    }

    Scaffold(
        bottomBar = {
            NavigationBar {
                tabs.forEach { tab ->
                    NavigationBarItem(
                        selected = selectedTab == tab,
                        onClick = { selectedTab = tab },
                        label = { Text(tab.label) },
                        icon = {
                            val showBadge = (tab == TabId.PARENT_FEED || (tab == TabId.PROFILE && !user.isParent)) && unreadCount > 0
                            if (showBadge) {
                                BadgedBox(badge = { Badge { Text(unreadCount.toString()) } }) {
                                    Icon(tab.icon, contentDescription = tab.label)
                                }
                            } else {
                                Icon(tab.icon, contentDescription = tab.label)
                            }
                        }
                    )
                }
            }
        }
    ) { innerPadding ->
        Box(modifier = Modifier.fillMaxSize().padding(innerPadding)) {
            when (selectedTab) {
                TabId.PARENT_FEED -> ParentFeedScreen(
                    user = user,
                    onOpenChildDetail = { studentId ->
                        parentFocusedStudentId = studentId
                        selectedTab = TabId.PARENT_ATTENDANCE
                    }
                )
                TabId.PARENT_ATTENDANCE -> ParentChildAttendanceScreen(
                    initialStudentId = parentFocusedStudentId
                )
                TabId.STUDENT_HOME -> StudentDashboardScreen(
                    user = user,
                    onOpenNotifications = { subRoute = SubRoute.Notifications },
                    onOpenClassAttendanceAdmin = { subRoute = SubRoute.ClassAttendanceAdmin },
                    onOpenClassLeaveApprovals = { subRoute = SubRoute.ClassLeaveApprovals }
                )
                TabId.LEAVE_REQUEST -> LeaveRequestFormScreen(user = user)
                TabId.LEAVE_HISTORY -> LeaveHistoryScreen(user = user)
                TabId.TEACHER_HOME -> TeacherDashboardScreen(
                    user = user,
                    onOpenNotifications = { subRoute = SubRoute.Notifications },
                    onOpenApprovals = {
                        if (TabId.APPROVALS in tabs) {
                            approvalsWeekTrigger += 1
                            selectedTab = TabId.APPROVALS
                        }
                    }
                )
                TabId.ROLL_CALL -> {
                    val showEveningSwitch = !user.isStudent && (user.hasPermission("evening_study.view") || user.canTakeEveningStudy || user.isTeacherOrAdmin)
                    if (showEveningSwitch) {
                        var segmentIdx by remember { mutableIntStateOf(0) }
                        Column(modifier = Modifier.fillMaxSize()) {
                            TopAppBar(title = { Text(if (segmentIdx == 0) "课堂点名" else "夜自习管理") })
                            SingleChoiceSegmentedButtonRow(
                                modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 4.dp)
                            ) {
                                listOf("课堂点名", "夜自习").forEachIndexed { idx, title ->
                                    SegmentedButton(
                                        selected = segmentIdx == idx,
                                        onClick = { segmentIdx = idx },
                                        shape = SegmentedButtonDefaults.itemShape(idx, 2)
                                    ) { Text(title) }
                                }
                            }
                            if (segmentIdx == 0) {
                                RollCallHubContent(
                                    user = user,
                                    onOpenRollCall = { subRoute = SubRoute.RollCallOperate(it) },
                                    onOpenAdmins = { subRoute = SubRoute.RollCallAdmins }
                                )
                            } else {
                                EveningStudyHubContent(
                                    user = user,
                                    forceTakeView = user.canTakeEveningStudy,
                                    onOpenSession = { payload, statuses, canModify ->
                                        subRoute = SubRoute.EveningStudyTake(payload, statuses, canModify)
                                    }
                                )
                            }
                        }
                    } else {
                        Column(modifier = Modifier.fillMaxSize()) {
                            TopAppBar(title = { Text("课堂点名") })
                            RollCallHubContent(
                                user = user,
                                onOpenRollCall = { subRoute = SubRoute.RollCallOperate(it) },
                                onOpenAdmins = { subRoute = SubRoute.RollCallAdmins }
                            )
                        }
                    }
                }
                TabId.EVENING_STUDY -> Column(modifier = Modifier.fillMaxSize()) {
                    TopAppBar(title = { Text("夜自习点名") })
                    EveningStudyHubContent(
                        user = user,
                        forceTakeView = true,
                        onOpenSession = { payload, statuses, canModify ->
                            subRoute = SubRoute.EveningStudyTake(payload, statuses, canModify)
                        }
                    )
                }
                TabId.APPROVALS -> {
                    if (user.isClassAdminStudent && user.isRollCallAdminStudent) {
                        Column(modifier = Modifier.fillMaxSize()) {
                            TopAppBar(title = { Text(if (classAdminLeaveSegIdx == 0) "请假审批" else "我的请假记录") })
                            SingleChoiceSegmentedButtonRow(
                                modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 4.dp)
                            ) {
                                listOf("班级审批", "我的记录").forEachIndexed { idx, title ->
                                    SegmentedButton(
                                        selected = classAdminLeaveSegIdx == idx,
                                        onClick = { classAdminLeaveSegIdx = idx },
                                        shape = SegmentedButtonDefaults.itemShape(idx, 2)
                                    ) { Text(title) }
                                }
                            }
                            if (classAdminLeaveSegIdx == 0) {
                                LeaveApprovalsScreen(
                                    openCurrentWeekTrigger = approvalsWeekTrigger,
                                    showTopBar = false
                                )
                            } else {
                                LeaveHistoryScreen(
                                    user = user,
                                    showTopBar = false,
                                    defaultOwnerScope = "mine"
                                )
                            }
                        }
                    } else {
                        LeaveApprovalsScreen(openCurrentWeekTrigger = approvalsWeekTrigger)
                    }
                }
                TabId.STUDENTS -> StudentListScreen(
                    onOpenStudent = { subRoute = SubRoute.StudentManageDetail(it) }
                )
                TabId.PROFILE -> ProfileScreen(
                    user = user,
                    onOpenNotifications = { subRoute = SubRoute.Notifications },
                    onOpenServerSetup = { subRoute = SubRoute.ServerSetup },
                    onOpenParentPortal = { studentId -> subRoute = SubRoute.ParentChildPortal(studentId) }
                )
            }
        }
    }
}
