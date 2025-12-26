import { useState, useEffect } from 'react';
import axios from 'axios';
import Layout from '../../components/Layout';
import { QrCodeIcon, CheckCircleIcon, XCircleIcon, ArrowPathIcon } from '@heroicons/react/24/outline';

export default function WechatPushPage() {
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [verifying, setVerifying] = useState(false);
    const [message, setMessage] = useState('');
    const [qrcodeUrl, setQrcodeUrl] = useState('');
    const [qrcodeLoading, setQrcodeLoading] = useState(false);

    const [formData, setFormData] = useState({
        use_system: true,
        appid: '',
        appsecret: '',
        token: '',
        template_id: '',
    });

    useEffect(() => {
        fetchConfig();
    }, []);

    const fetchConfig = async () => {
        try {
            const res = await axios.get('/wechat/teacher-config');
            setData(res.data);
            if (res.data.config) {
                setFormData({
                    use_system: res.data.config.use_system,
                    appid: res.data.config.appid || '',
                    appsecret: res.data.config.has_secret ? '******' : '',
                    token: res.data.config.token || '',
                    template_id: res.data.config.template_id || '',
                });
            }
        } catch (error) {
            console.error('Failed to fetch config', error);
        } finally {
            setLoading(false);
        }
    };

    const handleSave = async () => {
        setSaving(true);
        setMessage('');
        try {
            await axios.post('/wechat/teacher-config', formData);
            setMessage('保存成功');
            fetchConfig();
            setTimeout(() => setMessage(''), 3000);
        } catch (error) {
            setMessage('保存失败: ' + (error.response?.data?.error || error.message));
        } finally {
            setSaving(false);
        }
    };

    const handleVerify = async () => {
        setVerifying(true);
        setMessage('');
        try {
            await axios.post('/wechat/verify-config');
            setMessage('验证成功');
            fetchConfig();
        } catch (error) {
            setMessage('验证失败: ' + (error.response?.data?.error || error.message));
        } finally {
            setVerifying(false);
        }
    };

    const handleGetQrcode = async () => {
        setQrcodeLoading(true);
        try {
            const res = await axios.get('/wechat/qrcode', {
                params: { config_id: data?.config?.id }
            });
            setQrcodeUrl(res.data.qrcode_url);
        } catch (error) {
            alert('获取二维码失败: ' + (error.response?.data?.error || error.message));
        } finally {
            setQrcodeLoading(false);
        }
    };

    const handleUnbind = async (bindingId) => {
        if (!confirm('确定要解除该用户的绑定吗？')) return;
        try {
            await axios.delete(`/api/wechat/teacher-unbind/${bindingId}`);
            fetchConfig();
        } catch (error) {
            alert('解除绑定失败: ' + (error.response?.data?.error || error.message));
        }
    };

    if (loading) {
        return (
            <Layout>
                <div className="flex items-center justify-center min-h-64">
                    <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600"></div>
                </div>
            </Layout>
        );
    }

    const canUseSystem = data?.use_system_enabled;
    const canUseCustom = data?.custom_config_enabled;

    return (
        <Layout>
            <div className="max-w-4xl mx-auto space-y-6">
                <h1 className="text-2xl font-bold text-gray-900">微信推送管理</h1>

                {/* 选择公众号类型 */}
                {(canUseSystem || canUseCustom) && (
                    <div className="bg-white rounded-lg shadow p-6">
                        <h2 className="text-lg font-medium text-gray-900 mb-4">选择公众号</h2>
                        <div className="space-y-3">
                            {canUseSystem && (
                                <label className="flex items-center p-3 border rounded-lg cursor-pointer hover:bg-gray-50">
                                    <input
                                        type="radio"
                                        name="use_system"
                                        checked={formData.use_system}
                                        onChange={() => setFormData({ ...formData, use_system: true })}
                                        className="h-4 w-4 text-indigo-600 focus:ring-indigo-500"
                                    />
                                    <div className="ml-3">
                                        <span className="text-sm font-medium text-gray-900">使用系统公众号</span>
                                        <span className="ml-2 text-xs text-green-600">（推荐，无需配置）</span>
                                    </div>
                                </label>
                            )}
                            {canUseCustom && (
                                <label className="flex items-center p-3 border rounded-lg cursor-pointer hover:bg-gray-50">
                                    <input
                                        type="radio"
                                        name="use_system"
                                        checked={!formData.use_system}
                                        onChange={() => setFormData({ ...formData, use_system: false })}
                                        className="h-4 w-4 text-indigo-600 focus:ring-indigo-500"
                                    />
                                    <div className="ml-3">
                                        <span className="text-sm font-medium text-gray-900">使用自己的测试公众号</span>
                                    </div>
                                </label>
                            )}
                        </div>
                    </div>
                )}

                {/* 自定义公众号配置 */}
                {!formData.use_system && canUseCustom && (
                    <div className="bg-white rounded-lg shadow p-6 space-y-4">
                        <h2 className="text-lg font-medium text-gray-900">公众号配置</h2>

                        {/* 配置说明 */}
                        <div className="bg-blue-50 border border-blue-200 rounded-lg p-4">
                            <h3 className="text-sm font-medium text-blue-800 mb-2">配置说明</h3>
                            <ol className="text-sm text-blue-700 space-y-1 list-decimal list-inside">
                                <li>
                                    访问{' '}
                                    <a
                                        href={data?.test_account_url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="underline"
                                    >
                                        微信公众平台测试号申请页面
                                    </a>
                                </li>
                                <li>微信扫码登录，获取测试公众号</li>
                                <li>复制 AppID 和 AppSecret 填入下方</li>
                                <li>
                                    在测试号管理页面，配置接口信息：
                                    <ul className="ml-4 mt-1 space-y-1">
                                        <li>URL: <code className="bg-blue-100 px-1 rounded break-all">{data?.callback_url}</code></li>
                                        <li>Token: 自定义（与下方一致）</li>
                                    </ul>
                                </li>
                                <li>
                                    添加模板消息，复制模板ID。模板内容如下：
                                    <div className="mt-2 p-3 bg-white rounded border border-blue-200 text-gray-700 font-mono text-xs space-y-1">
                                        <div><span className="text-gray-500">标题：</span>请假申请通知</div>
                                        <div className="border-t border-gray-200 pt-2 mt-2 space-y-1">
                                            <div>{"{{first.DATA}}"}</div>
                                            <div>学生姓名：{"{{keyword1.DATA}}"}</div>
                                            <div>所在班级：{"{{keyword2.DATA}}"}</div>
                                            <div>请假类型：{"{{keyword3.DATA}}"}</div>
                                            <div>请假时间：{"{{keyword4.DATA}}"}</div>
                                            <div>请假原因：{"{{keyword5.DATA}}"}</div>
                                            <div>申请时间：{"{{keyword6.DATA}}"}</div>
                                            <div>{"{{remark.DATA}}"}</div>
                                        </div>
                                    </div>
                                </li>
                            </ol>
                        </div>

                        {/* 配置表单 */}
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1">AppID</label>
                                <input
                                    type="text"
                                    value={formData.appid}
                                    onChange={(e) => setFormData({ ...formData, appid: e.target.value })}
                                    className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                                    placeholder="wx..."
                                />
                            </div>
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1">AppSecret</label>
                                <input
                                    type="password"
                                    value={formData.appsecret}
                                    onChange={(e) => setFormData({ ...formData, appsecret: e.target.value })}
                                    className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                                    placeholder="AppSecret"
                                />
                            </div>
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1">Token</label>
                                <input
                                    type="text"
                                    value={formData.token}
                                    onChange={(e) => setFormData({ ...formData, token: e.target.value })}
                                    className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                                    placeholder="自定义Token"
                                />
                            </div>
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1">模板消息ID</label>
                                <input
                                    type="text"
                                    value={formData.template_id}
                                    onChange={(e) => setFormData({ ...formData, template_id: e.target.value })}
                                    className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                                    placeholder="模板ID"
                                />
                            </div>
                        </div>

                        {/* 回调URL */}
                        <div className="p-3 bg-gray-50 rounded-lg">
                            <p className="text-sm text-gray-600">
                                回调URL（填入微信测试号）:
                            </p>
                            <code className="text-sm bg-gray-200 px-2 py-1 rounded mt-1 block break-all">
                                {data?.callback_url}
                            </code>
                        </div>
                    </div>
                )}

                {/* 保存和验证按钮 */}
                {(canUseSystem || canUseCustom) && (
                    <div className="bg-white rounded-lg shadow p-6">
                        <div className="flex items-center gap-4 flex-wrap">
                            <button
                                onClick={handleSave}
                                disabled={saving}
                                className="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50"
                            >
                                {saving ? '保存中...' : '保存配置'}
                            </button>
                            {data?.config && !data.config.use_system && (
                                <button
                                    onClick={handleVerify}
                                    disabled={verifying}
                                    className="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50"
                                >
                                    {verifying ? '验证中...' : '验证配置'}
                                </button>
                            )}
                            {data?.config?.is_verified && (
                                <span className="flex items-center text-green-600">
                                    <CheckCircleIcon className="h-5 w-5 mr-1" />
                                    配置已验证
                                </span>
                            )}
                            {message && (
                                <span className={`text-sm ${message.includes('失败') ? 'text-red-600' : 'text-green-600'}`}>
                                    {message}
                                </span>
                            )}
                        </div>
                    </div>
                )}

                {/* 扫码绑定 */}
                {data?.config?.is_verified && (
                    <div className="bg-white rounded-lg shadow p-6">
                        <h2 className="text-lg font-medium text-gray-900 mb-4">扫码绑定</h2>

                        {/* 我的绑定状态 */}
                        <div className="mb-4 p-3 bg-gray-50 rounded-lg">
                            {data?.my_binding ? (
                                <div className="flex items-center text-green-600">
                                    <CheckCircleIcon className="h-5 w-5 mr-2" />
                                    <span>您已绑定（微信昵称: {data.my_binding.nickname}）</span>
                                </div>
                            ) : (
                                <div className="flex items-center text-gray-500">
                                    <XCircleIcon className="h-5 w-5 mr-2" />
                                    <span>您尚未绑定微信</span>
                                </div>
                            )}
                        </div>

                        {/* 二维码 */}
                        <div className="text-center">
                            {qrcodeUrl ? (
                                <div className="inline-block">
                                    <img src={qrcodeUrl} alt="绑定二维码" className="w-48 h-48 mx-auto" />
                                    <p className="text-sm text-gray-500 mt-2">扫码关注公众号完成绑定（10分钟有效）</p>
                                    <button
                                        onClick={handleGetQrcode}
                                        className="mt-2 text-indigo-600 hover:text-indigo-800 text-sm flex items-center mx-auto"
                                    >
                                        <ArrowPathIcon className="h-4 w-4 mr-1" />
                                        刷新二维码
                                    </button>
                                </div>
                            ) : (
                                <button
                                    onClick={handleGetQrcode}
                                    disabled={qrcodeLoading}
                                    className="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50 inline-flex items-center"
                                >
                                    <QrCodeIcon className="h-5 w-5 mr-2" />
                                    {qrcodeLoading ? '生成中...' : '生成绑定二维码'}
                                </button>
                            )}
                        </div>
                    </div>
                )}

                {/* 已绑定用户列表 */}
                {data?.bindings?.length > 0 && (
                    <div className="bg-white rounded-lg shadow overflow-hidden">
                        <div className="px-6 py-4 border-b border-gray-200">
                            <h2 className="text-lg font-medium text-gray-900">已绑定用户 ({data.bindings.length}人)</h2>
                        </div>
                        <table className="min-w-full divide-y divide-gray-200">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">姓名</th>
                                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">角色</th>
                                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">微信昵称</th>
                                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">绑定时间</th>
                                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">操作</th>
                                </tr>
                            </thead>
                            <tbody className="bg-white divide-y divide-gray-200">
                                {data.bindings.map((binding) => (
                                    <tr key={binding.id}>
                                        <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{binding.user_name}</td>
                                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            {binding.user_role === 'teacher' ? '班主任' : binding.user_role === 'student' ? '学生管理员' : binding.user_role}
                                        </td>
                                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{binding.nickname}</td>
                                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{binding.created_at}</td>
                                        <td className="px-6 py-4 whitespace-nowrap text-sm">
                                            <button
                                                onClick={() => handleUnbind(binding.id)}
                                                className="text-red-600 hover:text-red-900"
                                            >
                                                解除绑定
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {/* 没有权限提示 */}
                {!canUseSystem && !canUseCustom && (
                    <div className="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                        <p className="text-yellow-700">系统管理员尚未开启微信推送功能</p>
                    </div>
                )}
            </div>
        </Layout>
    );
}
