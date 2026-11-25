import React, { useEffect, useState } from 'react';
import Layout from '../../components/Layout';
import axios from 'axios';
import { Link } from 'react-router-dom';
import { normalizeDateKey } from '../../utils/dateFormat';
import {
    ArrowUpTrayIcon,
    CheckIcon,
    ChevronLeftIcon,
    ChevronRightIcon,
    PencilSquareIcon,
    PlusIcon,
    TrashIcon,
    XMarkIcon,
} from '@heroicons/react/24/outline';
import useAuthStore from '../../store/authStore';

const createDraft = (student) => ({
    id: student.id,
    gender: student.gender || 'male',
    is_boarding: Boolean(student.is_boarding),
    email: student.email || '',
    parent_contact: student.parent_contact || '',
    parent_email: student.parent_email || '',
});

const parseParentEmails = (raw) => {
    if (!raw || typeof raw !== 'string') return [];
    return raw
        .split(/[,，;；\s]+/)
        .map((item) => item.trim())
        .filter(Boolean);
};

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
    const [batchEditing, setBatchEditing] = useState(false);
    const [drafts, setDrafts] = useState({});
    const [savingBatch, setSavingBatch] = useState(false);
    const [notice, setNotice] = useState(null);

    // Form State
    const [showForm, setShowForm] = useState(false);
    const [editingStudent, setEditingStudent] = useState(null);
    const [parentAccounts, setParentAccounts] = useState([]);
    const [loadingParentAccounts, setLoadingParentAccounts] = useState(false);
    const [parentAccountsError, setParentAccountsError] = useState('');
    const [resettingParentId, setResettingParentId] = useState(null);
    const [suspensionStudent, setSuspensionStudent] = useState(null);
    const [suspensionForm, setSuspensionForm] = useState({ start_date: '', end_date: '', reason: '', destination: '' });
    const [eveningLeaveStudent, setEveningLeaveStudent] = useState(null);
    const [eveningPeriods, setEveningPeriods] = useState([]);
    const [eveningStatuses, setEveningStatuses] = useState([]);
    const [eveningLeaveTypes, setEveningLeaveTypes] = useState([]);
    const [eveningLeaveForm, setEveningLeaveForm] = useState({ date: '', period_id: '', leave_type_id: '', status_id: '', destination: '', reason: '' });
    const [formData, setFormData] = useState({
        name: '', student_no: '', gender: 'male', is_boarding: false, parent_contact: '', parent_email: '',
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
            setBatchEditing(false);
            setDrafts({});
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

    const fetchStudentParents = async (studentId) => {
        setLoadingParentAccounts(true);
        setParentAccountsError('');
        try {
            const res = await axios.get(`/students/${studentId}/parents`);
            const list = Array.isArray(res.data?.data) ? res.data.data : null;
            if (!list) {
                setParentAccounts([]);
                setParentAccountsError('服务端返回格式异常，请确认后端已更新至最新版本。');
            } else {
                setParentAccounts(list);
            }
        } catch (err) {
            setParentAccounts([]);
            setParentAccountsError(err.response?.data?.message || '无法加载父母端账号，请确认后端已部署并完成数据库迁移。');
        } finally {
            setLoadingParentAccounts(false);
        }
    };

    const handleResetParentPassword = async (parentAccount) => {
        if (!editingStudent) return;
        const customPwd = window.prompt(
            `重置家长账号「${parentAccount.email}」的密码：\n留空点击确定将重置为系统默认初始密码；或直接输入 6 位以上新密码。`,
            ''
        );
        if (customPwd === null) return;
        if (customPwd.trim() !== '' && customPwd.trim().length < 6) {
            alert('新密码至少需要 6 位');
            return;
        }
        setResettingParentId(parentAccount.id);
        try {
            const res = await axios.post(
                `/students/${editingStudent.id}/parents/${parentAccount.id}/reset-password`,
                customPwd.trim() ? { password: customPwd.trim() } : {}
            );
            alert(res.data?.message || '家长密码已重置');
        } catch (err) {
            alert(err.response?.data?.message || err.response?.data?.error || '重置失败');
        } finally {
            setResettingParentId(null);
        }
    };

    const handleEdit = (student) => {
        setEditingStudent(student);
        setParentAccounts([]);
        setFormData({
            name: student.name || '',
            student_no: student.student_no || '',
            gender: student.gender || 'male',
            is_boarding: Boolean(student.is_boarding),
            parent_contact: student.parent_contact || '',
            parent_email: student.parent_email || '',
            class_id: student.class_id || '',
            email: student.email || '',
            password: ''
        });
        setShowForm(true);
        fetchStudentParents(student.id);
    };

    const openCreate = () => {
        setEditingStudent(null);
        setParentAccounts([]);
        setFormData({ name: '', student_no: '', gender: 'male', is_boarding: false, parent_contact: '', parent_email: '', class_id: classes.length === 1 ? classes[0].id : '', email: '', password: 'password123' });
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
            const validationErrors = error.response?.data?.errors;
            const firstError = validationErrors ? Object.values(validationErrors).flat()[0] : null;
            alert('操作失败: ' + (error.response?.data?.error || firstError || error.message));
        }
    };

    const openSuspension = (student) => {
        const date = new Date();
        date.setMinutes(date.getMinutes() - date.getTimezoneOffset());
        const currentDate = date.toISOString().slice(0, 10);
        setSuspensionStudent(student);
        setSuspensionForm({ start_date: currentDate, end_date: currentDate, reason: '', destination: '' });
    };

    const saveSuspension = async (event) => {
        event.preventDefault();
        try {
            await axios.post('/boarding-suspensions', { student_id: suspensionStudent.id, ...suspensionForm });
            setSuspensionStudent(null);
            fetchStudents(meta.current_page);
        } catch (error) {
            alert(error.response?.data?.message || Object.values(error.response?.data?.errors || {}).flat()[0] || '暂停住宿失败');
        }
    };

    const revokeSuspension = async () => {
        const reason = prompt('请输入撤销原因');
        if (!reason) return;
        try {
            await axios.post(`/boarding-suspensions/${suspensionStudent.active_boarding_suspension.id}/revoke`, { reason });
            setSuspensionStudent(null);
            fetchStudents(meta.current_page);
        } catch (error) {
            alert(error.response?.data?.message || '撤销失败');
        }
    };

    const openEveningLeave = async (student) => {
        const date = new Date();
        date.setMinutes(date.getMinutes() - date.getTimezoneOffset());
        const currentDate = date.toISOString().slice(0, 10);
        try {
            const [periodRes, statusRes, leaveTypeRes] = await Promise.all([
                axios.get('/evening-study/periods'),
                axios.get('/evening-study-statuses'),
                axios.get('/leave-types'),
            ]);
            const availableStatuses = (statusRes.data.statuses || []).filter(status => status.student_requestable);
            const availableLeaveTypes = (leaveTypeRes.data || []).filter(type => type.is_active && type.student_requestable);
            setEveningPeriods(periodRes.data || []);
            setEveningStatuses(availableStatuses);
            setEveningLeaveTypes(availableLeaveTypes);
            setEveningLeaveForm({
                date: currentDate,
                period_id: String(periodRes.data?.[0]?.id || ''),
                leave_type_id: String(availableLeaveTypes[0]?.id || ''),
                status_id: String(availableStatuses[0]?.id || ''),
                destination: '',
                reason: '',
            });
            setEveningLeaveStudent(student);
        } catch (error) {
            alert(error.response?.data?.message || '夜自习配置加载失败');
        }
    };

    const saveEveningLeave = async (event) => {
        event.preventDefault();
        try {
            await axios.post('/evening-study/teacher-leave', {
                student_id: eveningLeaveStudent.id,
                ...eveningLeaveForm,
                period_id: Number(eveningLeaveForm.period_id),
                leave_type_id: Number(eveningLeaveForm.leave_type_id),
                status_id: Number(eveningLeaveForm.status_id),
            });
            setEveningLeaveStudent(null);
            setNotice({ type: 'success', text: '夜自习请假状态已标记' });
        } catch (error) {
            alert(error.response?.data?.message || Object.values(error.response?.data?.errors || {}).flat()[0] || '标记失败');
        }
    };

    const startBatchEditing = () => {
        setDrafts(Object.fromEntries(students.map(student => [student.id, createDraft(student)])));
        setSelectedIds([]);
        setNotice(null);
        setBatchEditing(true);
    };

    const cancelBatchEditing = () => {
        setDrafts({});
        setBatchEditing(false);
        setNotice(null);
    };

    const updateDraft = (studentId, field, value) => {
        setDrafts(prev => ({
            ...prev,
            [studentId]: { ...prev[studentId], [field]: value },
        }));
    };

    const changedDrafts = students
        .filter(student => {
            const draft = drafts[student.id];
            if (!draft) return false;
            const original = createDraft(student);
            return Object.keys(original).some(key => original[key] !== draft[key]);
        })
        .map(student => drafts[student.id]);

    const saveBatchChanges = async () => {
        if (changedDrafts.length === 0) return;

        setSavingBatch(true);
        setNotice(null);
        try {
            const response = await axios.post('/students/bulk-update', { students: changedDrafts });
            setNotice({ type: 'success', text: response.data.message });
            await fetchStudents(meta.current_page);
        } catch (error) {
            const validationErrors = error.response?.data?.errors;
            const firstError = validationErrors ? Object.values(validationErrors).flat()[0] : null;
            setNotice({ type: 'error', text: error.response?.data?.message || firstError || '保存修改失败' });
        } finally {
            setSavingBatch(false);
        }
    };


    const toggleClassAdmin = async (student) => {
        const action = student.is_class_admin ? '取消' : '指定';
        if (!confirm(`确定要${action}"${student.name}"为班级考勤员吗？`)) return;
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
                <div className="mt-4 flex flex-wrap gap-3 sm:ml-16 sm:mt-0 sm:flex-none sm:justify-end">
                    {batchEditing ? (
                        <>
                            <button type="button" onClick={cancelBatchEditing} disabled={savingBatch} className="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 disabled:opacity-50">
                                <XMarkIcon className="mr-2 h-4 w-4" />
                                取消
                            </button>
                            <button type="button" onClick={saveBatchChanges} disabled={savingBatch || changedDrafts.length === 0} className="inline-flex items-center justify-center rounded-md border border-transparent bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50">
                                <CheckIcon className="mr-2 h-4 w-4" />
                                {savingBatch ? '保存中...' : `保存修改${changedDrafts.length ? ` (${changedDrafts.length})` : ''}`}
                            </button>
                        </>
                    ) : (
                        <>
                            {students.length > 0 && (
                                <button type="button" onClick={startBatchEditing} className="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                                    <PencilSquareIcon className="mr-2 h-4 w-4" />
                                    修改
                                </button>
                            )}
                            <button onClick={openCreate} className="inline-flex items-center justify-center rounded-md border border-transparent bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700">
                                <PlusIcon className="mr-2 h-4 w-4" />
                                手动添加
                            </button>
                            <Link to="/teacher/import" className="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                                <ArrowUpTrayIcon className="mr-2 h-4 w-4" />
                                批量导入
                            </Link>
                        </>
                    )}
                </div>
            </div>

            {notice && (
                <div className={`mt-4 rounded-md px-4 py-3 text-sm ${notice.type === 'success' ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'}`}>
                    {notice.text}
                </div>
            )}

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
                                disabled={batchEditing}
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
                            disabled={batchEditing}
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
                            disabled={batchEditing}
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
                    <div className="overflow-x-auto rounded-lg shadow ring-1 ring-black ring-opacity-5">
                        <table className="w-full min-w-[820px] table-fixed divide-y divide-gray-300">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th scope="col" className="w-10 py-3 pl-3 pr-2 text-left">
                                        <input
                                            type="checkbox"
                                            checked={selectedIds.length === students.length && students.length > 0}
                                            onChange={toggleSelectAll}
                                            disabled={batchEditing}
                                            className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                        />
                                    </th>
                                    <th scope="col" className="w-[18%] py-3 pl-2 pr-3 text-left text-xs font-semibold text-gray-900">学生信息</th>
                                    <th scope="col" className="w-[12%] px-2.5 py-3 text-left text-xs font-semibold text-gray-900">班级 / 系部</th>
                                    <th scope="col" className="w-[17%] px-2.5 py-3 text-left text-xs font-semibold text-gray-900">学生账号</th>
                                    <th scope="col" className="w-[29%] px-2.5 py-3 text-left text-xs font-semibold text-gray-900">家长联系 / 父母端邮箱</th>
                                    <th scope="col" className="w-[20%] py-3 pl-2.5 pr-4 text-right text-xs font-semibold text-gray-900">操作</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200 bg-white">
                                {students.map((student) => {
                                    const draft = drafts[student.id] || createDraft(student);
                                    const parentEmails = parseParentEmails(student.parent_email);
                                    const genderLabel = student.gender === 'male' ? '男' : student.gender === 'female' ? '女' : '其他';
                                    return (
                                    <tr key={student.id} className={selectedIds.includes(student.id) ? 'bg-indigo-50/70' : 'hover:bg-gray-50/60'}>
                                        <td className="py-3 pl-3 pr-2 align-top">
                                            <input
                                                type="checkbox"
                                                checked={selectedIds.includes(student.id)}
                                                onChange={() => toggleSelect(student.id)}
                                                disabled={batchEditing}
                                                className="mt-1 h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                            />
                                        </td>
                                        <td className="py-3 pl-2 pr-3 align-top text-sm">
                                            <div className="flex flex-wrap items-center gap-1.5">
                                                {batchEditing ? (
                                                    <span className="font-medium text-gray-900">{student.name}</span>
                                                ) : (
                                                    <button
                                                        type="button"
                                                        onClick={() => handleEdit(student)}
                                                        title="点击编辑学生与查看父母端账号"
                                                        className="text-left font-medium text-gray-900 hover:text-indigo-600 hover:underline focus:outline-none"
                                                    >
                                                        {student.name}
                                                    </button>
                                                )}
                                                {!batchEditing && (
                                                    <span className="inline-flex items-center rounded bg-gray-100 px-1.5 py-0.5 text-[11px] text-gray-600">
                                                        {genderLabel}
                                                    </span>
                                                )}
                                                {student.is_class_admin && (
                                                    <span className="inline-flex items-center rounded-full bg-blue-50 px-1.5 py-0.5 text-[11px] font-medium text-blue-700">
                                                        考勤员
                                                    </span>
                                                )}
                                            </div>
                                            <div className="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-gray-500">
                                                <span>{student.student_no}</span>
                                                {!batchEditing && (
                                                    student.is_boarding ? (
                                                        student.active_boarding_suspension ? (
                                                            <span className="inline-flex items-center rounded bg-orange-50 px-1.5 py-0.5 text-[11px] font-medium text-orange-700">
                                                                住宿·已暂停
                                                            </span>
                                                        ) : (
                                                            <span className="inline-flex items-center rounded bg-emerald-50 px-1.5 py-0.5 text-[11px] font-medium text-emerald-700">
                                                                住宿
                                                            </span>
                                                        )
                                                    ) : (
                                                        <span className="text-gray-400">走读</span>
                                                    )
                                                )}
                                            </div>
                                            {batchEditing && (
                                                <div className="mt-2 flex flex-wrap gap-1.5">
                                                    <select aria-label={`${student.name}的性别`} value={draft.gender} onChange={event => updateDraft(student.id, 'gender', event.target.value)} className="rounded-md border border-gray-300 bg-white px-1.5 py-1 text-xs">
                                                        <option value="male">男</option>
                                                        <option value="female">女</option>
                                                        <option value="other">其他</option>
                                                    </select>
                                                    <select aria-label={`${student.name}的住宿状态`} value={draft.is_boarding ? '1' : '0'} onChange={event => updateDraft(student.id, 'is_boarding', event.target.value === '1')} className="rounded-md border border-gray-300 bg-white px-1.5 py-1 text-xs">
                                                        <option value="0">走读</option>
                                                        <option value="1">住宿</option>
                                                    </select>
                                                </div>
                                            )}
                                        </td>
                                        <td className="px-2.5 py-3 align-top text-sm">
                                            <div className="font-medium text-gray-800 break-words">{student.class_name || '-'}</div>
                                            <div className="mt-1 text-xs text-gray-500 break-words">
                                                {student.department_name || '-'}
                                                {student.enrollment_year ? ` · ${student.enrollment_year}级` : ''}
                                            </div>
                                        </td>
                                        <td className="px-2.5 py-3 align-top text-xs text-gray-600">
                                            {batchEditing ? (
                                                <input
                                                    aria-label={`${student.name}的账号`}
                                                    type="email"
                                                    value={draft.email}
                                                    onChange={event => updateDraft(student.id, 'email', event.target.value)}
                                                    className="w-full rounded-md border border-gray-300 px-2 py-1.5 text-xs"
                                                />
                                            ) : (
                                                <span className="break-all">{student.email || '-'}</span>
                                            )}
                                        </td>
                                        <td className="px-2.5 py-3 align-top text-xs text-gray-600">
                                            {batchEditing ? (
                                                <div className="space-y-1.5">
                                                    <input
                                                        aria-label={`${student.name}的家长联系方式`}
                                                        type="tel"
                                                        value={draft.parent_contact}
                                                        onChange={event => updateDraft(student.id, 'parent_contact', event.target.value)}
                                                        placeholder="家长电话"
                                                        className="w-full rounded-md border border-gray-300 px-2 py-1 text-xs"
                                                    />
                                                    <input
                                                        aria-label={`${student.name}的家长邮箱`}
                                                        type="text"
                                                        value={draft.parent_email}
                                                        onChange={event => updateDraft(student.id, 'parent_email', event.target.value)}
                                                        placeholder="家长邮箱（逗号分隔）"
                                                        className="w-full rounded-md border border-gray-300 px-2 py-1 text-xs"
                                                    />
                                                </div>
                                            ) : (
                                                <div className="space-y-1">
                                                    {student.parent_contact && (
                                                        <div className="text-gray-700">{student.parent_contact}</div>
                                                    )}
                                                    {parentEmails.length > 0 ? (
                                                        <div className="space-y-0.5">
                                                            {parentEmails.map((email, idx) => (
                                                                <div key={`${student.id}-pe-${idx}`} className="break-all text-gray-500">
                                                                    {email}
                                                                </div>
                                                            ))}
                                                        </div>
                                                    ) : (
                                                        !student.parent_contact && <span className="text-gray-400">-</span>
                                                    )}
                                                </div>
                                            )}
                                        </td>
                                        <td className="py-3 pl-2.5 pr-4 align-top text-right text-xs font-medium">
                                            {!batchEditing && (
                                                <div className="flex flex-wrap justify-end gap-x-2.5 gap-y-1.5">
                                                    <button onClick={() => handleEdit(student)} className="text-indigo-600 hover:text-indigo-900">编辑 / 父母端</button>
                                                    {student.is_boarding && (
                                                        <button onClick={() => openSuspension(student)} className="text-orange-600 hover:text-orange-900">
                                                            {student.active_boarding_suspension ? '查看暂停' : '暂停住宿'}
                                                        </button>
                                                    )}
                                                    {student.is_boarding && !student.active_boarding_suspension && (
                                                        <button onClick={() => openEveningLeave(student)} className="text-cyan-700 hover:text-cyan-900">夜自习请假</button>
                                                    )}
                                                    <button onClick={() => toggleClassAdmin(student)} className={student.is_class_admin ? 'text-orange-600 hover:text-orange-900' : 'text-blue-600 hover:text-blue-900'}>
                                                        {student.is_class_admin ? '取消考勤员' : '设为考勤员'}
                                                    </button>
                                                    <button onClick={() => handleDelete(student.id)} className="text-red-600 hover:text-red-900">删除</button>
                                                </div>
                                            )}
                                        </td>
                                    </tr>
                                    );
                                })}
                            </tbody>
                        </table>
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
                            disabled={meta.current_page === 1 || batchEditing}
                            className="inline-flex items-center px-3 py-1.5 rounded border border-gray-300 bg-white text-sm disabled:opacity-50 disabled:cursor-not-allowed hover:bg-gray-50"
                        >
                            <ChevronLeftIcon className="h-4 w-4 mr-1" />
                            上一页
                        </button>
                        <button
                            onClick={() => fetchStudents(meta.current_page + 1)}
                            disabled={meta.current_page === meta.last_page || batchEditing}
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
                                            <option value="other">其他</option>
                                        </select>
                                    </div>
                                </div>
                                <div>
                                    <label className="block text-sm font-medium text-gray-700">住宿状态</label>
                                    <select value={formData.is_boarding ? '1' : '0'} onChange={e => setFormData({ ...formData, is_boarding: e.target.value === '1' })} className="mt-1 block w-full rounded-md border border-gray-300 p-2 shadow-sm sm:text-sm">
                                        <option value="0">走读生</option>
                                        <option value="1">住宿生</option>
                                    </select>
                                </div>
                                <div>
                                    <label className="block text-sm font-medium text-gray-700">家长联系方式</label>
                                    <input type="text" value={formData.parent_contact} onChange={e => setFormData({ ...formData, parent_contact: e.target.value })} className="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm p-2 border" />
                                </div>
                                <div>
                                    <label className="block text-sm font-medium text-gray-700">家长邮箱（兼父母端登录用户名）</label>
                                    <input type="text" value={formData.parent_email} onChange={e => setFormData({ ...formData, parent_email: e.target.value })} placeholder="parent@example.com, guardian@example.com" className="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm p-2 border" />
                                    <p className="mt-1 text-xs text-gray-500">最多 10 个邮箱，用逗号分隔；保存后自动开通对应父母端账号</p>
                                </div>
                                {editingStudent && (
                                    <div className="rounded-lg border border-gray-200 bg-gray-50 p-3">
                                        <div className="flex items-center justify-between mb-2">
                                            <span className="text-xs font-semibold text-gray-700">已关联父母端账号</span>
                                            {loadingParentAccounts && <span className="text-xs text-gray-400">加载中...</span>}
                                        </div>
                                        {!loadingParentAccounts && parentAccountsError ? (
                                            <div className="flex items-center justify-between gap-2 text-xs text-red-600">
                                                <span>{parentAccountsError}</span>
                                                <button
                                                    type="button"
                                                    onClick={() => fetchStudentParents(editingStudent.id)}
                                                    className="flex-shrink-0 text-indigo-600 hover:underline"
                                                >
                                                    重试
                                                </button>
                                            </div>
                                        ) : !loadingParentAccounts && parentAccounts.length === 0 ? (
                                            <p className="text-xs text-gray-400">
                                                {parseParentEmails(formData.parent_email).length > 0
                                                    ? '当前邮箱尚未生成关联记录，请点击下方「保存」立即同步开通父母端账号。'
                                                    : '暂未关联家长账号，填写上方家长邮箱并保存后自动生成。'}
                                            </p>
                                        ) : (
                                            <ul className="space-y-2">
                                                {parentAccounts.map(pa => (
                                                    <li key={pa.id} className="flex items-center justify-between gap-2 text-xs bg-white px-2.5 py-2 rounded border border-gray-200">
                                                        <div className="min-w-0">
                                                            <div className="font-medium text-gray-800 truncate">{pa.email}</div>
                                                            <div className="text-[11px] text-gray-500 mt-0.5 flex flex-wrap gap-1.5">
                                                                <span>{pa.is_staff_account ? '教职工复用账号' : '独立家长账号'}</span>
                                                                <span>·</span>
                                                                <span className={pa.push_ready ? 'text-green-600' : 'text-gray-400'}>
                                                                    {pa.push_ready ? `推送已就绪 (${pa.device_count}台设备)` : '暂未绑定推送设备'}
                                                                </span>
                                                            </div>
                                                        </div>
                                                        {pa.can_reset_password ? (
                                                            <button
                                                                type="button"
                                                                disabled={resettingParentId === pa.id}
                                                                onClick={() => handleResetParentPassword(pa)}
                                                                className="flex-shrink-0 rounded bg-indigo-50 px-2 py-1 text-xs font-medium text-indigo-700 hover:bg-indigo-100 disabled:opacity-50"
                                                            >
                                                                {resettingParentId === pa.id ? '重置中...' : '重置密码'}
                                                            </button>
                                                        ) : (
                                                            <span className="flex-shrink-0 text-[11px] text-gray-400">用原教职工密码</span>
                                                        )}
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                    </div>
                                )}
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

            {suspensionStudent && (
                <div className="fixed inset-0 z-20 overflow-y-auto">
                    <div className="flex min-h-full items-end justify-center p-4 sm:items-center">
                        <div className="fixed inset-0 bg-gray-500/75" onClick={() => setSuspensionStudent(null)} />
                        <div className="relative w-full max-w-lg rounded-lg bg-white p-6 shadow-xl">
                            <h3 className="text-lg font-semibold text-gray-900">{suspensionStudent.name} · 暂停住宿许可</h3>
                            {suspensionStudent.active_boarding_suspension ? (
                                <div className="mt-4 space-y-3 text-sm text-gray-700">
                                    <p><span className="text-gray-500">有效期：</span>{normalizeDateKey(suspensionStudent.active_boarding_suspension.start_date)} 至 {normalizeDateKey(suspensionStudent.active_boarding_suspension.end_date)}</p>
                                    <p><span className="text-gray-500">原因：</span>{suspensionStudent.active_boarding_suspension.reason}</p>
                                    <p><span className="text-gray-500">去向：</span>{suspensionStudent.active_boarding_suspension.destination || '-'}</p>
                                    <div className="flex justify-end gap-3 pt-3"><button onClick={() => setSuspensionStudent(null)} className="rounded-md border border-gray-300 px-4 py-2">关闭</button><button onClick={revokeSuspension} className="rounded-md bg-red-600 px-4 py-2 text-white">撤销暂停</button></div>
                                </div>
                            ) : (
                                <form onSubmit={saveSuspension} className="mt-4 space-y-4">
                                    <div className="grid grid-cols-2 gap-4"><label className="text-sm text-gray-700">开始日期<input required type="date" value={suspensionForm.start_date} onChange={e => setSuspensionForm({ ...suspensionForm, start_date: e.target.value })} className="mt-1 w-full rounded-md border-gray-300" /></label><label className="text-sm text-gray-700">结束日期<input required type="date" min={suspensionForm.start_date} value={suspensionForm.end_date} onChange={e => setSuspensionForm({ ...suspensionForm, end_date: e.target.value })} className="mt-1 w-full rounded-md border-gray-300" /></label></div>
                                    <label className="block text-sm text-gray-700">原因<textarea required rows="3" value={suspensionForm.reason} onChange={e => setSuspensionForm({ ...suspensionForm, reason: e.target.value })} className="mt-1 w-full rounded-md border-gray-300" /></label>
                                    <label className="block text-sm text-gray-700">临时去向<input value={suspensionForm.destination} onChange={e => setSuspensionForm({ ...suspensionForm, destination: e.target.value })} className="mt-1 w-full rounded-md border-gray-300" placeholder="家中或其他地点" /></label>
                                    <div className="flex justify-end gap-3"><button type="button" onClick={() => setSuspensionStudent(null)} className="rounded-md border border-gray-300 px-4 py-2">取消</button><button type="submit" className="rounded-md bg-indigo-600 px-4 py-2 text-white">确认暂停</button></div>
                                </form>
                            )}
                        </div>
                    </div>
                </div>
            )}

            {eveningLeaveStudent && (
                <div className="fixed inset-0 z-20 overflow-y-auto">
                    <div className="flex min-h-full items-end justify-center p-4 sm:items-center">
                        <div className="fixed inset-0 bg-gray-500/75" onClick={() => setEveningLeaveStudent(null)} />
                        <div className="relative w-full max-w-lg rounded-lg bg-white p-6 shadow-xl">
                            <h3 className="text-lg font-semibold text-gray-900">{eveningLeaveStudent.name} · 标记夜自习请假</h3>
                            <form onSubmit={saveEveningLeave} className="mt-4 space-y-4">
                                <div className="grid gap-4 sm:grid-cols-2"><label className="text-sm text-gray-700">日期<input required type="date" value={eveningLeaveForm.date} onChange={e => setEveningLeaveForm({ ...eveningLeaveForm, date: e.target.value })} className="mt-1 w-full rounded-md border-gray-300" /></label><label className="text-sm text-gray-700">夜自习节次<select required value={eveningLeaveForm.period_id} onChange={e => setEveningLeaveForm({ ...eveningLeaveForm, period_id: e.target.value })} className="mt-1 w-full rounded-md border-gray-300">{eveningPeriods.map(period => <option key={period.id} value={period.id}>{period.name}</option>)}</select></label></div>
                                <div className="grid gap-4 sm:grid-cols-2"><label className="block text-sm text-gray-700">请假类型<select required value={eveningLeaveForm.leave_type_id} onChange={e => setEveningLeaveForm({ ...eveningLeaveForm, leave_type_id: e.target.value })} className="mt-1 w-full rounded-md border-gray-300">{eveningLeaveTypes.map(type => <option key={type.id} value={type.id}>{type.name}</option>)}</select></label><label className="block text-sm text-gray-700">夜自习状态<select required value={eveningLeaveForm.status_id} onChange={e => setEveningLeaveForm({ ...eveningLeaveForm, status_id: e.target.value })} className="mt-1 w-full rounded-md border-gray-300">{eveningStatuses.map(status => <option key={status.id} value={status.id}>{status.name}</option>)}</select></label></div>
                                <label className="block text-sm text-gray-700">具体去向<input required value={eveningLeaveForm.destination} onChange={e => setEveningLeaveForm({ ...eveningLeaveForm, destination: e.target.value })} className="mt-1 w-full rounded-md border-gray-300" placeholder="宿舍、家中或活动地点" /></label>
                                <label className="block text-sm text-gray-700">备注<textarea rows="3" value={eveningLeaveForm.reason} onChange={e => setEveningLeaveForm({ ...eveningLeaveForm, reason: e.target.value })} className="mt-1 w-full rounded-md border-gray-300" /></label>
                                <div className="flex justify-end gap-3"><button type="button" onClick={() => setEveningLeaveStudent(null)} className="rounded-md border border-gray-300 px-4 py-2">取消</button><button type="submit" className="rounded-md bg-indigo-600 px-4 py-2 text-white">保存标记</button></div>
                            </form>
                        </div>
                    </div>
                </div>
            )}
        </Layout>
    );
}
