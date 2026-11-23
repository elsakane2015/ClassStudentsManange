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

        let orderedNames = orderedPeriodNames(for: normalized, periods: periods)
        return mergePeriodNames(orderedNames, separator: "/")
    }

    /// 详细节次格式：命中时段时同时展示具体节次，例如 "上午（早读/早操/第1-3节）"
    static func formatDetailedPeriodSummary(
        periodIds: [Int],
        periods: [ClassPeriod],
        timeSlots: [TimeSlot] = [],
        preferredSlotName: String? = nil
    ) -> String {
        guard !periodIds.isEmpty else {
            return preferredSlotName?.trimmingCharacters(in: .whitespacesAndNewlines) ?? ""
        }
        let normalized = Array(Set(periodIds)).sorted()
        let orderedNames = orderedPeriodNames(for: normalized, periods: periods)
        let detail = mergePeriodNames(orderedNames, separator: "/")

        var matchedSlotName: String? = nil
        for slot in timeSlots {
            let slotIds = Array(Set(slot.periodIds ?? [])).sorted()
            if !slotIds.isEmpty && slotIds == normalized {
                matchedSlotName = slot.name.trimmingCharacters(in: .whitespacesAndNewlines)
                break
            }
        }
        if matchedSlotName == nil || matchedSlotName?.isEmpty == true {
            let cleanPreferred = preferredSlotName?.trimmingCharacters(in: .whitespacesAndNewlines) ?? ""
            if !cleanPreferred.isEmpty && cleanPreferred != detail {
                matchedSlotName = cleanPreferred
            }
        }

        if let slotName = matchedSlotName, !slotName.isEmpty {
            if detail.isEmpty || detail == slotName {
                return slotName
            }
            return "\(slotName)（\(detail)）"
        }
        return detail
    }

    private static func orderedPeriodNames(for periodIds: [Int], periods: [ClassPeriod]) -> [String] {
        let idSet = Set(periodIds)
        // 优先保留后端 periods 配置的校园自然顺序
        let inConfigOrder = periods.filter { idSet.contains($0.id) }.map(\.name)
        if !inConfigOrder.isEmpty {
            return inConfigOrder
        }
        let periodMap = Dictionary(uniqueKeysWithValues: periods.map { ($0.id, $0.name) })
        return periodIds.compactMap { periodMap[$0] }
    }

    /// 将 ["早读","早操","第1节","第2节","第3节","晚操"] 按校园自然顺序合并为 "早读/早操/第1-3节/晚操"
    static func mergePeriodNames(_ names: [String], separator: String = "/") -> String {
        guard !names.isEmpty else { return "" }

        var regularNumbers: [Int] = []
        var morningNames: [String] = []
        var otherSpecialNames: [String] = []
        let regex = try? NSRegularExpression(pattern: "^第(\\d+)节$")

        for name in names {
            let range = NSRange(name.startIndex..., in: name)
            if let match = regex?.firstMatch(in: name, range: range),
               let numRange = Range(match.range(at: 1), in: name),
               let num = Int(name[numRange]) {
                regularNumbers.append(num)
            } else if name.contains("早") || name.contains("晨") {
                if !morningNames.contains(name) { morningNames.append(name) }
            } else {
                if !otherSpecialNames.contains(name) { otherSpecialNames.append(name) }
            }
        }

        var parts: [String] = []
        parts.append(contentsOf: morningNames)

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

        parts.append(contentsOf: otherSpecialNames)
        return parts.joined(separator: separator)
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
