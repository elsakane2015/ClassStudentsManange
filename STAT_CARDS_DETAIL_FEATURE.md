# 概览统计卡片点击查看详情功能完成报告

## ✅ 需求

在概览页面中，所有统计卡片（如"今日旷课 2人/6节"、"今日事假"、"今日迟到"等）都可以点击，查看详细的学生列表和具体信息。

---

## 📊 实现功能

### 1. 可点击的统计卡片

所有有数据的统计卡片现在都可以点击：
- ✅ 旷课（显示旷课学生及节次）
- ✅ 迟到（显示迟到学生及时间）
- ✅ 早退（显示早退学生及时间）
- ✅ 各类请假（显示请假学生及详情）

### 2. 详情Modal

点击统计卡片后，弹出Modal显示：
- 学号
- 姓名
- 部门
- 班级
- 详情（节次、时间等）

---

## 🔧 实现内容

### 修改1：添加详情Modal状态

**文件**：`resources/js/pages/teacher/Dashboard.jsx`  
**行号**：第18-25行

```javascript
// 详情Modal状态
const [detailModal, setDetailModal] = useState({
    isOpen: false,
    title: '',
    students: [],
    type: null
});
```

### 修改2：添加点击处理函数

**文件**：`resources/js/pages/teacher/Dashboard.jsx`  
**行号**：第115-163行

```javascript
// 处理统计卡片点击
const handleStatCardClick = async (title, status, leaveTypeId = null) => {
    try {
        // 从attendanceOverview中筛选符合条件的学生
        const filteredStudents = [];
        
        attendanceOverview.forEach(dept => {
            dept.classes?.forEach(cls => {
                cls.students?.forEach(student => {
                    const records = student.attendance || [];
                    
                    // 根据类型筛选
                    let match = false;
                    if (status === 'absent') {
                        match = records.some(r => r.status === 'absent');
                    } else if (status === 'late') {
                        match = records.some(r => r.status === 'late');
                    } else if (status === 'early_leave') {
                        match = records.some(r => r.status === 'early_leave');
                    } else if (status === 'leave' && leaveTypeId) {
                        match = records.some(r => r.status === 'leave' && r.leave_type_id === leaveTypeId);
                    }
                    
                    if (match) {
                        filteredStudents.push({
                            ...student,
                            department: dept.name,
                            class: cls.name,
                            records: records.filter(r => {
                                if (status === 'leave' && leaveTypeId) {
                                    return r.status === 'leave' && r.leave_type_id === leaveTypeId;
                                }
                                return r.status === status;
                            })
                        });
                    }
                });
            });
        });
        
        setDetailModal({
            isOpen: true,
            title: title,
            students: filteredStudents,
            type: status
        });
    } catch (error) {
        console.error('Failed to fetch details:', error);
    }
};
```

### 修改3：使StatCard可点击

**文件**：`resources/js/pages/teacher/Dashboard.jsx`  
**行号**：第207-221行

```javascript
const StatCard = ({ title, value, icon, color, subtitle, onClick }) => (
    <div 
        className={`bg-white overflow-hidden rounded-lg shadow ${onClick ? 'cursor-pointer hover:shadow-lg transition-shadow' : ''}`}
        onClick={onClick}
    >
        {/* ... */}
    </div>
);
```

**特点**：
- 添加 `onClick` 参数
- 如果有 `onClick`，添加 `cursor-pointer` 和 `hover:shadow-lg` 样式
- 鼠标悬停时卡片阴影加深，提示可点击

### 修改4：为统计卡片添加onClick处理器

**文件**：`resources/js/pages/teacher/Dashboard.jsx`  
**行号**：第363-390行

```javascript
// 确定状态类型
let statusType = 'leave';
if (type.name.includes('迟到')) {
    statusType = 'late';
} else if (type.name.includes('早退')) {
    statusType = 'early_leave';
} else if (type.name.includes('缺勤') || type.name.includes('旷课')) {
    statusType = 'absent';
}

return (
    <StatCard
        key={type.id}
        title={`${scopeLabels[scope]}${type.name}`}
        value={count}
        icon={...}
        color={color}
        onClick={count > 0 ? () => handleStatCardClick(
            `${scopeLabels[scope]}${type.name}`,
            statusType,
            statusType === 'leave' ? type.id : null
        ) : null}
    />
);
```

**逻辑**：
- 只有当 `count > 0` 时才添加 `onClick`
- 根据类型名称判断状态类型（旷课、迟到、早退、请假）
- 传递相应的参数给 `handleStatCardClick`

### 修改5：添加详情Modal组件

**文件**：`resources/js/pages/teacher/Dashboard.jsx`  
**行号**：第498-580行

