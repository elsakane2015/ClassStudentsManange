<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'role' => 'sometimes|string|in:student,teacher,manager,admin', // Allow role for dev seeding
        ]);

        $user = User::create([
            'uuid' => (string) Str::uuid(),
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => $request->role ?? 'student',
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user,
        ]);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|string', // Can be email or student_id
            'password' => 'required',
            'device_name' => 'nullable|string|max:120',
        ]);

        $loginId = trim((string) $request->email);
        $user = null;

        // Try to find user by email first (case-insensitive across MySQL and SQLite)
        if (filter_var($loginId, FILTER_VALIDATE_EMAIL)) {
            $user = User::whereRaw('LOWER(email) = ?', [mb_strtolower($loginId)])->first();
        }

        // If not found by email, try to find by student_no (学号)
        if (!$user) {
            // Look up student by student_no, then get the associated user
            $student = \App\Models\Student::where('student_no', $loginId)->first();
            if ($student && $student->user_id) {
                $user = User::find($student->user_id);
            }
        }

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['账号或密码错误'],
            ]);
        }

        // Check if account is disabled (e.g. parent account detached from all students)
        if (! $user->status) {
            return response()->json([
                'message' => '该账号已停用或已解除关联，如有疑问请联系班主任或管理员。'
            ], 403);
        }

        // Check if student's class is graduated (archived)
        if ($user->role === 'student') {
            $student = $user->student;
            if ($student && $student->schoolClass && $student->schoolClass->is_graduated) {
                return response()->json([
                    'message' => '您的班级已毕业，账号已归档，无法登录。如有问题请联系管理员。'
                ], 403);
            }
        }

        // Check parent linked children status
        if ($user->role === 'parent') {
            $linkedChildren = $user->children()->with('schoolClass')->get();
            if ($linkedChildren->isEmpty()) {
                return response()->json([
                    'message' => '该家长账号尚未关联在校学生，无法登录。请联系班主任确认家长邮箱。'
                ], 403);
            }

            $allGraduated = $linkedChildren->every(
                fn ($child) => (bool) ($child->schoolClass?->is_graduated ?? false)
            );
            if ($allGraduated) {
                return response()->json([
                    'message' => '您关联的学生班级已全部毕业归档，无法登录。如有问题请联系管理员。'
                ], 403);
            }
        }

        // Optional: Revoke old tokens if single session policy
        // $user->tokens()->delete();

        $tokenName = trim((string) $request->input('device_name', 'auth_token')) ?: 'auth_token';
        $token = $user->createToken($tokenName)->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully']);
    }

    public function me(Request $request)
    {
        $user = $request->user()->load(['student', 'dutyDepartments:id,name']);
        
        // Check if student is a roll call admin
        $isRollCallAdmin = false;
        if ($user->student) {
            $isRollCallAdmin = \App\Models\RollCallAdmin::where('student_id', $user->student->id)
                ->where('is_active', true)
                ->exists();
        }
        
        $userData = $user->toArray();
        if ($user->student) {
            $userData['student']['is_roll_call_admin'] = $isRollCallAdmin;
        }
        
        $unreadNotificationsCount = 0;
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('app_notifications')) {
                $unreadNotificationsCount = $user->appNotifications()->whereNull('read_at')->count();
            }
        } catch (\Throwable $e) {
            $unreadNotificationsCount = 0;
        }

        $parentChildren = [];
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('parent_student')) {
                $parentChildren = $user->children()
                    ->with(['user:id,name,email', 'schoolClass.department:id,name', 'schoolClass.teacher:id,name'])
                    ->orderBy('students.id')
                    ->get()
                    ->map(fn ($child) => \App\Http\Controllers\Api\ParentPortalController::formatChildSummary($child))
                    ->values()
                    ->all();
            }
        } catch (\Throwable $e) {
            $parentChildren = [];
        }

        return response()->json([
            ...$userData,
            'permissions' => $user->getPermissions(),
            'unread_notifications_count' => $unreadNotificationsCount,
            'parent_children' => $parentChildren,
            'has_parent_children' => count($parentChildren) > 0,
        ]);
    }

    /**
     * Change password for authenticated user
     */
    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:6|confirmed',
        ], [
            'current_password.required' => '请输入当前密码',
            'new_password.required' => '请输入新密码',
            'new_password.min' => '新密码至少需要6位',
            'new_password.confirmed' => '两次输入的新密码不一致',
        ]);

        $user = $request->user();

        // Verify current password
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json(['error' => '当前密码错误'], 422);
        }

        // Update password
        $user->password = Hash::make($request->new_password);
        $user->save();

        return response()->json(['message' => '密码修改成功']);
    }
}
