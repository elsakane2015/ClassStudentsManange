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

    public function test_boarding_student_can_use_the_same_night_status_for_different_leave_types(): void
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
        $personalType = LeaveType::create([
            'school_id' => $data['school']->id,
            'name' => '事假',
            'slug' => 'personal_leave',
            'is_active' => true,
            'student_requestable' => true,
            'input_type' => 'duration_select',
        ]);
        $requestedStatus = EveningStudyStatus::create([
            'school_id' => $data['school']->id,
            'name' => '在宿舍',
            'color' => 'blue',
            'base_status' => 'excused',
            'student_requestable' => true,
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

        Sanctum::actingAs($student->user);
        $personalRequest = $this->postJson('/api/leave-requests', [
            'type' => 'personal_leave',
            'start_date' => '2026-07-22',
            'end_date' => '2026-07-22',
            'sessions' => [10],
            'reason' => '个人事务',
            'evening_study_status_id' => $requestedStatus->id,
            'destination' => '宿舍等候',
        ])->assertCreated();

        $personalRecord = AttendanceRecord::withoutGlobalScope('day_attendance')
            ->findOrFail($personalRequest->json('id'));
        $this->assertSame($personalType->id, $personalRecord->leave_type_id);
        $this->assertSame($requestedStatus->id, $personalRecord->requested_evening_status_id);
    }

    public function test_teacher_marks_night_leave_with_separate_leave_type_and_status(): void
    {
        $data = $this->schoolData('teacher-mark');
        $teacher = $this->user('teacher.mark@example.com', 'teacher');
        $data['class']->update(['teacher_id' => $teacher->id]);
        $student = $this->student($data, 'teacher-mark', true);
        $sickType = LeaveType::create([
            'school_id' => $data['school']->id,
            'name' => '病假',
            'slug' => 'sick_leave',
            'is_active' => true,
            'student_requestable' => true,
            'input_type' => 'duration_select',
        ]);
        $status = EveningStudyStatus::create([
            'school_id' => $data['school']->id,
            'name' => '在宿舍',
            'color' => 'blue',
            'base_status' => 'excused',
            'student_requestable' => true,
            'is_active' => true,
            'sort_order' => 2,
        ]);

        Sanctum::actingAs($teacher);
        $this->postJson('/api/evening-study/teacher-leave', [
            'student_id' => $student->id,
            'date' => '2026-07-21',
            'period_id' => 10,
            'leave_type_id' => $sickType->id,
            'status_id' => $status->id,
            'destination' => '宿舍',
            'reason' => '身体不适',
        ])->assertOk();

        $record = AttendanceRecord::withoutGlobalScope('day_attendance')->sole();
        $this->assertSame('evening_study', $record->scene);
        $this->assertFalse($record->counts_in_day_stats);
        $this->assertSame($sickType->id, $record->leave_type_id);
        $this->assertSame($status->id, $record->evening_study_status_id);
        $this->assertSame('宿舍', $record->destination);
        $this->assertSame(0, AttendanceRecord::count());
    }

    public function test_admin_can_make_a_status_student_selectable_without_a_leave_type(): void
    {
        $data = $this->schoolData('status-settings');
        $admin = $this->user('admin.status@example.com', 'system_admin');

        Sanctum::actingAs($admin);
        $response = $this->postJson('/api/evening-study-statuses', [
            'name' => '在图书馆',
            'color' => 'cyan',
            'base_status' => 'excused',
            'is_default' => false,
            'student_requestable' => true,
            'is_active' => true,
            'sort_order' => 3,
        ])->assertCreated();

        $response->assertJsonPath('student_requestable', true)
            ->assertJsonPath('leave_type_id', null);
        $statusId = $response->json('id');
        $this->assertDatabaseHas('evening_study_statuses', [
            'school_id' => $data['school']->id,
            'name' => '在图书馆',
            'student_requestable' => true,
            'leave_type_id' => null,
        ]);

        $student = $this->student($data, 'status-delete', true);
        $record = AttendanceRecord::withoutGlobalScope('day_attendance')->create([
            'student_id' => $student->id,
            'school_id' => $data['school']->id,
            'class_id' => $data['class']->id,
            'date' => '2026-07-21',
            'period_id' => 10,
            'status' => 'present',
            'scene' => 'evening_study',
            'counts_in_day_stats' => false,
            'requested_evening_status_id' => $statusId,
            'requested_status_name_snapshot' => '在图书馆',
            'approval_status' => 'pending',
        ]);

        $this->deleteJson("/api/evening-study-statuses/{$statusId}")
            ->assertUnprocessable()
            ->assertJsonPath('message', '该状态仍有待审批申请，处理完成后才能删除');

        $record->update(['approval_status' => 'rejected']);
        $this->deleteJson("/api/evening-study-statuses/{$statusId}")
            ->assertOk()
            ->assertJsonPath('message', '状态已删除');
        $this->assertDatabaseMissing('evening_study_statuses', ['id' => $statusId]);
        $this->assertSame('在图书馆', $record->fresh()->requested_status_name_snapshot);
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