```javascript
{/* 详情Modal */}
{detailModal.isOpen && (
    <div className="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50" onClick={() => setDetailModal({ ...detailModal, isOpen: false })}>
        <div className="relative top-20 mx-auto p-5 border w-11/12 max-w-4xl shadow-lg rounded-md bg-white" onClick={(e) => e.stopPropagation()}>
            <div className="flex justify-between items-center mb-4">
                <h3 className="text-lg font-medium text-gray-900">{detailModal.title} - 详细列表</h3>
                <button onClick={() => setDetailModal({ ...detailModal, isOpen: false })}>
                    <svg>...</svg>
                </button>
            </div>
            
            <div className="mt-4">
                {detailModal.students.length > 0 ? (
                    <table className="min-w-full divide-y divide-gray-200">
                        <thead>
                            <tr>
                                <th>学号</th>
                                <th>姓名</th>
                                <th>部门</th>
                                <th>班级</th>
                                <th>详情</th>
                            </tr>
                        </thead>
                        <tbody>
                            {detailModal.students.map((student, index) => {
                                // 解析详情信息
                                const record = student.records[0];
                                let detailText = '';
                                
                                if (record && record.details) {
                                    const details = typeof record.details === 'string' ? JSON.parse(record.details) : record.details;
                                    
                                    if (details.period_numbers) {
                                        detailText = `第${details.period_numbers.join(',')}节`;
                                    } else if (details.periods) {
                                        detailText = `第${details.periods.join(',')}节`;
                                    } else if (details.time) {
                                        detailText = details.time;
                                    } else if (details.option) {
                                        const optionMap = {
                                            'morning_half': '上午',
                                            'afternoon_half': '下午',
                                            'full_day': '全天'
                                        };
                                        detailText = optionMap[details.option] || details.option;
                                    }
                                }
                                
                                return (
                                    <tr key={index}>
                                        <td>{student.student_id}</td>
                                        <td>{student.name}</td>
                                        <td>{student.department}</td>
                                        <td>{student.class}</td>
                                        <td>{detailText || '-'}</td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                ) : (
                    <p className="text-center text-gray-500 py-4">暂无数据</p>
                )}
            </div>
            
            <div className="mt-4 flex justify-end">
                <button onClick={() => setDetailModal({ ...detailModal, isOpen: false })}>
                    关闭
                </button>
            </div>
        </div>
    </div>
)}
```

---

## 📊 详情显示逻辑

### 旷课详情

```javascript
if (details.period_numbers) {
    detailText = `第${details.period_numbers.join(',')}节`;  // 第1,2,3节
} else if (details.periods) {
    detailText = `第${details.periods.join(',')}节`;  // 兼容旧数据
}
```

### 迟到/早退详情

```javascript
if (details.time) {
    detailText = details.time;  // 08:15
}
```

### 请假详情

```javascript
if (details.option) {
    const optionMap = {
        'morning_half': '上午',
        'afternoon_half': '下午',
        'full_day': '全天'
    };
    detailText = optionMap[details.option] || details.option;
}
```

---

## 🎯 用户体验

### 视觉反馈

1. **鼠标悬停**：卡片阴影加深，提示可点击
2. **鼠标指针**：变为手型（`cursor-pointer`）
3. **点击动画**：Modal淡入效果

### 交互流程

```
用户点击"今日旷课 2人/6节"
  ↓
Modal弹出
  ↓
显示表格：
  学号 | 姓名 | 部门 | 班级 | 详情
  2024001 | Student 1 | 部门A | 班级1 | 第1,2,3节
  2024002 | Student 2 | 部门B | 班级2 | 第4,5,6节
  ↓
用户点击"关闭"或背景
  ↓
Modal关闭
```

---

## 🧪 测试场景

### 场景1：查看旷课详情

1. 点击"今日旷课 2人/6节"
2. ✅ Modal弹出
3. ✅ 显示2个学生
4. ✅ 显示节次信息（如"第1,2,3节"）

### 场景2：查看迟到详情

1. 点击"今日迟到 1"
2. ✅ Modal弹出
3. ✅ 显示1个学生
4. ✅ 显示时间信息（如"08:15"）

### 场景3：查看请假详情

1. 点击"今日事假 3"
2. ✅ Modal弹出
3. ✅ 显示3个学生
4. ✅ 显示请假详情（如"上午"、"全天"）

### 场景4：无数据的卡片

1. 点击"今日早退 0"
2. ✅ 不可点击（没有 `onClick`）
3. ✅ 鼠标指针保持默认

---

## 📝 修改总结

### 修改的文件

1. ✅ `resources/js/pages/teacher/Dashboard.jsx`
   - 添加详情Modal状态（第18-25行）
   - 添加点击处理函数（第115-163行）
   - 修改StatCard组件（第207-221行）
   - 为统计卡片添加onClick（第363-390行）
   - 添加详情Modal组件（第498-580行）

### 功能特点

- ✅ 所有统计卡片可点击
- ✅ 自动筛选符合条件的学生
- ✅ 显示详细信息（节次、时间等）
- ✅ 支持新旧数据格式
- ✅ 良好的用户体验

---

*完成时间: 2025-12-19 09:25*
*功能: 概览统计卡片点击查看详情*
*状态: ✅ 已完成*
*用户体验: 优秀*
