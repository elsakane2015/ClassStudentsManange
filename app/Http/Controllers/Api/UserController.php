<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
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

        if (!$targetRole) {
            return response()->json(['error' => 'Role parameter required'], 400);
        }

        // Check permission
        if (!$this->canViewRole($currentUser, $targetRole)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $query = User::where('role', $targetRole);

        // Eager load relationships
        if (in_array($targetRole, ['department_manager', 'manager'])) {
            $query->with('managedDepartments');
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
            'role' => ['required', Rule::in(['system_admin', 'school_admin', 'department_manager', 'teacher', 'manager', 'admin'])], // Support old and new
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
        if (!$this->canManageRole($currentUser, $role)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

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
            \App\Models\Department::whereIn('id', $request->department_ids)->update(['manager_id' => $user->id]);
        }
        
        if ($role === 'teacher' && $request->filled('class_ids')) {
            \App\Models\SchoolClass::whereIn('id', $request->class_ids)->update(['teacher_id' => $user->id]);
        }

        return response()->json($user, 201);
    }

    public function update(Request $request, User $user)
    {
        $currentUser = $request->user();
        
        // Permission Check
        if (!$this->canManageRole($currentUser, $user->role)) {
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

        $user->update($request->only(['name', 'email']));
        
        if ($request->filled('password')) {
            $user->update(['password' => Hash::make($request->password)]);
        }

        // Update Assignments
        if (in_array($user->role, ['department_manager', 'manager'])) {
            if ($request->has('department_ids')) {
                // Clear previous assignments
                \App\Models\Department::where('manager_id', $user->id)->update(['manager_id' => null]);
                // Assign new departments
                if (!empty($request->department_ids)) {
                    \App\Models\Department::whereIn('id', $request->department_ids)->update(['manager_id' => $user->id]);
                }
            }
        }
        
        if ($user->role === 'teacher' && $request->has('class_ids')) {
            \App\Models\SchoolClass::where('teacher_id', $user->id)->update(['teacher_id' => null]);
            if (!empty($request->class_ids)) {
                \App\Models\SchoolClass::whereIn('id', $request->class_ids)->update(['teacher_id' => $user->id]);
            }
        }

        return response()->json($user);
    }
    
    public function destroy(Request $request, User $user)
    {
        $currentUser = $request->user();
        
        // Permission Check
        if (!$this->canManageRole($currentUser, $user->role)) {
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

        // Nullify foreign keys
        \App\Models\Department::where('manager_id', $user->id)->update(['manager_id' => null]);
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
            return in_array($targetRole, ['school_admin', 'department_manager', 'teacher']);
        }

        if (in_array($currentUser->role, ['department_manager', 'manager'])) {
            return $targetRole === 'teacher';
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
            return in_array($targetRole, ['department_manager', 'teacher']);
        }

        if (in_array($currentUser->role, ['department_manager', 'manager'])) {
            return $targetRole === 'teacher';
        }

        return false;
    }
}
