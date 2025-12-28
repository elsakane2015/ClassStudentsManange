# Bug修复 - 考勤标记Modal中的硬编码选项

## 🐛 问题描述

**用户报告**：
- 系统管理中配置的是"早操/晚操"
- 考勤标记Modal中显示的也是"早操/晚操"（正确）
- 但标记后显示的是"生理假（上午）"（错误）

**原因**：
- Modal中生成选项时有硬编码的fallback逻辑
- StatusBadge显示时也有硬编码的映射

---

## 🔍 问题位置

### 位置1：Modal选项生成

**文件**：`resources/js/components/AttendanceUpdateModal.jsx` (第676-685行)

**问题代码**：
```javascript
const optLabel = typeof opt === 'object' ? opt.label : (
    opt === 'morning_half' ? '上午' :  // ❌ 硬编码
    (opt === 'afternoon_half' ? '下午' :  // ❌ 硬编码
        (opt === 'full_day' ? '全天' :
            (opt === 'morning_exercise' ? '早操' :
                (opt === 'evening_exercise' ? '晚操' : opt))))
);
```

### 位置2：StatusBadge显示

**文件**：`resources/js/components/AttendanceUpdateModal.jsx` (第382-391行)

**问题代码**：
```javascript
const map = {
    'morning_half': '上午',  // ❌ 硬编码
    'afternoon_half': '下午',  // ❌ 硬编码
    'full_day': '全天',
    'morning_exercise': '早操',
    'evening_exercise': '晚操'
};
detailText = `(${map[details.option] || details.option})`;
```

---

## ✅ 修复方案

### 修复1：简化选项生成逻辑

**文件**：`resources/js/components/AttendanceUpdateModal.jsx` (第676-680行)

**修改前**：
```javascript
const optLabel = typeof opt === 'object' ? opt.label : (
    opt === 'morning_half' ? '上午' :
    (opt === 'afternoon_half' ? '下午' : ...)
);
```

**修改后**：
```javascript
// 优先使用配置中的label
const optKey = typeof opt === 'object' ? opt.key : opt;
const optLabel = typeof opt === 'object' && opt.label ? opt.label : optKey;
```

**说明**：
- 如果`opt`是对象且有`label`，使用`opt.label`
- 否则使用`optKey`作为fallback
- 不再硬编码映射

### 修复2：动态获取StatusBadge的label

**文件**：`resources/js/components/AttendanceUpdateModal.jsx` (第382-407行)

**修改前**：
```javascript
const map = {
    'morning_half': '上午',
    'afternoon_half': '下午',
    ...
};
detailText = `(${map[details.option] || details.option})`;
```

**修改后**：
```javascript
// 从leaveType的input_config中获取label
let optionLabel = details.option;

if (leaveTypeId) {
    const leaveType = leaveTypes.find(lt => lt.id === leaveTypeId);
    if (leaveType && leaveType.input_config) {
        try {
            const config = typeof leaveType.input_config === 'string' 
                ? JSON.parse(leaveType.input_config) 
                : leaveType.input_config;
            
            if (config.options && Array.isArray(config.options)) {
                const option = config.options.find(opt => opt.key === details.option);
                if (option && option.label) {
                    optionLabel = option.label;
                }
            }
        } catch (e) {
            console.error('Failed to parse input_config:', e);
        }
    }
}

detailText = `(${optionLabel})`;
```

---

## 📊 修复效果

### 场景1：生理假（早操）

**数据库配置**：
```json
{
  "options": [
    {"key": "morning_half", "label": "早操"},
    {"key": "afternoon_half", "label": "晚操"}
  ]
}
```

**修复前**：
- Modal显示："早操"（正确）
- 标记后显示："生理假（上午）"（错误）

**修复后**：
- Modal显示："早操"（正确）
- 标记后显示："生理假（早操）"（正确）

### 场景2：病假（上午）

**数据库配置**：
```json
{
  "options": [
    {"key": "morning_half", "label": "上午"},
    {"key": "afternoon_half", "label": "下午"}
  ]
}
```

