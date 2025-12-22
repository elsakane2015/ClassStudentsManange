<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\RolePermission;
use Illuminate\Http\Request;

class PermissionController extends Controller
{
    /**
     * Get all permissions grouped by category
     */
    public function index()
    {
        $permissions = Permission::orderBy('category')->orderBy('name')->get();
        
        return response()->json([
            'permissions' => $permissions->groupBy('category')
        ]);
    }

    /**
     * Get role permissions matrix
     */
    public function getRolePermissions()
    {
        $roles = ['system_admin', 'school_admin', 'department_manager', 'teacher', 'student'];
        $permissions = Permission::with(['rolePermissions' => function($query) {
            $query->select('role', 'permission_id', 'can_create', 'can_read', 'can_update', 'can_delete');
        }])->get();

        $matrix = [];
        foreach ($permissions as $permission) {
            $permData = [
                'id' => $permission->id,
                'name' => $permission->name,
                'display_name' => $permission->display_name,
                'category' => $permission->category,
                'roles' => []
            ];

            foreach ($roles as $role) {
                $rolePermission = $permission->rolePermissions->firstWhere('role', $role);
                $permData['roles'][$role] = [
                    'can_create' => $rolePermission?->can_create ?? false,
                    'can_read' => $rolePermission?->can_read ?? false,
                    'can_update' => $rolePermission?->can_update ?? false,
                    'can_delete' => $rolePermission?->can_delete ?? false,
                ];
            }

            $matrix[] = $permData;
        }

        return response()->json([
            'matrix' => $matrix,
            'roles' => $roles
        ]);
    }

    /**
     * Update role permission
     */
    public function updateRolePermission(Request $request)
    {
        $request->validate([
            'role' => 'required|string',
            'permission_id' => 'required|exists:permissions,id',
            'can_create' => 'boolean',
            'can_read' => 'boolean',
            'can_update' => 'boolean',
            'can_delete' => 'boolean',
        ]);

        // Only system_admin can update permissions
        if ($request->user()->role !== 'system_admin') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        RolePermission::updateOrCreate(
            [
                'role' => $request->role,
                'permission_id' => $request->permission_id
            ],
            [
                'can_create' => $request->can_create ?? false,
                'can_read' => $request->can_read ?? false,
                'can_update' => $request->can_update ?? false,
                'can_delete' => $request->can_delete ?? false,
            ]
        );

        return response()->json(['message' => 'Permission updated successfully']);
    }

    /**
     * Batch update role permissions
     */
    public function batchUpdate(Request $request)
    {
        $request->validate([
            'updates' => 'required|array',
            'updates.*.role' => 'required|string',
            'updates.*.permission_id' => 'required|exists:permissions,id',
            'updates.*.can_create' => 'boolean',
            'updates.*.can_read' => 'boolean',
            'updates.*.can_update' => 'boolean',
            'updates.*.can_delete' => 'boolean',
        ]);

        // Only system_admin can update permissions
        if ($request->user()->role !== 'system_admin') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        foreach ($request->updates as $update) {
            RolePermission::updateOrCreate(
                [
                    'role' => $update['role'],
                    'permission_id' => $update['permission_id']
                ],
                [
                    'can_create' => $update['can_create'] ?? false,
                    'can_read' => $update['can_read'] ?? false,
                    'can_update' => $update['can_update'] ?? false,
                    'can_delete' => $update['can_delete'] ?? false,
                ]
            );
        }

        return response()->json(['message' => 'Permissions updated successfully']);
    }
}
