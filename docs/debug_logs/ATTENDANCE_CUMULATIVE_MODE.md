# 考勤状态累加模式设计

## 核心理念

**学生的一天 = 基础状态（是否到校）+ 特殊事件（时段异常）**

### 错误理解（当前）
```
标记"病假" → 状态变为"病假"（替换）
```

### 正确理解（应该）
```
学生到校 → 基础状态"出勤"
第2节生理假 → 添加"第2节:生理假"
第5节迟到 → 添加"第5节:迟到"
最终显示：出勤 | 第2节:生理假 | 第5节:迟到
```

## 数据模型

### 方案：双层记录系统

#### 1. 全天基础状态（必须）
- `period_id = NULL`
- `status = 'present'` 或 `'absent'`
- 表示学生是否到校

#### 2. 时段特殊事件（可选）
- `period_id = 1,2,3...`
- `status = 'late'`, `'excused'`, `'early_leave'` 等
- 表示具体时段的异常情况

### 数据示例

**学生A在2025-12-17**：

| id | student_id | date | period_id | status | leave_type | 含义 |
|----|------------|------|-----------|--------|------------|------|
| 1  | 100 | 2025-12-17 | NULL | present | - | 到校了 |
| 2  | 100 | 2025-12-17 | 2 | excused | 生理假 | 第2节生理假 |
| 3  | 100 | 2025-12-17 | 5 | late | - | 第5节迟到 |

**显示结果**：
```
出勤 | 第2节:生理假 | 第5节:迟到
```

**含义**：
- 学生到校了（基础状态：出勤）
- 第2节有生理假
- 第5节迟到了
- 其他时段正常上课

## UI设计

### 标记流程

#### Step 1: 确定基础状态
```
学生今天来了吗？
[✓] 来了（出勤）  [ ] 没来（缺勤）
```

#### Step 2: 标记特殊事件
```
时段选择: [第2节 ▼]
事件类型: [生理假] [迟到] [早退] [旷课]
```

### 显示逻辑

```javascript
// 基础状态（全天）
const baseStatus = fullDayRecord?.status; // 'present' or 'absent'

// 特殊事件（时段）
const events = periodRecords.map(r => ({
    period: r.period_id,
    type: r.status,
    name: r.leave_type?.name
}));

// 显示
<div>
    <Badge color="green">{baseStatus === 'present' ? '出勤' : '缺勤'}</Badge>
    {events.map(e => (
        <Badge>第{e.period}节:{e.name || e.type}</Badge>
    ))}
</div>
```

## 业务规则

### 规则1：必须先有基础状态
- 标记任何时段事件前，必须先确定学生是否到校
- 默认：到校（present）

### 规则2：时段事件是附加的
- 时段事件不影响基础状态
- 可以有多个时段事件

### 规则3：缺勤时不能有时段事件
- 如果基础状态是"缺勤"，不应该有时段记录
- 因为学生没来，不可能有"第2节迟到"

## 实现方案

### 后端逻辑

```php
// AttendanceService::record()
public function record($studentId, $date, $periodId, $status, $options = [])
{
    if ($periodId === null) {
        // 全天基础状态
        if (!in_array($status, ['present', 'absent'])) {
            throw new \Exception('全天状态只能是出勤或缺勤');
        }
    } else {
        // 时段特殊事件
        if (!in_array($status, ['late', 'excused', 'early_leave', 'absent'])) {
            throw new \Exception('时段状态只能是迟到、请假、早退或旷课');
        }
        
        // 确保有基础状态
        $hasBaseStatus = AttendanceRecord::where('student_id', $studentId)
            ->where('date', $date)
            ->whereNull('period_id')
            ->exists();
            
        if (!$hasBaseStatus) {
            // 自动创建默认基础状态
            AttendanceRecord::create([
                'student_id' => $studentId,
                'date' => $date,
                'period_id' => null,
                'status' => 'present', // 默认到校
            ]);
        }
    }
    
    // 创建记录
    return AttendanceRecord::updateOrCreate(
        [
            'student_id' => $studentId,
            'date' => $date,
            'period_id' => $periodId
        ],
        [
            'status' => $status,
            'leave_type_id' => $options['leave_type_id'] ?? null,
            'details' => $options['details'] ?? null,
        ]
    );
}
```

### 前端UI更新

#### 1. 默认创建基础状态

```javascript
// 标记时段事件时，自动确保有基础状态
const executeBulkUpdate = async (status, leaveTypeId, details) => {
    // 如果是时段标记，先确保有全天出勤记录
    if (selectedPeriod !== null) {
        // 先创建全天出勤（如果不存在）
        await axios.post('/attendance/bulk', {
            date: formattedDate,
            period_id: null,
            records: Array.from(selectedStudentIds).map(id => ({
                student_id: id,
                status: 'present'
            }))
        });
    }
    
    // 然后创建时段记录
    await axios.post('/attendance/bulk', {
        date: formattedDate,
        period_id: selectedPeriod,
        records: Array.from(selectedStudentIds).map(id => ({
            student_id: id,
            status: status,
            leave_type_id: leaveTypeId,
            details: details
        }))
    });
};
```

#### 2. 显示更新

```javascript
// 状态显示
<td>
    {/* 基础状态 */}
    {fullDayRecord && (
        <Badge color={fullDayRecord.status === 'present' ? 'green' : 'red'}>
            {fullDayRecord.status === 'present' ? '出勤' : '缺勤'}
        </Badge>
    )}
    
    {/* 时段事件 */}
    {periodRecords.map(record => (
        <Badge key={record.period_id}>
            第{record.period_id}节:{record.leave_type?.name || statusLabels[record.status]}
        </Badge>
    ))}
</td>
```

## 用户体验

### 场景1：正常到校，第2节生理假

**操作**：
1. 选择学生
2. 时段选择"全天"，点击"出勤" → 创建基础状态
3. 时段选择"第2节"，点击"生理假" → 添加时段事件

**显示**：
```
出勤 | 第2节:生理假
```

### 场景2：迟到，第5节早退

**操作**：
1. 时段选择"第1节"，点击"迟到" → 自动创建"出勤"+添加"第1节迟到"
2. 时段选择"第5节"，点击"早退" → 添加"第5节早退"

**显示**：
```
出勤 | 第1节:迟到 | 第5节:早退
```

### 场景3：全天缺勤

**操作**：
1. 时段选择"全天"，点击"旷课"或"缺勤"

**显示**：
```
缺勤（全天）
```

## 状态类型重新定义

### 全天状态（基础）
- `present` - 出勤（到校了）
- `absent` - 缺勤（没来）

### 时段状态（事件）
- `late` - 迟到
- `excused` - 请假（生理假、病假等）
- `early_leave` - 早退
- `absent` - 旷课（来了但某节没上）

## 优势

### 1. 符合实际
✅ 学生来了但有特殊情况
✅ 可以同时记录多个事件
✅ 不会丢失"到校"信息

### 2. 信息完整
✅ 基础状态：是否到校
✅ 详细事件：每节课情况
✅ 一目了然

### 3. 统计准确
✅ 出勤率：基于基础状态
✅ 异常统计：基于时段事件
✅ 数据不冲突

---

*设计时间: 2025-12-17 15:14*
*核心思想: 基础状态 + 特殊事件*
*下一步: 实现自动创建基础状态*
