import './bootstrap';
import '../css/app.css';
import React from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import LoginPage from './pages/LoginPage';

import StudentDashboard from './pages/student/Dashboard';
import LeaveRequestForm from './pages/student/LeaveRequestForm';

// Placeholder Pages
import TeacherDashboard from './pages/teacher/Dashboard';
import LeaveApprovals from './pages/teacher/LeaveApprovals';
import StudentImport from './pages/teacher/StudentImport';

function App() {
    return (
        <Routes>
            <Route path="/" element={<Navigate to="/login" replace />} />
            <Route path="/login" element={<LoginPage />} />

            {/* Protected Routes */}
            <Route path="/student/dashboard" element={<StudentDashboard />} />
            <Route path="/student/request" element={<LeaveRequestForm />} />

            <Route path="/teacher/dashboard" element={<TeacherDashboard />} />
            <Route path="/teacher/approvals" element={<LeaveApprovals />} />
            <Route path="/teacher/import" element={<StudentImport />} />
            <Route path="/teacher/students" element={<TeacherDashboard />} /> {/* Reuse dashboard for now */}

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
