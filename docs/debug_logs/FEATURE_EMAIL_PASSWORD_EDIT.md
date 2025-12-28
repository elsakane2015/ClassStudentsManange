# 功能增强 - 支持修改账号和重置密码

## 📋 需求

用户要求：

1. **账号(Email)可以修改**
2. **密码可以重置**

---

## ✅ 实现方案

### 修改1：前端 - 启用Email编辑

**文件**：`resources/js/pages/teacher/StudentList.jsx` (第213-223行)

**修改前**：
```javascript
<input 
    type="email" 
    value={formData.email} 
    disabled={editingStudent}  // ❌ 编辑时禁用
/>
{editingStudent && <p>账号不可修改</p>}
```

**修改后**：
```javascript
<input 
    type="email" 
    required
    value={formData.email} 
    onChange={e => setFormData({ ...formData, email: e.target.value })} 
    // ✅ 编辑时也可以修改
/>
```

---

### 修改2：前端 - 添加密码重置字段

**文件**：`resources/js/pages/teacher/StudentList.jsx` (第225-236行)

**添加**：
```javascript
{editingStudent && (
    <div>
        <label className="block text-sm font-medium text-gray-700">重置密码</label>
        <input 
            type="text" 
            placeholder="留空则不修改密码"
            value={formData.password} 
            onChange={e => setFormData({ ...formData, password: e.target.value })} 
            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm p-2 border"
        />
        <p className="mt-1 text-xs text-gray-500">如需重置密码，请输入新密码</p>
    </div>
)}
```

**特性**：
- 只在编辑时显示
- 留空则不修改密码
- 输入新密码则重置

---

### 修改3：后端 - 支持更新Email和密码

**文件**：`app/Http/Controllers/Api/StudentController.php` (第173-213行)

#### 验证规则

**添加**：
```php
$request->validate([
    'name' => 'sometimes|string',
    'student_no' => 'sometimes|string',
    'gender' => 'sometimes|in:male,female',
    'parent_contact' => 'nullable|string',
    'email' => 'sometimes|email|unique:users,email,' . $student->user->id,  // 新增
    'password' => 'nullable|string|min:6',  // 新增
]);
```

**说明**：
- `email`：验证email格式，且在users表中唯一（排除当前用户）
- `password`：可选，最少6位

#### 更新逻辑

**修改前**：
```php
// Update User info
if ($request->has('name')) {
    $student->user->update(['name' => $request->name]);
}
```

**修改后**：
```php
// Update User info
$userUpdates = [];
if ($request->has('name')) {
    $userUpdates['name'] = $request->name;
}
if ($request->has('email')) {
    $userUpdates['email'] = $request->email;
}
if ($request->filled('password')) {
    $userUpdates['password'] = bcrypt($request->password);
}

if (!empty($userUpdates)) {
    $student->user->update($userUpdates);
}
```

**说明**：
- 收集所有需要更新的User字段
- 一次性更新
- `filled('password')`：只有当password不为空时才更新

---

## 📊 界面效果

### 编辑表单

**修改前**：
- 姓名
- 学号
- 性别
- 家长联系方式
- 账号(Email) - ❌ 禁用，不可修改
- ❌ 没有密码字段

**修改后**：
- 姓名
- 学号
- 性别
- 家长联系方式
- 账号(Email) - ✅ 可以修改
- ✅ **重置密码** - 可选，留空则不修改

---

## 🔧 技术细节

### Email修改

#### 前端
```javascript
// 编辑时也可以修改email
<input 
    type="email" 
    required
    value={formData.email} 
    onChange={e => setFormData({ ...formData, email: e.target.value })} 
/>
```

#### 后端
```php
// 验证email唯一性（排除当前用户）
'email' => 'sometimes|email|unique:users,email,' . $student->user->id,

// 更新email
if ($request->has('email')) {
    $userUpdates['email'] = $request->email;
}
```

### 密码重置

#### 前端
```javascript
// 只在编辑时显示
{editingStudent && (
    <input 
        type="text" 
        placeholder="留空则不修改密码"
        value={formData.password} 
        ...
    />
)}
```

#### 后端
```php
// 验证密码长度
'password' => 'nullable|string|min:6',

// 只有当password不为空时才更新
if ($request->filled('password')) {
    $userUpdates['password'] = bcrypt($request->password);
}
```

---

## 🎯 使用场景

### 场景1：修改Email

**步骤**：
1. 点击"编辑"
2. 修改"账号(Email)"字段
3. 点击"保存"

**结果**：
- ✅ Email更新成功
- ✅ 学生可以用新email登录

### 场景2：重置密码

**步骤**：
1. 点击"编辑"
2. 在"重置密码"字段输入新密码
3. 点击"保存"

**结果**：
- ✅ 密码更新成功
- ✅ 学生可以用新密码登录

### 场景3：只修改其他信息

**步骤**：
1. 点击"编辑"
2. 修改姓名、学号等
3. "重置密码"留空
4. 点击"保存"

**结果**：
- ✅ 其他信息更新成功
- ✅ Email和密码保持不变

---

## 📝 修改总结

### 修改的文件

1. ✅ `resources/js/pages/teacher/StudentList.jsx` - 启用email编辑，添加密码重置
2. ✅ `app/Http/Controllers/Api/StudentController.php` - 支持更新email和密码

### 代码变更

| 文件 | 类型 | 行数 |
|------|------|------|
| StudentList.jsx | 移除禁用 | -3行 |
| StudentList.jsx | 添加密码字段 | +13行 |
| StudentController.php | 验证规则 | +2行 |
| StudentController.php | 更新逻辑 | +15行 |
| **总计** | | **+27行** |

---

## 🧪 测试验证

### 测试1：修改Email

**步骤**：
1. 刷新学生管理页面
2. 点击某个学生的"编辑"
3. 修改"账号(Email)"为新email
4. 点击"保存"

**预期**：
- ✅ 保存成功
- ✅ 列表中显示新email
- ✅ 学生可以用新email登录

### 测试2：重置密码

**步骤**：
1. 点击某个学生的"编辑"
2. 在"重置密码"字段输入"newpass123"
3. 点击"保存"

**预期**：
- ✅ 保存成功
- ✅ 学生可以用新密码登录

### 测试3：Email唯一性验证

**步骤**：
1. 点击某个学生的"编辑"
2. 修改email为已存在的email
3. 点击"保存"

**预期**：
- ❌ 保存失败
- ✅ 显示错误："该email已被使用"

### 测试4：密码留空

**步骤**：
1. 点击某个学生的"编辑"
2. 修改姓名
3. "重置密码"留空
4. 点击"保存"

**预期**：
- ✅ 保存成功
- ✅ 姓名更新
- ✅ 密码保持不变

---

## ⚠️ 注意事项

### Email修改的影响

**修改email后**：
- ✅ 学生需要用新email登录
- ⚠️ 旧email将无法登录
- 建议：修改前通知学生

### 密码重置的安全性

**最佳实践**：
- ✅ 密码最少6位
- ✅ 使用bcrypt加密
- ⚠️ 建议：重置后通知学生新密码

---

## ✅ 验证清单

- [x] 移除email禁用状态
- [x] 添加密码重置字段
- [x] 后端支持email更新
- [x] 后端支持密码更新
- [x] Email唯一性验证
- [x] 密码加密
- [ ] 测试修改email
- [ ] 测试重置密码
- [ ] 测试email唯一性
- [ ] 测试密码留空

---

*完成时间: 2025-12-19 14:18*
*功能: 支持修改账号和重置密码*
*修改: 前端+后端*
*状态: ✅ 已完成*
*特性: Email可修改，密码可重置*
