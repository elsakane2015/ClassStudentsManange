import { useEffect, useMemo, useState } from 'react';
import axios from 'axios';
import { format, parseISO } from 'date-fns';
import Layout from '../../components/Layout';
import useAuthStore from '../../store/authStore';
import { ArrowLeftIcon, CheckCircleIcon, ClockIcon, PencilSquareIcon, TrashIcon } from '@heroicons/react/24/outline';

const today = () => {
    const date = new Date();
    date.setMinutes(date.getMinutes() - date.getTimezoneOffset());
    return date.toISOString().slice(0, 10);
};

const colorStyles = {
    green: { selected: 'border-green-600 bg-green-600 text-white', soft: 'bg-green-50 text-green-700 ring-green-200' },
    blue: { selected: 'border-blue-600 bg-blue-600 text-white', soft: 'bg-blue-50 text-blue-700 ring-blue-200' },
    indigo: { selected: 'border-indigo-600 bg-indigo-600 text-white', soft: 'bg-indigo-50 text-indigo-700 ring-indigo-200' },
    purple: { selected: 'border-purple-600 bg-purple-600 text-white', soft: 'bg-purple-50 text-purple-700 ring-purple-200' },
    cyan: { selected: 'border-cyan-600 bg-cyan-600 text-white', soft: 'bg-cyan-50 text-cyan-700 ring-cyan-200' },
    red: { selected: 'border-red-600 bg-red-600 text-white', soft: 'bg-red-50 text-red-700 ring-red-200' },
    yellow: { selected: 'border-yellow-500 bg-yellow-500 text-white', soft: 'bg-yellow-50 text-yellow-700 ring-yellow-200' },
    orange: { selected: 'border-orange-500 bg-orange-500 text-white', soft: 'bg-orange-50 text-orange-700 ring-orange-200' },
    gray: { selected: 'border-gray-700 bg-gray-700 text-white', soft: 'bg-gray-100 text-gray-700 ring-gray-200' },
};

const stylesFor = (color) => colorStyles[color] || colorStyles.gray;

const formatDateTime = (value) => {
    if (!value) return '-';
    try {
        return format(parseISO(value), 'yyyy-MM-dd HH:mm:ss');
    } catch {
        return value;
    }
};

