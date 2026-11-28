import React, { useState, useEffect, useMemo } from 'react';
import Layout from '../../components/Layout';
import axios from 'axios';
import { format } from 'date-fns';
import { zhCN } from 'date-fns/locale';
import { PlusIcon, TrashIcon, PencilIcon, UserGroupIcon, ClockIcon, CheckCircleIcon, PlayIcon, AcademicCapIcon, XMarkIcon, InformationCircleIcon } from '@heroicons/react/24/outline';
import { Link, useNavigate } from 'react-router-dom';
import useAuthStore from '../../store/authStore';

// 方案 B 工具函数：区分固定活动节次（早读/早操/晚操）与正课节次（第1~N节）
function isFixedActivityPeriod(period) {
    if (!period) return false;
    if ((period.type || '').trim().toLowerCase() === 'special') return true;
    const name = (period.name || '').trim();
    if (/^第\d+节$/.test(name)) return false;
    return ['早读', '早操', '晚操', '晨读', '升旗', '集会', '跑操', '眼保健操'].some((kw) => name.includes(kw));
}

function normalizeActivityName(raw) {
    return (raw || '').trim().replace(/点名$/, '').trim();
}

function getOccupiedFixedPeriodIdsForClass(types, classId, excludingTypeId, periods) {
    const cid = parseInt(classId, 10);
    if (!cid || Number.isNaN(cid)) return new Set();
    const classTypes = types.filter(
        (t) => (t.is_active ?? true) && parseInt(t.class_id, 10) === cid && (!excludingTypeId || t.id !== excludingTypeId)
    );
    const result = new Set();
    for (const p of periods) {
        if (!isFixedActivityPeriod(p)) continue;
        const pid = parseInt(p.id, 10);
        const pNorm = normalizeActivityName(p.name);
        const occupied = classTypes.some((t) => {
            const pids = (t.period_ids || []).map((id) => parseInt(id, 10));
            if (pids.includes(pid)) return true;
            if (pids.length === 0 && pNorm) {
                const tNorm = normalizeActivityName(t.name);
                if (tNorm === pNorm || tNorm.includes(pNorm)) return true;
            }
            return false;
        });
        if (occupied) result.add(pid);
    }
    return result;
}

function findMatchingExistingType(selectedPeriodIds, types, classId, excludingTypeId) {
    const cid = parseInt(classId, 10);
    if (!selectedPeriodIds || selectedPeriodIds.length === 0 || !cid || Number.isNaN(cid)) return null;
    const selectedSet = new Set(selectedPeriodIds.map((id) => parseInt(id, 10)));
    return types.find((t) => {
        if (!(t.is_active ?? true)) return false;
        if (parseInt(t.class_id, 10) !== cid) return false;
        if (excludingTypeId && t.id === excludingTypeId) return false;
        const pids = (t.period_ids || []).map((id) => parseInt(id, 10));
        if (pids.length !== selectedSet.size) return false;
        return pids.every((id) => selectedSet.has(id));
    }) || null;
}

function suggestCourseRollCallName(selectedPeriodIds, periods) {
    const selectedSet = new Set((selectedPeriodIds || []).map((id) => parseInt(id, 10)));
    const ordered = periods.filter((p) => selectedSet.has(parseInt(p.id, 10)));
    if (ordered.length === 0) return '';
    if (ordered.length === 1) {
        const n = (ordered[0].name || '').trim();
        return n.endsWith('点名') ? n : `${n}点名`;
    }
    const nums = ordered.map((p) => {
        const m = (p.name || '').trim().match(/^第(\d+)节$/);
        return m ? parseInt(m[1], 10) : null;
    });
    if (nums.every((n) => n !== null)) {
        const sorted = [...nums].sort((a, b) => a - b);
        const contiguous = sorted.every((val, idx) => idx === 0 || val === sorted[idx - 1] + 1);
        return contiguous ? `第${sorted[0]}-${sorted[sorted.length - 1]}节点名` : `第${sorted.join('、')}节点名`;
    }
    return `${ordered.map((p) => p.name).join('、')}点名`;
}

