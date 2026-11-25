import React, { useEffect, useMemo, useState } from 'react';
import axios from 'axios';
import { format } from 'date-fns';
import {
    BellAlertIcon,
    CalendarDaysIcon,
    CheckCircleIcon,
    ClockIcon,
    DocumentTextIcon,
    ExclamationTriangleIcon,
    MoonIcon,
    TrashIcon,
    UserGroupIcon,
} from '@heroicons/react/24/outline';
import Layout from '../../components/Layout';
import StudentCalendar from '../../components/StudentCalendar';
import useAuthStore from '../../store/authStore';

const EVENT_BADGE_MAP = {
    parent_leave_submitted: { label: '提交请假', cls: 'bg-blue-100 text-blue-700' },
    parent_leave_approved: { label: '审批通过', cls: 'bg-green-100 text-green-700' },
    parent_leave_rejected: { label: '审批驳回', cls: 'bg-red-100 text-red-700' },
    parent_attendance_marked: { label: '考勤标记', cls: 'bg-amber-100 text-amber-800' },
    parent_evening_study_alert: { label: '夜自习/住宿', cls: 'bg-purple-100 text-purple-700' },
};

const APPROVAL_BADGE = {
    pending: { label: '待审批', cls: 'bg-amber-100 text-amber-800' },
    approved: { label: '已通过', cls: 'bg-green-100 text-green-700' },
    rejected: { label: '已驳回', cls: 'bg-red-100 text-red-700' },
};

const SCOPE_OPTIONS = [
    { key: 'today', label: '今日' },
    { key: 'week', label: '本周' },
    { key: 'month', label: '本月' },
    { key: 'semester', label: '本学期' },
];

const COLOR_HEX_MAP = {
    green: '#22c55e',
    purple: '#8b5cf6',
    blue: '#3b82f6',
    pink: '#ec4899',
    red: '#ef4444',
    yellow: '#eab308',
    orange: '#f97316',
    gray: '#6b7280',
    absent: '#ef4444',
    late: '#eab308',
    early_leave: '#f97316',
    sick_leave: '#8b5cf6',
    personal_leave: '#3b82f6',
};

