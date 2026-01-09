import React, { useState, useEffect } from 'react';
import axios from 'axios';
import { XMarkIcon, ArrowDownTrayIcon } from '@heroicons/react/24/outline';

export default function AttendanceExportModal({ isOpen, onClose, scope, selectedSemester }) {
    const [loading, setLoading] = useState(false);
    const [exporting, setExporting] = useState(false);
    const [options, setOptions] = useState({
        classes: [],
        leave_types: [],
        roll_call_types: []
    });

    // Form state
    const [selectedClasses, setSelectedClasses] = useState([]);
    const [studentRange, setStudentRange] = useState('all'); // 'all' or 'with_records'
    const [exportFormat, setExportFormat] = useState('count'); // 'count' or 'detail'
    const [selectedLeaveTypes, setSelectedLeaveTypes] = useState([]);
    const [includeRollCall, setIncludeRollCall] = useState(true);
    const [selectedRollCallTypes, setSelectedRollCallTypes] = useState([]);

    useEffect(() => {
        if (isOpen) {
            fetchOptions();
        }
    }, [isOpen]);

    // 合并相同名称的点名类型
    const mergeRollCallTypes = (rollCallTypes) => {
        const merged = {};
        rollCallTypes.forEach(rt => {
            if (!merged[rt.name]) {
                merged[rt.name] = {
                    name: rt.name,
                    ids: [rt.id]
                };
            } else {
                merged[rt.name].ids.push(rt.id);
            }
        });
        return Object.values(merged);
    };

    // 获取合并后的点名类型列表
    const mergedRollCallTypes = React.useMemo(() => {
        return mergeRollCallTypes(options.roll_call_types);
    }, [options.roll_call_types]);

    const fetchOptions = async () => {
        setLoading(true);
        try {
            const res = await axios.get('/attendance/export-options');
            setOptions(res.data);
            // Auto-select all classes
            setSelectedClasses(res.data.classes.map(c => c.id));
            // Auto-select all leave types
            setSelectedLeaveTypes(res.data.leave_types.map(lt => lt.id));
            // Auto-select all roll call types (all original IDs)
            setSelectedRollCallTypes(res.data.roll_call_types.map(rt => rt.id));
        } catch (err) {
            console.error('Failed to fetch export options:', err);
            alert('获取导出选项失败: ' + (err.response?.data?.error || err.message));
        } finally {
            setLoading(false);
        }
    };

    const handleExport = async () => {
        if (selectedClasses.length === 0) {
            alert('请至少选择一个班级');
            return;
        }

        setExporting(true);
        try {
            const params = {
                scope,
                semester_id: selectedSemester || undefined,
                class_ids: selectedClasses,
                student_range: studentRange,
                export_format: exportFormat,
                leave_type_ids: selectedLeaveTypes,
                include_roll_call: includeRollCall ? 1 : 0,
                roll_call_type_ids: includeRollCall ? selectedRollCallTypes : [],
            };

            const response = await axios.get('/attendance/export', {
                params,
                responseType: 'blob',
            });

            // Check if response is an error (JSON)
            const contentType = response.headers['content-type'];
            if (contentType && contentType.includes('application/json')) {
                const text = await response.data.text();
                const json = JSON.parse(text);
                alert(json.error || '导出失败');
                return;
            }

            // Get filename from header or generate default
            const contentDisposition = response.headers['content-disposition'];
            let filename = '考勤记录.xlsx';
            if (contentDisposition) {
                const match = contentDisposition.match(/filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/);
                if (match && match[1]) {
                    filename = decodeURIComponent(match[1].replace(/['"]/g, ''));
                }
            }

            // Create download link
            const url = window.URL.createObjectURL(new Blob([response.data]));
            const link = document.createElement('a');
            link.href = url;
            link.setAttribute('download', filename);
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.URL.revokeObjectURL(url);

            onClose();
        } catch (err) {
            console.error('Export failed:', err);
            if (err.response?.data instanceof Blob) {
                const text = await err.response.data.text();
                try {
                    const json = JSON.parse(text);
                    alert('导出失败: ' + (json.error || json.message || '未知错误'));
                } catch {
                    alert('导出失败');
                }
            } else {
                alert('导出失败: ' + (err.response?.data?.error || err.message));
            }
        } finally {
            setExporting(false);
        }
    };

    const toggleAllClasses = () => {
        if (selectedClasses.length === options.classes.length) {
            setSelectedClasses([]);
        } else {
            setSelectedClasses(options.classes.map(c => c.id));
        }
    };

    const toggleAllLeaveTypes = () => {
        if (selectedLeaveTypes.length === options.leave_types.length) {
            setSelectedLeaveTypes([]);
        } else {
            setSelectedLeaveTypes(options.leave_types.map(lt => lt.id));
        }
    };

    const toggleAllRollCallTypes = () => {
        if (selectedRollCallTypes.length === options.roll_call_types.length) {
            setSelectedRollCallTypes([]);
        } else {
            // 选择所有原始 ID
            setSelectedRollCallTypes(options.roll_call_types.map(rt => rt.id));
        }
    };

    // 检查某个合并后的类型是否被选中（需要其所有 IDs 都被选中）
    const isMergedTypeSelected = (mergedType) => {
        return mergedType.ids.every(id => selectedRollCallTypes.includes(id));
    };

    // 切换合并后的类型选中状态
    const toggleMergedType = (mergedType, checked) => {
        if (checked) {
            // 添加该类型的所有 IDs
            const newIds = [...new Set([...selectedRollCallTypes, ...mergedType.ids])];
            setSelectedRollCallTypes(newIds);
        } else {
            // 移除该类型的所有 IDs
            setSelectedRollCallTypes(selectedRollCallTypes.filter(id => !mergedType.ids.includes(id)));
        }
    };

    const scopeLabels = {
        today: '今日',
        week: '本周',
        month: '本月',
        semester: '本学期'
    };

    if (!isOpen) return null;

    return (
        <div className="fixed inset-0 bg-gray-500 bg-opacity-75 flex items-center justify-center z-50">
            <div className="bg-white rounded-lg shadow-xl max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto">
                {/* Header */}
                <div className="flex items-center justify-between p-4 border-b">
                    <h3 className="text-lg font-medium text-gray-900">导出考勤记录</h3>
                    <button onClick={onClose} className="text-gray-400 hover:text-gray-500">
                        <XMarkIcon className="h-6 w-6" />
                    </button>
                </div>

                {loading ? (
                    <div className="p-8 text-center">
                        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
                        <p className="mt-2 text-gray-500">加载中...</p>
                    </div>
                ) : (
                    <div className="p-4 space-y-6">
                        {/* Time Range Display */}
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">时间范围</label>
                            <div className="text-sm text-gray-600 bg-gray-50 px-3 py-2 rounded">
                                {scopeLabels[scope] || scope}
                            </div>
                        </div>

                        {/* Class Selection */}
                        <div>
                            <div className="flex items-center justify-between mb-2">
                                <label className="block text-sm font-medium text-gray-700">班级选择</label>
                                <button
                                    type="button"
                                    onClick={toggleAllClasses}
                                    className="text-xs text-indigo-600 hover:text-indigo-800"
                                >
                                    {selectedClasses.length === options.classes.length ? '取消全选' : '全选'}
                                </button>
                            </div>
                            <div className="grid grid-cols-2 sm:grid-cols-3 gap-2 max-h-40 overflow-y-auto border rounded p-2">
                                {options.classes.map(cls => (
                                    <label key={cls.id} className="flex items-center text-sm">
                                        <input
                                            type="checkbox"
                                            checked={selectedClasses.includes(cls.id)}
                                            onChange={(e) => {
                                                if (e.target.checked) {
                                                    setSelectedClasses([...selectedClasses, cls.id]);
                                                } else {
                                                    setSelectedClasses(selectedClasses.filter(id => id !== cls.id));
                                                }
                                            }}
                                            className="h-4 w-4 text-indigo-600 rounded"
                                        />
                                        <span className="ml-2">{cls.name}</span>
                                    </label>
                                ))}
                            </div>
                        </div>

                        {/* Student Range */}
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-2">学生范围</label>
                            <div className="space-y-2">
                                <label className="flex items-center text-sm">
                                    <input
                                        type="radio"
                                        name="studentRange"
                                        value="all"
                                        checked={studentRange === 'all'}
                                        onChange={() => setStudentRange('all')}
                                        className="h-4 w-4 text-indigo-600"
                                    />
                                    <span className="ml-2">全部学生(按学号)</span>
                                </label>
                                <label className="flex items-center text-sm">
                                    <input
                                        type="radio"
                                        name="studentRange"
                                        value="with_records"
                                        checked={studentRange === 'with_records'}
                                        onChange={() => setStudentRange('with_records')}
                                        className="h-4 w-4 text-indigo-600"
                                    />
                                    <span className="ml-2">仅有考勤记录的学生</span>
                                </label>
                            </div>
                        </div>

                        {/* Export Format */}
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-2">导出格式</label>
                            <div className="space-y-2">
                                <label className="flex items-center text-sm">
                                    <input
                                        type="radio"
                                        name="exportFormat"
                                        value="count"
                                        checked={exportFormat === 'count'}
                                        onChange={() => setExportFormat('count')}
                                        className="h-4 w-4 text-indigo-600"
                                    />
                                    <span className="ml-2">汇总次数 (如: 2天, 3次)</span>
                                </label>
                                <label className="flex items-center text-sm">
                                    <input
                                        type="radio"
                                        name="exportFormat"
                                        value="detail"
                                        checked={exportFormat === 'detail'}
                                        onChange={() => setExportFormat('detail')}
                                        className="h-4 w-4 text-indigo-600"
                                    />
                                    <span className="ml-2">详细信息 (如: 2026-01-09标记(早操))</span>
                                </label>
                            </div>
                        </div>

                        {/* Leave Types */}
                        <div>
                            <div className="flex items-center justify-between mb-2">
                                <label className="block text-sm font-medium text-gray-700">请假类型</label>
                                <button
                                    type="button"
                                    onClick={toggleAllLeaveTypes}
                                    className="text-xs text-indigo-600 hover:text-indigo-800"
                                >
                                    {selectedLeaveTypes.length === options.leave_types.length ? '取消全选' : '全选'}
                                </button>
                            </div>
                            <div className="flex flex-wrap gap-3 border rounded p-2">
                                {options.leave_types.map(lt => (
                                    <label key={lt.id} className="flex items-center text-sm">
                                        <input
                                            type="checkbox"
                                            checked={selectedLeaveTypes.includes(lt.id)}
                                            onChange={(e) => {
                                                if (e.target.checked) {
                                                    setSelectedLeaveTypes([...selectedLeaveTypes, lt.id]);
                                                } else {
                                                    setSelectedLeaveTypes(selectedLeaveTypes.filter(id => id !== lt.id));
                                                }
                                            }}
                                            className="h-4 w-4 text-indigo-600 rounded"
                                        />
                                        <span className="ml-2">{lt.name}</span>
                                    </label>
                                ))}
                            </div>
                        </div>

                        {/* Roll Call Types */}
                        <div>
                            <div className="flex items-center mb-2">
                                <input
                                    type="checkbox"
                                    checked={includeRollCall}
                                    onChange={(e) => setIncludeRollCall(e.target.checked)}
                                    className="h-4 w-4 text-indigo-600 rounded"
                                />
                                <label className="ml-2 text-sm font-medium text-gray-700">包含点名记录</label>
                            </div>
                            {includeRollCall && mergedRollCallTypes.length > 0 && (
                                <div className="ml-6">
                                    <div className="flex items-center justify-between mb-2">
                                        <span className="text-sm text-gray-500">点名类型</span>
                                        <button
                                            type="button"
                                            onClick={toggleAllRollCallTypes}
                                            className="text-xs text-indigo-600 hover:text-indigo-800"
                                        >
                                            {selectedRollCallTypes.length === options.roll_call_types.length ? '取消全选' : '全选'}
                                        </button>
                                    </div>
                                    <div className="flex flex-wrap gap-3 border rounded p-2">
                                        {mergedRollCallTypes.map(rt => (
                                            <label key={rt.name} className="flex items-center text-sm">
                                                <input
                                                    type="checkbox"
                                                    checked={isMergedTypeSelected(rt)}
                                                    onChange={(e) => toggleMergedType(rt, e.target.checked)}
                                                    className="h-4 w-4 text-indigo-600 rounded"
                                                />
                                                <span className="ml-2">{rt.name}</span>
                                            </label>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>
                )}

                {/* Footer */}
                <div className="flex items-center justify-end gap-3 p-4 border-t bg-gray-50">
                    <button
                        onClick={onClose}
                        className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
                    >
                        取消
                    </button>
                    <button
                        onClick={handleExport}
                        disabled={exporting || loading || selectedClasses.length === 0}
                        className="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-indigo-600 border border-transparent rounded-md hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        <ArrowDownTrayIcon className="h-4 w-4 mr-2" />
                        {exporting ? '导出中...' : '导出 Excel'}
                    </button>
                </div>
            </div>
        </div>
    );
}
