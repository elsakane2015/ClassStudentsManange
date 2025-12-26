import React, { useState } from 'react';
import axios from 'axios';
import { KeyIcon, EyeIcon, EyeSlashIcon, XMarkIcon } from '@heroicons/react/24/outline';

export default function ChangePasswordModal({ isOpen, onClose }) {
    const [formData, setFormData] = useState({
        current_password: '',
        new_password: '',
        new_password_confirmation: '',
    });
    const [showCurrentPassword, setShowCurrentPassword] = useState(false);
    const [showNewPassword, setShowNewPassword] = useState(false);
    const [showConfirmPassword, setShowConfirmPassword] = useState(false);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [success, setSuccess] = useState(false);

    const handleSubmit = async (e) => {
        e.preventDefault();
        setError('');
        setLoading(true);

        // Client-side validation
        if (formData.new_password !== formData.new_password_confirmation) {
            setError('两次输入的新密码不一致');
            setLoading(false);
            return;
        }

        if (formData.new_password.length < 6) {
            setError('新密码至少需要6位');
            setLoading(false);
            return;
        }

        try {
            await axios.post('/user/change-password', formData);
            setSuccess(true);
            setFormData({
                current_password: '',
                new_password: '',
                new_password_confirmation: '',
            });
            // Close modal after 1.5 seconds
            setTimeout(() => {
                setSuccess(false);
                onClose();
            }, 1500);
        } catch (err) {
            const errorMsg = err.response?.data?.error
                || err.response?.data?.message
                || err.response?.data?.errors?.new_password?.[0]
                || err.response?.data?.errors?.current_password?.[0]
                || '密码修改失败';
            setError(errorMsg);
        } finally {
            setLoading(false);
        }
    };

    const handleChange = (e) => {
        setFormData({ ...formData, [e.target.name]: e.target.value });
        setError('');
    };

    if (!isOpen) return null;

    return (
        <div className="fixed inset-0 z-50 overflow-y-auto">
            <div className="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:p-0">
                {/* Backdrop */}
                <div
                    className="fixed inset-0 transition-opacity bg-gray-500 bg-opacity-75"
                    onClick={onClose}
                />

                {/* Modal */}
                <div className="relative inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-md sm:w-full">
                    {/* Header */}
                    <div className="bg-gradient-to-r from-indigo-600 to-purple-600 px-6 py-4">
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-3">
                                <KeyIcon className="h-6 w-6 text-white" />
                                <h3 className="text-lg font-semibold text-white">修改密码</h3>
                            </div>
                            <button
                                onClick={onClose}
                                className="text-white/80 hover:text-white transition-colors"
                            >
                                <XMarkIcon className="h-6 w-6" />
                            </button>
                        </div>
                    </div>

                    {/* Body */}
                    <form onSubmit={handleSubmit} className="px-6 py-5 space-y-4">
                        {/* Success Message */}
                        {success && (
                            <div className="p-3 bg-green-50 border border-green-200 rounded-lg text-green-700 text-sm text-center">
                                ✓ 密码修改成功
                            </div>
                        )}

                        {/* Error Message */}
                        {error && (
                            <div className="p-3 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm">
                                {error}
                            </div>
                        )}

                        {/* Current Password */}
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">
                                当前密码
                            </label>
                            <div className="relative">
                                <input
                                    type={showCurrentPassword ? 'text' : 'password'}
                                    name="current_password"
                                    value={formData.current_password}
                                    onChange={handleChange}
                                    required
                                    className="w-full px-4 py-2 pr-10 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                                    placeholder="请输入当前密码"
                                />
                                <button
                                    type="button"
                                    onClick={() => setShowCurrentPassword(!showCurrentPassword)}
                                    className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                                >
                                    {showCurrentPassword ? (
                                        <EyeSlashIcon className="h-5 w-5" />
                                    ) : (
                                        <EyeIcon className="h-5 w-5" />
                                    )}
                                </button>
                            </div>
                        </div>

                        {/* New Password */}
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">
                                新密码
                            </label>
                            <div className="relative">
                                <input
                                    type={showNewPassword ? 'text' : 'password'}
                                    name="new_password"
                                    value={formData.new_password}
                                    onChange={handleChange}
                                    required
                                    minLength={6}
                                    className="w-full px-4 py-2 pr-10 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                                    placeholder="请输入新密码"
                                />
                                <button
                                    type="button"
                                    onClick={() => setShowNewPassword(!showNewPassword)}
                                    className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                                >
                                    {showNewPassword ? (
                                        <EyeSlashIcon className="h-5 w-5" />
                                    ) : (
                                        <EyeIcon className="h-5 w-5" />
                                    )}
                                </button>
                            </div>
                            <p className="text-xs text-gray-400 mt-1">密码至少需要6位</p>
                        </div>

                        {/* Confirm New Password */}
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">
                                确认新密码
                            </label>
                            <div className="relative">
                                <input
                                    type={showConfirmPassword ? 'text' : 'password'}
                                    name="new_password_confirmation"
                                    value={formData.new_password_confirmation}
                                    onChange={handleChange}
                                    required
                                    className="w-full px-4 py-2 pr-10 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                                    placeholder="请再次输入新密码"
                                />
                                <button
                                    type="button"
                                    onClick={() => setShowConfirmPassword(!showConfirmPassword)}
                                    className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                                >
                                    {showConfirmPassword ? (
                                        <EyeSlashIcon className="h-5 w-5" />
                                    ) : (
                                        <EyeIcon className="h-5 w-5" />
                                    )}
                                </button>
                            </div>
                        </div>

                        {/* Actions */}
                        <div className="flex gap-3 pt-2">
                            <button
                                type="button"
                                onClick={onClose}
                                className="flex-1 px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors"
                            >
                                取消
                            </button>
                            <button
                                type="submit"
                                disabled={loading || success}
                                className="flex-1 px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                            >
                                {loading ? '提交中...' : '确认修改'}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    );
}
