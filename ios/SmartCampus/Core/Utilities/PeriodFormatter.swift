import Foundation

/// 节次智能合并与日期格式化工具（与后端 LeaveRequestController 及 Web 端完全对齐）
enum PeriodFormatter {
    static func formatPeriodNames(
        periodIds: [Int],
        periods: [ClassPeriod],
        timeSlots: [TimeSlot] = []
    ) -> String {
        guard !periodIds.isEmpty, !periods.isEmpty else { return "" }

        let normalized = Array(Set(periodIds)).sorted()

        for slot in timeSlots {
            let slotIds = Array(Set(slot.periodIds ?? [])).sorted()
            if !slotIds.isEmpty && slotIds == normalized {
                return slot.name
            }
        }

        let periodMap = Dictionary(uniqueKeysWithValues: periods.map { ($0.id, $0.name) })
        let names = normalized.compactMap { periodMap[$0] }
        return mergePeriodNames(names)
    }

    /// 将 ["第1节","第2节","第3节","晚自习"] 合并为 "第1-3节、晚自习"
    static func mergePeriodNames(_ names: [String]) -> String {
        guard !names.isEmpty else { return "" }

        var regularNumbers: [Int] = []
        var specialNames: [String] = []
        let regex = try? NSRegularExpression(pattern: "^第(\\d+)节$")

        for name in names {
            let range = NSRange(name.startIndex..., in: name)
            if let match = regex?.firstMatch(in: name, range: range),
               let numRange = Range(match.range(at: 1), in: name),
               let num = Int(name[numRange]) {
                regularNumbers.append(num)
            } else if !specialNames.contains(name) {
                specialNames.append(name)
            }
        }

        var parts: [String] = []
        let sortedUnique = Array(Set(regularNumbers)).sorted()
        if !sortedUnique.isEmpty {
            var ranges: [String] = []
            var start = sortedUnique[0]
            var end = start

            for num in sortedUnique.dropFirst() {
                if num == end + 1 {
                    end = num
                } else {
                    ranges.append(start == end ? "\(start)" : "\(start)-\(end)")
                    start = num
                    end = num
                }
            }
            ranges.append(start == end ? "\(start)" : "\(start)-\(end)")
            parts.append("第\(ranges.joined(separator: ","))节")
        }

        parts.append(contentsOf: specialNames)
        return parts.joined(separator: "、")
    }
}

enum SCDateFormatter {
    static let yyyyMMdd: DateFormatter = {
        let f = DateFormatter()
        f.dateFormat = "yyyy-MM-dd"
        f.locale = Locale(identifier: "en_US_POSIX")
        return f
    }()

    static let yyyyMM: DateFormatter = {
        let f = DateFormatter()
        f.dateFormat = "yyyy-MM"
        f.locale = Locale(identifier: "en_US_POSIX")
        return f
    }()

    static let hhmm: DateFormatter = {
        let f = DateFormatter()
        f.dateFormat = "HH:mm"
        f.locale = Locale(identifier: "en_US_POSIX")
        return f
    }()

    static let cnMonthDay: DateFormatter = {
        let f = DateFormatter()
        f.dateFormat = "M月d日 EEEE"
        f.locale = Locale(identifier: "zh_CN")
        return f
    }()

    static let cnYearMonth: DateFormatter = {
        let f = DateFormatter()
        f.dateFormat = "yyyy年M月"
        f.locale = Locale(identifier: "zh_CN")
        return f
    }()

    static let iso8601WithFractional: ISO8601DateFormatter = {
        let f = ISO8601DateFormatter()
        f.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        return f
    }()

    static let iso8601Basic: ISO8601DateFormatter = {
        let f = ISO8601DateFormatter()
        f.formatOptions = [.withInternetDateTime]
        return f
    }()

    static func todayString() -> String {
        yyyyMMdd.string(from: Date())
    }

    /// 将后端返回的日期字符串（含纯日期 `2026-11-20` 或 Laravel UTC ISO `2026-11-19T16:00:00.000000Z`）
    /// 统一规整为本地时区（东八区）的 `yyyy-MM-dd`，彻底避免直接截取前 10 位导致日期提前一天。
    static func normalizeDateKey(_ raw: String?) -> String {
        guard let raw else { return "" }
        let trimmed = raw.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmed.isEmpty else { return "" }

        if trimmed.contains("T") {
            if let d = iso8601WithFractional.date(from: trimmed) ?? iso8601Basic.date(from: trimmed) {
                return yyyyMMdd.string(from: d)
            }
        }
        return String(trimmed.prefix(10))
    }

    static func parseDate(_ str: String?) -> Date? {
        let key = normalizeDateKey(str)
        guard !key.isEmpty else { return nil }
        return yyyyMMdd.date(from: key)
    }

    static func displayDateTime(_ raw: String?) -> String {
        guard let raw, !raw.isEmpty else { return "-" }
        if let date = iso8601WithFractional.date(from: raw) ?? iso8601Basic.date(from: raw) {
            let f = DateFormatter()
            f.dateFormat = "yyyy-MM-dd HH:mm"
            f.locale = Locale(identifier: "zh_CN")
            return f.string(from: date)
        }
        return raw.replacingOccurrences(of: "T", with: " ").prefix(16).description
    }
}
