import React, { useEffect, useState } from 'react';
import Layout from '../../components/Layout';
import axios from 'axios';
import { CheckIcon, XMarkIcon, PhotoIcon, ChevronDownIcon, ChevronUpIcon } from '@heroicons/react/24/outline';
import clsx from 'clsx';
import { format } from 'date-fns';

export default function LeaveApprovals() {
    const [requests, setRequests] = useState([]);
    const [loading, setLoading] = useState(true);
    const [expandedImages, setExpandedImages] = useState({});
    const [lightboxImage, setLightboxImage] = useState(null);

    useEffect(() => {
        fetchRequests();
    }, []);

    const fetchRequests = async () => {
        try {
            const res = await axios.get('/leave-requests?status=pending');
            setRequests(res.data.data);
        } catch (error) {
            console.error(error);
        } finally {
            setLoading(false);
        }
    };

    const handleAction = async (id, action) => {
        if (!confirm(`确定要 ${action === 'approve' ? '通过' : '驳回'} 这条申请吗?`)) return;

        try {
            if (action === 'approve') {
                await axios.post(`/leave-requests/${id}/approve`);
            } else {
                await axios.post(`/leave-requests/${id}/reject`, { reason: 'Teacher rejected' });
            }
            // remove from list
            setRequests(prev => prev.filter(r => r.id !== id));
        } catch (error) {
            alert('操作失败');
        }
    };

    const toggleImages = (id) => {
        setExpandedImages(prev => ({ ...prev, [id]: !prev[id] }));
    };

    const getImageUrl = (path) => {
        if (path.startsWith('http')) return path;
        return `/storage/${path}`;
    };

    return (
        <Layout>
            <h2 className="text-2xl font-bold mb-6">待办请假审批</h2>

            {loading ? (
                <div>加载中...</div>
            ) : requests.length === 0 ? (
                <div className="bg-white p-8 rounded-lg shadow text-center text-gray-500">
                    暂无待审批的记录。
                </div>
            ) : (
                <div className="bg-white shadow overflow-hidden sm:rounded-md">
                    <ul role="list" className="divide-y divide-gray-200">
                        {requests.map((request) => (
                            <li key={request.id}>
                                <div className="px-4 py-4 sm:px-6">
                                    <div className="flex items-center justify-between">
                                        <div className="flex flex-col">
                                            <p className="text-sm font-medium text-indigo-600 truncate">{request.student?.user?.name || request.student?.name}</p>
                                            <p className="flex items-center text-sm text-gray-500">
                                                {request.leave_type?.name || request.type || '请假'} • {request.half_day_label || (request.half_day ? request.half_day : '全天')}
                                            </p>
                                        </div>
                                        <div className="ml-2 flex-shrink-0 flex gap-2">
                                            {/* Image indicator */}
                                            {request.images && request.images.length > 0 && (
                                                <span className="px-2 inline-flex items-center gap-1 text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">
                                                    <PhotoIcon className="h-3 w-3" />
                                                    {request.images.length}
                                                </span>
                                            )}
                                            <p className="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">
                                                {request.status === 'pending' ? '待审批' : request.status}
                                            </p>
                                        </div>
                                    </div>
                                    <div className="mt-2 text-sm text-gray-700">
                                        <p>日期: <span className="font-semibold">{format(new Date(request.start_date), 'MMM d, yyyy')}</span> - <span className="font-semibold">{format(new Date(request.end_date), 'MMM d, yyyy')}</span></p>
                                        <p className="mt-1 italic">"{request.reason}"</p>
                                    </div>

                                    {/* Images Section */}
                                    {request.images && request.images.length > 0 && (
                                        <div className="mt-3">
                                            <button
                                                onClick={() => toggleImages(request.id)}
                                                className="flex items-center gap-1 text-sm text-indigo-600 hover:text-indigo-800"
                                            >
                                                <PhotoIcon className="h-4 w-4" />
                                                {expandedImages[request.id] ? '收起附件' : `查看附件 (${request.images.length}张)`}
                                                {expandedImages[request.id] ? (
                                                    <ChevronUpIcon className="h-4 w-4" />
                                                ) : (
                                                    <ChevronDownIcon className="h-4 w-4" />
                                                )}
                                            </button>

                                            {expandedImages[request.id] && (
                                                <div className="mt-2 flex flex-wrap gap-2">
                                                    {request.images.map((path, index) => (
                                                        <img
                                                            key={index}
                                                            src={getImageUrl(path)}
                                                            alt={`附件 ${index + 1}`}
                                                            className="w-24 h-24 object-cover rounded-lg border border-gray-200 cursor-pointer hover:opacity-80 transition-opacity"
                                                            onClick={() => setLightboxImage(getImageUrl(path))}
                                                        />
                                                    ))}
                                                </div>
                                            )}
                                        </div>
                                    )}

                                    <div className="mt-4 flex justify-end space-x-3">
                                        <button
                                            onClick={() => handleAction(request.id, 'reject')}
                                            className="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-full shadow-sm text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500"
                                        >
                                            <XMarkIcon className="h-4 w-4 mr-1" />
                                            驳回
                                        </button>
                                        <button
                                            onClick={() => handleAction(request.id, 'approve')}
                                            className="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-full shadow-sm text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500"
                                        >
                                            <CheckIcon className="h-4 w-4 mr-1" />
                                            批准
                                        </button>
                                    </div>
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {/* Lightbox Modal */}
            {lightboxImage && (
                <div
                    className="fixed inset-0 z-50 bg-black bg-opacity-90 flex items-center justify-center p-4"
                    onClick={() => setLightboxImage(null)}
                >
                    <div className="relative max-w-4xl max-h-full">
                        <button
                            onClick={() => setLightboxImage(null)}
                            className="absolute top-2 right-2 bg-white/20 hover:bg-white/40 rounded-full p-2 transition-colors"
                        >
                            <XMarkIcon className="h-6 w-6 text-white" />
                        </button>
                        <img
                            src={lightboxImage}
                            alt="放大查看"
                            className="max-w-full max-h-[90vh] object-contain rounded-lg"
                            onClick={(e) => e.stopPropagation()}
                        />
                    </div>
                </div>
            )}
        </Layout>
    );
}
