import React, { useState, useEffect } from 'react';
import Layout from '../../components/Layout';
import axios from 'axios';
import { format } from 'date-fns';
import { zhCN } from 'date-fns/locale';
import { PlusIcon, TrashIcon, PencilIcon, UserGroupIcon, ClockIcon, CheckCircleIcon, PlayIcon } from '@heroicons/react/24/outline';
import { Link, useNavigate } from 'react-router-dom';
import useAuthStore from '../../store/authStore';

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
                    setPeriods(Array.isArray(periodsData) ? periodsData : []);
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
                roll_call_time: new Date().toISOString(),
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
                roll_call_time: new Date().toISOString(),
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
                                        onClick={() => { setEditingType(null); setSelectedPeriodIds([]); setShowTypeForm(true); }}
                                        className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-medium bg-green-100 text-green-700 hover:bg-green-200 transition-colors"
                                    >
                                        <PlusIcon className="h-4 w-4" />
                                        <span className="hidden sm:inline">新增</span>
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
                                        defaultValue={editingType?.name}
                                        placeholder="早操点名"
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
                                        <select name="class_id" defaultValue={editingType?.class_id || selectedClassId} className="input-field">
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
                                            const isSelected = selectedPeriodIds.some(id => parseInt(id) === parseInt(p.id));
                                            return (
                                                <label
                                                    key={p.id}
                                                    className={`flex items-center justify-center p-2 border rounded cursor-pointer transition-colors text-sm ${isSelected
                                                        ? 'bg-indigo-100 border-indigo-500 text-indigo-700'
                                                        : 'hover:bg-gray-50 border-gray-200'
                                                        }`}
                                                >
                                                    <input
                                                        type="checkbox"
                                                        className="sr-only"
                                                        checked={isSelected}
                                                        onChange={() => {
                                                            const periodId = parseInt(p.id);
                                                            setSelectedPeriodIds(prev =>
                                                                prev.some(id => parseInt(id) === periodId)
                                                                    ? prev.filter(id => parseInt(id) !== periodId)
                                                                    : [...prev, periodId]
                                                            );
                                                        }}
                                                    />
                                                    {p.name}
                                                </label>
                                            );
                                        })}
                                    </div>
                                    {periods.length === 0 && (
                                        <p className="text-sm text-gray-400">暂无节次配置，请在系统设置中添加</p>
                                    )}
                                    <p className="text-xs text-gray-400 mt-2">
                                        已选 {selectedPeriodIds.length} 个节次，缺勤时将生成对应的考勤记录
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
                                                    setBatchTypeMode(true);
                                                    setBatchTypeClassIds(group.classIds);
                                                    // 确保 period_ids 转换为整数数组
                                                    setSelectedPeriodIds((firstType.period_ids || []).map(id => parseInt(id)));
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
                                                        // 确保 period_ids 转换为整数数组
                                                        setSelectedPeriodIds((type.period_ids || []).map(id => parseInt(id)));
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
