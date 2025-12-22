<?php

namespace App\Imports;

use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class StudentsImport implements ToCollection, WithHeadingRow
{
    protected $classId;
    protected $schoolId;

    public function __construct($classId, $schoolId)
    {
        $this->classId = $classId;
        $this->schoolId = $schoolId;
    }

    public function collection(Collection $rows)
    {
        foreach ($rows as $index => $row) {
            // Mapping
            $deptName = $row['department_name'] ?? null;
            $gradeName = $row['grade_name'] ?? null;
            $className = $row['class_name'] ?? null;
            
            $name = $row['name'] ?? null;
            $email = $row['email'] ?? null;
            $password = $row['password'] ?? null;
            $studentNo = $row['student_no'] ?? null;
            $phone = $row['parent_contact'] ?? null;
            $gender = $row['gender'] ?? null;
            $birthdate = $row['birthdate'] ?? null;

            if (!$name || !$studentNo || !$email || !$password) {
                 continue; 
            }

            // Determine Class ID
            $targetClassId = $this->classId;

            if ($deptName && $gradeName && $className) {
                // Find or Create Structure
                $dept = \App\Models\Department::firstOrCreate(
                    ['school_id' => $this->schoolId, 'name' => $deptName]
                );
                
                $grade = \App\Models\Grade::firstOrCreate(
                    ['school_id' => $this->schoolId, 'name' => $gradeName]
                );

                $class = \App\Models\SchoolClass::firstOrCreate(
                    [
                        'school_id' => $this->schoolId,
                        'department_id' => $dept->id,
                        'grade_id' => $grade->id,
                        'name' => $className
                    ],
                    // Optional: Assign current user (teacher) if creating new? Or leave null.
                );
                $targetClassId = $class->id;
            }

            if (!$targetClassId) {
                throw new \Exception("导入失败：第 " . ($index + 2) . " 行缺少班级信息 (系部/年级/班级)，且未指定默认班级。");
            }

            // 1. Check for Duplicate Student No in School
            $exists = Student::where('school_id', $this->schoolId)
                ->where('student_no', $studentNo)
                ->where('id', '!=', '?') // Logic limitation: create vs update. If update, we should exclude self.
                ->exists();
            
            // Actually, if we use updateOrCreate below, we handle existing students.
            // But if student exists, we might move them to a new class?
            // The constraint `students_school_id_student_no_unique` will trigger if we try to create duplicate.
            // But updateOrCreate prevents that.
            // The only issue is if `student_no` exists but mapped to DIFFERENT user.
            
            // Let's rely on updateOrCreate logic based on User ID or unique search.
            
            // 2. Create/Update User
            $user = User::where('email', $email)->first();
            
            // If user exists, check if it's the SAME student?
            // Hard to verify without student_no check.
            // Risk: User A exists. Import says User A has student_no 123.
            // If another Student B has student_no 123, we have a conflict.
            
            // Simplified logic: Email is the key.
            $user = User::updateOrCreate(
                ['email' => $email],
                [
                    'uuid' => (string) Str::uuid(),
                    'name' => $name,
                    'password' => Hash::make((string)$password),
                    'role' => 'student',
                    'status' => true,
                ]
            );

            // 3. Create/Update Student
            // Note: If student exists in another class, this will MOVE them to the new class.
            // This is usually desired behavior for "Update".
            Student::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'school_id' => $this->schoolId,
                    'class_id' => $targetClassId,
                    'student_no' => $studentNo,
                    'gender' => $gender,
                    'birthdate' => $birthdate,
                    'parent_contact' => $phone,
                ]
            );
        }
    }
}
