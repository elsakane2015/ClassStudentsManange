<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Imports\StudentsImport;
use App\Models\SchoolClass; // Ensure alias
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class StudentController extends Controller
{
    public function import(Request $request)
    {
        $request->validate([
            'class_id' => 'required|exists:classes,id',
            'file' => 'required|file|mimes:xlsx,csv',
        ]);

        $user = $request->user();
        if (!in_array($user->role, ['teacher', 'admin', 'manager'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $class = SchoolClass::find($request->class_id);
        
        // Authorization check: Teacher must own the class
        if ($user->role === 'teacher' && $class->teacher_id !== $user->id) {
             return response()->json(['error' => 'Not your class'], 403);
        }

        try {
            Excel::import(new StudentsImport($class->id, $class->school_id), $request->file('file'));
            return response()->json(['message' => 'Import successful']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Import failed: ' . $e->getMessage()], 500);
        }
    }
    
    public function template()
    {
        // Return a sample structure
        return response()->json([
            'headers' => ['name', 'student_no', 'parent_contact'],
            'example' => [
                ['name' => 'Zhang San', 'student_no' => '2024001', 'parent_contact' => '13800000000']
            ]
        ]);
        // Ideally return a real file download stream using Excel::download
    }
}
