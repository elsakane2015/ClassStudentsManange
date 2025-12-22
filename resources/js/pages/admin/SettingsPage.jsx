import React, { useState, useEffect } from 'react';
import Layout from '../../components/Layout';
import axios from 'axios';
import { format, addWeeks, startOfWeek, endOfWeek, eachDayOfInterval, isSameMonth, isSameDay, startOfMonth, endOfMonth, parseISO, addDays, getDay } from 'date-fns';
import { zhCN } from 'date-fns/locale';
import { PlusIcon, TrashIcon, PencilIcon, CheckCircleIcon } from '@heroicons/react/24/outline';

// Calendar Component
import { differenceInCalendarWeeks } from 'date-fns'; // Ensure this is imported

const CalendarSelector = ({ startDateStr, totalWeeks, holidays, onToggleDate, weekStartsOn = 1 }) => {
    if (!startDateStr || !totalWeeks) return null;

    const startDate = parseISO(startDateStr);
    if (isNaN(startDate)) return null;

    const endDate = addWeeks(startDate, totalWeeks);
    const months = [];
    let current = startOfMonth(startDate);

    while (current <= endDate) {
        months.push(current);
        current = addDays(endOfMonth(current), 1); // Next month
    }

    const weekDays = weekStartsOn === 1
        ? ['一', '二', '三', '四', '五', '六', '日']
        : ['日', '一', '二', '三', '四', '五', '六'];

    // Helper to chunk flat days array into weeks
    const chunkWeeks = (daysArr) => {
        const weeks = [];
        for (let i = 0; i < daysArr.length; i += 7) {
            weeks.push(daysArr.slice(i, i + 7));
        }
        return weeks;
    };

    return (
        <div className="mt-4 border-t pt-4">
            <h5 className="text-sm font-bold text-gray-700 mb-2">工作日设定 (点击日期标记为节假日/非工作日)</h5>
            <div className="space-y-4 max-h-96 overflow-y-auto border p-2 rounded bg-gray-50">
                {months.map(monthStart => {
                    const monthEnd = endOfMonth(monthStart);
                    const start = startOfWeek(monthStart, { weekStartsOn: weekStartsOn });
                    const end = endOfWeek(monthEnd, { weekStartsOn: weekStartsOn });
                    const days = eachDayOfInterval({ start, end });
                    const weeks = chunkWeeks(days);

                    return (
                        <div key={monthStart.toString()} className="bg-white p-3 rounded shadow-sm">
                            <h6 className="text-center font-bold text-gray-800 mb-2 border-b pb-1">
                                {format(monthStart, 'yyyy年 MM月')}
                            </h6>
                            {/* Grid Layout: 8 cols (1 for week num + 7 days) */}
                            <div className="grid grid-cols-8 gap-1 text-center text-xs">
                                {/* Header Row */}
                                <div className="font-semibold text-gray-400 flex items-center justify-center bg-gray-50 rounded">周</div>
                                {weekDays.map(d => <div key={d} className="font-semibold text-gray-500 flex items-center justify-center">{d}</div>)}

                                {/* Weeks Rows */}
                                {weeks.map((weekDaysRow, wIdx) => {
                                    // Calculate week number relative to semester start
                                    // weekStartsOn ensures logic matches display
                                    const weekFirstDay = weekDaysRow[0];
                                    // differenceInCalendarWeeks returns 0 for same week, so +1
                                    const weekNum = differenceInCalendarWeeks(weekFirstDay, startDate, { weekStartsOn: weekStartsOn }) + 1;
                                    const showWeekNum = weekNum > 0 && weekNum <= totalWeeks;

                                    return (
                                        <React.Fragment key={weekFirstDay.toISOString()}>
                                            {/* Week Number Cell */}
                                            <div className="flex items-center justify-center font-bold text-indigo-300 border-r border-gray-100 italic">
                                                {showWeekNum ? weekNum : ''}
                                            </div>

                                            {/* Days Cells */}
                                            {weekDaysRow.map(day => {
                                                const dateStr = format(day, 'yyyy-MM-dd');
                                                const isHoliday = holidays.includes(dateStr);
                                                // Check if day is within semester range
                                                const inRange = day >= startDate && day < endDate; // End date is usually exclusive or inclusive depending on exact math. addWeeks is exact. Let's say < endDate.
                                                // Actually loop logic: start_date is Monday of Week 1.
                                                // end_date = start_date + N weeks.
                                                // The semester usually implies N full weeks.
                                                // So [startDate, startDate + weeks*7 days).
                                                // Let's stick to inRange = day >= startDate && day < endDate

                                                const isCurrentMonth = isSameMonth(day, monthStart);
                                                const clickable = inRange;

                                                let bgClass = 'bg-white';
                                                let textClass = 'text-gray-900';

                                                if (!isCurrentMonth) textClass = 'text-gray-300';
                                                else if (!clickable) {
                                                    bgClass = 'bg-gray-100';
                                                    textClass = 'text-gray-400 cursor-not-allowed';
                                                } else if (isHoliday) {
                                                    bgClass = 'bg-red-100 border-red-300';
                                                    textClass = 'text-red-700 font-bold';
                                                } else {
                                                    bgClass = 'hover:bg-indigo-50 cursor-pointer border-transparent';
                                                }

                                                return (
                                                    <div
                                                        key={day.toISOString()}
                                                        onClick={() => clickable && onToggleDate(dateStr)}
                                                        className={`p-2 border rounded ${bgClass} ${textClass} flex items-center justify-center`}
                                                    >
                                                        {format(day, 'd')}
                                                        {isHoliday && <span className="sr-only">休</span>}
                                                    </div>
                                                );
                                            })}
                                        </React.Fragment>
                                    );
                                })}
                            </div>
                        </div>
                    );
                })}
            </div>
            <div className="mt-2 text-xs text-gray-500 flex gap-4">
                <span className="flex items-center"><span className="w-3 h-3 bg-white border mr-1"></span> 工作日</span>
                <span className="flex items-center"><span className="w-3 h-3 bg-red-100 border border-red-300 mr-1"></span> 节假日/休息</span>
            </div>
        </div>
    );
};

