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

    public function children()
    {
        return $this->belongsToMany(Student::class, 'parent_student', 'parent_user_id', 'student_id')
            ->withTimestamps();
    }

    public function isParentOnly(): bool
    {
        return $this->role === 'parent';
    }
    
    public function teacherClasses()
    {
        return $this->hasMany(SchoolClass::class, 'teacher_id');
    }

    public function managedDepartments()
    {
        return $this->belongsToMany(Department::class, 'department_managers', 'user_id', 'department_id')
            ->withTimestamps();
    }

    public function dutyDepartments()
    {
        return $this->belongsToMany(Department::class, 'department_duty_teachers', 'user_id', 'department_id')
            ->withTimestamps();
    }

    public function teacherEmailAccount()
    {
        return $this->hasOne(TeacherEmailAccount::class);
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
     * Get all permissions for this user
     */
    public function getPermissions(): array
    {
        if ($this->role === 'system_admin') {
            return \App\Models\Permission::pluck('name')->toArray();
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

        // School admin can manage department managers, duty teachers and teachers.
        if ($this->role === 'school_admin') {
            return in_array($targetUser->role, ['department_manager', 'duty_teacher', 'teacher']);
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

    public function wechatConfig()
    {
        return $this->hasOne(WechatConfig::class);
    }

    public function wechatBinding()
    {
        return $this->hasOne(WechatBinding::class);
    }

    public function emailNotificationPreference()
    {
        return $this->hasOne(EmailNotificationPreference::class);
    }

    public function mobileDevices()
    {
        return $this->hasMany(MobileDevice::class);
    }

    public function appNotifications()
    {
        return $this->hasMany(AppNotification::class);
    }
}
