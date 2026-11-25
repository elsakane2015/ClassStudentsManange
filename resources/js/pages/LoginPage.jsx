import React, { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { authService } from '../services/auth';
import useAuthStore from '../store/authStore';
import axios from 'axios';

export default function LoginPage() {
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [error, setError] = useState(null);
    const [loading, setLoading] = useState(false);
    const [schoolName, setSchoolName] = useState('智慧校园考勤系统');

    // Forgot password (email verification code) modal state
    const [showResetModal, setShowResetModal] = useState(false);
    const [resetEmail, setResetEmail] = useState('');
    const [resetCode, setResetCode] = useState('');
    const [resetPassword, setResetPassword] = useState('');
    const [resetPasswordConfirm, setResetPasswordConfirm] = useState('');
    const [resetStep, setResetStep] = useState('request'); // request | confirm
    const [resetBusy, setResetBusy] = useState(false);
    const [resetCountdown, setResetCountdown] = useState(0);
    const [resetFeedback, setResetFeedback] = useState(null);

    useEffect(() => {
        if (resetCountdown <= 0) return undefined;
        const timer = setTimeout(() => setResetCountdown(c => c - 1), 1000);
        return () => clearTimeout(timer);
    }, [resetCountdown]);

    // Store
    const { setUser, setToken } = useAuthStore();
    const navigate = useNavigate();

    useEffect(() => {
        // Fetch school info (axios baseURL is already /api)
        axios.get('/school/info')
            .then(res => {
                const name = res.data?.name;
                if (name) {
                    setSchoolName(name);
                    document.title = name + ' - 登录';
                }
            })
            .catch(() => {
                // Keep default
            });
    }, []);

    const handleSubmit = async (e) => {
        e.preventDefault();
        setError(null);
        setLoading(true);

        try {
            const data = await authService.login(email, password);
            console.log('Login success:', data);

            // Handle Token Logic via Store
            if (data.access_token) {
                setToken(data.access_token);
            }

            // Fetch full user details to trust state
            const user = await authService.me();
            setUser(user);

            // Redirect based on role
            if (user.role === 'parent') navigate('/parent/dashboard');
            else if (user.role === 'student') navigate('/student/dashboard');
            else if (user.role === 'duty_teacher') navigate('/evening-study/take');
            else if (['teacher', 'system_admin', 'school_admin', 'department_manager', 'admin', 'manager'].includes(user.role)) navigate('/teacher/dashboard');
            else navigate('/');

        } catch (err) {
            console.error(err);
            setError(err.response?.data?.message || err.response?.data?.email?.[0] || '登录失败');
        } finally {
            setLoading(false);
        }
    };

    const openResetModal = () => {
        setResetEmail(email.includes('@') ? email : '');
        setResetCode('');
        setResetPassword('');
        setResetPasswordConfirm('');
        setResetStep('request');
        setResetFeedback(null);
        setShowResetModal(true);
    };

    const handleSendResetCode = async () => {
        if (!resetEmail.trim()) {
            setResetFeedback({ type: 'error', text: '请先输入登录邮箱' });
            return;
        }
        setResetBusy(true);
        setResetFeedback(null);
        try {
            const res = await axios.post('/auth/password-reset/send-code', { email: resetEmail.trim() });
            setResetStep('confirm');
            setResetCountdown(45);
            setResetFeedback({ type: 'success', text: res.data?.message || '验证码已发送，请查收邮件' });
        } catch (err) {
            const validationErrors = err.response?.data?.errors;
            const firstErr = validationErrors ? Object.values(validationErrors).flat()[0] : null;
            setResetFeedback({ type: 'error', text: firstErr || err.response?.data?.message || '发送验证码失败' });
        } finally {
            setResetBusy(false);
        }
    };

    const handleConfirmReset = async (e) => {
        e.preventDefault();
        setResetBusy(true);
        setResetFeedback(null);
        try {
            const res = await axios.post('/auth/password-reset/confirm', {
                email: resetEmail.trim(),
                code: resetCode.trim(),
                password: resetPassword,
                password_confirmation: resetPasswordConfirm,
            });
            setEmail(resetEmail.trim());
            setPassword('');
            setShowResetModal(false);
            setError(null);
            alert(res.data?.message || '密码重置成功，请使用新密码登录');
        } catch (err) {
            const validationErrors = err.response?.data?.errors;
            const firstErr = validationErrors ? Object.values(validationErrors).flat()[0] : null;
            setResetFeedback({ type: 'error', text: firstErr || err.response?.data?.message || '重置失败，请核对验证码' });
        } finally {
            setResetBusy(false);
        }
    };

    return (
        <div className="flex min-h-screen flex-col justify-center px-6 py-12 lg:px-8 bg-gray-50">
            <div className="sm:mx-auto sm:w-full sm:max-w-sm">
                <h2 className="mt-10 text-center text-2xl font-bold leading-9 tracking-tight text-gray-900">
                    学生考勤系统
                </h2>
                <p className="text-center text-sm text-gray-500 mt-2">
                    {schoolName}
                </p>
            </div>

            <div className="mt-10 sm:mx-auto sm:w-full sm:max-w-sm">
                <form className="space-y-6" onSubmit={handleSubmit}>
                    {error && (
                        <div className="rounded-md bg-red-50 p-4">
                            <div className="flex">
                                <div className="ml-3">
                                    <h3 className="text-sm font-medium text-red-800">{error}</h3>
                                </div>
                            </div>
                        </div>
                    )}

                    <div>
                        <label htmlFor="email" className="block text-sm font-medium leading-6 text-gray-900">
                            邮箱 / 学号
                        </label>
                        <div className="mt-2">
                            <input
                                id="email"
                                name="email"
                                type="text"
                                autoComplete="username"
                                required
                                placeholder="输入邮箱或学号"
                                value={email}
                                onChange={e => setEmail(e.target.value)}
                                className="block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6 px-3"
                            />
                        </div>
                    </div>

                    <div>
                        <div className="flex items-center justify-between">
                            <label htmlFor="password" className="block text-sm font-medium leading-6 text-gray-900">
                                密码
                            </label>
                            <button
                                type="button"
                                onClick={openResetModal}
                                className="text-xs font-medium text-indigo-600 hover:text-indigo-500"
                            >
                                忘记密码？
                            </button>
                        </div>
                        <div className="mt-2">
                            <input
                                id="password"
                                name="password"
                                type="password"
                                autoComplete="current-password"
                                required
                                value={password}
                                onChange={e => setPassword(e.target.value)}
                                className="block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6 px-3"
                            />
                        </div>
                    </div>

                    <div>
                        <button
                            type="submit"
                            disabled={loading}
                            className="flex w-full justify-center rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-semibold leading-6 text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50"
                        >
                            {loading ? '登录中...' : '登 录'}
                        </button>
                    </div>
                </form>

                <div className="mt-8 text-center text-xs text-gray-400">
                    <p>支持学生（学号/邮箱）、教职工及家长邮箱统一登录</p>
                </div>
            </div>

            {showResetModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <div className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                        <div className="flex items-center justify-between mb-4">
                            <h3 className="text-lg font-semibold text-gray-900">邮箱验证码重置密码</h3>
                            <button
                                type="button"
                                onClick={() => setShowResetModal(false)}
                                className="text-gray-400 hover:text-gray-600 text-xl leading-none"
                            >
                                ×
                            </button>
                        </div>

                        {resetFeedback && (
                            <div className={`mb-4 rounded-lg p-3 text-sm ${resetFeedback.type === 'success' ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'}`}>
                                {resetFeedback.text}
                            </div>
                        )}

                        <form onSubmit={handleConfirmReset} className="space-y-4">
                            <div>
                                <label className="block text-sm font-medium text-gray-700">登录邮箱（家长/教师/学生邮箱）</label>
                                <div className="mt-1 flex gap-2">
                                    <input
                                        type="email"
                                        required
                                        value={resetEmail}
                                        onChange={e => setResetEmail(e.target.value)}
                                        placeholder="name@example.com"
                                        className="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    />
                                    <button
                                        type="button"
                                        disabled={resetBusy || resetCountdown > 0}
                                        onClick={handleSendResetCode}
                                        className="flex-shrink-0 rounded-md bg-indigo-50 px-3 py-2 text-xs font-semibold text-indigo-700 hover:bg-indigo-100 disabled:opacity-50"
                                    >
                                        {resetCountdown > 0 ? `${resetCountdown}s后重发` : '获取验证码'}
                                    </button>
                                </div>
                            </div>

                            {resetStep === 'confirm' && (
                                <>
                                    <div>
                                        <label className="block text-sm font-medium text-gray-700">6 位邮箱验证码</label>
                                        <input
                                            type="text"
                                            required
                                            maxLength={6}
                                            value={resetCode}
                                            onChange={e => setResetCode(e.target.value)}
                                            placeholder="输入邮件中的 6 位数字验证码"
                                            className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm tracking-widest"
                                        />
                                    </div>
                                    <div>
                                        <label className="block text-sm font-medium text-gray-700">新密码（至少 6 位）</label>
                                        <input
                                            type="password"
                                            required
                                            minLength={6}
                                            value={resetPassword}
                                            onChange={e => setResetPassword(e.target.value)}
                                            className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                                        />
                                    </div>
                                    <div>
                                        <label className="block text-sm font-medium text-gray-700">确认新密码</label>
                                        <input
                                            type="password"
                                            required
                                            minLength={6}
                                            value={resetPasswordConfirm}
                                            onChange={e => setResetPasswordConfirm(e.target.value)}
                                            className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                                        />
                                    </div>
                                </>
                            )}

                            <div className="flex justify-end gap-2 pt-2">
                                <button
                                    type="button"
                                    onClick={() => setShowResetModal(false)}
                                    className="rounded-md border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50"
                                >
                                    取消
                                </button>
                                {resetStep === 'confirm' && (
                                    <button
                                        type="submit"
                                        disabled={resetBusy}
                                        className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50"
                                    >
                                        {resetBusy ? '提交中...' : '确认重置密码'}
                                    </button>
                                )}
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </div>
    );
}