const LeaveTypeForm = ({ initialData, onSubmit, onCancel }) => {
    const [inputType, setInputType] = useState(initialData?.input_type || 'none');
    const [config, setConfig] = useState(initialData?.input_config || {});
    // 动态选项管理（用于 duration_select）
    const [durationOptions, setDurationOptions] = useState([]);

    useEffect(() => {
        if (initialData) {
            setInputType(initialData.input_type || 'none');
            const cfg = initialData.input_config || {};
            setConfig(cfg);

            // 初始化 duration 选项
            if (cfg.options && Array.isArray(cfg.options)) {
                // 检查是新格式（对象数组）还是旧格式（字符串数组）
                if (cfg.options.length > 0 && typeof cfg.options[0] === 'object') {
                    // 新格式：[{key, label}, ...]
                    setDurationOptions(cfg.options);
                } else {
                    // 旧格式：['morning_half', 'afternoon_half', ...]
                    // 转换为 {key, label} 格式
                    const optionMap = {
                        'morning_half': '上午',
                        'afternoon_half': '下午',
                        'full_day': '全天',
                        'morning_exercise': '早操',
                        'evening_exercise': '晚操',
                        '1_period': '1节课',
                        '2_periods': '2节课',
                        'half_day': '半天'
                    };
                    const opts = cfg.options.map(key => ({
                        key: key,
                        label: optionMap[key] || key
                    }));
                    setDurationOptions(opts);
                }
            } else {
                // 默认选项
                setDurationOptions([
                    { key: 'morning_half', label: '上午' },
                    { key: 'afternoon_half', label: '下午' },
                    { key: 'full_day', label: '全天' }
                ]);
            }
        }
    }, [initialData]);

    // 添加选项
    const addDurationOption = () => {
        setDurationOptions([...durationOptions, { key: '', label: '' }]);
    };

    // 删除选项
    const removeDurationOption = (index) => {
        setDurationOptions(durationOptions.filter((_, i) => i !== index));
    };

    // 更新选项
    const updateDurationOption = (index, field, value) => {
        const newOptions = [...durationOptions];
        newOptions[index][field] = value;
        setDurationOptions(newOptions);
    };

    const handleFormSubmit = (e) => {
        e.preventDefault();
        const formData = new FormData(e.target);

        // Inject config as JSON string for backend regular handling or just let backend handle array?
        // Laravel $casts = ['input_config' => 'array'] handles array input if sent as JSON or distinct fields?
        // If we send 'input_config' as a key with JSON string, or array?
        // Typically FormData sends strings.
        // Let's manually construct the data object and call onSubmit.

        const data = Object.fromEntries(formData);
        data.is_active = formData.get('is_active') === 'on';
        data.student_requestable = formData.get('student_requestable') === 'on';

        // Construct config based on input type
        let finalConfig = {};
        if (inputType === 'time') {
            finalConfig = { format: formData.get('config_format') };
        } else if (inputType === 'period_select') {
            finalConfig = { max_periods: parseInt(formData.get('config_max_periods')) || 8 };
        } else if (inputType === 'duration_select') {
            // 使用动态选项，保存完整的 {key, label} 对象
            const options = durationOptions.filter(opt => opt.key && opt.label);
            finalConfig = { options };
        }

        // Remove temp config fields from data if needed, or just overwrite input_config
        data.input_type = inputType;
        data.input_config = finalConfig; // axios handles object to JSON automatically

        // Allow parent to handle API call
        // We modify the event target or just pass data?
        // Parent `handleLeaveTypeSubmit` uses `new FormData(e.target)`.
        // So we should probably hijack the submission and call parent with data?
        // But parent expects event.
        // Let's change parent usage to accept data if we can, or we simulate event?
        // Easier: Change parent to accept data OR event.
        // But here I can just use `onSubmit({ ...data })` if `onSubmit` supports it.
        // The `handleLeaveTypeSubmit` in SettingsPage takes `e`.
        // Let's make a wrapper there or change it.
        // I will change `LeaveTypeForm` to call a custom handler that mimics the event or just passes data, 
        // and update `SettingsPage` to handle it.
        // actually `SettingsPage`'s `handleLeaveTypeSubmit` does: `const formData = new FormData(e.target);`.
        // I will overload it to check if `e` is event or data.

        // Actually, easiest is to append a hidden input with keys? No, nested json is hard.
        // I will append a hidden input 'input_config' with JSON string value.
        // Then existing logic work?
        // Yes, if I stringify it.
        // But I need to update `handleLeaveTypeSubmit` to parse it?
        // Laravel expects JSON? If I send 'input_config' as stringified JSON, Laravel might see it as string unless I use `json_decode` or request validates it.
        // `casts` in model works on retrieving, but for saving:
        // `fill` with array works.
        // `fill` with json string? Might need manual decoding in controller.

        // Let's assume I will update `handleLeaveTypeSubmit` to handle data object.
        onSubmit(data);
    };

    return (
        <form onSubmit={handleFormSubmit} className="bg-gray-50 p-4 rounded mb-4 border grid grid-cols-2 gap-4">
            <div>
                <label className="label">类型名称 (中文)</label>
                <input required name="name" defaultValue={initialData?.name} placeholder="病假" className="input-field" />
            </div>
            <div>
                <label className="label">标识符 (英文Slug)</label>
                <input required name="slug" defaultValue={initialData?.slug} placeholder="sick" className="input-field" />
            </div>
            <div className="col-span-2">
                <label className="label">描述</label>
                <input name="description" defaultValue={initialData?.description} placeholder="描述" className="input-field" />
            </div>

            <div className="col-span-2 border-t pt-4 mt-2">
                <label className="label mb-2">输入配置</label>
                <div className="grid grid-cols-2 gap-4">
                    <div>
                        <label className="text-xs text-gray-500">输入类型</label>
                        <select
                            value={inputType}
                            onChange={e => setInputType(e.target.value)}
                            className="input-field"
                        >
                            <option value="none">无 (直接标记)</option>
                            <option value="time">时间点 (Time)</option>
                            <option value="period_select">节次选择 (Periods)</option>
                            <option value="duration_select">时长选择 (Duration)</option>
                        </select>
                    </div>

                    <div className="bg-white p-3 border rounded">
                        {inputType === 'none' && <span className="text-gray-400 text-sm">无需额外输入</span>}
                        {inputType === 'time' && (
                            <div>
                                <label className="text-xs text-gray-500">时间格式</label>
                                <input name="config_format" defaultValue={config.format || 'HH:mm'} className="input-field" placeholder="HH:mm" />
                            </div>
                        )}
                        {inputType === 'period_select' && (
                            <div>
                                <label className="text-xs text-gray-500">最大节数</label>
                                <input name="config_max_periods" type="number" defaultValue={config.max_periods || 8} className="input-field" />
                            </div>
                        )}
                        {inputType === 'duration_select' && (
                            <div>
                                <div className="flex justify-between items-center mb-2">
                                    <label className="text-xs text-gray-500">选项配置</label>
                                    <button
                                        type="button"
                                        onClick={addDurationOption}
                                        className="text-xs bg-indigo-500 text-white px-2 py-1 rounded hover:bg-indigo-600"
                                    >
                                        + 添加选项
                                    </button>
                                </div>
                                <div className="space-y-2">
                                    {durationOptions.map((opt, index) => (
                                        <div key={index} className="flex gap-2 items-center">
                                            <input
                                                type="text"
                                                placeholder="键值 (如: morning_half)"
                                                value={opt.key}
                                                onChange={(e) => updateDurationOption(index, 'key', e.target.value)}
                                                className="flex-1 text-xs border rounded px-2 py-1"
                                            />
                                            <input
                                                type="text"
                                                placeholder="显示文本 (如: 上午)"
                                                value={opt.label}
                                                onChange={(e) => updateDurationOption(index, 'label', e.target.value)}
                                                className="flex-1 text-xs border rounded px-2 py-1"
                                            />
                                            <button
                                                type="button"
                                                onClick={() => removeDurationOption(index)}
                                                className="text-red-500 hover:text-red-700 text-sm"
                                            >
                                                ✕
                                            </button>
                                        </div>
                                    ))}
                                    {durationOptions.length === 0 && (
                                        <p className="text-xs text-gray-400">暂无选项，点击"添加选项"开始配置</p>
                                    )}
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            </div>

            <label className="flex items-center col-span-2 mt-2"><input name="is_active" type="checkbox" defaultChecked={initialData?.is_active ?? true} className="mr-2" /> 启用</label>
            <label className="flex items-center col-span-2"><input name="student_requestable" type="checkbox" defaultChecked={initialData?.student_requestable ?? false} className="mr-2" /> 学生可申请</label>

            <div className="col-span-2 flex justify-end space-x-2">
                <button type="button" onClick={onCancel} className="btn-secondary">取消</button>
                <button type="submit" className="btn-primary">保存</button>
            </div>
        </form>
    );
};

export default function SettingsPage() {
    const [activeTab, setActiveTab] = useState('semesters');
    const [semesters, setSemesters] = useState([]);
    const [leaveTypes, setLeaveTypes] = useState([]);
    const [departments, setDepartments] = useState([]);
    const [classes, setClasses] = useState([]);
    const [teachers, setTeachers] = useState([]); // For dropdown
    const [grades, setGrades] = useState([{ id: 1, name: 'Grade 10' }, { id: 2, name: 'Grade 11' }, { id: 3, name: 'Grade 12' }]); // Mock or Fetch? Better mock for now or fetch if API exists.

    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');

    // Form States
    const [editingSemester, setEditingSemester] = useState(null);
    const [editingLeaveType, setEditingLeaveType] = useState(null);
    const [editingDepartment, setEditingDepartment] = useState(null);
    const [editingClass, setEditingClass] = useState(null);

    const [showSemesterForm, setShowSemesterForm] = useState(false);
    const [showLeaveTypeForm, setShowLeaveTypeForm] = useState(false);
    const [showDepartmentForm, setShowDepartmentForm] = useState(false);
    const [showClassForm, setShowClassForm] = useState(false);

    // Settings State
    const [settings, setSettings] = useState({});

    // Semester Form State (Controlled)
    const [semesterForm, setSemesterForm] = useState({
        name: '', start_date: '', total_weeks: 20, is_current: false, holidays: []
    });

    // Initial Data Fetch
    useEffect(() => {
        fetchData();
    }, []);

    const fetchData = async () => {
        setLoading(true);
        setError('');

        try {
            // Fetch Core Data
            const [semRes, leaveRes, deptRes, classRes, teacherRes] = await Promise.all([
                axios.get('/semesters'),
                axios.get('/leave-types'),
                axios.get('/departments'),
                axios.get('/admin/classes'),
                axios.get('/admin/teachers'),
            ]);
            setSemesters(semRes.data);
            setLeaveTypes(leaveRes.data);
            setDepartments(deptRes.data);
            setClasses(classRes.data);
            setTeachers(teacherRes.data);
        } catch (err) {
            console.error(err);
            setError('Failed to load core data: ' + (err.response?.data?.message || err.message));
            setLoading(false);
            return;
        }

        // Fetch Settings Separately (Non-blocking)
        try {
            const setRes = await axios.get('/settings');
            const settingsObj = {};
            setRes.data.forEach(s => settingsObj[s.key] = s.value);
            setSettings(settingsObj);
        } catch (err) {
            console.warn("Failed to load settings", err);
            // Non-critical, just log
        } finally {
            setLoading(false);
        }
    };

    const handleSettingsSubmit = async (e) => {
        e.preventDefault();
        try {
            const settingsArray = Object.keys(settings).map(key => ({
                key: key,
                value: settings[key]
            }));
            await axios.post('/settings', { settings: settingsArray });
            alert('考勤规则已保存');
        } catch (err) {
            alert('Error: ' + err.message);
        }
    };

    // --- Semi-Generic Handlers ---
    const handleDelete = async (endpoint, id, refreshFn) => {
        if (!confirm('Are you sure you want to delete this item?')) return;
        try {
            await axios.delete(`/${endpoint}/${id}`);
            fetchData();
        } catch (err) {
            alert('Failed to delete: ' + (err.response?.data?.message || err.message));
        }
    };

    // Handlers for Semester Form
    const openSemesterCreate = () => {
        setEditingSemester(null);
        setSemesterForm({ name: '', start_date: '', total_weeks: 20, is_current: false, holidays: [] });
        setShowSemesterForm(true);
    };

    const openSemesterEdit = (s) => {
        setEditingSemester(s);
        // Convert start_date from ISO format to yyyy-MM-dd for the date input
        let formattedStartDate = s.start_date || '';
        if (formattedStartDate && formattedStartDate.includes('T')) {
            formattedStartDate = formattedStartDate.split('T')[0];
        }
        setSemesterForm({
            name: s.name,
            start_date: formattedStartDate,
            total_weeks: s.total_weeks,
            is_current: s.is_current,
            holidays: s.holidays || []
        });
        setShowSemesterForm(true);
    };

    const handleHolidayToggle = (dateStr) => {
        setSemesterForm(prev => {
            const exists = prev.holidays.includes(dateStr);
            if (exists) return { ...prev, holidays: prev.holidays.filter(d => d !== dateStr) };
            return { ...prev, holidays: [...prev.holidays, dateStr] };
        });
    };

    // Modified Submission
    const handleSemesterSubmit = async (e) => {
        e.preventDefault();
        const data = { ...semesterForm };
        // data.holidays is already in state

        try {
            if (editingSemester) await axios.put(`/semesters/${editingSemester.id}`, data);
            else await axios.post('/semesters', data);
            setShowSemesterForm(false); setEditingSemester(null); fetchData();
        } catch (err) { alert('Error: ' + err.message); }
    };

    const handleLeaveTypeSubmit = async (e) => {
        e.preventDefault();
        const formData = new FormData(e.target);
        const data = Object.fromEntries(formData);
        data.is_active = data.is_active === 'on';

        try {
            if (editingLeaveType) await axios.put(`/leave-types/${editingLeaveType.id}`, data);
            else await axios.post('/leave-types', data);
            setShowLeaveTypeForm(false); setEditingLeaveType(null); fetchData();
        } catch (err) { alert('Error: ' + err.message); }
    };

    const handleDepartmentSubmit = async (e) => {
        e.preventDefault();
        const formData = new FormData(e.target);
        const data = Object.fromEntries(formData);
        data.school_id = 1; // Default

        try {
            if (editingDepartment) await axios.put(`/departments/${editingDepartment.id}`, data);
            else await axios.post('/departments', data);
            setShowDepartmentForm(false); setEditingDepartment(null); fetchData();
        } catch (err) { alert('Error: ' + err.message); }
    };

    const handleClassSubmit = async (e) => {
        e.preventDefault();
        const formData = new FormData(e.target);
        const data = Object.fromEntries(formData);
        data.school_id = 1; // Default
        // Grade ID hardcoded or select?
        // data.grade_id = 1; // Need a select for grade

        try {
            if (editingClass) await axios.put(`/admin/classes/${editingClass.id}`, data);
            else await axios.post('/admin/classes', data);
            setShowClassForm(false); setEditingClass(null); fetchData();
        } catch (err) { alert('Error: ' + (err.response?.data?.error || err.message)); }
    };


    const toggleLeaveTypeActive = async (type) => {
        try {
            await axios.put(`/leave-types/${type.id}`, { ...type, is_active: !type.is_active });
            fetchData();
        } catch (err) { console.error(err); }
    };

    const NavButton = ({ id, label }) => (
        <button
            onClick={() => setActiveTab(id)}
            className={`w-full text-left px-3 py-2 rounded-md text-sm font-medium ${activeTab === id ? 'bg-indigo-100 text-indigo-700' : 'text-gray-700 hover:bg-gray-50'}`}
        >
            {label}
        </button>
    );

    return (
        <Layout>
            <div className="space-y-6">
                <div className="bg-white px-4 py-5 shadow sm:rounded-lg sm:p-6">
                    <div className="md:grid md:grid-cols-4 md:gap-6">
                        <div className="md:col-span-1">
                            <h3 className="text-lg font-medium leading-6 text-gray-900">系统设置</h3>
                            <p className="mt-1 text-sm text-gray-500">学校基础数据配置中心。</p>
                            <nav className="mt-4 space-y-1">
                                <NavButton id="semesters" label="学期管理" />
                                <NavButton id="departments" label="系部管理" />
                                <NavButton id="classes" label="班级管理" />
                                <NavButton id="leaveTypes" label="请假类型" />
                                <NavButton id="attendance" label="考勤规则" />
                            </nav>
                        </div>

                        <div className="mt-5 md:col-span-3 md:mt-0">
                            {error && <div className="bg-red-50 text-red-600 p-3 rounded mb-4">{error}</div>}

                            {/* SEMESTERS */}
                            {activeTab === 'semesters' && (
                                <div>
                                    <div className="flex justify-between items-center mb-4">
                                        <h4 className="text-md font-bold text-gray-700">学期列表</h4>
                                        <button onClick={openSemesterCreate} className="btn-primary"><PlusIcon className="h-4 w-4 mr-1" /> 新增</button>
                                    </div>
                                    {(showSemesterForm || editingSemester) && (
                                        <form onSubmit={handleSemesterSubmit} className="bg-gray-50 p-4 rounded mb-4 border grid grid-cols-2 gap-4">
                                            <div>
                                                <label className="label">学期名称</label>
                                                <input required name="name" value={semesterForm.name} onChange={e => setSemesterForm({ ...semesterForm, name: e.target.value })} placeholder="2025-2026 第一学期" className="input-field" />
                                            </div>
                                            <div>
                                                <label className="label">开学日期 (第一周周一)</label>
                                                <input required name="start_date" type="date" value={semesterForm.start_date} onChange={e => setSemesterForm({ ...semesterForm, start_date: e.target.value })} className="input-field" />
                                            </div>
                                            <div>
                                                <label className="label">总周数</label>
                                                <input required name="total_weeks" type="number" value={semesterForm.total_weeks} onChange={e => setSemesterForm({ ...semesterForm, total_weeks: parseInt(e.target.value) || 0 })} className="input-field" placeholder="总周数" />
                                            </div>
                                            <label className="flex items-center"><input name="is_current" type="checkbox" checked={semesterForm.is_current} onChange={e => setSemesterForm({ ...semesterForm, is_current: e.target.checked })} className="mr-2" /> 设为当前学期</label>

                                            <div className="col-span-2">
                                                <div className="flex justify-between items-end">
                                                    <label className="label">日历设定</label>
                                                    <div className="flex space-x-4">
                                                        <label className="text-xs flex items-center text-gray-600 cursor-pointer">
                                                            <input
                                                                type="checkbox"
                                                                checked={semesterForm.weekStartsOn === 0}
                                                                onChange={() => setSemesterForm(prev => ({ ...prev, weekStartsOn: prev.weekStartsOn === 0 ? 1 : 0 }))}
                                                                className="mr-1 h-3 w-3"
                                                            />
                                                            周日作为第一天
                                                        </label>
                                                        <button
                                                            type="button"
                                                            onClick={() => {
                                                                const { start_date, total_weeks } = semesterForm;
                                                                if (!start_date || !total_weeks) return;
                                                                const start = parseISO(start_date);
                                                                const end = addWeeks(start, parseInt(total_weeks));
                                                                const weekends = [];
                                                                let curr = start;
                                                                while (curr < end) {
                                                                    const d = getDay(curr);
                                                                    if (d === 0 || d === 6) weekends.push(format(curr, 'yyyy-MM-dd'));
                                                                    curr = addDays(curr, 1);
                                                                }
                                                                const allMarked = weekends.every(d => semesterForm.holidays.includes(d));

                                                                setSemesterForm(prev => ({
                                                                    ...prev,
                                                                    holidays: allMarked
                                                                        ? prev.holidays.filter(d => !weekends.includes(d)) // Unmark all
                                                                        : Array.from(new Set([...prev.holidays, ...weekends])) // Mark all
                                                                }));
                                                            }}
                                                            className="text-xs text-indigo-600 hover:text-indigo-800 underline"
                                                        >
                                                            + 一键标记周末为节假日
                                                        </button>
                                                    </div>
                                                </div>
                                                <CalendarSelector
                                                    startDateStr={semesterForm.start_date}
                                                    totalWeeks={semesterForm.total_weeks}
                                                    holidays={semesterForm.holidays}
                                                    onToggleDate={handleHolidayToggle}
                                                    weekStartsOn={semesterForm.weekStartsOn ?? 1} // Default Monday
                                                />
                                            </div>

                                            <div className="col-span-2 flex justify-end space-x-2">
                                                <button type="button" onClick={() => { setShowSemesterForm(false); setEditingSemester(null) }} className="btn-secondary">取消</button>
                                                <button type="submit" className="btn-primary">保存</button>
                                            </div>
                                        </form>
                                    )}
                                    <div className="overflow-hidden shadow ring-1 ring-black ring-opacity-5 md:rounded-lg">
                                        <table className="min-w-full divide-y divide-gray-200">
                                            <thead>
                                                <tr>
                                                    <th className="text-left pl-4 sm:pl-6">名称</th>
                                                    <th className="text-center">开始日期</th>
                                                    <th className="text-center">周数</th>
                                                    <th className="text-center">状态</th>
                                                    <th className="text-center pr-4 sm:pr-6">操作</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-gray-200 bg-white">
                                                {semesters.map(s => (
                                                    <tr key={s.id}>
                                                        <td className="text-left pl-4 sm:pl-6 font-medium text-gray-900">{s.name}</td>
                                                        <td className="text-center">{s.start_date ? format(parseISO(s.start_date), 'yyyy-MM-dd') : '-'}</td>
                                                        <td className="text-center">{s.total_weeks}</td>
                                                        <td className="text-center">{s.is_current && <span className="badge-green">当前</span>}</td>
                                                        <td className="text-center pr-4 sm:pr-6 space-x-2">
                                                            <button onClick={() => openSemesterEdit(s)} className="text-indigo-600"><PencilIcon className="h-4 w-4" /></button>
                                                            <button onClick={() => handleDelete('semesters', s.id)} className="text-red-600"><TrashIcon className="h-4 w-4" /></button>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            )}

                            {/* DEPARTMENTS */}
                            {activeTab === 'departments' && (
                                <div>
                                    <div className="flex justify-between items-center mb-4">
                                        <h4 className="text-md font-bold text-gray-700">系部列表</h4>
                                        <button onClick={() => { setEditingDepartment(null); setShowDepartmentForm(true); }} className="btn-primary"><PlusIcon className="h-4 w-4 mr-1" /> 新增</button>
                                    </div>
                                    {(showDepartmentForm || editingDepartment) && (
                                        <form onSubmit={handleDepartmentSubmit} className="bg-gray-50 p-4 rounded mb-4 border flex gap-4 items-end">
                                            <div className="flex-1">
                                                <label className="label">系部名称</label>
                                                <input required name="name" defaultValue={editingDepartment?.name} className="input-field mt-1" placeholder="例如：信息技术系" />
                                            </div>
                                            <button type="button" onClick={() => { setShowDepartmentForm(false); setEditingDepartment(null) }} className="btn-secondary">取消</button>
                                            <button type="submit" className="btn-primary">保存</button>
                                        </form>
                                    )}
                                    <div className="overflow-hidden shadow ring-1 ring-black ring-opacity-5 md:rounded-lg">
                                        <ul className="divide-y divide-gray-200 bg-white">
                                            {departments.map(d => (
                                                <li key={d.id} className="px-4 py-3 flex justify-between items-center hover:bg-gray-50">
                                                    <span className="text-sm font-medium text-gray-900">{d.name}</span>
                                                    <div className="space-x-2">
                                                        <button onClick={() => { setEditingDepartment(d); setShowDepartmentForm(true) }} className="text-indigo-600"><PencilIcon className="h-4 w-4" /></button>
                                                        <button onClick={() => handleDelete('departments', d.id)} className="text-red-600"><TrashIcon className="h-4 w-4" /></button>
                                                    </div>
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                </div>
                            )}

                            {/* CLASSES */}
                            {activeTab === 'classes' && (
                                <div>
                                    <div className="flex justify-between items-center mb-4">
                                        <h4 className="text-md font-bold text-gray-700">班级管理</h4>
                                        <button onClick={() => { setEditingClass(null); setShowClassForm(true); }} className="btn-primary"><PlusIcon className="h-4 w-4 mr-1" /> 新增</button>
                                    </div>
                                    {(showClassForm || editingClass) && (
                                        <form onSubmit={handleClassSubmit} className="bg-gray-50 p-4 rounded mb-4 border grid grid-cols-2 gap-4">
                                            <div>
                                                <label className="label">班级名称</label>
                                                <input required name="name" defaultValue={editingClass?.name} placeholder="Class 10-A" className="input-field" />
                                            </div>
                                            <div>
                                                <label className="label">入学年份</label>
                                                <input
                                                    type="number"
                                                    name="enrollment_year"
                                                    defaultValue={editingClass?.enrollment_year ?? ''}
                                                    placeholder="例如: 2024"
                                                    min="2000"
                                                    max={new Date().getFullYear() + 1}
                                                    className="input-field"
                                                />
                                            </div>
                                            <div>
                                                <label className="label">所属系部</label>
                                                <select name="department_id" defaultValue={editingClass?.department_id ?? ''} key={`dept-${editingClass?.id}`} className="input-field">
                                                    <option value="">-- 无 --</option>
                                                    {departments.map(d => <option key={d.id} value={d.id}>{d.name}</option>)}
                                                </select>
                                            </div>
                                            <div>
                                                <label className="label">班主任 (Teacher)</label>
                                                <select name="teacher_id" defaultValue={editingClass?.teacher_id ?? ''} key={`teacher-${editingClass?.id}`} className="input-field">
                                                    <option value="">-- 选择班主任 --</option>
                                                    {teachers.map(t => <option key={t.id} value={t.id}>{t.name} ({t.email})</option>)}
                                                </select>
                                            </div>
                                            <div className="col-span-2 flex justify-end space-x-2">
                                                <button type="button" onClick={() => { setShowClassForm(false); setEditingClass(null) }} className="btn-secondary">取消</button>
                                                <button type="submit" className="btn-primary">保存</button>
                                            </div>
                                        </form>
                                    )}
                                    <div className="overflow-hidden shadow ring-1 ring-black ring-opacity-5 md:rounded-lg">
                                        <table className="min-w-full divide-y divide-gray-200">
                                            <thead>
                                                <tr>
                                                    <th className="text-left pl-4 sm:pl-6">班级名</th>
                                                    <th className="text-left">入学年份</th>
                                                    <th className="text-left">系部</th>
                                                    <th className="text-left">班主任</th>
                                                    <th className="text-center pr-4 sm:pr-6">操作</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-gray-200 bg-white">
                                                {classes.map(c => (
                                                    <tr key={c.id}>
                                                        <td className="text-left pl-4 sm:pl-6 font-medium text-gray-900">{c.name}</td>
                                                        <td className="text-left">{c.enrollment_year || '-'}</td>
                                                        <td className="text-left">{departments.find(d => d.id === c.department_id)?.name || '-'}</td>
                                                        <td className="text-left">{teachers.find(t => t.id === c.teacher_id) ? <span className="text-blue-600">{teachers.find(t => t.id === c.teacher_id).name}</span> : <span className="text-gray-400">未指定</span>}</td>
                                                        <td className="text-center pr-4 sm:pr-6 space-x-2">
                                                            <button onClick={() => { setEditingClass(c); setShowClassForm(true) }} className="text-indigo-600"><PencilIcon className="h-4 w-4" /></button>
                                                            <button onClick={() => handleDelete('admin/classes', c.id)} className="text-red-600"><TrashIcon className="h-4 w-4" /></button>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            )}

                            {/* LEAVE TYPES */}
                            {activeTab === 'leaveTypes' && (
                                <div>
                                    <div className="flex justify-between items-center mb-4">
                                        <h4 className="text-md font-bold text-gray-700">请假类型</h4>
                                        <button onClick={() => { setEditingLeaveType(null); setShowLeaveTypeForm(true); }} className="btn-primary"><PlusIcon className="h-4 w-4 mr-1" /> 新增</button>
                                    </div>
                                    {(showLeaveTypeForm || editingLeaveType) && (
                                        <LeaveTypeForm
                                            key={editingLeaveType ? editingLeaveType.id : 'new'}
                                            initialData={editingLeaveType}
                                            onSubmit={e => {
                                                // Wrapper to match existing handler signature if needed, or update handler?
                                                // handleLeaveTypeSubmit expects an event `e` with `e.target` as form.
                                                // But LeaveTypeForm passes `data` object.
                                                // Let's create a synthetic event or just call axios here?
                                                // Re-using handleLeaveTypeSubmit is hard if signature mismatches.
                                                // Let's just inline the submit logic here for simplicity or adapt.
                                                // Actually I'll adapt handleLeaveTypeSubmit to accept data below or here.
                                                // But wait, `LeaveTypeForm` calls `onSubmit(data)`.
                                                // I'll define a quick handler here.
                                                const submitData = async (data) => {
                                                    try {
                                                        if (editingLeaveType) await axios.put(`/leave-types/${editingLeaveType.id}`, data);
                                                        else await axios.post('/leave-types', data);
                                                        setShowLeaveTypeForm(false); setEditingLeaveType(null); fetchData();
                                                    } catch (err) { alert('Error: ' + err.message); }
                                                };
                                                submitData(e); // 'e' is actually 'data' here
                                            }}
                                            onCancel={() => { setShowLeaveTypeForm(false); setEditingLeaveType(null); }}
                                        />
                                    )}
                                    <div className="overflow-hidden shadow ring-1 ring-black ring-opacity-5 md:rounded-lg">
                                        <table className="min-w-full divide-y divide-gray-200">
                                            <thead>
                                                <tr>
                                                    <th className="text-left pl-4 sm:pl-6">名称</th>
                                                    <th className="text-left">标识</th>
                                                    <th className="text-left">描述</th>
                                                    <th className="text-left">输入类型</th>
                                                    <th className="text-center">状态</th>
                                                    <th className="text-center">学生可申请</th>
                                                    <th className="text-center pr-4 sm:pr-6">操作</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-gray-200 bg-white">
                                                {leaveTypes.map(lt => (
                                                    <tr key={lt.id}>
                                                        <td className="text-left pl-4 sm:pl-6 font-medium text-gray-900">{lt.name}</td>
                                                        <td className="text-left">{lt.slug}</td>
                                                        <td className="text-left text-gray-500">{lt.description}</td>
                                                        <td className="text-left text-xs text-gray-500">
                                                            {lt.input_type === 'none' ? '直接标记' : lt.input_type}
                                                        </td>
                                                        <td className="text-center">{lt.is_active ? <span className="badge-green">启用</span> : <span className="badge-gray">停用</span>}</td>
                                                        <td className="text-center">{lt.student_requestable ? <span className="badge-green">是</span> : <span className="badge-gray">否</span>}</td>
                                                        <td className="text-center pr-4 sm:pr-6 space-x-2">
                                                            <button onClick={() => { setEditingLeaveType(lt); setShowLeaveTypeForm(true) }} className="text-indigo-600" title="编辑"><PencilIcon className="h-4 w-4" /></button>
                                                            <button onClick={() => toggleLeaveTypeActive(lt)} className="text-gray-500 text-xs underline" title="切换状态">切换状态</button>
                                                            <button
                                                                onClick={async () => {
                                                                    if (window.confirm(`确定要删除请假类型"${lt.name}"吗？`)) {
                                                                        try {
                                                                            await axios.delete(`/leave-types/${lt.id}`);
                                                                            fetchData();
                                                                        } catch (err) {
                                                                            alert('删除失败: ' + (err.response?.data?.message || err.message));
                                                                        }
                                                                    }
                                                                }}
                                                                className="text-red-600 hover:text-red-800"
                                                                title="删除"
                                                            >
                                                                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                                </svg>
                                                            </button>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            )}

                            {/* ATTENDANCE SETTINGS */}
                            {activeTab === 'attendance' && (
                                <div>
                                    <h4 className="text-md font-bold text-gray-700 mb-4">考勤规则设置</h4>
                                    <form onSubmit={handleSettingsSubmit} className="bg-white p-6 rounded-lg border shadow-sm space-y-6">
                                        <div className="grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                                            <div className="sm:col-span-3">
                                                <label className="label">自动出勤标记时间</label>
                                                <p className="text-xs text-gray-500 mb-2">在此时间点，如果通过定时任务检测，系统将自动把未标记且未请假的学生设为“出勤”。</p>
                                                <input
                                                    type="time"
                                                    value={settings.attendance_auto_mark_time || '08:30'}
                                                    onChange={e => setSettings({ ...settings, attendance_auto_mark_time: e.target.value })}
                                                    className="input-field max-w-xs"
                                                />
                                            </div>

                                            <div className="sm:col-span-3">
                                                <label className="label">每日总课时数 (节)</label>
                                                <p className="text-xs text-gray-500 mb-2">用于统计每日出勤率的基础总数。</p>
                                                <input
                                                    type="number"
                                                    min="1"
                                                    value={settings.daily_lessons_count || 8}
                                                    onChange={e => setSettings({ ...settings, daily_lessons_count: parseInt(e.target.value) || 0 })}
                                                    className="input-field max-w-xs"
                                                />
                                            </div>

                                            <div className="sm:col-span-3">
                                                <label className="label">旷课折算阈值 (节 &gt; 天)</label>
                                                <p className="text-xs text-gray-500 mb-2">累计旷课多少节，在统计时视为旷课 1 天。</p>
                                                <input
                                                    type="number"
                                                    min="1"
                                                    value={settings.absent_lessons_as_day || 3}
                                                    onChange={e => setSettings({ ...settings, absent_lessons_as_day: parseInt(e.target.value) || 0 })}
                                                    className="input-field max-w-xs"
                                                />
                                            </div>
                                        </div>
                                        <div className="flex justify-end">
                                            <button type="submit" className="btn-primary">保存设置</button>
                                        </div>
                                    </form>
                                </div>
                            )}
                        </div>
                    </div>
                </div >
            </div >

            <style>{`
                .btn-primary { @apply inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none; }
                .btn-secondary { @apply px-3 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none; }
                .input-field { @apply mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm; }
                .label { @apply block text-sm font-medium text-gray-700; }
                th { @apply px-3 py-3.5 text-sm font-semibold text-gray-900 border-b; }
                td { @apply whitespace-nowrap px-3 py-4 text-sm text-gray-500 border-b; }
                .badge-green { @apply inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800; }
                .badge-gray { @apply inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-800; }
            `}</style>
        </Layout >
    );
}
