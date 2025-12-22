# 旷课预填充修复报告

## ❌ 问题：已有旷课节次未被选中

**现象**：
- Student Manager 已有 `[旷课(第1,2节)]` 记录
- 再次点击"旷课"时，第1、2节没有被选中（应该有蓝色背景）

**根本原因**：
后端返回的 `details` 字段是 **JSON 字符串**，而不是对象。前端没有解析就直接访问 `details.periods`，导致无法获取节次数据。

---

## ✅ 解决方案

在访问 `details.periods` 之前，先检查并解析 JSON 字符串。

### 修复前
```javascript
absentRecords.forEach(record => {
    if (record.details && record.details.periods) {
        existingPeriods.push(...record.details.periods);
    }
});
```

**问题**：如果 `record.details` 是字符串 `"{\"periods\":[1,2]}"`，则 `record.details.periods` 是 `undefined`。

### 修复后
```javascript
absentRecords.forEach(record => {
    // Parse details if it's a string
    let details = record.details;
    if (typeof details === 'string') {
        try {
            details = JSON.parse(details);
        } catch (e) {
            console.error('Failed to parse details:', e);
            details = null;
        }
    }
    
    if (details && details.periods) {
        existingPeriods.push(...details.periods);
    }
});
```

**效果**：正确解析 JSON 字符串，获取节次数组 `[1, 2]`。

---

## 📊 数据格式

### 后端返回
```json
{
  "id": 123,
  "status": "absent",
  "details": "{\"periods\":[1,2]}"  ← JSON 字符串
}
```

### 解析后
```javascript
{
  id: 123,
  status: "absent",
  details: {
    periods: [1, 2]  ← 对象
  }
}
```

---

## 🧪 测试步骤

1. 刷新浏览器
2. 选择 Student Manager（已有 `[旷课(第1,2节)]`）
3. 点击"旷课"按钮
4. ✅ 第1、2节应该被选中（蓝色背景）
5. 再选择第3、4节
6. 点击"确定"
7. ✅ 应该显示：`[旷课(第1,2,3,4节)]`

---

## 🔍 调试信息

打开浏览器控制台（F12），点击"旷课"时会看到：

```
[Absent Pre-fill] Student: {...}
[Absent Pre-fill] Absent records: [{...}]
[Absent Pre-fill] Existing periods: [1, 2]
[Absent Pre-fill] Unique periods: [1, 2]
```

如果看到 `Existing periods: []`，说明解析失败。

---

## 📝 修改的文件

1. ✅ `resources/js/components/AttendanceUpdateModal.jsx`
   - 第118-130行：添加 JSON 解析逻辑

---

## 💡 技术要点

### 为什么 details 是字符串？

Laravel 的 Eloquent 模型默认会将 JSON 字段作为字符串返回，除非在模型中定义了 `$casts`：

```php
// 如果没有这个，details 就是字符串
protected $casts = [
    'details' => 'array'
];
```

### 前端处理策略

由于不确定后端是否已经配置了 `$casts`，前端应该**防御性编程**：

```javascript
// 兼容两种情况
let details = record.details;
if (typeof details === 'string') {
    details = JSON.parse(details);
}
```

这样无论后端返回字符串还是对象，都能正确处理。

---

*修复时间: 2025-12-18 16:14*
*问题: details JSON 字符串未解析*
*状态: ✅ 已修复*
