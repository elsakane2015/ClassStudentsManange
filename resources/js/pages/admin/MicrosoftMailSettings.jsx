import React, { useEffect, useState } from 'react';
import axios from 'axios';
import {
    CheckCircleIcon,
    ClipboardDocumentIcon,
    EnvelopeIcon,
    ExclamationTriangleIcon,
} from '@heroicons/react/24/outline';

const emptySettings = {
    microsoft_mail_enabled: false,
    microsoft_mail_client_id: '',
    microsoft_mail_client_secret: '',
    microsoft_mail_redirect_uri: '',
    is_ready: false,
    source: 'none',
};

export default function MicrosoftMailSettings() {
    const [settings, setSettings] = useState(emptySettings);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [notice, setNotice] = useState(null);

    useEffect(() => {
        fetchSettings();
    }, []);

    const fetchSettings = async () => {
        setLoading(true);
        try {
            const response = await axios.get('/microsoft-mail/settings');
            setSettings(response.data);
        } catch (error) {
            setNotice({ type: 'error', text: error.response?.data?.error || '读取 Microsoft 邮箱配置失败' });
        } finally {
            setLoading(false);
        }
    };

    const handleSave = async event => {
        event.preventDefault();
        setSaving(true);
        setNotice(null);
        try {
            const response = await axios.post('/microsoft-mail/settings', settings);
            setSettings(prev => ({
                ...prev,
                microsoft_mail_client_secret: prev.microsoft_mail_client_secret ? '******' : '',
                is_ready: response.data.is_ready,
                source: 'system_settings',
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

    if (loading) {
        return <div className="py-12 text-center text-sm text-gray-500">加载中...</div>;
    }

    return (
        <form onSubmit={handleSave} className="space-y-8">
            <div className="flex flex-col gap-4 border-b border-gray-200 pb-6 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <div className="flex items-center gap-2">
                        <EnvelopeIcon className="h-6 w-6 text-blue-600" />
                        <h4 className="text-lg font-semibold text-gray-900">Microsoft 个人邮箱</h4>
                    </div>
                    <p className="mt-1 text-sm text-gray-500">允许班主任通过 Microsoft OAuth 连接 Outlook 或 Hotmail，并使用 Microsoft Graph 发信。</p>
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
                        aria-label="Microsoft 邮箱开关"
                        aria-checked={settings.microsoft_mail_enabled}
                        onClick={() => setSettings(prev => ({ ...prev, microsoft_mail_enabled: !prev.microsoft_mail_enabled }))}
                        className={`relative inline-flex h-6 w-11 shrink-0 rounded-full transition-colors ${settings.microsoft_mail_enabled ? 'bg-blue-600' : 'bg-gray-200'}`}
                    >
                        <span className={`inline-block h-5 w-5 translate-y-0.5 rounded-full bg-white shadow transition-transform ${settings.microsoft_mail_enabled ? 'translate-x-5' : 'translate-x-0.5'}`} />
                    </button>
                </div>
            </div>

            {notice && (
                <div className={`rounded-md px-4 py-3 text-sm ${notice.type === 'success' ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'}`}>
                    {notice.text}
                </div>
            )}

            <section className="space-y-5">
                <div className="grid grid-cols-1 gap-4">
                    <label className="block text-sm font-medium text-gray-700">
                        Application (client) ID
                        <input
                            type="text"
                            value={settings.microsoft_mail_client_id}
                            onChange={event => setSettings(prev => ({ ...prev, microsoft_mail_client_id: event.target.value }))}
                            placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
                            autoComplete="off"
                            className="mt-1 block w-full rounded-md border border-gray-300 p-2 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                        />
                    </label>
                    <label className="block text-sm font-medium text-gray-700">
                        Client Secret
                        <input
                            type="password"
                            value={settings.microsoft_mail_client_secret}
                            onChange={event => setSettings(prev => ({ ...prev, microsoft_mail_client_secret: event.target.value }))}
                            placeholder="Microsoft Entra 中生成的客户端密钥值"
                            autoComplete="new-password"
                            className="mt-1 block w-full rounded-md border border-gray-300 p-2 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                        />
                    </label>
                    <label className="block text-sm font-medium text-gray-700">
                        Web Redirect URI
                        <div className="mt-1 flex min-w-0 gap-2">
                            <input
                                type="url"
                                required
                                value={settings.microsoft_mail_redirect_uri}
                                onChange={event => setSettings(prev => ({ ...prev, microsoft_mail_redirect_uri: event.target.value }))}
                                className="min-w-0 flex-1 rounded-md border border-gray-300 p-2 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                            />
                            <button
                                type="button"
                                title="复制回调地址"
                                onClick={() => navigator.clipboard?.writeText(settings.microsoft_mail_redirect_uri)}
                                className="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-md border border-gray-300 bg-white text-gray-600 hover:bg-gray-50"
                            >
                                <ClipboardDocumentIcon className="h-5 w-5" />
                                <span className="sr-only">复制回调地址</span>
                            </button>
                        </div>
                    </label>
                </div>

                <div className="rounded-md bg-blue-50 px-4 py-3 text-sm text-blue-800">
                    Microsoft Entra 应用需要 Delegated 权限 <span className="font-medium">User.Read</span>、<span className="font-medium">Mail.Send</span>，并允许 <span className="font-medium">offline_access</span>。回调类型请选择 Web。
                </div>

                {settings.source === 'environment' && (
                    <p className="text-xs text-gray-500">当前配置来自服务器环境变量；在此保存后将改用系统设置中的配置。</p>
                )}
            </section>

            <div className="flex justify-end border-t border-gray-200 pt-6">
                <button
                    type="submit"
                    disabled={saving}
                    className="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700 disabled:opacity-50"
                >
                    {saving ? '保存中...' : '保存 Microsoft 配置'}
                </button>
            </div>
        </form>
    );
}
