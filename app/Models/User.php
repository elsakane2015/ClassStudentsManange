<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $guarded = [];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'status' => 'boolean',
    ];

    public function student()
    {
        return $this->hasOne(Student::class);
    }
    
    public function teacherClasses()
    {
        return $this->hasMany(SchoolClass::class, 'teacher_id');
    }

    public function managedDepartments()
    {
        return $this->hasMany(Department::class, 'manager_id');
    }

    /**
     * Check if user has a specific permission
     */
    public function hasPermission(string $permission, string $action = 'read'): bool
    {
        // System admin has all permissions
        if ($this->role === 'system_admin') {
            return true;
        }

        // Student managers get special permissions
        if ($this->role === 'student' && $this->student?->is_manager) {
            return $this->hasStudentManagerPermission($permission, $action);
        }

        // Check role permissions
        $rolePermission = \App\Models\RolePermission::whereHas('permission', function($query) use ($permission) {
            $query->where('name', $permission);
        })->where('role', $this->role)->first();

        if (!$rolePermission) {
            return false;
        }

        return match($action) {
            'create' => $rolePermission->can_create,
            'read' => $rolePermission->can_read,
            'update' => $rolePermission->can_update,
            'delete' => $rolePermission->can_delete,
            default => false,
        };
    }

    /**
     * Check permissions for student managers
     */
    private function hasStudentManagerPermission(string $permission, string $action): bool
    {
        $allowedPermissions = [
            'attendance.view_class',
            'attendance.edit',
            'leave_requests.view_class',
            'leave_requests.approve',
            'students.view_class',
        ];

        if (!in_array($permission, $allowedPermissions)) {
            return false;
        }

        return in_array($action, ['read', 'update']);
    }

    /**
     * Get all permissions for this user
     */
    public function getPermissions(): array
    {
        if ($this->role === 'system_admin') {
            return \App\Models\Permission::pluck('name')->toArray();
        }

        if ($this->role === 'student' && $this->student?->is_manager) {
            return [
                'attendance.view_class',
                'attendance.edit',
                'leave_requests.view_class',
                'leave_requests.approve',
                'students.view_class',
            ];
        }

        return \App\Models\RolePermission::where('role', $this->role)
            ->with('permission')
            ->get()
            ->filter(function($rp) {
                return $rp->can_read || $rp->can_create || $rp->can_update || $rp->can_delete;
            })
            ->pluck('permission.name')
            ->toArray();
    }

    /**
     * Check if user can manage a specific user
     */
    public function canManageUser(User $targetUser): bool
    {
        // System admin can manage everyone
        if ($this->role === 'system_admin') {
            return true;
        }

        // School admin can manage department_manager and teacher
        if ($this->role === 'school_admin') {
            return in_array($targetUser->role, ['department_manager', 'teacher']);
        }

        // Department manager can manage teachers in their department
        if ($this->role === 'department_manager') {
            if ($targetUser->role !== 'teacher') {
                return false;
            }
            // Check if teacher belongs to this manager's department
            $deptIds = $this->managedDepartments->pluck('id');
            $teacherDeptIds = $targetUser->teacherClasses()->whereIn('department_id', $deptIds)->exists();
            return $teacherDeptIds;
        }

        return false;
    }
}
