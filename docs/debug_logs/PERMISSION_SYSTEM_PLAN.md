# 权限管理系统实施计划

## 角色定义和权限矩阵

### 1. 系统管理员 (system_admin)
**最高权限，可以配置所有其他角色的权限**

权限列表：
- ✅ 系统设置（学期、请假类型、全局配置）
- ✅ 人员管理（创建/编辑/删除所有角色用户）
- ✅ 权限配置（为其他角色分配权限）
- ✅ 查看所有学生出勤概览
- ✅ 审批所有请假申请
- ✅ 设定所有学生考勤
- ✅ 学生管理（所有学生）
- ✅ 班级管理

### 2. 校管理员 (school_admin)
**学校级别管理员**

权限列表：
- ✅ 系统设置（学期、请假类型）
- ✅ 人员管理（查看同级、配置系部管理员、配置班主任）
- ❌ 权限配置（不能修改权限）
- ✅ 查看所有学生出勤概览
- ❌ 审批请假（不参与审批流程）
- ❌ 设定学生考勤（不直接操作）
- ✅ 学生管理（查看所有学生）
- ✅ 班级管理（查看）

### 3. 系部管理员 (department_manager)
**系部级别管理员**

权限列表：
- ❌ 系统设置
- ✅ 人员管理（查看同级、配置班主任）
- ❌ 权限配置
- ✅ 查看本系部学生出勤概览
- ✅ 审批本系部学生请假申请
- ✅ 调整本系部学生考勤
- ✅ 学生管理（本系部学生）
- ✅ 班级管理（本系部班级）

### 4. 班主任 (teacher)
**班级级别管理员**

权限列表：
- ❌ 系统设置
- ✅ 人员管理（指定班级学生管理员）
- ❌ 权限配置
- ✅ 查看本班学生出勤概览
- ✅ 审批本班学生请假申请
- ✅ 设定本班学生考勤
- ✅ 学生管理（本班学生）
- ❌ 班级管理

### 5. 班级学生管理员 (student_manager)
**学生角色，但有管理权限**

权限列表：
- ❌ 系统设置
- ❌ 人员管理
- ❌ 权限配置
- ✅ 查看本班学生出勤概览
- ✅ 审批本班学生请假申请
- ✅ 设定本班学生考勤
- ✅ 学生管理（本班学生，查看）
- ❌ 班级管理

### 6. 普通学生 (student)
**基础学生角色**

权限列表：
- ❌ 系统设置
- ❌ 人员管理
- ❌ 权限配置
- ✅ 查看自己的出勤记录
- ✅ 提交请假申请
- ❌ 设定考勤
- ❌ 学生管理
- ❌ 班级管理

## 数据库设计

### 1. 更新 users 表
```sql
ALTER TABLE users MODIFY COLUMN role ENUM(
    'system_admin',
    'school_admin', 
    'department_manager',
    'teacher',
    'student'
) NOT NULL DEFAULT 'student';
```

### 2. 创建 permissions 表（可选，用于动态权限配置）
```sql
CREATE TABLE permissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE,
    display_name VARCHAR(255) NOT NULL,
    description TEXT,
    category VARCHAR(100), -- 'system', 'user', 'attendance', 'student'
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

### 3. 创建 role_permissions 表
```sql
CREATE TABLE role_permissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role VARCHAR(50) NOT NULL,
    permission_id BIGINT UNSIGNED NOT NULL,
    can_create BOOLEAN DEFAULT FALSE,
    can_read BOOLEAN DEFAULT FALSE,
    can_update BOOLEAN DEFAULT FALSE,
    can_delete BOOLEAN DEFAULT FALSE,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE,
    UNIQUE KEY unique_role_permission (role, permission_id)
);
```

## 实施步骤

### Phase 1: 数据库和后端基础 (优先级：高)
1. ✅ 创建数据库迁移文件
2. ✅ 更新 User 模型
3. ✅ 创建 Permission 和 RolePermission 模型
4. ✅ 创建权限种子数据
5. ✅ 创建权限中间件

### Phase 2: 权限配置界面 (优先级：高)
1. ✅ 创建权限管理页面（仅系统管理员可见）
2. ✅ 实现权限矩阵编辑器
3. ✅ 实现角色权限分配API

### Phase 3: 更新现有功能 (优先级：中)
1. ✅ 更新导航菜单（基于权限显示/隐藏）
2. ✅ 更新人员管理页面（基于角色显示不同标签）
3. ✅ 更新Dashboard（基于权限过滤数据）
4. ✅ 更新学生列表（添加"指定为管理员"功能）

### Phase 4: 审批流程优化 (优先级：中)
1. ✅ 更新请假审批逻辑（多级审批）
2. ✅ 添加审批权限检查
3. ✅ 优化审批通知

### Phase 5: 测试和文档 (优先级：低)
1. ⬜ 创建测试用户（每个角色）
2. ⬜ 编写权限测试用例
3. ⬜ 编写用户手册

## 权限检查示例代码

### 后端 (Laravel Middleware)
```php
// app/Http/Middleware/CheckPermission.php
public function handle($request, Closure $next, $permission)
{
    if (!$request->user()->hasPermission($permission)) {
        abort(403, 'Unauthorized action.');
    }
    return $next($request);
}
```

### 前端 (React Hook)
```javascript
// usePermission.js
export const usePermission = (permission) => {
    const { user } = useAuthStore();
    return user?.permissions?.includes(permission) || false;
};
```

## 权限命名规范

格式：`resource.action`

示例：
- `settings.view` - 查看系统设置
- `settings.edit` - 编辑系统设置
- `users.create` - 创建用户
- `users.edit` - 编辑用户
- `users.delete` - 删除用户
- `attendance.view_all` - 查看所有考勤
- `attendance.view_department` - 查看本系部考勤
- `attendance.view_class` - 查看本班考勤
- `attendance.edit` - 编辑考勤
- `leave_requests.approve` - 审批请假
- `students.manage` - 管理学生
- `student_managers.assign` - 指定学生管理员

## 下一步行动

请确认以上设计是否符合您的需求。确认后我将开始实施：

1. 首先创建数据库迁移和模型
2. 然后创建权限配置界面
3. 最后更新现有功能以支持新的权限系统

是否开始实施？
