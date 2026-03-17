import React, { useState, useEffect, useMemo } from 'react';
import axios from 'axios';
import { XMarkIcon, ArrowDownTrayIcon } from '@heroicons/react/24/outline';
import { format, startOfMonth, endOfMonth, addWeeks, parseISO } from 'date-fns';
import { zhCN } from 'date-fns/locale';

export default function AttendanceExportModal({ isOpen, onClose, scope, selectedSemester }) {
    const [loading, setLoading] = useState(false);
    const [exporting, setExporting] = useState(false);
    const [options, setOptions] = useState({
        classes: [],
        leave_types: [],
        roll_call_types: [],
        semesters: []
    });

    // Form state
    const [selectedClasses, setSelectedClasses] = useState([]);
    const [studentRange, setStudentRange] = useState('all');
    const [exportFormat, setExportFormat] = useState('count');
    const [selectedLeaveTypes, setSelectedLeaveTypes] = useState([]);
    const [includeRollCall, setIncludeRollCall] = useState(true);
    const [selectedRollCallTypes, setSelectedRollCallTypes] = useState([]);

    // Time range state
    const [customStartDate, setCustomStartDate] = useState('');
    const [customEndDate, setCustomEndDate] = useState('');
    const [selectedWeeks, setSelectedWeeks] = useState([]);
    const [selectedMonths, setSelectedMonths] = useState([]);
    const [exportSemesterId, setExportSemesterId] = useState('');

    useEffect(() => {
        if (isOpen) {
            fetchOptions();
        }
    }, [isOpen]);

    // 初始化默认时间范围
    useEffect(() => {
        if (!isOpen) return;
        const today = format(new Date(), 'yyyy-MM-dd');

        if (scope === 'today') {
            setCustomStartDate(today);
            setCustomEndDate(today);
        } else if (scope === 'week') {
            const currentSemester = getCurrentSemester();
            if (currentSemester) {
                const weekNum = getCurrentWeekNumber(currentSemester);
                setSelectedWeeks(weekNum > 0 ? [weekNum] : []);
            }
        } else if (scope === 'month') {
            const currentMonth = format(new Date(), 'yyyy-MM');
            setSelectedMonths([currentMonth]);
        } else if (scope === 'semester') {
            setExportSemesterId(selectedSemester || '');
        }
    }, [isOpen, scope, selectedSemester, options.semesters]);

    const getCurrentSemester = () => {
        if (!options.semesters?.length) return null;
        if (selectedSemester) {
            return options.semesters.find(s => String(s.id) === String(selectedSemester));
        }
        return options.semesters.find(s => s.is_current) || options.semesters[0];
    };

    const getCurrentWeekNumber = (semester) => {
        if (!semester?.start_date) return 0;
        const start = parseISO(semester.start_date);
        const now = new Date();
        const diffMs = now - start;
        const diffWeeks = Math.floor(diffMs / (7 * 24 * 60 * 60 * 1000));
        return diffWeeks >= 0 && diffWeeks < (semester.total_weeks || 20) ? diffWeeks + 1 : 0;
    };

    // 生成学期的周列表
    const weekOptions = useMemo(() => {
        const semester = getCurrentSemester();
        if (!semester?.start_date || !semester?.total_weeks) return [];
        const weeks = [];
        const start = parseISO(semester.start_date);
        for (let i = 0; i < semester.total_weeks; i++) {
            const weekStart = addWeeks(start, i);
            const weekEnd = addWeeks(start, i + 1);
            weekEnd.setDate(weekEnd.getDate() - 1);
            weeks.push({
                num: i + 1,
                label: `第${i + 1}周`,
                start: format(weekStart, 'MM/dd'),
                end: format(weekEnd, 'MM/dd'),
            });
        }
        return weeks;
    }, [options.semesters, selectedSemester]);

    // 生成学期包含的月份列表
    const monthOptions = useMemo(() => {
        const semester = getCurrentSemester();
        if (!semester?.start_date || !semester?.total_weeks) return [];
        const start = parseISO(semester.start_date);
        const end = addWeeks(start, semester.total_weeks);
        const months = [];
        const current = new Date(start.getFullYear(), start.getMonth(), 1);
        while (current <= end) {
            months.push({
                value: format(current, 'yyyy-MM'),
                label: format(current, 'yyyy年M月', { locale: zhCN }),
            });
            current.setMonth(current.getMonth() + 1);
        }
        return months;
    }, [options.semesters, selectedSemester]);

    // 计算最终的导出日期范围
    const getExportDateRange = () => {
        const semester = getCurrentSemester();

        if (scope === 'today') {
            return { start_date: customStartDate, end_date: customEndDate };
        } else if (scope === 'week') {
            if (selectedWeeks.length === 0 || !semester?.start_date) return null;
            const semStart = parseISO(semester.start_date);
            const sorted = [...selectedWeeks].sort((a, b) => a - b);
            const firstWeekStart = addWeeks(semStart, sorted[0] - 1);
            const lastWeekEnd = addWeeks(semStart, sorted[sorted.length - 1]);
            lastWeekEnd.setDate(lastWeekEnd.getDate() - 1);
            return {
                start_date: format(firstWeekStart, 'yyyy-MM-dd'),
                end_date: format(lastWeekEnd, 'yyyy-MM-dd'),
            };
        } else if (scope === 'month') {
            if (selectedMonths.length === 0) return null;
            const sorted = [...selectedMonths].sort();
            const firstStart = startOfMonth(parseISO(sorted[0] + '-01'));
            const lastEnd = endOfMonth(parseISO(sorted[sorted.length - 1] + '-01'));
            return {
                start_date: format(firstStart, 'yyyy-MM-dd'),
                end_date: format(lastEnd, 'yyyy-MM-dd'),
            };
        } else if (scope === 'semester') {
            return { semester_id: exportSemesterId || selectedSemester };
        }
        return null;
    };

    // 合并相同名称的点名类型
    const mergeRollCallTypes = (rollCallTypes) => {
        const merged = {};
        rollCallTypes.forEach(rt => {
            if (!merged[rt.name]) {
                merged[rt.name] = { name: rt.name, ids: [rt.id] };
            } else {
                merged[rt.name].ids.push(rt.id);
            }
        });
        return Object.values(merged);
    };

    const mergedRollCallTypes = useMemo(() => {
        return mergeRollCallTypes(options.roll_call_types);
    }, [options.roll_call_types]);

    const fetchOptions = async () => {
        setLoading(true);
        try {
            const res = await axios.get('/attendance/export-options');
            setOptions(res.data);
            setSelectedClasses(res.data.classes.map(c => c.id));
            setSelectedLeaveTypes(res.data.leave_types.map(lt => lt.id));
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
        if (scope === 'week' && selectedWeeks.length === 0) {
            alert('请至少选择一个周');
            return;
        }
        if (scope === 'month' && selectedMonths.length === 0) {
            alert('请至少选择一个月份');
            return;
        }

        const dateRange = getExportDateRange();

        setExporting(true);
        try {
            const params = {
                scope,
                semester_id: scope === 'semester'
                    ? (exportSemesterId || selectedSemester || undefined)
                    : (selectedSemester || undefined),
                class_ids: selectedClasses,
                student_range: studentRange,
                export_format: exportFormat,
                leave_type_ids: selectedLeaveTypes,
                include_roll_call: includeRollCall ? 1 : 0,
                roll_call_type_ids: includeRollCall ? selectedRollCallTypes : [],
            };

            // 添加自定义日期覆盖
            if (dateRange?.start_date) {
                params.start_date = dateRange.start_date;
                params.end_date = dateRange.end_date;
            }

            const response = await axios.get('/attendance/export', {
                params,
                responseType: 'blob',
            });

            const contentType = response.headers['content-type'];
            if (contentType && contentType.includes('application/json')) {
                const text = await response.data.text();
                const json = JSON.parse(text);
                alert(json.error || '导出失败');
                return;
            }

            const contentDisposition = response.headers['content-disposition'];
            let filename = '考勤记录.xlsx';
            if (contentDisposition) {
                const match = contentDisposition.match(/filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/);
                if (match && match[1]) {
                    filename = decodeURIComponent(match[1].replace(/['"]/g, ''));
                }
            }

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
        setSelectedClasses(selectedClasses.length === options.classes.length ? [] : options.classes.map(c => c.id));
    };

    const toggleAllLeaveTypes = () => {
        setSelectedLeaveTypes(selectedLeaveTypes.length === options.leave_types.length ? [] : options.leave_types.map(lt => lt.id));
    };

    const toggleAllRollCallTypes = () => {
        setSelectedRollCallTypes(selectedRollCallTypes.length === options.roll_call_types.length ? [] : options.roll_call_types.map(rt => rt.id));
    };

    const isMergedTypeSelected = (mergedType) => mergedType.ids.every(id => selectedRollCallTypes.includes(id));

    const toggleMergedType = (mergedType, checked) => {
        if (checked) {
            setSelectedRollCallTypes([...new Set([...selectedRollCallTypes, ...mergedType.ids])]);
        } else {
            setSelectedRollCallTypes(selectedRollCallTypes.filter(id => !mergedType.ids.includes(id)));
        }
    };

    const toggleWeek = (weekNum) => {
        setSelectedWeeks(prev =>
            prev.includes(weekNum) ? prev.filter(w => w !== weekNum) : [...prev, weekNum]
        );
    };

    const toggleMonth = (monthVal) => {
        setSelectedMonths(prev =>
            prev.includes(monthVal) ? prev.filter(m => m !== monthVal) : [...prev, monthVal]
        );
    };

    const scopeLabels = { today: '今日', week: '本周', month: '本月', semester: '本学期' };

    if (!isOpen) return null;

    // 渲染时间范围选择器
    const renderTimeRangeSelector = () => {
        if (scope === 'today') {
            return (
                <div className="flex items-center gap-2">
                    <input
                        type="date"
                        value={customStartDate}
                        onChange={(e) => setCustomStartDate(e.target.value)}
                        className="border border-gray-300 rounded px-2 py-1.5 text-sm focus:ring-indigo-500 focus:border-indigo-500"
                    />
                    <span className="text-gray-500 text-sm">至</span>
                    <input
                        type="date"
                        value={customEndDate}
                        onChange={(e) => setCustomEndDate(e.target.value)}
                        className="border border-gray-300 rounded px-2 py-1.5 text-sm focus:ring-indigo-500 focus:border-indigo-500"
                    />
                </div>
            );
        }

        if (scope === 'week') {
            const currentWeekNum = getCurrentWeekNumber(getCurrentSemester());
            return (
                <div>
                    <div className="flex items-center justify-between mb-1">
                        <span className="text-xs text-gray-500">
                            {getCurrentSemester()?.name || ''}
                            {selectedWeeks.length > 0 && ` · 已选 ${selectedWeeks.length} 周`}
                        </span>
                        <div className="flex gap-2">
                            <button type="button" onClick={() => setSelectedWeeks(weekOptions.map(w => w.num))} className="text-xs text-indigo-600 hover:text-indigo-800">全选</button>
                            <button type="button" onClick={() => setSelectedWeeks([])} className="text-xs text-indigo-600 hover:text-indigo-800">清空</button>
                        </div>
                    </div>
                    <div className="grid grid-cols-4 sm:grid-cols-5 gap-1.5 max-h-48 overflow-y-auto border rounded p-2">
                        {weekOptions.map(w => (
                            <button
                                key={w.num}
                                type="button"
                                onClick={() => toggleWeek(w.num)}
                                className={`px-2 py-1.5 text-xs rounded border transition-colors ${
                                    selectedWeeks.includes(w.num)
                                        ? 'bg-indigo-600 text-white border-indigo-600'
                                        : w.num === currentWeekNum
                                            ? 'bg-indigo-50 text-indigo-700 border-indigo-300 hover:bg-indigo-100'
                                            : 'bg-white text-gray-700 border-gray-200 hover:bg-gray-50'
                                }`}
                                title={`${w.start}-${w.end}`}
                            >
                                {w.label}
                            </button>
                        ))}
                    </div>
                </div>
            );
        }

        if (scope === 'month') {
            const currentMonth = format(new Date(), 'yyyy-MM');
            return (
                <div>
                    <div className="flex items-center justify-between mb-1">
                        <span className="text-xs text-gray-500">
                            {getCurrentSemester()?.name || ''}
                            {selectedMonths.length > 0 && ` · 已选 ${selectedMonths.length} 个月`}
                        </span>
                        <div className="flex gap-2">
                            <button type="button" onClick={() => setSelectedMonths(monthOptions.map(m => m.value))} className="text-xs text-indigo-600 hover:text-indigo-800">全选</button>
                            <button type="button" onClick={() => setSelectedMonths([])} className="text-xs text-indigo-600 hover:text-indigo-800">清空</button>
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2 border rounded p-2">
                        {monthOptions.map(m => (
                            <button
                                key={m.value}
                                type="button"
                                onClick={() => toggleMonth(m.value)}
                                className={`px-3 py-1.5 text-sm rounded border transition-colors ${
                                    selectedMonths.includes(m.value)
                                        ? 'bg-indigo-600 text-white border-indigo-600'
                                        : m.value === currentMonth
                                            ? 'bg-indigo-50 text-indigo-700 border-indigo-300 hover:bg-indigo-100'
                                            : 'bg-white text-gray-700 border-gray-200 hover:bg-gray-50'
                                }`}
                            >
                                {m.label}
                            </button>
                        ))}
                    </div>
                </div>
            );
        }

        if (scope === 'semester') {
            return (
                <select
                    value={exportSemesterId || selectedSemester || ''}
                    onChange={(e) => setExportSemesterId(e.target.value)}
                    className="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:ring-indigo-500 focus:border-indigo-500"
                >
                    {options.semesters.map(s => (
                        <option key={s.id} value={s.id}>{s.name}{s.is_current ? ' (当前)' : ''}</option>
                    ))}
                </select>
            );
        }

        return <div className="text-sm text-gray-600 bg-gray-50 px-3 py-2 rounded">{scopeLabels[scope] || scope}</div>;
    };

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
                        {/* Time Range Selection */}
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">
                                时间范围
                                <span className="ml-2 text-xs text-gray-400 font-normal">
                                    （{scopeLabels[scope]}模式）
                                </span>
                            </label>
                            {renderTimeRangeSelector()}
                        </div>

                        {/* Class Selection */}
                        <div>
                            <div className="flex items-center justify-between mb-2">
                                <label className="block text-sm font-medium text-gray-700">班级选择</label>
                                <button type="button" onClick={toggleAllClasses} className="text-xs text-indigo-600 hover:text-indigo-800">
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
                                    <input type="radio" name="studentRange" value="all" checked={studentRange === 'all'} onChange={() => setStudentRange('all')} className="h-4 w-4 text-indigo-600" />
                                    <span className="ml-2">全部学生(按学号)</span>
                                </label>
                                <label className="flex items-center text-sm">
                                    <input type="radio" name="studentRange" value="with_records" checked={studentRange === 'with_records'} onChange={() => setStudentRange('with_records')} className="h-4 w-4 text-indigo-600" />
                                    <span className="ml-2">仅有考勤记录的学生</span>
                                </label>
                            </div>
                        </div>

                        {/* Export Format */}
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-2">导出格式</label>
                            <div className="space-y-2">
                                <label className="flex items-center text-sm">
                                    <input type="radio" name="exportFormat" value="count" checked={exportFormat === 'count'} onChange={() => setExportFormat('count')} className="h-4 w-4 text-indigo-600" />
                                    <span className="ml-2">汇总次数 (如: 2天, 3次)</span>
                                </label>
                                <label className="flex items-center text-sm">
                                    <input type="radio" name="exportFormat" value="detail" checked={exportFormat === 'detail'} onChange={() => setExportFormat('detail')} className="h-4 w-4 text-indigo-600" />
                                    <span className="ml-2">详细信息 (如: 2026-01-09标记(早操))</span>
                                </label>
                            </div>
                        </div>

                        {/* Leave Types */}
                        <div>
                            <div className="flex items-center justify-between mb-2">
                                <label className="block text-sm font-medium text-gray-700">请假类型</label>
                                <button type="button" onClick={toggleAllLeaveTypes} className="text-xs text-indigo-600 hover:text-indigo-800">
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
                                <input type="checkbox" checked={includeRollCall} onChange={(e) => setIncludeRollCall(e.target.checked)} className="h-4 w-4 text-indigo-600 rounded" />
                                <label className="ml-2 text-sm font-medium text-gray-700">包含点名记录</label>
                            </div>
                            {includeRollCall && mergedRollCallTypes.length > 0 && (
                                <div className="ml-6">
                                    <div className="flex items-center justify-between mb-2">
                                        <span className="text-sm text-gray-500">点名类型</span>
                                        <button type="button" onClick={toggleAllRollCallTypes} className="text-xs text-indigo-600 hover:text-indigo-800">
                                            {selectedRollCallTypes.length === options.roll_call_types.length ? '取消全选' : '全选'}
                                        </button>
                                    </div>
                                    <div className="flex flex-wrap gap-3 border rounded p-2">
                                        {mergedRollCallTypes.map(rt => (
                                            <label key={rt.name} className="flex items-center text-sm">
                                                <input type="checkbox" checked={isMergedTypeSelected(rt)} onChange={(e) => toggleMergedType(rt, e.target.checked)} className="h-4 w-4 text-indigo-600 rounded" />
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
                    <button onClick={onClose} className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">
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
