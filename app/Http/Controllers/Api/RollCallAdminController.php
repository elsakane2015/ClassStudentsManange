<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RollCallAdmin;
use App\Models\Student;
use Illuminate\Http\Request;

class RollCallAdminController extends Controller
{
    /**
     * List roll call admins for a class
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $classId = $request->query('class_id');
        
        if (!$classId) {
            return response()->json(['error' => 'class_id is required'], 400);
        }

        // Verify access
        if ($user->role === 'teacher') {
            $ownsClass = $user->teacherClasses()->where('id', $classId)->exists();
            if (!$ownsClass) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
        } elseif (!in_array($user->role, ['admin', 'system_admin'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $admins = RollCallAdmin::where('class_id', $classId)
            ->with(['student.user', 'creator'])
            ->get()
            ->map(function ($admin) {
                $admin->authorized_types = $admin->authorizedTypes();
                return $admin;
            });
        
        return response()->json($admins);
    }

    /**
     * Create a new roll call admin
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'class_id' => 'required|exists:classes,id',
            'student_id' => 'required|exists:students,id',
            'roll_call_type_ids' => 'required|array',
            'roll_call_type_ids.*' => 'exists:roll_call_types,id',
        ]);

        // Verify teacher owns the class
        if ($user->role === 'teacher') {
            $ownsClass = $user->teacherClasses()->where('id', $validated['class_id'])->exists();
            if (!$ownsClass) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
        } elseif (!in_array($user->role, ['admin', 'system_admin'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Verify student belongs to the class
        $student = Student::find($validated['student_id']);
        if ($student->class_id != $validated['class_id']) {
            return response()->json(['error' => 'Student does not belong to this class'], 400);
        }

        // Check if already exists
        $existing = RollCallAdmin::where('class_id', $validated['class_id'])
            ->where('student_id', $validated['student_id'])
            ->first();

        if ($existing) {
            $existing->update([
                'roll_call_type_ids' => $validated['roll_call_type_ids'],
                'is_active' => true,
                'can_modify_records' => $request->boolean('can_modify_records', false),
            ]);
            return response()->json($existing->load('student.user'));
        }

        $validated['created_by'] = $user->id;
        $validated['is_active'] = true;
        $validated['can_modify_records'] = $request->boolean('can_modify_records', false);

        $admin = RollCallAdmin::create($validated);
        return response()->json($admin->load('student.user'), 201);
    }

    /**
     * Update a roll call admin
     */
    public function update(Request $request, RollCallAdmin $rollCallAdmin)
    {
        $user = $request->user();

        if ($user->role === 'teacher') {
            $ownsClass = $user->teacherClasses()->where('id', $rollCallAdmin->class_id)->exists();
            if (!$ownsClass) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
        } elseif (!in_array($user->role, ['admin', 'system_admin'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'roll_call_type_ids' => 'sometimes|array',
            'roll_call_type_ids.*' => 'exists:roll_call_types,id',
            'is_active' => 'sometimes|boolean',
            'can_modify_records' => 'sometimes|boolean',
        ]);

        $rollCallAdmin->update($validated);
        return response()->json($rollCallAdmin->load('student.user'));
    }

    /**
     * Delete a roll call admin
     */
    public function destroy(Request $request, RollCallAdmin $rollCallAdmin)
    {
        $user = $request->user();

        if ($user->role === 'teacher') {
            $ownsClass = $user->teacherClasses()->where('id', $rollCallAdmin->class_id)->exists();
            if (!$ownsClass) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
        } elseif (!in_array($user->role, ['admin', 'system_admin'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $rollCallAdmin->delete();
        return response()->json(['message' => 'Deleted']);
    }
}
