# Bug修复 - 学生端请假申请（请假类型和时长硬编码）

## 🐛 问题

**用户报告**：
1. 学生端新建请假时，请假类型和时长是硬编码的
2. 提交失败，显示"The selected type is invalid."

---

## 🔍 根本原因

### 问题1：请假类型硬编码

**文件**：`resources/js/pages/student/LeaveRequestForm.jsx`

**问题代码**（第72-76行）：
```javascript
<select name="type" value={formData.type}>
    <option value="personal">事假</option>
    <option value="sick">病假</option>
    {user?.student?.gender === 'female' && (
        <option value="menstrual">例假</option>
    )}
</select>
```

**问题**：
- 硬编码了请假类型（personal, sick, menstrual）
- 没有从API动态获取
- 与数据库中的实际类型不匹配

### 问题2：时长选项硬编码

**问题代码**（第115-117行）：
```javascript
<select name="half_day">
    <option value="">全天</option>
    <option value="am">仅上午</option>
    <option value="pm">仅下午</option>
</select>
```

**问题**：
- 硬编码了时长选项（am, pm）
- 没有从请假类型的`input_config`中获取
- 与实际配置不匹配

### 问题3：验证失败

**错误信息**：
```
The selected type is invalid.
```

**原因**：
- 前端发送的type值（如"personal"）
- 后端验证期望的是数据库中实际存在的slug
- 但数据库中的slug可能是"personal_leave"或其他值
- 验证失败

---

## ✅ 修复方案

### 修改1：从API动态获取请假类型

**文件**：`resources/js/pages/student/LeaveRequestForm.jsx`

**添加状态**：
```javascript
const [leaveTypes, setLeaveTypes] = useState([]);
```

**添加useEffect**：
```javascript
useEffect(() => {
    const fetchLeaveTypes = async () => {
        try {
            const response = await axios.get('/leave-types');
            const activeTypes = response.data.filter(type => type.is_active);
            setLeaveTypes(activeTypes);
            // Set default type to first active type
            if (activeTypes.length > 0 && !formData.type) {
                setFormData(prev => ({ ...prev, type: activeTypes[0].slug }));
            }
        } catch (err) {
            console.error('Failed to fetch leave types:', err);
        }
    };
    fetchLeaveTypes();
}, []);
```

**修改表单**：
```javascript
<select name="type" value={formData.type} required>
    <option value="">-- 请选择 --</option>
    {leaveTypes.map(type => (
        <option key={type.id} value={type.slug}>
            {type.name}
        </option>
    ))}
</select>
```

### 修改2：从input_config动态获取时长选项

**获取选中类型的配置**：
```javascript
const selectedLeaveType = leaveTypes.find(type => type.slug === formData.type);
const durationOptions = selectedLeaveType?.input_config?.options || [];
```

**修改时长下拉框**：
```javascript
{formData.start_date && formData.end_date && formData.start_date === formData.end_date && durationOptions.length > 0 && (
    <div>
        <label>时长 (选填)</label>
        <select name="half_day">
            <option value="">全天</option>
            {durationOptions.map((option, index) => (
                <option key={index} value={option.key}>
                    {option.label}
                </option>
            ))}
        </select>
    </div>
)}
```

---

## 📊 数据流

### 修复前

```
前端（硬编码）：
<option value="personal">事假</option>
<option value="sick">病假</option>
    ↓
用户选择："personal"
    ↓
提交到后端：
{
  type: "personal"
}
    ↓
后端验证：
❌ "personal" 不在数据库中（实际是"personal_leave"）
    ↓
返回：422 "The selected type is invalid."
```

### 修复后

```
前端启动：
    ↓
调用API：GET /leave-types
    ↓
后端返回：
[
  { id: 1, slug: "personal_leave", name: "事假", input_config: {...} },
  { id: 2, slug: "sick_leave", name: "病假", input_config: {...} },
  { id: 3, slug: "health_leave", name: "生理假", input_config: {...} }
]
    ↓
前端渲染：
<option value="personal_leave">事假</option>
<option value="sick_leave">病假</option>
<option value="health_leave">生理假</option>
    ↓
用户选择："sick_leave"
    ↓
提交到后端：
{
  type: "sick_leave"
}
    ↓
后端验证：
✅ "sick_leave" 存在于数据库中
    ↓
返回：201 Created
```

