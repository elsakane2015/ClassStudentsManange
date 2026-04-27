import React, { useState, useEffect, Fragment } from 'react';
import axios from 'axios';
import { format } from 'date-fns';
import { Dialog, Transition } from '@headlessui/react';
import { CheckIcon } from '@heroicons/react/24/outline'; // Adjust if not installed

/**
 * 格式化节次名称显示
 * 将 period_ids 转换为可读的节次名称，智能合并连续的"第X节"
 * 如果选择的节次刚好匹配某个时段配置，则直接显示时段名称
 * 
 * @param {Array} periodIds - 节次ID数组
 * @param {Array} periods - 节次配置列表
 * @param {Array} timeSlots - 时段配置列表（可选）
 * @returns {string} 格式化后的显示文本，如 "(上午)" 或 "(早读、早操、第1-3节)"
 */
const formatPeriodNames = (periodIds, periods, timeSlots = []) => {
    if (!periodIds || periodIds.length === 0 || !periods || periods.length === 0) {
        return '(全天)';
    }

    // 标准化 periodIds 为整数数组
    const normalizedIds = periodIds.map(id => parseInt(id)).sort((a, b) => a - b);

    // 优先检查是否匹配某个时段配置
    if (timeSlots && timeSlots.length > 0) {
        for (const slot of timeSlots) {
            const slotPeriodIds = (slot.period_ids || []).map(id => parseInt(id)).sort((a, b) => a - b);

            // 检查是否完全匹配（数量相同且内容相同）
            if (slotPeriodIds.length > 0 &&
                slotPeriodIds.length === normalizedIds.length &&
                slotPeriodIds.every((id, idx) => id === normalizedIds[idx])) {
                return `(${slot.name})`;
            }
        }
    }

    // 如果选择了所有节次，直接显示"全天"
    if (periodIds.length >= periods.length) {
        return '(全天)';
    }

    // 获取节次对象，按在 periods 数组中的顺序排序
    const selectedPeriods = periodIds
        .map(id => {
            const index = periods.findIndex(p => p.id === id || p.id === parseInt(id));
            if (index === -1) return null;
            return { ...periods[index], sortIndex: index };
        })
        .filter(Boolean)
        .sort((a, b) => a.sortIndex - b.sortIndex);

    if (selectedPeriods.length === 0) {
        return `(${periodIds.length}节)`;
    }

    // 分离"第X节"格式的节次和特殊节次（早读、早操等）
    const numberedPattern = /^第(\d+)节$/;
    const result = [];
    let numberedRun = []; // 连续的"第X节"

    const flushNumberedRun = () => {
        if (numberedRun.length === 0) return;

        if (numberedRun.length === 1) {
            result.push(numberedRun[0].name);
        } else if (numberedRun.length === 2) {
            result.push(numberedRun.map(p => p.name).join('、'));
        } else {
            // 合并为"第X-Y节"
            const firstMatch = numberedRun[0].name.match(numberedPattern);
            const lastMatch = numberedRun[numberedRun.length - 1].name.match(numberedPattern);
            if (firstMatch && lastMatch) {
                result.push(`第${firstMatch[1]}-${lastMatch[1]}节`);
            } else {
                result.push(numberedRun.map(p => p.name).join('、'));
            }
        }
        numberedRun = [];
    };

    for (const period of selectedPeriods) {
        const match = period.name.match(numberedPattern);
        if (match) {
            // 检查是否可以继续当前的连续序列
            if (numberedRun.length > 0) {
                const lastMatch = numberedRun[numberedRun.length - 1].name.match(numberedPattern);
                if (lastMatch && parseInt(match[1]) === parseInt(lastMatch[1]) + 1) {
                    // 连续的，添加到当前序列
                    numberedRun.push(period);
                } else {
                    // 不连续，结束当前序列并开始新的
                    flushNumberedRun();
                    numberedRun.push(period);
                }
            } else {
                numberedRun.push(period);
            }
        } else {
            // 特殊节次（早读、早操等），先结束当前的数字序列
            flushNumberedRun();
            result.push(period.name);
        }
    }

    // 处理最后剩余的数字序列
    flushNumberedRun();

    return `(${result.join('、')})`;
};

