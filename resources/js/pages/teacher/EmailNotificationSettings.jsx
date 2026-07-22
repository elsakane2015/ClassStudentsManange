import React, { useEffect, useMemo, useState } from 'react';
import axios from 'axios';
import { ArrowTopRightOnSquareIcon, CheckCircleIcon, DevicePhoneMobileIcon, EnvelopeIcon, ExclamationTriangleIcon, PaperAirplaneIcon, TrashIcon } from '@heroicons/react/24/outline';
import { Link } from 'react-router-dom';
import Layout from '../../components/Layout';

const defaultSettings = {
    enabled: true,
    email_enabled: true,
    email_provider: 'system_resend',
    email_fallback_to_resend: false,
    sms_enabled: false,
    enabled_events: [],
    events: [],
    resend_ready: false,
    email_ready: false,
    personal_email_ready: false,
    personal_email_account: null,
    personal_email_providers: [],
    email_logs: [],
    sms_ready: false,
    student_count: 0,
    missing_parent_email_count: 0,
    missing_parent_phone_count: 0,
};

function normalizeSettings(data = {}) {
    return {
        ...defaultSettings,
        ...data,
        enabled_events: Array.isArray(data.enabled_events) ? data.enabled_events : [],
        events: Array.isArray(data.events) ? data.events : [],
        personal_email_providers: Array.isArray(data.personal_email_providers) ? data.personal_email_providers : [],
        email_logs: Array.isArray(data.email_logs) ? data.email_logs : [],
    };
}

