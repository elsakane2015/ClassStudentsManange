<?php

namespace Tests\Feature;

use App\Exports\StudentTemplateExport;
use App\Imports\StudentsImport;
use App\Models\Grade;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_can_batch_update_editable_student_fields(): void
    {
        [$teacher, $student] = $this->createTeacherAndStudent('one');
        Sanctum::actingAs($teacher);

        $response = $this->postJson('/api/students/bulk-update', [
            'students' => [[
                'id' => $student->id,
                'gender' => 'female',
                'is_boarding' => true,
                'email' => 'student.changed@example.com',
                'parent_contact' => '13900000000',
                'parent_email' => 'parent.changed@example.com',
            ]],
        ]);

        $response->assertOk()->assertJson([
            'message' => '学生信息修改已保存',
            'updated_count' => 1,
        ]);

        $student->refresh();
        $this->assertSame('female', $student->gender);
        $this->assertTrue($student->is_boarding);
        $this->assertSame('13900000000', $student->parent_contact);
        $this->assertSame('parent.changed@example.com', $student->parent_email);
        $this->assertSame('student.changed@example.com', $student->user->fresh()->email);

        $this->getJson('/api/students')
            ->assertOk()
            ->assertJsonPath('data.0.is_boarding', true)
            ->assertJsonPath('data.0.parent_email', 'parent.changed@example.com');
    }

    public function test_batch_update_is_rejected_atomically_for_another_teachers_student(): void
    {
        [$teacher, $ownedStudent] = $this->createTeacherAndStudent('owned');
        [, $otherStudent] = $this->createTeacherAndStudent('other');
        Sanctum::actingAs($teacher);

        $response = $this->postJson('/api/students/bulk-update', [
            'students' => [
                [
                    'id' => $ownedStudent->id,
                    'gender' => 'female',
                    'is_boarding' => true,
                    'email' => 'owned.changed@example.com',
                    'parent_contact' => '',
                    'parent_email' => '',
                ],
                [
                    'id' => $otherStudent->id,
                    'gender' => 'female',
                    'is_boarding' => true,
                    'email' => 'other.changed@example.com',
                    'parent_contact' => '',
                    'parent_email' => '',
                ],
            ],
        ]);

        $response->assertForbidden();
        $this->assertSame('male', $ownedStudent->fresh()->gender);
        $this->assertFalse($ownedStudent->fresh()->is_boarding);
        $this->assertSame('student.owned@example.com', $ownedStudent->user->fresh()->email);
    }

    public function test_student_cannot_use_single_record_edit_endpoint(): void
    {
        [, $student] = $this->createTeacherAndStudent('student-denied');
        Sanctum::actingAs($student->user);

        $this->putJson("/api/students/{$student->id}", [
            'gender' => 'female',
            'is_boarding' => true,
        ])->assertForbidden();

        $this->assertSame('male', $student->fresh()->gender);
        $this->assertFalse($student->fresh()->is_boarding);
    }

    public function test_import_template_and_import_include_parent_email_and_boarding_status(): void
    {
        $headings = (new StudentTemplateExport)->headings();
        $this->assertSame([
            '系部名称', '年级名称', '班级名称', '学生姓名', '账号邮箱', '初始密码',
            '学号', '性别', '出生日期', '家长联系方式', '家长邮箱', '是否住宿生',
        ], $headings);

        [$teacher, , $school, $class] = $this->createTeacherAndStudent('import-owner');

        $import = new StudentsImport($class->id, $school->id);
        $this->assertSame($headings, \Maatwebsite\Excel\Imports\HeadingRowFormatter::format($headings));

        $import->collection(collect([
            collect([
                'name' => '导入学生',
                'email' => 'imported.student@example.com',
                'password' => 'password123',
                'student_no' => 'IMPORT001',
                'gender' => 'male',
                'birthdate' => '2010-01-01',
                'parent_contact' => '13812345678',
                'parent_email' => 'imported.parent@example.com',
                'is_boarding' => '是',
            ]),
            collect([
                '学生姓名' => '中文模板学生',
                '账号邮箱' => 'chinese.template.student@example.com',
                '初始密码' => 'password123',
                '学号' => 'IMPORT002',
                '性别' => '女',
                '出生日期' => '2010-02-02',
                '家长联系方式' => '13912345678',
                '家长邮箱' => 'chinese.parent@example.com',
                '是否住宿生' => '否',
            ]),
        ]));

        $imported = Student::where('student_no', 'IMPORT001')->firstOrFail();
        $this->assertTrue($imported->is_boarding);
        $this->assertSame('imported.parent@example.com', $imported->parent_email);
        $this->assertSame($class->id, $imported->class_id);
        $this->assertSame($teacher->id, $imported->schoolClass->teacher_id);

        $chineseTemplateStudent = Student::where('student_no', 'IMPORT002')->firstOrFail();
        $this->assertSame('female', $chineseTemplateStudent->gender);
        $this->assertFalse($chineseTemplateStudent->is_boarding);
        $this->assertSame('chinese.parent@example.com', $chineseTemplateStudent->parent_email);
        $this->assertSame($class->id, $chineseTemplateStudent->class_id);
    }

    private function createTeacherAndStudent(string $suffix): array
    {
        $school = School::create(['name' => "测试学校-$suffix"]);
        $grade = Grade::create(['school_id' => $school->id, 'name' => '2026级']);
        $teacher = User::create([
            'uuid' => (string) Str::uuid(),
            'name' => "教师-$suffix",
            'email' => "teacher.$suffix@example.com",
            'password' => 'password',
            'role' => 'teacher',
            'status' => true,
        ]);
        $class = SchoolClass::create([
            'school_id' => $school->id,
            'grade_id' => $grade->id,
            'teacher_id' => $teacher->id,
            'name' => "班级-$suffix",
        ]);
        $studentUser = User::create([
            'uuid' => (string) Str::uuid(),
            'name' => "学生-$suffix",
            'email' => "student.$suffix@example.com",
            'password' => 'password',
            'role' => 'student',
            'status' => true,
        ]);
        $student = Student::create([
            'user_id' => $studentUser->id,
            'school_id' => $school->id,
            'class_id' => $class->id,
            'student_no' => "NO-$suffix",
            'gender' => 'male',
            'is_boarding' => false,
            'parent_contact' => '13800000000',
            'parent_email' => "parent.$suffix@example.com",
        ]);

        return [$teacher, $student, $school, $class];
    }
}
