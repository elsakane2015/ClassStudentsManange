<?php

namespace App\Imports;

use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Imports\HeadingRowFormatter;

class StudentsImport implements ToCollection, WithHeadingRow
{
    private const COLUMN_ALIASES = [
        'department_name' => ['department_name', '系部名称', '系部'],
        'grade_name' => ['grade_name', '年级名称', '年级'],
        'class_name' => ['class_name', '班级名称', '班级'],
        'name' => ['name', '学生姓名', '姓名'],
        'email' => ['email', '账号邮箱', '账号'],
        'password' => ['password', '初始密码', '密码'],
        'student_no' => ['student_no', '学号'],
        'gender' => ['gender', '性别'],
        'birthdate' => ['birthdate', '出生日期'],
        'parent_contact' => ['parent_contact', '家长联系方式', '家长联系电话'],
        'parent_email' => ['parent_email', '家长邮箱'],
        'is_boarding' => ['is_boarding', '是否住宿生', '住宿生'],
    ];

    protected $classId;

    protected $schoolId;

    public function __construct($classId, $schoolId)
    {
        $this->classId = $classId;
        $this->schoolId = $schoolId;

        // The default slug formatter drops Chinese headings entirely.
        HeadingRowFormatter::default(HeadingRowFormatter::FORMATTER_NONE);
    }

    public function collection(Collection $rows)
    {
        foreach ($rows as $index => $row) {
            // Mapping
            $deptName = $this->value($row, 'department_name');
            $gradeName = $this->value($row, 'grade_name');
            $className = $this->value($row, 'class_name');

            $name = $this->value($row, 'name');
            $email = $this->value($row, 'email');
            $password = $this->value($row, 'password');
            $studentNo = $this->value($row, 'student_no');
            $phone = $this->value($row, 'parent_contact');
            $parentEmail = $this->value($row, 'parent_email');
            $gender = $this->parseGender($this->value($row, 'gender'), $index);
            $isBoarding = $this->parseBoolean($this->value($row, 'is_boarding', false), $index);
            $birthdate = $this->value($row, 'birthdate');

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

    private function value(Collection $row, string $column, mixed $default = null): mixed
    {
        foreach (self::COLUMN_ALIASES[$column] as $alias) {
            if ($row->has($alias)) {
                return $row->get($alias);
            }
        }

        return $default;
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

        throw new \Exception('导入失败：第 '.($index + 2).' 行“是否住宿生”应填写 1/0、是/否或 yes/no。');
    }

    private function parseGender(mixed $value, int $index): ?string
    {
        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return null;
        }
        if (in_array($normalized, ['male', '男'], true)) {
            return 'male';
        }
        if (in_array($normalized, ['female', '女'], true)) {
            return 'female';
        }
        if (in_array($normalized, ['other', '其他'], true)) {
            return 'other';
        }

        throw new \Exception('导入失败：第 '.($index + 2).' 行“性别”应填写男/女/其他或 male/female/other。');
    }
}
