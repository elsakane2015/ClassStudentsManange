import './bootstrap';
import '../css/app.css';
import React from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import LoginPage from './pages/LoginPage';

import StudentDashboard from './pages/student/Dashboard';
import LeaveRequestForm from './pages/student/LeaveRequestForm';
import LeaveHistory from './pages/student/LeaveHistory';
import ClassAttendancePage from './pages/student/ClassAttendancePage';

// Placeholder Pages
import TeacherDashboard from './pages/teacher/Dashboard';
import LeaveApprovals from './pages/teacher/LeaveApprovals';
import StudentImport from './pages/teacher/StudentImport';
import StudentList from './pages/teacher/StudentList';
import RollCallPage from './pages/teacher/RollCallPage';
import RollCallOperationPage from './pages/teacher/RollCallOperationPage';
import RollCallHistoryPage from './pages/teacher/RollCallHistoryPage';
import RollCallAdminsPage from './pages/teacher/RollCallAdminsPage';
import WechatPushPage from './pages/teacher/WechatPushPage';
import SettingsPage from './pages/admin/SettingsPage';
import StaffPage from './pages/admin/StaffPage';
import PermissionsPage from './pages/admin/PermissionsPage';
import DebugPage from './pages/DebugPage';

import useAuthStore from './store/authStore';
import { authService } from './services/auth';
import { useEffect, useState } from 'react';

function App() {
    const { setUser } = useAuthStore();
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        const initAuth = async () => {
            try {
                // Try to refresh cookie first to be safe, but mostly me() is enough if cookie exists
                await axios.get('/sanctum/csrf-cookie', { baseURL: '/' }); // Global fix
                const user = await authService.me();
                setUser(user);
            } catch (error) {
                // 401 is expected when not logged in, only log other errors
                if (error.response?.status !== 401) {
                    console.error("Auth check failed:", error.response?.status, error.message);
                }
                // If 401, remain null (user not logged in)
            } finally {
                setLoading(false);
            }
        };
        initAuth();
    }, []);

    if (loading) {
        return <div className="flex justify-center items-center h-screen">加载中...</div>;
    }

    // Auth Guard Component
    const RequireAuth = ({ children }) => {
        const { user } = useAuthStore();
        if (!user) {
            return <Navigate to="/login" replace />;
        }
        return children;
    };

    return (
        <Routes>
            <Route path="/" element={<Navigate to="/login" replace />} />
            <Route path="/login" element={<LoginPage />} />

            {/* Protected Routes */}
            <Route path="/student" element={<Navigate to="/student/dashboard" replace />} />
            <Route path="/student/dashboard" element={<RequireAuth><StudentDashboard /></RequireAuth>} />
            <Route path="/student/request" element={<RequireAuth><LeaveRequestForm /></RequireAuth>} />
            <Route path="/student/history" element={<RequireAuth><LeaveHistory /></RequireAuth>} />
            <Route path="/student/class-attendance" element={<RequireAuth><ClassAttendancePage /></RequireAuth>} />

            <Route path="/teacher" element={<Navigate to="/teacher/dashboard" replace />} />
            <Route path="/teacher/dashboard" element={<RequireAuth><TeacherDashboard /></RequireAuth>} />
            <Route path="/teacher/approvals" element={<RequireAuth><LeaveApprovals /></RequireAuth>} />
            <Route path="/teacher/import" element={<RequireAuth><StudentImport /></RequireAuth>} />
            <Route path="/teacher/students" element={<RequireAuth><StudentList /></RequireAuth>} />

            {/* Roll Call Routes */}
            <Route path="/roll-call" element={<RequireAuth><RollCallPage /></RequireAuth>} />
            <Route path="/roll-call/:id" element={<RequireAuth><RollCallOperationPage /></RequireAuth>} />
            <Route path="/roll-call/history" element={<RequireAuth><RollCallHistoryPage /></RequireAuth>} />
            <Route path="/roll-call/admins" element={<RequireAuth><RollCallAdminsPage /></RequireAuth>} />

            {/* WeChat Push */}
            <Route path="/teacher/wechat" element={<RequireAuth><WechatPushPage /></RequireAuth>} />

            <Route path="/admin/settings" element={<RequireAuth><SettingsPage /></RequireAuth>} />
            <Route path="/admin/staff" element={<RequireAuth><StaffPage /></RequireAuth>} />
            <Route path="/admin/permissions" element={<RequireAuth><PermissionsPage /></RequireAuth>} />
            <Route path="/debug" element={<DebugPage />} />

            <Route path="*" element={<div>404 Not Found</div>} />
        </Routes>
    );
}

const container = document.getElementById('app');
if (container) {
    const root = createRoot(container);
    root.render(
        <React.StrictMode>
            <BrowserRouter>
                <App />
            </BrowserRouter>
        </React.StrictMode>
    );
}