export default function EveningStudyPage() {
    const { user } = useAuthStore();
    const [mode, setMode] = useState('take');
    const [date, setDate] = useState(today());
    const [periods, setPeriods] = useState([]);
    const [periodId, setPeriodId] = useState('');
    const [classes, setClasses] = useState([]);
    const [statuses, setStatuses] = useState([]);
    const [summary, setSummary] = useState(null);
    const [session, setSession] = useState(null);
    const [records, setRecords] = useState([]);
    const [history, setHistory] = useState([]);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [message, setMessage] = useState('');

    const canTake = ['duty_teacher', 'system_admin', 'school_admin'].includes(user?.role);
    const canModify = ['duty_teacher', 'system_admin', 'school_admin', 'department_manager', 'manager'].includes(user?.role);

    const loadBase = async () => {
        setLoading(true);
        try {
            const [periodRes, statusRes] = await Promise.all([
                axios.get('/evening-study/periods'),
                axios.get('/evening-study-statuses'),
            ]);
            setPeriods(periodRes.data || []);
            setStatuses((statusRes.data.statuses || []).filter(status => status.is_active));
            setPeriodId(current => current || String(periodRes.data?.[0]?.id || ''));
        } catch (error) {
            setMessage(error.response?.data?.message || '夜自习配置加载失败');
        } finally {
            setLoading(false);
        }
    };

    const loadClassesAndSummary = async () => {
        if (!periodId) return;
        try {
            const [classRes, summaryRes] = await Promise.all([
                axios.get('/evening-study/classes', { params: { date, period_id: periodId } }),
                axios.get('/evening-study/summary', { params: { date, period_id: periodId } }),
            ]);
            setClasses(classRes.data || []);
            setSummary(summaryRes.data || null);
        } catch (error) {
            setMessage(error.response?.data?.message || '夜自习数据加载失败');
        }
    };

    const loadHistory = async () => {
        try {
            const response = await axios.get('/evening-study/history');
            setHistory(response.data.data || []);
        } catch (error) {
            setMessage(error.response?.data?.message || '历史记录加载失败');
        }
    };

    useEffect(() => { loadBase(); }, []);

    useEffect(() => {
        if (!periodId || mode !== 'take') return;
        loadClassesAndSummary();
    }, [date, periodId, mode]);

    useEffect(() => {
        if (mode === 'history') loadHistory();
    }, [mode]);

    const openSession = async (sessionId) => {
        const response = await axios.get(`/evening-study/sessions/${sessionId}`);
        setSession(response.data.session);
        setRecords(response.data.records || []);
    };

    const openClass = async (item) => {
        setMessage('');
        try {
            if (item.session) {
                await openSession(item.session.id);
            } else if (canTake) {
                const response = await axios.post('/evening-study/sessions', {
                    date,
                    period_id: Number(periodId),
                    class_id: item.id,
                });
                setSession(response.data.session);
                setRecords(response.data.records || []);
                await loadClassesAndSummary();
            } else {
                setMessage('该班级还没有夜自习点名记录');
            }
        } catch (error) {
            setMessage(error.response?.data?.message || '打开点名失败');
        }
    };

    const openHistorySession = async (item) => {
        setMessage('');
        try {
            setDate(String(item.attendance_date).slice(0, 10));
            setPeriodId(String(item.period_id));
            setMode('take');
            await openSession(item.id);
        } catch (error) {
            setMessage(error.response?.data?.message || '打开历史点名失败');
        }
    };

    const showTakeList = () => {
        setMode('take');
        setSession(null);
        setRecords([]);
    };

    const changeDate = (value) => {
        setDate(value);
        setSession(null);
        setRecords([]);
    };

    const changePeriod = (value) => {
        setPeriodId(value);
        setSession(null);
        setRecords([]);
    };

    const selectStatus = (recordId, status) => {
        setRecords(items => items.map(item => item.id === recordId ? {
            ...item,
            evening_study_status_id: status.id,
            evening_study_status: status,
            status_name_snapshot: status.name,
            status: status.base_status,
        } : item));
    };

    const updateRecord = (id, field, value) => {
        setRecords(items => items.map(item => item.id === id ? { ...item, [field]: value } : item));
    };

    const recordsPayload = () => records.map(record => ({
        id: record.id,
        status_id: Number(record.evening_study_status_id),
        destination: record.destination || null,
        note: record.note || null,
    }));

    const complete = async () => {
        if (!confirm('确定保存当前结果并完成本场点名吗？')) return;
        setSaving(true);
        try {
            const response = await axios.post(`/evening-study/sessions/${session.id}/complete`, {
                records: recordsPayload(),
            });
            setSession(response.data.session);
            setRecords(response.data.records || []);
            setMessage('本场点名已完成');
            await loadClassesAndSummary();
        } catch (error) {
            setMessage(error.response?.data?.message || '提交失败');
        } finally {
            setSaving(false);
        }
    };

    const reopen = async () => {
        setSaving(true);
        try {
            const response = await axios.post(`/evening-study/sessions/${session.id}/reopen`);
            setSession(response.data.session);
            setRecords(response.data.records || []);
            setMessage('本场点名已进入修改状态');
            await loadClassesAndSummary();
        } catch (error) {
            setMessage(error.response?.data?.message || '打开修改失败');
        } finally {
            setSaving(false);
        }
    };

    const deleteSession = async (item) => {
        const className = item.school_class?.name || '该班级';
        if (!confirm(`确定删除 ${className} 的本场夜自习点名吗？\n自动生成的点名明细将删除，学生请假记录会保留。`)) return;
        setSaving(true);
        try {
            const response = await axios.delete(`/evening-study/sessions/${item.id}`);
            if (session?.id === item.id) {
                setSession(null);
                setRecords([]);
            }
            setMessage(response.data.message || '点名记录已删除');
            await Promise.all([loadHistory(), loadClassesAndSummary()]);
        } catch (error) {
            setMessage(error.response?.data?.message || '删除点名失败');
        } finally {
            setSaving(false);
        }
    };

    const markAllDefault = () => {
        const defaultStatus = statuses.find(status => status.is_default) || statuses.find(status => status.base_status === 'present');
        if (!defaultStatus) return;
        setRecords(items => items.map(item => ({
            ...item,
            evening_study_status_id: defaultStatus.id,
            evening_study_status: defaultStatus,
            status_name_snapshot: defaultStatus.name,
            status: defaultStatus.base_status,
        })));
    };

    const currentCounts = useMemo(() => {
        const counts = new Map(statuses.map(status => [status.name, { name: status.name, color: status.color, count: 0 }]));
        records.forEach(record => {
            const name = record.evening_study_status?.name || record.status_name_snapshot || '未设置';
            const current = counts.get(name) || { name, color: record.evening_study_status?.color || 'gray', count: 0 };
            counts.set(name, { ...current, count: current.count + 1 });
        });
        return Array.from(counts.values());
    }, [records, statuses]);

    const isEditing = session?.status === 'in_progress' && canModify;
    const overall = summary?.overall;

    return (
        <Layout>
            <div className="space-y-5">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900">夜自习点名</h1>
                        <p className="mt-1 text-sm text-gray-500">按班级核对住宿生当晚状态。</p>
                    </div>
                    <div className="inline-flex self-start rounded-md border border-gray-300 bg-white p-1">
                        <button onClick={showTakeList} className={`rounded px-3 py-1.5 text-sm ${mode === 'take' ? 'bg-indigo-600 text-white' : 'text-gray-600'}`}>当晚点名</button>
                        <button onClick={() => setMode('history')} className={`rounded px-3 py-1.5 text-sm ${mode === 'history' ? 'bg-indigo-600 text-white' : 'text-gray-600'}`}>历史记录</button>
                    </div>
                </div>

                {message && <div className="rounded-md bg-indigo-50 px-4 py-3 text-sm text-indigo-700">{message}</div>}

                {mode === 'take' && <>
                    <div className="flex flex-wrap gap-3 border-y border-gray-200 bg-white py-4">
                        <label className="text-sm text-gray-700">日期<input type="date" value={date} onChange={event => changeDate(event.target.value)} className="ml-2 rounded-md border-gray-300" /></label>
                        <label className="text-sm text-gray-700">节次<select value={periodId} onChange={event => changePeriod(event.target.value)} className="ml-2 rounded-md border-gray-300"><option value="">请选择</option>{periods.map(period => <option key={period.id} value={period.id}>{period.name}</option>)}</select></label>
                    </div>

                    {!session && overall && <section className="border-b border-gray-200 pb-5">
                        <div className="flex items-center justify-between gap-4">
                            <h2 className="text-base font-semibold text-gray-900">{summary.scope_type === 'school' ? '全校汇总' : '系部汇总'}</h2>
                            <span className="text-sm text-gray-500">已完成 {overall.completed_class_count}/{overall.class_count} 个班级</span>
                        </div>
                        <div className="mt-3 grid grid-cols-2 divide-x divide-gray-200 border-y border-gray-200 bg-white sm:grid-cols-4">
                            <div className="px-4 py-3"><div className="text-xs text-gray-500">应到</div><div className="mt-1 text-2xl font-semibold text-gray-900">{overall.expected_count}</div></div>
                            <div className="px-4 py-3"><div className="text-xs text-gray-500">实到</div><div className="mt-1 text-2xl font-semibold text-green-600">{overall.present_count}</div></div>
                            <div className="px-4 py-3"><div className="text-xs text-gray-500">已点名</div><div className="mt-1 text-2xl font-semibold text-indigo-600">{overall.recorded_count}</div></div>
                            <div className="px-4 py-3"><div className="text-xs text-gray-500">未点名</div><div className="mt-1 text-2xl font-semibold text-gray-700">{Math.max(0, overall.expected_count - overall.recorded_count)}</div></div>
                        </div>
                        <div className="mt-3 flex flex-wrap gap-2">
                            {overall.status_counts.map(status => <span key={status.name} className={`rounded-full px-3 py-1 text-xs ring-1 ${stylesFor(status.color).soft}`}>{status.name} {status.count}</span>)}
                        </div>
                        {summary.departments.length > 1 && <div className="mt-4 overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200 text-sm">
                                <thead><tr className="text-left text-xs text-gray-500"><th className="py-2 pr-4">系部</th><th className="px-3 py-2">班级</th><th className="px-3 py-2">应到</th><th className="px-3 py-2">实到</th><th className="px-3 py-2">状态分布</th></tr></thead>
                                <tbody className="divide-y divide-gray-100">{summary.departments.map(department => <tr key={department.department_id || department.department_name}><td className="py-3 pr-4 font-medium text-gray-900">{department.department_name}</td><td className="px-3 py-3 text-gray-600">{department.completed_class_count}/{department.class_count}</td><td className="px-3 py-3 text-gray-600">{department.expected_count}</td><td className="px-3 py-3 text-gray-600">{department.present_count}</td><td className="px-3 py-3"><div className="flex flex-wrap gap-1">{department.status_counts.map(status => <span key={status.name} className={`rounded px-2 py-0.5 text-xs ring-1 ${stylesFor(status.color).soft}`}>{status.name} {status.count}</span>)}</div></td></tr>)}</tbody>
                            </table>
                        </div>}
                    </section>}

                    {!session ? (
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            {classes.map(item => <button key={item.id} onClick={() => openClass(item)} className="min-h-28 rounded-md border border-gray-200 bg-white p-4 text-left shadow-sm hover:border-indigo-400 hover:shadow">
                                <div className="flex items-start justify-between gap-3">
                                    <span><span className="block font-semibold text-gray-900">{item.name}</span><span className="mt-1 block text-sm text-gray-500">{item.department?.name} · 应到 {item.boarding_count} 人</span></span>
                                    <span className={`text-xs font-medium ${item.session?.status === 'completed' ? 'text-green-600' : 'text-indigo-600'}`}>{item.session ? (item.session.status === 'completed' ? '已完成' : '进行中') : (canTake ? '开始点名' : '未开始')}</span>
                                </div>
                                {item.session && <div className="mt-3 flex flex-wrap gap-1">{item.session.status_counts?.map(status => <span key={status.name} className={`rounded px-2 py-0.5 text-xs ring-1 ${stylesFor(status.color).soft}`}>{status.name} {status.count}</span>)}</div>}
                            </button>)}
                            {!loading && classes.length === 0 && <p className="col-span-full py-12 text-center text-sm text-gray-500">当前范围内没有可点名班级。</p>}
                        </div>
                    ) : (
                        <div className="space-y-4">
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <button onClick={() => setSession(null)} className="inline-flex items-center gap-1 text-sm text-indigo-600"><ArrowLeftIcon className="h-4 w-4" />返回班级列表</button>
                                <div className="text-sm text-gray-600">{session.school_class?.name} · {session.period_name_snapshot} · 应到 {records.length} 人</div>
                            </div>
                            <div className="flex flex-wrap gap-2">{currentCounts.map(status => <span key={status.name} className={`rounded-full px-3 py-1 text-xs ring-1 ${stylesFor(status.color).soft}`}>{status.name} {status.count}</span>)}</div>
                            <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
                                {records.map(record => <div key={record.id} className={`rounded-md border bg-white p-4 shadow-sm ${isEditing ? 'border-gray-200' : 'border-gray-100'}`}>
                                    <div className="flex items-start justify-between gap-3">
                                        <div><div className="font-semibold text-gray-900">{record.student?.user?.name}</div><div className="mt-1 text-xs text-gray-500">{record.student?.student_no}</div></div>
                                        {record.boarding_suspension && <span className="rounded bg-gray-100 px-2 py-1 text-xs text-gray-700">暂停住宿</span>}
                                    </div>
                                    <div className="mt-4">
                                        <div className="mb-2 text-xs font-medium text-gray-600">点名状态</div>
                                        <div className="flex flex-wrap gap-2">
                                            {statuses.map(status => {
                                                const selected = Number(record.evening_study_status_id) === Number(status.id);
                                                return <button key={status.id} type="button" aria-pressed={selected} disabled={!isEditing || saving} onClick={() => selectStatus(record.id, status)} className={`rounded-md border px-3 py-2 text-sm font-medium transition-colors ${selected ? stylesFor(status.color).selected : 'border-gray-300 bg-white text-gray-700 hover:border-indigo-400'} disabled:cursor-default disabled:opacity-80`}>{status.name}</button>;
                                            })}
                                        </div>
                                    </div>
                                    <label className="mt-4 block text-xs font-medium text-gray-600">具体去向<input disabled={!isEditing || saving} value={record.destination || ''} onChange={event => updateRecord(record.id, 'destination', event.target.value)} className="mt-1.5 w-full rounded-md border-gray-300 text-sm disabled:bg-gray-50" placeholder="宿舍、家中、活动地点" /></label>
                                    {record.approval_status && <p className="mt-2 text-xs text-gray-500">学生申请：{record.requested_evening_status?.name || record.requested_status_name_snapshot || '状态待确认'}（{record.approval_status === 'approved' ? '已批准' : record.approval_status === 'pending' ? '待审批' : '已驳回'}）</p>}
                                    {record.boarding_suspension && <p className="mt-2 text-xs text-gray-500">{record.boarding_suspension.reason}{record.boarding_suspension.destination ? ` · 去向：${record.boarding_suspension.destination}` : ''}</p>}
                                </div>)}
                            </div>
                            {isEditing && <div className="sticky bottom-3 flex items-center justify-between gap-3 rounded-md border border-gray-200 bg-white/95 p-3 shadow-lg">
                                <button disabled={saving} onClick={markAllDefault} className="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">全部正常</button>
                                <button disabled={saving} onClick={complete} className="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-5 py-2.5 text-sm font-medium text-white disabled:opacity-60"><CheckCircleIcon className="h-5 w-5" />{saving ? '提交中...' : '完成点名'}</button>
                            </div>}
                            {session.status === 'completed' && canModify && <div className="flex justify-end"><button disabled={saving} onClick={reopen} className="inline-flex items-center gap-2 rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"><PencilSquareIcon className="h-5 w-5" />修改点名</button></div>}
                        </div>
                    )}
                </>}

                {mode === 'history' && <div className="overflow-x-auto rounded-md border border-gray-200 bg-white"><table className="min-w-full divide-y divide-gray-200 text-sm"><thead className="bg-gray-50"><tr><th className="p-3 text-left">记录时间</th><th className="p-3 text-left">班级</th><th className="p-3 text-left">节次</th><th className="p-3 text-left">执行人</th><th className="p-3 text-left">结果</th><th className="p-3 text-right">操作</th></tr></thead><tbody className="divide-y divide-gray-100">{history.map(item => <tr key={item.id}><td className="whitespace-nowrap p-3">{formatDateTime(item.recorded_at)}</td><td className="p-3">{item.school_class?.name}</td><td className="p-3">{item.period_name_snapshot}</td><td className="p-3">{item.creator?.name}</td><td className="p-3">{item.status === 'completed' ? <div className="flex min-w-72 flex-wrap gap-1">{item.status_counts?.map(status => <span key={status.name} className={`rounded px-2 py-0.5 text-xs ring-1 ${stylesFor(status.color).soft}`}>{status.name} {status.count}</span>)}</div> : '进行中'}</td><td className="p-3"><div className="flex items-center justify-end gap-3"><button onClick={() => openHistorySession(item)} className="text-sm font-medium text-indigo-600 hover:text-indigo-800">查看</button>{canModify && <button type="button" title="删除点名" disabled={saving} onClick={() => deleteSession(item)} className="text-red-600 hover:text-red-700 disabled:opacity-50"><TrashIcon className="h-5 w-5" /></button>}</div></td></tr>)}{history.length === 0 && <tr><td colSpan="6" className="p-10 text-center text-gray-500"><ClockIcon className="mx-auto mb-2 h-6 w-6" />暂无记录</td></tr>}</tbody></table></div>}
            </div>
        </Layout>
    );
}
