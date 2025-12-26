import { useState, useEffect } from 'react';
import axios from 'axios';

export default function WechatSettings() {
    const [settings, setSettings] = useState({
        wechat_system_appid: '',
        wechat_system_secret: '',
        wechat_system_token: '',
        wechat_system_template_id: '',
        wechat_callback_domain: '',
        wechat_use_system_enabled: false,
        wechat_custom_config_enabled: false,
        wechat_manager_bind_enabled: false,
    });
    const [teacherConfigs, setTeacherConfigs] = useState([]);
    const [bindings, setBindings] = useState([]);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [message, setMessage] = useState('');
    const [activeTab, setActiveTab] = useState('settings');

    useEffect(() => {
        fetchData();
    }, []);

    const fetchData = async () => {
        try {
            const [settingsRes, configsRes, bindingsRes] = await Promise.all([
                axios.get('/wechat/settings'),
                axios.get('/wechat/teacher-configs'),
                axios.get('/wechat/binding-list'),
            ]);
            setSettings(settingsRes.data);
            setTeacherConfigs(configsRes.data);
            setBindings(bindingsRes.data);
        } catch (error) {
            console.error('Failed to fetch wechat data', error);
        } finally {
            setLoading(false);
        }
    };

    const handleSave = async () => {
        setSaving(true);
        setMessage('');
        try {
            await axios.post('/wechat/settings', settings);
            setMessage('保存成功');
            setTimeout(() => setMessage(''), 3000);
        } catch (error) {
            setMessage('保存失败: ' + (error.response?.data?.error || error.message));
        } finally {
            setSaving(false);
        }
    };

    const handleUnbind = async (id) => {
        if (!confirm('确定要解除该用户的绑定吗？')) return;
        try {
            await axios.delete(`/wechat/admin-unbind/${id}`);
            setBindings(bindings.filter(b => b.id !== id));
        } catch (error) {
            alert('解除绑定失败: ' + (error.response?.data?.error || error.message));
        }
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
            {/* Tabs */}
            <div className="border-b border-gray-200">
                <nav className="-mb-px flex space-x-8">
                    <button
                        onClick={() => setActiveTab('settings')}
                        className={`py-2 px-1 border-b-2 font-medium text-sm ${activeTab === 'settings'
                            ? 'border-indigo-500 text-indigo-600'
                            : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                            }`}
                    >
                        系统设置
                    </button>
                    <button
                        onClick={() => setActiveTab('teachers')}
                        className={`py-2 px-1 border-b-2 font-medium text-sm ${activeTab === 'teachers'
                            ? 'border-indigo-500 text-indigo-600'
                            : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                            }`}
                    >
                        班主任配置 ({teacherConfigs.length})
                    </button>
                    <button
                        onClick={() => setActiveTab('bindings')}
                        className={`py-2 px-1 border-b-2 font-medium text-sm ${activeTab === 'bindings'
                            ? 'border-indigo-500 text-indigo-600'
                            : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                            }`}
                    >
                        绑定列表 ({bindings.length})
                    </button>
                </nav>
            </div>

            {/* Settings Tab */}
            {activeTab === 'settings' && (
                <div className="bg-white rounded-lg shadow p-6 space-y-6">
                    <div>
                        <h3 className="text-lg font-medium text-gray-900 mb-4">系统公众号配置</h3>
                        <p className="text-sm text-gray-500 mb-4">
                            配置系统公众号后，班主任可以选择使用系统提供的公众号（如果开启权限）
                        </p>
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1">AppID</label>
                                <input
                                    type="text"
                                    value={settings.wechat_system_appid}
                                    onChange={(e) => setSettings({ ...settings, wechat_system_appid: e.target.value })}
                                    className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                                    placeholder="wx..."
                                />
                            </div>
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1">AppSecret</label>
                                <input
                                    type="password"
                                    value={settings.wechat_system_secret}
                                    onChange={(e) => setSettings({ ...settings, wechat_system_secret: e.target.value })}
                                    className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                                    placeholder="留空则不修改"
                                />
                            </div>
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1">Token</label>
                                <input
                                    type="text"
                                    value={settings.wechat_system_token}
                                    onChange={(e) => setSettings({ ...settings, wechat_system_token: e.target.value })}
                                    className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                                    placeholder="自定义Token"
                                />
                            </div>
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1">模板消息ID</label>
                                <input
                                    type="text"
                                    value={settings.wechat_system_template_id}
                                    onChange={(e) => setSettings({ ...settings, wechat_system_template_id: e.target.value })}
                                    className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                                    placeholder="模板ID"
                                />
                            </div>
                            <div className="md:col-span-2">
                                <label className="block text-sm font-medium text-gray-700 mb-1">回调域名 / 消息跳转地址</label>
                                <input
                                    type="text"
                                    value={settings.wechat_callback_domain}
                                    onChange={(e) => setSettings({ ...settings, wechat_callback_domain: e.target.value })}
                                    className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                                    placeholder="https://kq.art-design.top（留空则使用当前域名）"
                                />
                                <p className="text-xs text-gray-400 mt-1">
                                    用于微信公众号服务器配置URL，也是用户点击模板消息后跳转的地址
                                </p>
                            </div>
                        </div>
                        <div className="mt-4 p-3 bg-gray-50 rounded-lg space-y-2">
                            <p className="text-sm text-gray-600">
                                回调URL: <code className="bg-gray-200 px-2 py-1 rounded break-all">
                                    {(settings.wechat_callback_domain || window.location.origin)}/api/wechat/callback/system
                                </code>
                            </p>
                            <p className="text-sm text-gray-600">
                                点击消息跳转: <code className="bg-gray-200 px-2 py-1 rounded break-all">
                                    {settings.wechat_callback_domain || window.location.origin}
                                </code>
                            </p>
                        </div>
                    </div>

                    <hr className="border-gray-200" />

                    <div>
                        <h3 className="text-lg font-medium text-gray-900 mb-4">权限设置</h3>
                        <div className="space-y-4">
                            <label className="flex items-center">
                                <input
                                    type="checkbox"
                                    checked={settings.wechat_use_system_enabled}
                                    onChange={(e) => setSettings({ ...settings, wechat_use_system_enabled: e.target.checked })}
                                    className="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded"
                                />
                                <span className="ml-3 text-sm text-gray-700">班主任可使用系统公众号</span>
                            </label>
                            <label className="flex items-center">
                                <input
                                    type="checkbox"
                                    checked={settings.wechat_custom_config_enabled}
                                    onChange={(e) => setSettings({ ...settings, wechat_custom_config_enabled: e.target.checked })}
                                    className="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded"
                                />
                                <span className="ml-3 text-sm text-gray-700">班主任可配置自己的公众号</span>
                            </label>
                            <label className="flex items-center">
                                <input
                                    type="checkbox"
                                    checked={settings.wechat_manager_bind_enabled}
                                    onChange={(e) => setSettings({ ...settings, wechat_manager_bind_enabled: e.target.checked })}
                                    className="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded"
                                />
                                <span className="ml-3 text-sm text-gray-700">班级管理员可绑定接收推送</span>
                            </label>
                        </div>
                    </div>

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
            )}

            {/* Teacher Configs Tab */}
            {activeTab === 'teachers' && (
                <div className="bg-white rounded-lg shadow overflow-hidden">
                    <table className="min-w-full divide-y divide-gray-200">
                        <thead className="bg-gray-50">
                            <tr>
                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">姓名</th>
                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">负责班级</th>
                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">使用公众号</th>
                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">验证状态</th>
                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">绑定人数</th>
                            </tr>
                        </thead>
                        <tbody className="bg-white divide-y divide-gray-200">
                            {teacherConfigs.length === 0 ? (
                                <tr>
                                    <td colSpan="5" className="px-6 py-4 text-center text-gray-500">暂无班主任配置</td>
                                </tr>
                            ) : (
                                teacherConfigs.map((config) => (
                                    <tr key={config.id}>
                                        <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{config.name}</td>
                                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{config.classes || '-'}</td>
                                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{config.config_type}</td>
                                        <td className="px-6 py-4 whitespace-nowrap">
                                            {config.is_verified ? (
                                                <span className="px-2 py-1 text-xs font-medium rounded-full bg-green-100 text-green-800">已验证</span>
                                            ) : (
                                                <span className="px-2 py-1 text-xs font-medium rounded-full bg-gray-100 text-gray-600">未验证</span>
                                            )}
                                        </td>
                                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{config.binding_count}人</td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
            )}

            {/* Bindings Tab */}
            {activeTab === 'bindings' && (
                <div className="bg-white rounded-lg shadow overflow-hidden">
                    <table className="min-w-full divide-y divide-gray-200">
                        <thead className="bg-gray-50">
                            <tr>
                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">用户</th>
                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">角色</th>
                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">微信昵称</th>
                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">绑定公众号</th>
                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">绑定时间</th>
                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">操作</th>
                            </tr>
                        </thead>
                        <tbody className="bg-white divide-y divide-gray-200">
                            {bindings.length === 0 ? (
                                <tr>
                                    <td colSpan="6" className="px-6 py-4 text-center text-gray-500">暂无绑定用户</td>
                                </tr>
                            ) : (
                                bindings.map((binding) => (
                                    <tr key={binding.id}>
                                        <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{binding.user_name}</td>
                                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            {binding.user_role === 'teacher' ? '班主任' : binding.user_role === 'student' ? '学生' : binding.user_role}
                                        </td>
                                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{binding.nickname}</td>
                                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{binding.config_owner}</td>
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
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}
