<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RollCallType;
use Illuminate\Http\Request;

class RollCallTypeController extends Controller
{
    /**
     * List roll call types for a class
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $classId = $request->query('class_id');
        
        // Teacher sees their classes
        if ($user->role === 'teacher') {
            $classIds = $user->teacherClasses->pluck('id');
            if ($classId && !$classIds->contains($classId)) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
            
            $query = RollCallType::whereIn('class_id', $classId ? [$classId] : $classIds);
        } 
        // Student roll call admin sees their authorized types
        elseif ($user->role === 'student' && $user->student) {
            $admin = \App\Models\RollCallAdmin::where('student_id', $user->student->id)
                ->where('is_active', true)
                ->first();
            
            if (!$admin) {
                return response()->json([]);
            }
            
            $query = RollCallType::whereIn('id', $admin->roll_call_type_ids ?? []);
        }
        // Admin sees all
        elseif (in_array($user->role, ['admin', 'system_admin', 'school_admin'])) {
            $query = RollCallType::query();
            if ($classId) {
                $query->where('class_id', $classId);
            }
        }
        else {
            return response()->json([]);
        }
        
        $types = $query->with(['class', 'leaveType'])
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
        
        return response()->json($types);
    }

    /**
     * Create a new roll call type (teacher only)
     */
    public function store(Request $request)
    {
        $user = $request->user();
        
        if ($user->role !== 'teacher' && !in_array($user->role, ['admin', 'system_admin'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'class_id' => 'required|exists:classes,id',
            'name' => 'required|string|max:100',
            'description' => 'nullable|string',
            'absent_status' => 'nullable|string|in:absent,late,leave',
            'leave_type_id' => 'nullable|exists:leave_types,id',
            'sort_order' => 'nullable|integer',
            'is_active' => 'boolean',
        ]);

        // Verify teacher owns the class
        if ($user->role === 'teacher') {
            $ownsClass = $user->teacherClasses()->where('id', $validated['class_id'])->exists();
            if (!$ownsClass) {
                return response()->json(['error' => 'You do not manage this class'], 403);
            }
        }

        $class = \App\Models\SchoolClass::find($validated['class_id']);
        $validated['school_id'] = $class->school_id;
        $validated['created_by'] = $user->id;

        $type = RollCallType::create($validated);
        return response()->json($type->load('class', 'leaveType'), 201);
    }

    /**
     * Update a roll call type
     */
    public function update(Request $request, RollCallType $rollCallType)
    {
        $user = $request->user();
        
        // Verify ownership
        if ($user->role === 'teacher') {
            $ownsClass = $user->teacherClasses()->where('id', $rollCallType->class_id)->exists();
            if (!$ownsClass) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
        } elseif (!in_array($user->role, ['admin', 'system_admin'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'description' => 'nullable|string',
            'absent_status' => 'nullable|string|in:absent,late,leave',
            'leave_type_id' => 'nullable|exists:leave_types,id',
            'sort_order' => 'nullable|integer',
            'is_active' => 'boolean',
        ]);

        $rollCallType->update($validated);
        return response()->json($rollCallType->load('class', 'leaveType'));
    }

    /**
     * Delete a roll call type
     */
    public function destroy(Request $request, RollCallType $rollCallType)
    {
        $user = $request->user();
        
        if ($user->role === 'teacher') {
            $ownsClass = $user->teacherClasses()->where('id', $rollCallType->class_id)->exists();
            if (!$ownsClass) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
        } elseif (!in_array($user->role, ['admin', 'system_admin'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $rollCallType->delete();
        return response()->json(['message' => 'Deleted']);
    }
}