export default function EmailNotificationSettings() {
    const [settings, setSettings] = useState(defaultSettings);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [accountBusy, setAccountBusy] = useState(false);
    const [notice, setNotice] = useState(null);
    const [personalProvider, setPersonalProvider] = useState('qq');
    const [accountForm, setAccountForm] = useState({ email: '', from_name: '', authorization_code: '' });

    useEffect(() => {
        axios.get('/resend/teacher-settings')
            .then(response => {
                setSettings(normalizeSettings(response.data));
                const account = response.data.personal_email_account;
                if (account?.provider) {
                    setPersonalProvider(account.provider);
                    setAccountForm({
                        email: account.email || '',
                        from_name: account.from_name || '',
                        authorization_code: '',
                    });
                }
                const connection = new URLSearchParams(window.location.search).get('email_connection');
                if (connection === 'success') setNotice({ type: 'success', text: 'Microsoft 邮箱连接成功' });
                if (connection === 'error') setNotice({ type: 'error', text: 'Microsoft 邮箱连接失败，请重试' });
            })
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
                email_provider: settings.email_provider,
                email_fallback_to_resend: settings.email_fallback_to_resend,
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

    const updateAccountState = (account) => {
        setSettings(prev => ({
            ...prev,
            personal_email_account: account || null,
            personal_email_ready: Boolean(account?.ready),
            email_ready: prev.email_provider === 'personal_email'
                ? Boolean(account?.ready) || (prev.email_fallback_to_resend && prev.resend_ready)
                : prev.resend_ready,
        }));
        if (account?.provider) {
            setPersonalProvider(account.provider);
            setAccountForm({
                email: account.email || '',
                from_name: account.from_name || '',
                authorization_code: '',
            });
        }
    };

    const savePersonalAccount = async () => {
        setAccountBusy(true);
        setNotice(null);
        try {
            const response = await axios.post('/teacher-email/account', {
                provider: personalProvider,
                ...accountForm,
            });
            updateAccountState(response.data.account);
            setNotice({ type: 'success', text: response.data.message });
        } catch (error) {
            const validation = error.response?.data?.errors;
            const detail = validation ? Object.values(validation).flat()[0] : null;
            setNotice({ type: 'error', text: detail || error.response?.data?.error || error.response?.data?.message || '个人邮箱保存失败' });
        } finally {
            setAccountBusy(false);
        }
    };

    const testPersonalAccount = async () => {
        setAccountBusy(true);
        setNotice(null);
        try {
            const response = await axios.post('/teacher-email/account/test');
            updateAccountState(response.data.account);
            setNotice({ type: 'success', text: response.data.message });
        } catch (error) {
            setNotice({ type: 'error', text: error.response?.data?.error || '测试邮件发送失败' });
        } finally {
            setAccountBusy(false);
        }
    };

    const disconnectPersonalAccount = async () => {
        if (!window.confirm('确定删除当前个人邮箱连接吗？')) return;
        setAccountBusy(true);
        try {
            const response = await axios.delete('/teacher-email/account');
            setSettings(prev => ({
                ...prev,
                personal_email_account: null,
                personal_email_ready: false,
                email_ready: prev.email_provider === 'personal_email'
                    ? prev.email_fallback_to_resend && prev.resend_ready
                    : prev.resend_ready,
            }));
            setPersonalProvider('qq');
            setAccountForm({ email: '', from_name: '', authorization_code: '' });
            setNotice({ type: 'success', text: response.data.message });
        } catch (error) {
            setNotice({ type: 'error', text: error.response?.data?.error || '删除个人邮箱连接失败' });
        } finally {
            setAccountBusy(false);
        }
    };

    const connectMicrosoft = async () => {
        setAccountBusy(true);
        setNotice(null);
        try {
            const response = await axios.post('/teacher-email/microsoft/connect');
            window.location.assign(response.data.authorization_url);
        } catch (error) {
            setNotice({ type: 'error', text: error.response?.data?.message || error.response?.data?.error || 'Microsoft 邮箱连接失败' });
            setAccountBusy(false);
        }
    };

    const retryEmail = async (logId) => {
        setAccountBusy(true);
        setNotice(null);
        try {
            const response = await axios.post(`/teacher-email/logs/${logId}/retry`);
            const refreshed = await axios.get('/resend/teacher-settings');
            setSettings(normalizeSettings(refreshed.data));
            setNotice({ type: 'success', text: response.data.message });
        } catch (error) {
            setNotice({ type: 'error', text: error.response?.data?.error || '重新发送失败' });
        } finally {
            setAccountBusy(false);
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

                {settings.email_enabled && !settings.email_ready && (
                    <div className="flex items-start gap-3 rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-800">
                        <ExclamationTriangleIcon className="mt-0.5 h-5 w-5 shrink-0" />
                        <span>{settings.email_provider === 'personal_email' ? '个人邮箱尚未连接并通过测试，邮件通道暂不可用。' : '系统管理员尚未完成 Resend 配置，邮件通道暂不可用。'}</span>
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
                            ready={settings.email_ready}
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

                {settings.enabled && settings.email_enabled && (
                    <section className="border-b border-gray-200 pb-6">
                        <h2 className="text-sm font-semibold text-gray-900">邮件发送账户</h2>
                        <div className="mt-3 inline-flex rounded-md border border-gray-300 bg-white p-1">
                            <button
                                type="button"
                                onClick={() => setSettings(prev => ({ ...prev, email_provider: 'system_resend', email_ready: prev.resend_ready }))}
                                className={`rounded px-3 py-1.5 text-sm ${settings.email_provider === 'system_resend' ? 'bg-indigo-600 text-white' : 'text-gray-600'}`}
                            >系统 Resend</button>
                            <button
                                type="button"
                                onClick={() => setSettings(prev => ({
                                    ...prev,
                                    email_provider: 'personal_email',
                                    email_ready: prev.personal_email_ready || (prev.email_fallback_to_resend && prev.resend_ready),
                                }))}
                                className={`rounded px-3 py-1.5 text-sm ${settings.email_provider === 'personal_email' ? 'bg-indigo-600 text-white' : 'text-gray-600'}`}
                            >个人邮箱</button>
                        </div>

                        {settings.email_provider === 'system_resend' ? (
                            <div className="mt-4 flex items-center gap-2 text-sm text-gray-600">
                                <span className={`h-2.5 w-2.5 rounded-full ${settings.resend_ready ? 'bg-green-500' : 'bg-amber-500'}`} />
                                {settings.resend_ready ? '系统 Resend 已配置，由学校统一发信。' : '系统 Resend 尚未配置。'}
                            </div>
                        ) : (
                            <div className="mt-5 space-y-5">
                                <div>
                                    <label className="block text-sm font-medium text-gray-700">邮箱服务商</label>
                                    {settings.personal_email_providers.length > 0 ? (
                                        <select
                                            value={personalProvider}
                                            onChange={event => {
                                                setPersonalProvider(event.target.value);
                                                setAccountForm({ email: '', from_name: '', authorization_code: '' });
                                            }}
                                            className="mt-1 block w-full rounded-md border-gray-300 sm:max-w-md"
                                        >
                                            {settings.personal_email_providers.map(provider => (
                                                <option key={provider.key} value={provider.key} disabled={provider.available === false}>{provider.label}{provider.available === false ? '（系统未配置）' : ''}</option>
                                            ))}
                                        </select>
                                    ) : (
                                        <div className="mt-1 rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-800">
                                            暂时无法读取个人邮箱服务商，请刷新页面后重试。
                                        </div>
                                    )}
                                </div>

                                {personalProvider === 'microsoft' ? (
                                    <div className="flex flex-col items-start gap-3">
                                        {settings.personal_email_account?.provider === 'microsoft' && (
                                            <div className="text-sm text-gray-700">
                                                已连接：<span className="font-medium">{settings.personal_email_account.email}</span>
                                                <span className={`ml-2 ${settings.personal_email_account.ready ? 'text-green-700' : 'text-amber-700'}`}>{settings.personal_email_account.ready ? '可用' : '需要重新连接'}</span>
                                            </div>
                                        )}
                                        <button type="button" onClick={connectMicrosoft} disabled={accountBusy} className="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50">
                                            <ArrowTopRightOnSquareIcon className="h-4 w-4" />连接 Microsoft 邮箱
                                        </button>
                                        <p className="text-xs text-gray-500">将跳转到 Microsoft 完成 OAuth 授权，系统不会获取或保存邮箱密码。</p>
                                    </div>
                                ) : (
                                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        <label className="text-sm font-medium text-gray-700">邮箱地址
                                            <input type="email" value={accountForm.email} onChange={event => setAccountForm(prev => ({ ...prev, email: event.target.value }))} className="mt-1 block w-full rounded-md border-gray-300" placeholder={personalProvider === 'qq' ? 'example@qq.com' : personalProvider === 'netease_163' ? 'example@163.com' : 'example@126.com'} />
                                        </label>
                                        <label className="text-sm font-medium text-gray-700">发件人名称
                                            <input type="text" value={accountForm.from_name} onChange={event => setAccountForm(prev => ({ ...prev, from_name: event.target.value }))} className="mt-1 block w-full rounded-md border-gray-300" placeholder="例如：王老师" />
                                        </label>
                                        <label className="text-sm font-medium text-gray-700 sm:col-span-2">客户端授权码
                                            <input type="password" value={accountForm.authorization_code} onChange={event => setAccountForm(prev => ({ ...prev, authorization_code: event.target.value }))} className="mt-1 block w-full rounded-md border-gray-300 sm:max-w-md" placeholder={settings.personal_email_account?.credential_configured ? '已保存；留空表示不修改' : '不是邮箱登录密码'} autoComplete="new-password" />
                                        </label>
                                    </div>
                                )}

                                {personalProvider !== 'microsoft' && (
                                    <div className="rounded-md bg-blue-50 px-4 py-3 text-sm text-blue-800">
                                        请先在邮箱网页设置中开启 SMTP 服务并生成客户端授权码。系统固定使用服务商官方 SMTP 地址，不支持自定义服务器。
                                    </div>
                                )}

                                <div className="flex flex-wrap gap-3">
                                    {personalProvider !== 'microsoft' && <button type="button" onClick={savePersonalAccount} disabled={accountBusy} className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50">保存邮箱配置</button>}
                                    {settings.personal_email_account?.provider === personalProvider && <button type="button" onClick={testPersonalAccount} disabled={accountBusy} className="inline-flex items-center gap-2 rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 disabled:opacity-50"><PaperAirplaneIcon className="h-4 w-4" />发送测试邮件</button>}
                                    {settings.personal_email_account?.provider && <button type="button" onClick={disconnectPersonalAccount} disabled={accountBusy} className="inline-flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium text-red-600 disabled:opacity-50"><TrashIcon className="h-4 w-4" />删除连接</button>}
                                </div>

                                <label className="flex items-center gap-3 text-sm text-gray-700">
                                    <input type="checkbox" checked={settings.email_fallback_to_resend} onChange={event => setSettings(prev => ({
                                        ...prev,
                                        email_fallback_to_resend: event.target.checked,
                                        email_ready: prev.personal_email_ready || (event.target.checked && prev.resend_ready),
                                    }))} className="h-4 w-4 rounded border-gray-300 text-indigo-600" />
                                    个人邮箱发送失败时，使用系统 Resend 重试
                                </label>
                            </div>
                        )}
                    </section>
                )}

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

                {settings.email_logs.length > 0 && (
                    <section className="border-b border-gray-200 pb-6">
                        <h2 className="text-sm font-semibold text-gray-900">最近邮件发送记录</h2>
                        <div className="mt-3 overflow-x-auto border-y border-gray-200">
                            <table className="min-w-full divide-y divide-gray-200 text-sm">
                                <thead className="bg-gray-50 text-left text-xs text-gray-500">
                                    <tr><th className="px-3 py-2 font-medium">时间</th><th className="px-3 py-2 font-medium">收件人</th><th className="px-3 py-2 font-medium">主题</th><th className="px-3 py-2 font-medium">通道</th><th className="px-3 py-2 font-medium">状态</th><th className="px-3 py-2 font-medium"><span className="sr-only">操作</span></th></tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100 bg-white">
                                    {settings.email_logs.map(log => (
                                        <tr key={log.id}>
                                            <td className="whitespace-nowrap px-3 py-2 text-gray-500">{log.created_at}</td>
                                            <td className="whitespace-nowrap px-3 py-2 text-gray-700">{log.recipient}</td>
                                            <td className="max-w-xs truncate px-3 py-2 text-gray-700" title={log.subject}>{log.subject}</td>
                                            <td className="whitespace-nowrap px-3 py-2 text-gray-500">{providerLabel(log.provider)}{log.fallback_used ? '（兜底）' : ''}</td>
                                            <td className="px-3 py-2">
                                                <span className={log.status === 'success' ? 'text-green-700' : log.status === 'failed' ? 'text-red-700' : 'text-amber-700'}>{log.status === 'success' ? '成功' : log.status === 'failed' ? '失败' : '发送中'}</span>
                                                {log.status === 'failed' && log.error_message && <p className="mt-1 max-w-xs truncate text-xs text-red-600" title={log.error_message}>{log.error_message}</p>}
                                            </td>
                                            <td className="whitespace-nowrap px-3 py-2 text-right">{log.status === 'failed' && <button type="button" disabled={accountBusy} onClick={() => retryEmail(log.id)} className="text-indigo-600 hover:text-indigo-800 disabled:opacity-50">重试</button>}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                )}

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

function providerLabel(provider) {
    return {
        system_resend: '系统 Resend',
        qq: 'QQ 邮箱',
        netease_163: '网易 163',
        netease_126: '网易 126',
        microsoft: 'Microsoft',
        personal_email: '个人邮箱',
    }[provider] || provider;
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
