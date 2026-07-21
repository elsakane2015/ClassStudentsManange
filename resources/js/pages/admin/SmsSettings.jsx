import React, { useEffect, useMemo, useState } from 'react';
import axios from 'axios';
import {
    ArrowDownIcon,
    ArrowUpIcon,
    CheckCircleIcon,
    DevicePhoneMobileIcon,
    ExclamationTriangleIcon,
    PaperAirplaneIcon,
} from '@heroicons/react/24/outline';

const variableLabels = {
    student_name: '学生姓名',
    student_no: '学号',
    class_name: '班级',
    event_name: '考勤事项',
    attendance_date: '日期',
    period: '时段/节次',
    reason: '说明',
    teacher_name: '班主任',
    school_name: '学校',
    submitted_at: '发送时间',
};

const emptySettings = {
    sms_enabled: false,
    sms_provider: 'aliyun',
    sms_template_variables: [],
    available_template_variables: [],
    aliyun: { access_key_id: '', access_key_secret: '', sign_name: '', template_code: '' },
    tencent: { secret_id: '', secret_key: '', sdk_app_id: '', sign_name: '', template_id: '', region: 'ap-guangzhou' },
    is_ready: false,
};

export default function SmsSettings() {
    const [settings, setSettings] = useState(emptySettings);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [testing, setTesting] = useState(false);
    const [testPhone, setTestPhone] = useState('');
    const [notice, setNotice] = useState(null);

    useEffect(() => {
        axios.get('/sms/settings')
            .then(response => setSettings(response.data))
            .catch(error => setNotice({ type: 'error', text: error.response?.data?.error || '读取短信配置失败' }))
            .finally(() => setLoading(false));
    }, []);

    const availableVariables = useMemo(
        () => settings.available_template_variables.filter(key => !settings.sms_template_variables.includes(key)),
        [settings.available_template_variables, settings.sms_template_variables]
    );

    const updateProvider = (provider, field, value) => {
        setSettings(prev => ({ ...prev, [provider]: { ...prev[provider], [field]: value } }));
    };

    const addVariable = (key) => {
        setSettings(prev => ({ ...prev, sms_template_variables: [...prev.sms_template_variables, key] }));
    };

    const removeVariable = (key) => {
        setSettings(prev => ({
            ...prev,
            sms_template_variables: prev.sms_template_variables.filter(item => item !== key),
        }));
    };

    const moveVariable = (index, direction) => {
        const nextIndex = index + direction;
        if (nextIndex < 0 || nextIndex >= settings.sms_template_variables.length) return;
        setSettings(prev => {
            const variables = [...prev.sms_template_variables];
            [variables[index], variables[nextIndex]] = [variables[nextIndex], variables[index]];
            return { ...prev, sms_template_variables: variables };
        });
    };

    const handleSave = async (event) => {
        event.preventDefault();
        setSaving(true);
        setNotice(null);
        try {
            const response = await axios.post('/sms/settings', settings);
            setSettings(prev => ({
                ...prev,
                aliyun: {
                    ...prev.aliyun,
                    access_key_id: prev.aliyun.access_key_id ? '******' : '',
                    access_key_secret: prev.aliyun.access_key_secret ? '******' : '',
                },
                tencent: {
                    ...prev.tencent,
                    secret_id: prev.tencent.secret_id ? '******' : '',
                    secret_key: prev.tencent.secret_key ? '******' : '',
                },
                is_ready: response.data.is_ready,
            }));
            setNotice({ type: 'success', text: response.data.message });
        } catch (error) {
            const validationErrors = error.response?.data?.errors;
            const firstError = validationErrors ? Object.values(validationErrors).flat()[0] : null;
            setNotice({ type: 'error', text: error.response?.data?.error || firstError || '保存失败' });
        } finally {
            setSaving(false);
        }
    };

    const handleTest = async () => {
        if (!testPhone) return;
        setTesting(true);
        setNotice(null);
        try {
            const response = await axios.post('/sms/test', { phone: testPhone });
            setNotice({ type: 'success', text: response.data.message });
        } catch (error) {
            setNotice({ type: 'error', text: error.response?.data?.error || '测试短信发送失败' });
        } finally {
            setTesting(false);
        }
    };

    if (loading) return <div className="py-12 text-center text-sm text-gray-500">加载中...</div>;

    return (
        <form onSubmit={handleSave} className="space-y-8">
            <div className="flex flex-col gap-4 border-b border-gray-200 pb-6 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <div className="flex items-center gap-2">
                        <DevicePhoneMobileIcon className="h-6 w-6 text-emerald-600" />
                        <h4 className="text-lg font-semibold text-gray-900">家长短信通知</h4>
                    </div>
                    <p className="mt-1 text-sm text-gray-500">使用审核通过的云短信签名和模板发送考勤通知。</p>
                </div>
                <div className="flex items-center gap-3">
                    {settings.is_ready ? (
                        <span className="inline-flex items-center gap-1 text-sm text-green-700"><CheckCircleIcon className="h-5 w-5" />配置可用</span>
                    ) : (
                        <span className="inline-flex items-center gap-1 text-sm text-amber-700"><ExclamationTriangleIcon className="h-5 w-5" />尚未就绪</span>
                    )}
                    <button
                        type="button"
                        role="switch"
                        aria-label="启用家长短信通知"
                        aria-checked={settings.sms_enabled}
                        onClick={() => setSettings(prev => ({ ...prev, sms_enabled: !prev.sms_enabled }))}
                        className={`relative inline-flex h-6 w-11 shrink-0 rounded-full transition-colors ${settings.sms_enabled ? 'bg-emerald-600' : 'bg-gray-200'}`}
                    >
                        <span className={`inline-block h-5 w-5 translate-y-0.5 rounded-full bg-white shadow transition-transform ${settings.sms_enabled ? 'translate-x-5' : 'translate-x-0.5'}`} />
                    </button>
                </div>
            </div>

            {notice && (
                <div className={`rounded-md px-4 py-3 text-sm ${notice.type === 'success' ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'}`}>{notice.text}</div>
            )}

            <section className="space-y-4 border-b border-gray-200 pb-8">
                <h5 className="font-medium text-gray-900">短信服务商</h5>
                <div className="grid grid-cols-2 overflow-hidden rounded-md border border-gray-300 sm:inline-grid sm:w-80">
                    {[['aliyun', '阿里云'], ['tencent', '腾讯云']].map(([value, label]) => (
                        <button
                            key={value}
                            type="button"
                            onClick={() => setSettings(prev => ({ ...prev, sms_provider: value }))}
                            className={`px-4 py-2 text-sm font-medium ${settings.sms_provider === value ? 'bg-emerald-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-50'}`}
                        >
                            {label}
                        </button>
                    ))}
                </div>
            </section>

            {settings.sms_provider === 'aliyun' ? (
                <section className="space-y-4 border-b border-gray-200 pb-8">
                    <h5 className="font-medium text-gray-900">阿里云配置</h5>
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <Field label="AccessKey ID" type="password" value={settings.aliyun.access_key_id} onChange={value => updateProvider('aliyun', 'access_key_id', value)} />
                        <Field label="AccessKey Secret" type="password" value={settings.aliyun.access_key_secret} onChange={value => updateProvider('aliyun', 'access_key_secret', value)} />
                        <Field label="签名名称" value={settings.aliyun.sign_name} onChange={value => updateProvider('aliyun', 'sign_name', value)} />
                        <Field label="模板 Code" placeholder="SMS_123456789" value={settings.aliyun.template_code} onChange={value => updateProvider('aliyun', 'template_code', value)} />
                    </div>
                </section>
            ) : (
                <section className="space-y-4 border-b border-gray-200 pb-8">
                    <h5 className="font-medium text-gray-900">腾讯云配置</h5>
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <Field label="SecretId" type="password" value={settings.tencent.secret_id} onChange={value => updateProvider('tencent', 'secret_id', value)} />
                        <Field label="SecretKey" type="password" value={settings.tencent.secret_key} onChange={value => updateProvider('tencent', 'secret_key', value)} />
                        <Field label="SmsSdkAppId" placeholder="1400000000" value={settings.tencent.sdk_app_id} onChange={value => updateProvider('tencent', 'sdk_app_id', value)} />
                        <Field label="签名名称" value={settings.tencent.sign_name} onChange={value => updateProvider('tencent', 'sign_name', value)} />
                        <Field label="模板 ID" value={settings.tencent.template_id} onChange={value => updateProvider('tencent', 'template_id', value)} />
                        <Field label="地域" value={settings.tencent.region} onChange={value => updateProvider('tencent', 'region', value)} />
                    </div>
                </section>
            )}

            <section className="space-y-4 border-b border-gray-200 pb-8">
                <div>
                    <h5 className="font-medium text-gray-900">模板参数</h5>
                    <p className="mt-1 text-xs text-gray-500">阿里云模板变量名使用英文键；腾讯云按此顺序传入模板变量。</p>
                </div>
                <div className="space-y-2">
                    {settings.sms_template_variables.map((key, index) => (
                        <div key={key} className="flex items-center gap-3 border-b border-gray-100 py-2">
                            <span className="w-6 text-center text-xs font-medium text-gray-400">{index + 1}</span>
                            <span className="min-w-0 flex-1 text-sm text-gray-800">{variableLabels[key]} <code className="text-xs text-gray-500">{key}</code></span>
                            <button type="button" title="上移" disabled={index === 0} onClick={() => moveVariable(index, -1)} className="p-1 text-gray-500 disabled:opacity-30"><ArrowUpIcon className="h-4 w-4" /></button>
                            <button type="button" title="下移" disabled={index === settings.sms_template_variables.length - 1} onClick={() => moveVariable(index, 1)} className="p-1 text-gray-500 disabled:opacity-30"><ArrowDownIcon className="h-4 w-4" /></button>
                            <button type="button" onClick={() => removeVariable(key)} className="px-2 py-1 text-xs font-medium text-red-600">移除</button>
                        </div>
                    ))}
                </div>
                {availableVariables.length > 0 && (
                    <div className="flex flex-wrap gap-2">
                        {availableVariables.map(key => (
                            <button key={key} type="button" onClick={() => addVariable(key)} className="rounded border border-gray-300 bg-white px-2 py-1 text-xs text-gray-700 hover:bg-gray-50">+ {variableLabels[key]}</button>
                        ))}
                    </div>
                )}
            </section>

            <section className="space-y-4">
                <h5 className="font-medium text-gray-900">发送测试</h5>
                <div className="flex flex-col gap-3 sm:flex-row">
                    <input type="tel" value={testPhone} onChange={event => setTestPhone(event.target.value)} placeholder="接收测试短信的手机号" className="block flex-1 rounded-md border border-gray-300 p-2 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 sm:text-sm" />
                    <button type="button" onClick={handleTest} disabled={!testPhone || testing || !settings.is_ready} className="inline-flex items-center justify-center gap-2 rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50">
                        <PaperAirplaneIcon className="h-4 w-4" />
                        {testing ? '发送中...' : '发送测试短信'}
                    </button>
                </div>
            </section>

            <div className="flex justify-end border-t border-gray-200 pt-6">
                <button type="submit" disabled={saving || settings.sms_template_variables.length === 0} className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-emerald-700 disabled:opacity-50">
                    {saving ? '保存中...' : '保存短信配置'}
                </button>
            </div>
        </form>
    );
}

function Field({ label, type = 'text', value, onChange, placeholder = '' }) {
    return (
        <div>
            <label className="block text-sm font-medium text-gray-700">{label}</label>
            <input type={type} value={value} onChange={event => onChange(event.target.value)} placeholder={placeholder} autoComplete={type === 'password' ? 'new-password' : undefined} className="mt-1 block w-full rounded-md border border-gray-300 p-2 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 sm:text-sm" />
        </div>
    );
}
