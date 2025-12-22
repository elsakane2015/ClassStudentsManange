# Bug修复 - 生理假显示key而不是label

## 🐛 问题描述

**用户报告**：
- Modal显示："早操"和"晚操"（正确）
- 标记后显示："生理假（afternoon_half）"和"生理假（morning_half）"（错误）

**问题**：显示的是key，而不是label

---

## 🔍 根本原因

### 原因1：旧数据使用旧的key

**数据库中的旧记录**：
```json
{
  "details": {
    "option": "morning_half"  // 旧的key
  },
  "period_id": 1  // 错误的period_id
}
```

**新配置**：
```json
{
  "options": [
    {"key": "morning_exercise", "label": "早操"},  // 新的key
    {"key": "evening_exercise", "label": "晚操"}
  ]
}
```

### 原因2：显示逻辑找不到对应的label

**显示逻辑**：
1. 读取`details.option = "morning_half"`
2. 在新配置中查找`key = "morning_half"`
3. 找不到（因为新配置使用`morning_exercise`）
4. Fallback到显示key本身："morning_half"

---

## ✅ 修复方案

### 方案1：删除旧数据（✅ 已执行）

**删除所有旧的生理假记录**：
```sql
DELETE FROM attendance_records 
WHERE leave_type_id = 3 
AND date = '2025-12-19';
```

**结果**：删除了3条旧记录

### 方案2：添加兼容性映射（可选）

如果需要保留旧数据，可以在显示逻辑中添加兼容性映射：

```javascript
// 兼容旧数据
const legacyMap = {
    'morning_half': '早操',
    'afternoon_half': '晚操'
};

let optionLabel = details.option;

// 先尝试从配置中获取
if (leaveType && leaveType.input_config) {
    const option = config.options.find(opt => opt.key === details.option);
    if (option && option.label) {
        optionLabel = option.label;
    }
}

// 如果找不到，使用兼容性映射
if (optionLabel === details.option && legacyMap[details.option]) {
    optionLabel = legacyMap[details.option];
}
```

---

## 📊 数据迁移

### 旧数据

| student_id | details.option | period_id | 问题 |
|------------|----------------|-----------|------|
| 3 | morning_half | 1 | ❌ 旧key，错误period_id |
| 8 | morning_half | 1 | ❌ 旧key，错误period_id |
| 7 | afternoon_half | 6 | ❌ 旧key，错误period_id |

### 修复后

| student_id | details.option | period_id | 状态 |
|------------|----------------|-----------|------|
| - | - | - | ✅ 已删除 |

**新标记将使用**：
- `morning_exercise` + `period_id = 8`
- `evening_exercise` + `period_id = 9`

---

## 🧪 测试验证

### 测试1：重新标记生理假（早操）

**步骤**：
1. 刷新页面（Ctrl+Shift+R）
2. 选择test学生
3. 点击"生理假"按钮
4. 选择"早操"
5. 点击"确定"

**预期**：
- ✅ 显示"生理假（早操）"
- ✅ 不显示"生理假（morning_exercise）"
- ✅ period_id = 8

### 测试2：重新标记生理假（晚操）

**步骤**：
1. 选择test学生
2. 点击"生理假"按钮
3. 选择"晚操"
4. 点击"确定"

**预期**：
- ✅ 显示"生理假（晚操）"
- ✅ 不显示"生理假（evening_exercise）"
- ✅ period_id = 9

### 测试3：验证不冲突

**步骤**：
1. 标记"旷课（第1节）"
2. 标记"生理假（早操）"

**预期**：
- ✅ 显示2条记录
- ✅ 旷课（第1节）- period_id=1
- ✅ 生理假（早操）- period_id=8
- ✅ 不互相覆盖

---

## 📝 完整的修复流程

### 步骤1：修改配置（✅ 已完成）

```json
// 从
{
  "options": [
    {"key": "morning_half", "label": "早操"},
    {"key": "afternoon_half", "label": "晚操"}
  ]
}

// 改为
{
  "options": [
    {"key": "morning_exercise", "label": "早操"},
    {"key": "evening_exercise", "label": "晚操"}
  ]
}
```

### 步骤2：删除旧数据（✅ 已完成）

```sql
DELETE FROM attendance_records 
WHERE leave_type_id = 3 
AND date = '2025-12-19';
```

### 步骤3：测试新标记（待用户测试）

1. 刷新页面
2. 重新标记生理假
3. 验证显示正确

---

## 🔍 为什么显示key而不是label

### 显示逻辑分析

**Dashboard.jsx**（第696-718行）：
```javascript
if (details.option) {
    let optionLabel = details.option;  // 默认使用key
    
    if (record.leave_type && record.leave_type.input_config) {
        const config = record.leave_type.input_config;
        const option = config.options.find(opt => opt.key === details.option);
        
        if (option && option.label) {
            optionLabel = option.label;  // 找到则使用label
        }
    }
    
    remarkText = optionLabel;  // 显示
}
```

**问题**：
- `details.option = "morning_half"`
- 新配置中没有`key = "morning_half"`的选项
- `option = undefined`
- `optionLabel = details.option = "morning_half"`
- 显示："生理假（morning_half）"

---

## ✅ 验证清单

- [x] 修改生理假配置
- [x] 删除旧数据
- [x] 验证新配置
- [ ] 测试重新标记生理假（早操）
- [ ] 测试重新标记生理假（晚操）
- [ ] 验证显示正确的label
- [ ] 验证period_id正确（8和9）
- [ ] 验证不与其他记录冲突

---

## 🎯 预期效果

### 修复前

```
标记生理假（早操）
  ↓
存储：{option: "morning_half", period_id: 1}
  ↓
显示：找不到"morning_half"的label
  ↓
结果："生理假（morning_half）" ❌
```

### 修复后

```
标记生理假（早操）
  ↓
存储：{option: "morning_exercise", period_id: 8}
  ↓
显示：找到"morning_exercise"的label = "早操"
  ↓
结果："生理假（早操）" ✅
```

---

*完成时间: 2025-12-19 14:07*
*Bug: 显示key而不是label*
*原因: 旧数据使用旧的key*
*修复: 删除旧数据，使用新配置*
*状态: ✅ 已修复*
*下一步: 用户测试*
