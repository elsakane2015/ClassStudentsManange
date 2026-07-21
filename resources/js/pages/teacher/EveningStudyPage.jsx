import { useEffect, useMemo, useState } from 'react';
import axios from 'axios';
import Layout from '../../components/Layout';
import useAuthStore from '../../store/authStore';
import { CheckCircleIcon, ClockIcon } from '@heroicons/react/24/outline';

const today = () => {
    const date = new Date();
    date.setMinutes(date.getMinutes() - date.getTimezoneOffset());
    return date.toISOString().slice(0, 10);
};

export default function EveningStudyPage() {
    const { user } = useAuthStore();
    const [mode, setMode] = useState('take');
    const [date, setDate] = useState(today());
    const [periods, setPeriods] = useState([]);
    const [periodId, setPeriodId] = useState('');
    const [classes, setClasses] = useState([]);
    const [statuses, setStatuses] = useState([]);
    const [session, setSession] = useState(null);
    const [records, setRecords] = useState([]);
    const [history, setHistory] = useState([]);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [message, setMessage] = useState('');
    const canTake = ['duty_teacher', 'system_admin', 'school_admin'].includes(user?.role);
    const canReopen = ['system_admin', 'school_admin', 'department_manager', 'manager'].includes(user?.role);

    const loadBase = async () => {
        setLoading(true);
        try {
            const [periodRes, statusRes] = await Promise.all([
                axios.get('/evening-study/periods'),
                axios.get('/evening-study-statuses'),
            ]);
            setPeriods(periodRes.data || []);
            setStatuses(statusRes.data.statuses || []);
            setPeriodId(current => current || String(periodRes.data?.[0]?.id || ''));
        } catch (error) {
            setMessage(error.response?.data?.message || '夜自习配置加载失败');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => { loadBase(); }, []);

    useEffect(() => {
        if (!periodId || mode !== 'take') return;
        setSession(null);
        setRecords([]);
        axios.get('/evening-study/classes', { params: { date, period_id: periodId } })
            .then(response => setClasses(response.data || []))
            .catch(error => setMessage(error.response?.data?.message || '班级加载失败'));
    }, [date, periodId, mode]);

    useEffect(() => {
        if (mode !== 'history') return;
        axios.get('/evening-study/history').then(response => setHistory(response.data.data || []));
    }, [mode]);

    const openClass = async (item) => {
        setMessage('');
        try {
            let response;
            if (item.session) {
                response = await axios.get(`/evening-study/sessions/${item.session.id}`);
            } else if (canTake) {
                response = await axios.post('/evening-study/sessions', { date, period_id: Number(periodId), class_id: item.id });
            } else {
                setMessage('该班级还没有夜自习点名记录');
                return;
            }
            setSession(response.data.session);
            setRecords(response.data.records || []);
        } catch (error) {
            setMessage(error.response?.data?.message || '打开点名失败');
        }
    };

    const updateRecord = (id, field, value) => {
        setRecords(items => items.map(item => item.id === id ? { ...item, [field]: value } : item));
    };

    const saveRecords = async () => {
        setSaving(true);
        try {
            const response = await axios.put(`/evening-study/sessions/${session.id}/records`, {
                records: records.map(record => ({
                    id: record.id,
                    status_id: Number(record.evening_study_status_id),
                    destination: record.destination || null,
                    note: record.note || null,
                })),
            });
            setRecords(response.data.records || []);
            setSession(response.data.session);
            setMessage('点名结果已保存');
        } catch (error) {
            setMessage(error.response?.data?.message || '保存失败');
        } finally {
            setSaving(false);
        }
    };

    const complete = async () => {
        if (!confirm('完成后将锁定本场点名，确定提交吗？')) return;
        try {
            const response = await axios.post(`/evening-study/sessions/${session.id}/complete`);
            setSession(response.data.session);
            setRecords(response.data.records || []);
            setMessage('本场点名已完成');
        } catch (error) {
            setMessage(error.response?.data?.message || '提交失败');
        }
    };

    const reopen = async () => {
        try {
            const response = await axios.post(`/evening-study/sessions/${session.id}/reopen`);
            setSession(response.data.session);
            setRecords(response.data.records || []);
            setMessage('本场点名已重新打开');
        } catch (error) {
            setMessage(error.response?.data?.message || '重新打开失败');
        }
    };

    const counts = useMemo(() => records.reduce((result, record) => {
        const name = record.evening_study_status?.name || record.status_name_snapshot || '未设置';
        result[name] = (result[name] || 0) + 1;
        return result;
    }, {}), [records]);

    return (
        <Layout>
            <div className="space-y-5">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div><h1 className="text-2xl font-bold text-gray-900">夜自习点名</h1><p className="mt-1 text-sm text-gray-500">按班级核对住宿生当晚状态。</p></div>
                    <div className="inline-flex self-start rounded-md border border-gray-300 bg-white p-1">
                        <button onClick={() => setMode('take')} className={`rounded px-3 py-1.5 text-sm ${mode === 'take' ? 'bg-indigo-600 text-white' : 'text-gray-600'}`}>当晚点名</button>
                        <button onClick={() => setMode('history')} className={`rounded px-3 py-1.5 text-sm ${mode === 'history' ? 'bg-indigo-600 text-white' : 'text-gray-600'}`}>历史记录</button>
                    </div>
                </div>

                {message && <div className="rounded-md bg-indigo-50 px-4 py-3 text-sm text-indigo-700">{message}</div>}

                {mode === 'take' && <>
                    <div className="flex flex-wrap gap-3 border-y border-gray-200 bg-white py-4">
                        <label className="text-sm text-gray-700">日期<input type="date" value={date} onChange={event => setDate(event.target.value)} className="ml-2 rounded-md border-gray-300" /></label>
                        <label className="text-sm text-gray-700">节次<select value={periodId} onChange={event => setPeriodId(event.target.value)} className="ml-2 rounded-md border-gray-300"><option value="">请选择</option>{periods.map(period => <option key={period.id} value={period.id}>{period.name}</option>)}</select></label>
                    </div>

                    {!session ? (
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            {classes.map(item => <button key={item.id} onClick={() => openClass(item)} className="flex min-h-24 items-center justify-between rounded-md border border-gray-200 bg-white p-4 text-left shadow-sm hover:border-indigo-300">
                                <span><span className="block font-semibold text-gray-900">{item.name}</span><span className="mt-1 block text-sm text-gray-500">{item.department?.name} · {item.boarding_count} 名住宿生</span></span>
                                <span className={`text-xs ${item.session?.status === 'completed' ? 'text-green-600' : 'text-indigo-600'}`}>{item.session ? (item.session.status === 'completed' ? '已完成' : '进行中') : (canTake ? '开始点名' : '未开始')}</span>
                            </button>)}
                            {!loading && classes.length === 0 && <p className="col-span-full py-12 text-center text-sm text-gray-500">当前范围内没有可点名班级。</p>}
                        </div>
                    ) : (
                        <div className="space-y-4">
                            <div className="flex flex-wrap items-center justify-between gap-3"><button onClick={() => setSession(null)} className="text-sm text-indigo-600">返回班级列表</button><div className="text-sm text-gray-600">{session.school_class?.name} · {session.period_name_snapshot} · {records.length} 人</div></div>
                            <div className="flex flex-wrap gap-2">{Object.entries(counts).map(([name, count]) => <span key={name} className="rounded-full bg-gray-100 px-3 py-1 text-xs text-gray-700">{name} {count}</span>)}</div>
                            <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
                                {records.map(record => <div key={record.id} className="rounded-md border border-gray-200 bg-white p-4 shadow-sm">
                                    <div className="flex items-start justify-between gap-3"><div><div className="font-semibold text-gray-900">{record.student?.user?.name}</div><div className="mt-1 text-xs text-gray-500">{record.student?.student_no}</div></div>{record.boarding_suspension && <span className="rounded bg-gray-100 px-2 py-1 text-xs text-gray-700">暂停住宿</span>}</div>
                                    <div className="mt-3 grid gap-3 sm:grid-cols-2">
                                        <label className="text-xs text-gray-500">状态<select disabled={session.status === 'completed' || !canTake} value={record.evening_study_status_id || ''} onChange={event => updateRecord(record.id, 'evening_study_status_id', Number(event.target.value))} className="mt-1 w-full rounded-md border-gray-300 text-sm">{statuses.map(status => <option key={status.id} value={status.id}>{status.name}</option>)}</select></label>
                                        <label className="text-xs text-gray-500">具体去向<input disabled={session.status === 'completed' || !canTake} value={record.destination || ''} onChange={event => updateRecord(record.id, 'destination', event.target.value)} className="mt-1 w-full rounded-md border-gray-300 text-sm" placeholder="宿舍、家中、活动地点" /></label>
                                    </div>
                                    {record.approval_status && <p className="mt-2 text-xs text-gray-500">学生申请：{record.requested_evening_status?.name}（{record.approval_status === 'approved' ? '已批准' : record.approval_status === 'pending' ? '待审批' : '已驳回'}）</p>}
                                    {record.boarding_suspension && <p className="mt-2 text-xs text-gray-500">{record.boarding_suspension.reason}{record.boarding_suspension.destination ? ` · 去向：${record.boarding_suspension.destination}` : ''}</p>}
                                </div>)}
                            </div>
                            {canTake && session.status !== 'completed' && <div className="sticky bottom-3 flex justify-end gap-3 rounded-md border border-gray-200 bg-white/95 p-3 shadow-lg"><button disabled={saving} onClick={saveRecords} className="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700">{saving ? '保存中...' : '保存修改'}</button><button onClick={complete} className="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white"><CheckCircleIcon className="h-5 w-5" />完成点名</button></div>}
                            {session.status === 'completed' && canReopen && <div className="flex justify-end"><button onClick={reopen} className="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700">重新打开本场点名</button></div>}
                        </div>
                    )}
                </>}

                {mode === 'history' && <div className="overflow-x-auto rounded-md border border-gray-200 bg-white"><table className="min-w-full divide-y divide-gray-200 text-sm"><thead className="bg-gray-50"><tr><th className="p-3 text-left">日期</th><th className="p-3 text-left">班级</th><th className="p-3 text-left">节次</th><th className="p-3 text-left">执行人</th><th className="p-3 text-left">结果</th></tr></thead><tbody className="divide-y divide-gray-100">{history.map(item => <tr key={item.id}><td className="p-3">{item.attendance_date}</td><td className="p-3">{item.school_class?.name}</td><td className="p-3">{item.period_name_snapshot}</td><td className="p-3">{item.creator?.name}</td><td className="p-3">{item.status === 'completed' ? `正常 ${item.normal_count} / 异常 ${item.exception_count}` : '进行中'}</td></tr>)}{history.length === 0 && <tr><td colSpan="5" className="p-10 text-center text-gray-500"><ClockIcon className="mx-auto mb-2 h-6 w-6" />暂无记录</td></tr>}</tbody></table></div>}
            </div>
        </Layout>
    );
}