export default function RollCallPage() {
    const navigate = useNavigate();
    const { user } = useAuthStore();
    // Check if user is teacher or admin (not a student roll call admin)
    const isTeacherOrAdmin = user?.role === 'teacher' || ['admin', 'system_admin', 'school_admin', 'department_manager'].includes(user?.role);
    const isDepartmentManager = user?.role === 'department_manager';
    const [rollCallTypes, setRollCallTypes] = useState([]);
    const [inProgressRollCalls, setInProgressRollCalls] = useState([]);
    const [loading, setLoading] = useState(true);
    const [showTypeForm, setShowTypeForm] = useState(false);
    const [editingType, setEditingType] = useState(null);
    const [selectedClassId, setSelectedClassId] = useState(null);
    const [classes, setClasses] = useState([]);
    const [leaveTypes, setLeaveTypes] = useState([]);
    const [periods, setPeriods] = useState([]);  // 节次列表
    const [selectedPeriodIds, setSelectedPeriodIds] = useState([]);  // 选中的节次ID（多选）
    const [formClassId, setFormClassId] = useState(null);
    const [formName, setFormName] = useState('');
    const [lastAutoFormName, setLastAutoFormName] = useState('');
    // 本次课程点名（单次事件，不加入常用活动类型）
    const [showCourseModal, setShowCourseModal] = useState(false);
    const [courseClassId, setCourseClassId] = useState(null);
    const [coursePeriodIds, setCoursePeriodIds] = useState([]);
    const [courseName, setCourseName] = useState('');
    const [lastAutoCourseName, setLastAutoCourseName] = useState('');
    const [courseAbsentStatus, setCourseAbsentStatus] = useState('absent');
    const [courseLeaveTypeId, setCourseLeaveTypeId] = useState('');
    const [courseNotes, setCourseNotes] = useState('');
    const [courseLoading, setCourseLoading] = useState(false);
    // For department manager batch selection
    const [showBatchModal, setShowBatchModal] = useState(false);
    const [batchTypeId, setBatchTypeId] = useState(null);
    const [selectedClassIds, setSelectedClassIds] = useState([]);
    const [batchLoading, setBatchLoading] = useState(false);

    useEffect(() => {
        fetchData();
    }, []);

    const fetchData = async () => {
        setLoading(true);
        try {
            const [typesRes, inProgressRes, classesRes, leaveTypesRes, settingsRes] = await Promise.all([
                axios.get('/roll-call-types'),
                axios.get('/roll-calls/in-progress'),
                axios.get('/options/classes'),
                axios.get('/leave-types'),
                axios.get('/settings'),  // 从系统设置获取节次配置
            ]);
            setRollCallTypes(typesRes.data);
            setInProgressRollCalls(inProgressRes.data);
            setClasses(classesRes.data || []);
            setLeaveTypes(leaveTypesRes.data || []);

            // 从 settings 中解析 attendance_periods
            const settingsObj = {};
            settingsRes.data.forEach(s => settingsObj[s.key] = s.value);
            if (settingsObj.attendance_periods) {
                try {
                    const periodsData = typeof settingsObj.attendance_periods === 'string'
                        ? JSON.parse(settingsObj.attendance_periods)
                        : settingsObj.attendance_periods;
                    setPeriods(Array.isArray(periodsData) ? periodsData.filter(period => (period.scene || 'regular') === 'regular' && (period.is_active ?? true)) : []);
                } catch (e) {
                    console.warn('Failed to parse attendance_periods', e);
                    setPeriods([]);
                }
            }

            // Set default class if available (auto-select for single-class teachers, not for department managers)
            if (classesRes.data?.length > 0 && !selectedClassId && !isDepartmentManager) {
                setSelectedClassId(classesRes.data[0].id);
            }
        } catch (err) {
            console.error('Failed to fetch data:', err);
        } finally {
            setLoading(false);
        }
    };

    // State for batch type creation (department manager)
    const [batchTypeMode, setBatchTypeMode] = useState(false);
    const [batchTypeClassIds, setBatchTypeClassIds] = useState([]);

    const effectiveFormClassId = editingType?.class_id || formClassId || selectedClassId || classes[0]?.id || null;

    // 长期活动类型表单：方案 B 已占用固定活动节次
    const formOccupiedFixedPeriodIds = useMemo(() => {
        if ((batchTypeMode && isDepartmentManager) || editingType?._isGroup) {
            if (batchTypeClassIds.length === 0) return new Set();
            const excludingId = editingType?._isGroup ? null : editingType?.id;
            const sets = batchTypeClassIds.map((cid) => {
                const matchGroupItem = editingType?._groupTypes?.find((gt) => parseInt(gt.class_id, 10) === parseInt(cid, 10));
                return getOccupiedFixedPeriodIdsForClass(rollCallTypes, cid, matchGroupItem?.id || excludingId, periods);
            });
            // 仅当所选全部班级都已占用该固定活动时才禁用
            return new Set([...sets[0]].filter((pid) => sets.every((s) => s.has(pid))));
        }
        return getOccupiedFixedPeriodIdsForClass(rollCallTypes, effectiveFormClassId, editingType?.id, periods);
    }, [rollCallTypes, effectiveFormClassId, editingType, periods, batchTypeMode, isDepartmentManager, batchTypeClassIds]);

    const formMatchedExistingType = useMemo(() => {
        if ((batchTypeMode && isDepartmentManager) || editingType?._isGroup) return null;
        return findMatchingExistingType(selectedPeriodIds, rollCallTypes, effectiveFormClassId, editingType?.id);
    }, [selectedPeriodIds, rollCallTypes, effectiveFormClassId, editingType, batchTypeMode, isDepartmentManager]);

    // 本次课程点名弹窗：方案 B 已占用固定活动节次（正课第1~N节永远开放重新自由选择）
    const courseOccupiedFixedPeriodIds = useMemo(
        () => getOccupiedFixedPeriodIdsForClass(rollCallTypes, courseClassId, null, periods),
        [rollCallTypes, courseClassId, periods]
    );

    const openCourseRollCallModal = () => {
        const defaultCid = selectedClassId || classes[0]?.id || null;
        setCourseClassId(defaultCid);
        setCoursePeriodIds([]);
        setCourseName('');
        setLastAutoCourseName('');
        setCourseAbsentStatus('absent');
        setCourseLeaveTypeId('');
        setCourseNotes('');
        setShowCourseModal(true);
    };

    const handleCourseClassChange = (newClassId) => {
        const cid = parseInt(newClassId, 10);
        setCourseClassId(cid);
        const occupied = getOccupiedFixedPeriodIdsForClass(rollCallTypes, cid, null, periods);
        setCoursePeriodIds((prev) => {
            const next = prev.filter((id) => !occupied.has(parseInt(id, 10)));
            const trimmed = courseName.trim();
            if (trimmed === '' || trimmed === lastAutoCourseName) {
                const suggested = suggestCourseRollCallName(next, periods);
                setCourseName(suggested);
                setLastAutoCourseName(suggested);
            }
            return next;
        });
    };

    const handleStartOneOffCourseRollCall = async (e) => {
        e.preventDefault();
        const cleanName = courseName.trim();
        if (!courseClassId) {
            alert('请选择班级');
            return;
        }
        if (!cleanName) {
            alert('请勾选课程节次或输入活动名称');
            return;
        }
        setCourseLoading(true);
        try {
            const orderedPeriods = periods
                .filter((p) => coursePeriodIds.includes(parseInt(p.id, 10)))
                .map((p) => parseInt(p.id, 10));
            const typePayload = {
                class_id: parseInt(courseClassId, 10),
                name: cleanName,
                absent_status: courseAbsentStatus || 'absent',
                period_ids: orderedPeriods.length > 0 ? orderedPeriods : null,
                is_active: false, // 仅对本次事件生效，不污染常用活动类型列表
            };
            if (courseLeaveTypeId) {
                typePayload.leave_type_id = parseInt(courseLeaveTypeId, 10);
            }
            const typeRes = await axios.post('/roll-call-types', typePayload);
            const rcRes = await axios.post('/roll-calls', {
                roll_call_type_id: typeRes.data.id,
                roll_call_time: format(new Date(), 'yyyy-MM-dd HH:mm:ss'),
                notes: courseNotes.trim() || null,
            });
            setShowCourseModal(false);
            navigate(`/roll-call/${rcRes.data.id}`);
        } catch (err) {
            alert('发起失败: ' + (err.response?.data?.error || err.response?.data?.message || err.message));
        } finally {
            setCourseLoading(false);
        }
    };

    const handleTypeSubmit = async (e) => {
        e.preventDefault();
        const formData = new FormData(e.target);
        const data = Object.fromEntries(formData);
        data.is_active = data.is_active === 'on';
        if (data.leave_type_id) data.leave_type_id = parseInt(data.leave_type_id);
        else delete data.leave_type_id;
        // 使用选中的节次ID数组
        data.period_ids = selectedPeriodIds.length > 0 ? selectedPeriodIds : null;
        delete data.period_count;  // 移除旧字段

        try {
            if (editingType?._isGroup && batchTypeMode) {
                // Editing a group - use batch update API
                const res = await axios.put('/roll-call-types/batch', {
                    old_name: editingType.name,  // Original name to find existing types
                    name: data.name,              // New name from form (may be same or different)
                    class_ids: batchTypeClassIds,
                    description: data.description,
                    absent_status: data.absent_status,
                    leave_type_id: data.leave_type_id,
                    period_ids: data.period_ids,
                    is_active: data.is_active,
                });
                alert(res.data.message || '更新成功');
            } else if (editingType) {
                await axios.put(`/roll-call-types/${editingType.id}`, data);
            } else if (batchTypeMode && batchTypeClassIds.length > 0) {
                // Batch create for multiple classes
                const res = await axios.post('/roll-call-types/batch', {
                    ...data,
                    class_ids: batchTypeClassIds,
                });
                alert(res.data.message || '批量创建成功');
            } else {
                data.class_id = parseInt(data.class_id);
                await axios.post('/roll-call-types', data);
            }
            setShowTypeForm(false);
            setEditingType(null);
            setBatchTypeMode(false);
            setBatchTypeClassIds([]);
            setSelectedPeriodIds([]);  // 重置选中的节次
            fetchData();
        } catch (err) {
            alert('Error: ' + (err.response?.data?.error || err.response?.data?.message || err.message));
        }
    };

    const deleteType = async (id) => {
        if (!confirm('确定要删除这个活动类型吗？')) return;
        try {
            await axios.delete(`/roll-call-types/${id}`);
            fetchData();
        } catch (err) {
            alert('Error: ' + err.message);
        }
    };

    const startRollCall = async (typeId) => {
        // For department manager, show batch selection modal
        if (isDepartmentManager && classes.length > 1) {
            setBatchTypeId(typeId);
            setSelectedClassIds([selectedClassId]); // Pre-select current class
            setShowBatchModal(true);
            return;
        }

        try {
            const res = await axios.post('/roll-calls', {
                roll_call_type_id: typeId,
                // 发送不带时区的本地时间字符串，让后端直接按配置的时区(Asia/Shanghai)解析
                roll_call_time: format(new Date(), 'yyyy-MM-dd HH:mm:ss'),
            });
            navigate(`/roll-call/${res.data.id}`);
        } catch (err) {
            alert('Error: ' + (err.response?.data?.error || err.message));
        }
    };

    // Batch create roll calls for department manager
    const handleBatchCreate = async () => {
        if (selectedClassIds.length === 0) {
            alert('请至少选择一个班级');
            return;
        }

        setBatchLoading(true);
        try {
            const res = await axios.post('/roll-calls', {
                roll_call_type_id: batchTypeId,
                // 发送不带时区的本地时间字符串
                roll_call_time: format(new Date(), 'yyyy-MM-dd HH:mm:ss'),
                class_ids: selectedClassIds,
            });

            setShowBatchModal(false);
            setBatchTypeId(null);
            setSelectedClassIds([]);

            // If only one roll call created, navigate to it
            if (res.data.id) {
                navigate(`/roll-call/${res.data.id}`);
            } else {
                alert(res.data.message || '点名创建成功');
                fetchData();
            }
        } catch (err) {
            alert('Error: ' + (err.response?.data?.error || err.message));
        } finally {
            setBatchLoading(false);
        }
    };

    // Toggle class selection
    const toggleClassSelection = (classId) => {
        setSelectedClassIds(prev =>
            prev.includes(classId)
                ? prev.filter(id => id !== classId)
                : [...prev, classId]
        );
    };

    // Select all classes
    const selectAllClasses = () => {
        setSelectedClassIds(classes.map(c => c.id));
    };

    // Deselect all classes
    const deselectAllClasses = () => {
        setSelectedClassIds([]);
    };

    const filteredTypes = selectedClassId
        ? rollCallTypes.filter(t => t.class_id === selectedClassId)
        : rollCallTypes;

    // Group types by name for department manager view (when no specific class selected)
    const groupedTypes = isDepartmentManager && !selectedClassId
        ? Object.values(
            rollCallTypes.reduce((acc, type) => {
                const key = type.name;
                if (!acc[key]) {
                    acc[key] = {
                        name: type.name,
                        description: type.description,
                        absent_status: type.absent_status,
                        leave_type_id: type.leave_type_id,
                        creator: type.creator,
                        types: [],
                        classNames: [],
                        classIds: [],
                    };
                }
                acc[key].types.push(type);
                if (type.class) {
                    acc[key].classNames.push(type.class.name);
                    acc[key].classIds.push(type.class_id);
                }
                return acc;
            }, {})
        )
        : null;

    if (loading) {
        return (
            <Layout>
                <div className="flex items-center justify-center h-64">
                    <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600"></div>
                </div>
            </Layout>
        );
    }

    return (
        <Layout>
            <div className="space-y-6">
                {/* Header */}
                <div className="md:flex md:items-center md:justify-between">
                    <div className="min-w-0 flex-1">
                        <h2 className="text-2xl font-bold leading-7 text-gray-900">点名</h2>
                    </div>
                </div>

                {/* In Progress Roll Calls */}
                {inProgressRollCalls.length > 0 && (
                    <div className="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                        <h3 className="text-sm font-medium text-yellow-800 mb-3">进行中的点名</h3>
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            {inProgressRollCalls.map(rc => (
                                <Link
                                    key={rc.id}
                                    to={`/roll-call/${rc.id}`}
                                    className="bg-white rounded-lg p-4 shadow-sm hover:shadow-md transition-shadow border border-yellow-100"
                                >
                                    <div className="font-medium text-gray-900">{rc.roll_call_type?.name}</div>
                                    <div className="text-sm text-gray-500">{rc.class?.name}</div>
                                    <div className="text-xs text-gray-400 mt-1">
                                        {format(new Date(rc.roll_call_time), 'MM-dd HH:mm')}
                                    </div>
                                    <div className="mt-2 text-sm">
                                        <span className="text-green-600">已到: {rc.present_count}</span>
                                        <span className="text-gray-400 mx-1">/</span>
                                        <span className="text-gray-600">{rc.total_students}</span>
                                    </div>
                                </Link>
                            ))}
                        </div>
                    </div>
                )}

                {/* Class Filter - Only show for teachers/admins */}
                {isTeacherOrAdmin && classes.length > 1 && (
                    <div className="flex items-center gap-4">
                        <label className="text-sm font-medium text-gray-700">选择班级:</label>
                        <select
                            value={selectedClassId || ''}
                            onChange={(e) => setSelectedClassId(e.target.value ? parseInt(e.target.value) : null)}
                            className="input-field max-w-xs"
                        >
                            {isDepartmentManager && (
                                <option value="">全部班级 (分组视图)</option>
                            )}
                            {classes.map(c => (
                                <option key={c.id} value={c.id}>{c.name}</option>
                            ))}
                        </select>
                    </div>
                )}

                {/* Roll Call Types */}
                <div className="bg-white shadow rounded-lg p-6">
                    <div className="flex justify-between items-center mb-4">
                        <h3 className="text-lg font-medium text-gray-900">活动类型</h3>
                        <div className="flex items-center gap-2">
                            <Link
                                to="/roll-call/history"
                                className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-medium bg-gray-100 text-gray-700 hover:bg-gray-200 transition-colors"
                            >
                                <ClockIcon className="h-4 w-4" />
                                <span className="hidden sm:inline">历史</span>
                            </Link>
                            {/* Only show admin buttons for teachers/admins */}
                            {isTeacherOrAdmin && (
                                <>
                                    <Link
                                        to="/roll-call/admins"
                                        className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-medium bg-blue-100 text-blue-700 hover:bg-blue-200 transition-colors"
                                    >
                                        <UserGroupIcon className="h-4 w-4" />
                                        <span className="hidden sm:inline">点名员</span>
                                    </Link>
                                    <button
                                        onClick={openCourseRollCallModal}
                                        className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-medium bg-indigo-100 text-indigo-700 hover:bg-indigo-200 transition-colors"
                                    >
                                        <AcademicCapIcon className="h-4 w-4" />
                                        <span className="hidden sm:inline">课程点名(本次)</span>
                                    </button>
                                    <button
                                        onClick={() => {
                                            setEditingType(null);
                                            setFormClassId(selectedClassId || classes[0]?.id || null);
                                            setFormName('');
                                            setLastAutoFormName('');
                                            setSelectedPeriodIds([]);
                                            setShowTypeForm(true);
                                        }}
                                        className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-medium bg-green-100 text-green-700 hover:bg-green-200 transition-colors"
                                    >
                                        <PlusIcon className="h-4 w-4" />
                                        <span className="hidden sm:inline">新增类型</span>
                                    </button>
                                </>
                            )}
                        </div>
                    </div>

                    {showTypeForm && (
                        <form onSubmit={handleTypeSubmit} className="bg-gray-50 p-4 rounded-lg mb-4 border">
                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <label className="block text-sm font-medium text-gray-700 mb-1">活动名称</label>
                                    <input
                                        required
                                        name="name"
                                        value={formName}
                                        onChange={(e) => setFormName(e.target.value)}
                                        placeholder="如：第1-2节点名 / 早操点名"
                                        className="input-field"
                                    />
                                    {editingType?._isGroup && (
                                        <p className="text-xs text-gray-400 mt-1">修改名称将同时更新所有班级</p>
                                    )}
                                </div>
                                <div>
                                    <label className="block text-sm font-medium text-gray-700 mb-1">
                                        班级
                                        {isDepartmentManager && !editingType?._isGroup && !editingType && (
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    setBatchTypeMode(!batchTypeMode);
                                                    if (!batchTypeMode) {
                                                        setBatchTypeClassIds([selectedClassId]);
                                                    }
                                                }}
                                                className="ml-2 text-xs text-indigo-600 hover:text-indigo-800"
                                            >
                                                {batchTypeMode ? '单班级模式' : '批量创建模式'}
                                            </button>
                                        )}
                                        {editingType?._isGroup && (
                                            <span className="ml-2 text-xs text-green-600">编辑班级范围</span>
                                        )}
                                    </label>
                                    {(batchTypeMode && isDepartmentManager) || editingType?._isGroup ? (
                                        <div className="space-y-2">
                                            <div className="flex gap-2 text-xs">
                                                <button
                                                    type="button"
                                                    onClick={() => setBatchTypeClassIds(classes.map(c => c.id))}
                                                    className="text-indigo-600 hover:text-indigo-800"
                                                >
                                                    全选本系
                                                </button>
                                                <span className="text-gray-300">|</span>
                                                <button
                                                    type="button"
                                                    onClick={() => setBatchTypeClassIds([])}
                                                    className="text-gray-600 hover:text-gray-800"
                                                >
                                                    取消全选
                                                </button>
                                                <span className="text-gray-400 ml-auto">
                                                    已选: {batchTypeClassIds.length} / {classes.length}
                                                </span>
                                            </div>
                                            <div className="grid grid-cols-2 gap-2 max-h-32 overflow-y-auto border rounded p-2">
                                                {classes.map(c => (
                                                    <label
                                                        key={c.id}
                                                        className={`flex items-center p-2 rounded cursor-pointer text-sm ${batchTypeClassIds.includes(c.id)
                                                            ? 'bg-indigo-50 text-indigo-700'
                                                            : 'hover:bg-gray-50'
                                                            }`}
                                                    >
                                                        <input
                                                            type="checkbox"
                                                            checked={batchTypeClassIds.includes(c.id)}
                                                            onChange={() => {
                                                                setBatchTypeClassIds(prev =>
                                                                    prev.includes(c.id)
                                                                        ? prev.filter(id => id !== c.id)
                                                                        : [...prev, c.id]
                                                                );
                                                            }}
                                                            className="h-3 w-3 text-indigo-600 rounded mr-2"
                                                        />
                                                        {c.name}
                                                    </label>
                                                ))}
                                            </div>
                                        </div>
                                    ) : (
                                        <select
                                            name="class_id"
                                            value={effectiveFormClassId || ''}
                                            onChange={(e) => {
                                                const cid = parseInt(e.target.value, 10);
                                                setFormClassId(cid);
                                                const occ = getOccupiedFixedPeriodIdsForClass(rollCallTypes, cid, editingType?.id, periods);
                                                setSelectedPeriodIds((prev) => prev.filter((id) => !occ.has(parseInt(id, 10))));
                                            }}
                                            disabled={!!editingType}
                                            className="input-field"
                                        >
                                            {classes.map(c => (
                                                <option key={c.id} value={c.id}>{c.name}</option>
                                            ))}
                                        </select>
                                    )}
                                </div>
                                <div className="col-span-2">
                                    <label className="block text-sm font-medium text-gray-700 mb-1">描述</label>
                                    <input
                                        name="description"
                                        defaultValue={editingType?.description}
                                        placeholder="可选描述"
                                        className="input-field"
                                    />
                                </div>
                                <div>
                                    <label className="block text-sm font-medium text-gray-700 mb-1">未到标记为</label>
                                    <select name="absent_status" defaultValue={editingType?.absent_status || 'absent'} className="input-field">
                                        {/* 从请假类型中筛选出可用于未到标记的类型 */}
                                        {leaveTypes
                                            .filter(lt => ['absent', 'late', 'early_leave'].includes(lt.slug))
                                            .map(lt => (
                                                <option key={lt.slug} value={lt.slug}>{lt.name}</option>
                                            ))
                                        }
                                        {/* 如果没有匹配的类型，提供默认选项 */}
                                        {leaveTypes.filter(lt => ['absent', 'late', 'early_leave'].includes(lt.slug)).length === 0 && (
                                            <>
                                                <option value="absent">旷课</option>
                                                <option value="late">迟到</option>
                                            </>
                                        )}
                                    </select>
                                </div>
                                <div>
                                    <label className="block text-sm font-medium text-gray-700 mb-1">关联请假类型 (可选)</label>
                                    <select name="leave_type_id" defaultValue={editingType?.leave_type_id || ''} className="input-field">
                                        <option value="">不关联</option>
                                        {leaveTypes.map(lt => (
                                            <option key={lt.id} value={lt.id}>{lt.name}</option>
                                        ))}
                                    </select>
                                </div>
                                <div className="col-span-2">
                                    <label className="block text-sm font-medium text-gray-700 mb-2">
                                        关联节次
                                        <span className="text-gray-400 font-normal ml-1">(用于考勤统计，可多选)</span>
                                    </label>
                                    <div className="grid grid-cols-4 sm:grid-cols-6 md:grid-cols-8 gap-2">
                                        {periods.map((p) => {
                                            const periodId = parseInt(p.id, 10);
                                            const isDisabled = formOccupiedFixedPeriodIds.has(periodId);
                                            const isSelected = !isDisabled && selectedPeriodIds.some(id => parseInt(id, 10) === periodId);
                                            return (
                                                <label
                                                    key={p.id}
                                                    title={isDisabled ? `本班已有「${p.name}」固定点名类型，不可重复选择` : p.name}
                                                    className={`flex flex-col items-center justify-center p-2 border rounded transition-colors text-sm ${isDisabled
                                                        ? 'bg-gray-100 border-gray-200 text-gray-400 cursor-not-allowed'
                                                        : isSelected
                                                            ? 'bg-indigo-100 border-indigo-500 text-indigo-700 cursor-pointer font-medium'
                                                            : 'hover:bg-gray-50 border-gray-200 cursor-pointer'
                                                        }`}
                                                >
                                                    <input
                                                        type="checkbox"
                                                        className="sr-only"
                                                        disabled={isDisabled}
                                                        checked={isSelected}
                                                        onChange={() => {
                                                            if (isDisabled) return;
                                                            setSelectedPeriodIds(prev => {
                                                                const next = prev.some(id => parseInt(id, 10) === periodId)
                                                                    ? prev.filter(id => parseInt(id, 10) !== periodId)
                                                                    : [...prev, periodId];
                                                                if (!editingType) {
                                                                    const trimmed = formName.trim();
                                                                    if (trimmed === '' || trimmed === lastAutoFormName) {
                                                                        const suggested = suggestCourseRollCallName(next, periods);
                                                                        setFormName(suggested);
                                                                        setLastAutoFormName(suggested);
                                                                    }
                                                                }
                                                                return next;
                                                            });
                                                        }}
                                                    />
                                                    <span>{p.name}</span>
                                                    {isDisabled && <span className="text-[10px] text-gray-400 leading-tight mt-0.5">已有</span>}
                                                </label>
                                            );
                                        })}
                                    </div>
                                    {formMatchedExistingType && (
                                        <div className="mt-2 flex items-center gap-1.5 text-xs text-blue-700 bg-blue-50 border border-blue-200 rounded-md px-2.5 py-1.5">
                                            <InformationCircleIcon className="h-4 w-4 flex-shrink-0" />
                                            <span>本班已有同节次类型「{formMatchedExistingType.name}」，可直接使用原类型或改名另建。</span>
                                        </div>
                                    )}
                                    {periods.length === 0 && (
                                        <p className="text-sm text-gray-400">暂无节次配置，请在系统设置中添加</p>
                                    )}
                                    <p className="text-xs text-gray-400 mt-2">
                                        已选 {selectedPeriodIds.length} 个节次；本班已有固定活动（如早读/早操/晚操）自动置灰不可重复选择，正课节次可自由组合。
                                    </p>
                                </div>
                                <label className="flex items-center col-span-2">
                                    <input name="is_active" type="checkbox" defaultChecked={editingType?.is_active ?? true} className="mr-2" />
                                    启用
                                </label>
                            </div>
                            <div className="flex justify-end gap-2 mt-4">
                                <button type="button" onClick={() => { setShowTypeForm(false); setEditingType(null); setBatchTypeMode(false); setBatchTypeClassIds([]); }} className="btn-secondary">
                                    取消
                                </button>
                                <button type="submit" className="btn-primary" disabled={(batchTypeMode || editingType?._isGroup) && batchTypeClassIds.length === 0}>
                                    {editingType?._isGroup
                                        ? `更新 ${batchTypeClassIds.length} 个班级`
                                        : batchTypeMode
                                            ? `为 ${batchTypeClassIds.length} 个班级创建`
                                            : '保存'}
                                </button>
                            </div>
                        </form>
                    )}

                    {/* Grouped view for department manager without class filter */}
                    {groupedTypes && groupedTypes.length > 0 ? (
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {groupedTypes.map(group => (
                                <div
                                    key={group.name}
                                    className="border rounded-lg p-4 hover:shadow-md transition-shadow"
                                >
                                    <div className="flex justify-between items-start">
                                        <div className="flex-1">
                                            <h4 className="font-medium text-gray-900">{group.name}</h4>
                                            <div className="text-xs text-indigo-600 mt-1">
                                                📚 {group.classNames.join(' / ')}
                                            </div>
                                            {group.description && (
                                                <p className="text-sm text-gray-500 mt-1">{group.description}</p>
                                            )}
                                            <div className="text-xs text-gray-400 mt-2">
                                                未到标记: {leaveTypes.find(lt => lt.slug === group.absent_status)?.name || group.absent_status}
                                            </div>
                                            {group.creator && (
                                                <div className="text-xs text-gray-400 mt-1">
                                                    创建: {group.creator.name}
                                                </div>
                                            )}
                                        </div>
                                        <div className="flex gap-1">
                                            <button
                                                onClick={() => {
                                                    // Edit group - enter batch mode with existing classes
                                                    const firstType = group.types[0];
                                                    setEditingType({ ...firstType, _isGroup: true, _groupTypes: group.types });
                                                    setFormName(firstType.name || '');
                                                    setLastAutoFormName('');
                                                    setBatchTypeMode(true);
                                                    setBatchTypeClassIds(group.classIds);
                                                    // 确保 period_ids 转换为整数数组
                                                    setSelectedPeriodIds((firstType.period_ids || []).map(id => parseInt(id, 10)));
                                                    setShowTypeForm(true);
                                                }}
                                                className="text-indigo-600 hover:text-indigo-800 p-1"
                                                title="编辑班级"
                                            >
                                                <PencilIcon className="h-4 w-4" />
                                            </button>
                                            <button
                                                onClick={async () => {
                                                    if (!confirm(`确定要删除所有班级的「${group.name}」活动类型吗？`)) return;
                                                    try {
                                                        await Promise.all(group.types.map(t => axios.delete(`/roll-call-types/${t.id}`)));
                                                        fetchData();
                                                    } catch (err) {
                                                        alert('Error: ' + err.message);
                                                    }
                                                }}
                                                className="text-red-600 hover:text-red-800 p-1"
                                                title="删除全部"
                                            >
                                                <TrashIcon className="h-4 w-4" />
                                            </button>
                                        </div>
                                    </div>
                                    <button
                                        onClick={() => {
                                            // Show batch modal with this group's classes pre-selected
                                            setBatchTypeId(group.types[0].id);
                                            setSelectedClassIds(group.classIds);
                                            setShowBatchModal(true);
                                        }}
                                        className="mt-4 w-full btn-primary flex items-center justify-center"
                                    >
                                        <PlayIcon className="h-4 w-4 mr-1" /> 开始点名
                                    </button>
                                </div>
                            ))}
                        </div>
                    ) : filteredTypes.length === 0 ? (
                        <div className="text-center py-8 text-gray-500">
                            <p>暂无活动类型</p>
                            <p className="text-sm mt-1">点击"新增类型"创建点名活动</p>
                        </div>
                    ) : (
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {filteredTypes.map(type => (
                                <div
                                    key={type.id}
                                    className="border rounded-lg p-4 hover:shadow-md transition-shadow"
                                >
                                    <div className="flex justify-between items-start">
                                        <div>
                                            <h4 className="font-medium text-gray-900">{type.name}</h4>
                                            {/* Show class name for department managers */}
                                            {isDepartmentManager && type.class && (
                                                <div className="text-xs text-indigo-600 mt-0.5">
                                                    📚 {type.class.name}
                                                </div>
                                            )}
                                            {type.description && (
                                                <p className="text-sm text-gray-500 mt-1">{type.description}</p>
                                            )}
                                            <div className="text-xs text-gray-400 mt-2">
                                                未到标记: {leaveTypes.find(lt => lt.slug === type.absent_status)?.name || type.absent_status}
                                            </div>
                                            {/* Show creator info */}
                                            {type.creator && (
                                                <div className="text-xs text-gray-400 mt-1">
                                                    创建: {type.creator.name}
                                                </div>
                                            )}
                                        </div>
                                        {isTeacherOrAdmin && (
                                            <div className="flex gap-1">
                                                <button
                                                    onClick={() => {
                                                        setEditingType(type);
                                                        setFormClassId(type.class_id);
                                                        setFormName(type.name || '');
                                                        setLastAutoFormName('');
                                                        // 确保 period_ids 转换为整数数组
                                                        setSelectedPeriodIds((type.period_ids || []).map(id => parseInt(id, 10)));
                                                        setShowTypeForm(true);
                                                    }}
                                                    className="text-indigo-600 hover:text-indigo-800 p-1"
                                                    title="编辑"
                                                >
                                                    <PencilIcon className="h-4 w-4" />
                                                </button>
                                                <button
                                                    onClick={() => deleteType(type.id)}
                                                    className="text-red-600 hover:text-red-800 p-1"
                                                    title="删除"
                                                >
                                                    <TrashIcon className="h-4 w-4" />
                                                </button>
                                            </div>
                                        )}
                                    </div>
                                    <button
                                        onClick={() => startRollCall(type.id)}
                                        className="mt-4 w-full btn-primary flex items-center justify-center"
                                    >
                                        <PlayIcon className="h-4 w-4 mr-1" /> 开始点名
                                    </button>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </div>

            {/* One-off Course Roll Call Modal (方案 B: 仅本次有效，下次重新选择节次) */}
            {showCourseModal && (
                <div
                    className="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 p-4"
                    onClick={() => !courseLoading && setShowCourseModal(false)}
                >
                    <form
                        onSubmit={handleStartOneOffCourseRollCall}
                        onClick={(e) => e.stopPropagation()}
                        className="bg-white rounded-xl shadow-xl max-w-lg w-full max-h-[88vh] overflow-y-auto"
                    >
                        <div className="px-6 py-4 border-b flex items-center justify-between">
                            <div>
                                <h3 className="text-lg font-semibold text-gray-900">发起本次课程点名</h3>
                                <p className="text-xs text-gray-500 mt-0.5">
                                    仅针对本次点名生效，不会添加到常用活动类型；明天或下次点名可重新自由选择节次
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setShowCourseModal(false)}
                                className="text-gray-400 hover:text-gray-600"
                            >
                                <XMarkIcon className="h-5 w-5" />
                            </button>
                        </div>

                        <div className="px-6 py-4 space-y-4">
                            {classes.length > 1 && (
                                <div>
                                    <label className="block text-sm font-medium text-gray-700 mb-1">班级</label>
                                    <select
                                        value={courseClassId || ''}
                                        onChange={(e) => handleCourseClassChange(e.target.value)}
                                        className="input-field"
                                    >
                                        {classes.map((c) => (
                                            <option key={c.id} value={c.id}>{c.name}</option>
                                        ))}
                                    </select>
                                </div>
                            )}

                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-2">
                                    选择本次课程节次
                                    <span className="text-gray-400 font-normal ml-1">(可多选，用于同步请假与考勤)</span>
                                </label>
                                <div className="grid grid-cols-4 gap-2">
                                    {periods.map((p) => {
                                        const pid = parseInt(p.id, 10);
                                        const isDisabled = courseOccupiedFixedPeriodIds.has(pid);
                                        const isSelected = !isDisabled && coursePeriodIds.includes(pid);
                                        return (
                                            <button
                                                key={p.id}
                                                type="button"
                                                disabled={isDisabled}
                                                title={isDisabled ? `本班已有「${p.name}」固定类型，请直接从活动类型列表发起点名` : p.name}
                                                onClick={() => {
                                                    if (isDisabled) return;
                                                    setCoursePeriodIds((prev) => {
                                                        const next = prev.includes(pid) ? prev.filter((id) => id !== pid) : [...prev, pid];
                                                        const trimmed = courseName.trim();
                                                        if (trimmed === '' || trimmed === lastAutoCourseName) {
                                                            const suggested = suggestCourseRollCallName(next, periods);
                                                            setCourseName(suggested);
                                                            setLastAutoCourseName(suggested);
                                                        }
                                                        return next;
                                                    });
                                                }}
                                                className={`flex flex-col items-center justify-center p-2 border rounded-lg transition-colors text-sm ${isDisabled
                                                    ? 'bg-gray-100 border-gray-200 text-gray-400 cursor-not-allowed'
                                                    : isSelected
                                                        ? 'bg-indigo-100 border-indigo-500 text-indigo-700 font-medium'
                                                        : 'hover:bg-gray-50 border-gray-200 text-gray-700'
                                                    }`}
                                            >
                                                <span>{p.name}</span>
                                                {isDisabled && <span className="text-[10px] text-gray-400 leading-tight mt-0.5">已有</span>}
                                            </button>
                                        );
                                    })}
                                </div>
                                <p className="text-xs text-gray-400 mt-1.5">
                                    已选 {coursePeriodIds.length} 个节次；早读/早操/晚操等本班已有固定活动置灰不可重复选，正课节次每次可自由重选。
                                </p>
                            </div>

                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1">本次点名名称</label>
                                <input
                                    required
                                    value={courseName}
                                    onChange={(e) => setCourseName(e.target.value)}
                                    placeholder="如：第1-2节点名 / 专业课点名"
                                    className="input-field"
                                />
                            </div>

                            <div className="grid grid-cols-2 gap-3">
                                <div>
                                    <label className="block text-sm font-medium text-gray-700 mb-1">未到标记为</label>
                                    <select
                                        value={courseAbsentStatus}
                                        onChange={(e) => setCourseAbsentStatus(e.target.value)}
                                        className="input-field"
                                    >
                                        <option value="absent">旷课</option>
                                        <option value="late">迟到</option>
                                        <option value="leave">请假</option>
                                    </select>
                                </div>
                                <div>
                                    <label className="block text-sm font-medium text-gray-700 mb-1">关联请假类型(可选)</label>
                                    <select
                                        value={courseLeaveTypeId}
                                        onChange={(e) => setCourseLeaveTypeId(e.target.value)}
                                        className="input-field"
                                    >
                                        <option value="">不关联</option>
                                        {leaveTypes.map((lt) => (
                                            <option key={lt.id} value={lt.id}>{lt.name}</option>
                                        ))}
                                    </select>
                                </div>
                            </div>

                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1">备注 (可选)</label>
                                <input
                                    value={courseNotes}
                                    onChange={(e) => setCourseNotes(e.target.value)}
                                    placeholder="可选备注"
                                    className="input-field"
                                />
                            </div>
                        </div>

                        <div className="px-6 py-4 border-t bg-gray-50 flex justify-end gap-3">
                            <button
                                type="button"
                                onClick={() => setShowCourseModal(false)}
                                disabled={courseLoading}
                                className="btn-secondary"
                            >
                                取消
                            </button>
                            <button
                                type="submit"
                                disabled={courseLoading || !courseName.trim() || !courseClassId}
                                className="btn-primary flex items-center gap-1"
                            >
                                <PlayIcon className="h-4 w-4" />
                                {courseLoading ? '创建中...' : '立即开始本次点名'}
                            </button>
                        </div>
                    </form>
                </div>
            )}

            {/* Batch Selection Modal for Department Manager */}
            {showBatchModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
                    <div className="bg-white rounded-lg shadow-xl max-w-lg w-full mx-4 max-h-[80vh] overflow-hidden">
                        <div className="px-6 py-4 border-b">
                            <h3 className="text-lg font-medium text-gray-900">选择班级创建点名</h3>
                            <p className="text-sm text-gray-500 mt-1">可选择多个班级同时创建点名</p>
                        </div>

                        <div className="px-6 py-4">
                            {/* Quick actions */}
                            <div className="flex gap-2 mb-4">
                                <button
                                    onClick={selectAllClasses}
                                    className="text-sm text-indigo-600 hover:text-indigo-800"
                                >
                                    全选本系
                                </button>
                                <span className="text-gray-300">|</span>
                                <button
                                    onClick={deselectAllClasses}
                                    className="text-sm text-gray-600 hover:text-gray-800"
                                >
                                    取消全选
                                </button>
                                <span className="text-gray-400 ml-auto text-sm">
                                    已选: {selectedClassIds.length} / {classes.length}
                                </span>
                            </div>

                            {/* Class list */}
                            <div className="space-y-2 max-h-60 overflow-y-auto">
                                {classes.map(c => (
                                    <label
                                        key={c.id}
                                        className={`flex items-center p-3 rounded-lg border cursor-pointer transition-colors ${selectedClassIds.includes(c.id)
                                            ? 'border-indigo-500 bg-indigo-50'
                                            : 'border-gray-200 hover:bg-gray-50'
                                            }`}
                                    >
                                        <input
                                            type="checkbox"
                                            checked={selectedClassIds.includes(c.id)}
                                            onChange={() => toggleClassSelection(c.id)}
                                            className="h-4 w-4 text-indigo-600 rounded"
                                        />
                                        <span className="ml-3 text-sm font-medium text-gray-900">
                                            {c.name}
                                        </span>
                                        {c.department?.name && (
                                            <span className="ml-2 text-xs text-gray-500">
                                                ({c.department.name})
                                            </span>
                                        )}
                                    </label>
                                ))}
                            </div>
                        </div>

                        <div className="px-6 py-4 border-t flex justify-end gap-3">
                            <button
                                onClick={() => {
                                    setShowBatchModal(false);
                                    setBatchTypeId(null);
                                    setSelectedClassIds([]);
                                }}
                                className="btn-secondary"
                                disabled={batchLoading}
                            >
                                取消
                            </button>
                            <button
                                onClick={handleBatchCreate}
                                className="btn-primary"
                                disabled={batchLoading || selectedClassIds.length === 0}
                            >
                                {batchLoading ? '创建中...' : `为 ${selectedClassIds.length} 个班级创建点名`}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </Layout>
    );
}
