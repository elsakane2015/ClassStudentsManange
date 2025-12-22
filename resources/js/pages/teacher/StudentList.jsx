import React, { useEffect, useState } from 'react';
import Layout from '../../components/Layout';
import axios from 'axios';
import { Link } from 'react-router-dom';
import { PlusIcon, ArrowUpTrayIcon, PencilSquareIcon, TrashIcon } from '@heroicons/react/24/outline';
import useAuthStore from '../../store/authStore';

export default function StudentList() {
    const { user } = useAuthStore();
    const [students, setStudents] = useState([]);
    const [loading, setLoading] = useState(true);
    const [classes, setClasses] = useState([]); // For dropdown in form

    // Form State
    const [showForm, setShowForm] = useState(false);
    const [editingStudent, setEditingStudent] = useState(null);
    const [formData, setFormData] = useState({
        name: '', student_no: '', gender: 'male', parent_contact: '',
        class_id: '', email: '', password: ''
    });

    const fetchStudents = async () => {
        try {
            const res = await axios.get('/students');
            setStudents(res.data.data);
            setLoading(false);
        } catch (error) {
            console.error(error);
            setLoading(false);
        }
    };

    const fetchClasses = async () => {
        try {
            const res = await axios.get('/options/classes'); // Uses existing options API
            setClasses(res.data);
        } catch (error) { console.error(error); }
    };

    useEffect(() => {
        fetchStudents();
        fetchClasses();
    }, []);

    const handleDelete = async (id) => {
        if (!confirm('确定删除该学生吗？')) return;
        try {
            await axios.delete(`/students/${id}`);
            fetchStudents();
        } catch (error) {
            alert('删除失败');
        }
    };

    const handleEdit = (student) => {
        setEditingStudent(student);
        // Map student data to form. Note: Student object structure from API might differ slightly
        // API returns: { id, name, student_no, gender, parent_contact, class_name }
        // We might not have class_id or email directly in the list response to refile.
        // For simplicity, we might need to fetch single student or pass enough data.
        // Let's assume for now we can't edit email/password easily here, or we need to look it up.
        // Actually the list API provided: id, name, student_no, gender, parent_contact.
        // If we want to edit, we can set known fields. 
        setFormData({
            name: student.name,
            student_no: student.student_no,
            gender: student.gender,
            parent_contact: student.parent_contact,
            class_id: student.class_id || '',
            email: student.email || '',
            password: ''
        });
        setShowForm(true);
    };

    const openCreate = () => {
        setEditingStudent(null);
        setFormData({ name: '', student_no: '', gender: 'male', parent_contact: '', class_id: classes.length === 1 ? classes[0].id : '', email: '', password: 'password123' });
        setShowForm(true);
    }

    const handleSubmit = async (e) => {
        e.preventDefault();
        try {
            if (editingStudent) {
                // Update
                await axios.put(`/students/${editingStudent.id}`, formData);
            } else {
                // Create
                await axios.post('/students', formData);
            }
            setShowForm(false);
            fetchStudents();
        } catch (error) {
            alert('操作失败: ' + (error.response?.data?.error || error.message));
        }
    };

    const toggleManager = async (student) => {
        const action = student.is_manager ? '取消' : '指定';
        if (!confirm(`确定要${action}"${student.name}"为班级管理员吗？`)) return;

        try {
            await axios.post(`/students/${student.id}/toggle-manager`);
            fetchStudents();
        } catch (error) {
            alert('操作失败: ' + (error.response?.data?.message || error.message));
        }
    };

    const toggleClassAdmin = async (student) => {
        const action = student.is_class_admin ? '取消' : '指定';
        if (!confirm(`确定要${action}"${student.name}"为班级学生管理员吗？`)) return;

        try {
            await axios.post(`/students/${student.id}/toggle-class-admin`);
            fetchStudents();
        } catch (error) {
            alert('操作失败: ' + (error.response?.data?.message || error.message));
        }
    };

    if (loading) return <Layout><div>Loading...</div></Layout>;

    return (
        <Layout>
            <div className="sm:flex sm:items-center">
                <div className="sm:flex-auto">
                    <h1 className="text-xl font-semibold text-gray-900">学生管理</h1>
                    <p className="mt-2 text-sm text-gray-700">查看及管理您班级的学生信息。</p>
                </div>
                <div className="mt-4 sm:mt-0 sm:ml-16 sm:flex-none space-x-3">
                    <button
                        onClick={openCreate}
                        className="inline-flex items-center justify-center rounded-md border border-transparent bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 focus:outline-none sm:w-auto"
                    >
                        <PlusIcon className="h-4 w-4 mr-2" />
                        手动添加
                    </button>
                    <Link
                        to="/teacher/import"
                        className="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none sm:w-auto"
                    >
                        <ArrowUpTrayIcon className="h-4 w-4 mr-2" />
                        批量导入
                    </Link>
                </div>
            </div>

            {/* Table */}
            <div className="mt-8 flex flex-col">
                <div className="-my-2 -mx-4 overflow-x-auto sm:-mx-6 lg:-mx-8">
                    <div className="inline-block min-w-full py-2 align-middle md:px-6 lg:px-8">
                        <div className="overflow-hidden shadow ring-1 ring-black ring-opacity-5 md:rounded-lg">
                            <table className="min-w-full divide-y divide-gray-300">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th scope="col" className="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900 sm:pl-6">姓名</th>
                                        <th scope="col" className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">学号</th>
                                        <th scope="col" className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">性别</th>
                                        <th scope="col" className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">班级</th>
                                        <th scope="col" className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">账号(Email)</th>
                                        <th scope="col" className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">家长联系方式</th>
                                        <th scope="col" className="relative py-3.5 pl-3 pr-4 sm:pr-6"><span className="sr-only">操作</span></th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-200 bg-white">
                                    {students.map((student) => (
                                        <tr key={student.id}>
                                            <td className="whitespace-nowrap py-4 pl-4 pr-3 text-sm font-medium text-gray-900 sm:pl-6">{student.name}</td>
                                            <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-500">{student.student_no}</td>
                                            <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-500">{student.gender === 'male' ? '男' : '女'}</td>
                                            <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-500">{student.class_name}</td>
                                            <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-500">{student.email || '-'}</td>
                                            <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-500">{student.parent_contact}</td>
                                            <td className="relative whitespace-nowrap py-4 pl-3 pr-4 text-right text-sm font-medium sm:pr-6 space-x-2">
                                                <button onClick={() => handleEdit(student)} className="text-indigo-600 hover:text-indigo-900">编辑</button>
                                                <button
                                                    onClick={() => toggleManager(student)}
                                                    className={student.is_manager ? "text-orange-600 hover:text-orange-900" : "text-green-600 hover:text-green-900"}
                                                    title={student.is_manager ? "取消管理员" : "指定为管理员"}
                                                >
                                                    {student.is_manager ? '取消管理员' : '指定管理员'}
                                                </button>
                                                <button
                                                    onClick={() => toggleClassAdmin(student)}
                                                    className={student.is_class_admin ? "text-purple-600 hover:text-purple-900" : "text-blue-600 hover:text-blue-900"}
                                                    title={student.is_class_admin ? "取消班级管理员" : "指定为班级管理员"}
                                                >
                                                    {student.is_class_admin ? '取消班级管理员' : '指定班级管理员'}
                                                </button>
                                                <button onClick={() => handleDelete(student.id)} className="text-red-600 hover:text-red-900">删除</button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            {/* Modal */}
            {showForm && (
                <div className="fixed inset-0 z-10 overflow-y-auto">
                    <div className="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div className="fixed inset-0 bg-gray-500 bg-opacity-75" onClick={() => setShowForm(false)}></div>
                        <div className="relative transform overflow-hidden rounded-lg bg-white px-4 pb-4 pt-5 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6">
                            <h3 className="text-lg font-medium leading-6 text-gray-900 mb-4">{editingStudent ? '编辑学生' : '添加学生'}</h3>
                            <form onSubmit={handleSubmit} className="space-y-4">
                                <div>
                                    <label className="block text-sm font-medium text-gray-700">姓名</label>
                                    <input type="text" required value={formData.name} onChange={e => setFormData({ ...formData, name: e.target.value })} className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm p-2 border" />
                                </div>
                                <div className="grid grid-cols-2 gap-4">
                                    <div>
                                        <label className="block text-sm font-medium text-gray-700">学号</label>
                                        <input type="text" required value={formData.student_no} onChange={e => setFormData({ ...formData, student_no: e.target.value })} className="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm p-2 border" />
                                    </div>
                                    <div>
                                        <label className="block text-sm font-medium text-gray-700">性别</label>
                                        <select value={formData.gender} onChange={e => setFormData({ ...formData, gender: e.target.value })} className="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm p-2 border">
                                            <option value="male">男</option>
                                            <option value="female">女</option>
                                        </select>
                                    </div>
                                </div>
                                <div>
                                    <label className="block text-sm font-medium text-gray-700">家长联系方式</label>
                                    <input type="text" value={formData.parent_contact} onChange={e => setFormData({ ...formData, parent_contact: e.target.value })} className="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm p-2 border" />
                                </div>

                                <div>
                                    <label className="block text-sm font-medium text-gray-700">账号(Email)</label>
                                    <input
                                        type="email"
                                        required
                                        value={formData.email}
                                        onChange={e => setFormData({ ...formData, email: e.target.value })}
                                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm p-2 border"
                                    />
                                </div>

                                {editingStudent && (
                                    <div>
                                        <label className="block text-sm font-medium text-gray-700">重置密码</label>
                                        <input
                                            type="text"
                                            placeholder="留空则不修改密码"
                                            value={formData.password}
                                            onChange={e => setFormData({ ...formData, password: e.target.value })}
                                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm p-2 border"
                                        />
                                        <p className="mt-1 text-xs text-gray-500">如需重置密码，请输入新密码</p>
                                    </div>
                                )}

                                {!editingStudent && (
                                    <>
                                        <div>
                                            <label className="block text-sm font-medium text-gray-700">班级</label>
                                            <select required value={formData.class_id} onChange={e => setFormData({ ...formData, class_id: e.target.value })} className="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm p-2 border">
                                                <option value="">请选择班级</option>
                                                {classes.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
                                            </select>
                                        </div>
                                        <div>
                                            <label className="block text-sm font-medium text-gray-700">登录邮箱</label>
                                            <input type="email" required value={formData.email} onChange={e => setFormData({ ...formData, email: e.target.value })} className="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm p-2 border" />
                                        </div>
                                        <div>
                                            <label className="block text-sm font-medium text-gray-700">初始密码</label>
                                            <input type="text" required value={formData.password} onChange={e => setFormData({ ...formData, password: e.target.value })} className="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm p-2 border" />
                                        </div>
                                    </>
                                )}

                                <div className="mt-5 sm:mt-6 sm:grid sm:grid-flow-row-dense sm:grid-cols-2 sm:gap-3">
                                    <button type="submit" className="inline-flex w-full justify-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 sm:col-start-2">保存</button>
                                    <button type="button" onClick={() => setShowForm(false)} className="mt-3 inline-flex w-full justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 sm:col-start-1 sm:mt-0">取消</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            )}

        </Layout>
    );
}
