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
        foreach ($rows as $row) {
            // Keys depend on Excel header: Name, Student No, Parent Phone
            // normalized to snake_case usually if using WithHeadingRow
            
            $name = $row['name'] ?? null;
            $studentNo = $row['student_no'] ?? null;
            $phone = $row['parent_contact'] ?? null;

            if (!$name || !$studentNo) continue;

            // Create User
            // Default Password = student_no
            $user = User::create([
                'uuid' => (string) Str::uuid(),
                'name' => $name,
                'email' => $studentNo . '@school.com', // Fake email for login? OR use student_no as login (but auth uses email). 
                // Suggestion: Generate a fake email or require email in excel.
                // Let's use student_no@system as placeholder
                'password' => Hash::make((string)$studentNo),
                'role' => 'student',
                'status' => true,
            ]);

            // Create Student
            Student::create([
                'user_id' => $user->id,
                'school_id' => $this->schoolId,
                'class_id' => $this->classId,
                'student_no' => $studentNo,
                'parent_contact' => $phone,
            ]);
        }
    }
}
