import React, { useState, useEffect } from 'react';
import axios from 'axios';
import { format, startOfMonth, endOfMonth, eachDayOfInterval, isWeekend, startOfWeek, endOfWeek, addMonths, subMonths, isSameMonth, isSameDay, isToday, differenceInCalendarWeeks, parseISO } from 'date-fns';
import { zhCN } from 'date-fns/locale';
import Layout from '../../components/Layout';
import AttendanceUpdateModal from '../../components/AttendanceUpdateModal';
import useAuthStore from '../../store/authStore';

export default function ClassAttendancePage() {
    const { user } = useAuthStore();
    const [currentMonth, setCurrentMonth] = useState(new Date());
    const [selectedDate, setSelectedDate] = useState(new Date());
    const [showModal, setShowModal] = useState(false);
    const [semester, setSemester] = useState(null);

    useEffect(() => {
        // Fetch active semester to calculate "Week Number"
        axios.get('/semesters').then(res => {
            const semesters = Array.isArray(res.data) ? res.data : (res.data.data || []);
            const current = semesters.find(s => s.is_current) || semesters[0];
            setSemester(current);
        });
    }, []);

    const getSchoolWeek = (date) => {
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

    // Check if a date is a holiday
    const isHoliday = (date) => {
        if (!semester || !semester.holidays) return false;
        const dateStr = format(date, 'yyyy-MM-dd');
        const holidays = Array.isArray(semester.holidays) ? semester.holidays : [];
        return holidays.includes(dateStr);
    };

    const monthStart = startOfMonth(currentMonth);
    const monthEnd = endOfMonth(currentMonth);
    const calendarStart = startOfWeek(monthStart, { weekStartsOn: 1 });
    const calendarEnd = endOfWeek(monthEnd, { weekStartsOn: 1 });

    const calendarDays = eachDayOfInterval({ start: calendarStart, end: calendarEnd });

    // Group days into weeks
    const weeks = [];
    let currentWeek = [];
    calendarDays.forEach((day, i) => {
        if (i % 7 === 0 && i !== 0) {
            weeks.push(currentWeek);
            currentWeek = [];
        }
        currentWeek.push(day);
    });
    weeks.push(currentWeek);

    const handleDateClick = (day) => {
        setSelectedDate(day);
        setShowModal(true);
    };

    return (
        <Layout>
            <div className="bg-white rounded-lg shadow p-6">
                <div className="flex items-center justify-between mb-6">
                    <h2 className="text-xl font-bold">班级考勤管理</h2>
                    <p className="text-gray-500 text-sm">点击日期进行考勤标记</p>
                </div>

                {/* Month Navigation */}
                <div className="flex items-center justify-between mb-4">
                    <button
                        onClick={() => setCurrentMonth(subMonths(currentMonth, 1))}
                        className="p-2 hover:bg-gray-100 rounded"
                    >
                        <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
                        </svg>
                    </button>
                    <h3 className="text-lg font-semibold">
                        {format(currentMonth, 'yyyy年 M月', { locale: zhCN })}
                    </h3>
                    <button
                        onClick={() => setCurrentMonth(addMonths(currentMonth, 1))}
                        className="p-2 hover:bg-gray-100 rounded"
                    >
                        <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                        </svg>
                    </button>
                </div>

                {/* Calendar Grid with Week Numbers */}
                <div className="flex flex-col">
                    {/* Week Header */}
                    <div className="grid grid-cols-[auto_repeat(7,1fr)] gap-px border-b border-gray-200 bg-gray-50">
                        <div className="w-12 p-2 text-center text-xs font-semibold text-gray-500 uppercase tracking-wider">周次</div>
                        {['周一', '周二', '周三', '周四', '周五', '周六', '周日'].map(day => (
                            <div key={day} className="p-2 text-center text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                {day}
                            </div>
                        ))}
                    </div>

                    {/* Calendar Body */}
                    <div className="border border-t-0 border-gray-200">
                        {weeks.map((weekDays, weekIdx) => {
                            const monday = weekDays[0];
                            const schoolWeek = getSchoolWeek(monday);

                            return (
                                <div key={weekIdx} className="grid grid-cols-[auto_repeat(7,1fr)] min-h-[4rem]">
                                    {/* Sidebar Week Number */}
                                    <div className="w-12 flex items-center justify-center p-2 bg-gray-50 border-r border-b border-gray-100 text-sm font-bold text-gray-400">
                                        {schoolWeek}
                                    </div>
                                    {weekDays.map(day => {
                                        const isCurrentMonth = isSameMonth(day, currentMonth);
                                        const isSelected = isSameDay(day, selectedDate) && showModal;
                                        const isTodayDate = isToday(day);
                                        const isWeekendDay = isWeekend(day);
                                        const holiday = isHoliday(day);

                                        return (
                                            <button
                                                key={day.toISOString()}
                                                onClick={() => handleDateClick(day)}
                                                className={`
                                                    relative border-b border-r border-gray-100 p-2 text-sm transition-colors
                                                    ${!isCurrentMonth ? 'bg-gray-50/50 text-gray-400' : ''}
                                                    ${isWeekendDay && isCurrentMonth ? 'bg-red-50' : ''}
                                                    ${holiday ? 'bg-red-100' : ''}
                                                    ${isTodayDate ? 'ring-2 ring-inset ring-indigo-500' : ''}
                                                    ${isSelected ? 'bg-indigo-600 text-white' : 'hover:bg-indigo-50'}
                                                    ${isCurrentMonth && !isWeekendDay && !holiday && !isTodayDate && !isSelected ? 'bg-white' : ''}
                                                `}
                                            >
                                                <div className="flex justify-between items-start">
                                                    <span className={`${isTodayDate && !isSelected ? 'bg-indigo-600 text-white w-6 h-6 rounded-full flex items-center justify-center' : ''} ${isWeekendDay && isCurrentMonth && !isSelected ? 'text-red-400' : ''}`}>
                                                        {format(day, 'd')}
                                                    </span>
                                                    {holiday && <span className="text-xs text-red-400">休</span>}
                                                </div>
                                            </button>
                                        );
                                    })}
                                </div>
                            );
                        })}
                    </div>
                </div>

                <div className="mt-6 text-sm text-gray-500">
                    <p>• 点击日期可以为班级学生标记考勤状态</p>
                    <p>• 周末日期以红色背景标识</p>
                    <p>• 今天的日期以蓝色边框标识</p>
                    <p>• 左侧数字为学期周次</p>
                </div>
            </div>

            {/* Attendance Modal */}
            <AttendanceUpdateModal
                isOpen={showModal}
                onClose={() => setShowModal(false)}
                date={selectedDate}
                user={user}
            />
        </Layout>
    );
}
