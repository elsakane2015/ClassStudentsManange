# 概览详情Modal时间范围支持计划

## 📋 需求

当用户切换到"本周"、"本月"或"学期"视图时，点击统计卡片应该显示相应时间范围的学生详细列表，而不是只显示今日数据。

---

## 🎯 当前状态

### 问题

1. **只显示今日数据**：无论scope是什么（today/week/month/semester），详情Modal只显示今日的学生列表
2. **数据来源限制**：`attendanceOverview` API只返回单个日期的数据

### 原因

```javascript
// Dashboard.jsx 第87-90行
const [statsRes, overviewRes] = await Promise.all([
    axios.get('/attendance/stats', { params: { scope } }),  // ← 支持scope
    axios.get('/attendance/overview')  // ← 不支持scope，总是返回今日数据
]);
```

---

## ✅ 解决方案

### 方案1：创建新的API端点（推荐）

**后端**：创建 `/attendance/details` API

```php
// routes/api.php
Route::get('/attendance/details', [AttendanceController::class, 'details']);

// AttendanceController.php
public function details(Request $request) {
    $scope = $request->input('scope', 'today');
    $status = $request->input('status');  // absent, late, early_leave, leave
    $leaveTypeId = $request->input('leave_type_id');
    
    // 根据scope计算日期范围
    $dateRange = $this->getDateRange($scope);
    
    // 查询符合条件的学生
    $students = Student::whereHas('attendanceRecords', function($q) use ($dateRange, $status, $leaveTypeId) {
        $q->whereBetween('date', [$dateRange['start'], $dateRange['end']])
          ->where('status', $status);
        if ($leaveTypeId) {
            $q->where('leave_type_id', $leaveTypeId);
        }
    })
    ->with(['user', 'class.department', 'attendanceRecords' => function($q) use ($dateRange, $status) {
        $q->whereBetween('date', [$dateRange['start'], $dateRange['end']])
          ->where('status', $status);
    }])
    ->get();
    
    // 格式化数据
    return response()->json($students->map(function($student) {
        return [
            'student_no' => $student->student_no,
            'name' => $student->user->name,
            'department' => $student->class->department->name,
            'class' => $student->class->name,
            'records' => $student->attendanceRecords,
            'count' => $student->attendanceRecords->count()
        ];
    }));
}
```

**前端**：修改 `handleStatCardClick`

```javascript
const handleStatCardClick = async (title, status, leaveTypeId = null) => {
    try {
        setDetailModal({
            isOpen: true,
            title: `${title} - 加载中...`,
            students: [],
            type: status
        });
        
        const response = await axios.get('/attendance/details', {
            params: {
                scope: scope,  // ← 传递当前scope
                status: status,
                leave_type_id: leaveTypeId
            }
        });
        
        setDetailModal({
            isOpen: true,
            title: title,
            students: response.data,
            type: status
        });
    } catch (error) {
        console.error('Failed to fetch details:', error);
    }
};
```

### 方案2：临时方案（快速实现）

在Modal中添加提示，告知用户当前只支持查看今日数据：

```javascript
{/* Modal标题 */}
<h3>
    {detailModal.title} - 详细列表
    {scope !== 'today' && (
        <span className="text-sm text-gray-500 ml-2">
            （仅显示今日数据）
        </span>
    )}
</h3>
```

---

## 📊 实现步骤

### 步骤1：后端API

1. ✅ 在 `routes/api.php` 添加路由
2. ✅ 在 `AttendanceController.php` 添加 `details` 方法
3. ✅ 实现日期范围计算逻辑
4. ✅ 实现学生查询和数据格式化

### 步骤2：前端修改

1. ✅ 修改 `handleStatCardClick` 函数
2. ✅ 调用新的API端点
3. ✅ 处理加载状态
4. ✅ 显示错误信息

### 步骤3：测试

1. ✅ 测试"今日"视图
2. ✅ 测试"本周"视图
3. ✅ 测试"本月"视图
4. ✅ 测试"学期"视图

---

## 💡 技术要点

### 日期范围计算

```php
private function getDateRange($scope) {
    $now = now();
    
    switch ($scope) {
        case 'today':
            return [
                'start' => $now->format('Y-m-d'),
                'end' => $now->format('Y-m-d')
            ];
        case 'week':
            return [
                'start' => $now->startOfWeek()->format('Y-m-d'),
                'end' => $now->endOfWeek()->format('Y-m-d')
            ];
        case 'month':
            return [
                'start' => $now->startOfMonth()->format('Y-m-d'),
                'end' => $now->endOfMonth()->format('Y-m-d')
            ];
        case 'semester':
            // 获取当前学期
            $semester = Semester::current()->first();
            return [
                'start' => $semester->start_date,
                'end' => $semester->end_date
            ];
    }
}
```

### 数据聚合

对于周/月/学期视图，需要聚合多天的数据：

```php
// 统计每个学生的缺勤次数
$students = Student::whereHas('attendanceRecords', function($q) use ($dateRange, $status) {
    $q->whereBetween('date', [$dateRange['start'], $dateRange['end']])
      ->where('status', $status);
})
->withCount(['attendanceRecords as absence_count' => function($q) use ($dateRange, $status) {
    $q->whereBetween('date', [$dateRange['start'], $dateRange['end']])
      ->where('status', $status);
}])
->get();
```

---

## 🎯 预期效果

### 今日视图

点击"今日旷课 2人/6节"：
```
学号      | 姓名        | 部门   | 班级   | 详情
2024001  | Student 1  | 部门A  | 班级1  | 第1,2,3节
2024002  | Student 2  | 部门B  | 班级2  | 第4,5,6节
```

### 本周视图

点击"本周旷课 5人"：
```
学号      | 姓名        | 部门   | 班级   | 缺勤次数
2024001  | Student 1  | 部门A  | 班级1  | 3次
2024002  | Student 2  | 部门B  | 班级2  | 2次
2024003  | Student 3  | 部门C  | 班级3  | 1次
...
```

---

*状态: 📋 计划中*
*优先级: 中*
*预计工作量: 2-3小时*
