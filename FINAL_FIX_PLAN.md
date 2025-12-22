# 考勤系统最终修复方案

## 需要修复的问题

### 1. 硬编码问题 ❌
**当前**：按钮显示硬编码文本
```jsx
<button>出勤</button>
<button>病假</button>
```

**应该**：从leaveTypes动态生成
```jsx
{leaveTypes.map(lt => (
    <button key={lt.id}>{lt.name}</button>
))}
```

### 2. 未标记显示 ❌
**当前**：显示"未标记"
**应该**：默认显示"出勤"

### 3. 显示bug ❌
**当前**：显示原始值`half_day`
**应该**：显示翻译后的文本"上午"/"下午"

### 4. 时长选项 ❌
**当前**：显示`1 period`, `half_day`
**应该**：显示"一节课"、"上午半天"、"下午半天"、"全天"

### 5. 智能合并 ❌
**需求**：上午病假 + 下午病假 = 全天病假

## 修复步骤

### Step 1: 更新LeaveTypeSeeder
- 移除"一节课"选项
- 只保留：上午、下午、全天

### Step 2: 更新StatusBadge的optionMap
- 添加`morning_half`和`afternoon_half`

### Step 3: 更新未标记显示
- 将"未标记"改为"出勤"

### Step 4: 实现智能合并
- 在AttendanceService中检测
- 如果同一天有上午+下午同类型请假
- 自动合并为全天

### Step 5: 移除硬编码按钮
- 动态从leaveTypes生成
- 添加"出勤"按钮

## 实现优先级

1. ✅ 修复显示bug（optionMap）
2. ✅ 修复未标记显示
3. ✅ 更新时长选项
4. ⏳ 实现智能合并
5. ⏳ 移除硬编码按钮
