<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /**
     * Display a listing of users based on requested role filter.
     */
    public function index(Request $request)
    {
        $currentUser = $request->user();
        $targetRole = $request->query('role');

        if (! $targetRole) {
            return response()->json(['error' => 'Role parameter required'], 400);
        }

        // Check permission
        if (! $this->canViewRole($currentUser, $targetRole)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $query = User::where('role', $targetRole);

        $managedDepartmentIds = collect();
        if (in_array($currentUser->role, ['department_manager', 'manager'], true)) {
            $managedDepartmentIds = $this->managedDepartmentIds($currentUser);

            if ($targetRole === 'duty_teacher') {
                $query->whereHas('dutyDepartments', function ($departmentQuery) use ($managedDepartmentIds) {
                    $departmentQuery->whereIn('departments.id', $managedDepartmentIds);
                });
            }
        }

        // Eager load relationships
        if (in_array($targetRole, ['department_manager', 'manager'])) {
            $query->with('managedDepartments');
        } elseif ($targetRole === 'duty_teacher') {
            $query->with(['dutyDepartments' => function ($departmentQuery) use ($currentUser, $managedDepartmentIds) {
                if (in_array($currentUser->role, ['department_manager', 'manager'], true)) {
                    $departmentQuery->whereIn('departments.id', $managedDepartmentIds);
                }
            }]);
        } elseif ($targetRole === 'teacher') {
            $query->with('teacherClasses.department');
        }

        return response()->json($query->orderBy('created_at', 'desc')->get());
    }

    public function store(Request $request)
    {
        $currentUser = $request->user();

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:6',
            'role' => ['required', Rule::in(['system_admin', 'school_admin', 'department_manager', 'duty_teacher', 'teacher', 'manager', 'admin'])], // Support old and new
            'department_ids' => 'nullable|array',
            'department_ids.*' => 'exists:departments,id',
            'class_ids' => 'nullable|array',
            'class_ids.*' => 'exists:classes,id',
        ]);

        $role = $request->role;

        // Map old roles to new ones
        $roleMap = [
            'admin' => 'school_admin',
            'manager' => 'department_manager',
        ];
        $role = $roleMap[$role] ?? $role;

        // Permission Check
        if (! $this->canManageRole($currentUser, $role)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if ($role === 'duty_teacher') {
            $scopeError = $this->validateDutyDepartmentScope(
                $currentUser,
                collect($request->input('department_ids', []))
            );
            if ($scopeError) {
                return $scopeError;
            }
        }

        $user = DB::transaction(function () use ($request, $role) {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role' => $role,
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'status' => true,
            ]);

            // Assignments
            if (in_array($role, ['department_manager', 'manager']) && $request->filled('department_ids')) {
                $user->managedDepartments()->sync($request->department_ids);
            }

            if ($role === 'duty_teacher' && $request->filled('department_ids')) {
                $user->dutyDepartments()->sync($request->department_ids);
            }

            if ($role === 'teacher' && $request->filled('class_ids')) {
                \App\Models\SchoolClass::whereIn('id', $request->class_ids)->update(['teacher_id' => $user->id]);
            }

            return $user;
        });

        return response()->json($user, 201);
    }

    public function update(Request $request, User $user)
    {
        $currentUser = $request->user();

        // Permission Check
        if (! $this->canManageRole($currentUser, $user->role)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if (! $this->canManageTarget($currentUser, $user)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => ['sometimes', 'email', Rule::unique('users')->ignore($user->id)],
            'department_ids' => 'nullable|array',
            'department_ids.*' => 'exists:departments,id',
            'class_ids' => 'nullable|array',
            'class_ids.*' => 'exists:classes,id',
        ]);

        if ($user->role === 'duty_teacher' && $request->has('department_ids')) {
            $scopeError = $this->validateDutyDepartmentScope(
                $currentUser,
                collect($request->input('department_ids', []))
            );
            if ($scopeError) {
                return $scopeError;
            }
        }

        $user->update($request->only(['name', 'email']));

        if ($request->filled('password')) {
            $user->update(['password' => Hash::make($request->password)]);
        }

        // Update Assignments
        if (in_array($user->role, ['department_manager', 'manager'])) {
            if ($request->has('department_ids')) {
                // Use sync for many-to-many relationship
                $user->managedDepartments()->sync($request->department_ids ?? []);
            }
        }

        if ($user->role === 'duty_teacher' && $request->has('department_ids')) {
            if (in_array($currentUser->role, ['department_manager', 'manager'], true)) {
                $managedDepartmentIds = $this->managedDepartmentIds($currentUser);
                $selectedDepartmentIds = collect($request->input('department_ids', []))->map(fn ($id) => (int) $id);

                $user->dutyDepartments()->detach($managedDepartmentIds->diff($selectedDepartmentIds));
                $user->dutyDepartments()->syncWithoutDetaching($selectedDepartmentIds);
            } else {
                $user->dutyDepartments()->sync($request->department_ids ?? []);
            }
        }

        if ($user->role === 'teacher' && $request->has('class_ids')) {
            \App\Models\SchoolClass::where('teacher_id', $user->id)->update(['teacher_id' => null]);
            if (! empty($request->class_ids)) {
                \App\Models\SchoolClass::whereIn('id', $request->class_ids)->update(['teacher_id' => $user->id]);
            }
        }

        return response()->json($user);
    }

    public function destroy(Request $request, User $user)
    {
        $currentUser = $request->user();

        // Permission Check
        if (! $this->canManageRole($currentUser, $user->role)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if (! $this->canManageTarget($currentUser, $user)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Prevent deleting self
        if ($user->id === $currentUser->id) {
            return response()->json(['error' => 'Cannot delete yourself'], 403);
        }

        // Prevent deleting system_admin if you're not system_admin
        if ($user->role === 'system_admin' && $currentUser->role !== 'system_admin') {
            return response()->json(['error' => 'Cannot delete system administrator'], 403);
        }

        if ($user->role === 'duty_teacher'
            && in_array($currentUser->role, ['department_manager', 'manager'], true)) {
            $user->dutyDepartments()->detach($this->managedDepartmentIds($currentUser));

            if ($user->dutyDepartments()->exists()) {
                return response()->json(['message' => '已解除该值班老师与当前系部的绑定']);
            }
        }

        // Nullify foreign keys and detach relationships
        $user->managedDepartments()->detach();
        $user->dutyDepartments()->detach();
        \App\Models\SchoolClass::where('teacher_id', $user->id)->update(['teacher_id' => null]);

        $user->delete();

        return response()->json(['message' => 'User deleted']);
    }

    /**
     * Check if current user can view users of target role
     */
    private function canViewRole(User $currentUser, string $targetRole): bool
    {
        // Map old roles to new
        $roleMap = [
            'admin' => 'school_admin',
            'manager' => 'department_manager',
        ];
        $targetRole = $roleMap[$targetRole] ?? $targetRole;

        if ($currentUser->role === 'system_admin') {
            return true;
        }

        if ($currentUser->role === 'school_admin') {
            return in_array($targetRole, ['school_admin', 'department_manager', 'duty_teacher', 'teacher']);
        }

        if (in_array($currentUser->role, ['department_manager', 'manager'])) {
            return in_array($targetRole, ['duty_teacher', 'teacher'], true);
        }

        return false;
    }

    /**
     * Check if current user can manage users of target role
     */
    private function canManageRole(User $currentUser, string $targetRole): bool
    {
        // Map old roles to new
        $roleMap = [
            'admin' => 'school_admin',
            'manager' => 'department_manager',
        ];
        $targetRole = $roleMap[$targetRole] ?? $targetRole;

        if ($currentUser->role === 'system_admin') {
            return true;
        }

        if ($currentUser->role === 'school_admin') {
            return in_array($targetRole, ['department_manager', 'duty_teacher', 'teacher']);
        }

        if (in_array($currentUser->role, ['department_manager', 'manager'])) {
            return in_array($targetRole, ['duty_teacher', 'teacher'], true);
        }

        return false;
    }

    private function canManageTarget(User $currentUser, User $targetUser): bool
    {
        if (! in_array($currentUser->role, ['department_manager', 'manager'], true)
            || $targetUser->role !== 'duty_teacher') {
            return true;
        }

        return $targetUser->dutyDepartments()
            ->whereIn('departments.id', $this->managedDepartmentIds($currentUser))
            ->exists();
    }

    private function validateDutyDepartmentScope(User $currentUser, $departmentIds)
    {
        if (! in_array($currentUser->role, ['department_manager', 'manager'], true)) {
            return null;
        }

        $departmentIds = collect($departmentIds)->map(fn ($id) => (int) $id)->unique();
        if ($departmentIds->isEmpty()) {
            return response()->json([
                'message' => '请至少选择一个负责系部',
                'errors' => ['department_ids' => ['请至少选择一个负责系部']],
            ], 422);
        }

        if ($departmentIds->diff($this->managedDepartmentIds($currentUser))->isNotEmpty()) {
            return response()->json(['error' => '不能分配当前账号无权管理的系部'], 403);
        }

        return null;
    }

    private function managedDepartmentIds(User $user)
    {
        return $user->managedDepartments()
            ->pluck('departments.id')
            ->map(fn ($id) => (int) $id);
    }
}
