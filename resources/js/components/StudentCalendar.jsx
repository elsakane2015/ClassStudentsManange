import React, { useState, useEffect } from 'react';
import axios from 'axios';
import { format, startOfWeek, endOfWeek, startOfMonth, endOfMonth, eachDayOfInterval, isSameMonth, isSameDay, addMonths, subMonths, addWeeks, subWeeks, differenceInCalendarWeeks, parseISO } from 'date-fns';
import { zhCN } from 'date-fns/locale';

export default function StudentCalendar({ events = [], onDateClick, onDateSelect }) {
    const [viewMode, setViewMode] = useState('month'); // 'month' or 'week'
    const [currentDate, setCurrentDate] = useState(new Date());
    const [semester, setSemester] = useState(null);
    const [selectionStart, setSelectionStart] = useState(null);
    const [isCollapsed, setIsCollapsed] = useState(false);
    const [eventDetail, setEventDetail] = useState(null); // For showing event detail modal
    const [expandedDay, setExpandedDay] = useState(null); // For showing all events of a day

    useEffect(() => {
        // Fetch active semester to calculate "Week Number"
        axios.get('/semesters').then(res => {
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
        if (onDateClick) {
            onDateClick(format(day, 'yyyy-MM-dd'));
        }
    };

    const handleMouseDown = (day) => {
        setSelectionStart(day);
    };

    const handleMouseUp = (day) => {
        if (selectionStart && onDateSelect) {
            const startDate = selectionStart < day ? selectionStart : day;
            const endDate = selectionStart < day ? day : selectionStart;
            onDateSelect(format(startDate, 'yyyy-MM-dd'), format(endDate, 'yyyy-MM-dd'));
        }
        setSelectionStart(null);
    };

    const next = () => {
        if (viewMode === 'month') setCurrentDate(addMonths(currentDate, 1));
        else setCurrentDate(addWeeks(currentDate, 1));
    };

    const prev = () => {
        if (viewMode === 'month') setCurrentDate(subMonths(currentDate, 1));
        else setCurrentDate(subWeeks(currentDate, 1));
    };

    const goToToday = () => {
        setCurrentDate(new Date());
    };

    // Get events for a specific date
    const getEventsForDate = (date) => {
        const dateStr = format(date, 'yyyy-MM-dd');
        return events.filter(event => {
            const eventStart = event.start?.split('T')[0] || event.start;
            const eventEnd = event.end?.split('T')[0] || eventStart;
            return dateStr >= eventStart && dateStr <= eventEnd;
        });
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
        <div className="bg-white rounded-lg shadow">
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
                <>
                    {/* Header */}
                    <div className="flex justify-between items-center p-4 border-b">
                        <div className="flex items-center space-x-2">
                            <button onClick={prev} className="p-1 hover:bg-gray-100 rounded">
                                <svg className="w-5 h-5 text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
                                </svg>
                            </button>
                            <button onClick={goToToday} className="px-2 py-1 text-sm text-gray-600 hover:bg-gray-100 rounded">
                                今天
                            </button>
                            <button onClick={next} className="p-1 hover:bg-gray-100 rounded">
                                <svg className="w-5 h-5 text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                                </svg>
                            </button>
                            <span className="text-lg font-bold text-gray-800 ml-2">
                                {format(currentDate, 'yyyy年 M月', { locale: zhCN })}
                            </span>
                        </div>
                        <div className="flex bg-gray-100 rounded p-1">
                            <button
                                onClick={() => setViewMode('month')}
                                className={`px-4 py-1.5 rounded text-sm font-medium transition-colors ${viewMode === 'month' ? 'bg-white text-indigo-600 shadow-sm' : 'text-gray-500 hover:text-gray-700'}`}
                            >
                                月
                            </button>
                            <button
                                onClick={() => setViewMode('week')}
                                className={`px-4 py-1.5 rounded text-sm font-medium transition-colors ${viewMode === 'week' ? 'bg-white text-indigo-600 shadow-sm' : 'text-gray-500 hover:text-gray-700'}`}
                            >
                                周
                            </button>
                        </div>
                    </div>

                    {/* Calendar Grid */}
                    <div className="flex flex-col">
                        {/* Week Header */}
                        <div className="grid grid-cols-[auto_repeat(7,1fr)] gap-px border-b border-gray-200 bg-gray-50">
                            <div className="w-12 p-2 text-center text-xs font-semibold text-gray-500 uppercase tracking-wider">周次</div>
                            {['一', '二', '三', '四', '五', '六', '日'].map(d => (
                                <div key={d} className="p-2 text-center text-xs font-semibold text-gray-500 uppercase tracking-wider">周{d}</div>
                            ))}
                        </div>

                        {/* Calendar Body */}
                        <div className="border border-t-0 border-gray-200">
                            {weeks.map((weekDays, weekIdx) => {
                                const monday = weekDays[0];
                                const schoolWeek = getSchoolWeekCheck(monday);

                                return (
                                    <div key={weekIdx} className="grid grid-cols-[auto_repeat(7,1fr)] min-h-[5rem]">
                                        {/* Sidebar Week Number */}
                                        <div className="w-12 flex items-center justify-center p-2 bg-gray-50 border-r border-b border-gray-100 text-sm font-bold text-gray-400">
                                            {schoolWeek}
                                        </div>
                                        {weekDays.map(day => {
                                            const dayEvents = getEventsForDate(day);
                                            const isToday = isSameDay(day, new Date());
                                            const isCurrentMonth = isSameMonth(day, currentDate);
                                            const holiday = isHoliday(day);

                                            return (
                                                <div
                                                    key={day.toISOString()}
                                                    className={`relative border-b border-r border-gray-100 p-1 cursor-pointer transition-colors hover:bg-indigo-50 min-w-0 overflow-hidden ${!isCurrentMonth ? 'bg-gray-50/50 text-gray-400' : holiday ? 'bg-red-100 text-red-600' : 'bg-white'}`}
                                                    onClick={() => handleDateClick(day)}
                                                    onMouseDown={() => handleMouseDown(day)}
                                                    onMouseUp={() => handleMouseUp(day)}
                                                    title={holiday ? '节假日' : ''}
                                                >
                                                    <div className="flex justify-between items-start mb-1">
                                                        <span className={`text-sm font-medium ${isToday ? 'bg-indigo-600 text-white w-6 h-6 rounded-full flex items-center justify-center' : ''}`}>
                                                            {format(day, 'd')}
                                                        </span>
                                                        {holiday && <span className="text-xs text-red-400">休</span>}
                                                    </div>
                                                    {/* Events */}
                                                    <div className="space-y-0.5 overflow-hidden min-w-0">
                                                        {dayEvents.slice(0, 3).map((event, idx) => (
                                                            <div
                                                                key={idx}
                                                                className="text-xs px-1 py-0.5 rounded text-white cursor-pointer hover:opacity-80 transition-opacity overflow-hidden whitespace-nowrap text-ellipsis max-w-full"
                                                                style={{ backgroundColor: event.color || '#6366f1' }}
                                                                title="点击查看详情"
                                                                onClick={(e) => {
                                                                    e.stopPropagation();
                                                                    e.preventDefault();
                                                                    // Use the clicked day's date, not event.start
                                                                    setEventDetail({
                                                                        ...event,
                                                                        displayDate: format(day, 'yyyy.MM.dd')
                                                                    });
                                                                }}
                                                                onMouseDown={(e) => e.stopPropagation()}
                                                                onMouseUp={(e) => e.stopPropagation()}
                                                            >
                                                                {event.title}
                                                            </div>
                                                        ))}
                                                        {dayEvents.length > 3 && (
                                                            <div
                                                                className="text-xs text-indigo-600 hover:text-indigo-800 cursor-pointer hover:underline"
                                                                onClick={(e) => {
                                                                    e.stopPropagation();
                                                                    e.preventDefault();
                                                                    setExpandedDay({
                                                                        date: day,
                                                                        events: dayEvents
                                                                    });
                                                                }}
                                                                onMouseDown={(e) => e.stopPropagation()}
                                                                onMouseUp={(e) => e.stopPropagation()}
                                                            >
                                                                +{dayEvents.length - 3}更多
                                                            </div>
                                                        )}
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                </>
            )}

            {/* Event Detail Modal */}
            {eventDetail && (
                <div
                    className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50"
                    onClick={() => setEventDetail(null)}
                >
                    <div
                        className="bg-white rounded-lg shadow-xl p-6 max-w-md w-full mx-4"
                        onClick={(e) => e.stopPropagation()}
                    >
                        <div className="flex justify-between items-center mb-4">
                            <h3 className="text-lg font-bold">考勤详情</h3>
                            <button
                                onClick={() => setEventDetail(null)}
                                className="text-gray-500 hover:text-gray-700"
                            >
                                <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        <div className="space-y-3">
                            <div className="flex items-center">
                                <span
                                    className="w-4 h-4 rounded-full mr-3"
                                    style={{ backgroundColor: eventDetail.color || '#6366f1' }}
                                ></span>
                                <span className="font-medium text-lg">{eventDetail.title}</span>
                            </div>

                            <div className="border-t pt-3 space-y-2">
                                <div className="flex justify-between">
                                    <span className="text-gray-500">日期</span>
                                    <span className="font-medium">
                                        {eventDetail.displayDate}
                                        {eventDetail.recordTime && <span className="text-gray-500 ml-2">{eventDetail.recordTime}</span>}
                                    </span>
                                </div>
                                {eventDetail.type && (
                                    <div className="flex justify-between">
                                        <span className="text-gray-500">类型</span>
                                        <span className="font-medium">{eventDetail.type}</span>
                                    </div>
                                )}
                                {eventDetail.detail && (
                                    <div className="flex justify-between">
                                        <span className="text-gray-500">详情</span>
                                        <span className="font-medium">{eventDetail.detail}</span>
                                    </div>
                                )}
                                {eventDetail.note && (
                                    <div className="border-t pt-2 mt-2">
                                        <span className="text-gray-500 block mb-1">备注</span>
                                        <p className="text-sm text-gray-700">{eventDetail.note}</p>
                                    </div>
                                )}
                            </div>
                        </div>

                        <div className="mt-4 flex justify-end">
                            <button
                                onClick={() => setEventDetail(null)}
                                className="px-4 py-2 bg-indigo-600 text-white rounded hover:bg-indigo-700"
                            >
                                关闭
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* Expanded Day Modal - Show all events for a day */}
            {expandedDay && (
                <div
                    className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50"
                    onClick={() => setExpandedDay(null)}
                >
                    <div
                        className="bg-white rounded-lg shadow-xl p-6 max-w-md w-full mx-4 max-h-[80vh] overflow-y-auto"
                        onClick={(e) => e.stopPropagation()}
                    >
                        <div className="flex justify-between items-center mb-4">
                            <h3 className="text-lg font-bold">
                                {format(expandedDay.date, 'yyyy年M月d日', { locale: zhCN })} 考勤记录
                            </h3>
                            <button
                                onClick={() => setExpandedDay(null)}
                                className="text-gray-500 hover:text-gray-700"
                            >
                                <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        <div className="space-y-2">
                            {expandedDay.events.map((event, idx) => (
                                <div
                                    key={idx}
                                    className="p-3 rounded-lg cursor-pointer hover:opacity-90 transition-opacity border-l-4"
                                    style={{
                                        backgroundColor: '#f9fafb',  // Light gray background
                                        borderLeftColor: event.color || '#6366f1'
                                    }}
                                    onClick={() => {
                                        setExpandedDay(null);
                                        setEventDetail({
                                            ...event,
                                            displayDate: format(expandedDay.date, 'yyyy.MM.dd')
                                        });
                                    }}
                                >
                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center">
                                            <span
                                                className="w-3 h-3 rounded-full mr-2 flex-shrink-0"
                                                style={{ backgroundColor: event.color || '#6366f1' }}
                                            ></span>
                                            <span className="font-medium text-gray-800">
                                                {event.title}
                                            </span>
                                        </div>
                                        {event.recordTime && (
                                            <span className="text-sm text-gray-500 ml-2">
                                                {event.recordTime}
                                            </span>
                                        )}
                                    </div>
                                    {event.detail && (
                                        <p className="text-sm text-gray-600 mt-1 ml-5">{event.detail}</p>
                                    )}
                                </div>
                            ))}
                        </div>

                        <div className="mt-4 flex justify-end">
                            <button
                                onClick={() => setExpandedDay(null)}
                                className="px-4 py-2 bg-gray-200 text-gray-700 rounded hover:bg-gray-300"
                            >
                                关闭
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
