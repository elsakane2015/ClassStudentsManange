import React, { useState, useEffect } from 'react';
import Layout from '../../components/Layout';
import axios from 'axios';
import { format } from 'date-fns';
import { useParams, useNavigate } from 'react-router-dom';
import { CheckIcon, XMarkIcon, ArrowLeftIcon, CheckCircleIcon, PencilIcon } from '@heroicons/react/24/outline';
import useAuthStore from '../../store/authStore';

export default function RollCallOperationPage() {
    const { id } = useParams();
    const navigate = useNavigate();
    const { user } = useAuthStore();
    const [rollCall, setRollCall] = useState(null);
    const [records, setRecords] = useState([]);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [searchTerm, setSearchTerm] = useState('');
    const [editingRecord, setEditingRecord] = useState(null);
    const [canModifyRecords, setCanModifyRecords] = useState(false);

    useEffect(() => {
        fetchRollCall();
    }, [id]);

    const fetchRollCall = async () => {
        setLoading(true);
        try {
            const res = await axios.get(`/roll-calls/${id}`);
            setRollCall(res.data);
            setRecords(res.data.records || []);
            // Get canModifyRecords directly from API response
            setCanModifyRecords(res.data.can_modify_records || false);
        } catch (err) {
            console.error('Failed to fetch roll call:', err);
            alert('加载失败: ' + (err.response?.data?.error || err.message));
        } finally {
            setLoading(false);
        }
    };

    // Toggle student - only update local state (no network request)
    const toggleStudent = (studentId, isPresent) => {
        setRecords(prev => prev.map(r =>
            r.student_id === studentId
                ? { ...r, status: isPresent ? 'present' : 'pending', marked_at: isPresent ? new Date().toISOString() : null }
                : r
        ));
    };

    // Update record status for completed roll calls (this still needs to save immediately)
    const updateRecordStatus = async (recordId, newStatus) => {
        setSaving(true);
        try {
            const res = await axios.put(`/roll-calls/${id}/records/${recordId}`, {
                status: newStatus,
            });
            // Update local state
            setRecords(prev => prev.map(r =>
                r.id === recordId
                    ? { ...r, status: newStatus, marked_at: new Date().toISOString() }
                    : r
            ));
            setEditingRecord(null);
        } catch (err) {
            alert('更新失败: ' + (err.response?.data?.error || err.message));
        } finally {
            setSaving(false);
        }
    };

    // Mark all pending students as present - only update local state
    const markAllPresent = () => {
        setRecords(prev => prev.map(r =>
            r.status === 'pending'
                ? { ...r, status: 'present', marked_at: new Date().toISOString() }
                : r
        ));
    };

    // Complete roll call - submit all records to backend
    const completeRollCall = async () => {
        // Check network connectivity
        if (!navigator.onLine) {
            alert('网络连接已断开，请连接网络后再试');
            return;
        }

        setSaving(true);
        try {
            // Prepare records data for submission
            const recordsData = records.map(r => ({
                student_id: r.student_id,
                status: r.status,
                marked_at: r.marked_at,
            }));

            await axios.post(`/roll-calls/${id}/complete`, {
                records: recordsData
            });
            navigate('/roll-call');
        } catch (err) {
            alert('完成失败: ' + (err.response?.data?.error || err.message));
        } finally {
            setSaving(false);
        }
    };


    const cancelRollCall = async () => {
        if (!confirm('确定要取消这次点名吗？')) return;

        try {
            await axios.post(`/roll-calls/${id}/cancel`);
            navigate('/roll-call');
        } catch (err) {
            alert('取消失败: ' + (err.response?.data?.error || err.message));
        }
    };

    const filteredRecords = records.filter(r => {
        const name = r.student?.user?.name || r.student?.name || '';
        const studentNo = r.student?.student_no || '';
        const searchLower = searchTerm.toLowerCase();
        return name.toLowerCase().includes(searchLower) || studentNo.toLowerCase().includes(searchLower);
    });

    // Stats
    const presentCount = records.filter(r => r.status === 'present').length;
    const onLeaveCount = records.filter(r => r.status === 'on_leave').length;
    const absentCount = records.filter(r => r.status === 'absent').length;
    const pendingCount = records.filter(r => r.status === 'pending').length;
    const totalCount = records.length;

    if (loading) {
        return (
            <Layout>
                <div className="flex items-center justify-center h-64">
                    <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600"></div>
                </div>
            </Layout>
        );
    }

    if (!rollCall) {
        return (
            <Layout>
                <div className="text-center py-12">
                    <p className="text-gray-500">点名记录不存在</p>
                    <button onClick={() => navigate('/roll-call')} className="btn-primary mt-4">返回</button>
                </div>
            </Layout>
        );
    }

    const isCompleted = rollCall.status === 'completed';
    const isCancelled = rollCall.status === 'cancelled';
    const isInProgress = rollCall.status === 'in_progress';

    return (
        <Layout>
            <div className="space-y-6">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-4">
                        <button onClick={() => navigate('/roll-call')} className="text-gray-500 hover:text-gray-700">
                            <ArrowLeftIcon className="h-5 w-5" />
                        </button>
                        <div>
                            <h2 className="text-xl font-bold text-gray-900">
                                {rollCall.roll_call_type?.name} - {rollCall.class?.name}
                            </h2>
                            <p className="text-sm text-gray-500">
                                {format(new Date(rollCall.roll_call_time), 'yyyy-MM-dd HH:mm')}
                                {isCompleted && <span className="ml-2 text-green-600">(已完成)</span>}
                                {isCancelled && <span className="ml-2 text-red-600">(已取消)</span>}
                            </p>
                        </div>
                    </div>
                </div>

                {/* Stats Bar */}
                <div className="bg-white rounded-lg shadow p-4">
                    <div className={`grid gap-4 text-center ${isCompleted ? 'grid-cols-5' : 'grid-cols-4'}`}>
                        <div>
                            <div className="text-2xl font-bold text-gray-900">{totalCount}</div>
                            <div className="text-sm text-gray-500">应到</div>
                        </div>
                        <div>
                            <div className="text-2xl font-bold text-green-600">{presentCount}</div>
                            <div className="text-sm text-gray-500">已到</div>
                        </div>
                        <div>
                            <div className="text-2xl font-bold text-blue-600">{onLeaveCount}</div>
                            <div className="text-sm text-gray-500">请假</div>
                        </div>
                        {isCompleted && (
                            <div>
                                <div className="text-2xl font-bold text-red-600">{absentCount}</div>
                                <div className="text-sm text-gray-500">缺勤</div>
                            </div>
                        )}
                        <div>
                            <div className="text-2xl font-bold text-yellow-600">{pendingCount}</div>
                            <div className="text-sm text-gray-500">待点</div>
                        </div>
                    </div>
                </div>

                {/* Actions */}
                <div className="flex items-center gap-4">
                    <input
                        type="text"
                        placeholder="搜索学生..."
                        value={searchTerm}
                        onChange={(e) => setSearchTerm(e.target.value)}
                        className="input-field max-w-xs"
                    />
                    {isInProgress && (
                        <button
                            onClick={markAllPresent}
                            disabled={saving || pendingCount === 0}
                            className="btn-secondary"
                        >
                            全部签到
                        </button>
                    )}
                    {isInProgress && (
                        <span className="text-sm text-gray-500">提示：点击学生行可切换签到状态</span>
                    )}
                    {isCompleted && canModifyRecords && (
                        <span className="text-sm text-gray-500">提示：点击学生行可切换出勤状态</span>
                    )}
                </div>

                {/* Student List */}
                <div className="bg-white rounded-lg shadow overflow-hidden">
                    <div className="divide-y divide-gray-200">
                        {filteredRecords.map(record => {
                            const studentName = record.student?.user?.name || record.student?.name || '未知学生';
                            const studentNo = record.student?.student_no || '';
                            const displayName = studentNo ? `${studentNo} ${studentName}` : studentName;
                            const isPresent = record.status === 'present';
                            const isOnLeave = record.status === 'on_leave';
                            const isAbsent = record.status === 'absent';
                            const isPending = record.status === 'pending';

                            // Determine if row is clickable
                            // For in-progress: all non-leave students
                            // For completed/cancelled: only if user has modify permission and not on leave
                            const isRowClickable = !isOnLeave && !saving && (
                                rollCall?.status === 'in_progress' ||
                                ((isCompleted || isCancelled) && canModifyRecords)
                            );

                            // Handle row click - toggle present status
                            const handleRowClick = () => {
                                if (!isRowClickable) return;

                                if (rollCall?.status === 'in_progress') {
                                    // For in-progress: toggle between present and pending
                                    toggleStudent(record.student_id, !isPresent);
                                } else if ((isCompleted || isCancelled) && canModifyRecords) {
                                    // For completed: toggle between present and absent
                                    const newStatus = isPresent ? 'absent' : 'present';
                                    updateRecordStatus(record.id, newStatus);
                                }
                            };

                            return (
                                <div
                                    key={record.id}
                                    onClick={handleRowClick}
                                    className={`p-4 flex items-center justify-between transition-all duration-150 ${isOnLeave ? 'bg-blue-50' :
                                        isPresent ? 'bg-green-50' :
                                            isAbsent ? 'bg-red-50' : 'bg-white'
                                        } ${isRowClickable ? 'cursor-pointer hover:bg-opacity-80 active:scale-[0.99]' : ''}`}
                                >
                                    <div className="flex items-center gap-3">
                                        <div className={`w-10 h-10 rounded-full flex items-center justify-center text-white font-medium ${isOnLeave ? 'bg-blue-500' :
                                            isPresent ? 'bg-green-500' :
                                                isAbsent ? 'bg-red-500' : 'bg-gray-300'
                                            }`}>
                                            {studentName.charAt(0)}
                                        </div>
                                        <div>
                                            <div className="font-medium text-gray-900">{displayName}</div>
                                            {isOnLeave && record.leave_detail && (
                                                <div className="text-xs text-blue-600">{record.leave_detail}</div>
                                            )}
                                            {isPresent && record.marked_at && (
                                                <div className="text-xs text-gray-400">
                                                    签到于 {format(new Date(record.marked_at), 'HH:mm')}
                                                </div>
                                            )}
                                        </div>
                                    </div>

                                    <div className="flex items-center gap-2">
                                        {isOnLeave ? (
                                            <span className="px-3 py-1 rounded-full text-sm bg-blue-100 text-blue-800">
                                                请假
                                            </span>
                                        ) : isCompleted || isCancelled ? (
                                            /* Completed/Cancelled: show status badge */
                                            <div className={`p-2 rounded-full transition-colors ${isPresent
                                                ? 'bg-green-500 text-white'
                                                : 'bg-red-500 text-white'
                                                }`}>
                                                <CheckIcon className="h-5 w-5" />
                                            </div>
                                        ) : (
                                            /* In-progress: show simple check icon indicator */
                                            <div className={`p-2 rounded-full transition-colors ${isPresent
                                                ? 'bg-green-500 text-white'
                                                : 'bg-gray-100 text-gray-400'
                                                }`}>
                                                <CheckIcon className="h-5 w-5" />
                                            </div>
                                        )}
                                    </div>
                                </div>
                            );
                        })}

                        {filteredRecords.length === 0 && (
                            <div className="p-8 text-center text-gray-500">
                                {searchTerm ? '没有匹配的学生' : '暂无学生数据'}
                            </div>
                        )}
                    </div>
                </div>

                {/* Add bottom padding when action bar is visible */}
                {isInProgress && <div className="h-20" />}
            </div>

            {/* Bottom Fixed Action Bar */}
            {isInProgress && (
                <div className="fixed bottom-0 left-0 right-0 bg-white border-t shadow-lg z-50">
                    <div className="max-w-4xl mx-auto px-4 py-3 flex items-center justify-between gap-4">
                        <button
                            onClick={cancelRollCall}
                            disabled={saving}
                            className="px-6 py-3 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors font-medium"
                        >
                            取消点名
                        </button>
                        <button
                            onClick={completeRollCall}
                            disabled={saving}
                            className={`flex-1 max-w-xs px-6 py-3 rounded-lg text-white font-medium transition-all flex items-center justify-center gap-2 ${presentCount === totalCount - onLeaveCount
                                ? 'bg-green-500 hover:bg-green-600 shadow-lg'
                                : 'bg-indigo-600 hover:bg-indigo-700'
                                }`}
                        >
                            <CheckCircleIcon className="h-5 w-5" />
                            完成点名 ({presentCount}/{totalCount - onLeaveCount})
                        </button>
                    </div>
                </div>
            )}
        </Layout>
    );
}
