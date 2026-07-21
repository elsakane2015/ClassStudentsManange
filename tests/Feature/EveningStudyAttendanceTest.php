<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\BoardingSuspension;
use App\Models\Department;
use App\Models\EveningStudyStatus;
use App\Models\Grade;
use App\Models\LeaveType;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EveningStudyAttendanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_duty_teacher_only_calls_boarding_students_and_sees_suspension(): void
    {
        $data = $this->schoolData('call');
        $dutyTeacher = $this->user('duty.call@example.com', 'duty_teacher');
        $dutyTeacher->dutyDepartments()->attach($data['department']->id);

        $boarding = $this->student($data, 'boarding', true);
        $dayStudent = $this->student($data, 'day', false);
        $suspended = $this->student($data, 'suspended', true);
        $creator = $this->user('teacher.suspension@example.com', 'teacher');
        BoardingSuspension::create([
            'student_id' => $suspended->id,
            'school_id' => $data['school']->id,
            'class_id' => $data['class']->id,
            'start_date' => '2026-07-21',
            'end_date' => '2026-07-22',
            'reason' => '临时回家',
            'destination' => '家中',
            'created_by' => $creator->id,
        ]);

        Sanctum::actingAs($dutyTeacher);
        $response = $this->postJson('/api/evening-study/sessions', [
            'date' => '2026-07-21',
            'period_id' => 10,
            'class_id' => $data['class']->id,
        ])->assertOk();

        $response->assertJsonCount(2, 'records');
        $studentIds = collect($response->json('records'))->pluck('student_id');
        $this->assertTrue($studentIds->contains($boarding->id));
        $this->assertTrue($studentIds->contains($suspended->id));
        $this->assertFalse($studentIds->contains($dayStudent->id));
        $response->assertJsonPath(
            'records.1.evening_study_status.name',
            '暂停住宿'
        );

        $this->assertSame(0, AttendanceRecord::count());
        $this->assertSame(2, AttendanceRecord::withoutGlobalScope('day_attendance')->count());
    }

    public function test_boarding_student_can_request_night_leave_and_teacher_can_approve_it(): void
    {
        $data = $this->schoolData('leave');
        $teacher = $this->user('teacher.leave@example.com', 'teacher');
        $data['class']->update(['teacher_id' => $teacher->id]);
        $student = $this->student($data, 'leave', true);
        $sickType = LeaveType::create([
            'school_id' => $data['school']->id,
            'name' => '病假',
            'slug' => 'sick_leave',
            'is_active' => true,
            'student_requestable' => true,
            'input_type' => 'duration_select',
        ]);
        $requestedStatus = EveningStudyStatus::create([
            'school_id' => $data['school']->id,
            'name' => '病假在家',
            'color' => 'blue',
            'base_status' => 'excused',
            'student_requestable' => true,
            'leave_type_id' => $sickType->id,
            'is_active' => true,
            'sort_order' => 2,
        ]);

        Sanctum::actingAs($student->user);
        $request = $this->postJson('/api/leave-requests', [
            'type' => 'sick_leave',
            'start_date' => '2026-07-21',
            'end_date' => '2026-07-21',
            'sessions' => [10],
            'reason' => '身体不适',
            'evening_study_status_id' => $requestedStatus->id,
            'destination' => '家中休息',
        ])->assertCreated()->assertJsonPath('scene', 'evening_study');

        $record = AttendanceRecord::withoutGlobalScope('day_attendance')->findOrFail($request->json('id'));
        $this->assertSame('pending', $record->approval_status);
        $this->assertSame('present', $record->status);
        $this->assertSame(0, AttendanceRecord::count());

        Sanctum::actingAs($teacher);
        $this->postJson("/api/leave-requests/{$record->id}/approve")->assertOk();

        $record->refresh();
        $this->assertSame('approved', $record->approval_status);
        $this->assertSame('excused', $record->status);
        $this->assertSame($requestedStatus->id, $record->evening_study_status_id);
    }

    private function schoolData(string $suffix): array
    {
        $school = School::create(['name' => "测试学校-$suffix"]);
        $department = Department::create(['school_id' => $school->id, 'name' => "测试系部-$suffix"]);
        $grade = Grade::create(['school_id' => $school->id, 'name' => "2026级-$suffix"]);
        $class = SchoolClass::create([
            'school_id' => $school->id,
            'department_id' => $department->id,
            'grade_id' => $grade->id,
            'name' => "测试班级-$suffix",
        ]);
        $normal = EveningStudyStatus::create([
            'school_id' => $school->id,
            'name' => '正常',
            'color' => 'green',
            'base_status' => 'present',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);
        $suspension = EveningStudyStatus::create([
            'school_id' => $school->id,
            'name' => '暂停住宿',
            'color' => 'gray',
            'base_status' => 'excused',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        SystemSetting::set('boarding_suspension_status_id', (string) $suspension->id);
        SystemSetting::set('attendance_periods', json_encode([[
            'id' => 10,
            'name' => '夜自习',
            'type' => 'special',
            'order' => 0,
            'audience_scope' => 'boarding',
            'scene' => 'evening_study',
            'counts_in_day_stats' => false,
            'is_active' => true,
        ]], JSON_UNESCAPED_UNICODE));

        return compact('school', 'department', 'grade', 'class', 'normal', 'suspension');
    }

    private function student(array $data, string $suffix, bool $boarding): Student
    {
        $user = $this->user("student.$suffix@example.com", 'student', "学生-$suffix");
        return Student::create([
            'user_id' => $user->id,
            'school_id' => $data['school']->id,
            'class_id' => $data['class']->id,
            'student_no' => "NO-$suffix",
            'gender' => 'male',
            'is_boarding' => $boarding,
        ]);
    }

    private function user(string $email, string $role, ?string $name = null): User
    {
        return User::create([
            'uuid' => (string) Str::uuid(),
            'name' => $name ?? $email,
            'email' => $email,
            'password' => 'password',
            'role' => $role,
            'status' => true,
        ]);
    }
}
