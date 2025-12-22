<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Semester;

class SemesterController extends Controller
{
    public function index(Request $request)
    {
        // Admin sees all? Or scoped to school?
        // Assuming current user is associated with a school via Student/Teacher profile?
        // But Admin might not have a student profile.
        // Let's assume request user has a school_id if they are manager/admin.
        // For now, return all for simplicity or filter by school if present.
        
        $semesters = Semester::latest()->get();
        return response()->json($semesters);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (!in_array($user->role, ['system_admin', 'school_admin', 'admin'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string',
            'start_date' => 'required|date',
            'total_weeks' => 'required|integer|min:1',
            'holidays' => 'nullable|array', // Added
            'is_current' => 'boolean'
        ]);

        // Get School ID. Admin might manage multiple? Or single tenant?
        // Let's assume 1 for now or from user.
        // If user has a student/teacher record, use that school.
        // If pure admin user, maybe pass school_id?
        $schoolId = 1; 
        if ($user->student) $schoolId = $user->student->school_id;
        elseif ($user->teacherClasses()->exists()) $schoolId = $user->teacherClasses()->first()->school_id;
        // else default 1.

        if ($validated['is_current'] ?? false) {
             Semester::where('school_id', $schoolId)->update(['is_current' => false]);
        }

        $semester = Semester::create(array_merge($validated, ['school_id' => $schoolId]));

        return response()->json($semester, 201);
    }

    public function update(Request $request, Semester $semester)
    {
        $user = $request->user();
        if (!in_array($user->role, ['system_admin', 'school_admin', 'admin'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string',
            'start_date' => 'sometimes|date',
            'total_weeks' => 'sometimes|integer|min:1',
            'holidays' => 'nullable|array', // Added
            'is_current' => 'boolean'
        ]);

        if ($validated['is_current'] ?? false) {
             Semester::where('school_id', $semester->school_id)->where('id', '!=', $semester->id)->update(['is_current' => false]);
        }

        $semester->update($validated);

        return response()->json($semester);
    }
    
    public function destroy(Request $request, Semester $semester)
    {
        $user = $request->user();
        if (!in_array($user->role, ['system_admin', 'school_admin', 'admin'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        
        $semester->delete();
        return response()->json(['message' => 'Deleted']);
    }
}
