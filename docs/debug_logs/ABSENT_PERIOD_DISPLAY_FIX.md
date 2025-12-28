# 旷课节次显示问题修复报告

## ❌ 问题

用户选择"第1节"和"第7节"，但保存后显示"旷课(第1,12节)"。

---

## 🔍 根本原因

### 问题分析

1. **前端显示**：使用 `index + 1`，正确显示"第1、2、3、4、5、6、7节" ✅
2. **前端保存**：保存的是 `period.id`（如1, 2, 3, 4, 6, 7, 12）
3. **后端存储**：`details.periods = [1, 12]`（ID数组）
4. **前端显示**：直接显示ID，显示为"第1,12节" ❌

### 数据流

```
用户选择：第1节、第7节
  ↓
前端获取：periods[0].id=1, periods[6].id=12
  ↓
保存数据：details.periods = [1, 12]  ← 保存的是ID！
  ↓
显示数据：第1,12节  ← 直接显示ID，错误！
```

### 为什么第7节的ID是12？

```
数据库中的节次：
  Period 1: id=1, ordinal=1
  Period 2: id=2, ordinal=2
  ...
  Period 6: id=7, ordinal=7
  Period 7: id=12, ordinal=16  ← 动态创建的节次，ID不连续！
```

---

## ✅ 解决方案

### 核心思路

在保存数据时，不仅保存 `period_ids`，还要保存 `period_numbers`（节次编号）。

### 修改1：保存时添加节次编号

**文件**：`resources/js/components/AttendanceUpdateModal.jsx`  
**行号**：第325-346行

```javascript
const handleInputConfirm = () => {
    if (!pendingAction) return;
    
    // 如果有periods（旷课），需要将ID转换为节次编号
    let enhancedInputData = { ...inputData };
    if (inputData.periods && Array.isArray(inputData.periods)) {
        // 创建ID到索引的映射
        const periodNumbers = inputData.periods.map(periodId => {
            const index = periods.findIndex(p => p.id === periodId);
            return index + 1; // 节次编号 = 索引 + 1
        });
        
        enhancedInputData = {
            ...inputData,
            period_numbers: periodNumbers // ✅ 添加节次编号数组
        };
        
        console.log('[Input Confirm] Period IDs:', inputData.periods);
        console.log('[Input Confirm] Period Numbers:', periodNumbers);
    }
    
    executeBulkUpdate(pendingAction.status, pendingAction.leaveType.id, enhancedInputData);
};
```

### 修改2：显示时优先使用节次编号

**文件**：`resources/js/components/AttendanceUpdateModal.jsx`  
**行号**：第372-391行

```javascript
// Add details text if possible
let detailText = '';
if (details) {
    if (details.time) detailText = `(${details.time})`;
    
    // ✅ 优先使用 period_numbers（节次编号），否则使用 periods（ID）
    if (details.period_numbers) {
        detailText = `(第${details.period_numbers.join(',')}节)`;
    } else if (details.periods) {
        detailText = `(第${details.periods.join(',')}节)`; // 兼容旧数据
    }
    
    if (details.option) {
        const map = {
            'morning_half': '上午',
            'afternoon_half': '下午',
            'full_day': '全天',
            'morning_exercise': '早操',
            'evening_exercise': '晚操'
        };
        detailText = `(${map[details.option] || details.option})`;
    }
}
```

---

## 📊 修复后的数据流

```
用户选择：第1节、第7节
  ↓
前端获取：
  periods[0] = {id: 1, ordinal: 1}  → index=0
  periods[6] = {id: 12, ordinal: 16} → index=6
  ↓
计算节次编号：
  periodNumbers = [0+1, 6+1] = [1, 7]
  ↓
保存数据：
  details = {
    periods: [1, 12],        // ID数组（用于后端逻辑）
    period_numbers: [1, 7]   // 节次编号（用于显示）
  }
  ↓
显示数据：
  优先使用 period_numbers
  显示：第1,7节 ✅
```

---

## 🧪 测试步骤

### 步骤1：强制刷新浏览器

- **Windows/Linux**: `Ctrl + Shift + R`
- **Mac**: `Cmd + Shift + R`

### 步骤2：测试旷课记录

1. 进入考勤标记
2. 选择学生
3. 点击"旷课"按钮
4. 选择"第1节"和"第7节"
5. 点击"确定"
6. 查看控制台日志：
   ```
   [Input Confirm] Period IDs: [1, 12]
   [Input Confirm] Period Numbers: [1, 7]
   ```
7. ✅ 应该显示：**旷课(第1,7节)**

### 步骤3：测试其他节次组合

- 选择"第2、4、6节" → 显示"旷课(第2,4,6节)" ✅
- 选择"第1、3、5、7节" → 显示"旷课(第1,3,5,7节)" ✅

---

## 💡 技术要点

### 为什么需要两个数组？

1. **`periods`（ID数组）**：
   - 用于后端逻辑
   - 用于数据库查询
   - 用于删除旧记录

2. **`period_numbers`（节次编号数组）**：
   - 用于前端显示
   - 始终连续（1, 2, 3, ...）
   - 用户友好

### 向后兼容

```javascript
if (details.period_numbers) {
    // 新数据：使用节次编号
    detailText = `(第${details.period_numbers.join(',')}节)`;
} else if (details.periods) {
    // 旧数据：使用ID（可能不准确）
    detailText = `(第${details.periods.join(',')}节)`;
}
```

---

## 📝 修改总结

### 修改的文件

1. ✅ `resources/js/components/AttendanceUpdateModal.jsx`（第325-346行）
   - 添加节次编号映射逻辑

2. ✅ `resources/js/components/AttendanceUpdateModal.jsx`（第372-391行）
   - 优先使用 `period_numbers` 显示

### 数据格式

**新格式**（推荐）：
```json
{
  "periods": [1, 12],
  "period_numbers": [1, 7]
}
```

**旧格式**（兼容）：
```json
{
  "periods": [1, 12]
}
```

---

## 🎯 预期效果

| 用户选择 | 保存的ID | 保存的编号 | 显示结果 |
|---------|---------|-----------|---------|
| 第1节 | [1] | [1] | 旷课(第1节) ✅ |
| 第1,7节 | [1,12] | [1,7] | 旷课(第1,7节) ✅ |
| 第2,4,6节 | [2,4,7] | [2,4,6] | 旷课(第2,4,6节) ✅ |

---

*完成时间: 2025-12-19 09:05*
*问题: 旷课显示使用ID而不是节次编号*
*解决: 添加period_numbers字段*
*状态: ✅ 已修复*
