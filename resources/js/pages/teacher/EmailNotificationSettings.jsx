import React, { useEffect, useMemo, useState } from 'react';
import axios from 'axios';
import { CheckCircleIcon, DevicePhoneMobileIcon, EnvelopeIcon, ExclamationTriangleIcon } from '@heroicons/react/24/outline';
import { Link } from 'react-router-dom';
import Layout from '../../components/Layout';

export default function EmailNotificationSettings() {
    const [settings, setSettings] = useState({
        enabled: true,
        email_enabled: true,
        sms_enabled: false,
        enabled_events: [],
        events: [],
        resend_ready: false,
        sms_ready: false,
        student_count: 0,
        missing_parent_email_count: 0,
        missing_parent_phone_count: 0,
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
                email_enabled: settings.email_enabled,
                sms_enabled: settings.sms_enabled,
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
                            <h1 className="text-2xl font-semibold text-gray-900">家长通知</h1>
                        </div>
                        <p className="mt-1 text-sm text-gray-500">选择通知通道以及需要发送的请假和考勤事项。</p>
                    </div>
                    <button
                        type="button"
                        role="switch"
                        aria-label="家长通知总开关"
                        aria-checked={settings.enabled}
                        onClick={() => setSettings(prev => ({ ...prev, enabled: !prev.enabled }))}
                        className={`relative inline-flex h-7 w-12 shrink-0 rounded-full transition-colors ${settings.enabled ? 'bg-indigo-600' : 'bg-gray-200'}`}
                    >
                        <span className={`inline-block h-6 w-6 translate-y-0.5 rounded-full bg-white shadow transition-transform ${settings.enabled ? 'translate-x-5' : 'translate-x-0.5'}`} />
                    </button>
                </div>

                {settings.email_enabled && !settings.resend_ready && (
                    <div className="flex items-start gap-3 rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-800">
                        <ExclamationTriangleIcon className="mt-0.5 h-5 w-5 shrink-0" />
                        <span>系统管理员尚未完成 Resend 配置，邮件通道暂不可用。</span>
                    </div>
                )}

                {settings.sms_enabled && !settings.sms_ready && (
                    <div className="flex items-start gap-3 rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-800">
                        <ExclamationTriangleIcon className="mt-0.5 h-5 w-5 shrink-0" />
                        <span>系统管理员尚未完成短信服务配置，短信通道暂不可用。</span>
                    </div>
                )}

                {(settings.missing_parent_email_count > 0 || settings.missing_parent_phone_count > 0) && (
                    <div className="flex items-start justify-between gap-4 rounded-md bg-blue-50 px-4 py-3 text-sm text-blue-800">
                        <span>
                            当前 {settings.student_count} 名学生中，{settings.missing_parent_email_count} 名缺少家长邮箱，{settings.missing_parent_phone_count} 名缺少有效家长手机号。
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

                <section className={`border-y border-gray-200 py-6 ${settings.enabled ? '' : 'pointer-events-none opacity-50'}`}>
                    <h2 className="text-sm font-semibold text-gray-900">发送方式</h2>
                    <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <ChannelToggle
                            label="邮件"
                            description="发送到家长邮箱"
                            enabled={settings.email_enabled}
                            ready={settings.resend_ready}
                            icon={EnvelopeIcon}
                            onChange={() => setSettings(prev => ({ ...prev, email_enabled: !prev.email_enabled }))}
                        />
                        <ChannelToggle
                            label="短信"
                            description="发送到家长手机号"
                            enabled={settings.sms_enabled}
                            ready={settings.sms_ready}
                            icon={DevicePhoneMobileIcon}
                            onChange={() => setSettings(prev => ({ ...prev, sms_enabled: !prev.sms_enabled }))}
                        />
                    </div>
                </section>

                <div className={`divide-y divide-gray-200 border-b border-gray-200 ${settings.enabled ? '' : 'pointer-events-none opacity-50'}`}>
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

function ChannelToggle({ label, description, enabled, ready, icon: Icon, onChange }) {
    return (
        <div className="flex items-center gap-3 rounded-md border border-gray-200 px-4 py-3">
            <Icon className="h-5 w-5 shrink-0 text-gray-500" />
            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2">
                    <span className="text-sm font-medium text-gray-900">{label}</span>
                    <span className={`text-xs ${ready ? 'text-green-700' : 'text-amber-700'}`}>{ready ? '可用' : '未配置'}</span>
                </div>
                <p className="text-xs text-gray-500">{description}</p>
            </div>
            <button
                type="button"
                role="switch"
                aria-label={`${label}通知开关`}
                aria-checked={enabled}
                onClick={onChange}
                className={`relative inline-flex h-6 w-11 shrink-0 rounded-full transition-colors ${enabled ? 'bg-indigo-600' : 'bg-gray-200'}`}
            >
                <span className={`inline-block h-5 w-5 translate-y-0.5 rounded-full bg-white shadow transition-transform ${enabled ? 'translate-x-5' : 'translate-x-0.5'}`} />
            </button>
        </div>
    );
}
