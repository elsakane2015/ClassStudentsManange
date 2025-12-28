# 实现计划 - 学生端仪表盘改进

## 📋 需求

### 1. 统计数据动态化
- 当前：硬编码了4个统计卡片（正常出勤、缺勤、迟到、请假/早退）
- 需求：根据请假类型动态生成统计卡片

### 2. 月/周视图标记动态化
- 当前：硬编码了请假类型映射（病假、事假）
- 需求：从API获取请假类型，动态显示

### 3. 班级学生管理员功能
- 需求：学生被指定为班级管理员时，可以标记班级考勤
- 界面：添加"管理班级考勤"按钮，弹出考勤标记界面
- 参考：班主任端的标记学生考勤界面

---

## 🎯 实现方案

### 任务1：动态统计卡片

#### 1.1 获取请假类型配置
```javascript
const [leaveTypes, setLeaveTypes] = useState([]);

useEffect(() => {
    const fetchLeaveTypes = async () => {
        const res = await axios.get('/leave-types');
        setLeaveTypes(res.data);
    };
    fetchLeaveTypes();
}, []);
```

#### 1.2 动态计算统计
```javascript
// 当前硬编码
const newStats = { present: 0, absent: 0, late: 0, excused: 0 };

// 改为动态
const newStats = {};
leaveTypes.forEach(type => {
    newStats[type.slug] = 0;
});
newStats['present'] = 0; // 正常出勤

attendance.forEach(r => {
    if (newStats[r.status] !== undefined) {
        newStats[r.status]++;
    }
});
```

#### 1.3 动态渲染卡片
```javascript
// 当前硬编码4个卡片
<div>正常出勤: {stats.present}</div>
<div>缺勤: {stats.absent}</div>
...

// 改为动态
{Object.entries(stats).map(([key, value]) => {
    const type = leaveTypes.find(t => t.slug === key);
    return (
        <div key={key}>
            <div>{type?.name || '正常出勤'}</div>
            <div>{value}</div>
        </div>
    );
})}
```

---

### 任务2：动态请假类型标记

#### 2.1 日历事件标题
```javascript
// 当前硬编码
const typeMap = { 'sick': '病假', 'personal': '事假' };
title: `${typeMap[leave.type] || leave.type}`

// 改为动态
const leaveType = leaveTypes.find(t => t.slug === leave.type);
title: `${leaveType?.name || leave.type}`
```

#### 2.2 图例动态化
```javascript
// 当前硬编码
<div>正常 (自动)</div>
<div>缺勤</div>
<div>迟到</div>
<div>请假</div>

// 改为动态
{leaveTypes.map(type => (
    <div key={type.id}>
        <span className={`w-3 h-3 rounded-full bg-${type.color}-500`}></span>
        {type.name}
    </div>
))}
```

---

### 任务3：班级学生管理员功能

#### 3.1 数据库设计

**添加字段到students表**：
```sql
ALTER TABLE students 
ADD COLUMN is_class_admin BOOLEAN DEFAULT FALSE 
COMMENT '是否为班级学生管理员';
```

**或者创建新表**：
```sql
CREATE TABLE class_admins (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    class_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id),
    FOREIGN KEY (student_id) REFERENCES students(id),
    UNIQUE KEY (class_id, student_id)
);
```

#### 3.2 前端界面

**添加按钮**：
```javascript
<div className="flex justify-between items-center mb-4">
    <h3>我的记录</h3>
    {isClassAdmin && (
        <button onClick={() => setShowAttendanceModal(true)}>
            管理班级考勤
        </button>
    )}
</div>
```

**考勤标记模态框**：
```javascript
{showAttendanceModal && (
    <AttendanceModal
        classId={user.student.class_id}
        onClose={() => setShowAttendanceModal(false)}
    />
)}
```

#### 3.3 后端API

**检查权限**：
```php
public function markAttendance(Request $request) {
    $user = $request->user();
    
    // 检查是否为班级管理员
    if (!$user->student->is_class_admin) {
        return response()->json(['error' => 'Unauthorized'], 403);
    }
    
    // 标记考勤
    // ...
}
```

---

## 📝 实现步骤

### 阶段1：动态统计和标记（优先）

1. ✅ 修改`StudentDashboard.jsx`
   - 添加`leaveTypes`状态
   - 从API获取请假类型
   - 动态计算统计
   - 动态渲染卡片
   - 动态显示日历标记
   - 动态显示图例

2. ✅ 测试
   - 验证统计数据正确
   - 验证日历标记正确
   - 验证图例显示正确

### 阶段2：班级学生管理员功能

1. ⏳ 数据库迁移
   - 添加`is_class_admin`字段到`students`表
   - 或创建`class_admins`表

2. ⏳ 后端API
   - 添加权限检查
   - 添加标记考勤接口

3. ⏳ 前端界面
   - 添加"管理班级考勤"按钮
   - 创建考勤标记模态框
   - 复用班主任端的组件

4. ⏳ 测试
   - 测试权限检查
   - 测试考勤标记
   - 测试界面交互

---

## ⚠️ 注意事项

### 颜色映射

**问题**：不同请假类型需要不同颜色

**方案1**：在数据库中存储颜色
```sql
ALTER TABLE leave_types ADD COLUMN color VARCHAR(20) DEFAULT 'blue';
```

**方案2**：前端定义颜色映射
```javascript
const colorMap = {
    'sick_leave': 'purple',
    'personal_leave': 'blue',
    'health_leave': 'pink',
    'absent': 'red',
    'late': 'yellow',
    'present': 'green'
};
```

### 性能优化

**问题**：每次渲染都要查找请假类型

**优化**：创建映射对象
```javascript
const leaveTypeMap = useMemo(() => {
    const map = {};
    leaveTypes.forEach(type => {
        map[type.slug] = type;
    });
    return map;
}, [leaveTypes]);

// 使用
const type = leaveTypeMap[leave.type];
```

---

## 🧪 测试计划

### 测试1：统计数据
- 创建不同类型的考勤记录
- 验证统计卡片显示正确数量

### 测试2：日历标记
- 创建不同类型的请假申请
- 验证日历上显示正确的标签和颜色

### 测试3：班级管理员
- 设置学生为班级管理员
- 验证"管理班级考勤"按钮显示
- 验证可以标记考勤

---

*创建时间: 2025-12-19 16:38*
*任务: 学生端仪表盘改进*
*状态: 📋 计划中*
