import React, { useState, useEffect } from 'react';
import axios from 'axios';
import { useNavigate } from 'react-router-dom';

const InstallWizard = () => {
    const navigate = useNavigate();
    const [step, setStep] = useState(1);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [success, setSuccess] = useState('');

    // Environment check data
    const [requirements, setRequirements] = useState(null);
    const [reqPassed, setReqPassed] = useState(false);

    // Form data
    const [formData, setFormData] = useState({
        db_host: '127.0.0.1',
        db_port: '3306',
        db_database: '',
        db_username: '',
        db_password: '',
        school_name: '',
        admin_name: 'Admin',
        admin_email: '',
        admin_password: '',
        admin_password_confirm: '',
    });

    const [dbTestResult, setDbTestResult] = useState(null);

    // Check if already installed on mount
    useEffect(() => {
        checkInstalled();
    }, []);

    const checkInstalled = async () => {
        try {
            const response = await axios.get('/install/check');
            if (response.data.installed) {
                navigate('/login');
            }
        } catch (err) {
            // Ignore errors, continue with install
        }
    };

    // Step 1: Check requirements
    const checkRequirements = async () => {
        setLoading(true);
        setError('');
        try {
            const response = await axios.get('/install/requirements');
            setRequirements(response.data.requirements);
            setReqPassed(response.data.passed);
            if (response.data.passed) {
                setStep(2);
            }
        } catch (err) {
            setError('环境检测失败: ' + (err.response?.data?.message || err.message));
        } finally {
            setLoading(false);
        }
    };

    // Step 2: Test database connection
    const testDatabase = async () => {
        setLoading(true);
        setError('');
        setDbTestResult(null);
        try {
            const response = await axios.post('/install/test-database', {
                host: formData.db_host,
                port: formData.db_port,
                database: formData.db_database,
                username: formData.db_username,
                password: formData.db_password,
            });
            setDbTestResult(response.data);
            if (response.data.success) {
                setStep(3);
            }
        } catch (err) {
            setDbTestResult({ success: false, message: err.response?.data?.message || '连接失败' });
        } finally {
            setLoading(false);
        }
    };

    // Step 4: Run installation
    const runInstall = async () => {
        if (formData.admin_password !== formData.admin_password_confirm) {
            setError('两次输入的密码不一致');
            return;
        }
        if (formData.admin_password.length < 6) {
            setError('密码长度至少6位');
            return;
        }

        setLoading(true);
        setError('');
        try {
            const response = await axios.post('/install/run', formData);
            if (response.data.success) {
                setSuccess('安装成功！正在跳转到登录页面...');
                setStep(5);
                setTimeout(() => {
                    navigate('/login');
                }, 3000);
            }
        } catch (err) {
            setError('安装失败: ' + (err.response?.data?.error || err.message));
        } finally {
            setLoading(false);
        }
    };

    const handleInputChange = (e) => {
        const { name, value } = e.target;
        setFormData(prev => ({ ...prev, [name]: value }));
    };

    // Render step content
    const renderStep = () => {
        switch (step) {
            case 1:
                return renderWelcome();
            case 2:
                return renderDatabaseConfig();
            case 3:
                return renderSiteConfig();
            case 4:
                return renderConfirm();
            case 5:
                return renderComplete();
            default:
                return null;
        }
    };

    // Step 1: Welcome & Requirements
    const renderWelcome = () => (
        <div className="space-y-6">
            <div className="text-center mb-8">
                <div className="text-6xl mb-4">🎓</div>
                <h2 className="text-2xl font-bold text-gray-800">欢迎安装智慧校园考勤系统</h2>
                <p className="text-gray-600 mt-2">请按照向导完成系统安装</p>
            </div>

            {requirements && (
                <div className="space-y-4">
                    {/* PHP Version */}
                    <div className="bg-gray-50 rounded-lg p-4">
                        <h3 className="font-medium mb-2">PHP 版本</h3>
                        <div className={`flex items-center justify-between ${requirements.php_version.passed ? 'text-green-600' : 'text-red-600'}`}>
                            <span>需要: {requirements.php_version.required}+</span>
                            <span>当前: {requirements.php_version.current} {requirements.php_version.passed ? '✓' : '✗'}</span>
                        </div>
                    </div>

                    {/* Extensions */}
                    <div className="bg-gray-50 rounded-lg p-4">
                        <h3 className="font-medium mb-2">PHP 扩展</h3>
                        <div className="grid grid-cols-2 gap-2">
                            {requirements.extensions.map(ext => (
                                <div key={ext.name} className={`flex items-center ${ext.installed ? 'text-green-600' : 'text-red-600'}`}>
                                    <span className="mr-2">{ext.installed ? '✓' : '✗'}</span>
                                    <span>{ext.name}</span>
                                </div>
                            ))}
                        </div>
                    </div>

                    {/* Directories */}
                    <div className="bg-gray-50 rounded-lg p-4">
                        <h3 className="font-medium mb-2">目录权限</h3>
                        <div className="space-y-1">
                            {Object.values(requirements.directories).map(dir => (
                                <div key={dir.name} className={`flex items-center justify-between ${dir.writable ? 'text-green-600' : 'text-red-600'}`}>
                                    <span>{dir.name}</span>
                                    <span>{dir.writable ? '可写 ✓' : '不可写 ✗'}</span>
                                </div>
                            ))}
                        </div>
                    </div>

                    {!reqPassed && (
                        <div className="bg-red-50 border border-red-200 rounded-lg p-4 text-red-700">
                            <p className="font-medium">环境检测未通过</p>
                            <p className="text-sm">请修复上述问题后重新检测</p>
                        </div>
                    )}
                </div>
            )}

            <div className="flex justify-center">
                <button
                    onClick={checkRequirements}
                    disabled={loading}
                    className="px-8 py-3 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50 font-medium"
                >
                    {loading ? '检测中...' : (requirements ? '重新检测' : '开始检测环境')}
                </button>
            </div>
        </div>
    );

    // Step 2: Database Configuration
    const renderDatabaseConfig = () => (
        <div className="space-y-6">
            <div className="text-center mb-6">
                <h2 className="text-2xl font-bold text-gray-800">数据库配置</h2>
                <p className="text-gray-600 mt-2">请输入数据库连接信息</p>
            </div>

            <div className="grid grid-cols-2 gap-4">
                <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">主机地址</label>
                    <input
                        type="text"
                        name="db_host"
                        value={formData.db_host}
                        onChange={handleInputChange}
                        className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                        placeholder="127.0.0.1"
                    />
                </div>
                <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">端口</label>
                    <input
                        type="text"
                        name="db_port"
                        value={formData.db_port}
                        onChange={handleInputChange}
                        className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                        placeholder="3306"
                    />
                </div>
            </div>

            <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">数据库名</label>
                <input
                    type="text"
                    name="db_database"
                    value={formData.db_database}
                    onChange={handleInputChange}
                    className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                    placeholder="attendance"
                />
            </div>

            <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">用户名</label>
                <input
                    type="text"
                    name="db_username"
                    value={formData.db_username}
                    onChange={handleInputChange}
                    className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                    placeholder="root"
                />
            </div>

            <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">密码</label>
                <input
                    type="password"
                    name="db_password"
                    value={formData.db_password}
                    onChange={handleInputChange}
                    className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                    placeholder="••••••••"
                />
            </div>

            {dbTestResult && (
                <div className={`p-4 rounded-lg ${dbTestResult.success ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'}`}>
                    {dbTestResult.message}
                    {dbTestResult.has_tables && (
                        <p className="text-sm mt-1">⚠️ 数据库中已有 {dbTestResult.table_count} 张表，安装将覆盖现有数据</p>
                    )}
                </div>
            )}

            <div className="flex justify-between">
                <button
                    onClick={() => setStep(1)}
                    className="px-6 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50"
                >
                    上一步
                </button>
                <button
                    onClick={testDatabase}
                    disabled={loading || !formData.db_database || !formData.db_username}
                    className="px-8 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50"
                >
                    {loading ? '测试中...' : '测试连接并继续'}
                </button>
            </div>
        </div>
    );

    // Step 3: Site Configuration
    const renderSiteConfig = () => (
        <div className="space-y-6">
            <div className="text-center mb-6">
                <h2 className="text-2xl font-bold text-gray-800">站点配置</h2>
                <p className="text-gray-600 mt-2">设置学校信息和管理员账户</p>
            </div>

            <div className="bg-gray-50 rounded-lg p-4">
                <h3 className="font-medium mb-4">学校信息</h3>
                <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">学校名称</label>
                    <input
                        type="text"
                        name="school_name"
                        value={formData.school_name}
                        onChange={handleInputChange}
                        className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                        placeholder="智慧校园"
                    />
                </div>
            </div>

            <div className="bg-gray-50 rounded-lg p-4">
                <h3 className="font-medium mb-4">管理员账户</h3>
                <div className="space-y-4">
                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">管理员姓名</label>
                        <input
                            type="text"
                            name="admin_name"
                            value={formData.admin_name}
                            onChange={handleInputChange}
                            className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                            placeholder="Admin"
                        />
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">邮箱</label>
                        <input
                            type="email"
                            name="admin_email"
                            value={formData.admin_email}
                            onChange={handleInputChange}
                            className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                            placeholder="admin@example.com"
                        />
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">密码</label>
                        <input
                            type="password"
                            name="admin_password"
                            value={formData.admin_password}
                            onChange={handleInputChange}
                            className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                            placeholder="至少6位"
                        />
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">确认密码</label>
                        <input
                            type="password"
                            name="admin_password_confirm"
                            value={formData.admin_password_confirm}
                            onChange={handleInputChange}
                            className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                            placeholder="再次输入密码"
                        />
                    </div>
                </div>
            </div>

            <div className="flex justify-between">
                <button
                    onClick={() => setStep(2)}
                    className="px-6 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50"
                >
                    上一步
                </button>
                <button
                    onClick={() => setStep(4)}
                    disabled={!formData.school_name || !formData.admin_email || !formData.admin_password}
                    className="px-8 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50"
                >
                    下一步
                </button>
            </div>
        </div>
    );

    // Step 4: Confirmation
    const renderConfirm = () => (
        <div className="space-y-6">
            <div className="text-center mb-6">
                <h2 className="text-2xl font-bold text-gray-800">确认安装</h2>
                <p className="text-gray-600 mt-2">请确认以下信息无误</p>
            </div>

            <div className="bg-gray-50 rounded-lg p-4 space-y-4">
                <div>
                    <h3 className="font-medium text-gray-500">数据库</h3>
                    <p>{formData.db_username}@{formData.db_host}:{formData.db_port}/{formData.db_database}</p>
                </div>
                <div>
                    <h3 className="font-medium text-gray-500">学校名称</h3>
                    <p>{formData.school_name}</p>
                </div>
                <div>
                    <h3 className="font-medium text-gray-500">管理员</h3>
                    <p>{formData.admin_name} ({formData.admin_email})</p>
                </div>
            </div>

            <div className="bg-yellow-50 border border-yellow-200 rounded-lg p-4 text-yellow-800">
                <p className="font-medium">⚠️ 注意</p>
                <ul className="text-sm mt-2 space-y-1">
                    <li>• 安装将创建数据库表结构</li>
                    <li>• 如果数据库已有数据，可能会被覆盖</li>
                    <li>• 安装完成后请妥善保管管理员密码</li>
                </ul>
            </div>

            <div className="flex justify-between">
                <button
                    onClick={() => setStep(3)}
                    disabled={loading}
                    className="px-6 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 disabled:opacity-50"
                >
                    上一步
                </button>
                <button
                    onClick={runInstall}
                    disabled={loading}
                    className="px-8 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50"
                >
                    {loading ? '正在安装...' : '开始安装'}
                </button>
            </div>
        </div>
    );

    // Step 5: Complete
    const renderComplete = () => (
        <div className="text-center space-y-6">
            <div className="text-6xl">🎉</div>
            <h2 className="text-2xl font-bold text-green-600">安装成功！</h2>
            <p className="text-gray-600">系统已成功安装，即将跳转到登录页面...</p>
            <div className="bg-gray-50 rounded-lg p-4 inline-block">
                <p className="text-gray-500">管理员账号</p>
                <p className="font-mono text-lg">{formData.admin_email}</p>
            </div>
            <div>
                <button
                    onClick={() => navigate('/login')}
                    className="px-8 py-3 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700"
                >
                    立即登录
                </button>
            </div>
        </div>
    );

    return (
        <div className="min-h-screen bg-gradient-to-br from-indigo-100 via-purple-50 to-pink-100 flex items-center justify-center p-4">
            <div className="bg-white rounded-2xl shadow-xl w-full max-w-2xl p-8">
                {/* Progress Steps */}
                <div className="flex items-center justify-center mb-8">
                    {[1, 2, 3, 4, 5].map((s) => (
                        <React.Fragment key={s}>
                            <div className={`w-8 h-8 rounded-full flex items-center justify-center text-sm font-medium
                                ${step >= s ? 'bg-indigo-600 text-white' : 'bg-gray-200 text-gray-500'}`}>
                                {step > s ? '✓' : s}
                            </div>
                            {s < 5 && (
                                <div className={`w-12 h-1 mx-1 ${step > s ? 'bg-indigo-600' : 'bg-gray-200'}`} />
                            )}
                        </React.Fragment>
                    ))}
                </div>

                {/* Error Message */}
                {error && (
                    <div className="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg text-red-700">
                        {error}
                    </div>
                )}

                {/* Success Message */}
                {success && (
                    <div className="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg text-green-700">
                        {success}
                    </div>
                )}

                {/* Step Content */}
                {renderStep()}
            </div>
        </div>
    );
};

export default InstallWizard;
