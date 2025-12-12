import React, { useEffect, useState } from 'react';
import Layout from '../../components/Layout';
import FullCalendar from '@fullcalendar/react';
import dayGridPlugin from '@fullcalendar/daygrid';
import interactionPlugin from '@fullcalendar/interaction';
import axios from 'axios';
import { format } from 'date-fns';

export default function StudentDashboard() {
    const [events, setEvents] = useState([]);
    const [stats, setStats] = useState({ present: 0, absent: 0, late: 0, excused: 0 });

    useEffect(() => {
        fetchData();
    }, []);

    const fetchData = async () => {
        try {
            // Fetch for current month range (simplified to fetch all recent)
            // Ideally should be dynamic based on calendar view dates
            const start = format(new Date(new Date().getFullYear(), new Date().getMonth() - 1, 1), 'yyyy-MM-dd');
            const end = format(new Date(new Date().getFullYear(), new Date().getMonth() + 2, 0), 'yyyy-MM-dd');

            const res = await axios.get(`/calendar?start=${start}&end=${end}`);

            const { attendance, leaves } = res.data;

            const calendarEvents = [];

            // Map Attendance
            attendance.forEach(record => {
                let color = '#3b82f6'; // blue
                if (record.status === 'absent') color = '#ef4444';
                if (record.status === 'late') color = '#f59e0b';
                if (record.status === 'excused') color = '#10b981';

                calendarEvents.push({
                    title: `Attendance: ${record.status}`,
                    date: record.date,
                    color: color,
                    allDay: !record.period_id
                });
            });

            // Map Leaves
            leaves.forEach(leave => {
                calendarEvents.push({
                    title: `Leave: ${leave.type}`,
                    start: leave.start_date,
                    end: format(new Date(new Date(leave.end_date).getTime() + 86400000), 'yyyy-MM-dd'), // +1 day for exclusive end
                    color: '#8b5cf6', // purple
                    display: 'block'
                });
            });

            setEvents(calendarEvents);

            // Calc Stats
            const newStats = { present: 0, absent: 0, late: 0, excused: 0 };
            attendance.forEach(r => {
                if (newStats[r.status] !== undefined) newStats[r.status]++;
            });
            setStats(newStats);

        } catch (error) {
            console.error("Failed to fetch calendar data", error);
        }
    };

    return (
        <Layout>
            <div className="grid grid-cols-1 lg:grid-cols-4 gap-6">
                {/* Stats Cards */}
                <div className="lg:col-span-4 grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div className="bg-white p-4 rounded-lg shadow border-l-4 border-green-500">
                        <div className="text-gray-500 text-sm">Present</div>
                        <div className="text-2xl font-bold">{stats.present}</div>
                    </div>
                    <div className="bg-white p-4 rounded-lg shadow border-l-4 border-red-500">
                        <div className="text-gray-500 text-sm">Absent</div>
                        <div className="text-2xl font-bold">{stats.absent}</div>
                    </div>
                    <div className="bg-white p-4 rounded-lg shadow border-l-4 border-yellow-500">
                        <div className="text-gray-500 text-sm">Late</div>
                        <div className="text-2xl font-bold">{stats.late}</div>
                    </div>
                    <div className="bg-white p-4 rounded-lg shadow border-l-4 border-purple-500">
                        <div className="text-gray-500 text-sm">Excused</div>
                        <div className="text-2xl font-bold">{stats.excused}</div>
                    </div>
                </div>

                {/* Calendar */}
                <div className="lg:col-span-3 bg-white p-6 rounded-lg shadow">
                    <FullCalendar
                        plugins={[dayGridPlugin, interactionPlugin]}
                        initialView="dayGridMonth"
                        events={events}
                        headerToolbar={{
                            left: 'prev,next today',
                            center: 'title',
                            right: 'dayGridMonth,dayGridWeek'
                        }}
                        height="auto"
                    />
                </div>

                {/* Info / Quick Actions */}
                <div className="lg:col-span-1 space-y-6">
                    <div className="bg-white p-6 rounded-lg shadow">
                        <h3 className="font-semibold mb-4">Quick Actions</h3>
                        <a href="/student/request" className="block w-full text-center bg-indigo-600 text-white py-2 rounded hover:bg-indigo-700 transition">
                            Request Leave
                        </a>
                    </div>

                    <div className="bg-white p-6 rounded-lg shadow text-sm">
                        <h3 className="font-semibold mb-2">Legend</h3>
                        <div className="space-y-2">
                            <div className="flex items-center"><span className="w-3 h-3 rounded-full bg-green-500 mr-2"></span> Present (Auto)</div>
                            <div className="flex items-center"><span className="w-3 h-3 rounded-full bg-red-500 mr-2"></span> Absent</div>
                            <div className="flex items-center"><span className="w-3 h-3 rounded-full bg-yellow-500 mr-2"></span> Late</div>
                            <div className="flex items-center"><span className="w-3 h-3 rounded-full bg-purple-500 mr-2"></span> Leave/Excused</div>
                        </div>
                    </div>
                </div>
            </div>
        </Layout>
    );
}
