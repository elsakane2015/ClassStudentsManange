import React, { useEffect, useState } from 'react';
import axios from 'axios';
import { CheckCircleIcon, EnvelopeIcon, ExclamationTriangleIcon, PaperAirplaneIcon } from '@heroicons/react/24/outline';

const emptySettings = {
    resend_enabled: false,
    resend_api_key: '',
    resend_from_email: '',
    resend_from_name: '',
    resend_reply_to: '',
    resend_subject_template: '',
    resend_html_template: '',
    is_ready: false,
};

const templateVariables = [
    'student_name',
    'student_no',
    'class_name',
    'event_name',
    'attendance_date',
    'period',
    'reason',
    'teacher_name',
    'school_name',
    'submitted_at',
];

export default function ResendSettings() {
    const [settings, setSettings] = useState(emptySettings);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [testEmail, setTestEmail] = useState('');
    const [testing, setTesting] = useState(false);
    const [notice, setNotice] = useState(null);

    useEffect(() => {
        fetchSettings();
    }, []);

    const fetchSettings = async () => {
        setLoading(true);
        try {
            const response = await axios.get('/resend/settings');
            setSettings(response.data);
        } catch (error) {
            setNotice({ type: 'error', text: error.response?.data?.error || '读取 Resend 配置失败' });
        } finally {
            setLoading(false);
        }
    };

    const handleSave = async (event) => {
        event.preventDefault();
        setSaving(true);
        setNotice(null);
        try {
            const response = await axios.post('/resend/settings', settings);
            setSettings(prev => ({
                ...prev,
                resend_api_key: prev.resend_api_key ? '******' : '',
                is_ready: response.data.is_ready,
            }));
            setNotice({ type: 'success', text: response.data.message });
        } catch (error) {
            const validationErrors = error.response?.data?.errors;
            const firstValidationError = validationErrors ? Object.values(validationErrors).flat()[0] : null;
            setNotice({ type: 'error', text: error.response?.data?.error || firstValidationError || '保存失败' });
        } finally {
            setSaving(false);
        }
    };

    const handleTest = async () => {
        if (!testEmail) return;
        setTesting(true);
        setNotice(null);
        try {
            const response = await axios.post('/resend/test', { email: testEmail });
            setNotice({ type: 'success', text: response.data.message });
        } catch (error) {
            setNotice({ type: 'error', text: error.response?.data?.error || '测试邮件发送失败' });
        } finally {
            setTesting(false);
        }
    };

    if (loading) {
        return <div className="py-12 text-center text-sm text-gray-500">加载中...</div>;
    }

    return (
        <form onSubmit={handleSave} className="space-y-8">
            <div className="flex flex-col gap-4 border-b border-gray-200 pb-6 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <div className="flex items-center gap-2">
                        <EnvelopeIcon className="h-6 w-6 text-indigo-600" />
                        <h4 className="text-lg font-semibold text-gray-900">Resend 家长邮件</h4>
                    </div>
                    <p className="mt-1 text-sm text-gray-500">通过 Resend API 向学生家长邮箱发送请假和考勤通知。</p>
                </div>
                <div className="flex items-center gap-3">
                    {settings.is_ready ? (
                        <span className="inline-flex items-center gap-1 text-sm text-green-700">
                            <CheckCircleIcon className="h-5 w-5" /> 配置可用
                        </span>
                    ) : (
                        <span className="inline-flex items-center gap-1 text-sm text-amber-700">
                            <ExclamationTriangleIcon className="h-5 w-5" /> 尚未就绪
                        </span>
                    )}
                    <button
                        type="button"
                        role="switch"
                        aria-checked={settings.resend_enabled}
                        onClick={() => setSettings(prev => ({ ...prev, resend_enabled: !prev.resend_enabled }))}
                        className={`relative inline-flex h-6 w-11 shrink-0 rounded-full transition-colors ${settings.resend_enabled ? 'bg-indigo-600' : 'bg-gray-200'}`}
                    >
                        <span className={`inline-block h-5 w-5 translate-y-0.5 rounded-full bg-white shadow transition-transform ${settings.resend_enabled ? 'translate-x-5' : 'translate-x-0.5'}`} />
                    </button>
                </div>
            </div>

            {notice && (
                <div className={`rounded-md px-4 py-3 text-sm ${notice.type === 'success' ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'}`}>
                    {notice.text}
                </div>
            )}

            <section className="space-y-4 border-b border-gray-200 pb-8">
                <h5 className="font-medium text-gray-900">发送配置</h5>
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div className="md:col-span-2">
                        <label className="block text-sm font-medium text-gray-700">Resend API Key</label>
                        <input
                            type="password"
                            value={settings.resend_api_key}
                            onChange={event => setSettings({ ...settings, resend_api_key: event.target.value })}
                            placeholder="re_xxxxxxxxx"
                            autoComplete="new-password"
                            className="mt-1 block w-full rounded-md border border-gray-300 p-2 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                        />
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700">发件人名称</label>
                        <input
                            type="text"
                            value={settings.resend_from_name}
                            onChange={event => setSettings({ ...settings, resend_from_name: event.target.value })}
                            placeholder="学校考勤通知"
                            className="mt-1 block w-full rounded-md border border-gray-300 p-2 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                        />
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700">发件邮箱</label>
                        <input
                            type="email"
                            value={settings.resend_from_email}
                            onChange={event => setSettings({ ...settings, resend_from_email: event.target.value })}
                            placeholder="attendance@notice.example.com"
                            className="mt-1 block w-full rounded-md border border-gray-300 p-2 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                        />
                        <p className="mt-1 text-xs text-gray-500">邮箱域名必须与 Resend 中已验证的域名完全一致。</p>
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700">回复邮箱（可选）</label>
                        <input
                            type="email"
                            value={settings.resend_reply_to}
                            onChange={event => setSettings({ ...settings, resend_reply_to: event.target.value })}
                            placeholder="teacher@example.com"
                            className="mt-1 block w-full rounded-md border border-gray-300 p-2 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                        />
                    </div>
                </div>
            </section>

            <section className="space-y-4 border-b border-gray-200 pb-8">
                <div>
                    <h5 className="font-medium text-gray-900">邮件模板</h5>
                    <div className="mt-2 flex flex-wrap gap-2">
                        {templateVariables.map(variable => (
                            <button
                                key={variable}
                                type="button"
                                title="复制变量"
                                onClick={() => navigator.clipboard?.writeText(`{{${variable}}}`)}
                                className="rounded bg-gray-100 px-2 py-1 font-mono text-xs text-gray-700 hover:bg-gray-200"
                            >
                                {`{{${variable}}}`}
                            </button>
                        ))}
                    </div>
                </div>
                <div>
                    <label className="block text-sm font-medium text-gray-700">邮件主题</label>
                    <input
                        type="text"
                        required
                        value={settings.resend_subject_template}
                        onChange={event => setSettings({ ...settings, resend_subject_template: event.target.value })}
                        className="mt-1 block w-full rounded-md border border-gray-300 p-2 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                    />
                </div>
                <div>
                    <label className="block text-sm font-medium text-gray-700">HTML 内容</label>
                    <textarea
                        required
                        rows={16}
                        value={settings.resend_html_template}
                        onChange={event => setSettings({ ...settings, resend_html_template: event.target.value })}
                        className="mt-1 block w-full rounded-md border border-gray-300 p-3 font-mono text-xs shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    />
                </div>
                <div>
                    <label className="block text-sm font-medium text-gray-700">模板预览</label>
                    <iframe
                        title="邮件模板预览"
                        sandbox=""
                        srcDoc={settings.resend_html_template}
                        className="mt-1 h-80 w-full rounded-md border border-gray-200 bg-white"
                    />
                </div>
            </section>

            <section className="space-y-4">
                <h5 className="font-medium text-gray-900">发送测试</h5>
                <div className="flex flex-col gap-3 sm:flex-row">
                    <input
                        type="email"
                        value={testEmail}
                        onChange={event => setTestEmail(event.target.value)}
                        placeholder="接收测试邮件的邮箱"
                        className="block flex-1 rounded-md border border-gray-300 p-2 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                    />
                    <button
                        type="button"
                        onClick={handleTest}
                        disabled={!testEmail || testing || !settings.is_ready}
                        className="inline-flex items-center justify-center gap-2 rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <PaperAirplaneIcon className="h-4 w-4" />
                        {testing ? '发送中...' : '发送测试邮件'}
                    </button>
                </div>
            </section>

            <div className="flex justify-end border-t border-gray-200 pt-6">
                <button
                    type="submit"
                    disabled={saving}
                    className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 disabled:opacity-50"
                >
                    {saving ? '保存中...' : '保存 Resend 配置'}
                </button>
            </div>
        </form>
    );
}
