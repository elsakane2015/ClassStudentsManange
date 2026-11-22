import { format, parseISO } from 'date-fns';

/**
 * 将后端返回的日期值（纯日期 `2026-11-20`、点分日期 `2026.11.20` 或 Laravel UTC ISO `2026-11-19T16:00:00.000000Z`）
 * 统一规范为本地时区的 `yyyy-MM-dd`，彻底杜绝直接 split('T')[0] / slice(0, 10) 导致的东八区提前一天问题。
 */
export function normalizeDateKey(raw) {
    if (!raw) return '';
    if (raw instanceof Date) {
        return Number.isNaN(raw.getTime()) ? '' : format(raw, 'yyyy-MM-dd');
    }

    const str = String(raw).trim();
    if (!str) return '';

    // 带时区信息的 ISO 8601 字符串（如 ...T16:00:00.000000Z 或 ...+08:00），解析后按本地时区还原日期
    if (str.includes('T') && (str.endsWith('Z') || /[+-]\d{2}:?\d{2}$/.test(str))) {
        const parsed = parseISO(str);
        if (!Number.isNaN(parsed.getTime())) {
            return format(parsed, 'yyyy-MM-dd');
        }
    }

    const head = str.split(/[T ]/)[0].replace(/\./g, '-');
    return head.slice(0, 10);
}

/**
 * 将日期值格式化为点分形式 `yyyy.MM.dd`（空值返回 `-`）
 */
export function formatDotDate(raw) {
    const key = normalizeDateKey(raw);
    return key ? key.replace(/-/g, '.') : '-';
}

/**
 * 将日期值安全解析为本地时区 00:00:00 的 Date 对象（不受 `new Date('YYYY-MM-DD')` 按 UTC 解析的影响）
 */
export function parseLocalDate(raw) {
    const key = normalizeDateKey(raw);
    if (!key) return null;
    const parsed = parseISO(key);
    return Number.isNaN(parsed.getTime()) ? null : parsed;
}
