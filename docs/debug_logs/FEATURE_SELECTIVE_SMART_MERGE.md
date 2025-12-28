# 功能优化 - 选择性智能合并

## 📋 需求

用户要求：

1. **生理假（早操/晚操）**：不要合并，保留两条记录
2. **病假/事假（上午/下午）**：需要合并成全天

**原因**：
- 生理假的早操和晚操是不同的活动，应该分开记录
- 病假和事假的上午和下午是连续的，应该合并为全天

---

## ✅ 实现方案

### 选择性合并逻辑

**文件**：`app/Services/AttendanceService.php` (第98-164行)

**核心逻辑**：
```php
// 智能合并：如果同一天有上午和下午的同类型请假，合并为全天
// 只对病假和事假进行合并，生理假等其他类型不合并
if ($periodId !== null && isset($options['leave_type_id'])) {
    // 获取请假类型，检查是否需要合并
    $leaveType = \App\Models\LeaveType::find($options['leave_type_id']);
    
    // 只对病假(sick_leave)和事假(personal_leave)进行合并
    if ($leaveType && in_array($leaveType->slug, ['sick_leave', 'personal_leave'])) {
        // ... 执行合并逻辑
    }
}
```

---

## 📊 行为对比

### 场景1：生理假（早操 + 晚操）

**操作**：
1. 请生理假（早操）
2. 请生理假（晚操）

**结果**：
- ✅ 保留两条记录
- ✅ 显示"生理假（早操）"和"生理假（晚操）"
- ❌ 不合并

**原因**：`health_leave`不在合并列表中

### 场景2：病假（上午 + 下午）

**操作**：
1. 请病假（上午）
2. 请病假（下午）

**结果**：
- ✅ 合并为一条记录
- ✅ 显示"病假（全天）"
- ✅ 删除上午和下午的记录

**原因**：`sick_leave`在合并列表中

### 场景3：事假（上午 + 下午）

**操作**：
1. 请事假（上午）
2. 请事假（下午）

**结果**：
- ✅ 合并为一条记录
- ✅ 显示"事假（全天）"
- ✅ 删除上午和下午的记录

**原因**：`personal_leave`在合并列表中

---

## 🔧 技术细节

### 合并条件

合并需要同时满足以下条件：

1. **请假类型匹配**：
   ```php
   in_array($leaveType->slug, ['sick_leave', 'personal_leave'])
   ```

2. **上午和下午都有记录**：
   ```php
   $morning && $afternoon
   ```

3. **Details匹配**：
   ```php
   $morningDetails['option'] === 'morning_half' &&
   $afternoonDetails['option'] === 'afternoon_half'
   ```

### 合并流程

```
1. 检测到添加病假（下午）
   ↓
2. 查询是否已有病假（上午）
   ↓
3. 如果有，检查details
   ↓
4. 删除上午和下午记录
   ↓
5. 创建全天记录
   details: {option: 'full_day'}
   source_type: 'auto_merge'
```

### 不合并的情况

以下情况**不会**合并：

1. **请假类型不在列表中**（如生理假）
2. **只有上午或下午**（不是两个都有）
3. **Details不匹配**（如一个是morning_half，另一个不是afternoon_half）

---

## 🎯 支持的请假类型

### 需要合并的类型

| 类型 | Slug | ID | 合并规则 |
|------|------|-----|---------|
| 病假 | `sick_leave` | 1 | 上午+下午 → 全天 |
| 事假 | `personal_leave` | 2 | 上午+下午 → 全天 |

### 不需要合并的类型

| 类型 | Slug | ID | 保留规则 |
|------|------|-----|---------|
| 生理假 | `health_leave` | 3 | 早操+晚操 → 两条记录 |
| 旷课 | `absent` | 4 | 不合并 |
| 迟到 | `late` | 5 | 不合并 |

---

## 🧪 测试验证

### 测试1：生理假不合并

**步骤**：
1. 给Student Manager请生理假（早操）
2. 给Student Manager请生理假（晚操）

**预期**：
- ✅ 显示2条记录
- ✅ "生理假（早操）"
- ✅ "生理假（晚操）"

### 测试2：病假合并

**步骤**：
1. 给Student 1请病假（上午）
2. 给Student 1请病假（下午）

**预期**：
- ✅ 显示1条记录
- ✅ "病假（全天）"
- ❌ 不显示上午和下午

### 测试3：事假合并

**步骤**：
1. 给Student 2请事假（上午）
2. 给Student 2请事假（下午）

**预期**：
- ✅ 显示1条记录
- ✅ "事假（全天）"
- ❌ 不显示上午和下午

### 测试4：混合情况

**步骤**：
1. 给Student Manager请生理假（早操）
2. 给Student Manager请病假（上午）
3. 给Student Manager请病假（下午）

**预期**：
- ✅ 显示2条记录
- ✅ "生理假（早操）"（不合并）
- ✅ "病假（全天）"（合并了上午和下午）

---

## 📝 修改总结

### 修改的文件

1. ✅ `app/Services/AttendanceService.php` - 添加选择性合并逻辑

### 代码变更

| 类型 | 行数 |
|------|------|
| 修改逻辑 | 4行 |
| 新增检查 | 3行 |
| **总计** | **7行** |

### 关键修改

**修改前**：
```php
// 用户要求：不要合并，保留所有记录
/* ... 合并逻辑被注释 ... */
```

**修改后**：
```php
// 只对病假和事假进行合并，生理假等其他类型不合并
if ($periodId !== null && isset($options['leave_type_id'])) {
    $leaveType = \App\Models\LeaveType::find($options['leave_type_id']);
    
    // 只对病假(sick_leave)和事假(personal_leave)进行合并
    if ($leaveType && in_array($leaveType->slug, ['sick_leave', 'personal_leave'])) {
        // ... 执行合并逻辑
    }
}
```

---

## 🔍 扩展性

### 添加新的合并类型

如果将来需要对其他请假类型进行合并，只需修改一行代码：

```php
// 添加新的请假类型到合并列表
if ($leaveType && in_array($leaveType->slug, [
    'sick_leave', 
    'personal_leave',
    'new_leave_type'  // 新增
])) {
    // ... 合并逻辑
}
```

### 配置化

未来可以将合并列表移到配置文件或数据库中：

```php
// config/attendance.php
return [
    'merge_leave_types' => ['sick_leave', 'personal_leave'],
];

// 使用
if ($leaveType && in_array($leaveType->slug, config('attendance.merge_leave_types'))) {
    // ... 合并逻辑
}
```

---

## ✅ 验证清单

- [x] 添加请假类型检查
- [x] 只对病假和事假合并
- [x] 生理假不合并
- [ ] 测试生理假（早操+晚操）不合并
- [ ] 测试病假（上午+下午）合并
- [ ] 测试事假（上午+下午）合并
- [ ] 验证日志输出正确

---

*完成时间: 2025-12-19 13:48*
*功能: 选择性智能合并*
*规则: 病假/事假合并，生理假不合并*
*状态: ✅ 已实现*
*扩展性: 易于添加新类型*
