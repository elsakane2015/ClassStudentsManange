import React, { useEffect, useState, useMemo } from 'react';
import Layout from '../../components/Layout';
import axios from 'axios';
import { format, addDays, parseISO } from 'date-fns';
import { useSearchParams } from 'react-router-dom';
import {
    PhotoIcon, ChevronDownIcon, ChevronUpIcon,
    XMarkIcon, CheckIcon, TrashIcon,
} from '@heroicons/react/24/outline';

// ── 工具函数 ──────────────────────────────────────────────
function semesterDateRange(semester) {
    if (!semester) return {};
    const start = parseISO(semester.start_date);
    const end = addDays(start, (semester.total_weeks || 20) * 7 - 1);
    return { date_from: format(start, 'yyyy-MM-dd'), date_to: format(end, 'yyyy-MM-dd') };
}

function reasonText(request) {
    let d = null;
    try { d = typeof request.details === 'string' ? JSON.parse(request.details) : request.details; } catch (e) {}
    return d?.text || request.reason || null;
}

function RequestScopeDetails({ request, compact = false }) {
    const eveningStatus = request.display_evening_status_name
        || request.display_evening_status?.name
        || request.requested_evening_status?.name
        || request.requested_status_name_snapshot
        || request.evening_study_status?.name
        || '状态待确认';
    const textClass = compact ? 'text-xs' : 'text-sm';

    return (
        <div className={`${textClass} space-y-1`}>
            {request.regular_period_label && (
                <p className="text-gray-600">
                    普通考勤：{request.regular_period_label}
                    {request.regular_period_count ? ` (${request.regular_period_count}节)` : ''}
                </p>
            )}
            {request.has_evening_study && (
                <p className="text-cyan-700">
                    夜自习：{eveningStatus}{request.destination ? ` · 去向：${request.destination}` : ''}
                </p>
            )}
            {!request.regular_period_label && !request.has_evening_study && (
                <p className="text-gray-600">{request.half_day_label || request.half_day || '全天'}</p>
            )}
        </div>
    );
}

// ── StatusBadge ───────────────────────────────────────────
function StatusBadge({ status }) {
    const styles = { approved: 'bg-green-100 text-green-800', rejected: 'bg-red-100 text-red-800', pending: 'bg-yellow-100 text-yellow-800' };
    const labels = { approved: '已批准', rejected: '已驳回', pending: '待审批' };
    return (
        <span className={`px-2 py-0.5 inline-flex text-xs font-semibold rounded-full ${styles[status] || 'bg-gray-100 text-gray-800'}`}>
            {labels[status] || status}
        </span>
    );
}

// ── Pagination ────────────────────────────────────────────
function Pagination({ currentPage, totalPages, onChange }) {
    if (totalPages <= 1) return null;
    const btnCls = (active, disabled) =>
        `px-2 py-1 text-sm rounded border transition-colors ${disabled ? 'opacity-40 cursor-not-allowed' : 'hover:bg-gray-50'} ${active ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white border-gray-300'}`;
    const half = 2;
    let start = Math.max(1, currentPage - half);
    let end = Math.min(totalPages, start + 4);
    if (end - start < 4) start = Math.max(1, end - 4);
    const pages = [];
    for (let p = start; p <= end; p++) pages.push(p);
    return (
        <div className="flex items-center gap-1">
            <button className={btnCls(false, currentPage === 1)} disabled={currentPage === 1} onClick={() => onChange(1)}>«</button>
            <button className={btnCls(false, currentPage === 1)} disabled={currentPage === 1} onClick={() => onChange(currentPage - 1)}>‹</button>
            {start > 1 && <span className="px-1 text-gray-400">…</span>}
            {pages.map(p => <button key={p} className={btnCls(p === currentPage, false)} onClick={() => onChange(p)}>{p}</button>)}
            {end < totalPages && <span className="px-1 text-gray-400">…</span>}
            <button className={btnCls(false, currentPage === totalPages)} disabled={currentPage === totalPages} onClick={() => onChange(currentPage + 1)}>›</button>
            <button className={btnCls(false, currentPage === totalPages)} disabled={currentPage === totalPages} onClick={() => onChange(totalPages)}>»</button>
        </div>
    );
}

