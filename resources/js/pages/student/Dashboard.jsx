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
    const [scope, setScope] = useState('month'); // today, month, semester
    const [detailModal, setDetailModal] = useState({ isOpen: false, title: '', records: [] });
    const [statsExpanded, setStatsExpanded] = useState(true); // Stats section collapsed state

    useEffect(() => {
        const init = async () => {
            await fetchLeaveTypes();
            await checkClassAdmin();
        };
        init();
        // eslint-disable-next-line
    }, []);

    // Fetch data when leaveTypes is loaded or scope changes
    useEffect(() => {
        if (leaveTypes.length > 0) {
            fetchData();
        }
        // eslint-disable-next-line
    }, [leaveTypes]);

    // Fetch stats when scope changes or class admin status is confirmed
    useEffect(() => {
        if (leaveTypes.length > 0) {
            fetchStats();
        }
        // eslint-disable-next-line
    }, [leaveTypes, scope, isClassAdmin]);

    const fetchStats = async (retryCount = 0) => {
        try {
            const res = await axios.get('/student/stats', { params: { scope } });
            const data = res.data;

            // Validate that we got valid data (working_days should be > 0 for month/semester)
            if (scope !== 'today' && data.working_days === 0 && retryCount < 3) {
                console.warn(`Stats returned working_days=0 for scope=${scope}, retrying... (${retryCount + 1})`);
                setTimeout(() => fetchStats(retryCount + 1), 500);
                return;
            }

            setStats(data);
        } catch (error) {
            console.error("Failed to fetch stats", error);
            // Retry on error
            if (retryCount < 3) {
                setTimeout(() => fetchStats(retryCount + 1), 500);
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
                                // 否则使用时段名称（自主请假选择的时段，如"上午"、"下午"）
                            } else if (details.time_slot_name) {
                                detailLabel = details.time_slot_name;
                                // 处理时间（迟到/早退）
                            } else if (details.time) {
                                detailLabel = details.time;
                                // 处理节次信息（旷课等）
                            } else if (details.period_numbers && details.period_numbers.length > 0) {
                                detailLabel = `第${details.period_numbers.join(',')}节`;
                            } else if (details.periods && details.periods.length > 0) {
                                detailLabel = `第${details.periods.join(',')}节`;
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

        // Always show "present" first - display as "出勤天数/工作日天数"
        if (stats.present !== undefined) {
            const presentDays = stats.present || 0;
            const workingDays = stats.working_days || 0;
            entries.push({
                key: 'present',
                name: '正常出勤',
                value: `${presentDays}/${workingDays}`,
                color: 'green'
            });
        }

        // Add all leave types (show even if count is 0)
        leaveTypes.forEach(type => {
            const colorMap = {
                'sick_leave': 'purple',
                'personal_leave': 'blue',
                'health_leave': 'pink',
                'absent': 'red',
                'late': 'yellow',
                'early_leave': 'orange',
            };
            entries.push({
                key: type.slug,
                name: type.name,
                value: stats[type.slug] || 0,
                color: type.color || colorMap[type.slug] || 'gray'
            });
        });

        return entries;
    }, [stats, leaveTypes]);

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
                                {isClassAdmin && stats.pending_requests !== undefined && (
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
                                renderRecord={(record, idx) => (
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
                                                <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">
                                                    {record.type_name}
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
                                )}
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
