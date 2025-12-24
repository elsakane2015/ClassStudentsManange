import React, { useState, useEffect } from 'react';
import Layout from '../../components/Layout';
import axios from 'axios';
import { format } from 'date-fns';
import { Link, useSearchParams } from 'react-router-dom';
import { ClockIcon, EyeIcon, ChevronLeftIcon, ChevronRightIcon, TrashIcon } from '@heroicons/react/24/outline';
import useAuthStore from '../../store/authStore';

export default function RollCallHistoryPage() {
    const { user } = useAuthStore();
    const [searchParams, setSearchParams] = useSearchParams();
    const [rollCalls, setRollCalls] = useState([]);
    const [loading, setLoading] = useState(true);
    const [scope, setScope] = useState(searchParams.get('scope') || 'today');
    const [typeId, setTypeId] = useState(searchParams.get('type_id') || '');
    const [rollCallTypes, setRollCallTypes] = useState([]);
    const [pagination, setPagination] = useState({ current_page: 1, last_page: 1 });
    const [canModifyRecords, setCanModifyRecords] = useState(false);

    useEffect(() => {
        fetchTypes();
        checkModifyPermission();
    }, []);

    useEffect(() => {
        fetchHistory();
    }, [scope, typeId]);

    const fetchTypes = async () => {
        try {
            const res = await axios.get('/roll-call-types');
            setRollCallTypes(res.data);
        } catch (err) {
            console.error('Failed to fetch types:', err);
        }
    };

    const checkModifyPermission = async () => {
        // Teachers and admins always have permission
        if (user?.role === 'teacher' || ['admin', 'system_admin'].includes(user?.role)) {
            setCanModifyRecords(true);
            return;
        }
        // For students, check if they're a roll call admin with modify permission
        if (user?.role === 'student' && user?.student) {
            try {
                const res = await axios.get('/roll-call-admins', { params: { class_id: user.student.class_id } });
                const admin = res.data.find(a =>
                    a.student_id === user.student.id &&
                    a.is_active &&
                    a.can_modify_records
                );
                setCanModifyRecords(!!admin);
            } catch (e) {
                setCanModifyRecords(false);
            }
        }
    };

    const fetchHistory = async (page = 1) => {
        setLoading(true);
        try {
            const params = { scope, page };
            if (typeId) params.type_id = typeId;

            const res = await axios.get('/roll-calls', { params });
            setRollCalls(res.data.data || []);
            setPagination({
                current_page: res.data.current_page || 1,
                last_page: res.data.last_page || 1,
            });
        } catch (err) {
            console.error('Failed to fetch history:', err);
        } finally {
            setLoading(false);
        }
    };

    const deleteRollCall = async (id, typeName) => {
        if (!confirm(`确定要删除"${typeName}"点名吗？相关的考勤记录也会被删除，此操作不可恢复！`)) return;

        try {
            await axios.delete(`/roll-calls/${id}`);
            // Remove from local state
            setRollCalls(prev => prev.filter(rc => rc.id !== id));
        } catch (err) {
            alert('删除失败: ' + (err.response?.data?.error || err.message));
        }
    };

    const getStatusBadge = (status) => {
        switch (status) {
            case 'completed':
                return <span className="px-2 py-1 text-xs rounded-full bg-green-100 text-green-800">已完成</span>;
            case 'in_progress':
                return <span className="px-2 py-1 text-xs rounded-full bg-yellow-100 text-yellow-800">进行中</span>;
            case 'cancelled':
                return <span className="px-2 py-1 text-xs rounded-full bg-gray-100 text-gray-800">已取消</span>;
            default:
                return null;
        }
    };

    return (
        <Layout>
            <div className="space-y-6">
                {/* Header */}
                <div className="md:flex md:items-center md:justify-between">
                    <div className="min-w-0 flex-1">
                        <h2 className="text-2xl font-bold leading-7 text-gray-900">点名历史</h2>
                    </div>
                    <div className="mt-4 md:ml-4 md:mt-0">
                        <Link to="/roll-call" className="btn-secondary">
                            返回点名
                        </Link>
                    </div>
                </div>

                {/* Filters */}
                <div className="bg-white shadow rounded-lg p-4">
                    <div className="flex flex-wrap items-center gap-4">
                        <div>
                            <label className="block text-xs text-gray-500 mb-1">时间范围</label>
                            <div className="flex rounded-lg overflow-hidden border">
                                {[
                                    { value: 'today', label: '今日' },
                                    { value: 'month', label: '本月' },
                                    { value: 'semester', label: '本学期' },
                                ].map(option => (
                                    <button
                                        key={option.value}
                                        onClick={() => setScope(option.value)}
                                        className={`px-4 py-2 text-sm ${scope === option.value
                                            ? 'bg-indigo-600 text-white'
                                            : 'bg-white text-gray-700 hover:bg-gray-50'
                                            }`}
                                    >
                                        {option.label}
                                    </button>
                                ))}
                            </div>
                        </div>
                        <div>
                            <label className="block text-xs text-gray-500 mb-1">活动类型</label>
                            <select
                                value={typeId}
                                onChange={(e) => setTypeId(e.target.value)}
                                className="input-field"
                            >
                                <option value="">全部类型</option>
                                {rollCallTypes.map(t => (
                                    <option key={t.id} value={t.id}>{t.name}</option>
                                ))}
                            </select>
                        </div>
                    </div>
                </div>

                {/* List */}
                <div className="bg-white shadow rounded-lg overflow-hidden">
                    {loading ? (
                        <div className="flex items-center justify-center h-32">
                            <div className="animate-spin rounded-full h-6 w-6 border-b-2 border-indigo-600"></div>
                        </div>
                    ) : rollCalls.length === 0 ? (
                        <div className="text-center py-12 text-gray-500">
                            暂无点名记录
                        </div>
                    ) : (
                        <>
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">活动</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">班级</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">时间</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">点名员</th>
                                        <th className="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">出勤</th>
                                        <th className="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">状态</th>
                                        <th className="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">操作</th>
                                    </tr>
                                </thead>
                                <tbody className="bg-white divide-y divide-gray-200">
                                    {rollCalls.map(rc => (
                                        <tr key={rc.id} className="hover:bg-gray-50">
                                            <td className="px-6 py-4 whitespace-nowrap">
                                                <div className="font-medium text-gray-900">{rc.roll_call_type?.name}</div>
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                {rc.class?.name}
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                {format(new Date(rc.roll_call_time), 'MM-dd HH:mm')}
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                {rc.creator?.name || '-'}
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap text-center">
                                                <span className="text-green-600">{rc.present_count}</span>
                                                <span className="text-gray-400 mx-1">/</span>
                                                <span className="text-gray-600">{rc.total_students}</span>
                                                {rc.on_leave_count > 0 && (
                                                    <span className="text-blue-500 ml-1">(请假{rc.on_leave_count})</span>
                                                )}
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap text-center">
                                                {getStatusBadge(rc.status)}
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap text-center">
                                                <div className="flex items-center justify-center gap-2">
                                                    <Link
                                                        to={`/roll-call/${rc.id}`}
                                                        className="text-indigo-600 hover:text-indigo-800"
                                                        title="查看详情"
                                                    >
                                                        <EyeIcon className="h-5 w-5" />
                                                    </Link>
                                                    {/* Show delete button for teachers/admins, or for student with modify permission who created it */}
                                                    {(user?.role === 'teacher' || ['admin', 'system_admin'].includes(user?.role) || (canModifyRecords && rc.created_by === user?.id)) && (
                                                        <button
                                                            onClick={() => deleteRollCall(rc.id, rc.roll_call_type?.name)}
                                                            className="text-red-500 hover:text-red-700"
                                                            title="删除点名"
                                                        >
                                                            <TrashIcon className="h-5 w-5" />
                                                        </button>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>

                            {/* Pagination */}
                            {pagination.last_page > 1 && (
                                <div className="px-6 py-4 flex items-center justify-between border-t">
                                    <button
                                        onClick={() => fetchHistory(pagination.current_page - 1)}
                                        disabled={pagination.current_page === 1}
                                        className="btn-secondary disabled:opacity-50"
                                    >
                                        <ChevronLeftIcon className="h-4 w-4" />
                                    </button>
                                    <span className="text-sm text-gray-500">
                                        第 {pagination.current_page} / {pagination.last_page} 页
                                    </span>
                                    <button
                                        onClick={() => fetchHistory(pagination.current_page + 1)}
                                        disabled={pagination.current_page === pagination.last_page}
                                        className="btn-secondary disabled:opacity-50"
                                    >
                                        <ChevronRightIcon className="h-4 w-4" />
                                    </button>
                                </div>
                            )}
                        </>
                    )}
                </div>
            </div>
        </Layout>
    );
}
