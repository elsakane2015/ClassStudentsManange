package com.litotime.smartcampus.core.util

import com.litotime.smartcampus.data.model.ClassPeriod
import com.litotime.smartcampus.data.model.TimeSlot
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.booleanOrNull
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.doubleOrNull
import kotlinx.serialization.json.intOrNull
import java.time.Instant
import java.time.LocalDate
import java.time.LocalDateTime
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Locale

/**
 * 节次智能合并与日期格式化工具（与后端及 iOS 端算法完全一致）
 */
object PeriodFormatter {
    private val numberedRegex = Regex("^第(\\d+)节$")

    fun formatPeriodNames(
        periodIds: List<Int>,
        periods: List<ClassPeriod>,
        timeSlots: List<TimeSlot> = emptyList()
    ): String {
        if (periodIds.isEmpty() || periods.isEmpty()) return ""
        val normalized = periodIds.distinct().sorted()

        for (slot in timeSlots) {
            val slotIds = slot.periodIds.distinct().sorted()
            if (slotIds.isNotEmpty() && slotIds == normalized) {
                return slot.name
            }
        }

        val orderedNames = orderedPeriodNames(normalized, periods)
        return mergePeriodNames(orderedNames, separator = "/")
    }

    /** 详细节次格式：命中时段时同时展示具体节次，例如 "上午（早读/早操/第1-3节）" */
    fun formatDetailedPeriodSummary(
        periodIds: List<Int>,
        periods: List<ClassPeriod>,
        timeSlots: List<TimeSlot> = emptyList(),
        preferredSlotName: String? = null
    ): String {
        if (periodIds.isEmpty()) return preferredSlotName?.trim().orEmpty()
        val normalized = periodIds.distinct().sorted()
        val orderedNames = orderedPeriodNames(normalized, periods)
        val detail = mergePeriodNames(orderedNames, separator = "/")

        var matchedSlotName: String? = null
        for (slot in timeSlots) {
            val slotIds = slot.periodIds.distinct().sorted()
            if (slotIds.isNotEmpty() && slotIds == normalized) {
                matchedSlotName = slot.name.trim()
                break
            }
        }
        if (matchedSlotName.isNullOrBlank()) {
            val cleanPreferred = preferredSlotName?.trim().orEmpty()
            if (cleanPreferred.isNotEmpty() && cleanPreferred != detail) {
                matchedSlotName = cleanPreferred
            }
        }

        return if (!matchedSlotName.isNullOrBlank()) {
            if (detail.isEmpty() || detail == matchedSlotName) matchedSlotName
            else "$matchedSlotName（$detail）"
        } else {
            detail
        }
    }

    private fun orderedPeriodNames(periodIds: List<Int>, periods: List<ClassPeriod>): List<String> {
        val idSet = periodIds.toSet()
        val inConfigOrder = periods.filter { it.id in idSet }.map { it.name }
        if (inConfigOrder.isNotEmpty()) return inConfigOrder
        val periodMap = periods.associate { it.id to it.name }
        return periodIds.mapNotNull { periodMap[it] }
    }

    fun mergePeriodNames(names: List<String>, separator: String = "/"): String {
        if (names.isEmpty()) return ""
        val regularNums = mutableListOf<Int>()
        val morningNames = mutableListOf<String>()
        val otherSpecialNames = mutableListOf<String>()

        for (name in names) {
            val match = numberedRegex.matchEntire(name)
            if (match != null) {
                match.groupValues[1].toIntOrNull()?.let { regularNums.add(it) }
            } else if (name.contains("早") || name.contains("晨")) {
                if (!morningNames.contains(name)) morningNames.add(name)
            } else if (!otherSpecialNames.contains(name)) {
                otherSpecialNames.add(name)
            }
        }

        val parts = mutableListOf<String>()
        parts.addAll(morningNames)

        val sortedUnique = regularNums.distinct().sorted()
        if (sortedUnique.isNotEmpty()) {
            val ranges = mutableListOf<String>()
            var start = sortedUnique.first()
            var end = start
            for (n in sortedUnique.drop(1)) {
                if (n == end + 1) {
                    end = n
                } else {
                    ranges.add(if (start == end) "$start" else "$start-$end")
                    start = n
                    end = n
                }
            }
            ranges.add(if (start == end) "$start" else "$start-$end")
            parts.add("第${ranges.joinToString(",")}节")
        }
        parts.addAll(otherSpecialNames)
        return parts.joinToString(separator)
    }

    private val countSuffixRegex = Regex("^(.*?)\\s*\\((\\d+)节\\)$")
    private val numberedRangeRegex = Regex("^第([\\d,-]+)节$")
    private val knownSpecialPeriods = setOf("早读", "早操", "晚操", "晚自习", "夜自习", "晨读", "晨跑", "午休", "课间操")

