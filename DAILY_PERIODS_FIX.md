# 考勤规则每日总课时数修复报告

## ❌ 问题

系统设置中"考勤规则"里设置的"每日总课时数"为6节，但在考勤标记中仍然显示7个节次（第1-7节）。

**问题原因**：
`/class-periods` API 返回的是 `ClassPeriod` 表中的所有节次，没有根据考勤规则中的"每日总课时数"设置进行过滤。

---

## ✅ 解决方案

修改 `/class-periods` API，根据考勤规则中的"每日总课时数"设置来限制返回的节次数量。

### 修改的文件

**文件**：`routes/api.php`  
**行号**：第70-87行

### 修改前
```php
Route::get('/class-periods', function(Request $request) {
    // Simple closure for now, should be in controller ideally
    return \App\Models\ClassPeriod::where('school_id', $request->user()->student->school_id ?? 1)
        ->orderBy('ordinal')
        ->get();
});
```

### 修改后
```php
Route::get('/class-periods', function(Request $request) {
    // 获取考勤规则中的每日总课时数
    $dailyPeriods = \App\Models\SystemSetting::where('key', 'daily_periods')->value('value');
    $dailyPeriods = $dailyPeriods ? (int)$dailyPeriods : 8; // 默认8节
    
    // 获取所有节次，按序号排序
    $allPeriods = \App\Models\ClassPeriod::where('school_id', $request->user()->student->school_id ?? 1)
        ->orderBy('ordinal')
        ->get();
    
    // 过滤掉特殊节次（早操、晚操、午休等），只保留常规课程节次
    $regularPeriods = $allPeriods->filter(function($period) {
        return !in_array($period->name, ['早操', '晚操', 'Lunch', '午休']);
    });
    
    // 只返回前N个节次（N = daily_periods）
    return $regularPeriods->take($dailyPeriods)->values();
});
```

---

## 📊 工作流程

### 修改前
```
/class-periods API
  ↓
返回所有节次：[第1节, 第2节, ..., 第7节]
  ↓
考勤标记显示：第1-7节 ❌
```

### 修改后
```
/class-periods API
  ↓
读取考勤规则：daily_periods = 6
  ↓
获取所有节次：[第1节, 第2节, ..., 第7节]
  ↓
过滤特殊节次：[第1节, 第2节, ..., 第7节]（去掉早操、晚操等）
  ↓
只取前6个：[第1节, 第2节, 第3节, 第4节, 第5节, 第6节]
  ↓
考勤标记显示：第1-6节 ✅
```

---

## 🔧 实现逻辑

### 1. 读取配置
```php
$dailyPeriods = \App\Models\SystemSetting::where('key', 'daily_periods')->value('value');
$dailyPeriods = $dailyPeriods ? (int)$dailyPeriods : 8; // 默认8节
```

从 `system_settings` 表中读取 `daily_periods` 配置，如果没有配置则默认为8节。

### 2. 获取所有节次
```php
$allPeriods = \App\Models\ClassPeriod::where('school_id', $request->user()->student->school_id ?? 1)
    ->orderBy('ordinal')
    ->get();
```

获取该学校的所有节次，按序号排序。

### 3. 过滤特殊节次
```php
$regularPeriods = $allPeriods->filter(function($period) {
    return !in_array($period->name, ['早操', '晚操', 'Lunch', '午休']);
});
```

过滤掉特殊节次（早操、晚操、午休等），只保留常规课程节次。

### 4. 限制数量
```php
return $regularPeriods->take($dailyPeriods)->values();
```

只返回前N个节次（N = daily_periods）。

---

## 🧪 测试步骤

### 测试1：设置为6节
1. 进入"系统设置" → "考勤规则"
2. 设置"每日总课时数"为 6
3. 点击"保存设置"
4. 刷新考勤标记页面
5. 点击"旷课"按钮
6. ✅ 应该只显示：第1节、第2节、第3节、第4节、第5节、第6节

### 测试2：设置为4节
1. 进入"系统设置" → "考勤规则"
2. 设置"每日总课时数"为 4
3. 点击"保存设置"
4. 刷新考勤标记页面
5. 点击"旷课"按钮
6. ✅ 应该只显示：第1节、第2节、第3节、第4节

### 测试3：设置为8节
1. 进入"系统设置" → "考勤规则"
2. 设置"每日总课时数"为 8
3. 点击"保存设置"
4. 刷新考勤标记页面
5. 点击"旷课"按钮
6. ✅ 应该显示：第1节、第2节、...、第8节

---

## 💡 技术要点

### 为什么要过滤特殊节次？

系统中可能存在一些特殊节次，如：
- 早操
- 晚操
- 午休（Lunch）

这些不算在"每日总课时数"内，所以需要先过滤掉，然后再限制数量。

### 为什么使用 `take()` 而不是 `limit()`？

- `limit()` 是数据库查询方法，在查询时限制
- `take()` 是集合方法，在内存中限制

因为我们需要先过滤（`filter()`），所以使用 `take()` 更合适。

### 为什么使用 `values()`？

`filter()` 和 `take()` 可能会导致集合的键不连续，使用 `values()` 重新索引，确保返回的是标准的数组格式。

---

## 📝 影响范围

这个修改会影响所有使用 `/class-periods` API 的地方：

1. ✅ **考勤标记** - 旷课节次选择
2. ✅ **考勤标记** - 迟到节次选择
3. ✅ **考勤标记** - 早退节次选择
4. ✅ **其他使用节次选择的地方**

所有这些地方都会根据"每日总课时数"设置来显示节次。

---

## 🎯 预期效果

| 每日总课时数设置 | 考勤标记显示的节次 |
|----------------|------------------|
| 4节 | 第1、2、3、4节 |
| 5节 | 第1、2、3、4、5节 |
| 6节 | 第1、2、3、4、5、6节 |
| 7节 | 第1、2、3、4、5、6、7节 |
| 8节 | 第1、2、3、4、5、6、7、8节 |

---

*完成时间: 2025-12-19 08:10*
*问题: 考勤标记未读取每日总课时数设置*
*状态: ✅ 已修复*