export default function AttendanceUpdateModal({ isOpen, onClose, date, user }) {
    const formattedDate = date ? format(date, 'yyyy-MM-dd') : '';
    const [students, setStudents] = useState([]);
    const [loading, setLoading] = useState(true);
    const [selectedStudentIds, setSelectedStudentIds] = useState(new Set());
    const [studentFilter, setStudentFilter] = useState('all'); // 'all' | 'marked'
    const [leaveTypes, setLeaveTypes] = useState([]);
    const [periods, setPeriods] = useState([]); // 节次列表
    const [timeSlots, setTimeSlots] = useState([]); // 时段列表（上午/下午/全天）
    const [selectedPeriod, setSelectedPeriod] = useState(null); // 选中的时段ID，null表示全天

    // Dynamic Input State
    const [pendingAction, setPendingAction] = useState(null); // { status, leaveType, ... }
    const [inputModalOpen, setInputModalOpen] = useState(false);
    const [inputData, setInputData] = useState({});
    const [showPeriodDetail, setShowPeriodDetail] = useState(false);
    const [showPrintOptions, setShowPrintOptions] = useState(false);
    const [printColumns, setPrintColumns] = useState(2);
    const [viewColumns, setViewColumns] = useState(1);

    useEffect(() => {
        if (isOpen && date) {
            fetchAttendance();
            axios.get('/leave-types').then(res => {
                setLeaveTypes(res.data.data || res.data);
            }).catch(e => console.error("Failed to load leave types"));

            // 从系统设置获取节次列表
            axios.get('/settings').then(res => {
                const settingsObj = {};
                res.data.forEach(s => settingsObj[s.key] = s.value);
                if (settingsObj.attendance_periods) {
                    try {
                        const periodsData = typeof settingsObj.attendance_periods === 'string'
                            ? JSON.parse(settingsObj.attendance_periods)
                            : settingsObj.attendance_periods;
                        setPeriods(Array.isArray(periodsData) ? periodsData : []);
                    } catch (e) {
                        console.warn('Failed to parse attendance_periods', e);
                        setPeriods([]);
                    }
                }
            }).catch(e => console.error("Failed to load settings"));

            // 获取时段列表（用于 duration_select）
            axios.get('/time-slots').then(res => {
                const slots = res.data || [];
                // 按 sort_order 排序
                slots.sort((a, b) => (a.sort_order || 0) - (b.sort_order || 0));
                setTimeSlots(slots);
            }).catch(e => console.error("Failed to load time slots"));
        }
    }, [isOpen, date]);

    const fetchAttendance = async () => {
        setLoading(true);
        try {
            const res = await axios.get('/attendance/overview', { params: { date: formattedDate } });
            // Flatten logic
            let allStudents = [];
            // Handle different API responses (overview gives departments -> classes -> students)
            const data = Array.isArray(res.data) ? res.data : (res.data.data || []);
            console.log("Attendance Modal Overview Data:", data);

            data.forEach(dept => {
                const classes = dept.classes || [];
                if (Array.isArray(classes)) {
                    classes.forEach(cls => {
                        if (Array.isArray(cls.students)) {
                            allStudents = [...allStudents, ...cls.students];
                        }
                    });
                }
            });
            console.log("All Students Found:", allStudents.length);
            setStudents(allStudents);
            setSelectedStudentIds(new Set());
        } catch (error) {
            console.error(error);
        } finally {
            setLoading(false);
        }
    };

    const toggleSelection = (id) => {
        const newSet = new Set(selectedStudentIds);
        if (newSet.has(id)) newSet.delete(id);
        else newSet.add(id);
        setSelectedStudentIds(newSet);
    };

    // 过滤后的学生列表（用于渲染、全选）
    const visibleStudents = students.filter(s => {
        if (studentFilter === 'all') return true;
        const records = s.attendance || [];
        return records.some(r => r.status !== 'present');
    });

    const toggleAll = () => {
        const visibleIds = visibleStudents.map(s => s.id);
        const allSelected = visibleIds.length > 0 && visibleIds.every(id => selectedStudentIds.has(id));
        if (allSelected) {
            // 取消勾选当前可见的
            const next = new Set(selectedStudentIds);
            visibleIds.forEach(id => next.delete(id));
            setSelectedStudentIds(next);
        } else {
            // 勾选全部可见的
            const next = new Set(selectedStudentIds);
            visibleIds.forEach(id => next.add(id));
            setSelectedStudentIds(next);
        }
    };

    const handleActionClick = async (typeOrStatus) => {
        if (selectedStudentIds.size === 0) return;

        if (typeOrStatus === 'present') {
            executeBulkUpdate('present', null, null);
            return;
        }

        // It's a leave type object
        const lt = typeOrStatus;

        // Map slug to status
        // Slugs: late, absent, early_leave -> map to status 'late', 'absent', 'early_leave'
        // Others -> status 'leave'
        let status = 'leave';
        if (['late', 'absent', 'early_leave'].includes(lt.slug)) {
            status = lt.slug;
        }

        if (!lt.input_type || lt.input_type === 'none') {
            executeBulkUpdate(status, lt.id, null);
        } else {
            // Open Input Modal
            console.log('[handleActionClick] Opening input modal, status:', status, 'leaveType:', lt);
            setPendingAction({ status, leaveType: lt });

            // 对于旷课，预填充已有的节次
            if (status === 'absent' && selectedStudentIds.size > 0) {
                console.log('[Absent Pre-fill] Starting pre-fill logic');
                const firstStudentId = Array.from(selectedStudentIds)[0];

                // 从 API 获取该学生的考勤记录
                try {
                    const res = await axios.get('/attendance/overview', {
                        params: { date: formattedDate }
                    });
                    const data = Array.isArray(res.data) ? res.data : (res.data.data || []);

                    // 查找该学生
                    let studentWithRecords = null;
                    data.forEach(dept => {
                        const classes = dept.classes || [];
                        classes.forEach(cls => {
                            if (Array.isArray(cls.students)) {
                                const found = cls.students.find(s => s.id === firstStudentId);
                                if (found) studentWithRecords = found;
                            }
                        });
                    });

                    console.log('[Absent Pre-fill] Student with records:', studentWithRecords);

                    if (studentWithRecords && studentWithRecords.attendance) {
                        const absentRecords = studentWithRecords.attendance.filter(r => r.status === 'absent');
                        const existingPeriods = [];

                        absentRecords.forEach(record => {
                            // Parse details if it's a string
                            let details = record.details;
                            if (typeof details === 'string') {
                                try {
                                    details = JSON.parse(details);
                                } catch (e) {
                                    console.error('Failed to parse details:', e);
                                    details = null;
                                }
                            }

                            if (details && details.periods) {
                                existingPeriods.push(...details.periods);
                            }
                        });

                        const uniquePeriods = [...new Set(existingPeriods)];
                        console.log('[Absent Pre-fill] Absent records:', absentRecords);
                        console.log('[Absent Pre-fill] Existing periods:', existingPeriods);
                        console.log('[Absent Pre-fill] Unique periods:', uniquePeriods);

                        setInputData(uniquePeriods.length > 0 ? { periods: uniquePeriods } : {});
                    } else {
                        console.log('[Absent Pre-fill] No attendance records found');
                        setInputData({});
                    }
                } catch (error) {
                    console.error('[Absent Pre-fill] Failed to fetch records:', error);
                    setInputData({});
                }
            } else {
                setInputData({}); // Reset input
            }
            setInputModalOpen(true);
        }
    };

    const executeBulkUpdate = async (status, leaveTypeId, details) => {
        try {
            const records = Array.from(selectedStudentIds).map(id => ({
                student_id: id,
                status: status,
                leave_type_id: leaveTypeId,
                details: details
            }));

            // 确定需要创建的时段列表
            let periodIds = [null]; // 默认全天
            let shouldLoopPeriods = true; // 是否需要循环每个节次

            if (details) {
                if (details.time) {
                    // 有时间输入（迟到/早退）
                    // 如果用户选择了节次，使用用户选择的节次
                    // 否则使用默认值：迟到用第1节，早退用第8节
                    if (details.period) {
                        periodIds = [details.period];
                    } else if (status === 'late') {
                        periodIds = [1];
                    } else if (status === 'early_leave') {
                        periodIds = [8];
                    } else {
                        periodIds = [1]; // 其他情况默认第1节
                    }
                } else if (details.periods && details.periods.length > 0) {
                    // 有时段选择（旷课）- 创建一条记录，包含所有节次信息
                    // 使用第一个节次作为 period_id，所有节次信息保存在 details 中
                    periodIds = [details.periods[0]];
                    shouldLoopPeriods = false; // 不循环，只发送一次
                } else if (details.period_ids && details.period_ids.length > 0) {
                    // text 类型的节次选择 - 创建一条记录，包含所有节次信息
                    periodIds = [details.period_ids[0]];
                    shouldLoopPeriods = false; // 不循环，只发送一次
                }
                // 对于 duration_select 类型的选项（如"上午"、"下午"），
                // 不需要前端指定 period_id，后端会根据 option 动态生成
            }

            // 为每个时段发送请求（旷课只发送一次）
            if (shouldLoopPeriods) {
                for (const periodId of periodIds) {
                    await axios.post('/attendance/bulk', {
                        date: formattedDate,
                        period_id: periodId,
                        records: records
                    });
                }
            } else {
                // 旷课：先删除旧的旷课记录，然后创建新的合并记录
                // 重新获取数据以确保有 attendance 字段
                try {
                    const res = await axios.get('/attendance/overview', {
                        params: { date: formattedDate }
                    });
                    const data = Array.isArray(res.data) ? res.data : (res.data.data || []);

                    for (const studentId of selectedStudentIds) {
                        // 查找该学生
                        let studentWithRecords = null;
                        data.forEach(dept => {
                            const classes = dept.classes || [];
                            classes.forEach(cls => {
                                if (Array.isArray(cls.students)) {
                                    const found = cls.students.find(s => s.id === studentId);
                                    if (found) studentWithRecords = found;
                                }
                            });
                        });

                        if (studentWithRecords && studentWithRecords.attendance) {
                            const absentRecords = studentWithRecords.attendance.filter(r => r.status === 'absent');
                            for (const record of absentRecords) {
                                try {
                                    await axios.delete('/attendance/records', {
                                        data: {
                                            student_id: studentId,
                                            date: formattedDate,
                                            period_id: record.period_id
                                        }
                                    });
                                } catch (e) {
                                    console.error('Failed to delete old absent record:', e);
                                }
                            }
                        }
                    }
                } catch (error) {
                    console.error('[Absent Delete] Failed to fetch records:', error);
                }

                // 创建新的合并记录
                await axios.post('/attendance/bulk', {
                    date: formattedDate,
                    period_id: periodIds[0],
                    records: records
                });
            }

            // Close inputs if any
            setInputModalOpen(false);
            setPendingAction(null);

            // Refresh
            fetchAttendance();
        } catch (error) {
            console.error("Failed to update", error);
            alert("更新失败: " + (error.response?.data?.message || error.message));
        }
    };

    // 删除考勤记录
    const handleDeleteRecord = async (studentId, record) => {
        const details = typeof record.details === 'string'
            ? JSON.parse(record.details || '{}')
            : (record.details || {});

        try {
            await axios.delete(`/attendance/records`, {
                params: {
                    student_id: studentId,
                    date: formattedDate,
                    period_id: record.period_id,
                    option: details.option,
                    source_type: record.source_type,
                    source_id: record.source_id,
                    leave_batch_id: record.leave_batch_id ?? null
                }
            });

            // Refresh
            fetchAttendance();
        } catch (error) {
            console.error("Failed to delete", error);
            alert("删除失败: " + (error.response?.data?.message || error.message));
        }
    };

    const handlePrint = () => {
        setShowPrintOptions(false);
        const cols = Math.max(1, Math.min(10, printColumns || 2));

        const safeParseDetails = (raw) => {
            if (!raw) return {};
            if (typeof raw === 'object') return raw;
            try { return JSON.parse(raw); } catch (e) { return {}; }
        };

        const deduplicateRecords = (records) => {
            const nonPresent = records.filter(r => r.status !== 'present');
            const seen = new Set();
            return nonPresent.filter(r => {
                const details = safeParseDetails(r.details);
                const displayLabel = r.display_label || details.display_label || '';
                let key;
                if (r.source_type === 'leave_request' && r.source_id) {
                    key = `leave-${r.source_id}`;
                } else if (displayLabel) {
                    key = `label-${r.leave_type_id}-${displayLabel}`;
                } else if (r.id) {
                    key = `id-${r.id}`;
                } else {
                    key = `${r.status}-${r.period_id}-${details.option || 'none'}`;
                }
                if (seen.has(key)) return false;
                seen.add(key);
                return true;
            });
        };

        const statusColorMap = {
            absent: '#dc2626', late: '#d97706', leave: '#2563eb',
            early_leave: '#ea580c', excused: '#9333ea',
        };

        const buildStatusHtml = (student) => {
            const uniqueRecords = deduplicateRecords(student.attendance || []);
            if (uniqueRecords.length === 0) return '<span style="color:#9ca3af">-</span>';

            return uniqueRecords.map(r => {
                const details = safeParseDetails(r.details);
                const displayLabel = r.display_label || details.display_label;
                const lt = r.leave_type || leaveTypes.find(l => l.id === r.leave_type_id);
                const ltName = lt?.name || '';

                let label;
                if (displayLabel) {
                    label = (ltName && !displayLabel.includes(ltName))
                        ? `${ltName}(${displayLabel})` : displayLabel;
                } else {
                    label = ltName || r.status;
                    if (details.time) label += `(${details.time})`;
                    else if (details.option_label) label += `(${details.option_label})`;
                    else if (details.option) label += `(${details.option})`;
                }

                let prefix = '';
                if (r.approval_status === 'pending') prefix = '待审:';
                else if (r.approval_status === 'approved') prefix = '批准:';
                else if (r.approval_status === 'rejected') prefix = '驳回:';

                const color = statusColorMap[r.status] || '#6b7280';
                return `<span style="color:${color};white-space:nowrap">${prefix}${label}</span>`;
            }).join(' <span style="color:#d1d5db">|</span> ');
        };

        const filterLabel = studentFilter === 'marked' ? '有标记' : '全部';
        const items = visibleStudents.map(s => ({
            no: s.student_no || '',
            name: s.user?.name || '',
            statusHtml: buildStatusHtml(s),
        }));

        const cards = items.map(s => `
            <div style="break-inside:avoid;border:1px solid #e5e7eb;border-radius:4px;padding:4px 6px;margin-bottom:3px;font-size:11px;line-height:1.4">
                <div style="white-space:nowrap">
                    <span style="color:#9ca3af;font-family:monospace">${s.no}</span>
                    <strong style="margin-left:5px">${s.name}</strong>
                </div>
                <div style="margin-top:2px;font-size:10px">${s.statusHtml}</div>
            </div>`).join('');

        const html = `<!DOCTYPE html>
<html><head><meta charset="utf-8">
<title>考勤标记 - ${formattedDate}</title>
<style>
  body{font-family:sans-serif;padding:8mm;margin:0}
  h2{font-size:13px;margin:0 0 6px;font-weight:600}
  .meta{font-size:11px;color:#6b7280;margin-bottom:8px}
  .grid{column-count:${cols};column-gap:6px}
  @media print{body{padding:5mm} @page{margin:8mm}}
</style></head>
<body>
<h2>考勤标记 - ${formattedDate}</h2>
<div class="meta">${filterLabel} · 共 ${items.length} 人 · ${cols} 列</div>
<div class="grid">${cards}</div>
</body></html>`;

        const w = window.open('', '_blank', 'width=960,height=720');
        if (!w) { alert('请允许弹出窗口以打印'); return; }
        w.document.write(html);
        w.document.close();
        w.focus();
        setTimeout(() => w.print(), 400);
    };

    const handleInputConfirm = () => {
        if (!pendingAction) return;

        // 如果有periods（旷课），需要将ID转换为节次编号
        let enhancedInputData = { ...inputData };
        if (inputData.periods && Array.isArray(inputData.periods)) {
            // 创建ID到索引的映射
            const periodNumbers = inputData.periods.map(periodId => {
                const index = periods.findIndex(p => p.id === periodId);
                return index + 1; // 节次编号 = 索引 + 1
            });

            enhancedInputData = {
                ...inputData,
                period_numbers: periodNumbers // 添加节次编号数组
            };

            console.log('[Input Confirm] Period IDs:', inputData.periods);
            console.log('[Input Confirm] Period Numbers:', periodNumbers);
        }

        executeBulkUpdate(pendingAction.status, pendingAction.leaveType.id, enhancedInputData);
    };

    const StatusBadge = ({ status, details, leaveTypeId, leaveType, onClick, periodId, period, displayLabel, isSelfApplied, approvalStatus }) => {
        const styles = {
            present: 'bg-green-100 text-green-800',
            absent: 'bg-red-100 text-red-800',
            late: 'bg-yellow-100 text-yellow-800',
            leave: 'bg-blue-100 text-blue-800',
            excused: 'bg-purple-100 text-purple-800',
            early_leave: 'bg-orange-100 text-orange-800',
            unmarked: 'bg-gray-100 text-gray-400'
        };
        const labels = {
            present: '出勤',
            absent: '缺勤',
            late: '迟到',
            leave: '请假',
            excused: '已审批',
            early_leave: '早退',
            unmarked: '出勤'
        };

        // 生成审批状态前缀（针对自主请假或有审批状态的记录）
        let statusPrefix = '';
        // 如果有 approvalStatus，显示对应的前缀
        if (approvalStatus) {
            if (approvalStatus === 'pending') {
                statusPrefix = '待审:';
            } else if (approvalStatus === 'approved') {
                statusPrefix = '批准:';
            } else if (approvalStatus === 'rejected') {
                statusPrefix = '驳回:';
            }
        }

        // 获取请假类型名称
        let leaveTypeName = '';
        if (leaveType && leaveType.name) {
            leaveTypeName = leaveType.name;
        } else if (leaveTypeId) {
            const lt = leaveTypes.find(l => l.id === leaveTypeId);
            if (lt) leaveTypeName = lt.name;
        }

        // If displayLabel is provided (from roll_call or leave_request), use it directly
        // But add leave type name if available
        if (displayLabel) {
            const s = status || 'leave';
            // Use red style for roll_call absents (旷课)
            const styleKey = displayLabel.includes('旷课') ? 'absent' : s;
            const classes = `px-2 py-0.5 rounded text-xs font-medium whitespace-nowrap ${styles[styleKey] || styles.unmarked} ${onClick ? 'cursor-pointer hover:opacity-80 ring-1 ring-offset-1 ring-transparent hover:ring-red-300 transition-all' : ''}`;

            // 构建完整标签：状态前缀 + 请假类型名称(节次信息)
            // 例如：批准:病假(第1节、早读、早操)
            let fullLabel = statusPrefix;
            if (leaveTypeName && !displayLabel.includes(leaveTypeName)) {
                fullLabel += `${leaveTypeName}(${displayLabel})`;
            } else {
                fullLabel += displayLabel;
            }

            return (
                <span className={classes} onClick={onClick} title={onClick ? "点击撤销此记录" : ""}>
                    {fullLabel}
                </span>
            );
        }

        // Determine label: prefer leaveType object from record, then lookup by ID
        let label = labels[status] || status;

        // 优先使用 leaveTypeName（请假类型名称），而不是硬编码的 labels
        // 这样 "旷课"、"迟到"、"早退" 等都会显示实际配置的名称
        if (leaveTypeName) {
            label = leaveTypeName;
        }

        // Add details text if possible
        let detailText = '';
        if (details) {
            if (details.time) detailText = `(${details.time})`;
            // 使用节次名称显示，而不是简单的数字
            if (details.period_ids && Array.isArray(details.period_ids) && details.period_ids.length > 0) {
                detailText = formatPeriodNames(details.period_ids, periods, timeSlots);
            } else if (details.period_numbers && Array.isArray(details.period_numbers)) {
                // 旧数据兼容：通过 period_numbers 查找对应的 period_ids
                const periodIds = details.period_numbers.map(num => {
                    const p = periods.find((_, idx) => idx + 1 === num);
                    return p?.id;
                }).filter(Boolean);
                if (periodIds.length > 0) {
                    detailText = formatPeriodNames(periodIds, periods, timeSlots);
                } else {
                    detailText = `(第${details.period_numbers.join(',')}节)`;
                }
            } else if (details.periods && Array.isArray(details.periods)) {
                // 兼容旧数据：periods 可能是 ID 数组
                detailText = formatPeriodNames(details.periods, periods, timeSlots);
            }
            if (details.option) {
                // 优先使用保存的 option_label，然后从 leaveType 的 input_config 中获取 label
                let optionLabel = details.option_label || details.option;

                // 如果没有保存的 label，尝试从配置中查找（兼容旧数据）
                if (!details.option_label && leaveTypeId) {
                    const leaveType = leaveTypes.find(lt => lt.id === leaveTypeId);
                    if (leaveType && leaveType.input_config) {
                        try {
                            const config = typeof leaveType.input_config === 'string'
                                ? JSON.parse(leaveType.input_config)
                                : leaveType.input_config;

                            if (config.options && Array.isArray(config.options)) {
                                const option = config.options.find(opt => opt.key === details.option);
                                if (option && option.label) {
                                    optionLabel = option.label;
                                }
                            }
                        } catch (e) {
                            console.error('Failed to parse input_config:', e);
                        }
                    }
                }

                detailText = `(${optionLabel})`;
            }
            // 处理文本输入类型
            if (details.text) {
                // 截断过长的文本
                const displayText = details.text.length > 15
                    ? details.text.substring(0, 15) + '...'
                    : details.text;

                // 如果同时有节次信息，一起显示
                if (details.period_ids && Array.isArray(details.period_ids) && details.period_ids.length > 0) {
                    const periodText = formatPeriodNames(details.period_ids, periods, timeSlots);
                    // periodText 格式是 "(节次1、节次2)"，去掉括号
                    const periodNames = periodText.replace(/[()]/g, '');
                    detailText = `(${displayText}-${periodNames})`;
                } else if (details.period_names && Array.isArray(details.period_names) && details.period_names.length > 0) {
                    detailText = `(${displayText}-${details.period_names.join('、')})`;
                } else {
                    detailText = `(${displayText})`;
                }
            }
        }

        // 如果没有详细信息，且有时段ID，显示时段
        if (!detailText && periodId) {
            const periodObj = periods.find(p => p.id === periodId);
            detailText = `(${periodObj?.name || `第${periodId}节`})`;
        }

        const s = status || 'unmarked';
        const classes = `px-2 py-0.5 rounded text-xs font-medium whitespace-nowrap ${styles[s] || styles.unmarked} ${onClick ? 'cursor-pointer hover:opacity-80 ring-1 ring-offset-1 ring-transparent hover:ring-red-300 transition-all' : ''}`;

        return (
            <span
                className={classes}
                onClick={onClick}
                title={onClick ? "点击撤销此记录" : ""}
            >
                {label} {detailText}
            </span>
        );
    };

    return (
        <Transition appear show={isOpen} as={Fragment}>
            <Dialog as="div" className="relative z-50" onClose={onClose}>
                <div className="fixed inset-0 bg-black bg-opacity-25" />
                <div className="fixed inset-0 overflow-y-auto">
                    <div className="flex min-h-full items-center justify-center p-4 text-center">
                        <Dialog.Panel className="w-full max-w-5xl transform overflow-hidden rounded-2xl bg-white p-6 text-left align-middle shadow-xl transition-all">
                            <div className="flex justify-between items-center mb-4">
                                <Dialog.Title as="h3" className="text-lg font-medium leading-6 text-gray-900">
                                    考勤标记 - {formattedDate}
                                </Dialog.Title>
                                <button onClick={onClose} className="text-gray-400 hover:text-gray-500">
                                    <span className="text-2xl">&times;</span>
                                </button>
                            </div>

                            {/* Action Toolbar */}
                            <div className="flex flex-wrap gap-2 mb-4 p-4 bg-gray-50 rounded-lg border border-gray-100">
                                <span className="text-sm text-gray-500 flex items-center mr-2">批量标记 ({selectedStudentIds.size}人):</span>

                                {leaveTypes.map(lt => (
                                    <button
                                        key={lt.id}
                                        onClick={() => handleActionClick(lt)}
                                        className={`px-3 py-1 text-white rounded text-sm ${['late', 'early_leave'].includes(lt.slug) ? 'bg-yellow-500 hover:bg-yellow-600' : (lt.slug === 'absent' ? 'bg-red-600 hover:bg-red-700' : 'bg-blue-600 hover:bg-blue-700')}`}
                                        title={lt.description}
                                    >
                                        {lt.name}
                                    </button>
                                ))}

                                <div className="ml-auto flex items-center gap-2">
                                    <select
                                        value={studentFilter}
                                        onChange={e => setStudentFilter(e.target.value)}
                                        className="text-sm border border-gray-300 rounded px-2 py-1 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                                    >
                                        <option value="all">全部</option>
                                        <option value="marked">有标记</option>
                                    </select>

                                    {/* 弹窗内列数选择 */}
                                    <div className="flex items-center border border-gray-300 rounded overflow-hidden text-sm">
                                        {[1, 2, 3].map(n => (
                                            <button
                                                key={n}
                                                onClick={() => setViewColumns(n)}
                                                className={`px-2 py-1 transition-colors ${viewColumns === n ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-50'}`}
                                                title={`${n}列显示`}
                                            >
                                                {n}列
                                            </button>
                                        ))}
                                        <input
                                            type="number"
                                            min="1"
                                            max="8"
                                            value={viewColumns}
                                            onChange={e => setViewColumns(Math.max(1, parseInt(e.target.value) || 1))}
                                            className="w-10 border-l border-gray-300 px-1 py-1 text-center focus:outline-none"
                                            title="自定义列数"
                                        />
                                    </div>

                                    {/* 打印按钮 */}
                                    <div className="relative">
                                        <button
                                            onClick={() => setShowPrintOptions(v => !v)}
                                            title="打印 / 导出"
                                            className="flex items-center gap-1 px-2 py-1 text-sm border border-gray-300 rounded hover:bg-gray-100 text-gray-600"
                                        >
                                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                                            </svg>
                                            打印
                                        </button>

                                        {showPrintOptions && (
                                            <div className="absolute right-0 top-full mt-1 bg-white border border-gray-200 rounded-lg shadow-lg p-3 z-20 w-44">
                                                <div className="text-xs font-medium text-gray-700 mb-2">每行列数</div>
                                                <div className="flex gap-1 mb-2">
                                                    {[1, 2, 3].map(n => (
                                                        <button
                                                            key={n}
                                                            onClick={() => setPrintColumns(n)}
                                                            className={`flex-1 py-1 rounded text-sm border transition-colors ${printColumns === n ? 'bg-indigo-600 text-white border-indigo-600' : 'border-gray-300 hover:bg-gray-50'}`}
                                                        >
                                                            {n}列
                                                        </button>
                                                    ))}
                                                </div>
                                                <div className="flex items-center gap-2 mb-3">
                                                    <span className="text-xs text-gray-500 whitespace-nowrap">自定义:</span>
                                                    <input
                                                        type="number"
                                                        min="1"
                                                        max="8"
                                                        value={printColumns}
                                                        onChange={e => setPrintColumns(Math.max(1, parseInt(e.target.value) || 1))}
                                                        className="w-full border border-gray-300 rounded px-2 py-0.5 text-sm"
                                                    />
                                                </div>
                                                <button
                                                    onClick={handlePrint}
                                                    className="w-full bg-indigo-600 text-white rounded py-1.5 text-sm hover:bg-indigo-700"
                                                >
                                                    打印
                                                </button>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </div>

                            {viewColumns > 1 ? (
                                /* 多列卡片视图（截屏友好） */
                                <div className="overflow-y-auto max-h-[60vh] border rounded-md p-2">
                                    <div style={{ display: 'grid', gridTemplateColumns: `repeat(${viewColumns}, minmax(0, 1fr))`, gap: '4px' }}>
                                        {visibleStudents.map(student => {
                                            const records = student.attendance || [];
                                            const nonPresentRecords = records.filter(r => r.status !== 'present');
                                            const seenKeys = new Set();
                                            const uniqueRecords = nonPresentRecords.filter(r => {
                                                const details = typeof r.details === 'string' ? JSON.parse(r.details || '{}') : (r.details || {});
                                                const displayLabel = r.display_label || details.display_label || '';
                                                let key;
                                                if (r.source_type === 'leave_request' && r.source_id) key = `leave-${r.source_id}`;
                                                else if (displayLabel) key = `label-${r.leave_type_id}-${displayLabel}`;
                                                else if (r.id) key = `id-${r.id}`;
                                                else key = `${r.status}-${r.period_id}-${(typeof r.details === 'string' ? JSON.parse(r.details || '{}') : (r.details || {})).option || 'none'}`;
                                                if (seenKeys.has(key)) return false;
                                                seenKeys.add(key);
                                                return true;
                                            });
                                            return (
                                                <div key={student.id} className="border border-gray-200 rounded p-1.5 text-xs">
                                                    <div className="flex items-baseline gap-1 mb-1 min-w-0">
                                                        <span className="font-mono text-gray-400 shrink-0 text-[10px]">{student.student_no}</span>
                                                        <span className="font-semibold text-gray-900 truncate">{student.user?.name}</span>
                                                    </div>
                                                    {uniqueRecords.length === 0 ? (
                                                        <span className="text-gray-400">-</span>
                                                    ) : (
                                                        <div className="flex flex-wrap gap-1">
                                                            {uniqueRecords.map((record, idx) => {
                                                                const details = typeof record.details === 'string' ? JSON.parse(record.details || '{}') : (record.details || {});
                                                                const displayLabel = record.display_label || details.display_label;
                                                                return (
                                                                    <StatusBadge
                                                                        key={record.id || idx}
                                                                        status={record.status}
                                                                        details={record.details}
                                                                        leaveTypeId={record.leave_type_id}
                                                                        leaveType={record.leave_type}
                                                                        displayLabel={displayLabel}
                                                                        periodId={record.period_id}
                                                                        period={record.period}
                                                                        approvalStatus={record.approval_status}
                                                                    />
                                                                );
                                                            })}
                                                        </div>
                                                    )}
                                                </div>
                                            );
                                        })}
                                        {visibleStudents.length === 0 && !loading && (
                                            <div className="col-span-full text-center py-4 text-gray-500">
                                                {studentFilter === 'marked' ? '没有有标记的学生' : '无学生数据'}
                                            </div>
                                        )}
                                    </div>
                                </div>
                            ) : (
                                /* 单列表格视图（默认，支持勾选操作） */
                                <div className="overflow-y-auto max-h-[60vh] border rounded-md">
                                    <table className="min-w-full divide-y divide-gray-200">
                                        <thead className="bg-gray-50 sticky top-0">
                                            <tr>
                                                <th scope="col" className="px-6 py-3 text-left w-12">
                                                    <input
                                                        type="checkbox"
                                                        className="h-4 w-4 text-indigo-600 rounded"
                                                        checked={visibleStudents.length > 0 && visibleStudents.every(s => selectedStudentIds.has(s.id))}
                                                        onChange={toggleAll}
                                                    />
                                                </th>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">学号</th>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">姓名</th>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">状态</th>
                                            </tr>
                                        </thead>
                                        <tbody className="bg-white divide-y divide-gray-200">
                                            {visibleStudents.map(student => {
                                                const records = student.attendance || [];
                                                return (
                                                    <tr key={student.id} className={selectedStudentIds.has(student.id) ? 'bg-indigo-50' : 'hover:bg-gray-50'}>
                                                        <td className="px-6 py-4 whitespace-nowrap">
                                                            <input
                                                                type="checkbox"
                                                                className="h-4 w-4 text-indigo-600 rounded"
                                                                checked={selectedStudentIds.has(student.id)}
                                                                onChange={() => toggleSelection(student.id)}
                                                            />
                                                        </td>
                                                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500 font-mono">{student.student_no}</td>
                                                        <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{student.user?.name}</td>
                                                        <td className="px-6 py-4">
                                                            {(() => {
                                                                const nonPresentRecords = records.filter(r => r.status !== 'present');
                                                                const seenKeys = new Set();
                                                                const uniqueRecords = nonPresentRecords.filter(r => {
                                                                    const details = typeof r.details === 'string' ? JSON.parse(r.details || '{}') : (r.details || {});
                                                                    const displayLabel = r.display_label || details.display_label || '';
                                                                    let key;
                                                                    if (r.source_type === 'leave_request' && r.source_id) key = `leave-${r.source_id}`;
                                                                    else if (displayLabel) key = `label-${r.leave_type_id}-${displayLabel}`;
                                                                    else if (r.id) key = `id-${r.id}`;
                                                                    else key = `${r.status}-${r.period_id}-${details.option || 'none'}`;
                                                                    if (seenKeys.has(key)) return false;
                                                                    seenKeys.add(key);
                                                                    return true;
                                                                });
                                                                if (uniqueRecords.length === 0) return <span className="text-gray-400">-</span>;
                                                                return (
                                                                    <div className="flex flex-wrap items-center gap-1">
                                                                        {uniqueRecords.map((record, idx) => {
                                                                            const details = typeof record.details === 'string' ? JSON.parse(record.details || '{}') : (record.details || {});
                                                                            const displayLabel = record.display_label || details.display_label;
                                                                            return (
                                                                                <React.Fragment key={record.id || idx}>
                                                                                    <StatusBadge
                                                                                        status={record.status}
                                                                                        details={record.details}
                                                                                        leaveTypeId={record.leave_type_id}
                                                                                        leaveType={record.leave_type}
                                                                                        displayLabel={displayLabel}
                                                                                        periodId={record.period_id}
                                                                                        period={record.period}
                                                                                        isSelfApplied={record.source_type === 'leave_request'}
                                                                                        approvalStatus={record.approval_status}
                                                                                        onClick={() => handleDeleteRecord(student.id, record)}
                                                                                    />
                                                                                    {idx < uniqueRecords.length - 1 && <span className="text-gray-400">|</span>}
                                                                                </React.Fragment>
                                                                            );
                                                                        })}
                                                                    </div>
                                                                );
                                                            })()}
                                                        </td>
                                                    </tr>
                                                );
                                            })}
                                            {visibleStudents.length === 0 && !loading && (
                                                <tr><td colSpan="4" className="text-center py-4 text-gray-500">
                                                    {studentFilter === 'marked' ? '没有有标记的学生' : '无学生数据'}
                                                </td></tr>
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </Dialog.Panel>
                    </div>
                </div>

                {/* Second Dialog for Dynamic Input */}
                <Transition appear show={inputModalOpen} as={Fragment}>
                    <Dialog as="div" className="relative z-[60]" onClose={() => setInputModalOpen(false)}>
                        <div className="fixed inset-0 bg-black bg-opacity-30" />
                        <div className="fixed inset-0 overflow-y-auto">
                            <div className="flex min-h-full items-center justify-center p-4 text-center">
                                <Dialog.Panel className="w-full max-w-md transform overflow-visible rounded-2xl bg-white p-6 text-left align-middle shadow-xl transition-all" style={{ minHeight: '200px' }}>
                                    <Dialog.Title as="h3" className="text-lg font-medium leading-6 text-gray-900 mb-4">
                                        {pendingAction?.leaveType?.name} - 详细信息
                                    </Dialog.Title>

                                    <div className="space-y-4" style={{ minHeight: '100px' }}>
                                        {pendingAction?.leaveType?.input_type === 'time' && (() => {
                                            // 解析input_config
                                            let config = {};
                                            try {
                                                config = typeof pendingAction.leaveType.input_config === 'string'
                                                    ? JSON.parse(pendingAction.leaveType.input_config)
                                                    : pendingAction.leaveType.input_config || {};
                                            } catch (e) {
                                                console.error('Failed to parse input_config:', e);
                                            }

                                            return (
                                                <>
                                                    <div>
                                                        <label className="block text-sm font-medium text-gray-700 mb-2">时间</label>
                                                        <input
                                                            type="time"
                                                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                                            onChange={e => setInputData({ ...inputData, time: e.target.value })}
                                                        />
                                                    </div>

                                                    {config.require_period && (
                                                        <div className="mt-4">
                                                            <label className="block text-sm font-medium text-gray-700 mb-2">选择节次</label>
                                                            <select
                                                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                                                onChange={e => setInputData({ ...inputData, period: parseInt(e.target.value) })}
                                                                defaultValue=""
                                                            >
                                                                <option value="" disabled>请选择节次</option>
                                                                {periods.map((p, index) => (
                                                                    <option key={p.id} value={p.id}>第{index + 1}节</option>
                                                                ))}
                                                            </select>
                                                        </div>
                                                    )}
                                                </>
                                            );
                                        })()}

                                        {pendingAction?.leaveType?.input_type === 'period_select' && (() => {
                                            // 解析input_config
                                            let config = {};
                                            try {
                                                config = typeof pendingAction.leaveType.input_config === 'string'
                                                    ? JSON.parse(pendingAction.leaveType.input_config)
                                                    : pendingAction.leaveType.input_config || {};
                                            } catch (e) {
                                                console.error('Failed to parse input_config:', e);
                                            }

                                            // 如果有options，显示选项（如早操/晚操）
                                            if (config.options && config.options.length > 0) {
                                                return (
                                                    <div>
                                                        <label className="block text-sm font-medium text-gray-700 mb-2">选择选项</label>
                                                        <div className="space-y-2">
                                                            {config.options.map(opt => (
                                                                <label key={opt} className="flex items-center">
                                                                    <input
                                                                        type="radio"
                                                                        name="period_option"
                                                                        className="h-4 w-4 text-indigo-600 border-gray-300 focus:ring-indigo-500"
                                                                        onChange={() => setInputData({ ...inputData, option: opt })}
                                                                    />
                                                                    <span className="ml-2 text-sm text-gray-700">
                                                                        {opt === 'morning_exercise' ? '早操' :
                                                                            (opt === 'evening_exercise' ? '晚操' : opt)}
                                                                    </span>
                                                                </label>
                                                            ))}
                                                        </div>
                                                    </div>
                                                );
                                            }

                                            // 否则显示节次选择（旷课）
                                            return (
                                                <div>
                                                    <label className="block text-sm font-medium text-gray-700 mb-2">选择节次</label>
                                                    <div className="grid grid-cols-4 gap-2">
                                                        {(() => {
                                                            console.log('[Absent Periods] Rendering periods:', periods.map((p, i) => ({
                                                                id: p.id,
                                                                name: p.name,
                                                                ordinal: p.ordinal,
                                                                index: i,
                                                                display: `第${i + 1}节`
                                                            })));
                                                            return periods.map((p, index) => (
                                                                <label key={p.id} className={`flex items-center justify-center p-2 border rounded cursor-pointer ${inputData.periods?.includes(p.id) ? 'bg-indigo-100 border-indigo-500 text-indigo-700' : 'hover:bg-gray-50'}`}>
                                                                    <input
                                                                        type="checkbox"
                                                                        className="sr-only"
                                                                        onChange={e => {
                                                                            const current = inputData.periods || [];
                                                                            if (current.includes(p.id)) setInputData({ ...inputData, periods: current.filter(x => x !== p.id) });
                                                                            else setInputData({ ...inputData, periods: [...current, p.id] });
                                                                        }}
                                                                    />
                                                                    {p.name}
                                                                </label>
                                                            ));
                                                        })()}
                                                    </div>
                                                </div>
                                            );
                                        })()}

                                        {pendingAction?.leaveType?.input_type === 'duration_select' && (() => {
                                            // 解析input_config获取配置的时段
                                            let config = {};
                                            try {
                                                config = typeof pendingAction.leaveType.input_config === 'string'
                                                    ? JSON.parse(pendingAction.leaveType.input_config)
                                                    : pendingAction.leaveType.input_config || {};
                                            } catch (e) {
                                                console.error('Failed to parse input_config:', e);
                                            }

                                            // 严格按照配置的 options 过滤时段
                                            if (!config.options || config.options.length === 0) {
                                                return (
                                                    <div className="p-3 bg-yellow-50 rounded text-sm text-yellow-700">
                                                        该请假类型未配置可选时段
                                                    </div>
                                                );
                                            }

                                            const allowedSlotKeys = config.options.map(opt =>
                                                typeof opt === 'object' ? opt.key : opt
                                            );
                                            const filteredSlots = timeSlots.filter(slot => {
                                                const slotKey = `time_slot_${slot.id}`;
                                                return allowedSlotKeys.includes(slotKey) || allowedSlotKeys.includes(String(slot.id));
                                            });

                                            if (filteredSlots.length === 0) {
                                                return (
                                                    <div className="p-3 bg-yellow-50 rounded text-sm text-yellow-700">
                                                        该请假类型配置的时段不存在于系统时段中
                                                    </div>
                                                );
                                            }

                                            return (
                                                <div className="space-y-3">
                                                    <label className="block text-sm font-medium text-gray-700">选择时段</label>
                                                    <div className="flex flex-wrap gap-2">
                                                        {filteredSlots.map(slot => (
                                                            <button
                                                                key={slot.id}
                                                                type="button"
                                                                onClick={() => setInputData({
                                                                    ...inputData,
                                                                    time_slot_id: slot.id,
                                                                    time_slot_name: slot.name,
                                                                    option: `time_slot_${slot.id}`,
                                                                    option_label: slot.name,
                                                                    option_periods: (slot.period_ids || []).length || 1,
                                                                    period_ids: slot.period_ids || []
                                                                })}
                                                                className={`px-4 py-2 rounded-lg border text-sm transition-colors ${inputData.time_slot_id === slot.id
                                                                    ? 'bg-indigo-600 text-white border-indigo-600'
                                                                    : 'bg-white border-gray-300 hover:bg-gray-50'
                                                                    }`}
                                                            >
                                                                {slot.name}
                                                                <span className={`text-xs ml-1 ${inputData.time_slot_id === slot.id ? 'text-indigo-200' : 'text-gray-400'}`}>
                                                                    ({(slot.period_ids || []).length || 1}节)
                                                                </span>
                                                            </button>
                                                        ))}
                                                    </div>

                                                    {inputData.time_slot_id && (
                                                        <div className="mt-2">
                                                            <button
                                                                type="button"
                                                                onClick={() => setShowPeriodDetail(!showPeriodDetail)}
                                                                className="text-xs text-indigo-600 hover:text-indigo-800"
                                                            >
                                                                {showPeriodDetail ? '▲ 收起自定义节次' : '▼ 自定义节次（可选）'}
                                                            </button>
                                                            {showPeriodDetail && (
                                                                <div className="mt-2 p-3 bg-gray-50 rounded border border-gray-200">
                                                                    <div className="text-xs text-gray-500 mb-2">
                                                                        点击可单独选择/取消节次：
                                                                    </div>
                                                                    <div className="flex flex-wrap gap-2">
                                                                        {periods.map(period => {
                                                                            const isSelected = inputData.period_ids?.includes(period.id);
                                                                            const selectedSlot = timeSlots.find(s => s.id === inputData.time_slot_id);
                                                                            const isInSlot = selectedSlot?.period_ids?.includes(period.id);
                                                                            return (
                                                                                <label
                                                                                    key={period.id}
                                                                                    className={`px-3 py-1.5 rounded border text-sm cursor-pointer transition-colors ${isSelected
                                                                                        ? 'bg-indigo-100 border-indigo-500 text-indigo-700'
                                                                                        : isInSlot
                                                                                            ? 'bg-gray-100 border-gray-300 text-gray-500 hover:bg-gray-200'
                                                                                            : 'bg-white border-gray-200 text-gray-400 hover:bg-gray-50'
                                                                                        }`}
                                                                                >
                                                                                    <input
                                                                                        type="checkbox"
                                                                                        className="sr-only"
                                                                                        checked={isSelected}
                                                                                        onChange={() => {
                                                                                            const currentIds = inputData.period_ids || [];
                                                                                            let newIds;
                                                                                            if (isSelected) {
                                                                                                newIds = currentIds.filter(id => id !== period.id);
                                                                                            } else {
                                                                                                newIds = [...currentIds, period.id];
                                                                                            }
                                                                                            setInputData({
                                                                                                ...inputData,
                                                                                                period_ids: newIds,
                                                                                                option_periods: newIds.length
                                                                                            });
                                                                                        }}
                                                                                    />
                                                                                    {period.name}
                                                                                </label>
                                                                            );
                                                                        })}
                                                                    </div>
                                                                    <div className="mt-2 text-xs text-gray-500">
                                                                        已选：{(inputData.period_ids || []).length}节
                                                                    </div>
                                                                </div>
                                                            )}
                                                        </div>
                                                    )}
                                                </div>
                                            );
                                        })()}

                                        {pendingAction?.leaveType?.input_type === 'text' && (() => {
                                            // 解析input_config获取配置
                                            let config = {};
                                            try {
                                                config = typeof pendingAction.leaveType.input_config === 'string'
                                                    ? JSON.parse(pendingAction.leaveType.input_config)
                                                    : pendingAction.leaveType.input_config || {};
                                            } catch (e) {
                                                console.error('Failed to parse input_config:', e);
                                            }

                                            const withPeriods = config.with_periods !== false; // 默认启用节次选择

                                            return (
                                                <div className="space-y-4">
                                                    <div>
                                                        <label className="block text-sm font-medium text-gray-700 mb-2">
                                                            {config.label || '去向说明'}
                                                        </label>
                                                        <textarea
                                                            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                                            rows={2}
                                                            placeholder={config.placeholder || '请输入说明...'}
                                                            value={inputData.text || ''}
                                                            onChange={e => setInputData({
                                                                ...inputData,
                                                                text: e.target.value,
                                                                text_label: config.label || '去向说明'
                                                            })}
                                                        />
                                                    </div>

                                                    {withPeriods && (
                                                        <div>
                                                            <label className="block text-sm font-medium text-gray-700 mb-2">
                                                                选择节次 <span className="text-gray-400 font-normal">(点名时只在选定节次显示为活动状态)</span>
                                                            </label>
                                                            <div className="flex flex-wrap gap-2">
                                                                {periods.map((period, index) => {
                                                                    const isSelected = (inputData.period_ids || []).includes(period.id);
                                                                    return (
                                                                        <button
                                                                            key={period.id}
                                                                            type="button"
                                                                            onClick={() => {
                                                                                const currentIds = inputData.period_ids || [];
                                                                                let newIds;
                                                                                if (isSelected) {
                                                                                    newIds = currentIds.filter(id => id !== period.id);
                                                                                } else {
                                                                                    newIds = [...currentIds, period.id];
                                                                                }
                                                                                setInputData({
                                                                                    ...inputData,
                                                                                    period_ids: newIds
                                                                                });
                                                                            }}
                                                                            className={`px-3 py-1.5 rounded-lg border text-sm transition-colors ${isSelected
                                                                                ? 'bg-indigo-600 text-white border-indigo-600'
                                                                                : 'bg-white border-gray-300 hover:bg-gray-50'
                                                                                }`}
                                                                        >
                                                                            {period.name}
                                                                        </button>
                                                                    );
                                                                })}
                                                            </div>
                                                            {(inputData.period_ids || []).length > 0 && (
                                                                <p className="mt-2 text-xs text-indigo-600">
                                                                    已选：{(inputData.period_ids || []).length}节
                                                                </p>
                                                            )}
                                                        </div>
                                                    )}

                                                    <p className="text-xs text-gray-400">此说明将记录在考勤详情中</p>
                                                </div>
                                            );
                                        })()}
                                    </div>

                                    <div className="mt-6 flex justify-end space-x-2">
                                        <button onClick={() => setInputModalOpen(false)} className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50">取消</button>
                                        <button onClick={handleInputConfirm} className="px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-md hover:bg-indigo-700">确定</button>
                                    </div>
                                </Dialog.Panel>
                            </div>
                        </div>
                    </Dialog>
                </Transition>
            </Dialog>
        </Transition>
    );
}