export default function ParentDashboard({ initialTab = 'feed' }) {
    const { user } = useAuthStore();
    const [activeTab, setActiveTab] = useState(initialTab);
    const [children, setChildren] = useState([]);
    const [selectedChildId, setSelectedChildId] = useState(null);
    const [loadingChildren, setLoadingChildren] = useState(true);

    // Feed / Notifications state
    const [notifications, setNotifications] = useState([]);
    const [unreadCount, setUnreadCount] = useState(0);
    const [notifFilter, setNotifFilter] = useState('all'); // all | unread
    const [loadingNotifs, setLoadingNotifs] = useState(true);

    // Child Attendance state
    const [scope, setScope] = useState('month');
    const [overview, setOverview] = useState(null);
    const [calendarEvents, setCalendarEvents] = useState([]);
    const [leaveRequests, setLeaveRequests] = useState([]);
    const [records, setRecords] = useState([]);
    const [loadingChildData, setLoadingChildData] = useState(false);

    useEffect(() => {
        setActiveTab(initialTab);
    }, [initialTab]);

    const fetchChildren = async () => {
        setLoadingChildren(true);
        try {
            const res = await axios.get('/parent/children');
            const list = res.data?.data || [];
            setChildren(list);
            if (list.length > 0 && !list.some(c => c.id === selectedChildId)) {
                setSelectedChildId(list[0].id);
            }
        } catch (err) {
            console.error('Failed to load linked children', err);
        } finally {
            setLoadingChildren(false);
        }
    };

    const fetchNotifications = async () => {
        setLoadingNotifs(true);
        try {
            const res = await axios.get('/notifications', {
                params: { per_page: 50, unread_only: notifFilter === 'unread' ? 1 : 0 },
            });
            setNotifications(res.data?.data || []);
            setUnreadCount(res.data?.unread_count || 0);
        } catch (err) {
            console.error('Failed to load parent notifications', err);
        } finally {
            setLoadingNotifs(false);
        }
    };

    const fetchChildDetails = async (childId, currentScope) => {
        if (!childId) return;
        setLoadingChildData(true);
        try {
            const start = format(new Date(new Date().getFullYear(), new Date().getMonth() - 1, 1), 'yyyy-MM-dd');
            const end = format(new Date(new Date().getFullYear(), new Date().getMonth() + 2, 0), 'yyyy-MM-dd');

            const [overviewRes, calRes, leaveRes, recRes] = await Promise.all([
                axios.get(`/parent/children/${childId}/overview`, { params: { scope: currentScope } }),
                axios.get(`/parent/children/${childId}/calendar`, { params: { start, end } }),
                axios.get(`/parent/children/${childId}/leave-requests`, { params: { per_page: 30 } }),
                axios.get(`/parent/children/${childId}/records`, { params: { scope: currentScope } }),
            ]);

            setOverview(overviewRes.data);
            setLeaveRequests(leaveRes.data?.data || []);
            setRecords(recRes.data?.data || []);

            const rawAttendance = calRes.data?.attendance || [];
            const mappedEvents = rawAttendance.map(rec => {
                let title = rec.type_name || rec.leave_type?.name || rec.status;
                if (rec.detail_label) {
                    title = `${title}(${rec.detail_label})`;
                }
                if (rec.is_self_applied && rec.approval_status) {
                    const prefix = rec.approval_status === 'pending'
                        ? '待审'
                        : rec.approval_status === 'approved'
                            ? '批准'
                            : '驳回';
                    title = `${prefix}:${title}`;
                }
                const colorKey = rec.approval_status === 'pending'
                    ? 'gray'
                    : (rec.color || rec.leave_type?.color || rec.status);

                return {
                    title,
                    start: rec.date,
                    color: COLOR_HEX_MAP[colorKey] || '#6b7280',
                    allDay: true,
                    type: rec.type_name || rec.status,
                    detail: rec.regular_detail_label || rec.detail_label || null,
                    eveningStudyLabel: rec.evening_study_label || null,
                    eveningDestination: rec.evening_destination || null,
                    note: rec.reason || rec.note || '',
                    approvalStatus: rec.approval_status,
                    isSelfApplied: rec.is_self_applied,
                    recordTime: rec.record_time || null,
                };
            });
            setCalendarEvents(mappedEvents);
        } catch (err) {
            console.error('Failed to load child details', err);
        } finally {
            setLoadingChildData(false);
        }
    };

    useEffect(() => {
        fetchChildren();
    }, []);

    useEffect(() => {
        fetchNotifications();
        const timer = setInterval(fetchNotifications, 30000);
        return () => clearInterval(timer);
    }, [notifFilter]);

    useEffect(() => {
        if (selectedChildId) {
            fetchChildDetails(selectedChildId, scope);
        }
    }, [selectedChildId, scope]);

    const selectedChild = useMemo(
        () => children.find(c => c.id === selectedChildId) || null,
        [children, selectedChildId]
    );

    const visibleNotifications = useMemo(() => {
        if (!selectedChildId || children.length <= 1) return notifications;
        // Show notifications for selected child, plus any notifications without student_id
        return notifications.filter(item => {
            const sid = item.data?.student_id;
            return !sid || Number(sid) === Number(selectedChildId);
        });
    }, [notifications, selectedChildId, children.length]);

    const handleMarkRead = async (item) => {
        if (!item.read_at) {
            try {
                await axios.post(`/notifications/${item.id}/read`);
                setNotifications(prev => prev.map(n => n.id === item.id ? { ...n, read_at: new Date().toISOString() } : n));
                setUnreadCount(prev => Math.max(0, prev - 1));
                window.dispatchEvent(new CustomEvent('notificationsUpdated'));
            } catch (err) {
                console.error(err);
            }
        }

        const sid = Number(item.data?.student_id);
        if (sid && children.some(c => c.id === sid)) {
            setSelectedChildId(sid);
            setActiveTab('attendance');
        }
    };

    const handleMarkAllRead = async () => {
        try {
            await axios.post('/notifications/read-all');
            setNotifications(prev => prev.map(n => ({ ...n, read_at: n.read_at || new Date().toISOString() })));
            setUnreadCount(0);
            window.dispatchEvent(new CustomEvent('notificationsUpdated'));
        } catch (err) {
            console.error(err);
        }
    };

    const handleDeleteNotif = async (e, id) => {
        e.stopPropagation();
        try {
            await axios.delete(`/notifications/${id}`);
            setNotifications(prev => prev.filter(n => n.id !== id));
            window.dispatchEvent(new CustomEvent('notificationsUpdated'));
        } catch (err) {
            console.error(err);
        }
    };

    const statCards = useMemo(() => {
        if (!overview?.stats) return [];
        const stats = overview.stats;
        const leaveTypes = overview.leave_types || [];
        const config = stats._leave_types_config || {};
        const cards = [];

        if (scope === 'today') {
            cards.push({
                key: 'present',
                name: '今日出勤',
                value: stats.is_rest_day ? '今日休息' : ((stats.present || 0) >= 1 ? '正常出勤' : '有缺勤'),
                border: stats.is_rest_day ? 'border-gray-400' : ((stats.present || 0) >= 1 ? 'border-green-500' : 'border-red-500'),
            });
        } else {
            cards.push({
                key: 'present',
                name: '正常出勤',
                value: `${stats.present || 0}/${stats.working_days || 0}天`,
                border: 'border-green-500',
            });
        }

        if ((stats.pending_requests || 0) > 0) {
            cards.push({
                key: 'pending',
                name: '待审批请假',
                value: `${stats.pending_requests}条`,
                border: 'border-amber-500',
            });
        }

        const borderMap = {
            red: 'border-red-500',
            orange: 'border-orange-500',
            yellow: 'border-yellow-500',
            purple: 'border-purple-500',
            pink: 'border-pink-500',
            blue: 'border-blue-500',
            green: 'border-green-500',
        };

        leaveTypes.forEach(lt => {
            const unit = config[lt.slug]?.display_unit || lt.display_unit || '节';
            const val = stats[lt.slug] || 0;
            cards.push({
                key: lt.slug,
                name: lt.name,
                value: scope === 'today' && stats.is_rest_day ? '—' : `${val}${unit}`,
                border: borderMap[lt.color] || 'border-indigo-400',
            });
        });

        return cards;
    }, [overview, scope]);

    return (
        <Layout>
            <div className="space-y-6 px-4 sm:px-0">
                {/* Header & Child Switcher */}
                <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
                    <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                        <div>
                            <h1 className="text-xl font-bold text-gray-900 flex items-center gap-2">
                                <UserGroupIcon className="h-6 w-6 text-indigo-600" />
                                {user?.role === 'parent' ? '家长端 · 孩子动态与考勤' : '我的孩子 · 家长视图'}
                            </h1>
                            <p className="text-sm text-gray-500 mt-1">
                                实时接收孩子请假申请、审批进度、课堂考勤及夜自习异常提醒
                            </p>
                        </div>
                        <div className="inline-flex rounded-lg border border-gray-200 bg-gray-50 p-1 self-start">
                            <button
                                type="button"
                                onClick={() => setActiveTab('feed')}
                                className={`px-4 py-1.5 rounded-md text-sm font-medium transition ${activeTab === 'feed' ? 'bg-indigo-600 text-white shadow-sm' : 'text-gray-600 hover:text-gray-900'}`}
                            >
                                动态推送 {unreadCount > 0 && <span className="ml-1 inline-flex items-center rounded-full bg-red-500 px-1.5 py-0.5 text-xs text-white">{unreadCount}</span>}
                            </button>
                            <button
                                type="button"
                                onClick={() => setActiveTab('attendance')}
                                className={`px-4 py-1.5 rounded-md text-sm font-medium transition ${activeTab === 'attendance' ? 'bg-indigo-600 text-white shadow-sm' : 'text-gray-600 hover:text-gray-900'}`}
                            >
                                孩子考勤与请假
                            </button>
                        </div>
                    </div>

                    {loadingChildren ? (
                        <div className="mt-4 text-sm text-gray-400">正在加载关联学生信息...</div>
                    ) : children.length === 0 ? (
                        <div className="mt-4 rounded-lg bg-amber-50 border border-amber-200 p-4 text-sm text-amber-800">
                            暂未关联到在校学生。请联系班主任确认在学生档案的「家长邮箱」中已正确录入您的邮箱。
                        </div>
                    ) : (
                        <div className="mt-4 flex flex-wrap gap-3">
                            {children.map(child => {
                                const isSelected = child.id === selectedChildId;
                                return (
                                    <button
                                        key={child.id}
                                        type="button"
                                        onClick={() => setSelectedChildId(child.id)}
                                        className={`flex items-center gap-3 rounded-xl border px-4 py-3 text-left transition ${isSelected
                                            ? 'border-indigo-500 bg-indigo-50/70 ring-1 ring-indigo-500'
                                            : 'border-gray-200 bg-white hover:border-indigo-300'
                                            }`}
                                    >
                                        <div className={`h-10 w-10 rounded-full flex items-center justify-center font-bold text-sm ${isSelected ? 'bg-indigo-600 text-white' : 'bg-indigo-100 text-indigo-700'}`}>
                                            {child.name.slice(0, 1)}
                                        </div>
                                        <div>
                                            <div className="flex items-center gap-2">
                                                <span className="font-semibold text-gray-900">{child.name}</span>
                                                {child.is_boarding && <span className="text-xs px-1.5 py-0.5 rounded bg-purple-100 text-purple-700">住宿</span>}
                                                {child.is_graduated && <span className="text-xs px-1.5 py-0.5 rounded bg-gray-100 text-gray-600">已毕业</span>}
                                            </div>
                                            <div className="text-xs text-gray-500 mt-0.5">
                                                {child.class_name} · 学号 {child.student_no || '-'} · 班主任 {child.teacher_name || '-'}
                                            </div>
                                            {(child.pending_leave_count > 0 || child.today_anomaly_count > 0) && (
                                                <div className="flex gap-2 mt-1">
                                                    {child.pending_leave_count > 0 && (
                                                        <span className="text-xs text-amber-700 font-medium">待审请假 {child.pending_leave_count}</span>
                                                    )}
                                                    {child.today_anomaly_count > 0 && (
                                                        <span className="text-xs text-red-600 font-medium">今日动态 {child.today_anomaly_count}</span>
                                                    )}
                                                </div>
                                            )}
                                        </div>
                                    </button>
                                );
                            })}
                        </div>
                    )}
                </div>

                {/* TAB 1: Real-time Notifications Feed */}
                {activeTab === 'feed' && (
                    <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                        <div className="px-5 py-4 border-b border-gray-100 flex flex-wrap items-center justify-between gap-3">
                            <div className="flex items-center gap-2">
                                <BellAlertIcon className="h-5 w-5 text-indigo-600" />
                                <h2 className="font-semibold text-gray-900">孩子实时动态推送</h2>
                                {unreadCount > 0 && (
                                    <span className="rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-semibold text-red-700">
                                        {unreadCount} 条未读
                                    </span>
                                )}
                            </div>
                            <div className="flex items-center gap-3">
                                <div className="inline-flex rounded-md border border-gray-200 text-xs">
                                    <button
                                        type="button"
                                        onClick={() => setNotifFilter('all')}
                                        className={`px-3 py-1.5 rounded-l-md ${notifFilter === 'all' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-50'}`}
                                    >
                                        全部
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setNotifFilter('unread')}
                                        className={`px-3 py-1.5 rounded-r-md ${notifFilter === 'unread' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-50'}`}
                                    >
                                        仅看未读
                                    </button>
                                </div>
                                {unreadCount > 0 && (
                                    <button
                                        type="button"
                                        onClick={handleMarkAllRead}
                                        className="text-xs font-medium text-indigo-600 hover:text-indigo-800"
                                    >
                                        全部标为已读
                                    </button>
                                )}
                                <button
                                    type="button"
                                    onClick={fetchNotifications}
                                    className="text-xs font-medium text-gray-500 hover:text-gray-800"
                                >
                                    刷新
                                </button>
                            </div>
                        </div>

                        {loadingNotifs && visibleNotifications.length === 0 ? (
                            <div className="p-10 text-center text-sm text-gray-400">正在加载推送消息...</div>
                        ) : visibleNotifications.length === 0 ? (
                            <div className="p-12 text-center">
                                <CheckCircleIcon className="mx-auto h-10 w-10 text-gray-300" />
                                <p className="mt-2 text-sm text-gray-500">
                                    {notifFilter === 'unread' ? '暂无未读消息' : '暂无孩子申请或考勤标记推送记录'}
                                </p>
                            </div>
                        ) : (
                            <ul className="divide-y divide-gray-100">
                                {visibleNotifications.map(item => {
                                    const isUnread = !item.read_at;
                                    const badge = EVENT_BADGE_MAP[item.type] || { label: '系统提醒', cls: 'bg-gray-100 text-gray-700' };
                                    return (
                                        <li
                                            key={item.id}
                                            onClick={() => handleMarkRead(item)}
                                            className={`px-5 py-4 cursor-pointer transition hover:bg-indigo-50/40 ${isUnread ? 'bg-indigo-50/25' : 'bg-white'}`}
                                        >
                                            <div className="flex items-start justify-between gap-4">
                                                <div className="flex items-start gap-3 min-w-0">
                                                    <span className={`mt-2 h-2.5 w-2.5 rounded-full flex-shrink-0 ${isUnread ? 'bg-indigo-600' : 'bg-transparent'}`} />
                                                    <div className="min-w-0">
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${badge.cls}`}>
                                                                {badge.label}
                                                            </span>
                                                            <p className={`text-sm ${isUnread ? 'font-semibold text-gray-900' : 'font-medium text-gray-700'}`}>
                                                                {item.title}
                                                            </p>
                                                        </div>
                                                        <p className="mt-1 text-sm text-gray-600 leading-relaxed">{item.body}</p>
                                                        <p className="mt-1.5 text-xs text-gray-400 flex items-center gap-1">
                                                            <ClockIcon className="h-3.5 w-3.5" />
                                                            {item.created_at}
                                                        </p>
                                                    </div>
                                                </div>
                                                <button
                                                    type="button"
                                                    onClick={(e) => handleDeleteNotif(e, item.id)}
                                                    title="删除此条消息"
                                                    className="p-1.5 text-gray-400 hover:text-red-600 rounded-md"
                                                >
                                                    <TrashIcon className="h-4 w-4" />
                                                </button>
                                            </div>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </div>
                )}

                {/* TAB 2: Child Attendance Overview, Calendar & Leave History */}
                {activeTab === 'attendance' && selectedChild && (
                    <div className="space-y-6">
                        {/* Stats Summary */}
                        <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
                            <div className="flex flex-wrap items-center justify-between gap-3 mb-4">
                                <h2 className="font-semibold text-gray-900 flex items-center gap-2">
                                    <CalendarDaysIcon className="h-5 w-5 text-indigo-600" />
                                    {selectedChild.name} · 考勤统计
                                </h2>
                                <div className="inline-flex rounded-md shadow-sm">
                                    {SCOPE_OPTIONS.map((opt, idx) => (
                                        <button
                                            key={opt.key}
                                            type="button"
                                            onClick={() => setScope(opt.key)}
                                            className={`px-3 py-1.5 text-xs font-medium border ${idx === 0 ? 'rounded-l-md' : ''} ${idx === SCOPE_OPTIONS.length - 1 ? 'rounded-r-md' : ''} ${scope === opt.key
                                                ? 'bg-indigo-600 text-white border-indigo-600'
                                                : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'
                                                }`}
                                        >
                                            {opt.label}
                                        </button>
                                    ))}
                                </div>
                            </div>

                            {loadingChildData && !overview ? (
                                <div className="py-6 text-center text-sm text-gray-400">正在加载考勤数据...</div>
                            ) : (
                                <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                    {statCards.map(card => (
                                        <div key={card.key} className={`bg-gray-50 rounded-lg p-3.5 border-l-4 ${card.border}`}>
                                            <div className="text-xs text-gray-500">{card.name}</div>
                                            <div className="text-xl font-bold text-gray-900 mt-1">{card.value}</div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>

                        {/* Read-only Calendar */}
                        <StudentCalendar events={calendarEvents} />

                        {/* Bottom Two Columns: Leave Requests & Marked Attendance Records */}
                        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                            {/* Leave Request History */}
                            <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                                <div className="px-5 py-4 border-b border-gray-100 flex items-center gap-2">
                                    <DocumentTextIcon className="h-5 w-5 text-indigo-600" />
                                    <h3 className="font-semibold text-gray-900">孩子请假申请记录</h3>
                                </div>
                                {leaveRequests.length === 0 ? (
                                    <div className="p-8 text-center text-sm text-gray-400">暂无自主请假申请记录</div>
                                ) : (
                                    <ul className="divide-y divide-gray-100 max-h-[460px] overflow-y-auto">
                                        {leaveRequests.map(req => {
                                            const badge = APPROVAL_BADGE[req.status] || APPROVAL_BADGE.approved;
                                            return (
                                                <li key={`${req.id}_${req.status}`} className="p-4 space-y-1.5">
                                                    <div className="flex items-center justify-between gap-2">
                                                        <div className="flex items-center gap-2">
                                                            <span className="font-semibold text-sm text-gray-900">{req.type_name}</span>
                                                            <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${badge.cls}`}>
                                                                {badge.label}
                                                            </span>
                                                        </div>
                                                        <span className="text-xs text-gray-500">{req.date_range_text}</span>
                                                    </div>
                                                    {req.period_label && (
                                                        <p className="text-xs text-gray-600">时段：{req.period_label}</p>
                                                    )}
                                                    {req.evening_study_label && (
                                                        <p className="text-xs text-purple-700 flex items-center gap-1">
                                                            <MoonIcon className="h-3.5 w-3.5" />
                                                            {req.evening_study_label}{req.destination ? `（去向：${req.destination}）` : ''}
                                                        </p>
                                                    )}
                                                    {req.reason && (
                                                        <p className="text-xs text-gray-600">原因：{req.reason}</p>
                                                    )}
                                                    {req.approver_name && (
                                                        <p className="text-xs text-gray-400">
                                                            审批人：{req.approver_name}{req.rejection_reason ? ` · 驳回理由：${req.rejection_reason}` : ''}
                                                        </p>
                                                    )}
                                                </li>
                                            );
                                        })}
                                    </ul>
                                )}
                            </div>

                            {/* Attendance / Anomaly Records */}
                            <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                                <div className="px-5 py-4 border-b border-gray-100 flex items-center gap-2">
                                    <ExclamationTriangleIcon className="h-5 w-5 text-amber-500" />
                                    <h3 className="font-semibold text-gray-900">考勤与异常标记明细</h3>
                                </div>
                                {records.length === 0 ? (
                                    <div className="p-8 text-center text-sm text-gray-400">当前周期内无异常考勤标记</div>
                                ) : (
                                    <ul className="divide-y divide-gray-100 max-h-[460px] overflow-y-auto">
                                        {records.map(rec => (
                                            <li key={rec.id} className="p-4 flex items-start justify-between gap-3">
                                                <div className="space-y-1">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <span className="font-medium text-sm text-gray-900">{rec.date}</span>
                                                        {rec.record_time && <span className="text-xs text-gray-400">{rec.record_time}</span>}
                                                        <span className="inline-flex items-center rounded-md bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700">
                                                            {rec.type_name}
                                                        </span>
                                                        {rec.scene === 'evening_study' && (
                                                            <span className="inline-flex items-center rounded-md bg-purple-50 px-2 py-0.5 text-xs font-medium text-purple-700">
                                                                夜自习
                                                            </span>
                                                        )}
                                                    </div>
                                                    {rec.detail_label && <p className="text-xs text-gray-600">{rec.detail_label}</p>}
                                                    {(rec.reason || rec.note) && (
                                                        <p className="text-xs text-gray-500">备注：{rec.reason || rec.note}</p>
                                                    )}
                                                </div>
                                                <span className="text-xs text-gray-400 flex-shrink-0">
                                                    {rec.is_self_applied ? '自主申请' : (rec.source_type === 'roll_call' ? '点名标记' : '老师标记')}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </Layout>
    );
}
