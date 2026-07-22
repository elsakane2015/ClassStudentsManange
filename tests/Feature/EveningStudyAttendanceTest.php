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

    public function test_boarding_student_can_request_regular_and_evening_periods_in_one_batch(): void
    {
        $data = $this->schoolData('mixed-leave');
        $teacher = $this->user('teacher.mixed@example.com', 'teacher');
        $data['class']->update(['teacher_id' => $teacher->id]);
        $student = $this->student($data, 'mixed-leave', true);
        $leaveType = LeaveType::create([
            'school_id' => $data['school']->id,
            'name' => '病假',
            'slug' => 'sick_leave',
            'is_active' => true,
            'student_requestable' => true,
            'input_type' => 'duration_select',
        ]);
        $requestedStatus = EveningStudyStatus::create([
            'school_id' => $data['school']->id,
            'name' => '在家',
            'color' => 'blue',
            'base_status' => 'excused',
            'student_requestable' => true,
            'is_active' => true,
            'sort_order' => 2,
        ]);

        Sanctum::actingAs($student->user);
        $response = $this->postJson('/api/leave-requests', [
            'type' => $leaveType->slug,
            'start_date' => '2026-07-21',
            'end_date' => '2026-07-21',
            'sessions' => [1, 2, 10],
            'reason' => '身体不适',
            'evening_study_status_id' => $requestedStatus->id,
            'destination' => '父母家',
        ])->assertCreated()
            ->assertJsonPath('scene', 'mixed')
            ->assertJsonPath('has_evening_study', true)
            ->assertJsonPath('record_count', 3);

        $records = AttendanceRecord::withoutGlobalScope('day_attendance')
            ->where('leave_batch_id', $response->json('leave_batch_id'))
            ->orderBy('period_id')
            ->get();

        $this->assertCount(3, $records);
        $this->assertSame(2, AttendanceRecord::count());

        $regularRecords = $records->where('scene', 'regular');
        $eveningRecord = $records->firstWhere('scene', 'evening_study');
        $this->assertCount(2, $regularRecords);
        $this->assertSame([1, 2], $regularRecords->first()->details['period_ids']);
        $this->assertTrue($regularRecords->every(fn ($record) => $record->counts_in_day_stats));
        $this->assertSame([10], $eveningRecord->details['period_ids']);
        $this->assertFalse($eveningRecord->counts_in_day_stats);
        $this->assertSame($requestedStatus->id, $eveningRecord->requested_evening_status_id);
        $this->assertSame('父母家', $eveningRecord->destination);

        Sanctum::actingAs($teacher);
        $overview = $this->getJson('/api/attendance/overview?date=2026-07-21&include_evening=1')->assertOk();
        $overviewStudent = collect($overview->json())
            ->flatMap(fn ($department) => $department['classes'] ?? [])
            ->flatMap(fn ($class) => $class['students'] ?? [])
            ->firstWhere('id', $student->id);
        $overviewEveningRecord = collect($overviewStudent['attendance'] ?? [])->firstWhere('scene', 'evening_study');
        $this->assertSame('在家', $overviewEveningRecord['requested_evening_status']['name']);
        $this->assertSame('正常', $overviewEveningRecord['evening_study_status']['name']);

        $teacherCalendar = $this->getJson('/api/attendance/calendar-summary?month=2026-07')->assertOk();
        $teacherEveningEvent = collect($teacherCalendar->json('2026-07-21'))
            ->first(fn ($event) => str_starts_with($event['option'] ?? '', '夜自习'));
        $this->assertNotNull($teacherEveningEvent);
        $this->assertSame('夜自习·在家', $teacherEveningEvent['option']);

        $this->postJson("/api/leave-requests/{$response->json('id')}/approve")
            ->assertOk()
            ->assertJsonPath('approved_count', 3);

        $records->each->refresh();
        $this->assertTrue($records->every(fn ($record) => $record->approval_status === 'approved'));
        $this->assertTrue($regularRecords->every(fn ($record) => $record->fresh()->status === 'excused'));
        $this->assertSame('excused', $eveningRecord->fresh()->status);

        $index = $this->getJson('/api/leave-requests')->assertOk();
        $index->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.scene', 'mixed')
            ->assertJsonPath('data.0.has_evening_study', true)
            ->assertJsonPath('data.0.regular_period_label', '第1-2节')
            ->assertJsonPath('data.0.regular_period_count', 2)
            ->assertJsonPath('data.0.evening_study_label', '夜自习·在家')
            ->assertJsonPath('data.0.destination', '父母家')
            ->assertJsonPath('data.0.requested_evening_status.name', '在家')
            ->assertJsonPath('data.0.display_evening_status.name', '在家');

        Sanctum::actingAs($student->user);
        $calendar = $this->getJson('/api/calendar?start=2026-07-21&end=2026-07-21')->assertOk();
        $calendar->assertJsonCount(1, 'attendance')
            ->assertJsonPath('attendance.0.detail_label', '第1-2节；夜自习·在家')
            ->assertJsonPath('attendance.0.regular_detail_label', '第1-2节')
            ->assertJsonPath('attendance.0.evening_study_label', '夜自习·在家')
            ->assertJsonPath('attendance.0.display_evening_status.name', '在家')
            ->assertJsonPath('attendance.0.evening_destination', '父母家')
            ->assertJsonPath('attendance.0.has_evening_study', true);
    }

    public function test_day_student_cannot_submit_a_boarding_only_period_in_a_mixed_request(): void
    {
        $data = $this->schoolData('day-mixed-leave');
        $student = $this->student($data, 'day-mixed-leave', false);
        LeaveType::create([
            'school_id' => $data['school']->id,
            'name' => '病假',
            'slug' => 'sick_leave',
            'is_active' => true,
            'student_requestable' => true,
            'input_type' => 'duration_select',
        ]);

        Sanctum::actingAs($student->user);
        $this->postJson('/api/leave-requests', [
            'type' => 'sick_leave',
            'start_date' => '2026-07-21',
            'end_date' => '2026-07-21',
            'sessions' => [1, 10],
            'reason' => '身体不适',
        ])->assertUnprocessable()
            ->assertJsonPath('error', '所选节次仅对住宿生开放');

        $this->assertSame(0, AttendanceRecord::withoutGlobalScope('day_attendance')->count());
    }

    public function test_student_can_edit_an_entire_pending_mixed_request(): void
    {
        $data = $this->schoolData('edit-mixed-leave');
        $student = $this->student($data, 'edit-mixed-leave', true);
        $leaveType = LeaveType::create([
            'school_id' => $data['school']->id,
            'name' => '病假',
            'slug' => 'sick_leave',
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
        $created = $this->postJson('/api/leave-requests', [
            'type' => $leaveType->slug,
            'start_date' => '2026-07-21',
            'end_date' => '2026-07-21',
            'sessions' => [1, 2, 10],
            'reason' => '原申请',
            'evening_study_status_id' => $requestedStatus->id,
            'destination' => '原去向',
        ])->assertCreated();
        $oldRecordIds = AttendanceRecord::withoutGlobalScope('day_attendance')
            ->where('leave_batch_id', $created->json('leave_batch_id'))
            ->pluck('id');

        $updated = $this->putJson('/api/leave-requests/'.$created->json('id'), [
            'type' => $leaveType->slug,
            'start_date' => '2026-07-22',
            'end_date' => '2026-07-22',
            'sessions' => [2, 10],
            'reason' => '修改后的申请',
            'evening_study_status_id' => $requestedStatus->id,
            'destination' => '宿舍301',
        ])->assertCreated()
            ->assertJsonPath('record_count', 2)
            ->assertJsonPath('scene', 'mixed');

        $this->assertSame(2, AttendanceRecord::withoutGlobalScope('day_attendance')->count());
        $this->assertSame(0, AttendanceRecord::withoutGlobalScope('day_attendance')->whereIn('id', $oldRecordIds)->count());
        $this->assertDatabaseHas('attendance_records', [
            'id' => $updated->json('id'),
            'date' => '2026-07-22',
            'reason' => '修改后的申请',
            'approval_status' => 'pending',
        ]);
        $this->assertDatabaseHas('attendance_records', [
            'period_id' => 10,
            'destination' => '宿舍301',
            'requested_evening_status_id' => $requestedStatus->id,
        ]);
    }

    public function test_teacher_can_process_only_selected_parts_without_deleting_the_rest(): void
    {
        $data = $this->schoolData('partial-approval');
        $teacher = $this->user('teacher.partial@example.com', 'teacher');
        $data['class']->update(['teacher_id' => $teacher->id]);
        $student = $this->student($data, 'partial-approval', true);
        $leaveType = LeaveType::create([
            'school_id' => $data['school']->id,
            'name' => '病假',
            'slug' => 'sick_leave',
            'is_active' => true,
            'student_requestable' => true,
            'input_type' => 'duration_select',
        ]);
        $requestedStatus = EveningStudyStatus::create([
            'school_id' => $data['school']->id,
            'name' => '在家',
            'color' => 'blue',
            'base_status' => 'excused',
            'student_requestable' => true,
            'is_active' => true,
            'sort_order' => 2,
        ]);

        Sanctum::actingAs($student->user);
        $created = $this->postJson('/api/leave-requests', [
            'type' => $leaveType->slug,
            'start_date' => '2026-07-21',
            'end_date' => '2026-07-21',
            'sessions' => [1, 2, 10],
            'reason' => '部分审批测试',
            'evening_study_status_id' => $requestedStatus->id,
            'destination' => '父母家',
        ])->assertCreated();
        $records = AttendanceRecord::withoutGlobalScope('day_attendance')
            ->where('leave_batch_id', $created->json('leave_batch_id'))
            ->get()->keyBy('period_id');

        Sanctum::actingAs($teacher);
        $this->postJson('/api/leave-requests/'.$created->json('id').'/approve', [
            'record_ids' => [$records[1]->id],
        ])->assertOk()
            ->assertJsonPath('approved_count', 1)
            ->assertJsonPath('remaining_pending_count', 2);

        $this->assertSame('approved', $records[1]->fresh()->approval_status);
        $this->assertSame('pending', $records[2]->fresh()->approval_status);
        $this->assertSame('pending', $records[10]->fresh()->approval_status);

        $pending = $this->getJson('/api/leave-requests?status=pending')->assertOk();
        $pending->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.regular_period_label', '第2节')
            ->assertJsonPath('data.0.evening_study_label', '夜自习·在家');

        $this->postJson('/api/leave-requests/'.$records[10]->id.'/reject', [
            'record_ids' => [$records[10]->id],
            'reason' => '夜自习部分不批准',
        ])->assertOk()
            ->assertJsonPath('rejected_count', 1)
            ->assertJsonPath('remaining_pending_count', 1);

        $this->assertSame('rejected', $records[10]->fresh()->approval_status);
        $this->assertSame('pending', $records[2]->fresh()->approval_status);
        $this->assertSame(3, AttendanceRecord::withoutGlobalScope('day_attendance')->count());

        $all = $this->getJson('/api/leave-requests')->assertOk();
        $this->assertEqualsCanonicalizing(
            ['approved', 'pending', 'rejected'],
            collect($all->json('data'))->pluck('status')->all()
        );
    }

    public function test_teacher_removes_only_the_clicked_scene_from_a_mixed_leave_batch(): void
    {
        $data = $this->schoolData('delete-mixed-scene');
        $teacher = $this->user('teacher.delete.scene@example.com', 'teacher');
        $data['class']->update(['teacher_id' => $teacher->id]);
        $student = $this->student($data, 'delete-mixed-scene', true);
        $leaveType = LeaveType::create([
            'school_id' => $data['school']->id,
            'name' => '病假',
            'slug' => 'sick_leave',
            'is_active' => true,
            'student_requestable' => true,
            'input_type' => 'duration_select',
        ]);
        $requestedStatus = EveningStudyStatus::create([
            'school_id' => $data['school']->id,
            'name' => '在家',
            'color' => 'blue',
            'base_status' => 'excused',
            'student_requestable' => true,
            'is_active' => true,
            'sort_order' => 2,
        ]);

        Sanctum::actingAs($student->user);
        $created = $this->postJson('/api/leave-requests', [
            'type' => $leaveType->slug,
            'start_date' => '2026-07-22',
            'end_date' => '2026-07-22',
            'sessions' => [1, 2, 10],
            'reason' => '撤销分组测试',
            'evening_study_status_id' => $requestedStatus->id,
            'destination' => '父母家',
        ])->assertCreated();
        $records = AttendanceRecord::withoutGlobalScope('day_attendance')
            ->where('leave_batch_id', $created->json('leave_batch_id'))
            ->get();

        Sanctum::actingAs($teacher);
        $regularRecord = $records->firstWhere('scene', 'regular');
        $this->deleteJson('/api/attendance/records?'.http_build_query([
            'record_id' => $regularRecord->id,
            'student_id' => $student->id,
            'date' => '2026-07-22',
            'period_id' => $regularRecord->period_id,
            'source_type' => 'self_applied',
            'leave_batch_id' => $created->json('leave_batch_id'),
            'scene' => 'regular',
            'delete_scope' => 'scene_date',
        ]))->assertOk()
            ->assertJsonPath('deleted_count', 2);

        $remaining = AttendanceRecord::withoutGlobalScope('day_attendance')
            ->where('leave_batch_id', $created->json('leave_batch_id'))
            ->get();
        $this->assertCount(1, $remaining);
        $this->assertSame('evening_study', $remaining->sole()->scene);
        $this->assertSame(10, $remaining->sole()->period_id);
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

        $findStudent = function ($response) use ($student) {
            return collect($response->json())
                ->flatMap(fn ($department) => $department['classes'] ?? [])
                ->flatMap(fn ($class) => $class['students'] ?? [])
                ->firstWhere('id', $student->id);
        };

        Sanctum::actingAs($this->user('admin.overview@example.com', 'system_admin'));
        $dayOverview = $this->getJson('/api/attendance/overview?date=2026-07-21')->assertOk();
        $dayStudent = $findStudent($dayOverview);
        $this->assertNotNull($dayStudent, json_encode($dayOverview->json(), JSON_UNESCAPED_UNICODE));
        $this->assertCount(0, $dayStudent['attendance']);

        $fullOverview = $this->getJson('/api/attendance/overview?date=2026-07-21&include_evening=1')->assertOk();
        $fullStudent = $findStudent($fullOverview);
        $this->assertNotNull($fullStudent, json_encode($fullOverview->json(), JSON_UNESCAPED_UNICODE));
        $overviewRecord = collect($fullStudent['attendance'])->sole();
        $this->assertSame('evening_study', $overviewRecord['scene']);
        $this->assertSame('在宿舍', $overviewRecord['evening_study_status']['name']);
        $this->assertSame('夜自习', $overviewRecord['period_name_snapshot']);

        $calendar = $this->getJson('/api/attendance/calendar-summary?month=2026-07')->assertOk();
        $calendarRecord = collect($calendar->json('2026-07-21'))->sole();
        $this->assertSame('病假', $calendarRecord['type']);
        $this->assertSame('夜自习·在宿舍', $calendarRecord['option']);

        $this->deleteJson('/api/attendance/records?'.http_build_query([
            'record_id' => $record->id,
            'student_id' => $student->id,
            'date' => '2026-07-21',
            'period_id' => 10,
            'source_type' => 'teacher_evening_leave',
        ]))->assertOk();
        $this->assertDatabaseMissing('attendance_records', ['id' => $record->id]);
    }

    public function test_teacher_can_mark_night_attendance_with_non_student_requestable_type(): void
    {
        $data = $this->schoolData('teacher-night-status');
        $teacher = $this->user('teacher.night.status@example.com', 'teacher');
        $data['class']->update(['teacher_id' => $teacher->id]);
        $student = $this->student($data, 'teacher-night-status', true);
        $lateType = LeaveType::create([
            'school_id' => $data['school']->id,
            'name' => '迟到',
            'slug' => 'late',
            'is_active' => true,
            'student_requestable' => false,
            'input_type' => 'time',
        ]);
        $status = EveningStudyStatus::create([
            'school_id' => $data['school']->id,
            'name' => '在教室',
            'color' => 'green',
            'base_status' => 'present',
            'student_requestable' => true,
            'is_active' => true,
            'sort_order' => 2,
        ]);

        Sanctum::actingAs($teacher);
        $this->postJson('/api/evening-study/teacher-leave', [
            'student_id' => $student->id,
            'date' => '2026-07-21',
            'period_id' => 10,
            'leave_type_id' => $lateType->id,
            'status_id' => $status->id,
            'destination' => '教室',
        ])->assertOk();

        $this->assertDatabaseHas('attendance_records', [
            'student_id' => $student->id,
            'scene' => 'evening_study',
            'leave_type_id' => $lateType->id,
            'evening_study_status_id' => $status->id,
            'status' => 'present',
        ]);
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

    public function test_completing_session_saves_statuses_and_duty_teacher_can_reopen_it(): void
    {
        $data = $this->schoolData('complete-and-reopen');
        $dutyTeacher = $this->user('duty.complete@example.com', 'duty_teacher');
        $dutyTeacher->dutyDepartments()->attach($data['department']->id);
        $this->student($data, 'complete-one', true);
        $this->student($data, 'complete-two', true);
        $library = EveningStudyStatus::create([
            'school_id' => $data['school']->id,
            'name' => '在图书馆',
            'color' => 'cyan',
            'base_status' => 'excused',
            'is_active' => true,
            'sort_order' => 2,
        ]);

        Sanctum::actingAs($dutyTeacher);
        $started = $this->postJson('/api/evening-study/sessions', [
            'date' => '2026-07-24',
            'period_id' => 10,
            'class_id' => $data['class']->id,
        ])->assertOk();
        $records = collect($started->json('records'));

        $completed = $this->postJson('/api/evening-study/sessions/'.$started->json('session.id').'/complete', [
            'records' => [
                [
                    'id' => $records[0]['id'],
                    'status_id' => $data['normal']->id,
                    'destination' => null,
                ],
                [
                    'id' => $records[1]['id'],
                    'status_id' => $library->id,
                    'destination' => '校图书馆',
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('session.status', 'completed')
            ->assertJsonPath('session.normal_count', 1)
            ->assertJsonPath('session.exception_count', 1);

        $breakdown = collect($completed->json('session.status_counts'))->keyBy('name');
        $this->assertSame(1, $breakdown['正常']['count']);
        $this->assertSame(1, $breakdown['在图书馆']['count']);
        $this->assertDatabaseHas('attendance_records', [
            'id' => $records[1]['id'],
            'evening_study_status_id' => $library->id,
            'destination' => '校图书馆',
        ]);

        $this->postJson('/api/evening-study/sessions/'.$started->json('session.id').'/reopen')
            ->assertOk()
            ->assertJsonPath('session.status', 'in_progress');

        $history = $this->getJson('/api/evening-study/history')->assertOk();
        $history->assertJsonPath('data.0.recorded_at', fn ($value) => is_string($value) && $value !== '');
        $historyBreakdown = collect($history->json('data.0.status_counts'))->keyBy('name');
        $this->assertSame(1, $historyBreakdown['正常']['count']);
        $this->assertSame(1, $historyBreakdown['在图书馆']['count']);
    }

    public function test_summary_is_scoped_to_department_and_school_admin_sees_whole_school(): void
    {
        $data = $this->schoolData('summary-scope');
        $dutyTeacher = $this->user('duty.summary@example.com', 'duty_teacher');
        $dutyTeacher->dutyDepartments()->attach($data['department']->id);
        $this->student($data, 'summary-one', true);
        $this->student($data, 'summary-two', true);

        $secondDepartment = Department::create([
            'school_id' => $data['school']->id,
            'name' => '第二系部',
        ]);
        $secondClass = SchoolClass::create([
            'school_id' => $data['school']->id,
            'department_id' => $secondDepartment->id,
            'grade_id' => $data['grade']->id,
            'name' => '第二班级',
        ]);
        $secondData = [...$data, 'department' => $secondDepartment, 'class' => $secondClass];
        $this->student($secondData, 'summary-three', true);

        Sanctum::actingAs($dutyTeacher);
        $started = $this->postJson('/api/evening-study/sessions', [
            'date' => '2026-07-24',
            'period_id' => 10,
            'class_id' => $data['class']->id,
        ])->assertOk();
        $this->postJson('/api/evening-study/sessions/'.$started->json('session.id').'/complete')
            ->assertOk();

        $departmentSummary = $this->getJson('/api/evening-study/summary?date=2026-07-24&period_id=10')
            ->assertOk()
            ->assertJsonPath('scope_type', 'department')
            ->assertJsonPath('overall.class_count', 1)
            ->assertJsonPath('overall.expected_count', 2)
            ->assertJsonPath('overall.present_count', 2)
            ->assertJsonCount(1, 'departments');
        $departmentBreakdown = collect($departmentSummary->json('overall.status_counts'))->keyBy('name');
        $this->assertSame(2, $departmentBreakdown['正常']['count']);
        $this->assertSame(0, $departmentBreakdown['暂停住宿']['count']);

        Sanctum::actingAs($this->user('school.summary@example.com', 'school_admin'));
        $schoolSummary = $this->getJson('/api/evening-study/summary?date=2026-07-24&period_id=10')
            ->assertOk()
            ->assertJsonPath('scope_type', 'school')
            ->assertJsonPath('overall.class_count', 2)
            ->assertJsonPath('overall.expected_count', 3)
            ->assertJsonPath('overall.recorded_count', 2)
            ->assertJsonPath('overall.present_count', 2)
            ->assertJsonCount(2, 'departments');
    }

    public function test_deleting_session_removes_generated_records_and_restores_approved_leave(): void
    {
        $data = $this->schoolData('delete-session');
        $teacher = $this->user('teacher.delete.session@example.com', 'teacher');
        $data['class']->update(['teacher_id' => $teacher->id]);
        $dutyTeacher = $this->user('duty.delete.session@example.com', 'duty_teacher');
        $dutyTeacher->dutyDepartments()->attach($data['department']->id);
        $leaveStudent = $this->student($data, 'delete-leave', true);
        $this->student($data, 'delete-generated', true);
        $leaveType = LeaveType::create([
            'school_id' => $data['school']->id,
            'name' => '病假',
            'slug' => 'sick_leave',
            'is_active' => true,
            'student_requestable' => true,
            'input_type' => 'duration_select',
        ]);
        $homeStatus = EveningStudyStatus::create([
            'school_id' => $data['school']->id,
            'name' => '在家',
            'color' => 'indigo',
            'base_status' => 'excused',
            'student_requestable' => true,
            'is_active' => true,
            'sort_order' => 2,
        ]);

        Sanctum::actingAs($leaveStudent->user);
        $leave = $this->postJson('/api/leave-requests', [
            'type' => $leaveType->slug,
            'start_date' => '2026-07-25',
            'end_date' => '2026-07-25',
            'sessions' => [10],
            'reason' => '回家休养',
            'evening_study_status_id' => $homeStatus->id,
            'destination' => '家中',
        ])->assertCreated();
        Sanctum::actingAs($teacher);
        $this->postJson('/api/leave-requests/'.$leave->json('id').'/approve')->assertOk();

        Sanctum::actingAs($dutyTeacher);
        $started = $this->postJson('/api/evening-study/sessions', [
            'date' => '2026-07-25',
            'period_id' => 10,
            'class_id' => $data['class']->id,
        ])->assertOk();
        $records = collect($started->json('records'));
        $leaveRecord = $records->firstWhere('student_id', $leaveStudent->id);
        $generatedRecord = $records->firstWhere('student_id', '!=', $leaveStudent->id);

        $this->postJson('/api/evening-study/sessions/'.$started->json('session.id').'/complete', [
            'records' => $records->map(fn ($record) => [
                'id' => $record['id'],
                'status_id' => $data['normal']->id,
            ])->values()->all(),
        ])->assertOk();
        $this->assertSame('present', AttendanceRecord::withoutGlobalScope('day_attendance')->find($leaveRecord['id'])->status);

        $this->deleteJson('/api/evening-study/sessions/'.$started->json('session.id'))
            ->assertOk()
            ->assertJsonPath('deleted_record_count', 1)
            ->assertJsonPath('retained_leave_count', 1);

        $this->assertDatabaseMissing('evening_study_sessions', ['id' => $started->json('session.id')]);
        $this->assertDatabaseMissing('attendance_records', ['id' => $generatedRecord['id']]);
        $this->assertDatabaseHas('attendance_records', [
            'id' => $leaveRecord['id'],
            'evening_study_session_id' => null,
            'approval_status' => 'approved',
            'status' => 'excused',
            'evening_study_status_id' => $homeStatus->id,
            'source_type' => 'self_applied',
        ]);

        $this->postJson('/api/evening-study/sessions', [
            'date' => '2026-07-25',
            'period_id' => 10,
            'class_id' => $data['class']->id,
        ])->assertOk()
            ->assertJsonCount(2, 'records');
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
        SystemSetting::set('attendance_periods', json_encode([
            [
                'id' => 1,
                'name' => '第1节',
                'type' => 'regular',
                'order' => 0,
                'audience_scope' => 'all',
                'scene' => 'regular',
                'counts_in_day_stats' => true,
                'is_active' => true,
            ],
            [
                'id' => 2,
                'name' => '第2节',
                'type' => 'regular',
                'order' => 1,
                'audience_scope' => 'all',
                'scene' => 'regular',
                'counts_in_day_stats' => true,
                'is_active' => true,
            ],
            [
                'id' => 10,
                'name' => '夜自习',
                'type' => 'special',
                'order' => 2,
                'audience_scope' => 'boarding',
                'scene' => 'evening_study',
                'counts_in_day_stats' => false,
                'is_active' => true,
            ],
        ], JSON_UNESCAPED_UNICODE));

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
