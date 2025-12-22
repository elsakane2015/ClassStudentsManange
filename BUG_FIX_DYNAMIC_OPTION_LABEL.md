# Bug修复 - 生理假显示"早操/晚操"而不是"上午/下午"

## 🐛 问题描述

**用户报告**：
- 选择时长：弹窗显示"早操"和"晚操"
- 显示结果：但显示的是"生理假（上午）"和"生理假（下午）"

**用户期望**：直接显示"早操"和"晚操"，不要用硬编码

---

## 🔍 根本原因

### 数据库配置

**leave_types表**中，生理假的配置：

```json
{
  "name": "生理假",
  "slug": "health_leave",
  "input_type": "duration_select",
  "input_config": {
    "options": [
      {
        "key": "morning_half",
        "label": "早操"
      },
      {
        "key": "afternoon_half",
        "label": "晚操"
      }
    ]
  }
}
```

- `key`: 存储在数据库中的值（`morning_half`, `afternoon_half`）
- `label`: 用户看到的文本（"早操", "晚操"）

### 前端硬编码

**文件**：`resources/js/pages/teacher/Dashboard.jsx` (第697-702行)

**问题代码**：
```javascript
const optionMap = {
    'morning_half': '上午',  // ❌ 硬编码
    'afternoon_half': '下午',  // ❌ 硬编码
    'full_day': '全天'
};
remarkText = optionMap[details.option] || details.option;
```

**问题**：
- 前端硬编码了`morning_half` → "上午"的映射
- 忽略了数据库中配置的label（"早操"）

---

## ✅ 修复方案

### 动态获取Label

**文件**：`resources/js/pages/teacher/Dashboard.jsx` (第696-718行)

**修改前**：
```javascript
else if (details.option) {
    const optionMap = {
        'morning_half': '上午',
        'afternoon_half': '下午',
        'full_day': '全天'
    };
    remarkText = optionMap[details.option] || details.option;
}
```

**修改后**：
```javascript
else if (details.option) {
    // 从leave_type的input_config中获取label，而不是硬编码
    let optionLabel = details.option;
    
    if (record.leave_type && record.leave_type.input_config) {
        try {
            const inputConfig = typeof record.leave_type.input_config === 'string' 
                ? JSON.parse(record.leave_type.input_config) 
                : record.leave_type.input_config;
            
            if (inputConfig.options && Array.isArray(inputConfig.options)) {
                const option = inputConfig.options.find(opt => opt.key === details.option);
                if (option && option.label) {
                    optionLabel = option.label;
                }
            }
        } catch (e) {
            console.error('Failed to parse input_config:', e);
        }
    }
    
    remarkText = optionLabel;
}
```

---

## 📊 修复逻辑

### 数据流

```
1. 用户选择"早操"
   ↓
2. 前端发送 {option: "morning_half"}
   ↓
3. 后端存储 details = {option: "morning_half"}
   ↓
4. 前端显示时：
   - 读取 record.leave_type.input_config.options
   - 查找 key === "morning_half" 的选项
   - 获取 label = "早操"
   - 显示"早操"
```

### 示例

**数据库记录**：
```json
{
  "leave_type": {
    "name": "生理假",
    "input_config": {
      "options": [
        {"key": "morning_half", "label": "早操"},
        {"key": "afternoon_half", "label": "晚操"}
      ]
    }
  },
  "details": {
    "option": "morning_half"
  }
}
```

**显示结果**：
- ✅ "早操"（从input_config.options中获取）
- ❌ "上午"（旧的硬编码）

---

## 🎯 优势

### 1. 灵活性

**修改前**：
- 如果要改成"早操" → 需要修改前端代码
- 如果要添加新选项 → 需要修改前端代码

**修改后**：
- 如果要改成"早操" → 只需修改数据库配置
- 如果要添加新选项 → 只需修改数据库配置

### 2. 一致性

**修改前**：
- 选择时显示"早操"
- 结果显示"上午"
- 不一致！

**修改后**：
- 选择时显示"早操"
- 结果显示"早操"
- 一致！

### 3. 可维护性

**修改前**：
- 配置分散在前端和后端
- 需要同步修改

**修改后**：
- 配置集中在数据库
- 单一数据源

---

## 🧪 测试验证

### 测试1：生理假（早操）

1. 给Student Manager请生理假（早操）
2. **验证**：显示"生理假（早操）"，不是"生理假（上午）"

### 测试2：生理假（晚操）

1. 给Student Manager请生理假（晚操）
2. **验证**：显示"生理假（晚操）"，不是"生理假（下午）"

### 测试3：修改配置

1. 在数据库中修改label：
   ```sql
   UPDATE leave_types 
   SET input_config = JSON_SET(input_config, '$.options[0].label', '早上做操')
   WHERE slug = 'health_leave';
   ```
2. 刷新页面
3. **验证**：显示"生理假（早上做操）"

---

## 📝 修改总结

### 修改的文件

1. ✅ `resources/js/pages/teacher/Dashboard.jsx` - 动态获取option label

### 代码变更

| 类型 | 行数 |
|------|------|
| 删除硬编码 | 6行 |
| 新增动态逻辑 | 20行 |
| **总计** | **26行** |

### 影响范围

- ✅ 考勤记录显示
- ✅ 请假记录显示
- ✅ 所有使用duration_select的请假类型

---

## 🔧 扩展性

### 支持其他请假类型

这个修复不仅适用于生理假，也适用于所有使用`duration_select`的请假类型。

**示例**：如果添加新的请假类型"体育课请假"

```json
{
  "name": "体育课请假",
  "input_type": "duration_select",
  "input_config": {
    "options": [
      {"key": "first_half", "label": "上半节"},
      {"key": "second_half", "label": "下半节"}
    ]
  }
}
```

**无需修改前端代码**，自动支持显示"上半节"和"下半节"！

---

## ✅ 验证清单

- [x] 移除硬编码映射
- [x] 实现动态label获取
- [x] 添加错误处理
- [x] 支持JSON字符串和对象
- [ ] 测试生理假（早操）显示
- [ ] 测试生理假（晚操）显示
- [ ] 验证配置修改生效

---

*完成时间: 2025-12-19 13:39*
*Bug: 硬编码option映射*
*修复: 动态从input_config获取label*
*状态: ✅ 已修复*
*优势: 灵活、一致、可维护*
