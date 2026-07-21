import { useEffect, useState } from 'react';
import axios from 'axios';
import { PlusIcon, TrashIcon } from '@heroicons/react/24/outline';

const emptyStatus = {
    name: '', color: 'gray', base_status: 'excused', is_default: false,
    student_requestable: false, is_active: true, sort_order: 0,
};

const colors = [
    ['gray', '灰色'], ['green', '绿色'], ['blue', '蓝色'], ['cyan', '青色'],
    ['indigo', '靛蓝'], ['purple', '紫色'], ['orange', '橙色'], ['red', '红色'],
];

export default function EveningStudySettings() {
    const [statuses, setStatuses] = useState([]);
    const [suspensionStatusId, setSuspensionStatusId] = useState('');
    const [draft, setDraft] = useState(emptyStatus);
    const [message, setMessage] = useState('');

    const load = async () => {
        const statusRes = await axios.get('/evening-study-statuses');
        setStatuses(statusRes.data.statuses || []);
        setSuspensionStatusId(String(statusRes.data.boarding_suspension_status_id || ''));
    };

    useEffect(() => { load().catch(error => setMessage(error.response?.data?.message || '加载失败')); }, []);

    const save = async (status) => {
        await axios.put(`/evening-study-statuses/${status.id}`, status);
        setMessage('状态已保存');
        await load();
    };

    const create = async () => {
        await axios.post('/evening-study-statuses', {
            ...draft,
            sort_order: statuses.length,
        });
        setDraft(emptyStatus);
        setMessage('状态已添加');
        await load();
    };

    const remove = async (status) => {
        if (!confirm(`确定删除“${status.name}”吗？历史记录中的状态名称仍会保留。`)) return;
        await axios.delete(`/evening-study-statuses/${status.id}`);
        setMessage('状态已删除');
        await load();
    };

    const setField = (id, field, value) => {
        setStatuses(items => items.map(item => item.id === id ? { ...item, [field]: value } : item));
    };

    const saveSuspensionStatus = async () => {
        await axios.post('/evening-study-statuses/suspension-status', { status_id: Number(suspensionStatusId) });
        setMessage('暂停住宿状态已保存');
    };

    const errorMessage = error => setMessage(error.response?.data?.message || Object.values(error.response?.data?.errors || {}).flat()[0] || '操作失败');

    return (
        <div className="space-y-6">
            <div>
                <h3 className="text-lg font-semibold text-gray-900">夜自习状态</h3>
                <p className="mt-1 text-sm text-gray-500">配置值班点名状态，以及允许住宿生申请的夜自习请假去向。</p>
            </div>

            {message && <div className="rounded-md bg-indigo-50 px-3 py-2 text-sm text-indigo-700">{message}</div>}

            <div className="overflow-x-auto border border-gray-200 rounded-md">
                <table className="min-w-[760px] w-full divide-y divide-gray-200 text-sm">
                    <thead className="bg-gray-50 text-left text-gray-600">
                        <tr><th className="p-3">名称</th><th className="p-3">颜色</th><th className="p-3">归类</th><th className="p-3">学生可选择</th><th className="p-3">默认</th><th className="p-3">启用</th><th className="p-3 text-right">操作</th></tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100 bg-white">
                        {statuses.map(status => (
                            <tr key={status.id}>
                                <td className="p-2"><input value={status.name} onChange={e => setField(status.id, 'name', e.target.value)} className="w-32 rounded-md border-gray-300 text-sm" /></td>
                                <td className="p-2"><select value={status.color} onChange={e => setField(status.id, 'color', e.target.value)} className="rounded-md border-gray-300 text-sm">{colors.map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></td>
                                <td className="p-2"><select value={status.base_status} onChange={e => setField(status.id, 'base_status', e.target.value)} className="rounded-md border-gray-300 text-sm"><option value="present">正常</option><option value="excused">已说明</option><option value="absent">缺席</option></select></td>
                                <td className="p-2 text-center"><input type="checkbox" checked={status.student_requestable} onChange={e => setField(status.id, 'student_requestable', e.target.checked)} className="rounded border-gray-300 text-indigo-600" /></td>
                                <td className="p-2 text-center"><input type="checkbox" checked={status.is_default} onChange={e => setField(status.id, 'is_default', e.target.checked)} className="rounded border-gray-300 text-indigo-600" /></td>
                                <td className="p-2 text-center"><input type="checkbox" checked={status.is_active} onChange={e => setField(status.id, 'is_active', e.target.checked)} className="rounded border-gray-300 text-indigo-600" /></td>
                                <td className="p-2 text-right whitespace-nowrap"><button onClick={() => save(status).catch(errorMessage)} className="text-indigo-600 hover:text-indigo-800">保存</button><button title="删除" onClick={() => remove(status).catch(errorMessage)} className="ml-3 p-1 text-gray-400 hover:text-red-600"><TrashIcon className="h-4 w-4" /></button></td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <div className="grid gap-3 border-t border-gray-200 pt-5 md:grid-cols-[1fr_9rem_9rem_8rem_auto] md:items-end">
                <label className="text-sm text-gray-700">新状态<input value={draft.name} onChange={e => setDraft({ ...draft, name: e.target.value })} className="mt-1 w-full rounded-md border-gray-300" /></label>
                <label className="text-sm text-gray-700">颜色<select value={draft.color} onChange={e => setDraft({ ...draft, color: e.target.value })} className="mt-1 w-full rounded-md border-gray-300">{colors.map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label>
                <label className="text-sm text-gray-700">归类<select value={draft.base_status} onChange={e => setDraft({ ...draft, base_status: e.target.value })} className="mt-1 w-full rounded-md border-gray-300"><option value="present">正常</option><option value="excused">已说明</option><option value="absent">缺席</option></select></label>
                <label className="flex items-center gap-2 pb-2 text-sm text-gray-700"><input type="checkbox" checked={draft.student_requestable} onChange={e => setDraft({ ...draft, student_requestable: e.target.checked })} className="rounded border-gray-300 text-indigo-600" />学生可选择</label>
                <button disabled={!draft.name.trim()} onClick={() => create().catch(errorMessage)} className="inline-flex h-10 items-center justify-center gap-1 rounded-md bg-indigo-600 px-3 text-sm font-medium text-white disabled:bg-gray-300"><PlusIcon className="h-4 w-4" />添加</button>
            </div>

            <div className="border-t border-gray-200 pt-5">
                <label className="block text-sm font-medium text-gray-700">暂停住宿对应状态</label>
                <div className="mt-2 flex max-w-lg gap-2"><select value={suspensionStatusId} onChange={e => setSuspensionStatusId(e.target.value)} className="flex-1 rounded-md border-gray-300"><option value="">请选择</option>{statuses.filter(status => status.is_active).map(status => <option key={status.id} value={status.id}>{status.name}</option>)}</select><button onClick={() => saveSuspensionStatus().catch(errorMessage)} className="rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">保存</button></div>
            </div>
        </div>
    );
}
