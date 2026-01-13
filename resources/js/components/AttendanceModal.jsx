import React, { useState, useEffect, Fragment } from 'react';
import axios from 'axios';
import { format } from 'date-fns';
import { Dialog, Transition } from '@headlessui/react';

export default function AttendanceModal({ classId, onClose, onSuccess }) {
    const [selectedDate, setSelectedDate] = useState(format(new Date(), 'yyyy-MM-dd'));
    const [students, setStudents] = useState([]);
    const [loading, setLoading] = useState(true);
    const [selectedStudentIds, setSelectedStudentIds] = useState(new Set());
    const [leaveTypes, setLeaveTypes] = useState([]);
    const [periods, setPeriods] = useState([]);
    const [timeSlots, setTimeSlots] = useState([]);
    const [submitting, setSubmitting] = useState(false);

    // Dynamic Input State (for leave types with input requirements)
    const [pendingAction, setPendingAction] = useState(null); // { status, leaveType }
    const [inputModalOpen, setInputModalOpen] = useState(false);
    const [inputData, setInputData] = useState({});
    const [showPeriodDetail, setShowPeriodDetail] = useState(false);

    useEffect(() => {
        fetchLeaveTypes();
        fetchPeriods();
        fetchTimeSlots();
    }, []);

    useEffect(() => {
        if (selectedDate) {
            fetchStudents();
        }
    }, [selectedDate, classId]);

    const fetchLeaveTypes = async () => {
        try {
            const res = await axios.get('/leave-types');
            setLeaveTypes((res.data.data || res.data).filter(type => type.is_active));
        } catch (error) {
            console.error("Failed to load leave types", error);
        }
    };

    const fetchPeriods = async () => {
        try {
            const res = await axios.get('/class-periods');
            setPeriods(res.data.data || res.data || []);
        } catch (error) {
            console.error("Failed to load periods", error);
        }
    };

    const fetchTimeSlots = async () => {
        try {
            const res = await axios.get('/time-slots');
            setTimeSlots(res.data || []);
        } catch (error) {
            console.error("Failed to load time slots", error);
        }
    };

    const fetchStudents = async () => {
        setLoading(true);
        try {
            const res = await axios.get('/attendance/overview', { params: { date: selectedDate } });

            let allStudents = [];
            const data = Array.isArray(res.data) ? res.data : (res.data.data || []);

            data.forEach(dept => {
                const classes = dept.classes || [];
                if (Array.isArray(classes)) {
                    classes.forEach(cls => {
                        if (classId && cls.id !== classId) return;
                        if (Array.isArray(cls.students)) {
                            allStudents = [...allStudents, ...cls.students];
                        }
                    });
                }
            });

            console.log("Students fetched:", allStudents.length);
            setStudents(allStudents);
            setSelectedStudentIds(new Set());
        } catch (error) {
            console.error("Failed to fetch students", error);
        } finally {
            setLoading(false);
        }
    };

    const toggleSelection = (id) => {
        const newSet = new Set(selectedStudentIds);
        if (newSet.has(id)) newSet.delete(id);
        else newSet.add(id);
        setSelectedStudentIds(newSet);
    };

    const toggleAll = () => {
        if (selectedStudentIds.size === students.length) {
            setSelectedStudentIds(new Set());
        } else {
            setSelectedStudentIds(new Set(students.map(s => s.id)));
        }
    };

    const handleActionClick = async (typeOrStatus) => {
        if (selectedStudentIds.size === 0) {
            alert('请先选择学生');
            return;
        }

        if (typeOrStatus === 'present') {
            executeBulkUpdate('present', null, null);
            return;
        }

        // It's a leave type object
        const lt = typeOrStatus;

        // Map slug to status
        let status = 'leave';
        if (['late', 'absent', 'early_leave'].includes(lt.slug)) {
            status = lt.slug;
        }

        // Check if leave type has input requirements
        if (!lt.input_type || lt.input_type === 'none') {
            executeBulkUpdate(status, lt.id, null);
        } else {
            // Open Input Modal
            setPendingAction({ status, leaveType: lt });
            setInputData({});
            setInputModalOpen(true);
        }
    };

    const executeBulkUpdate = async (status, leaveTypeId, details) => {
        try {
            setSubmitting(true);

            const records = Array.from(selectedStudentIds).map(id => ({
                student_id: id,
                status: status,
                leave_type_id: leaveTypeId,
                details: details
            }));

            await axios.post('/attendance/bulk', {
                date: selectedDate,
                period_id: null,
                records: records
            });

            setInputModalOpen(false);
            setPendingAction(null);

            alert('考勤标记成功！');
            fetchStudents();
            if (onSuccess) onSuccess();
        } catch (error) {
            console.error("Failed to update", error);
            alert("更新失败: " + (error.response?.data?.message || error.message));
        } finally {
            setSubmitting(false);
        }
    };

    const handleInputConfirm = () => {
        if (!pendingAction) return;
        executeBulkUpdate(pendingAction.status, pendingAction.leaveType.id, inputData);
    };

    const StatusBadge = ({ status, leaveTypeId }) => {
        const styles = {
            present: 'bg-green-100 text-green-800',
            absent: 'bg-red-100 text-red-800',
            late: 'bg-yellow-100 text-yellow-800',
            leave: 'bg-blue-100 text-blue-800',
            early_leave: 'bg-orange-100 text-orange-800',
            unmarked: 'bg-gray-100 text-gray-400'
        };
        const labels = {
            present: '出勤',
            absent: '缺勤',
            late: '迟到',
            leave: '请假',
            early_leave: '早退',
            unmarked: '出勤'
        };

        const lt = leaveTypes.find(l => l.id === leaveTypeId);
        let label = labels[status] || status;
        if (lt) label = lt.name;

        const s = status || 'unmarked';
        return (
            <span className={`px-2 py-0.5 rounded text-xs font-medium ${styles[s] || styles.unmarked}`}>
                {label}
            </span>
        );
    };

    // Render input fields based on leave type configuration
    const renderInputFields = () => {
        if (!pendingAction?.leaveType) return null;

        const lt = pendingAction.leaveType;
        let config = {};
        try {
            config = typeof lt.input_config === 'string'
                ? JSON.parse(lt.input_config)
                : lt.input_config || {};
        } catch (e) {
            console.error('Failed to parse input_config:', e);
        }

        switch (lt.input_type) {
            case 'time':
                return (
                    <>
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-2">时间</label>
                            <input
                                type="time"
                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm border p-2"
                                value={inputData.time || ''}
                                onChange={e => setInputData({ ...inputData, time: e.target.value })}
                            />
                        </div>
                        {config.require_period && (
                            <div className="mt-4">
                                <label className="block text-sm font-medium text-gray-700 mb-2">选择节次</label>
                                <select
                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm border p-2"
                                    value={inputData.period || ''}
                                    onChange={e => setInputData({ ...inputData, period: parseInt(e.target.value) })}
                                >
                                    <option value="">请选择节次</option>
                                    {periods.map((p, index) => (
                                        <option key={p.id} value={p.id}>第{index + 1}节</option>
                                    ))}
                                </select>
                            </div>
                        )}
                    </>
                );

            case 'period_select':
                // Check if it has options (like morning_exercise/evening_exercise)
                if (config.options && config.options.length > 0) {
                    return (
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-2">选择选项</label>
                            <div className="space-y-2">
                                {config.options.map(opt => {
                                    const optKey = typeof opt === 'object' ? opt.key : opt;
                                    const optLabel = typeof opt === 'object' && opt.label ? opt.label : optKey;
                                    return (
                                        <label key={optKey} className="flex items-center">
                                            <input
                                                type="radio"
                                                name="period_option"
                                                className="h-4 w-4 text-indigo-600 border-gray-300 focus:ring-indigo-500"
                                                checked={inputData.option === optKey}
                                                onChange={() => setInputData({ ...inputData, option: optKey })}
                                            />
                                            <span className="ml-2 text-sm text-gray-700">{optLabel}</span>
                                        </label>
                                    );
                                })}
                            </div>
                        </div>
                    );
                }

                // Otherwise show period checkboxes (for absent)
                return (
                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-2">选择节次</label>
                        <div className="grid grid-cols-4 gap-2">
                            {periods.map((p, index) => (
                                <label
                                    key={p.id}
                                    className={`flex items-center justify-center p-2 border rounded cursor-pointer ${inputData.periods?.includes(p.id)
                                        ? 'bg-indigo-100 border-indigo-500 text-indigo-700'
                                        : 'hover:bg-gray-50'
                                        }`}
                                >
                                    <input
                                        type="checkbox"
                                        className="sr-only"
                                        checked={inputData.periods?.includes(p.id) || false}
                                        onChange={() => {
                                            const current = inputData.periods || [];
                                            if (current.includes(p.id)) {
                                                setInputData({ ...inputData, periods: current.filter(x => x !== p.id) });
                                            } else {
                                                setInputData({ ...inputData, periods: [...current, p.id] });
                                            }
                                        }}
                                    />
                                    第{index + 1}节
                                </label>
                            ))}
                        </div>
                    </div>
                );

            case 'duration_select':
                // 严格按照请假类型配置的 options 过滤系统时段
                if (!config.options || config.options.length === 0) {
                    return (
                        <div className="p-3 bg-yellow-50 rounded text-sm text-yellow-700">
                            该请假类型未配置可选时段
                        </div>
                    );
                }

                const allowedSlotKeys = config.options.map(opt =>
                    typeof opt === 'object' ? opt.key : opt
                );
                const filteredSlots = timeSlots.filter(slot => {
                    const slotKey = `time_slot_${slot.id}`;
                    return allowedSlotKeys.includes(slotKey) || allowedSlotKeys.includes(String(slot.id));
                });

                if (filteredSlots.length === 0) {
                    return (
                        <div className="p-3 bg-yellow-50 rounded text-sm text-yellow-700">
                            该请假类型配置的时段不存在于系统时段中
                        </div>
                    );
                }

                return (
                    <div className="space-y-3">
                        <label className="block text-sm font-medium text-gray-700">选择时段</label>
                        <div className="flex flex-wrap gap-2">
                            {filteredSlots.map(slot => (
                                <button
                                    key={slot.id}
                                    type="button"
                                    onClick={() => setInputData({
                                        ...inputData,
                                        time_slot_id: slot.id,
                                        time_slot_name: slot.name,
                                        period_ids: slot.period_ids || [],
                                        option_periods: (slot.period_ids || []).length
                                    })}
                                    className={`px-4 py-2 rounded-lg border text-sm transition-colors ${inputData.time_slot_id === slot.id
                                        ? 'bg-indigo-600 text-white border-indigo-600'
                                        : 'bg-white border-gray-300 hover:bg-gray-50'
                                        }`}
                                >
                                    {slot.name}
                                    <span className={`text-xs ml-1 ${inputData.time_slot_id === slot.id ? 'text-indigo-200' : 'text-gray-400'}`}>
                                        ({(slot.period_ids || []).length}节)
                                    </span>
                                </button>
                            ))}
                        </div>

                        {inputData.time_slot_id && (
                            <div className="mt-2">
                                <button
                                    type="button"
                                    onClick={() => setShowPeriodDetail(!showPeriodDetail)}
                                    className="text-xs text-indigo-600 hover:text-indigo-800"
                                >
                                    {showPeriodDetail ? '▲ 收起自定义节次' : '▼ 自定义节次（可选）'}
                                </button>
                                {showPeriodDetail && (
                                    <div className="mt-2 p-3 bg-gray-50 rounded border border-gray-200">
                                        <div className="text-xs text-gray-500 mb-2">
                                            点击可单独选择/取消节次：
                                        </div>
                                        <div className="flex flex-wrap gap-2">
                                            {periods.map(period => {
                                                const isSelected = inputData.period_ids?.includes(period.id);
                                                const selectedSlot = timeSlots.find(s => s.id === inputData.time_slot_id);
                                                const isInSlot = selectedSlot?.period_ids?.includes(period.id);
                                                return (
                                                    <label
                                                        key={period.id}
                                                        className={`px-3 py-1.5 rounded border text-sm cursor-pointer transition-colors ${isSelected
                                                            ? 'bg-indigo-100 border-indigo-500 text-indigo-700'
                                                            : isInSlot
                                                                ? 'bg-gray-100 border-gray-300 text-gray-500 hover:bg-gray-200'
                                                                : 'bg-white border-gray-200 text-gray-400 hover:bg-gray-50'
                                                            }`}
                                                    >
                                                        <input
                                                            type="checkbox"
                                                            className="sr-only"
                                                            checked={isSelected}
                                                            onChange={() => {
                                                                const currentIds = inputData.period_ids || [];
                                                                let newIds;
                                                                if (isSelected) {
                                                                    newIds = currentIds.filter(id => id !== period.id);
                                                                } else {
                                                                    newIds = [...currentIds, period.id];
                                                                }

                                                                // Check if new selection matches any time slot
                                                                const matchingSlot = timeSlots.find(slot => {
                                                                    const slotPeriods = slot.period_ids || [];
                                                                    return slotPeriods.length === newIds.length &&
                                                                        slotPeriods.every(id => newIds.includes(id));
                                                                });

                                                                if (matchingSlot) {
                                                                    // Matches a time slot, use its name
                                                                    setInputData({
                                                                        ...inputData,
                                                                        period_ids: newIds,
                                                                        option_periods: newIds.length,
                                                                        time_slot_id: matchingSlot.id,
                                                                        time_slot_name: matchingSlot.name,
                                                                        is_custom: false
                                                                    });
                                                                } else {
                                                                    // Custom selection, clear time slot info
                                                                    setInputData({
                                                                        ...inputData,
                                                                        period_ids: newIds,
                                                                        option_periods: newIds.length,
                                                                        time_slot_id: null,
                                                                        time_slot_name: null,
                                                                        is_custom: true
                                                                    });
                                                                }
                                                            }}
                                                        />
                                                        {period.name}
                                                    </label>
                                                );
                                            })}
                                        </div>
                                        <div className="mt-2 text-xs text-gray-500">
                                            已选：{(inputData.period_ids || []).length}节
                                            {inputData.is_custom && <span className="ml-2 text-indigo-600">(自定义)</span>}
                                        </div>
                                    </div>
                                )}
                            </div>
                        )}
                    </div>
                );

            default:
                return null;
        }
    };

    return (
        <Transition appear show={true} as={Fragment}>
            <Dialog as="div" className="relative z-50" onClose={onClose}>
                <div className="fixed inset-0 bg-black bg-opacity-25" />
                <div className="fixed inset-0 overflow-y-auto">
                    <div className="flex min-h-full items-center justify-center p-4 text-center">
                        <Dialog.Panel className="w-full max-w-5xl transform overflow-hidden rounded-2xl bg-white p-6 text-left align-middle shadow-xl transition-all">
                            <div className="flex justify-between items-center mb-4">
                                <Dialog.Title as="h3" className="text-lg font-medium leading-6 text-gray-900">
                                    管理班级考勤
                                </Dialog.Title>
                                <button onClick={onClose} className="text-gray-400 hover:text-gray-500">
                                    <span className="text-2xl">&times;</span>
                                </button>
                            </div>

                            {/* Date Selector */}
                            <div className="mb-4">
                                <label className="block text-sm font-medium text-gray-700 mb-2">选择日期</label>
                                <input
                                    type="date"
                                    value={selectedDate}
                                    onChange={(e) => setSelectedDate(e.target.value)}
                                    className="border border-gray-300 rounded px-3 py-2"
                                />
                            </div>

                            {/* Action Toolbar */}
                            <div className="flex flex-wrap gap-2 mb-4 p-4 bg-gray-50 rounded-lg border border-gray-100">
                                <span className="text-sm text-gray-500 flex items-center mr-2">批量标记 ({selectedStudentIds.size}人):</span>
                                <button
                                    onClick={() => handleActionClick('present')}
                                    disabled={submitting}
                                    className="px-3 py-1 bg-green-600 text-white rounded text-sm hover:bg-green-700 disabled:opacity-50"
                                >
                                    出勤
                                </button>

                                {leaveTypes.map(lt => (
                                    <button
                                        key={lt.id}
                                        onClick={() => handleActionClick(lt)}
                                        disabled={submitting}
                                        className={`px-3 py-1 text-white rounded text-sm disabled:opacity-50 ${['late', 'early_leave'].includes(lt.slug)
                                            ? 'bg-yellow-500 hover:bg-yellow-600'
                                            : (lt.slug === 'absent'
                                                ? 'bg-red-600 hover:bg-red-700'
                                                : 'bg-blue-600 hover:bg-blue-700')
                                            }`}
                                        title={lt.description}
                                    >
                                        {lt.name}
                                    </button>
                                ))}
                            </div>

                            {loading ? (
                                <div className="text-center py-8 text-gray-500">加载中...</div>
                            ) : (
                                <div className="overflow-y-auto max-h-[60vh] border rounded-md">
                                    <table className="min-w-full divide-y divide-gray-200">
                                        <thead className="bg-gray-50 sticky top-0">
                                            <tr>
                                                <th scope="col" className="px-6 py-3 text-left w-12">
                                                    <input
                                                        type="checkbox"
                                                        className="h-4 w-4 text-indigo-600 rounded"
                                                        checked={students.length > 0 && selectedStudentIds.size === students.length}
                                                        onChange={toggleAll}
                                                    />
                                                </th>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">学号</th>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">姓名</th>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">状态</th>
                                            </tr>
                                        </thead>
                                        <tbody className="bg-white divide-y divide-gray-200">
                                            {students.map(student => {
                                                const records = student.attendance || [];
                                                return (
                                                    <tr key={student.id} className={selectedStudentIds.has(student.id) ? 'bg-indigo-50' : 'hover:bg-gray-50'}>
                                                        <td className="px-6 py-4 whitespace-nowrap">
                                                            <input
                                                                type="checkbox"
                                                                className="h-4 w-4 text-indigo-600 rounded"
                                                                checked={selectedStudentIds.has(student.id)}
                                                                onChange={() => toggleSelection(student.id)}
                                                            />
                                                        </td>
                                                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500 font-mono">{student.student_no}</td>
                                                        <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{student.user?.name}</td>
                                                        <td className="px-6 py-4">
                                                            {records.length === 0 && (
                                                                <StatusBadge status="present" />
                                                            )}
                                                            {records.length > 0 && (
                                                                <div className="flex flex-wrap items-center gap-1">
                                                                    {records.map((record, idx) => (
                                                                        <StatusBadge
                                                                            key={idx}
                                                                            status={record.status}
                                                                            leaveTypeId={record.leave_type_id}
                                                                        />
                                                                    ))}
                                                                </div>
                                                            )}
                                                        </td>
                                                    </tr>
                                                );
                                            })}
                                            {students.length === 0 && !loading && (
                                                <tr>
                                                    <td colSpan="4" className="text-center py-4 text-gray-500">无学生数据</td>
                                                </tr>
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            )}

                            {/* Footer */}
                            <div className="mt-4 flex justify-end">
                                <button
                                    onClick={onClose}
                                    className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
                                >
                                    关闭
                                </button>
                            </div>
                        </Dialog.Panel>
                    </div>
                </div>

                {/* Second Dialog for Dynamic Input */}
                <Transition appear show={inputModalOpen} as={Fragment}>
                    <Dialog as="div" className="relative z-[60]" onClose={() => setInputModalOpen(false)}>
                        <div className="fixed inset-0 bg-black bg-opacity-30" />
                        <div className="fixed inset-0 overflow-y-auto">
                            <div className="flex min-h-full items-center justify-center p-4 text-center">
                                <Dialog.Panel className="w-full max-w-md transform overflow-visible rounded-2xl bg-white p-6 text-left align-middle shadow-xl transition-all" style={{ minHeight: '200px' }}>
                                    <Dialog.Title as="h3" className="text-lg font-medium leading-6 text-gray-900 mb-4">
                                        {pendingAction?.leaveType?.name} - 详细信息
                                    </Dialog.Title>

                                    <div className="space-y-4" style={{ minHeight: '100px' }}>
                                        {renderInputFields()}
                                    </div>

                                    <div className="mt-6 flex justify-end space-x-2">
                                        <button
                                            onClick={() => setInputModalOpen(false)}
                                            className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
                                        >
                                            取消
                                        </button>
                                        <button
                                            onClick={handleInputConfirm}
                                            disabled={submitting}
                                            className="px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-md hover:bg-indigo-700 disabled:opacity-50"
                                        >
                                            {submitting ? '提交中...' : '确定'}
                                        </button>
                                    </div>
                                </Dialog.Panel>
                            </div>
                        </div>
                    </Dialog>
                </Transition>
            </Dialog>
        </Transition>
    );
}
