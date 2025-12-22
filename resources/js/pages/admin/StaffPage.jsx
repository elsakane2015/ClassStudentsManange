import React, { useState, useEffect } from 'react';
import Layout from '../../components/Layout';
import useAuthStore from '../../store/authStore';
import axios from 'axios';
import { Tab } from '@headlessui/react';
import { PlusIcon, PencilSquareIcon, TrashIcon } from '@heroicons/react/24/outline'; // Adjust import if needed

function classNames(...classes) {
    return classes.filter(Boolean).join(' ');
}

export default function StaffPage() {
    const { user } = useAuthStore();
    const [systemAdmins, setSystemAdmins] = useState([]);
    const [schoolAdmins, setSchoolAdmins] = useState([]);
    const [admins, setAdmins] = useState([]); // Keep for backward compatibility
    const [managers, setManagers] = useState([]);
    const [teachers, setTeachers] = useState([]);

    const [editingUser, setEditingUser] = useState(null);
    const [showForm, setShowForm] = useState(false);

    const [departments, setDepartments] = useState([]);
    const [classes, setClasses] = useState([]);

    // Form States
    const [formData, setFormData] = useState({ name: '', email: '', password: '', role: '', department_id: '', class_ids: [] });
    const [currentTabRole, setCurrentTabRole] = useState('system_admin'); // Updated default

    const fetchUsers = async (role) => {
        try {
            const res = await axios.get(`/users?role=${role}`);
            if (role === 'system_admin') setSystemAdmins(res.data);
            if (role === 'school_admin') setSchoolAdmins(res.data);
            if (role === 'admin') setAdmins(res.data); // Backward compatibility
            if (role === 'department_manager') setManagers(res.data);
            if (role === 'manager') setManagers(res.data); // Backward compatibility
            if (role === 'teacher') setTeachers(res.data);
        } catch (error) {
            console.error(error);
        }
    };

    const fetchOptions = async () => {
        try {
            const [deptRes, classRes] = await Promise.all([
                axios.get('/options/departments'),
                axios.get('/options/classes')
            ]);
            setDepartments(deptRes.data);
            setClasses(classRes.data);
        } catch (e) {
            console.error(e);
        }
    };

    useEffect(() => {
        fetchOptions();
        if (user?.role === 'system_admin') {
            fetchUsers('system_admin');
            fetchUsers('school_admin');
            fetchUsers('department_manager');
        } else if (user?.role === 'school_admin') {
            fetchUsers('department_manager');
        }
        fetchUsers('teacher');
    }, [user]);

    const handleEdit = (userData) => {
        setEditingUser(userData);
        // Extract assignment
        // Manager: userData.managed_departments (array) -> pick first id or ''
        let deptId = '';
        if (userData.role === 'manager' && userData.managed_departments?.length > 0) {
            deptId = userData.managed_departments[0].id;
        }

        // Teacher: userData.teacher_classes (array) -> map ids
        let clsIds = [];
        if (userData.role === 'teacher' && userData.teacher_classes?.length > 0) {
            clsIds = userData.teacher_classes.map(c => c.id);
        }

        setFormData({
            name: userData.name,
            email: userData.email,
            password: '',  // Don't fill password
            role: userData.role,
            department_id: deptId,
            class_ids: clsIds
        });
        setShowForm(true);
    };

    const handleDelete = async (id, role) => {
        if (!confirm('确定要删除此用户吗？此操作不可恢复。')) return;
        try {
            await axios.delete(`/users/${id}`);
            fetchUsers(role);
        } catch (error) {
            alert('删除失败: ' + (error.response?.data?.error || '未知错误'));
        }
    };

    const handleSubmit = async (e) => {
        e.preventDefault();
        try {
            const payload = { ...formData };
            if (!payload.password && editingUser) delete payload.password; // Don't send empty pass on edit
            payload.role = currentTabRole;

            if (editingUser) {
                await axios.put(`/users/${editingUser.id}`, payload);
            } else {
                await axios.post('/users', payload);
            }
            setShowForm(false);
            setEditingUser(null);
            setFormData({ name: '', email: '', password: '', role: '', department_id: '', class_ids: [] });
            fetchUsers(currentTabRole);
        } catch (error) {
            alert('操作失败: ' + (error.response?.data?.message || '未知错误'));
        }
    };

    const openCreateForm = (role) => {
        setCurrentTabRole(role);
        setEditingUser(null);
        setFormData({ name: '', email: '', password: '', role, department_id: '', class_ids: [] });
        setShowForm(true);
    };

    const tabs = [];
    if (user?.role === 'system_admin') {
        tabs.push({ key: 'system_admin', name: '系统管理员' });
        tabs.push({ key: 'school_admin', name: '校管理员' });
        tabs.push({ key: 'department_manager', name: '系部管理员' });
    } else if (user?.role === 'school_admin') {
        tabs.push({ key: 'department_manager', name: '系部管理员' });
    } else if (user?.role === 'department_manager') {
        // Department managers can only see teachers
    }
    tabs.push({ key: 'teacher', name: '班主任(教师)' });

    return (
        <Layout>
            <div className="md:flex md:items-center md:justify-between mb-6">
                <div className="min-w-0 flex-1">
                    <h2 className="text-2xl font-bold leading-7 text-gray-900 sm:truncate sm:text-3xl sm:tracking-tight">
                        人员管理
                    </h2>
                    <p className="mt-1 text-sm text-gray-500">管理学校教职工账号。</p>
                </div>
            </div>

            <Tab.Group onChange={(index) => {
                setCurrentTabRole(tabs[index].key);
                setShowForm(false);
            }}>
                <Tab.List className="flex space-x-1 rounded-xl bg-blue-900/20 p-1 mb-6 max-w-md">
                    {tabs.map((tab) => (
                        <Tab
                            key={tab.key}
                            className={({ selected }) =>
                                classNames(
                                    'w-full rounded-lg py-2.5 text-sm font-medium leading-5',
                                    'ring-white/60 ring-offset-2 ring-offset-blue-400 focus:outline-none focus:ring-2',
                                    selected
                                        ? 'bg-white text-blue-700 shadow'
                                        : 'text-blue-100 hover:bg-white/[0.12] hover:text-white'
                                )
                            }
                        >
                            {tab.name}
                        </Tab>
                    ))}
                </Tab.List>
                <Tab.Panels>
                    {tabs.map((tab) => (
                        <Tab.Panel key={tab.key}>
                            {/* Toolbar */}
                            <div className="flex justify-end mb-4">
                                <button
                                    onClick={() => openCreateForm(tab.key)}
                                    className="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                                >
                                    <PlusIcon className="-ml-0.5 mr-1.5 h-5 w-5" aria-hidden="true" />
                                    新增{tab.name}
                                </button>
                            </div>

                            {/* Table */}
                            <div className="overflow-hidden shadow ring-1 ring-black ring-opacity-5 sm:rounded-lg">
                                <table className="min-w-full divide-y divide-gray-300">
                                    <thead className="bg-gray-50">
                                        <tr>
                                            <th scope="col" className="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900 sm:pl-6">姓名</th>
                                            <th scope="col" className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">邮箱(账号)</th>
                                            {tab.key !== 'admin' && (
                                                <th scope="col" className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">
                                                    {tab.key === 'manager' ? '管理系部' : '负责班级'}
                                                </th>
                                            )}
                                            <th scope="col" className="relative py-3.5 pl-3 pr-4 sm:pr-6">
                                                <span className="sr-only">操作</span>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-200 bg-white">
                                        {(() => {
                                            let data = [];
                                            switch (tab.key) {
                                                case 'system_admin': data = systemAdmins; break;
                                                case 'school_admin': data = schoolAdmins; break;
                                                case 'department_manager': data = managers; break;
                                                case 'admin': data = admins; break; // Backward compatibility
                                                case 'manager': data = managers; break; // Backward compatibility
                                                case 'teacher': data = teachers; break;
                                                default: data = [];
                                            }
                                            return data.map((person) => (
                                                <tr key={person.id}>
                                                    <td className="whitespace-nowrap py-4 pl-4 pr-3 text-sm font-medium text-gray-900 sm:pl-6">{person.name}</td>
                                                    <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-500">{person.email}</td>
                                                    {!['system_admin', 'school_admin', 'admin'].includes(tab.key) && (
                                                        <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-500">
                                                            {['department_manager', 'manager'].includes(tab.key) && (
                                                                person.managed_departments?.length > 0
                                                                    ? person.managed_departments.map(d => d.name).join(', ')
                                                                    : <span className="text-gray-400">未分配</span>
                                                            )}
                                                            {tab.key === 'teacher' && (
                                                                person.teacher_classes?.length > 0
                                                                    ? person.teacher_classes.map(c => c.name).join(', ')
                                                                    : <span className="text-gray-400">未分配</span>
                                                            )}
                                                        </td>
                                                    )}
                                                    <td className="relative whitespace-nowrap py-4 pl-3 pr-4 text-right text-sm font-medium sm:pr-6">
                                                        <button onClick={() => handleEdit(person)} className="text-indigo-600 hover:text-indigo-900 mr-4">编辑</button>
                                                        <button onClick={() => handleDelete(person.id, tab.key)} className="text-red-600 hover:text-red-900">删除</button>
                                                    </td>
                                                </tr>
                                            ))
                                        })()}
                                        {(() => {
                                            let data = [];
                                            switch (tab.key) {
                                                case 'system_admin': data = systemAdmins; break;
                                                case 'school_admin': data = schoolAdmins; break;
                                                case 'department_manager': data = managers; break;
                                                case 'admin': data = admins; break;
                                                case 'manager': data = managers; break;
                                                case 'teacher': data = teachers; break;
                                                default: data = [];
                                            }
                                            return data.length === 0 && (
                                                <tr>
                                                    <td colSpan={['system_admin', 'school_admin', 'admin'].includes(tab.key) ? "3" : "4"} className="text-center py-4 text-gray-500 text-sm">暂无数据</td>
                                                </tr>
                                            )
                                        })()}
                                    </tbody>
                                </table>
                            </div>
                        </Tab.Panel>
                    ))}
                </Tab.Panels>
            </Tab.Group>

            {/* Modal Form */}
            {showForm && (
                <div className="fixed inset-0 z-10 overflow-y-auto">
                    <div className="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div className="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" onClick={() => setShowForm(false)}></div>
                        <div className="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6">
                            <h3 className="text-base font-semibold leading-6 text-gray-900 mb-4">
                                {editingUser ? '编辑用户' : '新增用户'} - {
                                    currentTabRole === 'system_admin' ? '系统管理员' :
                                        currentTabRole === 'school_admin' ? '校管理员' :
                                            currentTabRole === 'department_manager' ? '系部管理员' :
                                                currentTabRole === 'admin' ? '校管理员' :
                                                    currentTabRole === 'manager' ? '系部管理员' :
                                                        '班主任'
                                }
                            </h3>
                            <form onSubmit={handleSubmit}>
                                <div className="space-y-4">
                                    <div>
                                        <label className="block text-sm font-medium text-gray-700">姓名</label>
                                        <input
                                            type="text"
                                            required
                                            value={formData.name}
                                            onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2 border"
                                        />
                                    </div>
                                    <div>
                                        <label className="block text-sm font-medium text-gray-700">邮箱 (登录账号)</label>
                                        <input
                                            type="email"
                                            required
                                            value={formData.email}
                                            onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2 border"
                                        />
                                    </div>
                                    <div>
                                        <label className="block text-sm font-medium text-gray-700">密码 {editingUser && '(留空表示不修改)'}</label>
                                        <input
                                            type="password"
                                            required={!editingUser}
                                            minLength={6}
                                            value={formData.password}
                                            onChange={(e) => setFormData({ ...formData, password: e.target.value })}
                                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2 border"
                                        />
                                    </div>

                                    {/* Role Specific Fields */}
                                    {currentTabRole === 'manager' && (
                                        <div>
                                            <label className="block text-sm font-medium text-gray-700">管理系部</label>
                                            <select
                                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm p-2 border"
                                                value={formData.department_id}
                                                onChange={(e) => setFormData({ ...formData, department_id: e.target.value })}
                                            >
                                                <option value="">-- 选择系部 --</option>
                                                {departments.map(d => (
                                                    <option key={d.id} value={d.id}>{d.name}</option>
                                                ))}
                                            </select>
                                            <p className="mt-1 text-xs text-gray-500">注意：选择后该系部的管理员将变更为此用户。</p>
                                        </div>
                                    )}

                                    {currentTabRole === 'teacher' && (
                                        <div>
                                            <label className="block text-sm font-medium text-gray-700">分配班级 (Head Teacher)</label>
                                            <select
                                                multiple
                                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm p-2 border h-32"
                                                value={formData.class_ids}
                                                onChange={(e) => {
                                                    const selected = Array.from(e.target.selectedOptions, option => parseInt(option.value));
                                                    setFormData({ ...formData, class_ids: selected });
                                                }}
                                            >
                                                {classes.map(c => (
                                                    <option key={c.id} value={c.id}>
                                                        {c.grade_name ? `${c.grade_name} - ` : ''}{c.name}
                                                    </option>
                                                ))}
                                            </select>
                                            <p className="mt-1 text-xs text-gray-500">按住 Ctrl/Cmd 可多选。被选中的班级将指派此教师为班主任。</p>
                                        </div>
                                    )}
                                </div>
                                <div className="mt-5 sm:mt-6 sm:grid sm:grid-flow-row-dense sm:grid-cols-2 sm:gap-3">
                                    <button
                                        type="submit"
                                        className="inline-flex w-full justify-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 sm:col-start-2"
                                    >
                                        保存
                                    </button>
                                    <button
                                        type="button"
                                        className="mt-3 inline-flex w-full justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 sm:col-start-1 sm:mt-0"
                                        onClick={() => setShowForm(false)}
                                    >
                                        取消
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            )}
        </Layout>
    );
}
