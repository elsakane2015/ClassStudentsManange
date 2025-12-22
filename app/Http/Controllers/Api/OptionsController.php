<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Department;
use App\Models\Grade;
use App\Models\SchoolClass;

class OptionsController extends Controller
{
    public function departments(Request $request)
    {
        $user = $request->user();
        
        if (in_array($user->role, ['system_admin', 'school_admin', 'admin'])) {
            return response()->json(Department::select('id', 'name')->get());
        }
        
        if (in_array($user->role, ['department_manager', 'manager'])) {
            // Assuming Manager manages the department linked by manager_id
            // Or if user->student->department_id? User case says "系管理员". 
            // Let's assume departments.manager_id == user.id
            $depts = Department::where('manager_id', $user->id)->select('id', 'name')->get();
            return response()->json($depts);
        }
        
        // Teachers usually don't select Departments directly for import unless filtering classes
        // But for "Locking", if teacher belongs to a Dept, show that one.
        // For now, return empty or all? Safe to return empty for others.
        return response()->json([]);
    }

    public function grades(Request $request)
    {
        // Grades are generally public info within school
        return response()->json(Grade::select('id', 'name')->get());
    }

    public function classes(Request $request) 
{
    $user = $request->user();
    $deptId = $request->query('department_id');
    $gradeId = $request->query('grade_id');
    $enrollmentYear = $request->query('enrollment_year');

    $query = SchoolClass::query();

    // 1. Filter by hierarchy inputs if provided
    if ($deptId) $query->where('department_id', $deptId);
    if ($gradeId) $query->where('grade_id', $gradeId);
    if ($enrollmentYear) $query->where('enrollment_year', $enrollmentYear);

    // 2. Enforce Role Permissions
    if ($user->role === 'teacher') {
        // Teacher ONLY sees their own classes
        $query->where('teacher_id', $user->id);
    } elseif ($user->role === 'manager') {
        // Manager ONLY sees their department's classes
        // Get depts managed by user
        $managedDeptIds = Department::where('manager_id', $user->id)->pluck('id');
        // If the requested deptId is not in managed list, forbid or return empty
        if ($deptId && !$managedDeptIds->contains($deptId)) {
            return response()->json([]);
        }
        if (!$deptId) {
            $query->whereIn('department_id', $managedDeptIds);
        }
    }
    // Admin sees all (filtered by inputs above)

    return response()->json($query->select('id', 'name')->get());
}
}
