<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\SchoolClass;
use App\Models\User;

class SchoolClassController extends Controller
{
    // Admin Management for Classes
    
    public function index(Request $request)
    {
        // Admin sees all classes with their teachers and departments
        if (!in_array($request->user()->role, ['system_admin', 'school_admin', 'admin'])) return response()->json(['error' => 'Unauthorized'], 403);
        
        return response()->json(SchoolClass::with(['grade', 'department', 'teacher'])->get());
    }

    public function store(Request $request)
    {
        if (!in_array($request->user()->role, ['system_admin', 'school_admin', 'admin'])) return response()->json(['error' => 'Unauthorized'], 403);
        
        $validated = $request->validate([
            'name' => 'required|string',
            'school_id' => 'required|exists:schools,id',
            'grade_id' => 'nullable|exists:grades,id',  // Changed from required to nullable
            'enrollment_year' => 'nullable|integer|min:2000|max:' . (date('Y') + 1),  // Added enrollment_year
            'department_id' => 'nullable|exists:departments,id',
            'teacher_id' => 'nullable|exists:users,id', // User ID of the teacher
        ]);
        
        // Verify teacher role? Optional but good.
        if (!empty($validated['teacher_id'])) {
             $teacher = User::find($validated['teacher_id']);
             if ($teacher && $teacher->role !== 'teacher') {
                 // Or we could auto-promote? Let's check strict for now.
                 // return response()->json(['error' => 'Selected user is not a teacher'], 422);
             }
        }

        $class = SchoolClass::create($validated);
        return response()->json($class, 201);
    }

    public function update(Request $request, $id)
    {
        if (!in_array($request->user()->role, ['system_admin', 'school_admin', 'admin'])) return response()->json(['error' => 'Unauthorized'], 403);
        
        $schoolClass = SchoolClass::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string',
            'grade_id' => 'sometimes|exists:grades,id', // Added grade_id
            'enrollment_year' => 'nullable|integer|min:2000|max:' . (date('Y') + 1),  // Added enrollment_year
            'department_id' => 'nullable|exists:departments,id',
            'teacher_id' => 'nullable|exists:users,id',
        ]);
        
        // Assign Teacher logic is implicitly handled by updating 'teacher_id'
        
        $schoolClass->update($validated);
        return response()->json($schoolClass);
    }

    public function destroy(Request $request, $id)
    {
        if (!in_array($request->user()->role, ['system_admin', 'school_admin', 'admin'])) return response()->json(['error' => 'Unauthorized'], 403);
        $schoolClass = SchoolClass::findOrFail($id);
        $schoolClass->delete();
        return response()->json(['message' => 'Deleted']);
    }

    public function availableTeachers(Request $request)
    {
        if (!in_array($request->user()->role, ['system_admin', 'school_admin', 'admin'])) return response()->json(['error' => 'Unauthorized'], 403);
        $teachers = User::where('role', 'teacher')->select('id', 'name', 'email')->get();
        return response()->json($teachers);
    }

    /**
     * Toggle graduated status of a class
     */
    public function toggleGraduated(Request $request, $id)
    {
        if (!in_array($request->user()->role, ['system_admin', 'school_admin', 'admin'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        
        $schoolClass = SchoolClass::findOrFail($id);
        
        $schoolClass->is_graduated = !$schoolClass->is_graduated;
        $schoolClass->graduated_at = $schoolClass->is_graduated ? now() : null;
        $schoolClass->save();
        
        $status = $schoolClass->is_graduated ? '已毕业' : '在读';
        
        return response()->json([
            'message' => "班级 {$schoolClass->name} 状态已更新为: {$status}",
            'is_graduated' => $schoolClass->is_graduated,
            'graduated_at' => $schoolClass->graduated_at,
        ]);
    }
}
