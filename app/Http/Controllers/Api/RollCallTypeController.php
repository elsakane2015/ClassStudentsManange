<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RollCallType;
use App\Models\SchoolClass;
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
        // Department manager sees all classes in their departments
        elseif ($user->role === 'department_manager') {
            $deptIds = $user->managedDepartments->pluck('id');
            $deptClassIds = SchoolClass::whereIn('department_id', $deptIds)
                ->where('is_graduated', false)
                ->pluck('id');
            
            if ($classId && !$deptClassIds->contains($classId)) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
            
            $query = RollCallType::whereIn('class_id', $classId ? [$classId] : $deptClassIds);
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
        
        $types = $query->with(['class', 'leaveType', 'creator'])
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
        
        return response()->json($types);
    }

    /**
     * Create a new roll call type
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'class_id' => 'required|exists:classes,id',
            'name' => 'required|string|max:100',
            'description' => 'nullable|string',
            'absent_status' => 'nullable|string|in:absent,late,leave',
            'leave_type_id' => 'nullable|exists:leave_types,id',
            'period_ids' => 'nullable|array',
            'period_ids.*' => 'integer',
            'sort_order' => 'nullable|integer',
            'is_active' => 'boolean',
        ]);

        // Verify access
        if ($user->role === 'teacher') {
            $ownsClass = $user->teacherClasses()->where('id', $validated['class_id'])->exists();
            if (!$ownsClass) {
                return response()->json(['error' => 'You do not manage this class'], 403);
            }
        } elseif ($user->role === 'department_manager') {
            $deptIds = $user->managedDepartments->pluck('id');
            $classInDept = SchoolClass::where('id', $validated['class_id'])
                ->whereIn('department_id', $deptIds)
                ->exists();
            if (!$classInDept) {
                return response()->json(['error' => '该班级不在您管辖的系部内'], 403);
            }
        } elseif (!in_array($user->role, ['admin', 'system_admin', 'school_admin'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $class = SchoolClass::find($validated['class_id']);
        $validated['school_id'] = $class->school_id;
        $validated['created_by'] = $user->id;

        $type = RollCallType::create($validated);
        return response()->json($type->load('class', 'leaveType'), 201);
    }

    /**
     * Batch create roll call types for multiple classes (department manager)
     */
    public function batchStore(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'class_ids' => 'required|array|min:1',
            'class_ids.*' => 'exists:classes,id',
            'name' => 'required|string|max:100',
            'description' => 'nullable|string',
            'absent_status' => 'nullable|string|in:absent,late,leave',
            'leave_type_id' => 'nullable|exists:leave_types,id',
            'period_ids' => 'nullable|array',
            'period_ids.*' => 'integer',
            'is_active' => 'boolean',
        ]);

        // Verify access - only department_manager and admins can batch create
        if ($user->role === 'department_manager') {
            $deptIds = $user->managedDepartments->pluck('id');
            $allowedClassIds = SchoolClass::whereIn('department_id', $deptIds)
                ->where('is_graduated', false)
                ->pluck('id')
                ->toArray();
            
            $invalidClasses = array_diff($validated['class_ids'], $allowedClassIds);
            if (!empty($invalidClasses)) {
                return response()->json(['error' => '部分班级不在您管辖的系部内'], 403);
            }
        } elseif (!in_array($user->role, ['admin', 'system_admin', 'school_admin'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $createdTypes = [];
        $skippedClasses = [];

        foreach ($validated['class_ids'] as $classId) {
            // Check if type with same name already exists for this class
            $existing = RollCallType::where('class_id', $classId)
                ->where('name', $validated['name'])
                ->first();
            
            if ($existing) {
                $class = SchoolClass::find($classId);
                $skippedClasses[] = $class->name;
                continue;
            }

            $class = SchoolClass::find($classId);
            $type = RollCallType::create([
                'class_id' => $classId,
                'school_id' => $class->school_id,
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'absent_status' => $validated['absent_status'] ?? 'absent',
                'leave_type_id' => $validated['leave_type_id'] ?? null,
                'period_ids' => $validated['period_ids'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
                'created_by' => $user->id,
            ]);
            $createdTypes[] = $type;
        }

        $message = '成功为 ' . count($createdTypes) . ' 个班级创建活动类型';
        if (!empty($skippedClasses)) {
            $message .= '，跳过 ' . count($skippedClasses) . ' 个已存在的班级';
        }

        return response()->json([
            'message' => $message,
            'created_count' => count($createdTypes),
            'skipped_classes' => $skippedClasses,
            'types' => $createdTypes,
        ], 201);
    }

    /**
     * Batch update roll call types - add/remove classes for a group
     */
    public function batchUpdate(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'old_name' => 'required|string|max:100',  // Original name to find existing types
            'name' => 'required|string|max:100',       // New name (can be same as old_name)
            'class_ids' => 'required|array',
            'class_ids.*' => 'exists:classes,id',
            'description' => 'nullable|string',
            'absent_status' => 'nullable|string|in:absent,late,leave',
            'leave_type_id' => 'nullable|exists:leave_types,id',
            'period_ids' => 'nullable|array',
            'period_ids.*' => 'integer',
            'is_active' => 'boolean',
        ]);

        // Verify access
        if ($user->role === 'department_manager') {
            $deptIds = $user->managedDepartments->pluck('id');
            $allowedClassIds = SchoolClass::whereIn('department_id', $deptIds)
                ->where('is_graduated', false)
                ->pluck('id')
                ->toArray();
            
            $invalidClasses = array_diff($validated['class_ids'], $allowedClassIds);
            if (!empty($invalidClasses)) {
                return response()->json(['error' => '部分班级不在您管辖的系部内'], 403);
            }
        } elseif (!in_array($user->role, ['admin', 'system_admin', 'school_admin'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Find existing types with the OLD name
        $existingTypes = RollCallType::where('name', $validated['old_name'])->get();
        $existingClassIds = $existingTypes->pluck('class_id')->toArray();
        
        // Classes to add (in new list but not in existing)
        $classesToAdd = array_diff($validated['class_ids'], $existingClassIds);
        
        // Classes to remove (in existing but not in new list)
        $classesToRemove = array_diff($existingClassIds, $validated['class_ids']);
        
        // Delete types for removed classes (use OLD name)
        if (!empty($classesToRemove)) {
            RollCallType::where('name', $validated['old_name'])
                ->whereIn('class_id', $classesToRemove)
                ->delete();
        }
        
        // Create types for new classes (use NEW name)
        $createdCount = 0;
        foreach ($classesToAdd as $classId) {
            $class = SchoolClass::find($classId);
            RollCallType::create([
                'class_id' => $classId,
                'school_id' => $class->school_id,
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'absent_status' => $validated['absent_status'] ?? 'absent',
                'leave_type_id' => $validated['leave_type_id'] ?? null,
                'period_ids' => $validated['period_ids'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
                'created_by' => $user->id,
            ]);
            $createdCount++;
        }
        
        // Update existing types with new data (update name too!)
        RollCallType::where('name', $validated['old_name'])
            ->whereIn('class_id', $validated['class_ids'])
            ->update([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'absent_status' => $validated['absent_status'] ?? 'absent',
                'leave_type_id' => $validated['leave_type_id'] ?? null,
                'period_ids' => $validated['period_ids'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
            ]);

        $renamed = $validated['old_name'] !== $validated['name'] ? "，已重命名为「{$validated['name']}」" : "";
        return response()->json([
            'message' => "更新成功：新增 {$createdCount} 个班级，移除 " . count($classesToRemove) . " 个班级{$renamed}",
            'added_count' => $createdCount,
            'removed_count' => count($classesToRemove),
        ]);
    }

    /**
     * Update a roll call type
     */
    public function update(Request $request, RollCallType $rollCallType)
    {
        $user = $request->user();
        
        // Verify access
        if ($user->role === 'teacher') {
            $ownsClass = $user->teacherClasses()->where('id', $rollCallType->class_id)->exists();
            if (!$ownsClass) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
        } elseif ($user->role === 'department_manager') {
            $deptIds = $user->managedDepartments->pluck('id');
            $classInDept = SchoolClass::where('id', $rollCallType->class_id)
                ->whereIn('department_id', $deptIds)
                ->exists();
            if (!$classInDept) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
        } elseif (!in_array($user->role, ['admin', 'system_admin', 'school_admin'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'description' => 'nullable|string',
            'absent_status' => 'nullable|string|in:absent,late,leave',
            'leave_type_id' => 'nullable|exists:leave_types,id',
            'period_ids' => 'nullable|array',
            'period_ids.*' => 'integer',
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
        } elseif ($user->role === 'department_manager') {
            $deptIds = $user->managedDepartments->pluck('id');
            $classInDept = SchoolClass::where('id', $rollCallType->class_id)
                ->whereIn('department_id', $deptIds)
                ->exists();
            if (!$classInDept) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
        } elseif (!in_array($user->role, ['admin', 'system_admin', 'school_admin'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $rollCallType->delete();
        return response()->json(['message' => 'Deleted']);
    }
}
