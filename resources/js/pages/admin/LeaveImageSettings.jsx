import React, { useState, useEffect } from 'react';
import axios from 'axios';
import { XMarkIcon, PlusIcon } from '@heroicons/react/24/outline';

export default function LeaveImageSettings() {
    const [settings, setSettings] = useState({
        enabled: false,
        max_count: 3,
        max_size_mb: 5,
        allowed_formats: 'jpg,jpeg,png,gif,webp',
        auto_cleanup: true,
        retention_days: 90
    });
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [newFormat, setNewFormat] = useState('');

    useEffect(() => {
        fetchSettings();
    }, []);

    const fetchSettings = async () => {
        try {
            const res = await axios.get('/leave-image/settings');
            setSettings(res.data);
        } catch (err) {
            console.error('Failed to fetch settings:', err);
        } finally {
            setLoading(false);
        }
    };

    const handleSave = async () => {
        setSaving(true);
        try {
            await axios.put('/leave-image/settings', settings);
            alert('设置已保存');
        } catch (err) {
            alert('保存失败: ' + (err.response?.data?.error || err.message));
        } finally {
            setSaving(false);
        }
    };

    const formatsList = settings.allowed_formats.split(',').map(f => f.trim()).filter(f => f);

    const addFormat = () => {
        const format = newFormat.trim().toLowerCase().replace(/^\./, '');
        if (!format) return;
        if (formatsList.includes(format)) {
            alert('该格式已存在');
            return;
        }
        const updated = [...formatsList, format].join(',');
        setSettings({ ...settings, allowed_formats: updated });
        setNewFormat('');
    };

    const removeFormat = (format) => {
        if (formatsList.length <= 1) {
            alert('至少保留一种格式');
            return;
        }
        const updated = formatsList.filter(f => f !== format).join(',');
        setSettings({ ...settings, allowed_formats: updated });
    };

    if (loading) {
        return (
            <div className="flex items-center justify-center py-8">
                <div className="animate-spin rounded-full h-6 w-6 border-b-2 border-indigo-600"></div>
            </div>
        );
    }

    return (
        <div>
            <h4 className="text-md font-bold text-gray-700 mb-4">📷 请假图片设置</h4>

            <div className="bg-white p-6 rounded-lg border shadow-sm space-y-6">
                {/* Enable/Disable */}
                <div className="flex items-center justify-between">
                    <div>
                        <label className="block text-sm font-medium text-gray-700">启用图片上传</label>
                        <p className="text-xs text-gray-500">允许学生在请假申请时上传证明材料</p>
                    </div>
                    <button
                        type="button"
                        onClick={() => setSettings({ ...settings, enabled: !settings.enabled })}
                        className={`relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none ${settings.enabled ? 'bg-indigo-600' : 'bg-gray-200'
                            }`}
                    >
                        <span
                            className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${settings.enabled ? 'translate-x-5' : 'translate-x-0'
                                }`}
                        />
                    </button>
                </div>

                {settings.enabled && (
                    <>
                        {/* Max Count */}
                        <div>
                            <label className="block text-sm font-medium text-gray-700">最大上传数量</label>
                            <p className="text-xs text-gray-500 mb-2">每次请假最多可上传的图片数量</p>
                            <input
                                type="number"
                                min="1"
                                max="10"
                                value={settings.max_count}
                                onChange={(e) => setSettings({ ...settings, max_count: parseInt(e.target.value) || 3 })}
                                className="mt-1 block w-32 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                            />
                        </div>

                        {/* Max Size */}
                        <div>
                            <label className="block text-sm font-medium text-gray-700">单张大小限制 (MB)</label>
                            <p className="text-xs text-gray-500 mb-2">超过此大小的图片将被拒绝上传</p>
                            <input
                                type="number"
                                min="1"
                                max="20"
                                value={settings.max_size_mb}
                                onChange={(e) => setSettings({ ...settings, max_size_mb: parseInt(e.target.value) || 5 })}
                                className="mt-1 block w-32 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                            />
                        </div>

                        {/* Allowed Formats */}
                        <div>
                            <label className="block text-sm font-medium text-gray-700">支持的文件格式</label>
                            <p className="text-xs text-gray-500 mb-2">点击标签删除，输入框添加新格式</p>
                            <div className="flex flex-wrap gap-2 mb-2">
                                {formatsList.map((format) => (
                                    <span
                                        key={format}
                                        className="inline-flex items-center gap-1 px-2 py-1 bg-indigo-100 text-indigo-700 rounded-full text-sm"
                                    >
                                        {format.toUpperCase()}
                                        <button
                                            type="button"
                                            onClick={() => removeFormat(format)}
                                            className="hover:text-red-600"
                                        >
                                            <XMarkIcon className="h-3 w-3" />
                                        </button>
                                    </span>
                                ))}
                            </div>
                            <div className="flex gap-2">
                                <input
                                    type="text"
                                    value={newFormat}
                                    onChange={(e) => setNewFormat(e.target.value)}
                                    onKeyPress={(e) => e.key === 'Enter' && (e.preventDefault(), addFormat())}
                                    placeholder="输入格式后回车添加"
                                    className="block w-48 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                />
                                <button
                                    type="button"
                                    onClick={addFormat}
                                    className="inline-flex items-center px-3 py-2 border border-gray-300 rounded-md text-sm text-gray-700 bg-white hover:bg-gray-50"
                                >
                                    <PlusIcon className="h-4 w-4 mr-1" />
                                    添加
                                </button>
                            </div>
                        </div>

                        <hr className="my-4" />

                        {/* Auto Cleanup */}
                        <div className="flex items-center justify-between">
                            <div>
                                <label className="block text-sm font-medium text-gray-700">自动清理旧图片</label>
                                <p className="text-xs text-gray-500">定期删除过期的图片文件以节省存储空间</p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setSettings({ ...settings, auto_cleanup: !settings.auto_cleanup })}
                                className={`relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none ${settings.auto_cleanup ? 'bg-indigo-600' : 'bg-gray-200'
                                    }`}
                            >
                                <span
                                    className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${settings.auto_cleanup ? 'translate-x-5' : 'translate-x-0'
                                        }`}
                                />
                            </button>
                        </div>

                        {settings.auto_cleanup && (
                            <div>
                                <label className="block text-sm font-medium text-gray-700">保留天数</label>
                                <p className="text-xs text-gray-500 mb-2">超过此天数的图片将被自动删除</p>
                                <input
                                    type="number"
                                    min="1"
                                    max="365"
                                    value={settings.retention_days}
                                    onChange={(e) => setSettings({ ...settings, retention_days: parseInt(e.target.value) || 90 })}
                                    className="mt-1 block w-32 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                />
                            </div>
                        )}

                        {!settings.auto_cleanup && (
                            <p className="text-sm text-amber-600 bg-amber-50 p-3 rounded-lg">
                                ⚠️ 自动清理已关闭，图片将永久保留。请注意服务器存储空间。
                            </p>
                        )}
                    </>
                )}

                {/* Save Button */}
                <div className="flex justify-end pt-4 border-t">
                    <button
                        type="button"
                        onClick={handleSave}
                        disabled={saving}
                        className="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none disabled:opacity-50"
                    >
                        {saving ? '保存中...' : '保存设置'}
                    </button>
                </div>
            </div>
        </div>
    );
}
