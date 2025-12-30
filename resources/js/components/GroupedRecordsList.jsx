import React, { useState, useMemo, useEffect } from 'react';
import { format, parseISO } from 'date-fns';
import { zhCN } from 'date-fns/locale';

/**
 * 按月份分组的记录列表组件
 * 支持折叠/展开，默认只展开最近一个月
 * 
 * @param {Array} records - 记录数组，每条记录需要有 date 字段
 * @param {Function} renderRecord - 渲染单条记录的函数 (record, index) => ReactNode
 * @param {String} emptyText - 无数据时显示的文字
 * @param {String} dateField - 日期字段名，默认为 'date'
 */
export default function GroupedRecordsList({
    records = [],
    renderRecord,
    emptyText = '暂无记录',
    dateField = 'date'
}) {
    const [expandedMonths, setExpandedMonths] = useState({});

    // 按月份分组记录
    const groupedRecords = useMemo(() => {
        if (!records || !Array.isArray(records) || records.length === 0) return [];

        const groups = {};

        records.forEach(record => {
            if (!record) return;
            const dateStr = record[dateField];
            if (!dateStr) return;

            try {
                // 提取月份 key (YYYY-MM)
                // 支持多种日期格式: 2025-12-30, 2025.12.30, 2025-12-30T10:00:00
                let dateOnly = typeof dateStr === 'string' ? dateStr.split('T')[0] : dateStr;

                // 将 2025.12.30 格式转换为 2025-12-30
                if (typeof dateOnly === 'string' && dateOnly.includes('.')) {
                    dateOnly = dateOnly.replace(/\./g, '-');
                }

                const date = typeof dateOnly === 'string' ? parseISO(dateOnly) : dateOnly;

                // 验证日期是否有效
                if (isNaN(date.getTime())) return;

                const monthKey = format(date, 'yyyy-MM');
                const monthLabel = format(date, 'yyyy年M月', { locale: zhCN });

                if (!groups[monthKey]) {
                    groups[monthKey] = {
                        key: monthKey,
                        label: monthLabel,
                        records: []
                    };
                }
                groups[monthKey].records.push(record);
            } catch (e) {
                console.error('GroupedRecordsList: Error parsing date', dateStr, e);
            }
        });

        // 按月份倒序排列（最新的在前）
        return Object.values(groups).sort((a, b) => b.key.localeCompare(a.key));
    }, [records, dateField]);

    // 当 groupedRecords 变化时，初始化展开第一个月份
    useEffect(() => {
        if (groupedRecords.length > 0) {
            setExpandedMonths(prev => {
                // 如果当前没有任何展开的月份，默认展开第一个
                const hasExpanded = Object.values(prev).some(v => v);
                if (!hasExpanded) {
                    return { [groupedRecords[0].key]: true };
                }
                return prev;
            });
        }
    }, [groupedRecords]);

    const toggleMonth = (monthKey) => {
        setExpandedMonths(prev => ({
            ...prev,
            [monthKey]: !prev[monthKey]
        }));
    };

    const expandAll = () => {
        const all = {};
        groupedRecords.forEach(g => { all[g.key] = true; });
        setExpandedMonths(all);
    };

    const collapseAll = () => {
        setExpandedMonths({});
    };

    // 如果没有记录或 emptyText 为 null/false，不显示空状态
    if (!records || !Array.isArray(records) || records.length === 0) {
        if (emptyText) {
            return (
                <p className="text-gray-500 text-center py-4">{emptyText}</p>
            );
        }
        return null;
    }

    // 如果分组后没有有效记录（所有记录都没有有效日期）
    if (groupedRecords.length === 0) {
        if (emptyText) {
            return (
                <p className="text-gray-500 text-center py-4">{emptyText}</p>
            );
        }
        return null;
    }

    // 计算总记录数
    const totalRecords = records.length;

    return (
        <div className="space-y-3">
            {/* 顶部操作栏 */}
            <div className="flex justify-between items-center text-sm">
                <span className="text-gray-500">
                    共 {totalRecords} 条记录，{groupedRecords.length} 个月
                </span>
                <div className="flex gap-2">
                    <button
                        onClick={expandAll}
                        className="text-indigo-600 hover:text-indigo-800"
                    >
                        展开全部
                    </button>
                    <span className="text-gray-300">|</span>
                    <button
                        onClick={collapseAll}
                        className="text-gray-600 hover:text-gray-800"
                    >
                        收起全部
                    </button>
                </div>
            </div>

            {/* 分组列表 */}
            <div className="space-y-2">
                {groupedRecords.map(group => (
                    <div key={group.key} className="border rounded-lg overflow-hidden">
                        {/* 月份标题 - 可点击折叠 */}
                        <div
                            className="flex justify-between items-center px-4 py-3 bg-gray-50 cursor-pointer hover:bg-gray-100 transition-colors"
                            onClick={() => toggleMonth(group.key)}
                        >
                            <div className="flex items-center gap-2">
                                <svg
                                    className={`w-4 h-4 text-gray-500 transition-transform ${expandedMonths[group.key] ? 'rotate-90' : ''}`}
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                >
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                                </svg>
                                <span className="font-medium text-gray-800">
                                    📅 {group.label}
                                </span>
                            </div>
                            <span className="text-sm text-gray-500 bg-white px-2 py-0.5 rounded">
                                {group.records.length} 条
                            </span>
                        </div>

                        {/* 记录列表 - 展开状态 */}
                        {expandedMonths[group.key] && (
                            <div className="divide-y divide-gray-100">
                                {group.records.map((record, index) => (
                                    <div key={record.id || index} className="px-4 py-3 hover:bg-gray-50">
                                        {renderRecord(record, index)}
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                ))}
            </div>
        </div>
    );
}
