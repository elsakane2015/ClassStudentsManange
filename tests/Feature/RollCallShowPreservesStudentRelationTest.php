<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\LeaveType;
use App\Models\RollCall;
use App\Models\RollCallRecord;
use App\Models\RollCallType;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RollCallShowPreservesStudentRelationTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_preserves_student_user_when_sync_reloads_leave_types(): void
    {
        $school = School::create(['name' => '测试中学']);

        $teacher = User::create([
            'uuid' => (string) Str::uuid(),
            'name' => '黄老师',
            'email' => 'teacher_rc_show@test.local',
            'password' => bcrypt('123456'),
            'role' => 'teacher',
        ]);

        $schoolClass = SchoolClass::create([
            'school_id' => $school->id,
            'teacher_id' => $teacher->id,
            'name' => '艺术2351',
            'is_graduated' => false,
        ]);

        $studentUser = User::create([
            'uuid' => (string) Str::uuid(),
            'name' => '梁曼婷',
            'email' => 'stu_rc_show@test.local',
            'password' => bcrypt('123456'),
            'role' => 'student',
        ]);

        $student = Student::create([
            'user_id' => $studentUser->id,
            'school_id' => $school->id,
            'class_id' => $schoolClass->id,
            'student_no' => '239172701',
            'gender' => 'female',
        ]);

        $sickLeave = LeaveType::create([
            'school_id' => $school->id,
            'name' => '病假',
            'slug' => 'sick_leave',
            'is_active' => true,
            'student_requestable' => true,
            'color' => 'blue',
        ]);

        $rollCallType = RollCallType::create([
            'school_id' => $school->id,
            'class_id' => $schoolClass->id,
            'name' => '早操点名',
            'absent_status' => 'absent',
            'period_ids' => [1],
            'is_active' => true,
            'created_by' => $teacher->id,
        ]);

        $rollCall = RollCall::create([
            'school_id' => $school->id,
            'class_id' => $schoolClass->id,
            'roll_call_type_id' => $rollCallType->id,
            'roll_call_time' => '2026-11-27 08:20:00',
            'created_by' => $teacher->id,
            'status' => 'completed',
            'total_students' => 1,
            'present_count' => 0,
            'on_leave_count' => 0,
            'completed_at' => '2026-11-27 08:30:00',
        ]);

        // Initially absent in roll call
        RollCallRecord::create([
            'roll_call_id' => $rollCall->id,
            'student_id' => $student->id,
            'status' => 'absent',
        ]);

        // Approved leave record added afterwards -> triggers $needReloadLeaveTypes = true in show()
        AttendanceRecord::create([
            'school_id' => $school->id,
            'student_id' => $student->id,
            'class_id' => $schoolClass->id,
            'date' => '2026-11-27',
            'period_id' => 1,
            'leave_type_id' => $sickLeave->id,
            'status' => 'excused',
            'is_self_applied' => true,
            'source_type' => 'self_applied',
            'approval_status' => 'approved',
        ]);

        Sanctum::actingAs($teacher);
        $res = $this->getJson("/api/roll-calls/{$rollCall->id}");

        $res->assertOk()
            ->assertJsonPath('records.0.status', 'on_leave')
            ->assertJsonPath('records.0.leave_type.name', '病假')
            ->assertJsonPath('records.0.student.student_no', '239172701')
            ->assertJsonPath('records.0.student.user.name', '梁曼婷');
    }
}
