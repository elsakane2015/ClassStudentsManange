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

    /// 压缩并规整异常汇总中的 option 文案，将手工标记的平铺节次压缩为简练格式并拆分备注
    /// - 例如："技能训练-早读、早操、第1节、第2节、第3节、第4节、第5节、第6节、晚操"
    ///   -> (option: "早读、早操、第1-6节、晚操 (9节)", note: "技能训练")
    /// - 例如："早读、早操、第1-6节、晚操 (9节) · 技能训练"
    ///   -> (option: "早读、早操、第1-6节、晚操 (9节)", note: "技能训练")
    /// - 例如："第1-6节、早读、早操、晚操 (9节)"
    ///   -> (option: "早读、早操、第1-6节、晚操 (9节)", note: nil)
    static func compactCalendarOption(
        _ rawOption: String?,
        explicitNote: String? = nil
    ) -> (option: String, note: String?) {
        let trimmed = rawOption?.trimmingCharacters(in: .whitespacesAndNewlines) ?? ""
        var note = explicitNote?.trimmingCharacters(in: .whitespacesAndNewlines)
        if note?.isEmpty == true { note = nil }

        guard !trimmed.isEmpty else {
            return ("", note)
        }

        // 1. 新版后端格式："<节次/时段> · <备注>"
        if let dotRange = trimmed.range(of: " · ") {
            let left = String(trimmed[..<dotRange.lowerBound]).trimmingCharacters(in: .whitespacesAndNewlines)
            let right = String(trimmed[dotRange.upperBound...]).trimmingCharacters(in: .whitespacesAndNewlines)
            if !left.isEmpty && !right.isEmpty {
                let compactedLeft = compactPeriodSummaryString(left) ?? left
                return (compactedLeft, note ?? right)
            }
        }

        // 2. 旧版后端手工标记带备注格式："<备注>-<顿号分隔的多节次>"
        if let dashIndex = trimmed.firstIndex(of: "-") {
            let left = String(trimmed[..<dashIndex]).trimmingCharacters(in: .whitespacesAndNewlines)
            let right = String(trimmed[trimmed.index(after: dashIndex)...]).trimmingCharacters(in: .whitespacesAndNewlines)
            let leftLooksLikePeriodRange = left.hasPrefix("第") && left.last?.isNumber == true
            if !left.isEmpty && !leftLooksLikePeriodRange,
               let compactedRight = compactPeriodSummaryString(right, requireMultiple: false) {
                return (compactedRight, note ?? left)
            }
        }

        // 3. 纯节次列表或已带 (N节) 尾缀的节次汇总：按校园自然顺序重排并合并连续区间
        if let compacted = compactPeriodSummaryString(trimmed, requireMultiple: false) {
            return (compacted, note)
        }

        // 4. 若 option 本身就等于 explicitNote，则不重复显示副行备注
        if let n = note, n == trimmed {
            return (trimmed, nil)
        }

        return (trimmed, note)
    }

    private static func compactPeriodSummaryString(_ raw: String, requireMultiple: Bool = true) -> String? {
        let trimmed = raw.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !trimmed.isEmpty else { return nil }

        var inner = trimmed
        var explicitCount: Int? = nil
        if let regex = try? NSRegularExpression(pattern: "^(.*?)\\s*\\((\\d+)节\\)$"),
           let match = regex.firstMatch(in: trimmed, range: NSRange(trimmed.startIndex..., in: trimmed)),
           let bodyRange = Range(match.range(at: 1), in: trimmed),
           let countRange = Range(match.range(at: 2), in: trimmed) {
            inner = String(trimmed[bodyRange]).trimmingCharacters(in: .whitespacesAndNewlines)
            explicitCount = Int(trimmed[countRange])
        }

        let tokens = inner
            .components(separatedBy: CharacterSet(charactersIn: "、/"))
            .map { $0.trimmingCharacters(in: .whitespacesAndNewlines) }
            .filter { !$0.isEmpty }

        guard !tokens.isEmpty else { return nil }
        if requireMultiple && tokens.count < 2 {
            return nil
        }

        var expandedNames: [String] = []
        let numberedRangeRegex = try? NSRegularExpression(pattern: "^第([\\d,-]+)节$")
        let knownSpecials: Set<String> = ["早读", "早操", "晚操", "晚自习", "夜自习", "晨读", "晨跑", "午休", "课间操"]

        for token in tokens {
            let nsRange = NSRange(token.startIndex..., in: token)
            if let match = numberedRangeRegex?.firstMatch(in: token, range: nsRange),
               let bodyRange = Range(match.range(at: 1), in: token) {
                let body = String(token[bodyRange])
                for seg in body.split(separator: ",") {
                    let bounds = seg.split(separator: "-")
                    if bounds.count == 1, let n = Int(bounds[0]) {
                        expandedNames.append("第\(n)节")
                    } else if bounds.count == 2, let s = Int(bounds[0]), let e = Int(bounds[1]), s <= e, e - s <= 20 {
                        for n in s...e {
                            expandedNames.append("第\(n)节")
                        }
                    } else {
                        return nil
                    }
                }
            } else if knownSpecials.contains(token) {
                expandedNames.append(token)
            } else {
                return nil
            }
        }

        guard !expandedNames.isEmpty else { return nil }
        var seen = Set<String>()
        let uniqueNames = expandedNames.filter { seen.insert($0).inserted }
        let merged = mergePeriodNames(uniqueNames, separator: "、")
        let count = explicitCount ?? uniqueNames.count
        if count > 1 {
            return "\(merged) (\(count)节)"
        }
        return merged
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
