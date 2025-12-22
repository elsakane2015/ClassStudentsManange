import React, { useEffect, useState } from 'react';
import Layout from '../../components/Layout';
import axios from 'axios';
import { format } from 'date-fns';
import { useNavigate } from 'react-router-dom';

export default function LeaveHistory() {
    const [leaves, setLeaves] = useState([]);
    const [loading, setLoading] = useState(true);
    const [deleteConfirm, setDeleteConfirm] = useState(null);
    const [deleting, setDeleting] = useState(false);
    const navigate = useNavigate();

    useEffect(() => {
        fetchLeaves();
    }, []);

    const fetchLeaves = async () => {
        try {
            const res = await axios.get('/leave-requests'); // Gets own requests for student
            setLeaves(res.data.data);
        } catch (error) {
            console.error(error);
        } finally {
            setLoading(false);
        }
    };

    const handleEdit = (leave) => {
        // Navigate to leave request page with edit data
        navigate('/student/leave-request', {
            state: {
                editMode: true,
                leaveData: leave
            }
        });
    };

    const handleDelete = async (leaveId) => {
        setDeleting(true);
        try {
            await axios.delete(`/leave-requests/${leaveId}`);
            // Remove from list
            setLeaves(leaves.filter(l => l.id !== leaveId));
            setDeleteConfirm(null);
        } catch (error) {
            console.error(error);
            alert('删除失败，请重试');
        } finally {
            setDeleting(false);
        }
    };

    const getStatusBadge = (status) => {
        switch (status) {
            case 'approved':
                return <span className="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">已批准</span>;
            case 'rejected':
                return <span className="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">已驳回</span>;
            case 'cancelled':
                return <span className="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">已取消</span>;
            default:
                return <span className="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">待审批</span>;
        }
    };

    const getTypeLabel = (leave) => {
        // Use leave_type name if available
        if (leave.leave_type?.name) {
            return leave.leave_type.name;
        }
        // Fallback to type slug mapping
        const typeMap = {
            'sick_leave': '病假',
            'sick': '病假',
            'personal_leave': '事假',
            'personal': '事假',
        };
        return typeMap[leave.type] || leave.type || '请假';
    };

    return (
        <Layout>
            <div className="md:flex md:items-center md:justify-between mb-6">
                <div className="min-w-0 flex-1">
                    <h2 className="text-2xl font-bold leading-7 text-gray-900 sm:truncate sm:text-3xl sm:tracking-tight">
                        我的请假记录
                    </h2>
                </div>
            </div>

            {loading ? (
                <div className="text-center py-10">加载中...</div>
            ) : leaves.length === 0 ? (
                <div className="bg-white p-10 rounded-lg shadow text-center text-gray-500">
                    您还没有任何请假申请记录。
                </div>
            ) : (
                <div className="bg-white shadow overflow-hidden sm:rounded-lg">
                    <ul role="list" className="divide-y divide-gray-200">
                        {leaves.map((leave) => (
                            <li key={leave.id} className="px-4 py-4 sm:px-6 hover:bg-gray-50 transition duration-150 ease-in-out">
                                <div className="flex items-center justify-between">
                                    <div className="flex flex-col">
                                        <div className="flex items-center text-sm text-indigo-600 font-medium mb-1">
                                            {getTypeLabel(leave)}
                                            <span className="text-gray-400 mx-2">•</span>
                                            <span className="text-gray-600">
                                                {format(new Date(leave.start_date), 'yyyy-MM-dd')} 至 {format(new Date(leave.end_date), 'yyyy-MM-dd')}
                                            </span>
                                        </div>
                                        <div className="text-sm text-gray-500">
                                            {leave.half_day ? (leave.half_day === 'am' ? '仅上午' : '仅下午') : '全天'}
                                        </div>
                                        <p className="mt-2 text-sm text-gray-700 italic">
                                            "{leave.reason}"
                                        </p>
                                    </div>
                                    <div className="ml-2 flex-shrink-0 flex flex-col items-end">
                                        <div className="mb-2">
                                            {getStatusBadge(leave.status)}
                                        </div>
                                        <div className="text-xs text-gray-500 mb-2">
                                            申请于: {format(new Date(leave.created_at), 'yyyy-MM-dd')}
                                        </div>

                                        {/* Edit/Delete buttons for pending requests */}
                                        {leave.status === 'pending' && (
                                            <div className="flex items-center gap-2 mt-1">
                                                <button
                                                    onClick={() => handleEdit(leave)}
                                                    className="text-xs px-2 py-1 bg-blue-50 text-blue-600 rounded hover:bg-blue-100 transition-colors"
                                                >
                                                    编辑
                                                </button>
                                                <button
                                                    onClick={() => setDeleteConfirm(leave.id)}
                                                    className="text-xs px-2 py-1 bg-red-50 text-red-600 rounded hover:bg-red-100 transition-colors"
                                                >
                                                    撤销
                                                </button>
                                            </div>
                                        )}
                                    </div>
                                </div>
                                {leave.rejection_reason && (
                                    <div className="mt-2 text-sm text-red-600 bg-red-50 p-2 rounded">
                                        驳回原因: {leave.rejection_reason}
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {/* Delete Confirmation Modal */}
            {deleteConfirm && (
                <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                    <div className="bg-white rounded-lg p-6 max-w-sm mx-4 shadow-xl">
                        <h3 className="text-lg font-medium text-gray-900 mb-4">确认撤销</h3>
                        <p className="text-gray-500 mb-6">确定要撤销这个请假申请吗？此操作不可恢复。</p>
                        <div className="flex justify-end gap-3">
                            <button
                                onClick={() => setDeleteConfirm(null)}
                                className="px-4 py-2 text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors"
                                disabled={deleting}
                            >
                                取消
                            </button>
                            <button
                                onClick={() => handleDelete(deleteConfirm)}
                                className="px-4 py-2 text-white bg-red-600 rounded-lg hover:bg-red-700 transition-colors disabled:opacity-50"
                                disabled={deleting}
                            >
                                {deleting ? '撤销中...' : '确认撤销'}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </Layout>
    );
}
