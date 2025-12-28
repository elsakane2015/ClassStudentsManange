# 概览统计修复报告

## ❌ 问题：病假/事假等没有统计到

**现象**：
概览页面中显示的所有请假类型统计都是0：
- 今日事假：0
- 今日生理假：0
- 今日旷课：0
- 今日迟到：0
- 今日test：0
- 今日早退：0

**原因**：
后端返回的 `leaveStats` 数据格式与前端期望的格式不匹配。

### 后端返回（修复前）
```json
{
  "details": {
    "leaves": [
      {"type_name": "病假", "type_id": 1, "count": 5},
      {"type_name": "事假", "type_id": 2, "count": 3}
    ]
  }
}
```

### 前端期望
```javascript
const count = stats.details?.leaves?.[type.name] || 0;
// 期望 stats.details.leaves 是一个对象：
// { "病假": 5, "事假": 3 }
```

---

## ✅ 解决方案

修改后端 `AttendanceController::stats()` 方法，将 `leaveStats` 从数组转换为对象。

### 修改前
```php
$leaveStats = $attendanceQuery->clone()
     ->where('status', 'excused')
     ->join('leave_types', 'attendance_records.leave_type_id', '=', 'leave_types.id')
     ->selectRaw('leave_types.name as type_name, leave_types.id as type_id, count(*) as count')
     ->groupBy('leave_types.id', 'leave_types.name')
     ->get()
     ->toArray();
```

### 修改后
```php
$leaveStatsRaw = $attendanceQuery->clone()
     ->where('status', 'excused')
     ->join('leave_types', 'attendance_records.leave_type_id', '=', 'leave_types.id')
     ->selectRaw('leave_types.name as type_name, leave_types.id as type_id, count(*) as count')
     ->groupBy('leave_types.id', 'leave_types.name')
     ->get();
     
// Convert to key-value format for frontend
$leaveStats = [];
foreach ($leaveStatsRaw as $stat) {
    $leaveStats[$stat->type_name] = $stat->count;
}
```

### 返回格式（修复后）
```json
{
  "details": {
    "leaves": {
      "病假": 5,
      "事假": 3,
      "生理假": 2,
      "迟到": 1
    }
  }
}
```

---

## 🧪 测试步骤

1. 刷新概览页面
2. 查看统计卡片
3. ✅ 应该显示正确的数字：
   - 今日病假：实际数量
   - 今日事假：实际数量
   - 今日生理假：实际数量
   - 等等

---

## 📝 修改的文件

1. ✅ `app/Http/Controllers/Api/AttendanceController.php`
   - 修改 `stats()` 方法
   - 将 `leaveStats` 转换为键值对格式

---

*修复时间: 2025-12-18 12:38*
*问题: 概览统计显示0*
*状态: ✅ 已修复*
