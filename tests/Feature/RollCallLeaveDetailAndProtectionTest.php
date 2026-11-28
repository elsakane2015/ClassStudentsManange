<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\LeaveType;
use App\Models\RollCall;
use App\Models\RollCallAdmin;
use App\Models\RollCallRecord;
use App\Models\RollCallType;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RollCallLeaveDetailAndProtectionTest extends TestCase
{
    use RefreshDatabase;

    private function createFixture(): array
    {
        $school = School::create(['name' => '测试中学']);

        SystemSetting::set('attendance_periods', json_encode([
            ['id' => 1, 'name' => '早读', 'type' => 'special', 'scene' => 'regular', 'is_active' => true],
            ['id' => 2, 'name' => '第1节', 'type' => 'regular', 'scene' => 'regular', 'is_active' => true],
            ['id' => 3, 'name' => '第2节', 'type' => 'regular', 'scene' => 'regular', 'is_active' => true],
        ], JSON_UNESCAPED_UNICODE));

        $teacher = User::create([
            'uuid' => (string) Str::uuid(),
            'name' => '黄老师',
            'email' => 'teacher_rc_leave@test.local',
            'password' => bcrypt('123456'),
            'role' => 'teacher',
        ]);

        $schoolClass = SchoolClass::create([
            'school_id' => $school->id,
            'teacher_id' => $teacher->id,
            'name' => '艺术2351',
            'is_graduated' => false,
        ]);

        $adminUser = User::create([
            'uuid' => (string) Str::uuid(),
            'name' => '点名员甲',
            'email' => 'rc_admin@test.local',
            'password' => bcrypt('123456'),
            'role' => 'student',
        ]);
        $adminStudent = Student::create([
            'user_id' => $adminUser->id,
            'school_id' => $school->id,
            'class_id' => $schoolClass->id,
            'student_no' => '239172700',
            'gender' => 'female',
        ]);

        $stu1User = User::create([
            'uuid' => (string) Str::uuid(),
            'name' => '方戈',
            'email' => 'stu1_rc_leave@test.local',
            'password' => bcrypt('123456'),
            'role' => 'student',
        ]);
        $stu1 = Student::create([
            'user_id' => $stu1User->id,
            'school_id' => $school->id,
            'class_id' => $schoolClass->id,
            'student_no' => '239172713',
            'gender' => 'male',
        ]);

        $stu2User = User::create([
            'uuid' => (string) Str::uuid(),
            'name' => '江俊杰',
            'email' => 'stu2_rc_leave@test.local',
            'password' => bcrypt('123456'),
            'role' => 'student',
        ]);
        $stu2 = Student::create([
            'user_id' => $stu2User->id,
            'school_id' => $school->id,
            'class_id' => $schoolClass->id,
            'student_no' => '239172716',
            'gender' => 'male',
        ]);

        $sickLeave = LeaveType::create([
            'school_id' => $school->id,
            'name' => '病假',
            'slug' => 'sick_leave',
            'is_active' => true,
            'student_requestable' => true,
            'color' => 'blue',
        ]);
        $specialLeave = LeaveType::create([
            'school_id' => $school->id,
            'name' => '特殊',
            'slug' => 'special',
            'is_active' => true,
            'student_requestable' => true,
            'color' => 'purple',
        ]);
        $absentType = LeaveType::create([
            'school_id' => $school->id,
            'name' => '旷课',
            'slug' => 'absent',
            'is_active' => true,
            'student_requestable' => false,
            'color' => 'red',
        ]);

        $courseType = RollCallType::create([
            'school_id' => $school->id,
            'class_id' => $schoolClass->id,
            'name' => '第1-2节点名',
            'absent_status' => 'absent',
            'leave_type_id' => $absentType->id,
            'period_ids' => [2, 3],
            'is_active' => true,
            'created_by' => $teacher->id,
        ]);

        RollCallAdmin::create([
            'school_id' => $school->id,
            'class_id' => $schoolClass->id,
            'student_id' => $adminStudent->id,
            'roll_call_type_ids' => [$courseType->id],
            'is_active' => true,
            'can_modify_records' => true,
            'created_by' => $teacher->id,
        ]);

        return compact(
            'school',
            'schoolClass',
            'teacher',
            'adminUser',
            'adminStudent',
            'stu1User',
            'stu1',
            'stu2User',
            'stu2',
            'sickLeave',
            'specialLeave',
            'courseType'
        );
    }

    public function test_course_roll_call_shows_pending_and_marked_leaves_with_full_reason_and_blocks_student_admin_mutation(): void
    {
        $fx = $this->createFixture();

        // stu1: student applied pending sick leave for period 2 with reason
        $pendingAtt = AttendanceRecord::create([
            'school_id' => $fx['school']->id,
            'student_id' => $fx['stu1']->id,
            'class_id' => $fx['schoolClass']->id,
            'date' => '2026-11-28',
            'period_id' => 2,
            'leave_type_id' => $fx['sickLeave']->id,
            'status' => 'leave',
            'is_self_applied' => true,
            'source_type' => 'self_applied',
            'approval_status' => 'pending',
            'reason' => '发烧去校医室打点滴',
            'details' => ['display_label' => '第1节'],
        ]);

        // stu2: teacher directly marked special leave for period 3 with text/note
        AttendanceRecord::create([
            'school_id' => $fx['school']->id,
            'student_id' => $fx['stu2']->id,
            'class_id' => $fx['schoolClass']->id,
            'date' => '2026-11-28',
            'period_id' => 3,
            'leave_type_id' => $fx['specialLeave']->id,
            'status' => 'leave',
            'is_self_applied' => false,
            'source_type' => 'manual_bulk',
            'approval_status' => null,
            'note' => '去教务处协助整理画展',
            'details' => ['display_label' => '第2节'],
        ]);

        // Student roll call admin starts course roll call for periods 2 & 3
        Sanctum::actingAs($fx['adminUser']);
        $createRes = $this->postJson('/api/roll-calls', [
            'roll_call_type_id' => $fx['courseType']->id,
            'roll_call_time' => '2026-11-28 08:30:00',
        ])->assertCreated();

        $rollCallId = $createRes->json('id');
        $showRes = $this->getJson("/api/roll-calls/{$rollCallId}")->assertOk();

        $recordsByStudent = collect($showRes->json('records'))->keyBy('student_id');

        // Both stu1 (pending application) and stu2 (teacher marked) are on_leave with full reason/remark
        $r1 = $recordsByStudent->get($fx['stu1']->id);
        $this->assertSame('on_leave', $r1['status']);
        $this->assertSame('pending_leave', $r1['leave_status']);
        $this->assertStringContainsString('待审批', $r1['leave_detail']);
        $this->assertStringContainsString('发烧去校医室打点滴', $r1['leave_detail']);

        $r2 = $recordsByStudent->get($fx['stu2']->id);
        $this->assertSame('on_leave', $r2['status']);
        $this->assertStringContainsString('去教务处协助整理画展', $r2['leave_detail']);

        // Student roll call admin completes roll call (even if attempting to overwrite stu1/stu2)
        $this->postJson("/api/roll-calls/{$rollCallId}/complete", [
            'records' => [
                ['student_id' => $fx['stu1']->id, 'status' => 'present'],
                ['student_id' => $fx['stu2']->id, 'status' => 'absent'],
            ],
        ])->assertOk();

        // Both remain protected as on_leave
        $this->assertSame('on_leave', RollCallRecord::find($r1['id'])->status);
        $this->assertSame('on_leave', RollCallRecord::find($r2['id'])->status);

        // Even with can_modify_records = true, student admin gets 403 when attempting to modify on_leave record
        $this->putJson("/api/roll-calls/{$rollCallId}/records/{$r1['id']}", ['status' => 'present'])
            ->assertStatus(403);
        $this->putJson("/api/roll-calls/{$rollCallId}/records/{$r2['id']}", ['status' => 'absent'])
            ->assertStatus(403);

        // Pending attendance record received roll_call_pending link; if teacher rejects it, roll_call_record turns absent
        $this->assertNotNull($pendingAtt->fresh()->details['roll_call_pending'] ?? null);

        Sanctum::actingAs($fx['teacher']);
        $this->postJson("/api/leave-requests/{$pendingAtt->id}/reject", ['reason' => '未见假条'])
            ->assertOk();

        $this->assertSame('absent', RollCallRecord::find($r1['id'])->status);
        $this->assertSame(1, RollCall::find($rollCallId)->on_leave_count);
    }

    public function test_one_off_course_roll_call_type_does_not_pollute_active_types_and_reuses_cleanly(): void
    {
        $fx = $this->createFixture();
        Sanctum::actingAs($fx['teacher']);

        $firstType = $this->postJson('/api/roll-call-types', [
            'class_id' => $fx['schoolClass']->id,
            'name' => '第1-2节点名',
            'absent_status' => 'absent',
            'period_ids' => [2, 3],
            'is_active' => false,
        ])->assertCreated();

        // 单次课程点名不污染常用启用列表（仍仅有 fixture 中的 1 个启用类型）
        $activeTypes = $this->getJson('/api/roll-call-types')->assertOk()->json();
        $this->assertCount(1, $activeTypes);
        $this->assertSame($fx['courseType']->id, $activeTypes[0]['id']);

        // 明天或再次选择相同节次发起单次课程点名时，后端优雅复用该临时类型且依然可正常发起点名
        $secondType = $this->postJson('/api/roll-call-types', [
            'class_id' => $fx['schoolClass']->id,
            'name' => '第1-2节点名',
            'absent_status' => 'absent',
            'period_ids' => [3, 2],
            'is_active' => false,
        ])->assertCreated();

        $this->assertSame($firstType->json('id'), $secondType->json('id'));

        $rollCallRes = $this->postJson('/api/roll-calls', [
            'roll_call_type_id' => $secondType->json('id'),
            'roll_call_time' => '2026-11-29 08:30:00',
        ])->assertCreated();

        $detail = $this->getJson('/api/roll-calls/' . $rollCallRes->json('id'))->assertOk();
        $this->assertSame('第1-2节点名', $detail->json('roll_call_type.name'));
    }
}
