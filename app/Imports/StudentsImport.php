<?php

namespace App\Imports;

use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

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
            $parentEmail = $row['parent_email'] ?? null;
            $gender = $row['gender'] ?? null;
            $isBoarding = $this->parseBoolean($row['is_boarding'] ?? false, $index);
            $birthdate = $row['birthdate'] ?? null;

            if (! $name || ! $studentNo || ! $email || ! $password) {
                continue;
            }

            if ($parentEmail && ! filter_var($parentEmail, FILTER_VALIDATE_EMAIL)) {
                throw new \Exception('导入失败：第 '.($index + 2).' 行家长邮箱格式不正确。');
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
                        'name' => $className,
                    ],
                    // Optional: Assign current user (teacher) if creating new? Or leave null.
                );
                $targetClassId = $class->id;
            }

            if (! $targetClassId) {
                throw new \Exception('导入失败：第 '.($index + 2).' 行缺少班级信息 (系部/年级/班级)，且未指定默认班级。');
            }

            // Email is the account key; an existing account updates its student profile.
            $user = User::updateOrCreate(
                ['email' => $email],
                [
                    'uuid' => (string) Str::uuid(),
                    'name' => $name,
                    'password' => Hash::make((string) $password),
                    'role' => 'student',
                    'status' => true,
                ]
            );

            Student::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'school_id' => $this->schoolId,
                    'class_id' => $targetClassId,
                    'student_no' => $studentNo,
                    'gender' => $gender,
                    'is_boarding' => $isBoarding,
                    'birthdate' => $birthdate,
                    'parent_contact' => $phone,
                    'parent_email' => $parentEmail,
                ]
            );
        }
    }

    private function parseBoolean(mixed $value, int $index): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));
        if (in_array($normalized, ['', '0', 'false', 'no', '否', '非住宿'], true)) {
            return false;
        }
        if (in_array($normalized, ['1', 'true', 'yes', '是', '住宿'], true)) {
            return true;
        }

        throw new \Exception('导入失败：第 '.($index + 2).' 行 is_boarding 应填写 1/0、是/否或 yes/no。');
    }
}
