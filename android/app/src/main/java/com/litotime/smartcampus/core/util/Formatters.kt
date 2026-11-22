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

        val periodMap = periods.associate { it.id to it.name }
        val names = normalized.mapNotNull { periodMap[it] }
        return mergePeriodNames(names)
    }

    fun mergePeriodNames(names: List<String>): String {
        if (names.isEmpty()) return ""
        val regularNums = mutableListOf<Int>()
        val specialNames = mutableListOf<String>()

        for (name in names) {
            val match = numberedRegex.matchEntire(name)
            if (match != null) {
                match.groupValues[1].toIntOrNull()?.let { regularNums.add(it) }
            } else if (!specialNames.contains(name)) {
                specialNames.add(name)
            }
        }

        val parts = mutableListOf<String>()
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
        parts.addAll(specialNames)
        return parts.joinToString("、")
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