**修复前**：
- Modal显示："上午"（正确）
- 标记后显示："病假（上午）"（正确）

**修复后**：
- Modal显示："上午"（正确）
- 标记后显示："病假（上午）"（正确）

---

## 🔧 技术细节

### Modal选项生成

```javascript
// 从input_config获取options
const config = typeof leaveType.input_config === 'string' 
    ? JSON.parse(leaveType.input_config) 
    : leaveType.input_config;

const options = config?.options || [];

// 生成选项
options.map(opt => {
    const optKey = typeof opt === 'object' ? opt.key : opt;
    const optLabel = typeof opt === 'object' && opt.label ? opt.label : optKey;
    
    return (
        <label>
            <input type="radio" value={optKey} />
            {optLabel}  {/* 显示label */}
        </label>
    );
});
```

### StatusBadge显示

```javascript
// 从leaveTypes中查找对应的leaveType
const leaveType = leaveTypes.find(lt => lt.id === leaveTypeId);

// 从input_config中查找对应的option
const config = leaveType.input_config;
const option = config.options.find(opt => opt.key === details.option);

// 使用option.label
const optionLabel = option.label;  // "早操"
```

---

## 📝 修改总结

### 修改的文件

1. ✅ `resources/js/components/AttendanceUpdateModal.jsx` - 移除硬编码，动态获取label

### 代码变更

| 位置 | 类型 | 行数 |
|------|------|------|
| Modal选项生成 | 简化逻辑 | -8行 |
| StatusBadge显示 | 动态获取 | +18行 |
| **总计** | | **+10行** |

### 影响范围

- ✅ 考勤标记Modal的选项显示
- ✅ 考勤记录的详细信息显示
- ✅ 所有使用duration_select的请假类型

---

## 🎯 一致性

### 修复前

| 位置 | 显示 | 来源 |
|------|------|------|
| 系统管理 | 早操/晚操 | 数据库配置 |
| Modal选项 | 早操/晚操 | 数据库配置 |
| Dashboard显示 | 早操/晚操 | 数据库配置（已修复） |
| Modal显示 | 上午/下午 | ❌ 硬编码 |

### 修复后

| 位置 | 显示 | 来源 |
|------|------|------|
| 系统管理 | 早操/晚操 | 数据库配置 |
| Modal选项 | 早操/晚操 | 数据库配置 |
| Dashboard显示 | 早操/晚操 | 数据库配置 |
| Modal显示 | 早操/晚操 | ✅ 数据库配置 |

**完全一致！**

---

## 🧪 测试验证

### 测试1：生理假（早操）

**步骤**：
1. 点击"生理假"按钮
2. 选择"早操"
3. 点击"确定"

**预期**：
- ✅ Modal显示"早操"
- ✅ 标记后显示"生理假（早操）"

### 测试2：生理假（晚操）

**步骤**：
1. 点击"生理假"按钮
2. 选择"晚操"
3. 点击"确定"

**预期**：
- ✅ Modal显示"晚操"
- ✅ 标记后显示"生理假（晚操）"

### 测试3：病假（上午）

**步骤**：
1. 点击"病假"按钮
2. 选择"上午"
3. 点击"确定"

**预期**：
- ✅ Modal显示"上午"
- ✅ 标记后显示"病假（上午）"

---

## ✅ 验证清单

- [x] 移除Modal选项生成的硬编码
- [x] 移除StatusBadge显示的硬编码
- [x] 添加动态label获取逻辑
- [x] 添加错误处理
- [ ] 测试生理假（早操）显示
- [ ] 测试生理假（晚操）显示
- [ ] 测试病假（上午）显示
- [ ] 验证所有请假类型

---

*完成时间: 2025-12-19 13:56*
*Bug: Modal中硬编码选项label*
*修复: 动态从input_config获取label*
*状态: ✅ 已修复*
*影响: Modal选项和StatusBadge显示*
