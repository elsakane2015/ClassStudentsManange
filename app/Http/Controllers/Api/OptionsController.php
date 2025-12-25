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
            // Use the many-to-many relationship
            $depts = $user->managedDepartments()->select('departments.id', 'departments.name')->get();
            return response()->json($depts);
        }
        
        return response()->json([]);
    }

    public function grades(Request $request)
    {
        return response()->json(Grade::select('id', 'name')->get());
    }

    public function classes(Request $request) 
    {
        $user = $request->user();
        $deptId = $request->query('department_id');
        $gradeId = $request->query('grade_id');
        $enrollmentYear = $request->query('enrollment_year');
        $includeGraduated = $request->query('include_graduated', false);

        $query = SchoolClass::query();
        
        // By default, exclude graduated classes
        if (!$includeGraduated) {
            $query->where('is_graduated', false);
        }

        // 1. Filter by hierarchy inputs if provided
        if ($deptId) $query->where('department_id', $deptId);
        if ($gradeId) $query->where('grade_id', $gradeId);
        if ($enrollmentYear) $query->where('enrollment_year', $enrollmentYear);

        // 2. Enforce Role Permissions
        if ($user->role === 'teacher') {
            // Teacher ONLY sees their own classes
            $query->where('teacher_id', $user->id);
        } elseif (in_array($user->role, ['department_manager', 'manager'])) {
            // Manager ONLY sees their department's classes (use many-to-many)
            $managedDeptIds = $user->managedDepartments()->pluck('departments.id');
            if ($deptId && !$managedDeptIds->contains($deptId)) {
                return response()->json([]);
            }
            if (!$deptId) {
                $query->whereIn('department_id', $managedDeptIds);
            }
        }
        // Admin sees all (filtered by inputs above)

        // Return with enrollment_year and department_id for frontend filtering
        return response()->json(
            $query->select('id', 'name', 'enrollment_year', 'department_id', 'is_graduated')->get()
        );
    }
}
