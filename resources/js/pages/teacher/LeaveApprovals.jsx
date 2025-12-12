import React, { useEffect, useState } from 'react';
import Layout from '../../components/Layout';
import axios from 'axios';
import { CheckIcon, XMarkIcon } from '@heroicons/react/24/outline';
import clsx from 'clsx';
import { format } from 'date-fns';

export default function LeaveApprovals() {
    const [requests, setRequests] = useState([]);
    const [loading, setLoading] = useState(true);

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
        if (!confirm(`Are you sure you want to ${action} this request?`)) return;

        try {
            if (action === 'approve') {
                await axios.post(`/leave-requests/${id}/approve`);
            } else {
                await axios.post(`/leave-requests/${id}/reject`, { reason: 'Teacher rejected' });
            }
            // remove from list
            setRequests(prev => prev.filter(r => r.id !== id));
        } catch (error) {
            alert('Action failed');
        }
    };

    return (
        <Layout>
            <h2 className="text-2xl font-bold mb-6">Pending Leave Approvals</h2>

            {loading ? (
                <div>Loading...</div>
            ) : requests.length === 0 ? (
                <div className="bg-white p-8 rounded-lg shadow text-center text-gray-500">
                    No pending requests.
                </div>
            ) : (
                <div className="bg-white shadow overflow-hidden sm:rounded-md">
                    <ul role="list" className="divide-y divide-gray-200">
                        {requests.map((request) => (
                            <li key={request.id}>
                                <div className="px-4 py-4 sm:px-6">
                                    <div className="flex items-center justify-between">
                                        <div className="flex flex-col">
                                            <p className="text-sm font-medium text-indigo-600 truncate">{request.student?.user?.name}</p>
                                            <p className="flex items-center text-sm text-gray-500">
                                                {request.type.toUpperCase()} • {request.half_day ? request.half_day.toUpperCase() : 'Full Day'}
                                            </p>
                                        </div>
                                        <div className="ml-2 flex-shrink-0 flex">
                                            <p className="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">
                                                {request.status}
                                            </p>
                                        </div>
                                    </div>
                                    <div className="mt-2 text-sm text-gray-700">
                                        <p>Date: <span className="font-semibold">{format(new Date(request.start_date), 'MMM d, yyyy')}</span> - <span className="font-semibold">{format(new Date(request.end_date), 'MMM d, yyyy')}</span></p>
                                        <p className="mt-1 italic">"{request.reason}"</p>
                                    </div>
                                    <div className="mt-4 flex justify-end space-x-3">
                                        <button
                                            onClick={() => handleAction(request.id, 'reject')}
                                            className="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-full shadow-sm text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500"
                                        >
                                            <XMarkIcon className="h-4 w-4 mr-1" />
                                            Reject
                                        </button>
                                        <button
                                            onClick={() => handleAction(request.id, 'approve')}
                                            className="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-full shadow-sm text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500"
                                        >
                                            <CheckIcon className="h-4 w-4 mr-1" />
                                            Approve
                                        </button>
                                    </div>
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </Layout>
    );
}
