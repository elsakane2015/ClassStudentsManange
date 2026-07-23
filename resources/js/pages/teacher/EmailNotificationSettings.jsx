import React, { useEffect, useMemo, useState } from 'react';
import axios from 'axios';
import { ArrowTopRightOnSquareIcon, CheckCircleIcon, DevicePhoneMobileIcon, EnvelopeIcon, ExclamationTriangleIcon, PaperAirplaneIcon, TrashIcon, XMarkIcon } from '@heroicons/react/24/outline';
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

const defaultEmailLogs = {
    data: [],
    meta: { current_page: 1, last_page: 1, per_page: 10, total: 0, from: null, to: null },
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
    const [emailLogs, setEmailLogs] = useState(defaultEmailLogs);
    const [selectedLogIds, setSelectedLogIds] = useState([]);
    const [logsLoading, setLogsLoading] = useState(true);
    const [logsBusy, setLogsBusy] = useState(false);
    const [logDetail, setLogDetail] = useState(null);
    const [detailLoading, setDetailLoading] = useState(false);

    const loadEmailLogs = async (page = 1, perPage = emailLogs.meta.per_page) => {
        setLogsLoading(true);
        try {
            const response = await axios.get('/teacher-email/logs', { params: { page, per_page: perPage } });
            setEmailLogs({
                data: Array.isArray(response.data.data) ? response.data.data : [],
                meta: { ...defaultEmailLogs.meta, ...(response.data.meta || {}) },
            });
            setSelectedLogIds([]);
        } catch (error) {
            setNotice({ type: 'error', text: error.response?.data?.message || '读取邮件发送记录失败' });
        } finally {
            setLogsLoading(false);
        }
    };

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
        loadEmailLogs(1, 10);
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

    const retryEmail = async (logId, event = null) => {
        event?.stopPropagation();
        setLogsBusy(true);
        setNotice(null);
        try {
            const response = await axios.post(`/teacher-email/logs/${logId}/retry`);
            await loadEmailLogs(emailLogs.meta.current_page, emailLogs.meta.per_page);
            setNotice({ type: 'success', text: response.data.message });
        } catch (error) {
            setNotice({ type: 'error', text: error.response?.data?.error || '重新发送失败' });
        } finally {
            setLogsBusy(false);
        }
    };

    const openLogDetail = async (logId) => {
        setDetailLoading(true);
        setLogDetail({ id: logId });
        try {
            const response = await axios.get(`/teacher-email/logs/${logId}`);
            setLogDetail(response.data);
        } catch (error) {
            setLogDetail(null);
            setNotice({ type: 'error', text: error.response?.data?.message || '读取邮件详情失败' });
        } finally {
            setDetailLoading(false);
        }
    };

    const deleteEmailLog = async (logId, event = null) => {
        event?.stopPropagation();
        if (!window.confirm('确定删除这条邮件发送记录吗？')) return;
        setLogsBusy(true);
        try {
            const response = await axios.delete(`/teacher-email/logs/${logId}`);
            const targetPage = emailLogs.data.length === 1 && emailLogs.meta.current_page > 1
                ? emailLogs.meta.current_page - 1
                : emailLogs.meta.current_page;
            await loadEmailLogs(targetPage, emailLogs.meta.per_page);
            if (logDetail?.id === logId) setLogDetail(null);
            setNotice({ type: 'success', text: response.data.message });
        } catch (error) {
            setNotice({ type: 'error', text: error.response?.data?.message || '删除邮件发送记录失败' });
        } finally {
            setLogsBusy(false);
        }
    };

    const bulkDeleteEmailLogs = async () => {
        if (selectedLogIds.length === 0) return;
        if (!window.confirm(`确定删除选中的 ${selectedLogIds.length} 条邮件发送记录吗？`)) return;
        setLogsBusy(true);
        try {
            const response = await axios.delete('/teacher-email/logs', { data: { ids: selectedLogIds } });
            const targetPage = selectedLogIds.length === emailLogs.data.length && emailLogs.meta.current_page > 1
                ? emailLogs.meta.current_page - 1
                : emailLogs.meta.current_page;
            await loadEmailLogs(targetPage, emailLogs.meta.per_page);
            setNotice({ type: 'success', text: response.data.message });
        } catch (error) {
            setNotice({ type: 'error', text: error.response?.data?.message || '批量删除邮件发送记录失败' });
        } finally {
            setLogsBusy(false);
        }
    };

    const toggleLogSelection = (logId) => {
        setSelectedLogIds(prev => prev.includes(logId) ? prev.filter(id => id !== logId) : [...prev, logId]);
    };

    const allVisibleLogsSelected = emailLogs.data.length > 0
        && emailLogs.data.every(log => selectedLogIds.includes(log.id));

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
                                            <input type="email" value={accountForm.email} onChange={event => setAccountForm(prev => ({ ...prev, email: event.target.value }))} className="mt-1 block w-full rounded-md border-gray-300" placeholder={emailPlaceholder(personalProvider)} />
                                        </label>
                                        <label className="text-sm font-medium text-gray-700">发件人名称
                                            <input type="text" value={accountForm.from_name} onChange={event => setAccountForm(prev => ({ ...prev, from_name: event.target.value }))} className="mt-1 block w-full rounded-md border-gray-300" placeholder="例如：王老师" />
                                        </label>
                                        <label className="text-sm font-medium text-gray-700 sm:col-span-2">客户端授权码/专用密码
                                            <input type="password" value={accountForm.authorization_code} onChange={event => setAccountForm(prev => ({ ...prev, authorization_code: event.target.value }))} className="mt-1 block w-full rounded-md border-gray-300 sm:max-w-md" placeholder={settings.personal_email_account?.credential_configured ? '已保存；留空表示不修改' : '不是邮箱登录密码'} autoComplete="new-password" />
                                        </label>
                                    </div>
                                )}

                                {personalProvider !== 'microsoft' && (
                                    <div className="rounded-md bg-blue-50 px-4 py-3 text-sm text-blue-800">
                                        {personalProvider === 'tencent_exmail'
                                            ? '请填写完整企业邮箱地址，并在腾讯企业邮箱安全设置中生成客户端专用密码。系统固定使用 smtp.exmail.qq.com:465。'
                                            : '请先在邮箱网页设置中开启 SMTP 服务并生成客户端授权码。系统固定使用服务商官方 SMTP 地址，不支持自定义服务器。'}
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

                <section className="border-b border-gray-200 pb-6">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 className="text-sm font-semibold text-gray-900">邮件发送记录</h2>
                            <p className="mt-1 text-xs text-gray-500">共 {emailLogs.meta.total} 条，点击记录可查看邮件详情。</p>
                        </div>
                        <button
                            type="button"
                            onClick={bulkDeleteEmailLogs}
                            disabled={selectedLogIds.length === 0 || logsBusy}
                            className="inline-flex items-center gap-1.5 rounded-md px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-40"
                        >
                            <TrashIcon className="h-4 w-4" />删除选中{selectedLogIds.length > 0 ? `（${selectedLogIds.length}）` : ''}
                        </button>
                    </div>
                    <div className="mt-3 overflow-x-auto border-y border-gray-200">
                        {logsLoading ? (
                            <div className="py-10 text-center text-sm text-gray-500">正在读取发送记录...</div>
                        ) : emailLogs.data.length === 0 ? (
                            <div className="py-10 text-center text-sm text-gray-500">暂无邮件发送记录</div>
                        ) : (
                            <table className="min-w-full divide-y divide-gray-200 text-sm">
                                <thead className="bg-gray-50 text-left text-xs text-gray-500">
                                    <tr>
                                        <th className="w-10 px-3 py-2 font-medium">
                                            <input
                                                type="checkbox"
                                                aria-label="选择当前页全部邮件记录"
                                                checked={allVisibleLogsSelected}
                                                onChange={() => setSelectedLogIds(allVisibleLogsSelected ? [] : emailLogs.data.map(log => log.id))}
                                                className="h-4 w-4 rounded border-gray-300 text-indigo-600"
                                            />
                                        </th>
                                        <th className="px-3 py-2 font-medium">时间</th>
                                        <th className="px-3 py-2 font-medium">收件人</th>
                                        <th className="px-3 py-2 font-medium">主题</th>
                                        <th className="px-3 py-2 font-medium">通道</th>
                                        <th className="px-3 py-2 font-medium">状态</th>
                                        <th className="px-3 py-2 font-medium"><span className="sr-only">操作</span></th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100 bg-white">
                                    {emailLogs.data.map(log => (
                                        <tr
                                            key={log.id}
                                            tabIndex={0}
                                            onClick={() => openLogDetail(log.id)}
                                            onKeyDown={event => {
                                                if (event.key === 'Enter' || event.key === ' ') openLogDetail(log.id);
                                            }}
                                            className="cursor-pointer hover:bg-gray-50 focus:bg-indigo-50 focus:outline-none"
                                        >
                                            <td className="px-3 py-2" onClick={event => event.stopPropagation()}>
                                                <input
                                                    type="checkbox"
                                                    aria-label={`选择邮件记录 ${log.subject}`}
                                                    checked={selectedLogIds.includes(log.id)}
                                                    onChange={() => toggleLogSelection(log.id)}
                                                    className="h-4 w-4 rounded border-gray-300 text-indigo-600"
                                                />
                                            </td>
                                            <td className="whitespace-nowrap px-3 py-2 text-gray-500">{log.created_at}</td>
                                            <td className="whitespace-nowrap px-3 py-2 text-gray-700">{log.recipient}</td>
                                            <td className="max-w-xs truncate px-3 py-2 text-gray-700" title={log.subject}>{log.subject}</td>
                                            <td className="whitespace-nowrap px-3 py-2 text-gray-500">{providerLabel(log.provider)}{log.fallback_used ? '（兜底）' : ''}</td>
                                            <td className="px-3 py-2">
                                                <span className={log.status === 'success' ? 'text-green-700' : log.status === 'failed' ? 'text-red-700' : 'text-amber-700'}>{log.status === 'success' ? '成功' : log.status === 'failed' ? '失败' : '发送中'}</span>
                                                {log.status === 'failed' && log.error_message && <p className="mt-1 max-w-xs truncate text-xs text-red-600" title={log.error_message}>{log.error_message}</p>}
                                            </td>
                                            <td className="whitespace-nowrap px-3 py-2 text-right" onClick={event => event.stopPropagation()}>
                                                <div className="flex items-center justify-end gap-2">
                                                    {log.status === 'failed' && <button type="button" disabled={logsBusy} onClick={event => retryEmail(log.id, event)} className="text-indigo-600 hover:text-indigo-800 disabled:opacity-50">重试</button>}
                                                    <button type="button" title="删除记录" aria-label="删除记录" disabled={logsBusy} onClick={event => deleteEmailLog(log.id, event)} className="rounded p-1 text-red-500 hover:bg-red-50 hover:text-red-700 disabled:opacity-50"><TrashIcon className="h-4 w-4" /></button>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </div>
                    {emailLogs.meta.total > 0 && (
                        <div className="mt-3 flex flex-col gap-3 text-sm text-gray-600 sm:flex-row sm:items-center sm:justify-between">
                            <div className="flex items-center gap-2">
                                <span>每页</span>
                                <select
                                    aria-label="每页邮件记录数量"
                                    value={emailLogs.meta.per_page}
                                    onChange={event => loadEmailLogs(1, Number(event.target.value))}
                                    className="rounded-md border-gray-300 py-1 pl-2 pr-7 text-sm"
                                >
                                    <option value={10}>10</option>
                                    <option value={20}>20</option>
                                    <option value={50}>50</option>
                                </select>
                                <span>条</span>
                            </div>
                            <div className="flex items-center gap-3">
                                <span>第 {emailLogs.meta.current_page}/{emailLogs.meta.last_page} 页</span>
                                <div className="inline-flex rounded-md border border-gray-300 bg-white">
                                    <button type="button" disabled={logsLoading || emailLogs.meta.current_page <= 1} onClick={() => loadEmailLogs(emailLogs.meta.current_page - 1, emailLogs.meta.per_page)} className="px-3 py-1.5 disabled:opacity-40">上一页</button>
                                    <button type="button" disabled={logsLoading || emailLogs.meta.current_page >= emailLogs.meta.last_page} onClick={() => loadEmailLogs(emailLogs.meta.current_page + 1, emailLogs.meta.per_page)} className="border-l border-gray-300 px-3 py-1.5 disabled:opacity-40">下一页</button>
                                </div>
                            </div>
                        </div>
                    )}
                </section>

                {logDetail && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onMouseDown={() => setLogDetail(null)}>
                        <div className="max-h-[90vh] w-full max-w-3xl overflow-y-auto rounded-md bg-white shadow-xl" onMouseDown={event => event.stopPropagation()}>
                            <div className="sticky top-0 flex items-center justify-between border-b border-gray-200 bg-white px-5 py-4">
                                <h2 className="text-lg font-semibold text-gray-900">邮件发送详情</h2>
                                <button type="button" title="关闭" aria-label="关闭邮件详情" onClick={() => setLogDetail(null)} className="rounded p-1 text-gray-500 hover:bg-gray-100"><XMarkIcon className="h-5 w-5" /></button>
                            </div>
                            {detailLoading ? (
                                <div className="py-16 text-center text-sm text-gray-500">正在读取邮件详情...</div>
                            ) : (
                                <div className="space-y-5 p-5">
                                    <dl className="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                                        <DetailItem label="发送时间" value={logDetail.created_at} />
                                        <DetailItem label="状态" value={statusLabel(logDetail.status)} />
                                        <DetailItem label="收件人" value={logDetail.recipient} />
                                        <DetailItem label="发件人" value={logDetail.sender_address || '-'} />
                                        <DetailItem label="发送通道" value={`${providerLabel(logDetail.provider)}${logDetail.fallback_used ? '（兜底）' : ''}`} />
                                        <DetailItem label="尝试次数" value={logDetail.attempt_count || 1} />
                                        <div className="sm:col-span-2"><DetailItem label="主题" value={logDetail.subject} /></div>
                                        {logDetail.error_message && <div className="sm:col-span-2"><DetailItem label="失败原因" value={logDetail.error_message} valueClassName="text-red-700" /></div>}
                                    </dl>
                                    <div>
                                        <h3 className="mb-2 text-sm font-medium text-gray-700">邮件正文</h3>
                                        {logDetail.html ? (
                                            <iframe title="邮件正文预览" srcDoc={logDetail.html} sandbox="" className="h-80 w-full rounded-md border border-gray-200 bg-white" />
                                        ) : (
                                            <div className="rounded-md border border-gray-200 px-4 py-8 text-center text-sm text-gray-500">该历史记录没有可预览的正文</div>
                                        )}
                                    </div>
                                    <div className="flex justify-end gap-3">
                                        <button type="button" onClick={() => setLogDetail(null)} className="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700">关闭</button>
                                        <button type="button" onClick={event => deleteEmailLog(logDetail.id, event)} disabled={logsBusy} className="inline-flex items-center gap-2 rounded-md px-4 py-2 text-sm font-medium text-red-600 hover:bg-red-50 disabled:opacity-50"><TrashIcon className="h-4 w-4" />删除记录</button>
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>
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
        tencent_exmail: '腾讯企业邮箱',
        netease_163: '网易 163',
        netease_126: '网易 126',
        microsoft: 'Microsoft',
        personal_email: '个人邮箱',
    }[provider] || provider;
}

function emailPlaceholder(provider) {
    return {
        qq: 'example@qq.com',
        tencent_exmail: 'teacher@school.example',
        netease_163: 'example@163.com',
        netease_126: 'example@126.com',
    }[provider] || 'name@example.com';
}

function statusLabel(status) {
    return { success: '成功', failed: '失败', pending: '发送中' }[status] || status;
}

function DetailItem({ label, value, valueClassName = 'text-gray-900' }) {
    return (
        <div>
            <dt className="text-xs text-gray-500">{label}</dt>
            <dd className={`mt-1 break-words ${valueClassName}`}>{value ?? '-'}</dd>
        </div>
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
