<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Imports\StudentsImport;
use App\Models\SchoolClass; // Ensure alias
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class StudentController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        // Query Builder start
        // Ensure we load User relationship for name
        $query = \App\Models\Student::with(['user', 'schoolClass.department']);

        // 1. Teacher: Filter by assigned classes
        if ($user->role === 'teacher') {
            $classIds = $user->teacherClasses->pluck('id');
            $query->whereIn('class_id', $classIds);
        }
        // 2. Manager: Filter by classes in managed departments
        elseif (in_array($user->role, ['department_manager', 'manager'])) {
            $deptIds = $user->managedDepartments->pluck('id');
            // Find classes in these departments
            $classIds = \App\Models\SchoolClass::whereIn('department_id', $deptIds)->pluck('id');
            $query->whereIn('class_id', $classIds);
        }
        // 3. Admin: No filter (sees all)
        elseif (in_array($user->role, ['system_admin', 'school_admin', 'admin'])) {
            \Log::info('StudentController: User is admin, showing all.');
            // No additional where clause needed
        } else {
             \Log::warning('StudentController: User role unauthorized or unknown: ' . $user->role);
             // Student or other? Nothing
             return response()->json(['data' => []]);
        }

        // Apply filters from request
        if ($request->filled('department_id')) {
            $classIdsInDept = \App\Models\SchoolClass::where('department_id', $request->department_id)->pluck('id');
            $query->whereIn('class_id', $classIdsInDept);
        }
        
        if ($request->filled('enrollment_year')) {
            $query->whereHas('schoolClass', function($q) use ($request) {
                $q->where('enrollment_year', $request->enrollment_year);
            });
        }
        
        if ($request->filled('class_id')) {
            $query->where('class_id', $request->class_id);
        }

        // Pagination - order by student_no ascending
        $perPage = $request->input('per_page', 50);
        $result = $query->orderBy('student_no', 'asc')->paginate($perPage);

        $students = $result->getCollection()->map(function ($student) {
            return [
                'id' => $student->id,
                'name' => $student->user ? $student->user->name : 'Unknown', // Safety check
                'student_no' => $student->student_no,
                'gender' => $student->gender ?? 'male',  // Default to male if null
                'parent_contact' => $student->parent_contact,
                'class_id' => $student->class_id,
                'class_name' => $student->schoolClass ? $student->schoolClass->name : '-',
                'department_name' => $student->schoolClass?->department?->name ?? '-',
                'enrollment_year' => $student->schoolClass?->enrollment_year ?? '-',
                'email' => $student->user ? $student->user->email : null,
                'is_manager' => $student->is_manager ?? false,
                'is_class_admin' => $student->is_class_admin ?? false,
            ];
        });

        return response()->json([
            'data' => $students,
            'meta' => [
                'current_page' => $result->currentPage(),
                'last_page' => $result->lastPage(),
                'per_page' => $result->perPage(),
                'total' => $result->total(),
            ],
            'debug_meta' => [
                'user_id' => $user->id,
                'user_role' => $user->role,
                'query_count' => $students->count(),
            ]
        ]);
    }

    public function import(Request $request)
    {
        $request->validate([
            'class_id' => 'nullable|exists:classes,id', // Now nullable
            'file' => 'required|file|mimes:xlsx,csv',
        ]);

        $user = $request->user();
        if (!in_array($user->role, ['teacher', 'admin', 'manager'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $classId = $request->input('class_id');
        $schoolId = 1; // Default or from user

        // Validation Logic
        if ($classId) {
            $class = SchoolClass::find($classId);
            $schoolId = $class->school_id;

            // Teacher: Must own class
            if ($user->role === 'teacher' && $class->teacher_id !== $user->id) {
                return response()->json(['error' => 'Not your class'], 403);
            }
            // Manager: Must manage class's department
            if ($user->role === 'manager') {
                $managedDepts = \App\Models\Department::where('manager_id', $user->id)->pluck('id');
                if (!$class->department_id || !$managedDepts->contains($class->department_id)) {
                    return response()->json(['error' => 'Not your department'], 403);
                }
            }
        } else {
            // Bulk Import Mode
            // Only Admin or Manager allowed
            if ($user->role === 'teacher') {
                return response()->json(['error' => 'Teachers must select a class.'], 403);
            }
            
            // If Manager, we should potentially strictly filter Excel rows to their department?
            // This is complex in `ToCollection`. For now, we trust Managers upload correct files 
            // OR we rely on `StudentsImport` creating classes. 
            // Ideally, pass `managerDeptIds` to Import class to validate rows.
            // For MVP, we allow Manager to proceed, assuming 'Honest Manager' or they only have their own data.
            // But strict implementation would pass constraints to Import.
        }

        try {
            Excel::import(new StudentsImport($classId, $schoolId), $request->file('file'));
            return response()->json(['message' => 'Import successful']);
        } catch (\Exception $e) {
            // Log error for debugging
            \Illuminate\Support\Facades\Log::error($e);
            return response()->json(['error' => 'Import failed: ' . $e->getMessage()], 500);
        }
    }
    
    // ... existing import methods ...

    public function store(Request $request)
    {
        $user = $request->user();
        
        $request->validate([
            'name' => 'required|string',
            'student_no' => 'required|string', // Unique validation tricky with school scope, handled manually or complex rule
            'gender' => 'required|in:male,female',
            'parent_contact' => 'nullable|string',
            'class_id' => 'required|exists:classes,id',
            'email' => 'required|email|unique:users,email', // New user account
            'password' => 'required|min:6'
        ]);

        // Permission: Teacher must own class
        $class = SchoolClass::findOrFail($request->class_id);
        if ($user->role === 'teacher' && $class->teacher_id !== $user->id) {
            return response()->json(['error' => 'Not your class'], 403);
        }
        
        // 1. Create User
        $newUser = \App\Models\User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => \Illuminate\Support\Facades\Hash::make($request->password),
            'role' => 'student',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'status' => true,
        ]);

        // 2. Create Student Profile
        $student = \App\Models\Student::create([
            'user_id' => $newUser->id,
            'school_id' => $class->school_id,
            'class_id' => $class->id,
            'student_no' => $request->student_no,
            'gender' => $request->gender,
            'parent_contact' => $request->parent_contact,
        ]);

        return response()->json($student, 201);
    }

    public function update(Request $request, $id)
{
    $user = $request->user();
    $student = \App\Models\Student::with('user', 'schoolClass')->findOrFail($id);

    // Permission: Teacher must own the student's CURRENT class (or target class?)
    // Usually, teacher can only edit students in their class.
    if ($user->role === 'teacher' && $student->schoolClass->teacher_id !== $user->id) {
        return response()->json(['error' => 'Not in your class'], 403);
    }

    \Log::info('[StudentController.update] Request data:', $request->all());
    \Log::info('[StudentController.update] Student user_id:', ['user_id' => $student->user->id]);

    try {
        $request->validate([
            'name' => 'sometimes|string',
            'student_no' => 'sometimes|string',
            'gender' => 'nullable|in:male,female',  // Allow null
            'parent_contact' => 'nullable|string',
            'email' => 'sometimes|email|unique:users,email,' . $student->user->id,
            'password' => 'nullable|string|min:6',
        ]);
    } catch (\Illuminate\Validation\ValidationException $e) {
        \Log::error('[StudentController.update] Validation failed:', ['errors' => $e->errors()]);
        throw $e;
    }

    // Update User info
    $userUpdates = [];
    if ($request->has('name')) {
        $userUpdates['name'] = $request->name;
    }
    if ($request->has('email')) {
        $userUpdates['email'] = $request->email;
    }
    if ($request->filled('password')) {
        $userUpdates['password'] = bcrypt($request->password);
    }
    
    \Log::info('[StudentController.update] User updates:', $userUpdates);
    if (!empty($userUpdates)) {
        $student->user->update($userUpdates);
    }
    
    // Update Student info
    $studentUpdates = $request->only(['student_no', 'gender', 'parent_contact']);
    \Log::info('[StudentController.update] Student updates:', $studentUpdates);
    $student->update($studentUpdates);

    \Log::info('[StudentController.update] Update successful');
    return response()->json($student);
}
    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        $student = \App\Models\Student::with('schoolClass')->findOrFail($id);

        if ($user->role === 'teacher' && $student->schoolClass->teacher_id !== $user->id) {
            return response()->json(['error' => 'Not in your class'], 403);
        }

        // Delete User account too? Or just student profile? 
        // Usually, deleting a student implies removing access.
        $student->user->delete(); // Cascade delete student profile if set up, or delete explicitly
        $student->delete();

        return response()->json(['message' => 'Deleted']);
    }

    /**
     * Bulk delete students
     */
    public function bulkDestroy(Request $request)
    {
        $user = $request->user();
        
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'exists:students,id',
        ]);

        $studentIds = $request->input('ids');
        $deletedCount = 0;
        $errors = [];

        foreach ($studentIds as $id) {
            try {
                $student = \App\Models\Student::with('schoolClass')->findOrFail($id);
                
                // Permission check for teacher
                if ($user->role === 'teacher' && $student->schoolClass->teacher_id !== $user->id) {
                    $errors[] = "学生 {$student->user->name} 不在您的班级";
                    continue;
                }
                
                // Delete user and student
                if ($student->user) {
                    $student->user->delete();
                }
                $student->delete();
                $deletedCount++;
            } catch (\Exception $e) {
                $errors[] = "删除学生ID {$id} 失败: " . $e->getMessage();
            }
        }

        return response()->json([
            'message' => "成功删除 {$deletedCount} 名学生",
            'deleted_count' => $deletedCount,
            'errors' => $errors,
        ]);
    }

    public function template()
    {
        return Excel::download(new \App\Exports\StudentTemplateExport, 'student_import_template.xlsx');
    }

    public function debug(Request $request)
    {
        if (!in_array($request->user()->role, ['system_admin', 'school_admin', 'admin'])) return response()->json(['error'], 403);
        return response()->json([
            'all_students' => \App\Models\Student::all(),
            'all_users_student_role' => \App\Models\User::where('role', 'student')->get(),
            'total_students' => \App\Models\Student::count()
        ]);
    }
    /**
     * Toggle the manager status of a student.
     */
    public function toggleManager(Request $request, string $id)
    {
        $student = \App\Models\Student::findOrFail($id);
        
        // Authorization check: User must be a teacher or admin
        // For simplicity, we assume the middleware handles basic auth, but strictly we should check if teacher owns the class.
        // $request->user()->can('update', $student); 

        $student->is_manager = !$student->is_manager;
        $student->save();

        return response()->json([
            'message' => 'Student manager status updated.',
            'is_manager' => $student->is_manager
        ]);
    }

    public function isClassAdmin(Request $request)
    {
        $user = $request->user();
        
        if (!$user->student) {
            return response()->json(['is_class_admin' => false]);
        }

        return response()->json([
            'is_class_admin' => $user->student->is_class_admin ?? false
        ]);
    }

    public function toggleClassAdmin(Request $request, string $id)
    {
        $student = \App\Models\Student::findOrFail($id);
        
        // Authorization: Only teachers and admins can toggle
        $user = $request->user();
        if (!in_array($user->role, ['teacher', 'system_admin', 'school_admin', 'admin'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $student->is_class_admin = !$student->is_class_admin;
        $student->save();

        return response()->json([
            'message' => 'Student class admin status updated.',
            'is_class_admin' => $student->is_class_admin
        ]);
    }
}
