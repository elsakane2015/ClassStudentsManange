import React, { useState, useEffect, useRef } from 'react';
import Layout from '../../components/Layout';
import axios from 'axios';
import { format } from 'date-fns';
import { Link, useSearchParams } from 'react-router-dom';
import { ClockIcon, EyeIcon, ChevronLeftIcon, ChevronRightIcon, TrashIcon, XMarkIcon, PrinterIcon } from '@heroicons/react/24/outline';
import useAuthStore from '../../store/authStore';

export default function RollCallHistoryPage() {
    const { user } = useAuthStore();
    const [searchParams, setSearchParams] = useSearchParams();
    const [rollCalls, setRollCalls] = useState([]);
    const [loading, setLoading] = useState(true);
    const [scope, setScope] = useState(searchParams.get('scope') || 'today');
    const [typeId, setTypeId] = useState(searchParams.get('type_id') || '');
    const [classId, setClassId] = useState(searchParams.get('class_id') || '');
    const [rollCallTypes, setRollCallTypes] = useState([]);
    const [classes, setClasses] = useState([]);
    const [pagination, setPagination] = useState({ current_page: 1, last_page: 1 });
    const [canModifyRecords, setCanModifyRecords] = useState(false);

    // Absent modal state
    const [absentModal, setAbsentModal] = useState({
        isOpen: false,
        rollCall: null,
        records: [],
        loading: false
    });
    const printRef = useRef();

    // Check if user is department_manager or higher admin
    const isDeptOrAbove = ['department_manager', 'school_admin', 'system_admin', 'admin'].includes(user?.role);

    useEffect(() => {
        fetchTypes();
        if (isDeptOrAbove) {
            fetchClasses();
        }
        checkModifyPermission();
    }, []);

    useEffect(() => {
        fetchHistory();
    }, [scope, typeId, classId]);

    const fetchTypes = async () => {
        try {
            const res = await axios.get('/roll-call-types');
            setRollCallTypes(res.data);
        } catch (err) {
            console.error('Failed to fetch types:', err);
        }
    };

    const fetchClasses = async () => {
        try {
            // Fetch classes for department manager
            const res = await axios.get('/admin/classes');
            // API might return data directly or wrapped in data property
            const classData = res.data.data || res.data;
            setClasses(Array.isArray(classData) ? classData : []);
        } catch (err) {
            console.error('Failed to fetch classes:', err);
        }
    };

    const checkModifyPermission = async () => {
        // Teachers and admins always have permission
        if (user?.role === 'teacher' || ['admin', 'system_admin', 'department_manager', 'school_admin'].includes(user?.role)) {
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
            if (classId) params.class_id = classId;

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

    const handleRestoreRollCall = async (id) => {
        if (!confirm('确定要恢复此点名吗？恢复后状态将变为"进行中"，您可以继续进行点名操作。')) return;

        try {
            await axios.post(`/roll-calls/${id}/restore`);
            // Update local state
            setRollCalls(prev => prev.map(rc =>
                rc.id === id ? { ...rc, status: 'in_progress' } : rc
            ));
        } catch (err) {
            alert('恢复失败: ' + (err.response?.data?.error || err.message));
        }
    };

    const showAbsentList = async (rollCall) => {
        setAbsentModal({
            isOpen: true,
            rollCall,
            records: [],
            loading: true
        });

        try {
            const res = await axios.get(`/roll-calls/${rollCall.id}`);
            const records = res.data.records || [];
            // Filter non-present records
            const absentRecords = records.filter(r => r.status !== 'present');
            setAbsentModal(prev => ({
                ...prev,
                records: absentRecords,
                loading: false
            }));
        } catch (err) {
            console.error('Failed to fetch absent records:', err);
            setAbsentModal(prev => ({ ...prev, loading: false }));
        }
    };

    const handlePrint = () => {
        const printContent = printRef.current;
        if (!printContent) return;

        const printWindow = window.open('', '_blank');
        printWindow.document.write(`
            <html>
            <head>
                <title>缺勤名单打印</title>
                <style>
                    body { font-family: Arial, sans-serif; padding: 20px; }
                    h2 { text-align: center; margin-bottom: 5px; }
                    .info { text-align: center; color: #666; margin-bottom: 20px; font-size: 14px; }
                    .summary { display: flex; justify-content: center; gap: 30px; margin-bottom: 20px; }
                    .summary span { font-size: 14px; }
                    table { width: 100%; border-collapse: collapse; }
                    th, td { border: 1px solid #ddd; padding: 8px 12px; text-align: left; }
                    th { background: #f5f5f5; font-weight: bold; }
                    tr:nth-child(even) { background: #fafafa; }
                    .status-absent { color: #dc2626; }
                    .status-leave { color: #2563eb; }
                    @media print {
                        body { padding: 0; }
                    }
                </style>
            </head>
            <body>
                ${printContent.innerHTML}
            </body>
            </html>
        `);
        printWindow.document.close();
        printWindow.print();
    };

    const getStatusBadge = (status, rollCallId = null) => {
        switch (status) {
            case 'completed':
                return <span className="px-2 py-1 text-xs rounded-full bg-green-100 text-green-800">已完成</span>;
            case 'in_progress':
                return <span className="px-2 py-1 text-xs rounded-full bg-yellow-100 text-yellow-800">进行中</span>;
            case 'cancelled':
                return (
                    <button
                        onClick={(e) => {
                            e.stopPropagation();
                            if (rollCallId) handleRestoreRollCall(rollCallId);
                        }}
                        className="px-2 py-1 text-xs rounded-full bg-gray-100 text-gray-800 hover:bg-blue-100 hover:text-blue-800 transition-colors cursor-pointer"
                        title="点击恢复此点名"
                    >
                        已取消
                    </button>
                );
            default:
                return null;
        }
    };

    const getRecordStatusText = (record) => {
        if (record.status === 'absent') return '缺勤';
        if (record.status === 'on_leave') {
            const detail = record.leave_detail || record.leave_type?.name || '请假';
            return detail;
        }
        return record.status;
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
                                    { value: 'week', label: '本周' },
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

                        {/* Class Filter - Only for department managers and above */}
                        {isDeptOrAbove && classes.length > 0 && (
                            <div>
                                <label className="block text-xs text-gray-500 mb-1">班级</label>
                                <select
                                    value={classId}
                                    onChange={(e) => setClassId(e.target.value)}
                                    className="input-field"
                                >
                                    <option value="">全部班级</option>
                                    {classes.map(c => (
                                        <option key={c.id} value={c.id}>{c.name}</option>
                                    ))}
                                </select>
                            </div>
                        )}

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
                            <div className="overflow-x-auto">
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
                                                    <button
                                                        onClick={() => showAbsentList(rc)}
                                                        className="hover:bg-gray-100 px-2 py-1 rounded transition-colors cursor-pointer"
                                                        title="点击查看缺勤名单"
                                                    >
                                                        <span className="text-green-600">{rc.present_count}</span>
                                                        <span className="text-gray-400 mx-1">/</span>
                                                        <span className="text-gray-600">{rc.total_students}</span>
                                                        {rc.on_leave_count > 0 && (
                                                            <span className="text-blue-500 ml-1">(请假{rc.on_leave_count})</span>
                                                        )}
                                                    </button>
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-center">
                                                    {getStatusBadge(rc.status, rc.id)}
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
                                                        {/* Show delete button for teachers/admins, or for roll call admin who created it */}
                                                        {(user?.role === 'teacher' || ['admin', 'system_admin', 'department_manager', 'school_admin'].includes(user?.role) || (user?.student?.is_roll_call_admin && rc.created_by === user?.id)) && (
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
                            </div>

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

            {/* Absent List Modal */}
            {absentModal.isOpen && (
                <div className="fixed inset-0 z-50 overflow-y-auto">
                    <div className="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:p-0">
                        <div className="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" onClick={() => setAbsentModal({ isOpen: false, rollCall: null, records: [], loading: false })} />

                        <div className="relative inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-3xl sm:w-full">
                            {/* Header */}
                            <div className="bg-gradient-to-r from-red-500 to-orange-500 px-6 py-4">
                                <div className="flex items-center justify-between">
                                    <div>
                                        <h3 className="text-lg font-semibold text-white">
                                            {absentModal.rollCall?.roll_call_type?.name} - 缺勤/请假名单
                                        </h3>
                                        <p className="text-white/80 text-sm mt-1">
                                            {absentModal.rollCall && format(new Date(absentModal.rollCall.roll_call_time), 'yyyy-MM-dd HH:mm')}
                                            {' · '}班级：{absentModal.rollCall?.class?.name}
                                        </p>
                                    </div>
                                    <button
                                        onClick={() => setAbsentModal({ isOpen: false, rollCall: null, records: [], loading: false })}
                                        className="text-white/80 hover:text-white"
                                    >
                                        <XMarkIcon className="h-6 w-6" />
                                    </button>
                                </div>
                            </div>

                            {/* Content */}
                            <div className="px-6 py-4" ref={printRef}>
                                {/* Print Header (hidden on screen) */}
                                <div className="hidden print:block">
                                    <h2>{absentModal.rollCall?.roll_call_type?.name} - 缺勤/请假名单</h2>
                                    <p className="info">
                                        {absentModal.rollCall && format(new Date(absentModal.rollCall.roll_call_time), 'yyyy-MM-dd HH:mm')}
                                        {' · '}班级：{absentModal.rollCall?.class?.name}
                                    </p>
                                </div>

                                {/* Summary */}
                                <div className="flex gap-6 mb-4 text-sm">
                                    <span>出勤: <span className="text-green-600 font-medium">{absentModal.rollCall?.present_count}人</span></span>
                                    <span>缺勤: <span className="text-red-600 font-medium">{absentModal.rollCall?.total_students - absentModal.rollCall?.present_count - absentModal.rollCall?.on_leave_count}人</span></span>
                                    <span>请假: <span className="text-blue-600 font-medium">{absentModal.rollCall?.on_leave_count}人</span></span>
                                </div>

                                {absentModal.loading ? (
                                    <div className="flex items-center justify-center py-8">
                                        <div className="animate-spin rounded-full h-6 w-6 border-b-2 border-indigo-600"></div>
                                    </div>
                                ) : absentModal.records.length === 0 ? (
                                    <div className="text-center py-8 text-gray-500">
                                        全员出勤，无缺勤/请假记录 🎉
                                    </div>
                                ) : (
                                    <div className="overflow-x-auto max-h-96">
                                        <table className="min-w-full divide-y divide-gray-200">
                                            <thead className="bg-gray-50">
                                                <tr>
                                                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">序号</th>
                                                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">班级</th>
                                                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">学号</th>
                                                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">姓名</th>
                                                    <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">状态</th>
                                                </tr>
                                            </thead>
                                            <tbody className="bg-white divide-y divide-gray-200">
                                                {absentModal.records.map((record, index) => (
                                                    <tr key={record.id} className="hover:bg-gray-50">
                                                        <td className="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                                            {index + 1}
                                                        </td>
                                                        <td className="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                                            {absentModal.rollCall?.class?.name}
                                                        </td>
                                                        <td className="px-4 py-3 whitespace-nowrap text-sm text-gray-500 font-mono">
                                                            {record.student?.student_no || '-'}
                                                        </td>
                                                        <td className="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900">
                                                            {record.student?.user?.name || '-'}
                                                        </td>
                                                        <td className="px-4 py-3 whitespace-nowrap text-sm">
                                                            <span className={record.status === 'absent' ? 'text-red-600 font-medium' : 'text-blue-600'}>
                                                                {getRecordStatusText(record)}
                                                            </span>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </div>

                            {/* Footer */}
                            <div className="bg-gray-50 px-6 py-4 flex justify-end gap-3">
                                {absentModal.records.length > 0 && (
                                    <button
                                        onClick={handlePrint}
                                        className="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors"
                                    >
                                        <PrinterIcon className="h-4 w-4" />
                                        打印
                                    </button>
                                )}
                                <button
                                    onClick={() => setAbsentModal({ isOpen: false, rollCall: null, records: [], loading: false })}
                                    className="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors"
                                >
                                    关闭
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </Layout>
    );
}
