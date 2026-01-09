import React, { useEffect, useState, useMemo } from 'react';
import Layout from '../../components/Layout';
import { Link, useNavigate } from 'react-router-dom';
import axios from 'axios';
import { format } from 'date-fns';
import useAuthStore from '../../store/authStore';
import AttendanceUpdateModal from '../../components/AttendanceUpdateModal';
import StudentCalendar from '../../components/StudentCalendar';
import WechatBindCard from '../../components/WechatBindCard';
import GroupedRecordsList from '../../components/GroupedRecordsList';

/**
 * 格式化节次名称显示 - 将 period IDs 转换为可读的节次名称
 */
const formatPeriodNames = (periodIds, periods, timeSlots = []) => {
    if (!periodIds || periodIds.length === 0 || !periods || periods.length === 0) {
        return '';
    }

    // 标准化 periodIds 为整数数组
    const normalizedIds = periodIds.map(id => parseInt(id)).sort((a, b) => a - b);

    // 优先检查是否匹配某个时段配置
    if (timeSlots && timeSlots.length > 0) {
        for (const slot of timeSlots) {
            const slotPeriodIds = (slot.period_ids || []).map(id => parseInt(id)).sort((a, b) => a - b);
            if (slotPeriodIds.length > 0 &&
                slotPeriodIds.length === normalizedIds.length &&
                slotPeriodIds.every((id, idx) => id === normalizedIds[idx])) {
                return slot.name;
            }
        }
    }

    // 获取节次名称
    const names = periodIds
        .map(id => {
            const period = periods.find(p => p.id === id || p.id === parseInt(id));
            return period?.name;
        })
        .filter(Boolean);

    if (names.length === 0) {
        return '';
    }

    // 智能合并连续的"第X节"
    const numberedPattern = /^第(\d+)节$/;
    const result = [];
    let numberedRun = [];

    const flushNumberedRun = () => {
        if (numberedRun.length === 0) return;
        if (numberedRun.length <= 2) {
            result.push(...numberedRun);
        } else {
            const firstMatch = numberedRun[0].match(numberedPattern);
            const lastMatch = numberedRun[numberedRun.length - 1].match(numberedPattern);
            if (firstMatch && lastMatch) {
                result.push(`第${firstMatch[1]}-${lastMatch[1]}节`);
            } else {
                result.push(...numberedRun);
            }
        }
        numberedRun = [];
    };

    for (const name of names) {
        const match = name.match(numberedPattern);
        if (match) {
            if (numberedRun.length > 0) {
                const lastMatch = numberedRun[numberedRun.length - 1].match(numberedPattern);
                if (lastMatch && parseInt(match[1]) === parseInt(lastMatch[1]) + 1) {
                    numberedRun.push(name);
                } else {
                    flushNumberedRun();
                    numberedRun.push(name);
                }
            } else {
                numberedRun.push(name);
            }
        } else {
            flushNumberedRun();
            result.push(name);
        }
    }
    flushNumberedRun();

    return result.join('、');
};

