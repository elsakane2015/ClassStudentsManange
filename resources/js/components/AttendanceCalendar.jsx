import React, { useState, useEffect } from 'react';
import axios from 'axios';
import { format, startOfWeek, endOfWeek, startOfMonth, endOfMonth, eachDayOfInterval, isSameMonth, isSameDay, addMonths, subMonths, addWeeks, subWeeks, differenceInCalendarWeeks, parseISO } from 'date-fns';
import { zhCN } from 'date-fns/locale';
import AttendanceUpdateModal from './AttendanceUpdateModal';

export default function AttendanceCalendar({ user }) {
    const [viewMode, setViewMode] = useState('month'); // 'month' or 'week'
    const [currentDate, setCurrentDate] = useState(new Date());
    const [semester, setSemester] = useState(null);
    const [isCollapsed, setIsCollapsed] = useState(false);

    // Modal state
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [selectedDate, setSelectedDate] = useState(null);

    useEffect(() => {
        // Fetch active semester to calculate "Week Number"
        axios.get('/semesters').then(res => {
            // API returns array directly: [...]
            const semesters = Array.isArray(res.data) ? res.data : (res.data.data || []);
            const current = semesters.find(s => s.is_current) || semesters[0];
            setSemester(current);
        });
    }, []);

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
                                    <div key={weekIdx} className="grid grid-cols-[auto_repeat(7,1fr)] min-h-[6rem]">
                                        {/* Sidebar Week Number */}
                                        <div className="w-16 flex items-center justify-center p-2 bg-gray-50 border-r border-b border-gray-100 text-sm font-bold text-gray-400">
                                            {schoolWeek}
                                        </div>
                                        {weekDays.map(day => {
                                            const holiday = isHoliday(day);
                                            return (
                                                <div
                                                    key={day.toISOString()}
                                                    className={`relative border-b border-r border-gray-100 p-2 cursor-pointer transition-colors hover:bg-indigo-50 ${!isSameMonth(day, currentDate) ? 'bg-gray-50/50 text-gray-400' : holiday ? 'bg-red-100 text-red-600' : 'bg-white'}`}
                                                    onClick={() => handleDateClick(day)}
                                                    title={holiday ? '节假日' : ''}
                                                >
                                                    <div className={`flex justify-between items-start`}>
                                                        <span className={`text-sm font-medium ${isSameDay(day, new Date()) ? 'bg-indigo-600 text-white w-6 h-6 rounded-full flex items-center justify-center' : ''}`}>
                                                            {format(day, 'd')}
                                                        </span>
                                                        {holiday && <span className="text-xs text-red-400">休</span>}
                                                    </div>
                                                    {/* Optional: Show dot or status summary if we had that data loaded */}
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
        </div>
    );
}
