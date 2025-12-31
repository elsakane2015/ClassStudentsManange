import React, { useEffect, useState } from 'react';
import Layout from '../../components/Layout';
import axios from 'axios';
import { format } from 'date-fns';
import { useSearchParams } from 'react-router-dom';
import { PhotoIcon, ChevronDownIcon, ChevronUpIcon, XMarkIcon, CheckIcon } from '@heroicons/react/24/outline';

export default function ApprovalHistory() {
    const [searchParams, setSearchParams] = useSearchParams();
    const [requests, setRequests] = useState([]);
    const [loading, setLoading] = useState(true);
    const [filter, setFilter] = useState(searchParams.get('status') || 'all');
    const [expandedImages, setExpandedImages] = useState({});
    const [lightboxImage, setLightboxImage] = useState(null);

    useEffect(() => {
        fetchRequests();
    }, [filter]);

    const fetchRequests = async () => {
        setLoading(true);
        try {
            let url = '/leave-requests';
            if (filter !== 'all') {
                url += `?status=${filter}`;
            }
            const res = await axios.get(url);
            setRequests(res.data.data);
        } catch (error) {
            console.error(error);
        } finally {
            setLoading(false);
        }
    };

    const handleFilterChange = (newFilter) => {
        setFilter(newFilter);
        if (newFilter === 'all') {
            setSearchParams({});
        } else {
            setSearchParams({ status: newFilter });
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
            // Refresh the list
            fetchRequests();
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

    const getStatusBadge = (status) => {
        switch (status) {
            case 'approved':
                return <span className="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">已批准</span>;
            case 'rejected':
                return <span className="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">已驳回</span>;
            case 'pending':
                return <span className="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">待审批</span>;
            default:
                return <span className="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">{status}</span>;
        }
    };

    return (
        <Layout>
            <div className="md:flex md:items-center md:justify-between mb-6">
                <h2 className="text-2xl font-bold">审批记录</h2>
                <div className="mt-3 md:mt-0">
                    <div className="inline-flex rounded-md shadow-sm" role="group">
                        <button
                            type="button"
                            onClick={() => handleFilterChange('all')}
                            className={`px-4 py-2 text-sm font-medium rounded-l-lg border ${filter === 'all'
                                ? 'bg-indigo-600 text-white border-indigo-600'
                                : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'
                                }`}
                        >
                            全部
                        </button>
                        <button
                            type="button"
                            onClick={() => handleFilterChange('pending')}
                            className={`px-4 py-2 text-sm font-medium border-t border-b ${filter === 'pending'
                                ? 'bg-indigo-600 text-white border-indigo-600'
                                : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'
                                }`}
                        >
                            待审批
                        </button>
                        <button
                            type="button"
                            onClick={() => handleFilterChange('approved')}
                            className={`px-4 py-2 text-sm font-medium border-t border-b ${filter === 'approved'
                                ? 'bg-indigo-600 text-white border-indigo-600'
                                : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'
                                }`}
                        >
                            已批准
                        </button>
                        <button
                            type="button"
                            onClick={() => handleFilterChange('rejected')}
                            className={`px-4 py-2 text-sm font-medium rounded-r-lg border ${filter === 'rejected'
                                ? 'bg-indigo-600 text-white border-indigo-600'
                                : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'
                                }`}
                        >
                            已驳回
                        </button>
                    </div>
                </div>
            </div>

            {loading ? (
                <div className="text-center py-10">加载中...</div>
            ) : requests.length === 0 ? (
                <div className="bg-white p-10 rounded-lg shadow text-center text-gray-500">
                    暂无{filter === 'all' ? '' : (filter === 'pending' ? '待审批' : filter === 'approved' ? '已批准' : '已驳回')}记录
                </div>
            ) : (
                <div className="bg-white shadow overflow-hidden sm:rounded-lg">
                    <ul role="list" className="divide-y divide-gray-200">
                        {requests.map((request) => (
                            <li key={request.id} className="px-4 py-4 sm:px-6 hover:bg-gray-50 transition duration-150">
                                <div className="flex items-center justify-between">
                                    <div className="flex flex-col">
                                        <p className="text-sm font-medium text-indigo-600 truncate">
                                            {request.student?.user?.name || request.student?.name}
                                            <span className="text-gray-400 mx-2">•</span>
                                            <span className="text-gray-500">{request.student?.student_no}</span>
                                        </p>
                                        <p className="flex items-center text-sm text-gray-500 mt-1">
                                            {request.leave_type?.name || request.type || '请假'}
                                            <span className="mx-2">•</span>
                                            {(() => {
                                                // 解析 details
                                                let details = null;
                                                try {
                                                    details = typeof request.details === 'string'
                                                        ? JSON.parse(request.details)
                                                        : request.details;
                                                } catch (e) { }

                                                // 优先使用 display_label
                                                console.log('[ApprovalHistory] details:', details);
                                                if (details?.display_label) {
                                                    const label = details.display_label;
                                                    const count = details.option_periods;
                                                    return count ? `${label} (${count}节)` : label;
                                                }
                                                // 否则使用 time_slot_name
                                                if (details?.time_slot_name) {
                                                    return details.time_slot_name;
                                                }
                                                // 最后使用 half_day_label
                                                return request.half_day_label || (request.half_day ? request.half_day : '全天');
                                            })()}
                                        </p>
                                    </div>
                                    <div className="ml-2 flex-shrink-0 flex flex-col items-end gap-1">
                                        {getStatusBadge(request.status)}
                                        {request.images && request.images.length > 0 && (
                                            <span className="px-2 inline-flex items-center gap-1 text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">
                                                <PhotoIcon className="h-3 w-3" />
                                                {request.images.length}
                                            </span>
                                        )}
                                    </div>
                                </div>

                                <div className="mt-2 text-sm text-gray-700">
                                    <p>
                                        日期: <span className="font-semibold">{format(new Date(request.start_date), 'yyyy-MM-dd')}</span>
                                        {request.start_date !== request.end_date && (
                                            <> - <span className="font-semibold">{format(new Date(request.end_date), 'yyyy-MM-dd')}</span></>
                                        )}
                                    </p>
                                    <p className="mt-1 italic">"{request.reason}"</p>
                                </div>

                                <div className="mt-2 flex flex-wrap gap-3 text-xs text-gray-500">
                                    {request.created_at && (
                                        <span>提交于: {format(new Date(request.created_at), 'yyyy-MM-dd HH:mm')}</span>
                                    )}
                                    {request.approved_at && (request.status === 'approved' || request.status === 'rejected') && (
                                        <span>
                                            {request.status === 'approved' ? '批准于' : '驳回于'}: {format(new Date(request.approved_at), 'yyyy-MM-dd HH:mm')}
                                            {request.approver_name && ` (${request.approver_name})`}
                                        </span>
                                    )}
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

                                {request.rejection_reason && (
                                    <div className="mt-2 text-sm text-red-600 bg-red-50 p-2 rounded">
                                        驳回原因: {request.rejection_reason}
                                    </div>
                                )}

                                {/* Action buttons for pending requests */}
                                {request.status === 'pending' && (
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
                                )}
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