    /**
     * 压缩并规整异常汇总中的 option 文案，将手工标记的平铺节次压缩为简练格式并拆分备注
     */
    fun compactCalendarOption(
        rawOption: String?,
        explicitNote: String? = null
    ): Pair<String, String?> {
        val trimmed = rawOption?.trim().orEmpty()
        val note = explicitNote?.trim()?.takeIf { it.isNotEmpty() }

        if (trimmed.isEmpty()) return "" to note

        // 1. 新版后端格式："<节次/时段> · <备注>"
        val dotIdx = trimmed.indexOf(" · ")
        if (dotIdx > 0) {
            val left = trimmed.substring(0, dotIdx).trim()
            val right = trimmed.substring(dotIdx + 3).trim()
            if (left.isNotEmpty() && right.isNotEmpty()) {
                val compactedLeft = compactPeriodSummaryString(left, requireMultiple = false) ?: left
                return compactedLeft to (note ?: right)
            }
        }

        // 2. 旧版后端手工标记带备注格式："<备注>-<顿号分隔的多节次>"
        val dashIdx = trimmed.indexOf('-')
        if (dashIdx > 0) {
            val left = trimmed.substring(0, dashIdx).trim()
            val right = trimmed.substring(dashIdx + 1).trim()
            val leftLooksLikePeriodRange = left.startsWith("第") && left.lastOrNull()?.isDigit() == true
            if (left.isNotEmpty() && !leftLooksLikePeriodRange) {
                val compactedRight = compactPeriodSummaryString(right, requireMultiple = false)
                if (compactedRight != null) {
                    return compactedRight to (note ?: left)
                }
            }
        }

        // 3. 纯节次列表或带 (N节) 尾缀的节次汇总
        val compacted = compactPeriodSummaryString(trimmed, requireMultiple = false)
        if (compacted != null) {
            return compacted to note
        }

        if (note != null && note == trimmed) {
            return trimmed to null
        }
        return trimmed to note
    }

    fun compactDetailText(raw: String?): String {
        val trimmed = raw?.trim().orEmpty()
        if (trimmed.isEmpty()) return ""
        return trimmed.split(" | ").joinToString(" | ") { segment ->
            val colonIdx = segment.indexOf('：')
            if (colonIdx >= 0) {
                val prefix = segment.substring(0, colonIdx + 1)
                val body = segment.substring(colonIdx + 1)
                val (opt, note) = compactCalendarOption(body)
                when {
                    opt.isNotEmpty() && !note.isNullOrBlank() -> "$prefix$opt · $note"
                    opt.isNotEmpty() -> "$prefix$opt"
                    else -> segment
                }
            } else {
                segment
            }
        }
    }

    private fun compactPeriodSummaryString(raw: String, requireMultiple: Boolean = true): String? {
        val trimmed = raw.trim()
        if (trimmed.isEmpty()) return null

        var inner = trimmed
        var explicitCount: Int? = null
        val suffixMatch = countSuffixRegex.matchEntire(trimmed)
        if (suffixMatch != null) {
            inner = suffixMatch.groupValues[1].trim()
            explicitCount = suffixMatch.groupValues[2].toIntOrNull()
        }

        val tokens = inner.split('、', '/').map { it.trim() }.filter { it.isNotEmpty() }
        if (tokens.isEmpty()) return null
        if (requireMultiple && tokens.size < 2) return null

        val expandedNames = mutableListOf<String>()
        for (token in tokens) {
            val rangeMatch = numberedRangeRegex.matchEntire(token)
            if (rangeMatch != null) {
                val body = rangeMatch.groupValues[1]
                for (seg in body.split(',')) {
                    val bounds = seg.split('-')
                    when (bounds.size) {
                        1 -> {
                            val n = bounds[0].toIntOrNull() ?: return null
                            expandedNames.add("第${n}节")
                        }
                        2 -> {
                            val s = bounds[0].toIntOrNull() ?: return null
                            val e = bounds[1].toIntOrNull() ?: return null
                            if (s > e || e - s > 20) return null
                            for (n in s..e) expandedNames.add("第${n}节")
                        }
                        else -> return null
                    }
                }
            } else if (token in knownSpecialPeriods) {
                expandedNames.add(token)
            } else {
                return null
            }
        }

        if (expandedNames.isEmpty()) return null
        val uniqueNames = expandedNames.distinct()
        val merged = mergePeriodNames(uniqueNames, separator = "、")
        val count = explicitCount ?: uniqueNames.size
        return if (count > 1) "$merged (${count}节)" else merged
    }
}

