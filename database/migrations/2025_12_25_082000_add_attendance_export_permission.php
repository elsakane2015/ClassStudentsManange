<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Permission;
use App\Models\RolePermission;

return new class extends Migration
{
    public function up(): void
    {
        // Add attendance.export permission
        $permission = Permission::updateOrCreate(
            ['name' => 'attendance.export'],
            [
                'display_name' => '导出考勤记录',
                'category' => 'attendance'
            ]
        );

        // Grant to system_admin, school_admin, and teacher by default
        $roles = ['system_admin', 'school_admin', 'department_manager', 'teacher'];
        foreach ($roles as $role) {
            RolePermission::updateOrCreate(
                [
                    'role' => $role,
                    'permission_id' => $permission->id
                ],
                [
                    'can_read' => true,
                    'can_create' => false,
                    'can_update' => false,
                    'can_delete' => false,
                ]
            );
        }
    }

    public function down(): void
    {
        $permission = Permission::where('name', 'attendance.export')->first();
        if ($permission) {
            RolePermission::where('permission_id', $permission->id)->delete();
            $permission->delete();
        }
    }
};
