# 迟到/早退/旷课统计修复报告

## ❌ 问题：迟到和早退没办法在状态里都显示

**现象**：
概览页面中，迟到、早退、旷课的统计卡片不显示或显示为0。

**原因**：
迟到、早退、旷课使用的是直接状态值（`late`, `early_leave`, `absent`），而不是通过 `leave_type_id` 关联到 `leave_types` 表。因此它们不会被包含在 `leaveStats` 查询中。

---

## 📊 状态分类

### 类型1：通过 leave_type_id 的请假
- 病假（status='leave', leave_type_id=病假ID）
- 事假（status='leave', leave_type_id=事假ID）
- 生理假（status='leave', leave_type_id=生理假ID）

**查询方式**：
```php
->where('status', 'leave')
->join('leave_types', 'attendance_records.leave_type_id', '=', 'leave_types.id')
```

### 类型2：直接状态值
- 迟到（status='late'）
- 早退（status='early_leave'）
- 旷课（status='absent'）

**查询方式**：
```php
->selectRaw('status, count(*) as count')
->groupBy('status')
```

---

## ✅ 解决方案

在 `leaveStats` 中添加迟到、早退、旷课的统计数据。

### 修改前
```php
// Convert to key-value format for frontend
$leaveStats = [];
foreach ($leaveStatsRaw as $stat) {
    $leaveStats[$stat->type_name] = $stat->count;
}
```

### 修改后
```php
// Convert to key-value format for frontend
$leaveStats = [];
foreach ($leaveStatsRaw as $stat) {
    $leaveStats[$stat->type_name] = $stat->count;
}

// Add late and early_leave counts from attendanceStats
// These use direct status values instead of leave_type_id
if (isset($attendanceStats['late']) && $attendanceStats['late'] > 0) {
    $leaveStats['迟到'] = $attendanceStats['late'];
}
if (isset($attendanceStats['early_leave']) && $attendanceStats['early_leave'] > 0) {
    $leaveStats['早退'] = $attendanceStats['early_leave'];
}
if (isset($attendanceStats['absent']) && $attendanceStats['absent'] > 0) {
    $leaveStats['旷课'] = $attendanceStats['absent'];
}
```

---

## 📋 返回数据格式

### 修复后的返回格式
```json
{
  "details": {
    "leaves": {
      "病假": 5,
      "事假": 3,
      "生理假": 2,
      "迟到": 4,
      "早退": 1,
      "旷课": 2
    }
  }
}
```

---

## 🎯 前端匹配

前端通过 `leaveTypes` 数组遍历，并从 `stats.details.leaves` 中获取对应的计数：

```javascript
{leaveTypes.map(type => {
    const count = stats.details?.leaves?.[type.name] || 0;
    // 渲染统计卡片
})}
```

现在 `leaveStats` 包含了所有类型的统计数据，前端可以正确显示。

---

## 🧪 测试步骤

1. 刷新概览页面
2. 查看统计卡片
3. ✅ 应该显示所有类型的统计：
   - 今日病假：实际数量
   - 今日事假：实际数量
   - 今日生理假：实际数量
   - 今日迟到：实际数量 ✅
   - 今日早退：实际数量 ✅
   - 今日旷课：实际数量 ✅

---

## 📝 修改的文件

1. ✅ `app/Http/Controllers/Api/AttendanceController.php`
   - 第97-107行：添加迟到、早退、旷课到 `leaveStats`

---

## 💡 技术要点

### 为什么要分开处理？

1. **病假/事假/生理假**：
   - 这些是"请假类型"，需要在 `leave_types` 表中配置
   - 可以有不同的审批流程、输入要求等
   - 使用 `leave_type_id` 关联

2. **迟到/早退/旷课**：
   - 这些是"考勤状态"，不需要配置
   - 直接使用状态值即可
   - 不需要 `leave_type_id`

### 统一到 leaveStats 的好处

- 前端只需要一个数据源
- 简化前端渲染逻辑
- 所有统计卡片使用相同的代码

---

*修复时间: 2025-12-18 12:50*
*问题: 迟到/早退/旷课统计不显示*
*状态: ✅ 已修复*
