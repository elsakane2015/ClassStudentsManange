import React, { useState, useEffect } from 'react';
import axios from 'axios';
import { format, startOfWeek, endOfWeek, startOfMonth, endOfMonth, eachDayOfInterval, isSameMonth, isSameDay, addMonths, subMonths, addWeeks, subWeeks, differenceInCalendarWeeks, parseISO } from 'date-fns';
import { zhCN } from 'date-fns/locale';
import AttendanceUpdateModal from './AttendanceUpdateModal';
import useAuthStore from '../store/authStore';

// Status color mapping
const statusColors = {
    'leave': 'text-yellow-600',
    'excused': 'text-yellow-600',
    'absent': 'text-red-600',
    'late': 'text-orange-500',
    'early_leave': 'text-purple-500',
};

// Detail Modal Component
function CalendarDetailModal({ isOpen, onClose, date, records }) {
    if (!isOpen) return null;

    return (
        <div className="fixed inset-0 z-50 overflow-y-auto">
            <div className="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:p-0">
                <div className="fixed inset-0 transition-opacity" onClick={onClose}>
                    <div className="absolute inset-0 bg-gray-500 opacity-75"></div>
                </div>
                <div className="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
                    <div className="bg-white px-4 pt-5 pb-4 sm:p-6">
                        <div className="flex justify-between items-center mb-4">
                            <h3 className="text-lg font-medium text-gray-900">
                                {date && format(date, 'yyyy年M月d日')} 考勤记录
                            </h3>
                            <button onClick={onClose} className="text-gray-400 hover:text-gray-500">
                                <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                        <div className="max-h-96 overflow-y-auto space-y-2">
                            {records.length === 0 ? (
                                <p className="text-gray-500 text-center py-4">暂无考勤记录</p>
                            ) : (
                                records.map((record, idx) => {
                                    // 生成审批状态前缀（针对自主请假）
                                    let statusPrefix = '';
                                    if (record.is_self_applied) {
                                        if (record.approval_status === 'pending') {
                                            statusPrefix = '待审:';
                                        } else if (record.approval_status === 'approved') {
                                            statusPrefix = '批准:';
                                        } else if (record.approval_status === 'rejected') {
                                            statusPrefix = '驳回:';
                                        }
                                    }

                                    const typeLabel = `${record.type}${record.option ? `(${record.option})` : ''}`;

                                    return (
                                        <div key={idx} className="flex items-center gap-2 p-2 bg-gray-50 rounded text-sm">
                                            <span className={`font-medium ${statusColors[record.status] || 'text-gray-700'}`}>
                                                {statusPrefix}{typeLabel}
                                            </span>
                                            <span className="text-gray-600">：</span>
                                            <span className="text-gray-800">{record.student_no ? `${record.student_no} ` : ''}{record.student_name}</span>
                                            <span className="text-gray-400 ml-auto">{record.time}</span>
                                        </div>
                                    );
                                })
                            )}
                        </div>
                    </div>
                    <div className="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                        <button onClick={onClose} className="w-full sm:w-auto px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">
                            关闭
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}

export default function AttendanceCalendar({ user }) {
    const { hasPermission } = useAuthStore();
    const canViewCalendarSummary = hasPermission('attendance.calendar_summary');

    const [viewMode, setViewMode] = useState('month'); // 'month' or 'week'
    const [currentDate, setCurrentDate] = useState(new Date());
    const [semester, setSemester] = useState(null);
    const [isCollapsed, setIsCollapsed] = useState(false);
    const [attendanceData, setAttendanceData] = useState({});
    const [loadingData, setLoadingData] = useState(false);

    // Modal state
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [selectedDate, setSelectedDate] = useState(null);

    // Detail modal state
    const [isDetailModalOpen, setIsDetailModalOpen] = useState(false);
    const [detailDate, setDetailDate] = useState(null);
    const [detailRecords, setDetailRecords] = useState([]);

    useEffect(() => {
        // Fetch active semester to calculate "Week Number"
        axios.get('/semesters').then(res => {
            // API returns array directly: [...]
            const semesters = Array.isArray(res.data) ? res.data : (res.data.data || []);
            const current = semesters.find(s => s.is_current) || semesters[0];
            setSemester(current);
        });
    }, []);

    // Fetch calendar attendance data when month changes (only if has permission)
    useEffect(() => {
        if (isCollapsed || !canViewCalendarSummary) return;

        const fetchCalendarData = async () => {
            setLoadingData(true);
            try {
                const month = format(currentDate, 'yyyy-MM');
                const res = await axios.get('/attendance/calendar-summary', { params: { month } });
                setAttendanceData(res.data || {});
            } catch (error) {
                console.error('Failed to fetch calendar data:', error);
                setAttendanceData({});
            } finally {
                setLoadingData(false);
            }
        };

        fetchCalendarData();
    }, [currentDate, isCollapsed, canViewCalendarSummary]);

    const getSchoolWeekCheck = (date) => {
        if (!semester) return '';
        const start = parseISO(semester.start_date);
        const diff = differenceInCalendarWeeks(date, start, { weekStartsOn: 1 });

        // Before semester starts
        if (diff < 0) return '-';

        // After semester ends (based on total_weeks)
        const totalWeeks = semester.total_weeks || 20;
        if (diff >= totalWeeks) return '-';

        return `${diff + 1}`;
    };

    // Check if current view is within semester range
    const isWithinSemester = () => {
        if (!semester) return true;
        const start = parseISO(semester.start_date);
        const totalWeeks = semester.total_weeks || 20;
        const semesterEnd = new Date(start);
        semesterEnd.setDate(semesterEnd.getDate() + totalWeeks * 7);

        // Check if current date is within semester
        return currentDate >= start && currentDate <= semesterEnd;
    };

    // Check if a date is a holiday
    const isHoliday = (date) => {
        if (!semester || !semester.holidays) return false;
        const dateStr = format(date, 'yyyy-MM-dd');
        const holidays = Array.isArray(semester.holidays) ? semester.holidays : [];
        return holidays.includes(dateStr);
    };

    const generateCalendarDays = () => {
        const start = viewMode === 'month' ? startOfWeek(startOfMonth(currentDate), { weekStartsOn: 1 }) : startOfWeek(currentDate, { weekStartsOn: 1 });
        const end = viewMode === 'month' ? endOfWeek(endOfMonth(currentDate), { weekStartsOn: 1 }) : endOfWeek(currentDate, { weekStartsOn: 1 });
        return eachDayOfInterval({ start, end });
    };

    const handleDateClick = (day) => {
        setSelectedDate(day);
        setIsModalOpen(true);
    };

    const handleShowMore = (day, records, e) => {
        e.stopPropagation();
        setDetailDate(day);
        setDetailRecords(records);
        setIsDetailModalOpen(true);
    };

    const next = () => {
        if (viewMode === 'month') setCurrentDate(addMonths(currentDate, 1));
        else setCurrentDate(addWeeks(currentDate, 1));
    };

    const prev = () => {
        if (viewMode === 'month') setCurrentDate(subMonths(currentDate, 1));
        else setCurrentDate(subWeeks(currentDate, 1));
    };

    const renderHeader = () => {
        const dateFormat = viewMode === 'month' ? 'yyyy年 M月' : 'yyyy年 M月 (第w周)';
        return (
            <div className="flex justify-between items-center mb-4 bg-white p-4 rounded-lg shadow-sm">
                <div className="flex items-center space-x-4">
                    <button onClick={prev} className="p-1 hover:bg-gray-100 rounded">
                        <svg className="w-5 h-5 text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" /></svg>
                    </button>
                    <span className="text-lg font-bold text-gray-800">{format(currentDate, dateFormat, { locale: zhCN })}</span>
                    <button onClick={next} className="p-1 hover:bg-gray-100 rounded">
                        <svg className="w-5 h-5 text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" /></svg>
                    </button>
                </div>
                <div className="flex bg-gray-100 rounded p-1">
                    <button onClick={() => setViewMode('month')} className={`px-4 py-1.5 rounded text-sm font-medium transition-colors ${viewMode === 'month' ? 'bg-white text-indigo-600 shadow-sm' : 'text-gray-500 hover:text-gray-700'}`}>月视图</button>
                    <button onClick={() => setViewMode('week')} className={`px-4 py-1.5 rounded text-sm font-medium transition-colors ${viewMode === 'week' ? 'bg-white text-indigo-600 shadow-sm' : 'text-gray-500 hover:text-gray-700'}`}>周视图</button>
                </div>
            </div>
        );
    };

    // Render attendance records in a cell
    const renderCellRecords = (day) => {
        if (!canViewCalendarSummary) return null;

        const dateKey = format(day, 'yyyy-MM-dd');
        const records = attendanceData[dateKey] || [];

        if (records.length === 0) return null;

        const maxDisplay = 3;
        const displayRecords = records.slice(0, maxDisplay);
        const remaining = records.length - maxDisplay;

        return (
            <div className="mt-1 space-y-0.5 min-w-0">
                {displayRecords.map((record, idx) => {
                    // 生成审批状态前缀（针对自主请假）
                    let statusPrefix = '';
                    if (record.is_self_applied) {
                        if (record.approval_status === 'pending') {
                            statusPrefix = '待审:';
                        } else if (record.approval_status === 'approved') {
                            statusPrefix = '批准:';
                        } else if (record.approval_status === 'rejected') {
                            statusPrefix = '驳回:';
                        }
                    }

                    const typeLabel = `${record.type}${record.option ? `(${record.option})` : ''}`;
                    const displayText = `${statusPrefix}${typeLabel}: ${record.student_name}`;

                    return (
                        <div
                            key={idx}
                            className={`text-[10px] leading-tight overflow-hidden whitespace-nowrap text-ellipsis ${statusColors[record.status] || 'text-gray-600'}`}
                            title={`${statusPrefix}${typeLabel}: ${record.student_name} ${record.time}`}
                        >
                            {displayText}
                        </div>
                    );
                })}
                {remaining > 0 && (
                    <button
                        onClick={(e) => handleShowMore(day, records, e)}
                        className="text-[10px] text-indigo-600 hover:text-indigo-800 font-medium"
                    >
                        更多 (+{remaining})
                    </button>
                )}
            </div>
        );
    };

    const days = generateCalendarDays();
    const weeks = [];
    let currentWeek = [];
    days.forEach((day, i) => {
        if (i % 7 === 0 && i !== 0) {
            weeks.push(currentWeek);
            currentWeek = [];
        }
        currentWeek.push(day);
    });
    weeks.push(currentWeek);

    return (
        <div className="bg-white rounded-lg shadow mb-8">
            {/* Collapsible Header */}
            <div
                className="flex justify-between items-center p-4 cursor-pointer hover:bg-gray-50 transition-colors border-b border-gray-100"
                onClick={() => setIsCollapsed(!isCollapsed)}
            >
                <div className="flex items-center space-x-2">
                    <svg
                        className={`w-5 h-5 text-gray-500 transition-transform ${isCollapsed ? '' : 'rotate-90'}`}
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                    >
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                    </svg>
                    <h3 className="text-lg font-semibold text-gray-800">日历视图</h3>
                </div>
                <span className="text-sm text-gray-500">
                    {isCollapsed ? '点击展开' : '点击收起'}
                </span>
            </div>

            {!isCollapsed && (
                <div className="p-4">
                    {renderHeader()}
                    <div className="flex flex-col">
                        {/* Week Header */}
                        <div className="grid grid-cols-[auto_repeat(7,1fr)] gap-px border-b border-gray-200 bg-gray-50">
                            <div className="w-16 p-2 text-center text-xs font-semibold text-gray-500 uppercase tracking-wider">周次</div>
                            {['一', '二', '三', '四', '五', '六', '日'].map(d => (
                                <div key={d} className="p-2 text-center text-xs font-semibold text-gray-500 uppercase tracking-wider">周{d}</div>
                            ))}
                        </div>

                        {/* Calendar Body */}
                        <div className="border border-t-0 border-gray-200">
                            {weeks.map((weekDays, weekIdx) => {
                                // School Week Number for this row (based on first day of week)
                                // Use Monday (index 0)
                                const monday = weekDays[0];
                                const schoolWeek = getSchoolWeekCheck(monday);

                                return (
                                    <div key={weekIdx} className="grid grid-cols-[auto_repeat(7,1fr)] min-h-[5rem]">
                                        {/* Sidebar Week Number */}
                                        <div className="w-16 flex items-center justify-center p-2 bg-gray-50 border-r border-b border-gray-100 text-sm font-bold text-gray-400">
                                            {schoolWeek}
                                        </div>
                                        {weekDays.map(day => {
                                            const holiday = isHoliday(day);
                                            const dateKey = format(day, 'yyyy-MM-dd');
                                            const hasRecords = attendanceData[dateKey]?.length > 0;

                                            return (
                                                <div
                                                    key={day.toISOString()}
                                                    className={`relative border-b border-r border-gray-100 p-1 cursor-pointer transition-colors hover:bg-indigo-50 overflow-hidden ${!isSameMonth(day, currentDate) ? 'bg-gray-50/50 text-gray-400' : holiday ? 'bg-red-100 text-red-600' : 'bg-white'}`}
                                                    onClick={() => handleDateClick(day)}
                                                    title={holiday ? '节假日' : ''}
                                                >
                                                    <div className={`flex justify-between items-start`}>
                                                        <span className={`text-sm font-medium ${isSameDay(day, new Date()) ? 'bg-indigo-600 text-white w-6 h-6 rounded-full flex items-center justify-center' : ''}`}>
                                                            {format(day, 'd')}
                                                        </span>
                                                        {holiday && <span className="text-xs text-red-400">休</span>}
                                                    </div>
                                                    {renderCellRecords(day)}
                                                </div>
                                            );
                                        })}
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                </div>
            )}

            {isModalOpen && (
                <AttendanceUpdateModal
                    isOpen={isModalOpen}
                    onClose={() => setIsModalOpen(false)}
                    date={selectedDate}
                    user={user}
                />
            )}

            <CalendarDetailModal
                isOpen={isDetailModalOpen}
                onClose={() => setIsDetailModalOpen(false)}
                date={detailDate}
                records={detailRecords}
            />
        </div>
    );
}
