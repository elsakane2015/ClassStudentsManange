import React, { useEffect, useState } from 'react';
import Layout from '../../components/Layout';
import axios from 'axios';
import { Link, useNavigate } from 'react-router-dom';
import useAuthStore from '../../store/authStore';

import AttendanceCalendar from '../../components/AttendanceCalendar'; // Import
import AttendanceExportModal from '../../components/AttendanceExportModal';
import GroupedRecordsList from '../../components/GroupedRecordsList';

export default function TeacherDashboard() {
    const navigate = useNavigate();
    const { user, hasPermission } = useAuthStore();
    const canApproveLeave = hasPermission('leave_requests.approve');
    const canManageRollCall = hasPermission('roll_calls.manage');
    const canExportAttendance = hasPermission('attendance.export');
    const [stats, setStats] = useState({ total_students: 0, present_count: 0, pending_requests: 0 });
    const [attendanceOverview, setAttendanceOverview] = useState([]);
    const [loading, setLoading] = useState(true);
    const [expandedDepts, setExpandedDepts] = useState({});
    const [expandedClasses, setExpandedClasses] = useState({});
    const [scope, setScope] = useState('today'); // today, week, month, semester
    const [leaveTypes, setLeaveTypes] = useState([]); // Added state
    const [rollCallStats, setRollCallStats] = useState([]); // Roll call stats
    const [semesters, setSemesters] = useState([]); // All semesters for dropdown
    const [selectedSemester, setSelectedSemester] = useState(''); // Selected semester ID (empty = current)
    const [exportModalOpen, setExportModalOpen] = useState(false); // Export modal state
    const [statsExpanded, setStatsExpanded] = useState(true); // Stats section collapsed state

    // Dashboard stats configuration
    const [dashboardConfig, setDashboardConfig] = useState({
        show_pending_approval: true,
        show_student_count: true,
        show_all_leave_types: true
    });

    // 详情Modal状态
    const [detailModal, setDetailModal] = useState({
        isOpen: false,
        title: '',
        students: [],
        type: null
    });

    // 学生详细记录Modal状态
    const [studentDetailModal, setStudentDetailModal] = useState({
        isOpen: false,
        student: null,
        records: []
    });

    const toggleDept = (id) => {
        setExpandedDepts(prev => ({ ...prev, [id]: !prev[id] }));
    };

    const toggleClass = (id) => {
        setExpandedClasses(prev => ({ ...prev, [id]: !prev[id] }));
    };

    const handleToggleManager = async (studentId, currentStatus, e) => {
        e.stopPropagation(); // Prevent row click
        if (!confirm(`确定要${currentStatus ? '取消' : '设置'}该学生为班级管理员吗？`)) return;

        try {
            const res = await axios.post(`/students/${studentId}/toggle-manager`);
            // Update local state
            setAttendanceOverview(prev => prev.map(dept => ({
                ...dept,
                classes: dept.classes.map(cls => ({
                    ...cls,
                    students: cls.students.map(s =>
                        s.id === studentId ? { ...s, is_manager: res.data.is_manager } : s
                    )
                }))
            })));
        } catch (error) {
            console.error("Failed to toggle manager", error);
            alert("设置失败");
        }
    };

    // Fetch leave types and semesters on mount
    useEffect(() => {
        const fallbacks = [
            { id: 'sick', name: '病假' },
            { id: 'personal', name: '事假' }
        ];
        axios.get('/leave-types')
            .then(res => {
                const data = res.data.data || res.data;
                if (Array.isArray(data) && data.length > 0) {
                    setLeaveTypes(data);
                } else {
                    setLeaveTypes(fallbacks);
                }
            })
            .catch(err => {
                console.error(err);
                setLeaveTypes(fallbacks);
            });

        // Fetch semesters for dropdown
        axios.get('/semesters')
            .then(res => {
                const data = res.data.data || res.data;
                if (Array.isArray(data)) {
                    setSemesters(data);
                    // Auto-select current semester
                    const currentSem = data.find(s => s.is_current);
                    if (currentSem) {
                        setSelectedSemester(String(currentSem.id));
                    }
                }
            })
            .catch(err => console.error('Failed to fetch semesters:', err));

        // Fetch dashboard config
        axios.get('/settings')
            .then(res => {
                const settingsObj = {};
                res.data.forEach(s => settingsObj[s.key] = s.value);

                if (settingsObj.dashboard_stats_config) {
                    try {
                        const config = typeof settingsObj.dashboard_stats_config === 'string'
                            ? JSON.parse(settingsObj.dashboard_stats_config)
                            : settingsObj.dashboard_stats_config;
                        // Use class_admin config for teacher/class admin
                        if (config.class_admin) {
                            setDashboardConfig(prev => ({ ...prev, ...config.class_admin }));
                        }
                    } catch (e) {
                        console.warn('Failed to parse dashboard_stats_config', e);
                    }
                }
            })
            .catch(err => console.error('Failed to fetch settings:', err));
    }, []);

    // Fetch data whenever scope or selectedSemester changes
    useEffect(() => {
        const fetchData = async () => {
            console.log('[Dashboard] Starting data fetch, scope:', scope, 'semester:', selectedSemester);
            console.log('[Dashboard] API baseURL:', axios.defaults.baseURL);
            console.log('[Dashboard] Token:', localStorage.getItem('token') ? 'Present' : 'Missing');

            setLoading(true);
            try {
                // Build params with optional semester_id
                const params = { scope };
                if (selectedSemester) {
                    params.semester_id = selectedSemester;
                }

                // Parallel fetch: Stats (scoped) and Overview (always today for the list)
                const [statsRes, overviewRes, rollCallStatsRes] = await Promise.all([
                    axios.get('/attendance/stats', { params }),
                    axios.get('/attendance/overview'),
                    axios.get('/roll-calls/stats', { params }).catch(() => ({ data: [] }))
                ]);


                console.log('[Dashboard] Stats response:', statsRes.data);
                console.log('[Dashboard] Overview response:', overviewRes.data);

                setStats(statsRes.data);
                if (Array.isArray(overviewRes.data)) {
                    console.log("Dashboard Overview Data Loaded:", overviewRes.data.length, "departments");
                    console.log("[Debug] First dept:", overviewRes.data[0]);
                    if (overviewRes.data[0]?.classes?.[0]?.students?.[0]) {
                        console.log("[Debug] First student:", overviewRes.data[0].classes[0].students[0]);
                    }
                    setAttendanceOverview(overviewRes.data);
                } else {
                    console.error("Overview API returned non-array:", overviewRes.data);
                    setAttendanceOverview([]);
                }

                // Set roll call stats
                setRollCallStats(rollCallStatsRes.data || []);
            } catch (error) {
                console.error("[Dashboard] Failed to fetch dashboard data", error);
                console.error("[Dashboard] Error details:", error.response?.data);
            } finally {
                setLoading(false);
            }
        };
        fetchData();
    }, [scope, selectedSemester]);

    // 处理统计卡片点击
    const handleStatCardClick = async (title, status, leaveTypeId = null) => {
        try {
            // 显示加载状态
            setDetailModal({
                isOpen: true,
                title: `${title} - 加载中...`,
                students: [],
                type: status
            });

            // 调用API获取详细数据
            const response = await axios.get('/attendance/details', {
                params: {
                    scope: scope,  // 传递当前scope
                    status: status,
                    leave_type_id: leaveTypeId,
                    semester_id: selectedSemester || undefined  // 传递选中的学期
                }
            });

            console.log('[Detail Modal] API response:', response.data);

            // 更新Modal数据
            setDetailModal({
                isOpen: true,
                title: title,
                students: response.data || [],
                type: status
            });
        } catch (error) {
            console.error('Failed to fetch details:', error);
            setDetailModal({
                isOpen: true,
                title: `${title} - 加载失败`,
                students: [],
                type: status
            });
        }
    };

    // 处理点击学生行查看详细记录（当前状态的记录）
    const handleStudentClick = (student) => {
        console.log('[Student Row Click] Student:', student);
        console.log('[Student Row Click] Showing current status records');

        // 直接显示学生在当前状态下的记录（从student.records获取）
        setStudentDetailModal({
            isOpen: true,
            student: student,
            records: student.records || []
        });
    };

    // 处理点击学生姓名查看所有记录
    const handleStudentNameClick = async (student) => {
        console.log('[Student Name Click] ===== START =====');
        console.log('[Student Name Click] Student object:', student);
        console.log('[Student Name Click] Student.id:', student?.id);
        console.log('[Student Name Click] Student.student_id:', student?.student_id);
        console.log('[Student Name Click] Student.student_no:', student?.student_no);
        console.log('[Student Name Click] Current scope:', scope);

        try {
            const studentId = student.id || student.student_id;
            console.log('[Student Name Click] Using student_id:', studentId);

            if (!studentId) {
                console.error('[Student Name Click] ERROR: No valid student_id found!');
                alert('无法获取学生ID，请刷新页面后重试');
                return;
            }

            // 调用API获取该学生在当前时间范围内的所有考勤记录
            console.log('[Student Name Click] Calling API with params:', {
                student_id: studentId,
                scope: scope
            });

            const apiUrl = 'attendance/student-records';
            console.log('[Student Name Click] Full URL:', apiUrl);
            console.log('[Student Name Click] Axios baseURL:', axios.defaults.baseURL);

            const response = await axios.get(apiUrl, {
                params: {
                    student_id: studentId,
                    scope: scope
                }
            });

            console.log('[Student Name Click] API Response:', response.data);
            console.log('[Student Name Click] Response is array:', Array.isArray(response.data));
            console.log('[Student Name Click] Record Count:', Array.isArray(response.data) ? response.data.length : 0);

            // 确保records是数组
            const records = Array.isArray(response.data) ? response.data : [];

            setStudentDetailModal({
                isOpen: true,
                student: student,
                records: records
            });

            console.log('[Student Name Click] ===== END =====');
        } catch (error) {
            console.error('[Student Name Click] ERROR:', error);
            console.error('[Student Name Click] Error response:', error.response);
            alert('获取学生所有记录失败，请稍后重试');
        }
    };



    const StatusBadge = ({ record }) => {
        const status = record?.status || 'unmarked';
        const styles = {
            present: 'bg-green-100 text-green-800',
            absent: 'bg-red-100 text-red-800', // Warning color
            late: 'bg-yellow-100 text-yellow-800',
            leave: 'bg-blue-100 text-blue-800',
            early_leave: 'bg-orange-100 text-orange-800',
            unmarked: 'bg-gray-100 text-gray-400'
        };

        const labels = {
            present: '出勤',
            absent: '缺勤',
            late: '迟到',
            leave: '请假',
            early_leave: '早退',
            unmarked: '未标记'
        };

        // If 'leave', try to show specific type name
        let label = labels[status] || status;
        if (status === 'leave' && record?.leave_type?.name) {
            label = record.leave_type.name;
        }

        return (
            <span className={`px-2 py-0.5 inline-flex text-xs leading-4 font-medium rounded-full ${styles[status] === undefined ? styles.leave : styles[status]}`}>
                {label}
            </span>
        );
    };

    const scopeLabels = {
        today: '今日',
        week: '本周',
        month: '本月',
        semester: '本学期'
    };

    const StatCard = ({ title, value, icon, color, subtitle, onClick }) => (
        <div
            className={`bg-white overflow-hidden rounded-lg shadow ${onClick ? 'cursor-pointer hover:shadow-lg transition-shadow' : ''}`}
            onClick={onClick}
        >
            <div className="p-5">
                <div className="flex items-center">
                    <div className={`flex-shrink-0 rounded-md p-3 ${color}`}>
                        {icon}
                    </div>
                    <div className="ml-5 w-0 flex-1">
                        <dl>
                            <dt className="truncate text-sm font-medium text-gray-500">{title}</dt>
                            <dd className="text-2xl font-semibold text-gray-900">{value}</dd>
                            {subtitle && <dd className="text-xs text-gray-500 mt-1">{subtitle}</dd>}
                        </dl>
                    </div>
                </div>
            </div>
        </div>
    );

    return (
        <Layout>
            {loading && !stats.total_students ? <div className="p-8 text-center text-gray-500">加载中...</div> : (
                <>
                    {/* 概览 - Collapsible (matching calendar style) */}
                    <div className="bg-white rounded-lg shadow mb-8">
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
                                <h3 className="text-lg font-semibold text-gray-800">概览</h3>
                            </div>
                            <div className="flex items-center space-x-3" onClick={(e) => e.stopPropagation()}>
                                {/* Scope Selector - Button Group Style */}
                                {(() => {
                                    // Check if viewing a historical (non-current) semester
                                    const selectedSem = selectedSemester ? semesters.find(s => String(s.id) === selectedSemester) : null;
                                    const isHistorical = selectedSem && !selectedSem.is_current;

                                    return (
                                        <div className="inline-flex rounded-md shadow-sm" role="group">
                                            <button
                                                type="button"
                                                onClick={() => !isHistorical && setScope('today')}
                                                disabled={isHistorical}
                                                className={`px-4 py-2 text-sm font-medium rounded-l-lg border ${scope === 'today'
                                                    ? 'bg-indigo-600 text-white border-indigo-600 z-10'
                                                    : isHistorical
                                                        ? 'bg-gray-100 text-gray-400 border-gray-200 cursor-not-allowed'
                                                        : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'
                                                    }`}
                                            >
                                                今日数据
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => !isHistorical && setScope('week')}
                                                disabled={isHistorical}
                                                className={`px-4 py-2 text-sm font-medium border-t border-b ${scope === 'week'
                                                    ? 'bg-indigo-600 text-white border-indigo-600 z-10'
                                                    : isHistorical
                                                        ? 'bg-gray-100 text-gray-400 border-gray-200 cursor-not-allowed'
                                                        : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'
                                                    }`}
                                            >
                                                本周数据
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => !isHistorical && setScope('month')}
                                                disabled={isHistorical}
                                                className={`px-4 py-2 text-sm font-medium border-t border-b ${scope === 'month'
                                                    ? 'bg-indigo-600 text-white border-indigo-600 z-10'
                                                    : isHistorical
                                                        ? 'bg-gray-100 text-gray-400 border-gray-200 cursor-not-allowed'
                                                        : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'
                                                    }`}
                                            >
                                                本月数据
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => setScope('semester')}
                                                className={`px-4 py-2 text-sm font-medium rounded-r-lg border ${scope === 'semester'
                                                    ? 'bg-indigo-600 text-white border-indigo-600 z-10'
                                                    : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'
                                                    }`}
                                            >
                                                {isHistorical ? '学期数据' : '本学期数据'}
                                            </button>
                                        </div>
                                    );
                                })()}

                                {/* Semester Selector */}
                                {semesters.length > 0 && (
                                    <select
                                        value={selectedSemester}
                                        onChange={(e) => {
                                            const val = e.target.value;
                                            setSelectedSemester(val);
                                            // If selecting a non-current semester, force semester scope
                                            const selected = semesters.find(s => String(s.id) === val);
                                            if (selected && !selected.is_current) {
                                                setScope('semester');
                                            }
                                        }}
                                        className="rounded-md border-gray-300 shadow-sm text-sm py-2 pl-3 pr-8 border bg-white focus:border-indigo-500 focus:ring-indigo-500"
                                    >
                                        {semesters.map(s => (
                                            <option key={s.id} value={s.id}>
                                                {s.name} {s.is_current && '(当前)'}
                                            </option>
                                        ))}
                                    </select>
                                )}
                            </div>
                        </div>

                        {/* Stats Cards - Collapsible Content */}
                        {statsExpanded && (
                            <div className="p-4">
                                <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                                    {/* 1. Student Total - 分层显示 */}
                                    {dashboardConfig.show_student_count && (
                                        <StatCard
                                            title="学生总数"
                                            value={
                                                // 班主任: 班级/系部/全校
                                                stats.class_total_students !== null && stats.class_total_students !== undefined
                                                    ? `${stats.class_total_students}/${stats.department_total_students}/${stats.school_total_students}`
                                                    // 系部管理员: 系部/全校
                                                    : stats.department_total_students !== null && stats.department_total_students !== undefined
                                                        ? `${stats.department_total_students}/${stats.school_total_students}`
                                                        // 系统管理员: 全校
                                                        : stats.school_total_students || stats.total_students
                                            }
                                            subtitle={
                                                stats.class_total_students !== null && stats.class_total_students !== undefined
                                                    ? '班级/系部/全校'
                                                    : stats.department_total_students !== null && stats.department_total_students !== undefined
                                                        ? '系部/全校'
                                                        : null
                                            }
                                            icon={
                                                <svg className="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                                                </svg>
                                            }
                                            color="bg-indigo-500"
                                        />
                                    )}

                                    {/* 2. Pending Requests - 仅有审批权限的用户显示 */}
                                    {canApproveLeave && dashboardConfig.show_pending_approval && (
                                        <StatCard
                                            title="待审批"
                                            value={stats.pending_requests}
                                            icon={
                                                <svg className="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                </svg>
                                            }
                                            color="bg-yellow-500"
                                            onClick={() => navigate('/teacher/approvals?status=pending')}
                                        />
                                    )}

                                    {/* Dynamic Leave Types (including Absent, Late, Early Leave if they are configured as leave types) */}
                                    {dashboardConfig.show_all_leave_types && leaveTypes.map(type => {
                                        const count = stats.details?.leaves?.[type.name] || '0人/0次';
                                        // Map icons and colors based on name/slug if possible, or generic
                                        let color = 'bg-blue-400'; // Default for generic leave
                                        let iconPath = "M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"; // Generic document icon

                                        if (type.name.includes('迟到')) {
                                            color = 'bg-yellow-500';
                                            iconPath = "M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"; // Clock icon
                                        } else if (type.name.includes('早退')) {
                                            color = 'bg-orange-500';
                                            iconPath = "M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"; // Arrow left icon
                                        } else if (type.name.includes('缺勤') || type.name.includes('旷课')) {
                                            color = 'bg-red-500';
                                            iconPath = "M6 18L18 6M6 6l12 12"; // X icon
                                        } else if (type.name.includes('出勤')) { // If 'present' is also a leave type, though unlikely
                                            color = 'bg-green-500';
                                            iconPath = "M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"; // Checkmark icon
                                        }

                                        // 确定状态类型
                                        let statusType = 'leave';
                                        if (type.name.includes('迟到')) {
                                            statusType = 'late';
                                        } else if (type.name.includes('早退')) {
                                            statusType = 'early_leave';
                                        } else if (type.name.includes('缺勤') || type.name.includes('旷课')) {
                                            statusType = 'absent';
                                        }

                                        return (
                                            <StatCard
                                                key={type.id}
                                                title={`${scopeLabels[scope]}${type.name}`}
                                                value={count}
                                                icon={
                                                    <svg className="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d={iconPath} />
                                                    </svg>
                                                }
                                                color={color}
                                                onClick={(count && (typeof count === 'number' ? count > 0 : true)) ? () => handleStatCardClick(
                                                    `${scopeLabels[scope]}${type.name}`,
                                                    statusType,
                                                    statusType === 'leave' ? type.id : null
                                                ) : null}
                                            />
                                        );
                                    })}

                                    {/* 导出考勤卡片 */}
                                    {canExportAttendance && (
                                        <StatCard
                                            title="导出考勤"
                                            value={
                                                <svg className="h-6 w-6 text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                                                </svg>
                                            }
                                            icon={
                                                <svg className="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                                </svg>
                                            }
                                            color="bg-emerald-500"
                                            subtitle="导出Excel"
                                            onClick={() => setExportModalOpen(true)}
                                        />
                                    )}
                                </div>
                            </div>
                        )}
                    </div>


                    {/* Roll Call Stats Section - 仅有点名权限的用户显示 */}
                    {canManageRollCall && rollCallStats.length > 0 && (
                        <div className="mb-8">
                            <div className="flex items-center justify-between mb-4">
                                <h3 className="text-lg font-medium text-gray-900">点名统计</h3>
                                <Link to="/roll-call" className="text-sm text-indigo-600 hover:text-indigo-800">
                                    查看全部 →
                                </Link>
                            </div>
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                {rollCallStats.map(stat => (
                                    <Link
                                        key={stat.type_id}
                                        to={`/roll-call/history?type_id=${stat.type_id}&scope=${scope}`}
                                        className="bg-white rounded-lg shadow p-4 hover:shadow-md transition-shadow"
                                    >
                                        <div className="flex items-center justify-between">
                                            <h4 className="text-sm font-medium text-gray-500">{stat.type_name}</h4>
                                            <span className="bg-indigo-100 text-indigo-800 text-xs px-2 py-1 rounded-full">
                                                {stat.count}次
                                            </span>
                                        </div>
                                        <div className="mt-2 flex items-baseline">
                                            <span className="text-2xl font-bold text-red-600">{stat.absent_total}</span>
                                            <span className="ml-1 text-sm text-gray-500">人次缺勤</span>
                                        </div>
                                    </Link>
                                ))}
                            </div>
                        </div>
                    )}

                    {/* NEW: Calendar Section */}
                    <AttendanceCalendar user={user} />

                    {/* 学生出勤详情 - 仅对管理员角色显示 */}
                    {['system_admin', 'school_admin', 'department_manager', 'admin', 'manager'].includes(user?.role) && (
                        <>
                            <h3 className="text-lg leading-6 font-medium text-gray-900 mb-4 h-header">学生出勤详情 (今日)</h3>

                            {attendanceOverview.length === 0 ? (
                                <div className="bg-white shadow rounded-lg p-6 text-center text-gray-500">
                                    暂无班级或学生数据
                                </div>
                            ) : (
                                <div className={(['teacher', 'department_manager', 'manager'].includes(user?.role)) ? "grid grid-cols-1 gap-6" : "grid grid-cols-1 md:grid-cols-2 gap-6 align-top"}>
                                    {attendanceOverview.map(dept => (
                                        <div key={dept.id} className="bg-white shadow rounded-lg overflow-hidden h-fit">
                                            <div
                                                className="bg-gray-100 px-4 py-3 border-b border-gray-200 cursor-pointer flex justify-between items-center hover:bg-gray-200 transition-colors"
                                                onClick={() => toggleDept(dept.id)}
                                            >
                                                <h3 className="text-lg font-bold text-gray-800">{dept.name}</h3>
                                                <svg className={`w-5 h-5 text-gray-500 transform transition-transform ${expandedDepts[dept.id] ? 'rotate-180' : ''}`} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
                                                </svg>
                                            </div>

                                            {expandedDepts[dept.id] && (
                                                <div className="p-4">
                                                    {dept.classes && dept.classes.length > 0 ? (
                                                        <div className={(['teacher', 'department_manager', 'manager'].includes(user?.role)) ? "grid grid-cols-1 gap-4" : "grid grid-cols-1 md:grid-cols-2 gap-4"}>
                                                            {dept.classes.map(cls => (
                                                                <div key={cls.id} className="border border-gray-200 rounded-md overflow-hidden h-fit">
                                                                    <div
                                                                        className="bg-gray-50 px-3 py-2 border-b border-gray-200 flex justify-between items-center cursor-pointer hover:bg-gray-100 transition-colors"
                                                                        onClick={() => toggleClass(cls.id)}
                                                                    >
                                                                        <h4 className="font-medium text-gray-700 truncate mr-2" title={cls.name}>{cls.name}</h4>
                                                                        <div className="flex items-center space-x-1 flex-shrink-0">
                                                                            <span className="text-xs text-gray-500 bg-white px-1.5 py-0.5 rounded border">{cls.students ? cls.students.length : 0}人</span>
                                                                            <svg className={`w-4 h-4 text-gray-400 transform transition-transform ${expandedClasses[cls.id] ? 'rotate-180' : ''}`} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
                                                                            </svg>
                                                                        </div>
                                                                    </div>

                                                                    {expandedClasses[cls.id] && (
                                                                        <div className="overflow-x-auto">
                                                                            <table className="min-w-full divide-y divide-gray-200">
                                                                                <thead className="bg-white">
                                                                                    <tr>
                                                                                        <th scope="col" className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">学号</th>
                                                                                        <th scope="col" className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">姓名</th>
                                                                                        <th scope="col" className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">状态</th>
                                                                                    </tr>
                                                                                </thead>
                                                                                <tbody className="bg-white divide-y divide-gray-200">
                                                                                    {cls.students && cls.students.length > 0 ? cls.students.map(student => {
                                                                                        const record = student.attendance && student.attendance.length > 0 ? student.attendance[0] : null;
                                                                                        const status = record ? record.status : null;

                                                                                        return (
                                                                                            <tr key={student.id} className="hover:bg-gray-50">
                                                                                                <td className="px-3 py-2 whitespace-nowrap text-sm text-gray-500 font-mono">
                                                                                                    {student.student_no}
                                                                                                </td>
                                                                                                <td className="px-3 py-2 whitespace-nowrap text-sm font-medium text-gray-900 flex items-center">
                                                                                                    {student.user ? student.user.name : <span className="text-gray-400 italic">No Name</span>}
                                                                                                    {/* Manager Toggle Icon */}
                                                                                                    {(user.role === 'teacher' || user.role === 'admin') && (
                                                                                                        <button
                                                                                                            onClick={(e) => handleToggleManager(student.id, student.is_manager, e)}
                                                                                                            className={`ml-2 p-1 rounded-full hover:bg-gray-100 ${student.is_manager ? 'text-yellow-500' : 'text-gray-300'}`}
                                                                                                            title={student.is_manager ? "取消管理员" : "设为管理员"}
                                                                                                        >
                                                                                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" className="w-4 h-4">
                                                                                                                <path fillRule="evenodd" d="M10.788 3.21c.448-1.077 1.976-1.077 2.424 0l2.082 5.007 5.404.433c1.164.093 1.636 1.545.749 2.305l-4.117 3.527 1.257 5.273c.271 1.136-.964 2.033-1.96 1.425L12 18.354 7.373 21.18c-.996.608-2.231-.29-1.96-1.425l1.257-5.273-4.117-3.527c-.887-.76-.415-2.212.749-2.305l5.404-.433 2.082-5.006z" clipRule="evenodd" />
                                                                                                            </svg>
                                                                                                        </button>
                                                                                                    )}
                                                                                                </td>
                                                                                                <td className="px-3 py-2 whitespace-nowrap">
                                                                                                    <StatusBadge record={record} />
                                                                                                </td>
                                                                                            </tr>
                                                                                        );
                                                                                    }) : (
                                                                                        <tr>
                                                                                            <td colSpan="3" className="px-3 py-4 text-center text-sm text-gray-400">无数据</td>
                                                                                        </tr>
                                                                                    )}
                                                                                </tbody>
                                                                            </table>
                                                                        </div>
                                                                    )}
                                                                </div>
                                                            ))}
                                                        </div>
                                                    ) : (
                                                        <p className="text-sm text-gray-500 italic text-center">暂无班级数据</p>
                                                    )}
                                                </div>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            )}
                        </>
                    )}

                    {/* 详情Modal */}
                    {detailModal.isOpen && (
                        <div className="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50" onClick={() => setDetailModal({ ...detailModal, isOpen: false })}>
                            <div className="relative top-20 mx-auto p-5 border w-11/12 max-w-4xl shadow-lg rounded-md bg-white" onClick={(e) => e.stopPropagation()}>
                                <div className="flex justify-between items-center mb-4">
                                    <h3 className="text-lg font-medium text-gray-900">{detailModal.title} - 详细列表</h3>
                                    <button
                                        onClick={() => setDetailModal({ ...detailModal, isOpen: false })}
                                        className="text-gray-400 hover:text-gray-500"
                                    >
                                        <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    </button>
                                </div>

                                <div className="mt-4">
                                    {detailModal.students.length > 0 ? (
                                        <div className="overflow-x-auto">
                                            <table className="min-w-full divide-y divide-gray-200">
                                                <thead className="bg-gray-50">
                                                    <tr>
                                                        {/* 系部列 - 仅系统管理员/系管理员可见 */}
                                                        {['system_admin', 'school_admin', 'admin'].includes(user?.role) && (
                                                            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">系部</th>
                                                        )}
                                                        {/* 班级列 - 系统管理员/系管理员/系部管理员可见 */}
                                                        {['system_admin', 'school_admin', 'admin', 'department_manager', 'manager'].includes(user?.role) && (
                                                            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">班级</th>
                                                        )}
                                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">学号</th>
                                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">姓名</th>
                                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                            {scope === 'today' ? '详情' : '次数'}
                                                        </th>
                                                    </tr>
                                                </thead>
                                                <tbody className="bg-white divide-y divide-gray-200">
                                                    {detailModal.students.map((student, index) => {
                                                        const records = student.records || [];
                                                        const recordCount = records.length;

                                                        return (
                                                            <tr
                                                                key={index}
                                                                onClick={() => handleStudentClick(student)}
                                                                className="hover:bg-gray-50 cursor-pointer transition-colors"
                                                            >
                                                                {/* 系部列 */}
                                                                {['system_admin', 'school_admin', 'admin'].includes(user?.role) && (
                                                                    <td className="px-4 py-4 whitespace-nowrap text-sm text-gray-500">{student.department || '-'}</td>
                                                                )}
                                                                {/* 班级列 */}
                                                                {['system_admin', 'school_admin', 'admin', 'department_manager', 'manager'].includes(user?.role) && (
                                                                    <td className="px-4 py-4 whitespace-nowrap text-sm text-gray-500">{student.class || '-'}</td>
                                                                )}
                                                                <td className="px-4 py-4 whitespace-nowrap text-sm text-gray-500 font-mono">
                                                                    {student.student_no || 'N/A'}
                                                                </td>
                                                                <td className="px-4 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                                    {student.name || '-'}
                                                                </td>
                                                                <td className="px-4 py-4 text-sm">
                                                                    {scope === 'today' ? (
                                                                        <span className="text-gray-700">
                                                                            {student.detail || '-'}
                                                                        </span>
                                                                    ) : (
                                                                        <span className="px-2 py-1 text-xs font-medium rounded-full bg-gray-100 text-gray-700">
                                                                            {recordCount}次
                                                                        </span>
                                                                    )}
                                                                </td>
                                                            </tr>
                                                        );
                                                    })}
                                                </tbody>
                                            </table>
                                        </div>
                                    ) : (
                                        <p className="text-center text-gray-500 py-4">暂无数据</p>
                                    )}
                                </div>

                                <div className="mt-4 flex justify-between items-center">
                                    <span className="text-sm text-gray-500">
                                        共 {detailModal.students.length} 人，点击行查看详细记录
                                    </span>
                                    <button
                                        onClick={() => setDetailModal({ ...detailModal, isOpen: false })}
                                        className="px-4 py-2 bg-gray-200 text-gray-800 rounded hover:bg-gray-300"
                                    >
                                        关闭
                                    </button>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* 学生详细记录Modal */}
                    {studentDetailModal.isOpen && (
                        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                            <div className="bg-white rounded-lg shadow-xl max-w-4xl w-full mx-4 max-h-[90vh] overflow-y-auto">
                                <div className="p-6">
                                    <h3 className="text-lg font-semibold mb-4">
                                        <span
                                            className="text-blue-600 hover:text-blue-800 cursor-pointer underline"
                                            onClick={() => handleStudentNameClick(studentDetailModal.student)}
                                            title="点击查看该学生的所有考勤记录"
                                        >
                                            {studentDetailModal.student?.name || '-'}
                                        </span>
                                        {' '}的考勤记录
                                        <span className="text-sm text-gray-500 ml-2">
                                            ({studentDetailModal.student?.student_no || 'N/A'})
                                        </span>
                                        <span className="text-sm font-normal text-blue-600 ml-3">
                                            {scope === 'today' ? '今日' :
                                                scope === 'week' ? '本周' :
                                                    scope === 'month' ? '本月' :
                                                        scope === 'semester' ? '本学期' : ''}
                                        </span>
                                    </h3>

                                    <GroupedRecordsList
                                        records={studentDetailModal.records}
                                        emptyText="暂无记录"
                                        renderRecord={(record, index) => {
                                            // 安全解析 details
                                            let details = null;
                                            try {
                                                if (record.details) {
                                                    details = typeof record.details === 'string'
                                                        ? JSON.parse(record.details)
                                                        : record.details;
                                                }
                                            } catch (e) {
                                                console.error('Failed to parse details:', e);
                                            }

                                            let remarkText = '';
                                            let detailText = '';

                                            // 直接使用后端返回的 detail 字段（与第一次交互逻辑完全一致）
                                            if (record.detail) {
                                                remarkText = record.detail;
                                            } else if (record.period_names_display) {
                                                // fallback: 使用 period_names_display
                                                remarkText = record.period_names_display;
                                            }

                                            // 时间
                                            if (details) {
                                                if (details.roll_call_time) {
                                                    detailText = details.roll_call_time;
                                                } else if (details.time) {
                                                    detailText = details.time;
                                                }
                                            }

                                            // 状态显示
                                            let statusText = '';
                                            let statusColor = '';

                                            if ((record.status === 'leave' || record.status === 'excused') && record.leave_type) {
                                                statusText = record.leave_type.name;
                                                statusColor = 'bg-blue-100 text-blue-800';
                                            } else {
                                                const statusMap = {
                                                    'present': '出勤',
                                                    'absent': '旷课',
                                                    'late': '迟到',
                                                    'early_leave': '早退',
                                                    'leave': '请假',
                                                    'excused': '已批假'
                                                };
                                                statusText = statusMap[record.status] || record.status;

                                                if (record.status === 'present') {
                                                    statusColor = 'bg-green-100 text-green-800';
                                                } else if (record.status === 'absent') {
                                                    statusColor = 'bg-red-100 text-red-800';
                                                } else if (record.status === 'late') {
                                                    statusColor = 'bg-yellow-100 text-yellow-800';
                                                } else if (record.status === 'early_leave') {
                                                    statusColor = 'bg-orange-100 text-orange-800';
                                                } else {
                                                    statusColor = 'bg-blue-100 text-blue-800';
                                                }
                                            }

                                            const dateStr = record.date ? record.date.split('T')[0].replace(/-/g, '.') : '-';

                                            return (
                                                <div className="flex items-center justify-between">
                                                    <div className="flex-1">
                                                        <div className="flex items-center gap-2 flex-wrap">
                                                            <span className="font-medium text-gray-800">{dateStr}</span>
                                                            {detailText && (
                                                                <span className="text-gray-500 text-sm">{detailText}</span>
                                                            )}
                                                        </div>
                                                        <div className="flex items-center gap-2 mt-1 flex-wrap">
                                                            <span className={`px-2 py-0.5 rounded text-xs font-medium ${statusColor}`}>
                                                                {statusText}
                                                            </span>
                                                            {remarkText && (
                                                                <span className="text-gray-500 text-sm">({remarkText})</span>
                                                            )}
                                                        </div>
                                                    </div>
                                                </div>
                                            );
                                        }}
                                    />

                                    <div className="mt-4 flex justify-end">
                                        <button
                                            onClick={() => setStudentDetailModal({ isOpen: false, student: null, records: [] })}
                                            className="px-4 py-2 bg-gray-200 text-gray-800 rounded hover:bg-gray-300"
                                        >
                                            关闭
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}
                </>
            )}

            {/* 导出考勤弹窗 */}
            <AttendanceExportModal
                isOpen={exportModalOpen}
                onClose={() => setExportModalOpen(false)}
                scope={scope}
                selectedSemester={selectedSemester}
            />
        </Layout>
    );
}
