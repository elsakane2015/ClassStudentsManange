import React, { useEffect, useState } from 'react';
import Layout from '../../components/Layout';
import axios from 'axios';
import { Link } from 'react-router-dom';
import { PlusIcon, ArrowUpTrayIcon, TrashIcon, ChevronLeftIcon, ChevronRightIcon } from '@heroicons/react/24/outline';
import useAuthStore from '../../store/authStore';

export default function StudentList() {
    const { user } = useAuthStore();
    const [students, setStudents] = useState([]);
    const [loading, setLoading] = useState(true);
    const [classes, setClasses] = useState([]);
    const [departments, setDepartments] = useState([]);
    const [enrollmentYears, setEnrollmentYears] = useState([]);

    // Filters
    const [filterDepartment, setFilterDepartment] = useState('');
    const [filterYear, setFilterYear] = useState('');
    const [filterClass, setFilterClass] = useState('');

    // Pagination
    const [meta, setMeta] = useState({ current_page: 1, last_page: 1, total: 0 });

    // Selection
    const [selectedIds, setSelectedIds] = useState([]);

    // Form State
    const [showForm, setShowForm] = useState(false);
    const [editingStudent, setEditingStudent] = useState(null);
    const [formData, setFormData] = useState({
        name: '', student_no: '', gender: 'male', parent_contact: '',
        class_id: '', email: '', password: ''
    });

    // Check if user is teacher (limited view) or admin (full filters)
    const isTeacher = user?.role === 'teacher';
    const isAdmin = ['system_admin', 'school_admin', 'admin'].includes(user?.role);
    const isDepartmentManager = ['department_manager', 'manager'].includes(user?.role);

    // For admin/manager, require class selection before showing students
    const needsFilter = isAdmin || isDepartmentManager;
    const hasClassSelected = !!filterClass;
    const shouldShowStudents = isTeacher || hasClassSelected;

    const fetchStudents = async (page = 1) => {
        setLoading(true);
        try {
            const params = { page, per_page: 50 };
            if (filterDepartment) params.department_id = filterDepartment;
            if (filterYear) params.enrollment_year = filterYear;
            if (filterClass) params.class_id = filterClass;

            const res = await axios.get('/students', { params });
            setStudents(res.data.data);
            if (res.data.meta) {
                setMeta(res.data.meta);
            }
            setSelectedIds([]);
        } catch (error) {
            console.error(error);
        } finally {
            setLoading(false);
        }
    };

    const fetchFilters = async () => {
        try {
            const [deptRes, classRes] = await Promise.all([
                axios.get('/options/departments'),
                axios.get('/options/classes'),
            ]);
            const depts = deptRes.data || [];
            setDepartments(depts);
            setClasses(classRes.data || []);

            // For department manager, auto-select their first department (locked)
            if (isDepartmentManager && depts.length > 0 && !filterDepartment) {
                setFilterDepartment(String(depts[0].id));
            }

            // Extract unique enrollment years from classes
            const years = [...new Set(classRes.data?.map(c => c.enrollment_year).filter(Boolean))];
            setEnrollmentYears(years.sort().reverse());
        } catch (error) {
            console.error(error);
        }
    };

    useEffect(() => {
        fetchFilters();
    }, []);

    useEffect(() => {
        if (isTeacher || hasClassSelected) {
            fetchStudents(1);
        } else {
            setStudents([]);
            setMeta({ current_page: 1, last_page: 1, total: 0 });
            setLoading(false);
        }
    }, [filterDepartment, filterYear, filterClass]);

    const handleDelete = async (id) => {
        if (!confirm('确定删除该学生吗？')) return;
        try {
            await axios.delete(`/students/${id}`);
            fetchStudents(meta.current_page);
        } catch (error) {
            alert('删除失败');
        }
    };

    const handleBulkDelete = async () => {
        if (selectedIds.length === 0) return;
        if (!confirm(`确定删除选中的 ${selectedIds.length} 名学生吗？此操作不可恢复！`)) return;

        try {
            const res = await axios.post('/students/bulk-delete', { ids: selectedIds });
            alert(res.data.message);
            setSelectedIds([]);
            fetchStudents(meta.current_page);
        } catch (error) {
            alert('批量删除失败: ' + (error.response?.data?.message || error.message));
        }
    };

    const toggleSelectAll = () => {
        if (selectedIds.length === students.length) {
            setSelectedIds([]);
        } else {
            setSelectedIds(students.map(s => s.id));
        }
    };

    const toggleSelect = (id) => {
        if (selectedIds.includes(id)) {
            setSelectedIds(selectedIds.filter(i => i !== id));
        } else {
            setSelectedIds([...selectedIds, id]);
        }
    };

    const handleEdit = (student) => {
        setEditingStudent(student);
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
    };

    const handleSubmit = async (e) => {
        e.preventDefault();
        try {
            if (editingStudent) {
                await axios.put(`/students/${editingStudent.id}`, formData);
            } else {
                await axios.post('/students', formData);
            }
            setShowForm(false);
            fetchStudents(meta.current_page);
        } catch (error) {
            alert('操作失败: ' + (error.response?.data?.error || error.message));
        }
    };


    const toggleClassAdmin = async (student) => {
        const action = student.is_class_admin ? '取消' : '指定';
        if (!confirm(`确定要${action}"${student.name}"为班级学生管理员吗？`)) return;
        try {
            await axios.post(`/students/${student.id}/toggle-class-admin`);
            fetchStudents(meta.current_page);
        } catch (error) {
            alert('操作失败: ' + (error.response?.data?.message || error.message));
        }
    };

    // Filter classes based on selected department and year
    const filteredClasses = classes.filter(c => {
        if (filterDepartment && c.department_id != filterDepartment) return false;
        if (filterYear && c.enrollment_year != filterYear) return false;
        return true;
    });

    if (loading && students.length === 0 && isTeacher) {
        return <Layout><div className="text-center py-10">Loading...</div></Layout>;
    }

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
                        className="inline-flex items-center justify-center rounded-md border border-transparent bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700"
                    >
                        <PlusIcon className="h-4 w-4 mr-2" />
                        手动添加
                    </button>
                    <Link
                        to="/teacher/import"
                        className="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50"
                    >
                        <ArrowUpTrayIcon className="h-4 w-4 mr-2" />
                        批量导入
                    </Link>
                </div>
            </div>

            {/* Filters - Only show for admin/department manager */}
            {(isAdmin || isDepartmentManager) && (
                <div className="mt-4 flex flex-wrap gap-4 p-4 bg-gray-50 rounded-lg">
                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">系部</label>
                        {/* Department managers with single department see locked label */}
                        {isDepartmentManager && departments.length === 1 ? (
                            <div className="rounded-md border-gray-300 shadow-sm text-sm p-2 border min-w-[150px] bg-gray-100 text-gray-700">
                                {departments[0].name}
                            </div>
                        ) : (
                            <select
                                value={filterDepartment}
                                onChange={(e) => { setFilterDepartment(e.target.value); setFilterClass(''); }}
                                className="rounded-md border-gray-300 shadow-sm text-sm p-2 border min-w-[150px]"
                            >
                                {isAdmin && <option value="">全部系部</option>}
                                {departments.map(d => (
                                    <option key={d.id} value={d.id}>{d.name}</option>
                                ))}
                            </select>
                        )}
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">入学年份</label>
                        <select
                            value={filterYear}
                            onChange={(e) => { setFilterYear(e.target.value); setFilterClass(''); }}
                            className="rounded-md border-gray-300 shadow-sm text-sm p-2 border min-w-[120px]"
                        >
                            <option value="">全部年份</option>
                            {enrollmentYears.map(y => (
                                <option key={y} value={y}>{y}级</option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">班级</label>
                        <select
                            value={filterClass}
                            onChange={(e) => setFilterClass(e.target.value)}
                            className="rounded-md border-gray-300 shadow-sm text-sm p-2 border min-w-[150px]"
                        >
                            <option value="">全部班级</option>
                            {filteredClasses.map(c => (
                                <option key={c.id} value={c.id}>{c.name}</option>
                            ))}
                        </select>
                    </div>
                    {hasClassSelected && (
                        <div className="flex items-end">
                            <span className="text-sm text-gray-500">
                                共 {meta.total} 名学生
                            </span>
                        </div>
                    )}
                </div>
            )}

            {/* Bulk Actions */}
            {selectedIds.length > 0 && (
                <div className="mt-4 p-3 bg-indigo-50 rounded-lg flex items-center justify-between">
                    <span className="text-sm text-indigo-700">
                        已选择 <strong>{selectedIds.length}</strong> 名学生
                    </span>
                    <button
                        onClick={handleBulkDelete}
                        className="inline-flex items-center px-3 py-1.5 rounded bg-red-600 text-white text-sm font-medium hover:bg-red-700"
                    >
                        <TrashIcon className="h-4 w-4 mr-1" />
                        批量删除
                    </button>
                </div>
            )}

            {/* Prompt for admins to select class */}
            {needsFilter && !hasClassSelected && (
                <div className="mt-8 text-center py-12 bg-gray-50 rounded-lg border-2 border-dashed border-gray-300">
                    <svg className="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
                    </svg>
                    <h3 className="mt-2 text-sm font-medium text-gray-900">请先选择班级</h3>
                    <p className="mt-1 text-sm text-gray-500">
                        请依次选择系部 → 入学年份 → 班级来查看学生列表
                    </p>
                </div>
            )}

            {/* Table - only show when shouldShowStudents */}
            {shouldShowStudents && (
                <div className="mt-4 flex flex-col">
                    <div className="-my-2 -mx-4 overflow-x-auto sm:-mx-6 lg:-mx-8">
                        <div className="inline-block min-w-full py-2 align-middle md:px-6 lg:px-8">
                            <div className="overflow-hidden shadow ring-1 ring-black ring-opacity-5 md:rounded-lg">
                                <table className="min-w-full divide-y divide-gray-300">
                                    <thead className="bg-gray-50">
                                        <tr>
                                            <th scope="col" className="py-3.5 pl-4 pr-2 w-10">
                                                <input
                                                    type="checkbox"
                                                    checked={selectedIds.length === students.length && students.length > 0}
                                                    onChange={toggleSelectAll}
                                                    className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                                />
                                            </th>
                                            <th scope="col" className="py-3.5 pl-2 pr-3 text-left text-sm font-semibold text-gray-900">姓名</th>
                                            <th scope="col" className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">学号</th>
                                            <th scope="col" className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">性别</th>
                                            <th scope="col" className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">系部</th>
                                            <th scope="col" className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">入学年份</th>
                                            <th scope="col" className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">班级</th>
                                            <th scope="col" className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">账号(Email)</th>
                                            <th scope="col" className="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">家长联系方式</th>
                                            <th scope="col" className="relative py-3.5 pl-3 pr-4 sm:pr-6"><span className="sr-only">操作</span></th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-200 bg-white">
                                        {students.map((student) => (
                                            <tr key={student.id} className={selectedIds.includes(student.id) ? 'bg-indigo-50' : ''}>
                                                <td className="py-4 pl-4 pr-2">
                                                    <input
                                                        type="checkbox"
                                                        checked={selectedIds.includes(student.id)}
                                                        onChange={() => toggleSelect(student.id)}
                                                        className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                                    />
                                                </td>
                                                <td className="whitespace-nowrap py-4 pl-2 pr-3 text-sm font-medium text-gray-900">{student.name}</td>
                                                <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-500">{student.student_no}</td>
                                                <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-500">{student.gender === 'male' ? '男' : '女'}</td>
                                                <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-500">{student.department_name}</td>
                                                <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-500">{student.enrollment_year || '-'}</td>
                                                <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-500">{student.class_name}</td>
                                                <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-500">{student.email || '-'}</td>
                                                <td className="whitespace-nowrap px-3 py-4 text-sm text-gray-500">{student.parent_contact}</td>
                                                <td className="relative whitespace-nowrap py-4 pl-3 pr-4 text-right text-sm font-medium sm:pr-6 space-x-2">
                                                    <button onClick={() => handleEdit(student)} className="text-indigo-600 hover:text-indigo-900">编辑</button>
                                                    <button
                                                        onClick={() => toggleClassAdmin(student)}
                                                        className={student.is_class_admin ? "text-orange-600 hover:text-orange-900" : "text-blue-600 hover:text-blue-900"}
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
            )}

            {/* Pagination */}
            {shouldShowStudents && meta.last_page > 1 && (
                <div className="mt-4 flex items-center justify-between">
                    <div className="text-sm text-gray-700">
                        第 {meta.current_page} / {meta.last_page} 页，共 {meta.total} 条
                    </div>
                    <div className="flex gap-2">
                        <button
                            onClick={() => fetchStudents(meta.current_page - 1)}
                            disabled={meta.current_page === 1}
                            className="inline-flex items-center px-3 py-1.5 rounded border border-gray-300 bg-white text-sm disabled:opacity-50 disabled:cursor-not-allowed hover:bg-gray-50"
                        >
                            <ChevronLeftIcon className="h-4 w-4 mr-1" />
                            上一页
                        </button>
                        <button
                            onClick={() => fetchStudents(meta.current_page + 1)}
                            disabled={meta.current_page === meta.last_page}
                            className="inline-flex items-center px-3 py-1.5 rounded border border-gray-300 bg-white text-sm disabled:opacity-50 disabled:cursor-not-allowed hover:bg-gray-50"
                        >
                            下一页
                            <ChevronRightIcon className="h-4 w-4 ml-1" />
                        </button>
                    </div>
                </div>
            )}

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
                                    <input type="email" required value={formData.email} onChange={e => setFormData({ ...formData, email: e.target.value })} className="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm p-2 border" />
                                </div>
                                {editingStudent && (
                                    <div>
                                        <label className="block text-sm font-medium text-gray-700">重置密码</label>
                                        <input type="text" placeholder="留空则不修改密码" value={formData.password} onChange={e => setFormData({ ...formData, password: e.target.value })} className="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm p-2 border" />
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