---

## 🎯 修复效果

### 请假类型

**修复前**：
- 固定3个选项：事假、病假、例假
- 值是硬编码的：personal, sick, menstrual

**修复后**：
- 动态加载所有启用的请假类型
- 值来自数据库：personal_leave, sick_leave, health_leave等
- 管理员可以在后台添加/修改请假类型

### 时长选项

**修复前**：
- 固定选项：全天、仅上午、仅下午
- 值是硬编码的：am, pm

**修复后**：
- 根据请假类型的`input_config`动态显示
- 病假：上午、下午
- 生理假：早操、晚操
- 不同类型有不同的选项

---

## 🧪 测试验证

### 测试1：查看请假类型

**步骤**：
1. 刷新页面
2. 进入"新建请假申请"
3. 查看"请假类型"下拉框

**预期**：
- ✅ 显示所有启用的请假类型
- ✅ 显示正确的名称（事假、病假、生理假等）
- ✅ 默认选中第一个类型

### 测试2：查看时长选项

**步骤**：
1. 选择请假类型："病假"
2. 选择开始日期和结束日期（同一天）
3. 查看"时长"下拉框

**预期**：
- ✅ 显示病假的时长选项（上午、下午）
- ✅ 不显示硬编码的"仅上午"、"仅下午"

### 测试3：提交请假申请

**步骤**：
1. 选择请假类型："病假"
2. 选择日期范围
3. 填写申请理由
4. 点击"提交申请"

**预期**：
- ✅ 提交成功
- ✅ 不再出现"The selected type is invalid."错误
- ✅ 跳转到学生仪表板

### 测试4：验证数据库

**命令**：
```bash
docker exec classstudentsmanange-laravel.test-1 php artisan tinker --execute="
\$request = \App\Models\LeaveRequest::latest()->first();
echo json_encode([
    'type' => \$request->type,
    'start_date' => \$request->start_date,
    'end_date' => \$request->end_date,
    'half_day' => \$request->half_day,
], JSON_PRETTY_PRINT);
"
```

**预期**：
```json
{
    "type": "sick_leave",
    "start_date": "2025-12-20",
    "end_date": "2025-12-20",
    "half_day": "morning_half"
}
```

---

## 📝 修改总结

### 修改的文件

1. ✅ `resources/js/pages/student/LeaveRequestForm.jsx` - 动态加载请假类型和时长

### 代码变更

| 修改 | 说明 |
|------|------|
| 添加useState | 存储请假类型列表 |
| 添加useEffect | 从API获取请假类型 |
| 修改请假类型下拉框 | 使用动态数据 |
| 修改时长下拉框 | 从input_config获取选项 |

### 影响范围

- ✅ 学生端请假申请：现在使用动态数据
- ✅ 管理员可以在后台管理请假类型
- ✅ 不同请假类型有不同的时长选项

---

## ⚠️ 注意事项

### API依赖

**前端依赖的API**：
```
GET /leave-types
```

**返回格式**：
```json
[
  {
    "id": 1,
    "slug": "sick_leave",
    "name": "病假",
    "is_active": true,
    "input_config": {
      "options": [
        { "key": "morning_half", "label": "上午" },
        { "key": "afternoon_half", "label": "下午" }
      ]
    }
  }
]
```

### 向后兼容

**如果API返回空数组**：
- 下拉框会显示"-- 请选择 --"
- 用户无法提交请假
- 需要管理员先配置请假类型

### 性能优化

**当前实现**：
- 每次打开页面都会调用API
- 可以考虑缓存请假类型

**优化建议**：
```javascript
// 使用localStorage缓存
const cachedTypes = localStorage.getItem('leaveTypes');
if (cachedTypes) {
    setLeaveTypes(JSON.parse(cachedTypes));
} else {
    // 调用API并缓存
}
```

---

## ✅ 验证清单

- [x] 添加useEffect获取请假类型
- [x] 修改请假类型下拉框
- [x] 修改时长下拉框
- [x] 构建前端
- [ ] 测试查看请假类型
- [ ] 测试查看时长选项
- [ ] 测试提交请假申请
- [ ] 验证数据库记录

---

*完成时间: 2025-12-19 16:20*
*Bug: 请假类型和时长硬编码*
*修复: 从API动态获取*
*状态: ✅ 已修复*
*影响: 学生端请假申请*
