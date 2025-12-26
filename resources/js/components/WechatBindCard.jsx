import { useState, useEffect } from 'react';
import axios from 'axios';
import { QrCodeIcon, CheckCircleIcon, XCircleIcon, ExclamationTriangleIcon, ArrowPathIcon } from '@heroicons/react/24/outline';

export default function WechatBindCard() {
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [qrcodeUrl, setQrcodeUrl] = useState('');
    const [qrcodeLoading, setQrcodeLoading] = useState(false);
    const [showModal, setShowModal] = useState(false);

    useEffect(() => {
        fetchStatus();
    }, []);

    const fetchStatus = async () => {
        try {
            const res = await axios.get('/wechat/manager-status');
            setData(res.data);
        } catch (error) {
            console.error('Failed to fetch wechat status', error);
        } finally {
            setLoading(false);
        }
    };

    const handleGetQrcode = async () => {
        setQrcodeLoading(true);
        try {
            const res = await axios.get('/wechat/qrcode', {
                params: { config_id: data?.config_id }
            });
            setQrcodeUrl(res.data.qrcode_url);
        } catch (error) {
            alert('获取二维码失败: ' + (error.response?.data?.error || error.message));
        } finally {
            setQrcodeLoading(false);
        }
    };

    const handleUnbind = async () => {
        if (!confirm('确定要解除微信绑定吗？')) return;
        try {
            await axios.delete('/wechat/manager-unbind');
            fetchStatus();
            setQrcodeUrl('');
        } catch (error) {
            alert('解除绑定失败: ' + (error.response?.data?.error || error.message));
        }
    };

    if (loading) {
        return (
            <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-4">
                <div className="animate-pulse flex items-center">
                    <div className="h-10 w-10 bg-gray-200 rounded-lg"></div>
                    <div className="ml-3 h-4 w-24 bg-gray-200 rounded"></div>
                </div>
            </div>
        );
    }

    // 功能未开启 - 显示禁用状态
    if (!data?.enabled) {
        return (
            <div className="bg-gray-100 rounded-xl shadow-sm border border-gray-200 p-4">
                <div className="flex items-center">
                    <div className="p-2 bg-gray-200 rounded-lg">
                        <QrCodeIcon className="h-6 w-6 text-gray-400" />
                    </div>
                    <div className="ml-3">
                        <h3 className="text-sm font-medium text-gray-400">微信推送</h3>
                        <p className="text-sm text-gray-400">{data?.message || '功能未开启'}</p>
                    </div>
                </div>
            </div>
        );
    }

    return (
        <>
            <div className="bg-gradient-to-r from-green-400 to-green-500 rounded-xl shadow-sm p-4 text-white">
                <div className="flex items-center justify-between">
                    <div className="flex items-center">
                        <div className="p-2 bg-white/20 rounded-lg">
                            <QrCodeIcon className="h-6 w-6" />
                        </div>
                        <div className="ml-3">
                            <h3 className="text-sm font-medium opacity-90">微信推送</h3>
                            {data?.my_binding ? (
                                <p className="text-lg font-bold">已绑定</p>
                            ) : (
                                <p className="text-lg font-bold">未绑定</p>
                            )}
                        </div>
                    </div>
                    <button
                        onClick={() => setShowModal(true)}
                        className="px-3 py-1.5 bg-white/20 hover:bg-white/30 rounded-lg text-sm font-medium transition-colors"
                    >
                        {data?.my_binding ? '查看' : '绑定'}
                    </button>
                </div>
            </div>

            {/* Modal */}
            {showModal && (
                <div className="fixed inset-0 z-50 overflow-y-auto">
                    <div className="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
                        <div className="fixed inset-0 transition-opacity" onClick={() => setShowModal(false)}>
                            <div className="absolute inset-0 bg-gray-500 opacity-75"></div>
                        </div>

                        <div className="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-md sm:w-full">
                            <div className="bg-white px-4 pt-5 pb-4 sm:p-6">
                                <div className="flex justify-between items-center mb-4">
                                    <h3 className="text-lg font-medium text-gray-900">微信推送通知</h3>
                                    <button
                                        onClick={() => setShowModal(false)}
                                        className="text-gray-400 hover:text-gray-500"
                                    >
                                        <XCircleIcon className="h-6 w-6" />
                                    </button>
                                </div>

                                {!data?.can_bind ? (
                                    // 班主任未配置
                                    <div className="text-center py-6">
                                        <ExclamationTriangleIcon className="h-12 w-12 text-yellow-500 mx-auto mb-4" />
                                        <p className="text-gray-600">{data?.message || '请提醒班主任配置自己的测试公众号'}</p>
                                    </div>
                                ) : data?.my_binding ? (
                                    // 已绑定
                                    <div className="text-center py-4">
                                        <CheckCircleIcon className="h-12 w-12 text-green-500 mx-auto mb-4" />
                                        <p className="text-gray-900 font-medium mb-2">已绑定微信</p>
                                        <p className="text-gray-500 text-sm mb-1">微信昵称: {data.my_binding.nickname}</p>
                                        <p className="text-gray-400 text-xs mb-4">绑定时间: {data.my_binding.bound_at}</p>
                                        <button
                                            onClick={handleUnbind}
                                            className="px-4 py-2 text-red-600 hover:text-red-800 text-sm"
                                        >
                                            解除绑定
                                        </button>
                                    </div>
                                ) : (
                                    // 未绑定，显示二维码
                                    <div className="text-center py-4">
                                        <p className="text-gray-600 mb-4">
                                            绑定后可收到班级请假申请通知
                                        </p>
                                        <p className="text-sm text-gray-500 mb-4">
                                            班主任: {data?.teacher_name}
                                        </p>

                                        {qrcodeUrl ? (
                                            <div>
                                                <img src={qrcodeUrl} alt="绑定二维码" className="w-48 h-48 mx-auto" />
                                                <p className="text-sm text-gray-500 mt-2">扫码关注公众号完成绑定</p>
                                                <p className="text-xs text-gray-400">10分钟有效</p>
                                                <button
                                                    onClick={handleGetQrcode}
                                                    disabled={qrcodeLoading}
                                                    className="mt-3 text-indigo-600 hover:text-indigo-800 text-sm flex items-center mx-auto"
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
                                                {qrcodeLoading ? '生成中...' : '获取绑定二维码'}
                                            </button>
                                        )}
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </>
    );
}
