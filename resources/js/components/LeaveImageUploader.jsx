import React, { useState, useRef } from 'react';
import axios from 'axios';
import { XMarkIcon, PhotoIcon, CameraIcon } from '@heroicons/react/24/outline';

export default function LeaveImageUploader({
    images = [],
    onChange,
    maxCount = 3,
    maxSizeMb = 5,
    allowedFormats = 'jpg,jpeg,png,gif,webp',
    disabled = false
}) {
    const [uploading, setUploading] = useState(false);
    const [error, setError] = useState(null);
    const fileInputRef = useRef(null);
    const cameraInputRef = useRef(null);

    const formatsList = allowedFormats.split(',').map(f => f.trim().toLowerCase());
    const acceptString = formatsList.map(f => `.${f}`).join(',');

    const handleFileSelect = async (e) => {
        const files = Array.from(e.target.files);
        if (files.length === 0) return;

        setError(null);

        // Check count limit
        if (images.length + files.length > maxCount) {
            setError(`最多只能上传 ${maxCount} 张图片`);
            return;
        }

        for (const file of files) {
            // Check file size
            if (file.size > maxSizeMb * 1024 * 1024) {
                setError(`图片 "${file.name}" 太大，请选择小于 ${maxSizeMb}MB 的图片`);
                continue;
            }

            // Check file type
            const ext = file.name.split('.').pop().toLowerCase();
            if (!formatsList.includes(ext)) {
                setError(`不支持的格式 "${ext}"，请选择 ${allowedFormats.toUpperCase()} 格式`);
                continue;
            }

            // Upload
            await uploadFile(file);
        }

        // Reset input
        e.target.value = '';
    };

    const uploadFile = async (file) => {
        setUploading(true);
        try {
            const formData = new FormData();
            formData.append('image', file);

            const res = await axios.post('/leave-image/upload', formData, {
                headers: { 'Content-Type': 'multipart/form-data' }
            });

            const newImages = [...images, res.data.path];
            onChange(newImages);
        } catch (err) {
            setError(err.response?.data?.error || '上传失败');
        } finally {
            setUploading(false);
        }
    };

    const handleRemove = async (index) => {
        const pathToRemove = images[index];

        try {
            await axios.delete('/leave-image', { data: { path: pathToRemove } });
        } catch (err) {
            console.warn('Failed to delete image from server:', err);
        }

        const newImages = images.filter((_, i) => i !== index);
        onChange(newImages);
    };

    const getImageUrl = (path) => {
        if (path.startsWith('http')) return path;
        return `/storage/${path}`;
    };

    return (
        <div className="space-y-3">
            <label className="block text-sm font-medium text-gray-700">
                📷 上传证明材料（可选，最多{maxCount}张）
            </label>

            {/* Image Preview Grid */}
            <div className="flex flex-wrap gap-3">
                {images.map((path, index) => (
                    <div key={index} className="relative group">
                        <img
                            src={getImageUrl(path)}
                            alt={`上传图片 ${index + 1}`}
                            className="w-20 h-20 object-cover rounded-lg border border-gray-200"
                        />
                        {!disabled && (
                            <button
                                type="button"
                                onClick={() => handleRemove(index)}
                                className="absolute -top-2 -right-2 bg-red-500 text-white rounded-full p-1 opacity-0 group-hover:opacity-100 transition-opacity shadow-md"
                            >
                                <XMarkIcon className="h-3 w-3" />
                            </button>
                        )}
                    </div>
                ))}

                {/* Add Button */}
                {!disabled && images.length < maxCount && (
                    <div className="flex gap-2">
                        {/* Camera Button (mobile) */}
                        <button
                            type="button"
                            onClick={() => cameraInputRef.current?.click()}
                            disabled={uploading}
                            className="w-20 h-20 flex flex-col items-center justify-center border-2 border-dashed border-gray-300 rounded-lg hover:border-indigo-400 hover:bg-indigo-50 transition-colors disabled:opacity-50"
                        >
                            <CameraIcon className="h-6 w-6 text-gray-400" />
                            <span className="text-xs text-gray-500 mt-1">拍照</span>
                        </button>

                        {/* Gallery Button */}
                        <button
                            type="button"
                            onClick={() => fileInputRef.current?.click()}
                            disabled={uploading}
                            className="w-20 h-20 flex flex-col items-center justify-center border-2 border-dashed border-gray-300 rounded-lg hover:border-indigo-400 hover:bg-indigo-50 transition-colors disabled:opacity-50"
                        >
                            <PhotoIcon className="h-6 w-6 text-gray-400" />
                            <span className="text-xs text-gray-500 mt-1">相册</span>
                        </button>
                    </div>
                )}
            </div>

            {/* Hidden Inputs */}
            <input
                ref={fileInputRef}
                type="file"
                accept={acceptString}
                multiple
                onChange={handleFileSelect}
                className="hidden"
            />
            <input
                ref={cameraInputRef}
                type="file"
                accept="image/*"
                capture="environment"
                onChange={handleFileSelect}
                className="hidden"
            />

            {/* Upload Progress */}
            {uploading && (
                <div className="flex items-center gap-2 text-sm text-gray-500">
                    <div className="animate-spin rounded-full h-4 w-4 border-b-2 border-indigo-600"></div>
                    上传中...
                </div>
            )}

            {/* Error Message */}
            {error && (
                <p className="text-sm text-red-500">{error}</p>
            )}

            {/* Format Hint */}
            <p className="text-xs text-gray-400">
                支持 {allowedFormats.toUpperCase()} 格式，单张不超过 {maxSizeMb}MB
            </p>
        </div>
    );
}
