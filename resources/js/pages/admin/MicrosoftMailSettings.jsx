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
            setSettings(prev => ({ ...prev, ...response.data }));
        } catch (error) {
            setNotice({ type: 'error', text: error.response?.data?.error || '读取 Microsoft OAuth 应用配置失败' });
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
                        <h4 className="text-lg font-semibold text-gray-900">Microsoft OAuth 应用</h4>
                    </div>
                    <p className="mt-1 text-sm text-gray-500">配置学校系统统一使用的 Microsoft 登录应用，不在此处配置发件邮箱。</p>
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
                        aria-label="Microsoft OAuth 应用开关"
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
                <div className="rounded-md border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
                    <p className="font-medium">班主任仍使用自己的 Microsoft 邮箱发信</p>
                    <p className="mt-1 text-blue-800">管理员仅在这里配置 OAuth 应用身份。启用后，每位班主任需在“家长通知”中分别登录并授权自己的 Outlook、Hotmail 或 Microsoft 365 邮箱。</p>
                </div>

                <details className="rounded-md border border-gray-200 bg-white px-4 py-3 text-sm text-gray-700">
                    <summary className="cursor-pointer font-medium text-gray-900">Microsoft Entra 配置步骤</summary>
                    <ol className="mt-3 list-decimal space-y-2 pl-5 leading-6">
                        <li>
                            打开
                            <a href="https://entra.microsoft.com/" target="_blank" rel="noreferrer" className="mx-1 text-blue-600 hover:text-blue-800">Microsoft Entra 管理中心</a>
                            ，进入“应用程序 &gt; 应用注册 &gt; 新注册”。
                        </li>
                        <li>支持的账户类型选择“任何组织目录中的账户和个人 Microsoft 账户”，以同时支持 Microsoft 365、Outlook 和 Hotmail。</li>
                        <li>注册后，在“概述”复制 Application (client) ID，填写到下方同名字段。</li>
                        <li>进入“证书和密码 &gt; 客户端密码 &gt; 新客户端密码”，复制新密码的“值”填写到下方；不要填写“机密 ID”。</li>
                        <li>进入“身份验证 &gt; 添加平台 &gt; Web”，将下方 Web Redirect URI 完整复制到重定向 URI，协议、域名和路径必须完全一致。</li>
                        <li>进入“API 权限 &gt; 添加权限 &gt; Microsoft Graph &gt; 委托的权限”，添加 User.Read 和 Mail.Send。</li>
                        <li>填写下方配置，打开右上角开关并保存。随后由班主任在“家长通知 &gt; 个人邮箱”中连接自己的 Microsoft 邮箱。</li>
                    </ol>
                    <a href="https://learn.microsoft.com/en-us/graph/auth-register-app-v2" target="_blank" rel="noreferrer" className="mt-3 inline-block text-blue-600 hover:text-blue-800">查看 Microsoft 官方应用注册说明</a>
                </details>
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
                    系统连接时会申请 <span className="font-medium">openid、profile、email、offline_access、User.Read、Mail.Send</span>。其中 API 权限请选择 Delegated permissions，回调平台请选择 Web。
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
                    {saving ? '保存中...' : '保存 OAuth 应用配置'}
                </button>
            </div>
        </form>
    );
}
