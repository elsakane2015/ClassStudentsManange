import React, { useEffect, useMemo, useState } from 'react';
import axios from 'axios';
import { CheckCircleIcon, EnvelopeIcon, ExclamationTriangleIcon } from '@heroicons/react/24/outline';
import { Link } from 'react-router-dom';
import Layout from '../../components/Layout';

export default function EmailNotificationSettings() {
    const [settings, setSettings] = useState({
        enabled: true,
        enabled_events: [],
        events: [],
        resend_ready: false,
        student_count: 0,
        missing_parent_email_count: 0,
    });
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [notice, setNotice] = useState(null);

    useEffect(() => {
        axios.get('/resend/teacher-settings')
            .then(response => setSettings(response.data))
            .catch(error => setNotice({ type: 'error', text: error.response?.data?.error || '读取配置失败' }))
            .finally(() => setLoading(false));
    }, []);

    const groupedEvents = useMemo(() => settings.events.reduce((groups, event) => {
        if (!groups[event.group]) groups[event.group] = [];
        groups[event.group].push(event);
        return groups;
    }, {}), [settings.events]);

    const toggleEvent = (eventKey) => {
        setSettings(prev => ({
            ...prev,
            enabled_events: prev.enabled_events.includes(eventKey)
                ? prev.enabled_events.filter(key => key !== eventKey)
                : [...prev.enabled_events, eventKey],
        }));
    };

    const handleSave = async () => {
        setSaving(true);
        setNotice(null);
        try {
            const response = await axios.post('/resend/teacher-settings', {
                enabled: settings.enabled,
                enabled_events: settings.enabled_events,
            });
            setNotice({ type: 'success', text: response.data.message });
        } catch (error) {
            setNotice({ type: 'error', text: error.response?.data?.error || '保存失败' });
        } finally {
            setSaving(false);
        }
    };

    if (loading) {
        return <Layout><div className="py-16 text-center text-gray-500">加载中...</div></Layout>;
    }

    return (
        <Layout>
            <div className="mx-auto max-w-4xl space-y-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <div className="flex items-center gap-2">
                            <EnvelopeIcon className="h-7 w-7 text-indigo-600" />
                            <h1 className="text-2xl font-semibold text-gray-900">家长邮件通知</h1>
                        </div>
                        <p className="mt-1 text-sm text-gray-500">选择需要自动发送给家长的请假和考勤事项。</p>
                    </div>
                    <button
                        type="button"
                        role="switch"
                        aria-checked={settings.enabled}
                        onClick={() => setSettings(prev => ({ ...prev, enabled: !prev.enabled }))}
                        className={`relative inline-flex h-7 w-12 shrink-0 rounded-full transition-colors ${settings.enabled ? 'bg-indigo-600' : 'bg-gray-200'}`}
                    >
                        <span className={`inline-block h-6 w-6 translate-y-0.5 rounded-full bg-white shadow transition-transform ${settings.enabled ? 'translate-x-5' : 'translate-x-0.5'}`} />
                    </button>
                </div>

                {!settings.resend_ready && (
                    <div className="flex items-start gap-3 rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-800">
                        <ExclamationTriangleIcon className="mt-0.5 h-5 w-5 shrink-0" />
                        <span>系统管理员尚未完成 Resend 配置。您可以先保存通知规则，系统启用后会自动生效。</span>
                    </div>
                )}

                {settings.missing_parent_email_count > 0 && (
                    <div className="flex items-start justify-between gap-4 rounded-md bg-blue-50 px-4 py-3 text-sm text-blue-800">
                        <span>
                            当前 {settings.student_count} 名学生中有 {settings.missing_parent_email_count} 名未填写家长邮箱，这些学生不会收到邮件通知。
                        </span>
                        <Link to="/teacher/students" className="shrink-0 font-medium text-blue-700 hover:text-blue-900">去补充</Link>
                    </div>
                )}

                {notice && (
                    <div className={`flex items-center gap-2 rounded-md px-4 py-3 text-sm ${notice.type === 'success' ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'}`}>
                        {notice.type === 'success' && <CheckCircleIcon className="h-5 w-5" />}
                        {notice.text}
                    </div>
                )}

                <div className={`divide-y divide-gray-200 border-y border-gray-200 ${settings.enabled ? '' : 'pointer-events-none opacity-50'}`}>
                    {Object.entries(groupedEvents).map(([group, events]) => (
                        <section key={group} className="py-6">
                            <h2 className="text-sm font-semibold text-gray-900">{group}</h2>
                            <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                                {events.map(event => (
                                    <label key={event.key} className="flex cursor-pointer items-center gap-3 rounded-md border border-gray-200 px-4 py-3 hover:bg-gray-50">
                                        <input
                                            type="checkbox"
                                            checked={settings.enabled_events.includes(event.key)}
                                            onChange={() => toggleEvent(event.key)}
                                            className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                        />
                                        <span className="text-sm font-medium text-gray-800">{event.label}</span>
                                    </label>
                                ))}
                            </div>
                        </section>
                    ))}
                </div>

                <div className="flex justify-end">
                    <button
                        type="button"
                        onClick={handleSave}
                        disabled={saving}
                        className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 disabled:opacity-50"
                    >
                        {saving ? '保存中...' : '保存通知规则'}
                    </button>
                </div>
            </div>
        </Layout>
    );
}
