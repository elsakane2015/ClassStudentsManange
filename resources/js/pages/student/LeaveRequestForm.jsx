import React, { useState, useEffect } from 'react';
import Layout from '../../components/Layout';
import axios from 'axios';
import { useNavigate, useSearchParams } from 'react-router-dom';
import useAuthStore from '../../store/authStore';
import LeaveImageUploader from '../../components/LeaveImageUploader';

export default function LeaveRequestForm() {
    const { user } = useAuthStore();
    const navigate = useNavigate();
    const [searchParams] = useSearchParams();

    const [leaveTypes, setLeaveTypes] = useState([]);
    const [periods, setPeriods] = useState([]);
    const [imageSettings, setImageSettings] = useState({
        enabled: false,
        max_count: 3,
        max_size_mb: 5,
        allowed_formats: 'jpg,jpeg,png,gif,webp'
    });
    const [formData, setFormData] = useState({
        type: '',
        start_date: searchParams.get('start') || '',
        end_date: searchParams.get('end') || '',
        half_day: '',
        reason: '',
        details: {},
        images: []
    });
    const [error, setError] = useState(null);
    const [submitting, setSubmitting] = useState(false);

    // Fetch leave types and image settings on mount
    useEffect(() => {
        const fetchLeaveTypes = async () => {
            try {
                const response = await axios.get('/leave-types');
                const studentGender = user?.student?.gender;

                const studentTypes = response.data.filter(type => {
                    if (!type.is_active || !type.student_requestable) return false;

                    if (type.gender_restriction === 'all') return true;
                    if (type.gender_restriction === 'female' && studentGender === 'female') return true;
                    if (type.gender_restriction === 'male' && studentGender === 'male') return true;

                    return type.gender_restriction === 'all';
                });

                setLeaveTypes(studentTypes);
                if (studentTypes.length > 0 && !formData.type) {
                    setFormData(prev => ({ ...prev, type: studentTypes[0].slug }));
                }
            } catch (err) {
                console.error('Failed to fetch leave types:', err);
            }
        };

        const fetchImageSettings = async () => {
            try {
                const res = await axios.get('/leave-image/settings');
                setImageSettings(res.data);
            } catch (err) {
                console.error('Failed to fetch image settings:', err);
            }
        };

        fetchLeaveTypes();
        fetchImageSettings();
    }, [user]);

    // Fetch class periods for period_select types
    useEffect(() => {
        const fetchPeriods = async () => {
            try {
                const response = await axios.get('/class-periods');
                setPeriods(response.data.data || response.data || []);
            } catch (err) {
                console.error('Failed to fetch periods:', err);
            }
        };
        fetchPeriods();
    }, []);

    // Set default option when leave type changes and has duration_select
    useEffect(() => {
        if (!formData.type || !leaveTypes.length) return;

        const selectedType = leaveTypes.find(t => t.slug === formData.type);
        if (!selectedType) return;

        const inputType = selectedType.input_type;
        if (inputType === 'duration_select') {
            let config = selectedType.input_config;
            if (typeof config === 'string') {
                try { config = JSON.parse(config); } catch (e) { config = {}; }
            }

            const options = config?.options || [];
            if (options.length > 0 && !formData.details.option) {
                const firstOptKey = typeof options[0] === 'object' ? options[0].key : options[0];
                setFormData(prev => ({
                    ...prev,
                    details: { ...prev.details, option: firstOptKey }
                }));
            }
        }
    }, [formData.type, leaveTypes]);

    const handleChange = (e) => {
        const { name, value } = e.target;
        setFormData(prev => ({ ...prev, [name]: value }));

        if (name === 'type') {
            setFormData(prev => ({ ...prev, [name]: value, details: {}, half_day: '' }));
        }
    };

    const handleDetailsChange = (key, value) => {
        setFormData(prev => ({
            ...prev,
            details: { ...prev.details, [key]: value }
        }));
    };

    const handlePeriodToggle = (periodId) => {
        setFormData(prev => {
            const currentPeriods = prev.details.periods || [];
            const newPeriods = currentPeriods.includes(periodId)
                ? currentPeriods.filter(p => p !== periodId)
                : [...currentPeriods, periodId];
            return {
                ...prev,
                details: { ...prev.details, periods: newPeriods }
            };
        });
    };

    const handleImagesChange = (images) => {
        setFormData(prev => ({ ...prev, images }));
    };

    const handleSubmit = async (e) => {
        e.preventDefault();
        setSubmitting(true);
        setError(null);

        try {
            await axios.post('/leave-requests', {
                ...formData,
                half_day: formData.half_day || null,
                details: Object.keys(formData.details).length > 0 ? formData.details : null,
                images: formData.images.length > 0 ? formData.images : null
            });
            alert('请假申请提交成功！');
            navigate('/student/dashboard');
        } catch (err) {
            console.error(err);
            if (err.response?.status === 409) {
                setError("检测到冲突！该日期您已有请假或考勤记录。");
            } else {
                setError(err.response?.data?.message || '提交失败，请重试。');
            }
        } finally {
            setSubmitting(false);
        }
    };

    const selectedLeaveType = leaveTypes.find(type => type.slug === formData.type);

    const getInputConfig = () => {
        if (!selectedLeaveType?.input_config) return {};
        try {
            return typeof selectedLeaveType.input_config === 'string'
                ? JSON.parse(selectedLeaveType.input_config)
                : selectedLeaveType.input_config;
        } catch (e) {
            console.error('Failed to parse input_config:', e);
            return {};
        }
    };

    const inputConfig = getInputConfig();
    const inputType = selectedLeaveType?.input_type || 'none';

    const renderTypeSpecificInputs = () => {
        if (!selectedLeaveType || inputType === 'none') return null;

        switch (inputType) {
            case 'time':
                return (
                    <div className="space-y-4">
                        <div>
                            <label className="block text-sm font-medium text-gray-700">时间</label>
                            <input
                                type="time"
                                value={formData.details.time || ''}
                                onChange={(e) => handleDetailsChange('time', e.target.value)}
                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm border p-2"
                            />
                        </div>
                        {inputConfig.require_period && (
                            <div>
                                <label className="block text-sm font-medium text-gray-700">选择节次</label>
                                <select
                                    value={formData.details.period || ''}
                                    onChange={(e) => handleDetailsChange('period', parseInt(e.target.value))}
                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm border p-2"
                                >
                                    <option value="">请选择节次</option>
                                    {periods.map((p, index) => (
                                        <option key={p.id} value={p.id}>第{index + 1}节</option>
                                    ))}
                                </select>
                            </div>
                        )}
                    </div>
                );

            case 'period_select':
                if (inputConfig.options && inputConfig.options.length > 0) {
                    return (
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-2">选择选项</label>
                            <div className="space-y-2">
                                {inputConfig.options.map(opt => {
                                    const optKey = typeof opt === 'object' ? opt.key : opt;
                                    const optLabel = typeof opt === 'object' && opt.label ? opt.label : optKey;
                                    return (
                                        <label key={optKey} className="flex items-center">
                                            <input
                                                type="radio"
                                                name="period_option"
                                                className="h-4 w-4 text-indigo-600 border-gray-300 focus:ring-indigo-500"
                                                checked={formData.details.option === optKey}
                                                onChange={() => handleDetailsChange('option', optKey)}
                                            />
                                            <span className="ml-2 text-sm text-gray-700">{optLabel}</span>
                                        </label>
                                    );
                                })}
                            </div>
                        </div>
                    );
                }

                return (
                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-2">选择节次</label>
                        <div className="grid grid-cols-4 gap-2">
                            {periods.map((p, index) => (
                                <label
                                    key={p.id}
                                    className={`flex items-center justify-center p-2 border rounded cursor-pointer ${formData.details.periods?.includes(p.id)
                                        ? 'bg-indigo-100 border-indigo-500 text-indigo-700'
                                        : 'hover:bg-gray-50'
                                        }`}
                                >
                                    <input
                                        type="checkbox"
                                        className="sr-only"
                                        checked={formData.details.periods?.includes(p.id) || false}
                                        onChange={() => handlePeriodToggle(p.id)}
                                    />
                                    第{index + 1}节
                                </label>
                            ))}
                        </div>
                    </div>
                );

            case 'duration_select':
                const durationOptions = inputConfig.options || [];
                if (durationOptions.length === 0) return null;

                if (formData.start_date && formData.end_date && formData.start_date !== formData.end_date) return null;

                return (
                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-2">选择时长</label>
                        <div className="space-y-2">
                            {durationOptions.map((opt, index) => {
                                const optKey = typeof opt === 'object' ? opt.key : opt;
                                const optLabel = typeof opt === 'object' && opt.label ? opt.label : optKey;
                                return (
                                    <label key={optKey} className="flex items-center">
                                        <input
                                            type="radio"
                                            name="duration_option"
                                            className="h-4 w-4 text-indigo-600 border-gray-300 focus:ring-indigo-500"
                                            checked={formData.details.option === optKey || (index === 0 && !formData.details.option)}
                                            onChange={() => handleDetailsChange('option', optKey)}
                                        />
                                        <span className="ml-2 text-sm text-gray-700">{optLabel}</span>
                                    </label>
                                );
                            })}
                        </div>
                    </div>
                );

            default:
                return null;
        }
    };

    return (
        <Layout>
            <div className="max-w-2xl mx-auto bg-white p-8 rounded-lg shadow-md">
                <h2 className="text-2xl font-bold mb-6">新建请假申请</h2>

                {error && (
                    <div className="mb-4 bg-red-50 text-red-700 p-4 rounded">
                        {error}
                    </div>
                )}

                <form onSubmit={handleSubmit} className="space-y-6">
                    <div className="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        <div>
                            <label className="block text-sm font-medium text-gray-700">请假类型</label>
                            <select
                                name="type"
                                value={formData.type}
                                onChange={handleChange}
                                required
                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm border p-2"
                            >
                                <option value="">-- 请选择 --</option>
                                {leaveTypes.map(type => (
                                    <option key={type.id} value={type.slug}>
                                        {type.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>

                    <div className="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        <div>
                            <label className="block text-sm font-medium text-gray-700">开始日期</label>
                            <input
                                type="date"
                                name="start_date"
                                required
                                value={formData.start_date}
                                onChange={handleChange}
                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm border p-2"
                            />
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-gray-700">结束日期</label>
                            <input
                                type="date"
                                name="end_date"
                                required
                                value={formData.end_date}
                                onChange={handleChange}
                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm border p-2"
                            />
                        </div>
                    </div>

                    {renderTypeSpecificInputs()}

                    <div>
                        <label className="block text-sm font-medium text-gray-700">申请理由</label>
                        <textarea
                            name="reason"
                            rows="4"
                            required
                            value={formData.reason}
                            onChange={handleChange}
                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm border p-2"
                            placeholder="请说明请假原因..."
                        ></textarea>
                    </div>

                    {/* Image Upload */}
                    {imageSettings.enabled && (
                        <LeaveImageUploader
                            images={formData.images}
                            onChange={handleImagesChange}
                            maxCount={imageSettings.max_count}
                            maxSizeMb={imageSettings.max_size_mb}
                            allowedFormats={imageSettings.allowed_formats}
                        />
                    )}

                    <div className="flex justify-end">
                        <button
                            type="button"
                            onClick={() => navigate('/student/dashboard')}
                            className="mr-3 bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none"
                        >
                            取消
                        </button>
                        <button
                            type="submit"
                            disabled={submitting}
                            className="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 disabled:opacity-50"
                        >
                            {submitting ? '提交中...' : '提交申请'}
                        </button>
                    </div>
                </form>
            </div>
        </Layout>
    );
}
