import React, { useState, useEffect } from 'react';
import Layout from '../../components/Layout';
import axios from 'axios';
import { format } from 'date-fns';
import { zhCN } from 'date-fns/locale';
import { PlusIcon, TrashIcon, PencilIcon, UserGroupIcon, ClockIcon, CheckCircleIcon, PlayIcon } from '@heroicons/react/24/outline';
import { Link, useNavigate } from 'react-router-dom';

export default function RollCallPage() {
    const navigate = useNavigate();
    const [rollCallTypes, setRollCallTypes] = useState([]);
    const [inProgressRollCalls, setInProgressRollCalls] = useState([]);
    const [loading, setLoading] = useState(true);
    const [showTypeForm, setShowTypeForm] = useState(false);
    const [editingType, setEditingType] = useState(null);
    const [selectedClassId, setSelectedClassId] = useState(null);
    const [classes, setClasses] = useState([]);
    const [leaveTypes, setLeaveTypes] = useState([]);

    useEffect(() => {
        fetchData();
    }, []);

    const fetchData = async () => {
        setLoading(true);
        try {
            const [typesRes, inProgressRes, classesRes, leaveTypesRes] = await Promise.all([
                axios.get('/roll-call-types'),
                axios.get('/roll-calls/in-progress'),
                axios.get('/options/classes'),
                axios.get('/leave-types'),
            ]);
            setRollCallTypes(typesRes.data);
            setInProgressRollCalls(inProgressRes.data);
            setClasses(classesRes.data || []);
            setLeaveTypes(leaveTypesRes.data || []);

            // Set default class if available
            if (classesRes.data?.length > 0 && !selectedClassId) {
                setSelectedClassId(classesRes.data[0].id);
            }
        } catch (err) {
            console.error('Failed to fetch data:', err);
        } finally {
            setLoading(false);
        }
    };

    const handleTypeSubmit = async (e) => {
        e.preventDefault();
        const formData = new FormData(e.target);
        const data = Object.fromEntries(formData);
        data.class_id = parseInt(data.class_id);
        data.is_active = data.is_active === 'on';
        if (data.leave_type_id) data.leave_type_id = parseInt(data.leave_type_id);
        else delete data.leave_type_id;

        try {
            if (editingType) {
                await axios.put(`/roll-call-types/${editingType.id}`, data);
            } else {
                await axios.post('/roll-call-types', data);
            }
            setShowTypeForm(false);
            setEditingType(null);
            fetchData();
        } catch (err) {
            alert('Error: ' + (err.response?.data?.message || err.message));
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

    const filteredTypes = selectedClassId
        ? rollCallTypes.filter(t => t.class_id === selectedClassId)
        : rollCallTypes;

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

                {/* Class Filter */}
                <div className="flex items-center gap-4">
                    <label className="text-sm font-medium text-gray-700">选择班级:</label>
                    <select
                        value={selectedClassId || ''}
                        onChange={(e) => setSelectedClassId(parseInt(e.target.value))}
                        className="input-field max-w-xs"
                    >
                        {classes.map(c => (
                            <option key={c.id} value={c.id}>{c.name}</option>
                        ))}
                    </select>
                </div>

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
                            <Link
                                to="/roll-call/admins"
                                className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-medium bg-blue-100 text-blue-700 hover:bg-blue-200 transition-colors"
                            >
                                <UserGroupIcon className="h-4 w-4" />
                                <span className="hidden sm:inline">点名员</span>
                            </Link>
                            <button
                                onClick={() => { setEditingType(null); setShowTypeForm(true); }}
                                className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-medium bg-green-100 text-green-700 hover:bg-green-200 transition-colors"
                            >
                                <PlusIcon className="h-4 w-4" />
                                <span className="hidden sm:inline">新增</span>
                            </button>
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
                                </div>
                                <div>
                                    <label className="block text-sm font-medium text-gray-700 mb-1">班级</label>
                                    <select name="class_id" defaultValue={editingType?.class_id || selectedClassId} className="input-field">
                                        {classes.map(c => (
                                            <option key={c.id} value={c.id}>{c.name}</option>
                                        ))}
                                    </select>
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
                                        <option value="absent">旷课 (absent)</option>
                                        <option value="late">迟到 (late)</option>
                                        <option value="leave">请假 (leave)</option>
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
                                <label className="flex items-center col-span-2">
                                    <input name="is_active" type="checkbox" defaultChecked={editingType?.is_active ?? true} className="mr-2" />
                                    启用
                                </label>
                            </div>
                            <div className="flex justify-end gap-2 mt-4">
                                <button type="button" onClick={() => { setShowTypeForm(false); setEditingType(null); }} className="btn-secondary">
                                    取消
                                </button>
                                <button type="submit" className="btn-primary">保存</button>
                            </div>
                        </form>
                    )}

                    {filteredTypes.length === 0 ? (
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
                                            {type.description && (
                                                <p className="text-sm text-gray-500 mt-1">{type.description}</p>
                                            )}
                                            <div className="text-xs text-gray-400 mt-2">
                                                未到标记: {type.absent_status === 'absent' ? '旷课' : type.absent_status === 'late' ? '迟到' : '请假'}
                                            </div>
                                        </div>
                                        <div className="flex gap-1">
                                            <button
                                                onClick={() => { setEditingType(type); setShowTypeForm(true); }}
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
        </Layout>
    );
}