object SCDateFormatter {
    val yyyyMMdd: DateTimeFormatter = DateTimeFormatter.ofPattern("yyyy-MM-dd", Locale.US)
    val hhmm: DateTimeFormatter = DateTimeFormatter.ofPattern("HH:mm", Locale.US)
    val cnYearMonth: DateTimeFormatter = DateTimeFormatter.ofPattern("yyyy年M月", Locale.CHINA)
    val cnMonthDay: DateTimeFormatter = DateTimeFormatter.ofPattern("M月d日 EEEE", Locale.CHINA)
    private val displayDateTimeFmt: DateTimeFormatter = DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm", Locale.CHINA)

    fun todayString(): String = LocalDate.now().format(yyyyMMdd)

    fun nowRollCallDateTime(): String =
        LocalDateTime.now().format(DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm:ss", Locale.US))

    fun nowIso(): String = Instant.now().toString()

    fun nowHhmm(): String = LocalDateTime.now().format(hhmm)

    /**
     * 将后端返回的日期字符串（纯日期 `2026-11-20` 或 Laravel UTC ISO `2026-11-19T16:00:00.000000Z`）
     * 统一规整为本地时区（东八区）的 `yyyy-MM-dd`，避免直接 take(10) 导致日期提前一天。
     */
    fun normalizeDateKey(raw: String?): String {
        val trimmed = raw?.trim().orEmpty()
        if (trimmed.isEmpty()) return ""
        if (trimmed.contains("T")) {
            val zonedDate = runCatching {
                Instant.parse(trimmed).atZone(ZoneId.systemDefault()).toLocalDate()
            }.recoverCatching {
                OffsetDateTime.parse(trimmed).atZoneSameInstant(ZoneId.systemDefault()).toLocalDate()
            }.getOrNull()
            if (zonedDate != null) {
                return zonedDate.format(yyyyMMdd)
            }
        }
        return trimmed.take(10)
    }

    fun parseLocalDate(raw: String?): LocalDate? {
        val key = normalizeDateKey(raw)
        if (key.isBlank()) return null
        return runCatching { LocalDate.parse(key) }.getOrNull()
    }

    fun displayDateTime(raw: String?): String {
        if (raw.isNullOrBlank()) return "-"
        return runCatching {
            Instant.parse(raw).atZone(ZoneId.systemDefault()).format(displayDateTimeFmt)
        }.getOrElse {
            raw.replace("T", " ").take(16)
        }
    }
}

// MARK: - JsonElement 容错解析扩展（彻底兼容 PHP [] vs {}、MySQL 字符串数字及 0/1 布尔）

fun JsonObject.str(key: String): String? {
    val el = this[key] ?: return null
    if (el is JsonNull) return null
    return (el as? JsonPrimitive)?.contentOrNull
}

fun JsonObject.intVal(key: String, default: Int = 0): Int {
    val p = this[key] as? JsonPrimitive ?: return default
    return p.intOrNull ?: p.contentOrNull?.trim()?.toDoubleOrNull()?.toInt() ?: default
}

fun JsonObject.intOrNull(key: String): Int? {
    val p = this[key] as? JsonPrimitive ?: return null
    if (p is JsonNull) return null
    return p.intOrNull ?: p.contentOrNull?.trim()?.toDoubleOrNull()?.toInt()
}

fun JsonObject.doubleVal(key: String, default: Double = 0.0): Double {
    val p = this[key] as? JsonPrimitive ?: return default
    return p.doubleOrNull ?: p.contentOrNull?.trim()?.toDoubleOrNull() ?: default
}

fun JsonObject.boolVal(key: String, default: Boolean = false): Boolean {
    val p = this[key] as? JsonPrimitive ?: return default
    p.booleanOrNull?.let { return it }
    p.intOrNull?.let { return it != 0 }
    val s = p.contentOrNull?.lowercase() ?: return default
    return when (s) {
        "true", "1", "yes" -> true
        "false", "0", "no" -> false
        else -> default
    }
}

fun JsonObject.objOrNull(key: String): JsonObject? = this[key] as? JsonObject

fun JsonObject.arrOrEmpty(key: String): JsonArray = (this[key] as? JsonArray) ?: JsonArray(emptyList())

fun JsonObject.intList(key: String): List<Int> =
    arrOrEmpty(key).mapNotNull { (it as? JsonPrimitive)?.intOrNull ?: (it as? JsonPrimitive)?.contentOrNull?.toIntOrNull() }

fun JsonObject.strList(key: String): List<String> =
    arrOrEmpty(key).mapNotNull { (it as? JsonPrimitive)?.contentOrNull }

fun JsonElement.asObjectOrNull(): JsonObject? = this as? JsonObject

fun JsonElement.asArrayOrEmpty(): JsonArray = (this as? JsonArray) ?: JsonArray(emptyList())
