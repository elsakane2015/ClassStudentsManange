import React, { useState, useEffect } from 'react';
import Layout from '../../components/Layout';
import axios from 'axios';
import { format, parseISO } from 'date-fns';
import { useParams, useNavigate } from 'react-router-dom';
import { CheckIcon, XMarkIcon, ArrowLeftIcon, CheckCircleIcon, PencilIcon, MinusIcon, PlusIcon, LockClosedIcon } from '@heroicons/react/24/outline';
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
    const [inspectingLeaveRecord, setInspectingLeaveRecord] = useState(null);
    const [canModifyRecords, setCanModifyRecords] = useState(false);
    const isTeacherOrAdmin = ['teacher', 'admin', 'system_admin', 'school_admin', 'department_manager'].includes(user?.role);

    const isPendingApprovalLeave = (rec) =>
        rec?.leave_status === 'pending_leave' || (rec?.leave_detail || '').includes('待审批');

    // 列数配置，从 localStorage 读取，默认1列
    const [columnCount, setColumnCount] = useState(() => {
        const saved = localStorage.getItem('rollcall_column_count');
        return saved ? parseInt(saved, 10) : 1;
    });

    // 保存列数到 localStorage
    const updateColumnCount = (newCount) => {
        const clampedCount = Math.max(1, Math.min(6, newCount));
        setColumnCount(clampedCount);
        localStorage.setItem('rollcall_column_count', clampedCount.toString());
    };

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
        const now = new Date();
        const localTime = format(now, 'HH:mm');
        setRecords(prev => prev.map(r =>
            r.student_id === studentId
                ? { ...r, status: isPresent ? 'present' : 'pending', marked_at: isPresent ? now.toISOString() : null, marked_at_local: isPresent ? localTime : null }
                : r
        ));
    };

    // Update record status for completed roll calls (this still needs to save immediately)
    const updateRecordStatus = async (recordId, newStatus, leaveTypeId = null) => {
        setSaving(true);
        try {
            const payload = { status: newStatus };
            if (leaveTypeId) {
                payload.leave_type_id = leaveTypeId;
            }
            const res = await axios.put(`/roll-calls/${id}/records/${recordId}`, payload);
            const updated = res.data || {};
            // Update local state from authoritative backend response
            setRecords(prev => prev.map(r =>
                r.id === recordId
                    ? {
                        ...r,
                        ...updated,
                        student: updated.student || r.student,
                    }
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
        const now = new Date();
        const localTime = format(now, 'HH:mm');
        setRecords(prev => prev.map(r =>
            r.status === 'pending'
                ? { ...r, status: 'present', marked_at: now.toISOString(), marked_at_local: localTime }
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
    // 使用 rollCall.total_students 以保持与历史列表一致，如果没有则使用 records.length
    const totalCount = rollCall?.total_students || records.length;

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
                                {format(parseISO(rollCall.roll_call_time), 'yyyy-MM-dd HH:mm')}
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

                    {/* 列数控制 */}
                    <div className="flex items-center gap-2 ml-auto">
                        <button
                            onClick={() => updateColumnCount(columnCount - 1)}
                            disabled={columnCount <= 1}
                            className="p-1.5 rounded-lg border border-gray-300 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
                            title="减少列数"
                        >
                            <MinusIcon className="h-4 w-4" />
                        </button>
                        <span className="text-sm text-gray-600 min-w-[3rem] text-center">{columnCount}列</span>
                        <button
                            onClick={() => updateColumnCount(columnCount + 1)}
                            disabled={columnCount >= 6}
                            className="p-1.5 rounded-lg border border-gray-300 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
                            title="增加列数"
                        >
                            <PlusIcon className="h-4 w-4" />
                        </button>
                    </div>
                </div>

                {/* Student List */}
                <div className="bg-white rounded-lg shadow overflow-hidden">
                    <div
                        className="grid gap-px bg-gray-200"
                        style={{ gridTemplateColumns: `repeat(${columnCount}, minmax(0, 1fr))` }}
                    >
                        {filteredRecords.map(record => {
                            const studentName = record.student?.user?.name || record.student?.name || '未知学生';
                            const studentNo = record.student?.student_no || '';

                            // 始终使用紧凑显示：只显示姓名，头像显示学号尾号
                            const displayName = studentName;

                            // 头像显示学号尾号2位
                            const avatarContent = studentNo
                                ? studentNo.slice(-2)  // 取学号尾号2位
                                : studentName.charAt(0);  // 没有学号时显示姓氏

                            const isPresent = record.status === 'present';
                            const isOnLeave = record.status === 'on_leave';
                            const isPendingLeave = isOnLeave && isPendingApprovalLeave(record);
                            const isAbsent = record.status === 'absent';
                            const isPending = record.status === 'pending';
                            const leaveDetailText = record.leave_detail || record.leave_type?.name || '请假';

                            // Determine if row is clickable
                            // 请假学生始终可点击弹出只读「请假与去向详情」，绝不可由点名员修改状态
                            const isNonLeaveEditable = !isOnLeave && !saving && (
                                rollCall?.status === 'in_progress' ||
                                ((isCompleted || isCancelled) && canModifyRecords)
                            );
                            const isRowClickable = isOnLeave || isNonLeaveEditable;

                            // Handle row click - inspect leave detail for on_leave; toggle present for others
                            const handleRowClick = () => {
                                if (isOnLeave) {
                                    setInspectingLeaveRecord(record);
                                    return;
                                }
                                if (!isNonLeaveEditable) return;

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
                                    title={isOnLeave ? `${leaveDetailText}（点击查看完整请假与去向详情，不可修改）` : undefined}
                                    className={`p-3 flex items-center justify-between gap-2 transition-all duration-150 ${isOnLeave ? (isPendingLeave ? 'bg-amber-50' : 'bg-blue-50') :
                                        isPresent ? 'bg-green-50' :
                                            isAbsent ? 'bg-red-50' : 'bg-white'
                                        } ${isRowClickable ? 'cursor-pointer hover:bg-opacity-80 active:scale-[0.99]' : ''}`}
                                >
                                    <div className="flex items-center gap-3 min-w-0 flex-1">
                                        <div className={`w-10 h-10 rounded-full flex items-center justify-center text-white font-bold text-sm flex-shrink-0 ${isOnLeave ? (isPendingLeave ? 'bg-amber-500' : 'bg-blue-500') :
                                            isPresent ? 'bg-green-500' :
                                                isAbsent ? 'bg-red-500' : 'bg-gray-300'
                                            }`}>
                                            {avatarContent}
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center gap-1.5 min-w-0">
                                                <span className="font-medium text-gray-900 truncate">{displayName}</span>
                                                {isOnLeave && (
                                                    <LockClosedIcon className={`h-3.5 w-3.5 flex-shrink-0 ${isPendingLeave ? 'text-amber-600' : 'text-blue-600'}`} title="已锁定，不可修改" />
                                                )}
                                                {isPendingLeave && (
                                                    <span className="px-1.5 py-0.5 rounded text-[10px] font-medium bg-amber-100 text-amber-800 flex-shrink-0">
                                                        待审批
                                                    </span>
                                                )}
                                            </div>
                                            {/* 签到时间 - 所有列数都显示 */}
                                            {isPresent && record.marked_at_local && (
                                                <div className="text-xs text-gray-400">
                                                    签到于 {record.marked_at_local}
                                                </div>
                                            )}
                                            {/* 请假信息 - 2列及以上时显示在名字下方（完整事由/去向，点击可看全文） */}
                                            {isOnLeave && columnCount >= 2 && (
                                                <div className={`text-xs mt-0.5 break-words line-clamp-2 ${isPendingLeave ? 'text-amber-700' : 'text-blue-700'}`}>
                                                    {leaveDetailText}
                                                </div>
                                            )}
                                        </div>
                                    </div>

                                    {/* 右侧图标区域 - 1列时显示，2列及以上隐藏 */}
                                    {columnCount === 1 && (
                                        <div className="flex items-center gap-2 flex-shrink-0 max-w-[60%]">
                                            {isOnLeave ? (
                                                <span className={`px-3 py-1 rounded-lg text-xs sm:text-sm whitespace-normal break-words text-left leading-snug ${isPendingLeave ? 'bg-amber-100 text-amber-900' : 'bg-blue-100 text-blue-800'}`}>
                                                    {leaveDetailText}
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
                                    )}
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
            {/* Read-only Student Leave & Destination Detail Modal */}
            {inspectingLeaveRecord && (
                <div
                    className="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 p-4"
                    onClick={() => setInspectingLeaveRecord(null)}
                >
                    <div
                        className="bg-white rounded-xl shadow-xl max-w-md w-full overflow-hidden"
                        onClick={(e) => e.stopPropagation()}
                    >
                        <div className="px-5 py-4 border-b flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                <LockClosedIcon className="h-5 w-5 text-blue-600" />
                                <h3 className="text-base font-semibold text-gray-900">学生请假与去向详情</h3>
                            </div>
                            <button
                                type="button"
                                onClick={() => setInspectingLeaveRecord(null)}
                                className="text-gray-400 hover:text-gray-600"
                            >
                                <XMarkIcon className="h-5 w-5" />
                            </button>
                        </div>
                        <div className="px-5 py-4 space-y-3 text-sm">
                            <div className="flex items-center justify-between">
                                <div>
                                    <div className="font-semibold text-gray-900 text-base">
                                        {inspectingLeaveRecord.student?.user?.name || inspectingLeaveRecord.student?.name || '未知学生'}
                                    </div>
                                    {inspectingLeaveRecord.student?.student_no && (
                                        <div className="text-xs text-gray-500">学号：{inspectingLeaveRecord.student.student_no}</div>
                                    )}
                                </div>
                                <span className={`px-2.5 py-1 rounded-full text-xs font-medium ${isPendingApprovalLeave(inspectingLeaveRecord) ? 'bg-amber-100 text-amber-800' : 'bg-blue-100 text-blue-800'}`}>
                                    {isPendingApprovalLeave(inspectingLeaveRecord) ? '待审批请假' : (inspectingLeaveRecord.leave_type?.name || '请假 / 已标记')}
                                </span>
                            </div>

                            <div className={`p-3 rounded-lg border ${isPendingApprovalLeave(inspectingLeaveRecord) ? 'bg-amber-50 border-amber-200' : 'bg-blue-50 border-blue-200'}`}>
                                <div className="text-xs font-medium text-gray-600 mb-1">请假事由 / 备注与去向</div>
                                <div className="text-gray-900 whitespace-pre-wrap break-words leading-relaxed">
                                    {inspectingLeaveRecord.leave_detail || inspectingLeaveRecord.leave_type?.name || '请假'}
                                </div>
                            </div>

                            <p className="text-xs text-gray-500 leading-relaxed">
                                {isTeacherOrAdmin
                                    ? '该生在当前节次已有请假申请或考勤标记，点名过程中自动锁定以防误操作。'
                                    : '该生在当前节次已有请假申请或考勤标记，点名员仅可查看去向，不可修改状态。'}
                            </p>
                        </div>
                        <div className="px-5 py-3 bg-gray-50 border-t flex items-center justify-end gap-2">
                            {isTeacherOrAdmin && (isCompleted || isCancelled) && canModifyRecords && (
                                <>
                                    <button
                                        type="button"
                                        disabled={saving}
                                        onClick={() => {
                                            const recId = inspectingLeaveRecord.id;
                                            setInspectingLeaveRecord(null);
                                            updateRecordStatus(recId, 'present');
                                        }}
                                        className="px-3 py-1.5 text-xs font-medium rounded-lg border border-green-300 text-green-700 hover:bg-green-50"
                                    >
                                        改为已到
                                    </button>
                                    <button
                                        type="button"
                                        disabled={saving}
                                        onClick={() => {
                                            const recId = inspectingLeaveRecord.id;
                                            setInspectingLeaveRecord(null);
                                            updateRecordStatus(recId, 'absent');
                                        }}
                                        className="px-3 py-1.5 text-xs font-medium rounded-lg border border-red-300 text-red-700 hover:bg-red-50"
                                    >
                                        改为缺勤
                                    </button>
                                </>
                            )}
                            <button
                                type="button"
                                onClick={() => setInspectingLeaveRecord(null)}
                                className="px-4 py-1.5 text-sm font-medium rounded-lg bg-indigo-600 text-white hover:bg-indigo-700"
                            >
                                知道了
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </Layout>
    );
}
