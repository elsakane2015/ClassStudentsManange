import React, { useState, useEffect } from 'react';
import Layout from '../../components/Layout';
import axios from 'axios';
import { PlusIcon, TrashIcon, PencilIcon, UserGroupIcon } from '@heroicons/react/24/outline';
import { Link } from 'react-router-dom';

export default function RollCallAdminsPage() {
    const [admins, setAdmins] = useState([]);
    const [students, setStudents] = useState([]);
    const [rollCallTypes, setRollCallTypes] = useState([]);
    const [classes, setClasses] = useState([]);
    const [selectedClassId, setSelectedClassId] = useState(null);
    const [loading, setLoading] = useState(true);
    const [showForm, setShowForm] = useState(false);
    const [editingAdmin, setEditingAdmin] = useState(null);
    const [formData, setFormData] = useState({
        student_id: '',
        roll_call_type_ids: [],
        can_modify_records: false,
    });

    useEffect(() => {
        fetchInitialData();
    }, []);

    useEffect(() => {
        if (selectedClassId) {
            fetchAdmins();
            fetchClassStudents();
            fetchClassTypes();
        }
    }, [selectedClassId]);

    const fetchInitialData = async () => {
        try {
            const classesRes = await axios.get('/options/classes');
            setClasses(classesRes.data || []);
            if (classesRes.data?.length > 0) {
                setSelectedClassId(classesRes.data[0].id);
            }
        } catch (err) {
            console.error('Failed to fetch classes:', err);
        } finally {
            setLoading(false);
        }
    };

    const fetchAdmins = async () => {
        try {
            const res = await axios.get(`/roll-call-admins?class_id=${selectedClassId}`);
            setAdmins(res.data);
        } catch (err) {
            console.error('Failed to fetch admins:', err);
        }
    };

    const fetchClassStudents = async () => {
        try {
            const res = await axios.get(`/students?class_id=${selectedClassId}`);
            setStudents(res.data.data || res.data || []);
        } catch (err) {
            console.error('Failed to fetch students:', err);
        }
    };

    const fetchClassTypes = async () => {
        try {
            const res = await axios.get(`/roll-call-types?class_id=${selectedClassId}`);
            setRollCallTypes(res.data);
        } catch (err) {
            console.error('Failed to fetch types:', err);
        }
    };

    const handleSubmit = async (e) => {
        e.preventDefault();

        if (formData.roll_call_type_ids.length === 0) {
            alert('请至少选择一个活动类型');
            return;
        }

        try {
            const data = {
                class_id: selectedClassId,
                student_id: parseInt(formData.student_id),
                roll_call_type_ids: formData.roll_call_type_ids.map(id => parseInt(id)),
                can_modify_records: formData.can_modify_records,
            };

            if (editingAdmin) {
                await axios.put(`/roll-call-admins/${editingAdmin.id}`, data);
            } else {
                await axios.post('/roll-call-admins', data);
            }

            setShowForm(false);
            setEditingAdmin(null);
            setFormData({ student_id: '', roll_call_type_ids: [], can_modify_records: false });
            fetchAdmins();
        } catch (err) {
            alert('Error: ' + (err.response?.data?.error || err.message));
        }
    };

    const toggleActive = async (admin) => {
        try {
            await axios.put(`/roll-call-admins/${admin.id}`, {
                is_active: !admin.is_active,
            });
            fetchAdmins();
        } catch (err) {
            alert('Error: ' + err.message);
        }
    };

    const deleteAdmin = async (id) => {
        if (!confirm('确定要删除这个点名员吗？')) return;
        try {
            await axios.delete(`/roll-call-admins/${id}`);
            fetchAdmins();
        } catch (err) {
            alert('Error: ' + err.message);
        }
    };

    const openEditForm = (admin) => {
        setEditingAdmin(admin);
        setFormData({
            student_id: admin.student_id.toString(),
            roll_call_type_ids: admin.roll_call_type_ids || [],
            can_modify_records: admin.can_modify_records || false,
        });
        setShowForm(true);
    };

    const handleTypeToggle = (typeId) => {
        setFormData(prev => {
            const ids = prev.roll_call_type_ids.includes(typeId)
                ? prev.roll_call_type_ids.filter(id => id !== typeId)
                : [...prev.roll_call_type_ids, typeId];
            return { ...prev, roll_call_type_ids: ids };
        });
    };

    // Filter out students who are already admins (for new admin form)
    const availableStudents = editingAdmin
        ? students
        : students.filter(s => !admins.some(a => a.student_id === s.id));

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
                        <h2 className="text-2xl font-bold leading-7 text-gray-900">点名员管理</h2>
                        <p className="mt-1 text-sm text-gray-500">指定学生为点名员，并授权可使用的活动类型</p>
                    </div>
                    <div className="mt-4 md:ml-4 md:mt-0">
                        <Link to="/roll-call" className="btn-secondary">
                            返回点名
                        </Link>
                    </div>
                </div>

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

                {/* Add Button */}
                <div className="bg-white shadow rounded-lg p-6">
                    <div className="flex justify-between items-center mb-4">
                        <h3 className="text-lg font-medium text-gray-900">点名员列表</h3>
                        <button
                            onClick={() => { setEditingAdmin(null); setFormData({ student_id: '', roll_call_type_ids: [], can_modify_records: false }); setShowForm(true); }}
                            className="btn-primary"
                            disabled={availableStudents.length === 0}
                        >
                            <PlusIcon className="h-4 w-4 mr-1" /> 添加点名员
                        </button>
                    </div>

                    {/* Form */}
                    {showForm && (
                        <form onSubmit={handleSubmit} className="bg-gray-50 p-4 rounded-lg mb-4 border">
                            <div className="space-y-4">
                                <div>
                                    <label className="block text-sm font-medium text-gray-700 mb-1">选择学生</label>
                                    <select
                                        value={formData.student_id}
                                        onChange={(e) => setFormData({ ...formData, student_id: e.target.value })}
                                        required
                                        disabled={editingAdmin}
                                        className="input-field"
                                    >
                                        <option value="">请选择学生</option>
                                        {availableStudents.map(s => (
                                            <option key={s.id} value={s.id}>
                                                {s.user?.name || s.name} ({s.student_no})
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                <div>
                                    <label className="block text-sm font-medium text-gray-700 mb-2">授权活动类型</label>
                                    {rollCallTypes.length === 0 ? (
                                        <p className="text-sm text-gray-500">该班级暂无活动类型，请先创建活动类型</p>
                                    ) : (
                                        <div className="flex flex-wrap gap-2">
                                            {rollCallTypes.map(type => (
                                                <label
                                                    key={type.id}
                                                    className={`px-3 py-2 rounded-lg border cursor-pointer transition-colors ${formData.roll_call_type_ids.includes(type.id)
                                                        ? 'bg-indigo-100 border-indigo-500 text-indigo-700'
                                                        : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50'
                                                        }`}
                                                >
                                                    <input
                                                        type="checkbox"
                                                        checked={formData.roll_call_type_ids.includes(type.id)}
                                                        onChange={() => handleTypeToggle(type.id)}
                                                        className="sr-only"
                                                    />
                                                    {type.name}
                                                </label>
                                            ))}
                                        </div>
                                    )}
                                </div>
                                <div className="mt-4">
                                    <label className="flex items-center gap-2 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            checked={formData.can_modify_records}
                                            onChange={(e) => setFormData({ ...formData, can_modify_records: e.target.checked })}
                                            className="w-4 h-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500"
                                        />
                                        <span className="text-sm font-medium text-gray-700">允许修改点名记录</span>
                                    </label>
                                    <p className="text-xs text-gray-500 mt-1 ml-6">勾选后，该点名员可以修改已完成点名的考勤状态</p>
                                </div>
                            </div>
                            <div className="flex justify-end gap-2 mt-4">
                                <button type="button" onClick={() => { setShowForm(false); setEditingAdmin(null); }} className="btn-secondary">
                                    取消
                                </button>
                                <button type="submit" className="btn-primary" disabled={rollCallTypes.length === 0}>
                                    保存
                                </button>
                            </div>
                        </form>
                    )}

                    {/* List */}
                    {admins.length === 0 ? (
                        <div className="text-center py-8 text-gray-500">
                            <UserGroupIcon className="h-12 w-12 mx-auto text-gray-300 mb-2" />
                            <p>暂无点名员</p>
                            <p className="text-sm mt-1">点击"添加点名员"指定学生为点名员</p>
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">学生</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">授权类型</th>
                                        <th className="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">修改权限</th>
                                        <th className="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">状态</th>
                                        <th className="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">操作</th>
                                    </tr>
                                </thead>
                                <tbody className="bg-white divide-y divide-gray-200">
                                    {admins.map(admin => (
                                        <tr key={admin.id}>
                                            <td className="px-6 py-4 whitespace-nowrap">
                                                <div className="font-medium text-gray-900">
                                                    {admin.student?.user?.name || admin.student?.name}
                                                </div>
                                                <div className="text-sm text-gray-500">
                                                    {admin.student?.student_no}
                                                </div>
                                            </td>
                                            <td className="px-6 py-4">
                                                <div className="flex flex-wrap gap-1">
                                                    {admin.authorized_types?.map(type => (
                                                        <span key={type.id} className="px-2 py-1 text-xs rounded-full bg-indigo-100 text-indigo-700">
                                                            {type.name}
                                                        </span>
                                                    ))}
                                                </div>
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap text-center">
                                                <span className={`px-2 py-1 text-xs rounded-full ${admin.can_modify_records
                                                    ? 'bg-green-100 text-green-800'
                                                    : 'bg-gray-100 text-gray-500'
                                                    }`}>
                                                    {admin.can_modify_records ? '允许' : '禁止'}
                                                </span>
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap text-center">
                                                <button
                                                    onClick={() => toggleActive(admin)}
                                                    className={`px-2 py-1 text-xs rounded-full ${admin.is_active
                                                        ? 'bg-green-100 text-green-800'
                                                        : 'bg-gray-100 text-gray-800'
                                                        }`}
                                                >
                                                    {admin.is_active ? '启用' : '停用'}
                                                </button>
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap text-center space-x-2">
                                                <button
                                                    onClick={() => openEditForm(admin)}
                                                    className="text-indigo-600 hover:text-indigo-800"
                                                    title="编辑"
                                                >
                                                    <PencilIcon className="h-4 w-4 inline" />
                                                </button>
                                                <button
                                                    onClick={() => deleteAdmin(admin.id)}
                                                    className="text-red-600 hover:text-red-800"
                                                    title="删除"
                                                >
                                                    <TrashIcon className="h-4 w-4 inline" />
                                                </button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </div>
        </Layout>
    );
}
