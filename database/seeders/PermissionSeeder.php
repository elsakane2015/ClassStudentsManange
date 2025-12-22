<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Permission;
use App\Models\RolePermission;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Define all permissions
        $permissions = [
            // System Settings
            ['name' => 'settings.view', 'display_name' => '查看系统设置', 'category' => 'system'],
            ['name' => 'settings.edit', 'display_name' => '编辑系统设置', 'category' => 'system'],
            
            // User Management
            ['name' => 'users.view', 'display_name' => '查看用户', 'category' => 'user'],
            ['name' => 'users.create', 'display_name' => '创建用户', 'category' => 'user'],
            ['name' => 'users.edit', 'display_name' => '编辑用户', 'category' => 'user'],
            ['name' => 'users.delete', 'display_name' => '删除用户', 'category' => 'user'],
            
            // Permission Management
            ['name' => 'permissions.manage', 'display_name' => '管理权限配置', 'category' => 'system'],
            
            // Attendance
            ['name' => 'attendance.view_all', 'display_name' => '查看所有考勤', 'category' => 'attendance'],
            ['name' => 'attendance.view_department', 'display_name' => '查看本系部考勤', 'category' => 'attendance'],
            ['name' => 'attendance.view_class', 'display_name' => '查看本班考勤', 'category' => 'attendance'],
            ['name' => 'attendance.edit', 'display_name' => '编辑考勤', 'category' => 'attendance'],
            
            // Leave Requests
            ['name' => 'leave_requests.view_all', 'display_name' => '查看所有请假', 'category' => 'leave'],
            ['name' => 'leave_requests.view_department', 'display_name' => '查看本系部请假', 'category' => 'leave'],
            ['name' => 'leave_requests.view_class', 'display_name' => '查看本班请假', 'category' => 'leave'],
            ['name' => 'leave_requests.approve', 'display_name' => '审批请假', 'category' => 'leave'],
            
            // Student Management
            ['name' => 'students.view_all', 'display_name' => '查看所有学生', 'category' => 'student'],
            ['name' => 'students.view_department', 'display_name' => '查看本系部学生', 'category' => 'student'],
            ['name' => 'students.view_class', 'display_name' => '查看本班学生', 'category' => 'student'],
            ['name' => 'students.edit', 'display_name' => '编辑学生信息', 'category' => 'student'],
            ['name' => 'students.assign_manager', 'display_name' => '指定学生管理员', 'category' => 'student'],
            
            // Class Management
            ['name' => 'classes.view', 'display_name' => '查看班级', 'category' => 'class'],
            ['name' => 'classes.manage', 'display_name' => '管理班级', 'category' => 'class'],
        ];

        foreach ($permissions as $permission) {
            Permission::updateOrCreate(
                ['name' => $permission['name']],
                $permission
            );
        }

        // Define default role permissions
        $this->seedRolePermissions();
    }

    private function seedRolePermissions()
    {
        $rolePermissions = [
            // System Admin - Full Access
            'system_admin' => [
                'settings.view' => ['read' => true, 'update' => true],
                'settings.edit' => ['read' => true, 'update' => true],
                'users.view' => ['read' => true],
                'users.create' => ['create' => true],
                'users.edit' => ['update' => true],
                'users.delete' => ['delete' => true],
                'permissions.manage' => ['read' => true, 'update' => true],
                'attendance.view_all' => ['read' => true],
                'attendance.edit' => ['update' => true],
                'leave_requests.view_all' => ['read' => true],
                'leave_requests.approve' => ['update' => true],
                'students.view_all' => ['read' => true],
                'students.edit' => ['update' => true],
                'students.assign_manager' => ['update' => true],
                'classes.view' => ['read' => true],
                'classes.manage' => ['create' => true, 'update' => true, 'delete' => true],
            ],
            
            // School Admin
            'school_admin' => [
                'settings.view' => ['read' => true],
                'settings.edit' => ['update' => true],
                'users.view' => ['read' => true],
                'users.create' => ['create' => true],
                'users.edit' => ['update' => true],
                'users.delete' => ['delete' => true],
                'attendance.view_all' => ['read' => true],
                'students.view_all' => ['read' => true],
                'classes.view' => ['read' => true],
            ],
            
            // Department Manager
            'department_manager' => [
                'users.view' => ['read' => true],
                'users.create' => ['create' => true],
                'users.edit' => ['update' => true],
                'attendance.view_department' => ['read' => true],
                'attendance.edit' => ['update' => true],
                'leave_requests.view_department' => ['read' => true],
                'leave_requests.approve' => ['update' => true],
                'students.view_department' => ['read' => true],
                'students.edit' => ['update' => true],
                'classes.view' => ['read' => true],
            ],
            
            // Teacher
            'teacher' => [
                'attendance.view_class' => ['read' => true],
                'attendance.edit' => ['update' => true],
                'leave_requests.view_class' => ['read' => true],
                'leave_requests.approve' => ['update' => true],
                'students.view_class' => ['read' => true],
                'students.assign_manager' => ['update' => true],
            ],
            
            // Student (with is_manager flag will get additional permissions at runtime)
            'student' => [
                // Base student has no special permissions
                // Student managers get permissions checked via is_manager flag
            ],
        ];

        foreach ($rolePermissions as $role => $permissions) {
            foreach ($permissions as $permissionName => $actions) {
                $permission = Permission::where('name', $permissionName)->first();
                if ($permission) {
                    RolePermission::updateOrCreate(
                        [
                            'role' => $role,
                            'permission_id' => $permission->id
                        ],
                        [
                            'can_create' => $actions['create'] ?? false,
                            'can_read' => $actions['read'] ?? false,
                            'can_update' => $actions['update'] ?? false,
                            'can_delete' => $actions['delete'] ?? false,
                        ]
                    );
                }
            }
        }
    }
}