// ── 审批范围面板 ──────────────────────────────────────────
function ApprovalScopePanel({ request, leaveTypes, onApprove, onReject }) {
    const [selectedLeaveTypeId, setSelectedLeaveTypeId] = useState(request.leave_type?.id ?? '');
    const [selected, setSelected] = useState(() => new Set((request.records || []).map(record => record.id)));
    const [rejectionReason, setRejectionReason] = useState('');
    const [submitting, setSubmitting] = useState(false);

    // group records by date
    const recordsByDate = useMemo(() => {
        const records = request.records || [];
        const map = {};
        records.forEach(r => {
            if (!map[r.date]) map[r.date] = [];
            map[r.date].push(r);
        });
        return map;
    }, [request.records]);

    const totalRecords = (request.records || []).length;
    const selectedCount = selected.size;

    const toggle = (id) => {
        setSelected(prev => {
            const next = new Set(prev);
            next.has(id) ? next.delete(id) : next.add(id);
            return next;
        });
    };

    const toggleDay = (date) => {
        const ids = (recordsByDate[date] || []).map(r => r.id);
        const allSelected = ids.every(id => selected.has(id));
        setSelected(prev => {
            const next = new Set(prev);
            if (allSelected) ids.forEach(id => next.delete(id));
            else ids.forEach(id => next.add(id));
            return next;
        });
    };

    const recordLabel = (rec) => {
        if (rec.scene === 'evening_study') {
            const status = rec.requested_evening_status?.name || rec.evening_study_status?.name || '状态待确认';
            return `${rec.label || '夜自习'} · ${status}`;
        }
        if (rec.label) return rec.label;
        if (rec.period_id == null) return '全天';
        return `节次 ${rec.period_id}`;
    };

    const submit = async (action) => {
        if (selectedCount === 0 || submitting) return;
        setSubmitting(true);
        try {
            if (action === 'approve') {
                await onApprove(request.id, selectedLeaveTypeId || null, Array.from(selected));
            } else {
                await onReject(request.id, Array.from(selected), rejectionReason.trim());
            }
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <div className="mt-4 border-t border-gray-200 pt-3 text-sm">
            <div className="flex items-center justify-between gap-2 mb-2">
                <p className="font-medium text-gray-700">选择审批范围</p>
                <span className="text-xs text-gray-500">已选 {selectedCount}/{totalRecords} 项</span>
            </div>

            {/* 假类选择 */}
            <div className="flex items-center gap-2 mb-3">
                <label className="text-xs text-gray-500 whitespace-nowrap">批准为</label>
                <select
                    value={selectedLeaveTypeId}
                    onChange={e => setSelectedLeaveTypeId(Number(e.target.value))}
                    className="flex-1 text-sm border border-gray-300 rounded px-2 py-1 bg-white focus:outline-none focus:ring-1 focus:ring-indigo-500"
                >
                    {leaveTypes.map(lt => (
                        <option key={lt.id} value={lt.id}>{lt.name}</option>
                    ))}
                </select>
            </div>

            {/* 节次/日期列表 */}
            {Object.keys(recordsByDate).length > 0 && (
                <div className="mb-3 space-y-2">
                    <p className="text-xs text-gray-500">未选部分会继续保留为待审批，可稍后单独处理。</p>
                    {Object.entries(recordsByDate).sort(([a], [b]) => a.localeCompare(b)).map(([date, recs]) => {
                        const dayIds = recs.map(r => r.id);
                        const allSelected = dayIds.every(id => selected.has(id));
                        const someSelected = dayIds.some(id => selected.has(id)) && !allSelected;
                        return (
                            <div key={date} className="bg-white rounded border border-gray-200 p-2">
                                {/* 日期行 */}
                                <label className="flex items-center gap-2 cursor-pointer mb-1">
                                    <input
                                        type="checkbox"
                                        checked={allSelected}
                                        ref={el => el && (el.indeterminate = someSelected)}
                                        onChange={() => toggleDay(date)}
                                        className="h-4 w-4 rounded text-indigo-600"
                                    />
                                    <span className="font-medium text-gray-700 text-xs">{date}</span>
                                </label>
                                {/* 节次行（多节时展开） */}
                                {recs.length > 1 || recs[0]?.period_id != null ? (
                                    <div className="ml-6 flex flex-wrap gap-1">
                                        {recs.map(rec => (
                                            <label key={rec.id} className={`flex items-center gap-1 px-2 py-1 rounded border cursor-pointer text-xs transition-colors ${selected.has(rec.id) ? 'bg-indigo-50 border-indigo-300 text-indigo-700' : 'bg-gray-50 border-gray-200 text-gray-400'}`}>
                                                <input
                                                    type="checkbox"
                                                    checked={selected.has(rec.id)}
                                                    onChange={() => toggle(rec.id)}
                                                    className="h-3.5 w-3.5 rounded text-indigo-600"
                                                />
                                                {recordLabel(rec)}
                                            </label>
                                        ))}
                                    </div>
                                ) : null}
                            </div>
                        );
                    })}
                </div>
            )}

            <input
                value={rejectionReason}
                onChange={event => setRejectionReason(event.target.value)}
                className="mb-3 w-full rounded border border-gray-300 bg-white px-2 py-1.5 text-xs"
                placeholder="驳回原因（驳回选中项时填写）"
            />

            <div className="flex justify-end gap-2">
                <button
                    disabled={selectedCount === 0 || submitting}
                    onClick={() => submit('reject')}
                    className="inline-flex items-center px-3 py-1.5 text-xs rounded bg-red-600 text-white hover:bg-red-700 disabled:opacity-40"
                >
                    <XMarkIcon className="h-4 w-4 mr-1" />驳回选中
                </button>
                <button
                    disabled={selectedCount === 0 || submitting}
                    onClick={() => submit('approve')}
                    className="inline-flex items-center px-3 py-1.5 text-xs rounded bg-green-600 text-white hover:bg-green-700 disabled:opacity-40"
                >
                    <CheckIcon className="h-4 w-4 mr-1" />批准选中
                </button>
            </div>
        </div>
    );
}

// ── SegmentInput（列数/条数选择器） ───────────────────────
function SegmentInput({ values, labels, current, onSelect, onInput }) {
    return (
        <div className="flex items-center border border-gray-300 rounded overflow-hidden text-sm">
            {values.map((v, i) => (
                <button key={v} onClick={() => onSelect(v)}
                    className={`px-2 py-1 transition-colors ${current === v ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-50'}`}>
                    {labels ? labels[i] : v}
                </button>
            ))}
            <input type="number" min="1" max="99" value={current}
                onChange={e => onInput(Math.max(1, parseInt(e.target.value) || 1))}
                className="w-12 border-l border-gray-300 px-1 py-1 text-center focus:outline-none bg-white" />
        </div>
    );
}

// ── 主组件 ────────────────────────────────────────────────
export default function ApprovalHistory() {
    const [searchParams, setSearchParams] = useSearchParams();
    const [requests, setRequests]   = useState([]);
    const [loading, setLoading]     = useState(true);
    const [filter, setFilter]       = useState(searchParams.get('status') || 'all');
    const [semesters, setSemesters] = useState([]);
    const [selectedSemesterId, setSelectedSemesterId] = useState(null);
    const [leaveTypes, setLeaveTypes] = useState([]);
    const [viewColumns, setViewColumns] = useState(1);
    const [pageSize, setPageSize]   = useState(10);
    const [currentPage, setCurrentPage] = useState(1);
    const [expandedImages, setExpandedImages] = useState({});
    const [lightboxImage, setLightboxImage]   = useState(null);

    // ── 初始化 ───────────────────────────────────────────
    useEffect(() => {
        axios.get('/leave-types').then(res => {
            setLeaveTypes((res.data.data || res.data || []).filter(lt => lt.is_active));
        }).catch(() => {});

        axios.get('/semesters').then(res => {
            const list = Array.isArray(res.data) ? res.data : (res.data.data || []);
            setSemesters(list);
            const current = list.find(s => s.is_current) || list[0];
            setSelectedSemesterId(current?.id ?? 0);
        }).catch(() => setSelectedSemesterId(0));
    }, []);

    useEffect(() => {
        if (selectedSemesterId === null) return;
        fetchRequests();
    }, [filter, selectedSemesterId]);

    const fetchRequests = async () => {
        setLoading(true);
        setCurrentPage(1);
        try {
            const params = {};
            if (filter !== 'all') params.status = filter;
            const semester = semesters.find(s => s.id === selectedSemesterId);
            if (semester) {
                const { date_from, date_to } = semesterDateRange(semester);
                params.date_from = date_from;
                params.date_to   = date_to;
            }
            const res = await axios.get('/leave-requests', { params });
            setRequests(res.data.data || []);
        } catch (error) {
            console.error(error);
        } finally {
            setLoading(false);
        }
    };

    const handleFilterChange = (f) => {
        setFilter(f);
        setSearchParams(f === 'all' ? {} : { status: f });
    };

    // ── 操作处理 ─────────────────────────────────────────
    const handleDelete = async (id) => {
        if (!confirm('确定要删除这条记录吗？删除后对应考勤也会被清除。')) return;
        try {
            await axios.delete(`/leave-requests/${id}`);
            fetchRequests();
        } catch (error) {
            alert('操作失败: ' + (error.response?.data?.error || error.message));
        }
    };

    const handleReject = async (id, recordIds, reason) => {
        try {
            await axios.post(`/leave-requests/${id}/reject`, {
                record_ids: recordIds,
                reason: reason || '教师驳回',
            });
            fetchRequests();
        } catch (error) {
            alert('操作失败: ' + (error.response?.data?.message || error.response?.data?.error || error.message));
            throw error;
        }
    };

    const handleApprove = async (id, leaveTypeId, recordIds) => {
        try {
            const payload = { record_ids: recordIds };
            if (leaveTypeId) payload.leave_type_id = leaveTypeId;
            await axios.post(`/leave-requests/${id}/approve`, payload);
            fetchRequests();
        } catch (error) {
            alert('操作失败: ' + (error.response?.data?.message || error.response?.data?.error || error.message));
            throw error;
        }
    };

    const getImageUrl = (path) => path.startsWith('http') ? path : `/storage/${path}`;

    // ── 分页 ─────────────────────────────────────────────
    const totalPages = Math.max(1, Math.ceil(requests.length / pageSize));
    const paginatedRequests = useMemo(() =>
        requests.slice((currentPage - 1) * pageSize, currentPage * pageSize),
        [requests, currentPage, pageSize]
    );

    // ── 操作按钮区（待审批时显示） ────────────────────────
    const renderActionButtons = (request) => {
        if (request.status !== 'pending') {
            return (
                <div className="mt-4 flex justify-end">
                    <button onClick={() => handleDelete(request.id)}
                        className="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded-full shadow-sm text-gray-700 bg-white hover:bg-gray-50">
                        <TrashIcon className="h-4 w-4 mr-1 text-gray-500" />删除
                    </button>
                </div>
            );
        }

        return (
            <ApprovalScopePanel
                request={request}
                leaveTypes={leaveTypes}
                onApprove={handleApprove}
                onReject={handleReject}
            />
        );
    };

    // ── 列表视图（单列） ──────────────────────────────────
    const renderListItem = (request) => (
        <li key={request.id} className="px-4 py-4 sm:px-6 hover:bg-gray-50 transition duration-150">
            <div className="flex items-center justify-between">
                <div className="flex flex-col">
                    <p className="text-sm font-medium text-indigo-600">
                        {request.student?.user?.name || request.student?.name}
                        <span className="text-gray-400 mx-2">•</span>
                        <span className="text-gray-500">{request.student?.student_no}</span>
                    </p>
                    <p className="text-sm text-gray-500 mt-1">
                        {request.leave_type?.name || request.type || '请假'}
                    </p>
                    <RequestScopeDetails request={request} />
                </div>
                <div className="ml-2 flex-shrink-0 flex flex-col items-end gap-1">
                    <StatusBadge status={request.status} />
                    {request.images?.length > 0 && (
                        <span className="px-2 inline-flex items-center gap-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800">
                            <PhotoIcon className="h-3 w-3" />{request.images.length}
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
                {(() => { const r = reasonText(request); return r ? <p className="mt-1 italic">"{r}"</p> : null; })()}
            </div>

            <div className="mt-2 flex flex-wrap gap-3 text-xs text-gray-500">
                {request.created_at && <span>提交于: {format(new Date(request.created_at), 'yyyy-MM-dd HH:mm')}</span>}
                {request.approved_at && (request.status === 'approved' || request.status === 'rejected') && (
                    <span>
                        {request.status === 'approved' ? '批准于' : '驳回于'}: {format(new Date(request.approved_at), 'yyyy-MM-dd HH:mm')}
                        {request.approver_name && ` (${request.approver_name})`}
                    </span>
                )}
            </div>

            {request.images?.length > 0 && (
                <div className="mt-3">
                    <button onClick={() => setExpandedImages(p => ({ ...p, [request.id]: !p[request.id] }))}
                        className="flex items-center gap-1 text-sm text-indigo-600 hover:text-indigo-800">
                        <PhotoIcon className="h-4 w-4" />
                        {expandedImages[request.id] ? '收起附件' : `查看附件 (${request.images.length}张)`}
                        {expandedImages[request.id] ? <ChevronUpIcon className="h-4 w-4" /> : <ChevronDownIcon className="h-4 w-4" />}
                    </button>
                    {expandedImages[request.id] && (
                        <div className="mt-2 flex flex-wrap gap-2">
                            {request.images.map((path, i) => (
                                <img key={i} src={getImageUrl(path)} alt={`附件${i+1}`}
                                    className="w-24 h-24 object-cover rounded-lg border border-gray-200 cursor-pointer hover:opacity-80"
                                    onClick={() => setLightboxImage(getImageUrl(path))} />
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

            {renderActionButtons(request)}
        </li>
    );

    // ── 卡片视图（多列） ──────────────────────────────────
    const renderCard = (request) => {
        const r = reasonText(request);
        return (
            <div key={request.id} className="bg-white border border-gray-200 rounded-lg p-3 flex flex-col gap-2">
                <div className="flex items-start justify-between gap-2">
                    <div className="min-w-0">
                        <p className="text-sm font-medium text-indigo-600 truncate">
                            {request.student?.user?.name || request.student?.name}
                        </p>
                        <p className="text-xs text-gray-400">{request.student?.student_no}</p>
                    </div>
                    <StatusBadge status={request.status} />
                </div>
                <p className="text-xs text-gray-600">
                    {request.leave_type?.name || '请假'}
                </p>
                <RequestScopeDetails request={request} compact />
                <p className="text-xs text-gray-700">
                    <span className="font-medium">{format(new Date(request.start_date), 'yyyy-MM-dd')}</span>
                    {request.start_date !== request.end_date && (
                        <> ~ <span className="font-medium">{format(new Date(request.end_date), 'yyyy-MM-dd')}</span></>
                    )}
                </p>
                {r && <p className="text-xs text-gray-500 italic truncate">"{r}"</p>}
                <div className="text-[10px] text-gray-400 space-y-0.5">
                    {request.created_at && <p>提交: {format(new Date(request.created_at), 'MM-dd HH:mm')}</p>}
                    {request.approved_at && (request.status === 'approved' || request.status === 'rejected') && (
                        <p>{request.status === 'approved' ? '批准' : '驳回'}: {format(new Date(request.approved_at), 'MM-dd HH:mm')}{request.approver_name && ` (${request.approver_name})`}</p>
                    )}
                </div>
                {request.images?.length > 0 && (
                    <div>
                        <button onClick={() => setExpandedImages(p => ({ ...p, [request.id]: !p[request.id] }))}
                            className="flex items-center gap-1 text-xs text-indigo-600 hover:text-indigo-800">
                            <PhotoIcon className="h-3 w-3" />
                            {expandedImages[request.id] ? '收起' : `附件(${request.images.length})`}
                        </button>
                        {expandedImages[request.id] && (
                            <div className="mt-1 flex flex-wrap gap-1">
                                {request.images.map((path, i) => (
                                    <img key={i} src={getImageUrl(path)} alt={`附件${i+1}`}
                                        className="w-16 h-16 object-cover rounded border border-gray-200 cursor-pointer hover:opacity-80"
                                        onClick={() => setLightboxImage(getImageUrl(path))} />
                                ))}
                            </div>
                        )}
                    </div>
                )}
                {request.rejection_reason && (
                    <p className="text-xs text-red-600 bg-red-50 p-1.5 rounded">驳回: {request.rejection_reason}</p>
                )}
                {renderActionButtons(request)}
            </div>
        );
    };

    const filterLabel = { all: '', pending: '待审批', approved: '已批准', rejected: '已驳回' }[filter] || '';

    return (
        <Layout>
            {/* ── 标题行 ── */}
            <div className="flex flex-wrap items-center justify-between gap-3 mb-4">
                <h2 className="text-2xl font-bold shrink-0">审批记录</h2>
                <div className="flex flex-wrap items-center gap-2">
                    {/* 状态过滤 */}
                    <div className="inline-flex rounded-md shadow-sm">
                        {[['all','全部'],['pending','待审批'],['approved','已批准'],['rejected','已驳回']].map(([val, label], i, arr) => (
                            <button key={val} type="button" onClick={() => handleFilterChange(val)}
                                className={`px-3 py-1.5 text-sm font-medium border ${i === 0 ? 'rounded-l-lg' : ''} ${i === arr.length-1 ? 'rounded-r-lg' : ''} ${filter === val ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'}`}>
                                {label}
                            </button>
                        ))}
                    </div>
                    {/* 学期 */}
                    {semesters.length > 0 && (
                        <select value={selectedSemesterId ?? ''}
                            onChange={e => setSelectedSemesterId(Number(e.target.value))}
                            className="text-sm border border-gray-300 rounded px-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                            {semesters.map(s => (
                                <option key={s.id} value={s.id}>{s.name}{s.is_current ? ' (当前)' : ''}</option>
                            ))}
                        </select>
                    )}
                    {/* 列数 */}
                    <div className="flex items-center gap-1">
                        <span className="text-xs text-gray-500">列:</span>
                        <SegmentInput values={[1,2,3]} current={viewColumns} onSelect={setViewColumns} onInput={setViewColumns} />
                    </div>
                    {/* 每页 */}
                    <div className="flex items-center gap-1">
                        <span className="text-xs text-gray-500">每页:</span>
                        <SegmentInput values={[10,20]} labels={['10','20']} current={pageSize}
                            onSelect={v => { setPageSize(v); setCurrentPage(1); }}
                            onInput={v => { setPageSize(v); setCurrentPage(1); }} />
                    </div>
                </div>
            </div>

            {/* ── 内容区 ── */}
            {loading ? (
                <div className="text-center py-10 text-gray-500">加载中...</div>
            ) : requests.length === 0 ? (
                <div className="bg-white p-10 rounded-lg shadow text-center text-gray-500">
                    暂无{filterLabel}记录
                </div>
            ) : (
                <>
                    {viewColumns === 1 ? (
                        <div className="bg-white shadow overflow-hidden sm:rounded-lg">
                            <ul role="list" className="divide-y divide-gray-200">
                                {paginatedRequests.map(renderListItem)}
                            </ul>
                        </div>
                    ) : (
                        <div style={{ display: 'grid', gridTemplateColumns: `repeat(${viewColumns}, minmax(0, 1fr))`, gap: '12px' }}>
                            {paginatedRequests.map(renderCard)}
                        </div>
                    )}
                    <div className="flex items-center justify-between mt-4 px-1">
                        <span className="text-sm text-gray-500">
                            共 {requests.length} 条 · 第 {currentPage}/{totalPages} 页
                        </span>
                        <Pagination currentPage={currentPage} totalPages={totalPages} onChange={setCurrentPage} />
                    </div>
                </>
            )}

            {/* ── Lightbox ── */}
            {lightboxImage && (
                <div className="fixed inset-0 z-50 bg-black bg-opacity-90 flex items-center justify-center p-4"
                    onClick={() => setLightboxImage(null)}>
                    <div className="relative max-w-4xl max-h-full">
                        <button onClick={() => setLightboxImage(null)}
                            className="absolute top-2 right-2 bg-white/20 hover:bg-white/40 rounded-full p-2 transition-colors">
                            <XMarkIcon className="h-6 w-6 text-white" />
                        </button>
                        <img src={lightboxImage} alt="放大查看"
                            className="max-w-full max-h-[90vh] object-contain rounded-lg"
                            onClick={e => e.stopPropagation()} />
                    </div>
                </div>
            )}
        </Layout>
    );
}