export default function StudentDashboard() {
    const navigate = useNavigate();
    const { user } = useAuthStore();
    const [events, setEvents] = useState([]);
    const [stats, setStats] = useState({});
    const [leaveTypes, setLeaveTypes] = useState([]);
    const [showAttendanceModal, setShowAttendanceModal] = useState(false);
    const [selectedAttendanceDate, setSelectedAttendanceDate] = useState(new Date());
    const [isClassAdmin, setIsClassAdmin] = useState(false);
    const [loading, setLoading] = useState(true);
    const [scope, setScope] = useState('today'); // today, month, semester
    const [detailModal, setDetailModal] = useState({ isOpen: false, title: '', records: [] });
    const [statsExpanded, setStatsExpanded] = useState(true); // Stats section collapsed state
    const [dashboardConfig, setDashboardConfig] = useState({
        show_my_pending: true,
        show_normal_attendance: true,
        show_all_leave_types: true
    });
    const [periods, setPeriods] = useState([]);
    const [timeSlots, setTimeSlots] = useState([]);

    // Track if initial load is complete
    const initialLoadRef = React.useRef(true);
    const currentScopeRef = React.useRef(scope);

    // Main initialization - runs once on mount
    useEffect(() => {
        const init = async () => {
            try {
                await fetchLeaveTypes();
                await checkClassAdmin();
                await fetchDashboardConfig();
            } catch (error) {
                console.error("Init error:", error);
            } finally {
                setLoading(false);
            }
        };
        init();
        // eslint-disable-next-line
    }, []);

    // Fetch data and stats after leaveTypes AND periods are loaded
    useEffect(() => {
        if (leaveTypes.length > 0 && periods.length > 0) {
            currentScopeRef.current = scope;
            fetchData();
            fetchStats(scope);
            initialLoadRef.current = false;
        }
        // eslint-disable-next-line
    }, [leaveTypes, periods]);

    // Refetch stats when scope changes (after initial load)
    useEffect(() => {
        // Skip if it's the initial load or scope hasn't actually changed
        if (initialLoadRef.current || leaveTypes.length === 0) {
            return;
        }
        // Only refetch if scope actually changed
        if (currentScopeRef.current !== scope) {
            currentScopeRef.current = scope;
            fetchStats(scope);
        }
        // eslint-disable-next-line
    }, [scope]);

    const fetchStats = async (currentScope, retryCount = 0) => {
        try {
            const res = await axios.get('/student/stats', { params: { scope: currentScope } });
            const data = res.data;

            // Validate that we got valid data (working_days should be > 0 for month/semester)
            if (currentScope !== 'today' && data.working_days === 0 && retryCount < 3) {
                console.warn(`Stats returned working_days=0 for scope=${currentScope}, retrying... (${retryCount + 1})`);
                setTimeout(() => fetchStats(currentScope, retryCount + 1), 500);
                return;
            }

            setStats(data);
        } catch (error) {
            console.error("Failed to fetch stats", error);
            // Retry on error
            if (retryCount < 3) {
                setTimeout(() => fetchStats(currentScope, retryCount + 1), 500);
            }
        }
    };

    const handleCardClick = async (statKey, statName) => {
        try {
            const res = await axios.get('/student/details', { params: { scope, status: statKey } });
            setDetailModal({
                isOpen: true,
                title: `${statName}详情`,
                records: res.data.records || [],
                message: res.data.message || ''
            });
        } catch (error) {
            console.error("Failed to fetch details", error);
        }
    };

    const fetchLeaveTypes = async () => {
        try {
            const res = await axios.get('/leave-types');
            setLeaveTypes(res.data.filter(type => type.is_active));
        } catch (error) {
            console.error("Failed to fetch leave types", error);
        }
    };

    const checkClassAdmin = async () => {
        try {
            const res = await axios.get('/student/is-class-admin');
            setIsClassAdmin(res.data.is_class_admin);
        } catch (error) {
            console.error("Failed to check class admin status", error);
            setIsClassAdmin(false);
        }
    };

    const fetchDashboardConfig = async () => {
        try {
            const res = await axios.get('/settings');
            const settingsObj = {};
            res.data.forEach(s => settingsObj[s.key] = s.value);

            // Load periods configuration
            if (settingsObj.attendance_periods) {
                try {
                    const periodsData = typeof settingsObj.attendance_periods === 'string'
                        ? JSON.parse(settingsObj.attendance_periods)
                        : settingsObj.attendance_periods;
                    setPeriods(periodsData || []);
                } catch (e) {
                    console.warn('Failed to parse attendance_periods', e);
                }
            }

            // Load time slots
            try {
                const slotsRes = await axios.get('/time-slots');
                setTimeSlots(slotsRes.data || []);
            } catch (e) {
                console.warn('Failed to fetch time slots', e);
            }

            if (settingsObj.dashboard_stats_config) {
                try {
                    const config = typeof settingsObj.dashboard_stats_config === 'string'
                        ? JSON.parse(settingsObj.dashboard_stats_config)
                        : settingsObj.dashboard_stats_config;
                    // Use student config
                    if (config.student) {
                        setDashboardConfig(prev => ({ ...prev, ...config.student }));
                    }
                } catch (e) {
                    console.warn('Failed to parse dashboard_stats_config', e);
                }
            }
        } catch (error) {
            console.error("Failed to fetch dashboard config", error);
        }
    };

    // Create a map for quick lookup
    const leaveTypeMap = useMemo(() => {
        const map = {};
        leaveTypes.forEach(type => {
            map[type.slug] = type;
        });
        return map;
    }, [leaveTypes]);

    // Color mapping for different types
    const getColorForType = (slug) => {
        const colorMap = {
            'present': '#22c55e',        // green
            'sick_leave': '#8b5cf6',     // purple
            'personal_leave': '#3b82f6', // blue
            'health_leave': '#ec4899',   // pink
            'absent': '#ef4444',         // red
            'late': '#eab308',           // yellow
            'early_leave': '#f97316',    // orange
        };
        return colorMap[slug] || '#6b7280'; // default gray
    };

    const fetchData = async () => {
        try {
            const start = format(new Date(new Date().getFullYear(), new Date().getMonth() - 1, 1), 'yyyy-MM-dd');
            const end = format(new Date(new Date().getFullYear(), new Date().getMonth() + 2, 0), 'yyyy-MM-dd');

            const res = await axios.get(`/calendar?start=${start}&end=${end}`);
            const { attendance, leaves } = res.data;

            const calendarEvents = [];

            // UNIFIED DATA SOURCE: All data comes from attendance_records
            // Including self-applied leaves with approval_status
            if (attendance && Array.isArray(attendance)) {
                attendance.forEach(record => {
                    // Skip present status
                    if (record.status === 'present') return;

                    // Use leave_type from backend if available, otherwise use status-based mapping
                    let title = '';
                    let detailLabel = record.detail_label || '';

                    // Special handling for roll_call source: use detail_label directly as title
                    if (record.source_type === 'roll_call' && detailLabel) {
                        title = detailLabel;
                        detailLabel = ''; // Clear so we don't append again
                    } else {
                        if (record.leave_type) {
                            title = record.leave_type.name;
                        } else if (record.status === 'excused' || record.status === 'leave') {
                            // For excused/leave without leave_type, show generic label
                            title = '请假';
                        } else if (leaveTypeMap[record.status]) {
                            title = leaveTypeMap[record.status].name;
                        } else {
                            // Use status as fallback
                            title = record.status;
                        }

                        // Add details if present (from backend detail_label or parse locally)
                        if (!detailLabel && record.details) {
                            const details = typeof record.details === 'string'
                                ? JSON.parse(record.details)
                                : record.details;

                            // 优先使用自定义的显示标签（用户自定义选择节次时生成）
                            if (details.display_label) {
                                detailLabel = details.display_label;
                                // 如果有节次数量，附加显示
                                if (details.option_periods) {
                                    detailLabel += ` (${details.option_periods}节)`;
                                }
                                // 处理文本输入类型：显示文本 + 节次（如果有）
                            } else if (details.text) {
                                detailLabel = details.text;
                                // 如果有节次信息，添加到文本后面
                                if (details.period_names && Array.isArray(details.period_names) && details.period_names.length > 0) {
                                    detailLabel += '-' + details.period_names.join('、');
                                } else if (details.period_ids && Array.isArray(details.period_ids) && details.period_ids.length > 0) {
                                    const periodNamesStr = formatPeriodNames(details.period_ids, periods, timeSlots);
                                    if (periodNamesStr) {
                                        detailLabel += '-' + periodNamesStr;
                                    }
                                }
                                // 否则使用时段名称（自主请假选择的时段，如"上午"、"下午"）
                            } else if (details.time_slot_name) {
                                detailLabel = details.time_slot_name;
                                // 处理时间（迟到/早退）
                            } else if (details.time) {
                                detailLabel = details.time;
                                // 处理节次信息 - 使用节次名称而不是ID
                            } else if (details.period_ids && details.period_ids.length > 0) {
                                detailLabel = formatPeriodNames(details.period_ids, periods, timeSlots);
                            } else if (details.periods && details.periods.length > 0) {
                                // periods 存储的是 period_id，不是编号
                                detailLabel = formatPeriodNames(details.periods, periods, timeSlots);
                                // 处理 option（上午/下午等）
                            } else if (details.option) {
                                // 优先使用保存的 option_label
                                if (details.option_label) {
                                    detailLabel = details.option_label;
                                } else if (record.leave_type?.input_config) {
                                    const config = typeof record.leave_type.input_config === 'string'
                                        ? JSON.parse(record.leave_type.input_config)
                                        : record.leave_type.input_config;
                                    if (config.options) {
                                        const opt = config.options.find(o =>
                                            (typeof o === 'object' ? o.key : o) === details.option
                                        );
                                        if (opt) {
                                            detailLabel = typeof opt === 'object' ? opt.label : opt;
                                        }
                                    }
                                }
                            }
                        }

                        if (detailLabel) {
                            title += `(${detailLabel})`;
                        }
                    }

                    // For self-applied leaves, add approval status prefix
                    // Format: "待审:病假(xxx)" or "批准:病假(xxx)" or "驳回:病假(xxx)"
                    if (record.is_self_applied) {
                        let statusPrefix = '';
                        if (record.approval_status === 'pending') {
                            statusPrefix = '待审';
                        } else if (record.approval_status === 'approved') {
                            statusPrefix = '批准';
                        } else if (record.approval_status === 'rejected') {
                            statusPrefix = '驳回';
                        }
                        if (statusPrefix) {
                            title = `${statusPrefix}:${title}`;
                        }
                    }

                    // Color: gray for pending, otherwise based on status/leave_type
                    const color = record.approval_status === 'pending'
                        ? '#9ca3af'
                        : (record.leave_type?.color || getColorForType(record.status));

                    calendarEvents.push({
                        title: title,
                        start: record.date,
                        color: color,
                        allDay: true,
                        type: record.leave_type?.name || record.status,
                        // detail 已包含在 title 中，不再重复设置
                        note: record.reason || record.note || '',
                        approvalStatus: record.approval_status,
                        isSelfApplied: record.is_self_applied,
                        recordTime: record.record_time || null
                    });
                });
            }

            // leaves array is now deprecated/empty - all data in attendance

            setEvents(calendarEvents);

            // Calculate Stats dynamically
            const newStats = { present: 0 };
            leaveTypes.forEach(type => {
                newStats[type.slug] = 0;
            });

            if (attendance && Array.isArray(attendance)) {
                attendance.forEach(r => {
                    if (newStats[r.status] !== undefined) {
                        newStats[r.status]++;
                    }
                });
            }

            setStats(newStats);
            setLoading(false);

        } catch (error) {
            console.error("Failed to fetch calendar data", error);
            setLoading(false);
        }
    };

    // Get stats entries for display
    const statsEntries = useMemo(() => {
        const entries = [];
        const leaveTypesConfig = stats._leave_types_config || {};

        // Show "present" if configured - display as "出勤天数/工作日天数"
        if (dashboardConfig.show_normal_attendance && stats.present !== undefined) {
            const presentDays = stats.present || 0;
            const workingDays = stats.working_days || 0;
            entries.push({
                key: 'present',
                name: '正常出勤',
                value: `${presentDays}/${workingDays}`,
                color: 'green'
            });
        }

        // Add all leave types if configured (show even if count is 0)
        // Filter by gender restriction based on student's gender
        if (dashboardConfig.show_all_leave_types) {
            const studentGender = user?.student?.gender;

            leaveTypes
                .filter(type => {
                    // Filter by gender restriction
                    if (!type.gender_restriction || type.gender_restriction === 'all') return true;
                    if (type.gender_restriction === 'female' && studentGender === 'female') return true;
                    if (type.gender_restriction === 'male' && studentGender === 'male') return true;
                    return false;
                })
                .forEach(type => {
                    const colorMap = {
                        'sick_leave': 'purple',
                        'personal_leave': 'blue',
                        'health_leave': 'pink',
                        'absent': 'red',
                        'late': 'yellow',
                        'early_leave': 'orange',
                    };

                    // Get display config for this leave type
                    const typeConfig = leaveTypesConfig[type.slug] || {};
                    const displayUnit = typeConfig.display_unit || '节';
                    const count = stats[type.slug] || 0;

                    entries.push({
                        key: type.slug,
                        name: type.name,
                        value: `${count}${displayUnit}`,
                        color: type.color || colorMap[type.slug] || 'gray'
                    });
                });
        }

        return entries;
    }, [stats, leaveTypes, dashboardConfig]);

    if (loading) {
        return (
            <Layout>
                <div className="flex items-center justify-center h-64">
                    <div className="text-gray-500">加载中...</div>
                </div>
            </Layout>
        );
    }

    return (
        <Layout>
            <div className="grid grid-cols-1 lg:grid-cols-4 gap-6">
                {/* Header with Scope Selector - Collapsible (matching calendar style) */}
                <div className="lg:col-span-4 bg-white rounded-lg shadow">
                    <div
                        className="flex justify-between items-center p-4 cursor-pointer hover:bg-gray-50 transition-colors border-b border-gray-100"
                        onClick={() => setStatsExpanded(!statsExpanded)}
                    >
                        <div className="flex items-center space-x-2">
                            <svg
                                className={`w-5 h-5 text-gray-500 transition-transform ${statsExpanded ? 'rotate-90' : ''}`}
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                            >
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                            </svg>
                            <h3 className="text-lg font-semibold text-gray-800">我的记录</h3>
                        </div>
                        <div className="flex items-center space-x-4">
                            {/* Scope Selector */}
                            <div className="inline-flex rounded-md shadow-sm" role="group" onClick={(e) => e.stopPropagation()}>
                                <button
                                    type="button"
                                    onClick={() => setScope('today')}
                                    className={`px-4 py-2 text-sm font-medium rounded-l-lg border ${scope === 'today'
                                        ? 'bg-indigo-600 text-white border-indigo-600'
                                        : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'
                                        }`}
                                >
                                    今日
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setScope('month')}
                                    className={`px-4 py-2 text-sm font-medium border-t border-b ${scope === 'month'
                                        ? 'bg-indigo-600 text-white border-indigo-600'
                                        : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'
                                        }`}
                                >
                                    本月
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setScope('semester')}
                                    className={`px-4 py-2 text-sm font-medium rounded-r-lg border ${scope === 'semester'
                                        ? 'bg-indigo-600 text-white border-indigo-600'
                                        : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'
                                        }`}
                                >
                                    本学期
                                </button>
                            </div>
                        </div>
                    </div>

                    {/* Stats Cards - Collapsible Content */}
                    {statsExpanded && (
                        <div className="p-4">
                            <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                                {/* Pending Requests Card for Class Admin */}
                                {isClassAdmin && dashboardConfig.show_my_pending !== false && stats.pending_requests !== undefined && (
                                    <Link
                                        to="/teacher/approvals?status=pending"
                                        className="bg-gray-50 p-4 rounded-lg border-l-4 border-amber-500 hover:bg-gray-100 transition cursor-pointer"
                                    >
                                        <div className="text-gray-500 text-sm">待审批请假</div>
                                        <div className="text-2xl font-bold text-amber-600">{stats.pending_requests}</div>
                                    </Link>
                                )}
                                {statsEntries.map(stat => (
                                    <div
                                        key={stat.key}
                                        className={`bg-gray-50 p-4 rounded-lg border-l-4 border-${stat.color}-500 hover:bg-gray-100 transition cursor-pointer`}
                                        onClick={() => handleCardClick(stat.key, stat.name)}
                                    >
                                        <div className="text-gray-500 text-sm">{stat.name}</div>
                                        <div className="text-2xl font-bold">{stat.value}</div>
                                    </div>
                                ))}
                                {/* WeChat Bind Card for Class Admins */}
                                {isClassAdmin && <WechatBindCard />}
                            </div>
                        </div>
                    )}
                </div>


                {/* Content Area: Calendar + Sidebar */}
                <div className="lg:col-span-4 flex flex-col lg:flex-row gap-6">
                    {/* Calendar Section */}
                    <div className="flex-1 min-w-0 overflow-hidden">
                        <StudentCalendar
                            events={events}
                            onDateClick={(dateStr) => {
                                navigate(`/student/request?start=${dateStr}&end=${dateStr}`);
                            }}
                            onDateSelect={(startStr, endStr) => {
                                navigate(`/student/request?start=${startStr}&end=${endStr}`);
                            }}
                        />
                    </div>
                </div>
            </div>

            {/* Attendance Modal - Using teacher's AttendanceUpdateModal */}
            {
                showAttendanceModal && (
                    <AttendanceUpdateModal
                        isOpen={showAttendanceModal}
                        onClose={() => setShowAttendanceModal(false)}
                        date={selectedAttendanceDate}
                        user={user}
                    />
                )
            }

            {/* Detail Modal */}
            {
                detailModal.isOpen && (
                    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                        <div className="bg-white rounded-lg shadow-xl p-6 max-w-md w-full mx-4 max-h-[80vh] overflow-y-auto">
                            <div className="flex justify-between items-center mb-4">
                                <h3 className="text-lg font-bold">{detailModal.title}</h3>
                                <button
                                    onClick={() => setDetailModal({ isOpen: false, title: '', records: [], message: '' })}
                                    className="text-gray-500 hover:text-gray-700"
                                >
                                    <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>

                            {detailModal.message && (
                                <p className="text-gray-600 mb-3">{detailModal.message}</p>
                            )}

                            <GroupedRecordsList
                                records={detailModal.records}
                                emptyText={detailModal.message ? null : '暂无记录'}
                                renderRecord={(record, idx) => {
                                    // 为自主申请的记录添加审批状态前缀
                                    let statusPrefix = '';
                                    let badgeClass = 'bg-blue-100 text-blue-800';

                                    if (record.is_self_applied && record.approval_status) {
                                        if (record.approval_status === 'pending') {
                                            statusPrefix = '待审:';
                                            badgeClass = 'bg-gray-100 text-gray-600';
                                        } else if (record.approval_status === 'approved') {
                                            statusPrefix = '批准:';
                                            badgeClass = 'bg-green-100 text-green-800';
                                        } else if (record.approval_status === 'rejected') {
                                            statusPrefix = '驳回:';
                                            badgeClass = 'bg-red-100 text-red-800';
                                        }
                                    }

                                    return (
                                        <div className="flex justify-between items-start">
                                            <div className="flex-1">
                                                <div className="flex items-center gap-2">
                                                    <span className="font-medium text-gray-800">
                                                        {record.date?.split('T')[0]?.replace(/-/g, '.')}
                                                    </span>
                                                    {record.time && (
                                                        <span className="text-gray-500 text-sm">{record.time}</span>
                                                    )}
                                                </div>
                                                <div className="text-sm text-gray-600 mt-1">
                                                    <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${badgeClass}`}>
                                                        {statusPrefix}{record.type_name}
                                                    </span>
                                                    {record.detail_label && (
                                                        <span className="ml-2 text-gray-500">({record.detail_label})</span>
                                                    )}
                                                </div>
                                                {record.note && (
                                                    <div className="text-sm text-gray-500 mt-1">
                                                        备注: {record.note}
                                                    </div>
                                                )}
                                            </div>
                                        </div>
                                    );
                                }}
                            />

                            <div className="mt-4 flex justify-end">
                                <button
                                    onClick={() => setDetailModal({ isOpen: false, title: '', records: [], message: '' })}
                                    className="px-4 py-2 bg-gray-200 text-gray-700 rounded hover:bg-gray-300"
                                >
                                    关闭
                                </button>
                            </div>
                        </div>
                    </div>
                )
            }
        </Layout >
    );
}
