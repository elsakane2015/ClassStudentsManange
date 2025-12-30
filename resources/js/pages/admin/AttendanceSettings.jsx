import { useState, useEffect } from 'react';
import axios from 'axios';
import { PlusIcon, TrashIcon } from '@heroicons/react/24/outline';

export default function AttendanceSettings() {
    const [settings, setSettings] = useState({});
    const [attendancePeriods, setAttendancePeriods] = useState([]);
    const [newPeriodName, setNewPeriodName] = useState('');
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [message, setMessage] = useState('');

    useEffect(() => {
        fetchData();
    }, []);

    const fetchData = async () => {
        try {
            const res = await axios.get('/settings');
            const settingsObj = {};
            res.data.forEach(s => settingsObj[s.key] = s.value);
            setSettings(settingsObj);

            // 解析节次配置
            if (settingsObj.attendance_periods) {
                try {
                    const periods = typeof settingsObj.attendance_periods === 'string'
                        ? JSON.parse(settingsObj.attendance_periods)
                        : settingsObj.attendance_periods;
                    setAttendancePeriods(Array.isArray(periods) ? periods : []);
                } catch (e) {
                    console.warn('Failed to parse attendance_periods', e);
                    setAttendancePeriods([]);
                }
            }
        } catch (error) {
            console.error('Failed to fetch settings', error);
        } finally {
            setLoading(false);
        }
    };

    const handleSave = async () => {
        setSaving(true);
        setMessage('');
        try {
            const settingsToSave = {
                ...settings,
                attendance_periods: JSON.stringify(attendancePeriods)
            };

            const settingsArray = Object.keys(settingsToSave).map(key => ({
                key: key,
                value: settingsToSave[key]
            }));
            await axios.post('/settings', { settings: settingsArray });
            setMessage('保存成功');
            setTimeout(() => setMessage(''), 3000);
        } catch (error) {
            setMessage('保存失败: ' + (error.response?.data?.error || error.message));
        } finally {
            setSaving(false);
        }
    };

    // 节次管理辅助函数
    const addPeriod = (type = 'special', customName = '') => {
        const maxId = attendancePeriods.length > 0 ? Math.max(...attendancePeriods.map(p => p.id)) : 0;
        let name = customName;
        if (type === 'regular') {
            const regularCount = attendancePeriods.filter(p => p.type === 'regular').length;
            name = `第${regularCount + 1}节`;
        }
        const newPeriod = { id: maxId + 1, name: name || '新节次', type: type, order: attendancePeriods.length };
        setAttendancePeriods([...attendancePeriods, newPeriod]);
        setNewPeriodName('');
    };

    const removePeriod = async (id) => {
        if (!confirm('删除节次将同时从所有时段配置中移除该节次的关联，确定要删除吗？')) {
            return;
        }

        // 调用后端清理 time_slots 中的引用
        try {
            await axios.post('/settings/cleanup-period', { period_id: id });
        } catch (err) {
            console.warn('Failed to cleanup period from time slots', err);
        }

        setAttendancePeriods(attendancePeriods.filter(p => p.id !== id));
    };

    const updatePeriodName = (id, newName) => {
        setAttendancePeriods(attendancePeriods.map(p => p.id === id ? { ...p, name: newName } : p));
    };

    const movePeriod = (fromIndex, toIndex) => {
        const items = [...attendancePeriods];
        const [removed] = items.splice(fromIndex, 1);
        items.splice(toIndex, 0, removed);
        setAttendancePeriods(items.map((item, idx) => ({ ...item, order: idx })));
    };

    if (loading) {
        return (
            <div className="flex items-center justify-center min-h-64">
                <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600"></div>
            </div>
        );
    }

    return (
        <div className="space-y-6">
            {/* 基础设置卡片 */}
            <div className="bg-white rounded-lg shadow p-6">
                <h3 className="text-lg font-medium text-gray-900 mb-2">基础设置</h3>
                <p className="text-sm text-gray-500 mb-4">配置考勤统计和自动标记相关的规则参数</p>
                <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">自动出勤标记时间</label>
                        <input
                            type="time"
                            value={settings.attendance_auto_mark_time || '08:30'}
                            onChange={e => setSettings({ ...settings, attendance_auto_mark_time: e.target.value })}
                            className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                        />
                        <p className="text-xs text-gray-400 mt-1">定时任务将未标记学生设为出勤</p>
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">旷课折算阈值</label>
                        <div className="flex items-center gap-2">
                            <input
                                type="number"
                                min="1"
                                value={settings.absent_lessons_as_day || 3}
                                onChange={e => setSettings({ ...settings, absent_lessons_as_day: parseInt(e.target.value) || 0 })}
                                className="w-20 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                            />
                            <span className="text-sm text-gray-500">节 = 1 天</span>
                        </div>
                        <p className="text-xs text-gray-400 mt-1">累计多少节旷课算1天</p>
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">请假折算阈值</label>
                        <div className="flex items-center gap-2">
                            <input
                                type="number"
                                min="1"
                                value={settings.leave_periods_as_day || 8}
                                onChange={e => setSettings({ ...settings, leave_periods_as_day: parseInt(e.target.value) || 8 })}
                                className="w-20 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                            />
                            <span className="text-sm text-gray-500">节 = 1 天</span>
                        </div>
                        <p className="text-xs text-gray-400 mt-1">请假多少节算1天</p>
                    </div>
                </div>
            </div>

            {/* 节次管理卡片 */}
            <div className="bg-white rounded-lg shadow p-6">
                <h3 className="text-lg font-medium text-gray-900 mb-2">节次管理</h3>
                <p className="text-sm text-gray-500 mb-4">配置每日课节，用于点名类型的"关联节次"选择</p>

                {/* 节次列表 */}
                <div className="bg-gray-50 rounded-lg border p-4 mb-4">
                    {attendancePeriods.length === 0 ? (
                        <p className="text-gray-400 text-sm text-center py-8">暂无节次配置，请点击下方按钮添加</p>
                    ) : (
                        <div className="space-y-2">
                            {attendancePeriods.map((period, index) => (
                                <div key={period.id} className="flex items-center gap-3 bg-white p-3 rounded-lg border hover:shadow-sm transition-shadow">
                                    <div className="flex flex-col gap-0.5">
                                        <button type="button" onClick={() => index > 0 && movePeriod(index, index - 1)} disabled={index === 0} className={`p-0.5 rounded text-xs ${index === 0 ? 'text-gray-200' : 'text-gray-400 hover:bg-gray-100'}`}>▲</button>
                                        <button type="button" onClick={() => index < attendancePeriods.length - 1 && movePeriod(index, index + 1)} disabled={index === attendancePeriods.length - 1} className={`p-0.5 rounded text-xs ${index === attendancePeriods.length - 1 ? 'text-gray-200' : 'text-gray-400 hover:bg-gray-100'}`}>▼</button>
                                    </div>
                                    <span className="text-xs text-gray-400 w-5 text-center">{index + 1}</span>
                                    <input type="text" value={period.name} onChange={(e) => updatePeriodName(period.id, e.target.value)} className="flex-1 px-3 py-1.5 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" placeholder="节次名称" />
                                    <span className={`text-xs px-2 py-1 rounded-full font-medium ${period.type === 'special' ? 'bg-orange-100 text-orange-700' : 'bg-blue-100 text-blue-700'}`}>{period.type === 'special' ? '特殊' : '普通'}</span>
                                    <button type="button" onClick={() => removePeriod(period.id)} className="text-gray-400 hover:text-red-600 p-1.5 hover:bg-red-50 rounded-lg transition-colors"><TrashIcon className="h-4 w-4" /></button>
                                </div>
                            ))}
                        </div>
                    )}
                </div>

                {/* 添加按钮 */}
                <div className="flex flex-wrap gap-3 items-center">
                    <button type="button" onClick={() => addPeriod('regular')} className="inline-flex items-center px-4 py-2 text-sm font-medium text-blue-700 bg-blue-50 rounded-lg hover:bg-blue-100 border border-blue-200">
                        <PlusIcon className="h-4 w-4 mr-1.5" />添加普通节次
                    </button>
                    <div className="flex items-center gap-2">
                        <input type="text" value={newPeriodName} onChange={(e) => setNewPeriodName(e.target.value)} placeholder="早操/晚操/午休..." className="px-3 py-2 text-sm border border-gray-300 rounded-lg w-36 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" onKeyDown={(e) => { if (e.key === 'Enter' && newPeriodName) { e.preventDefault(); addPeriod('special', newPeriodName); } }} />
                        <button type="button" onClick={() => newPeriodName && addPeriod('special', newPeriodName)} disabled={!newPeriodName} className={`inline-flex items-center px-4 py-2 text-sm font-medium rounded-lg border ${newPeriodName ? 'text-orange-700 bg-orange-50 hover:bg-orange-100 border-orange-200' : 'text-gray-400 bg-gray-50 border-gray-200 cursor-not-allowed'}`}>
                            <PlusIcon className="h-4 w-4 mr-1.5" />添加特殊节次
                        </button>
                    </div>
                </div>

                <div className="mt-4 p-3 bg-gray-50 rounded-lg">
                    <p className="text-sm text-gray-600">💡 普通节次自动命名为"第N节"，特殊节次可自定义名称（如早操、晚操等）</p>
                </div>
            </div>

            {/* 保存按钮 */}
            <div className="flex items-center gap-4">
                <button
                    onClick={handleSave}
                    disabled={saving}
                    className="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50"
                >
                    {saving ? '保存中...' : '保存设置'}
                </button>
                {message && (
                    <span className={`text-sm ${message.includes('失败') ? 'text-red-600' : 'text-green-600'}`}>
                        {message}
                    </span>
                )}
            </div>
        </div>
    );
}
