<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Grade;
use App\Models\LeaveType;
use App\Models\RollCall;
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

class RollCallLeaveOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_late_leave_request_is_preserved_when_roll_call_completes(): void
    {
        $data = $this->setupData('late-leave');
        $rollCallAdminUser = $data['rollCallAdminUser'];
        $studentUser = $data['studentUser'];
        $student = $data['student'];
        $rollCallType = $data['rollCallType'];
        $class = $data['class'];

        // 1. 学生此时未发起请假申请

        // 2. 点名学生开启点名窗口
        Sanctum::actingAs($rollCallAdminUser);
        $rollCallResponse = $this->postJson('/api/roll-calls', [
            'class_id' => $class->id,
            'roll_call_type_id' => $rollCallType->id,
            'roll_call_time' => '2026-09-20 08:00:00',
        ])->assertCreated();

        $rollCallId = $rollCallResponse->json('id');
        $this->assertDatabaseHas('roll_call_records', [
            'roll_call_id' => $rollCallId,
            'student_id' => $student->id,
            'status' => 'pending',
        ]);

        // 3. 学生发起请假申请
        Sanctum::actingAs($studentUser);
        $leaveResponse = $this->postJson('/api/leave-requests', [
            'type' => 'personal_leave',
            'start_date' => '2026-09-20',
            'end_date' => '2026-09-20',
            'sessions' => [1, 2],
            'reason' => '家中有事',
        ])->assertCreated();

        // 验证请假记录已创建
        $this->assertDatabaseHas('attendance_records', [
            'student_id' => $student->id,
            'date' => '2026-09-20 00:00:00',
            'period_id' => 1,
            'is_self_applied' => true,
            'approval_status' => 'pending',
            'source_type' => 'self_applied',
        ]);

        // 4. 点名学生完成点名
        Sanctum::actingAs($rollCallAdminUser);
        $this->postJson("/api/roll-calls/{$rollCallId}/complete", [])->assertOk();

        // 5. 验证：晚发起的请假记录必须被保留，不能被删除或覆盖！
        $leaveRecords = AttendanceRecord::withoutGlobalScope('day_attendance')
            ->where('student_id', $student->id)
            ->where('date', '2026-09-20')
            ->where('period_id', 1)
            ->get();

        $this->assertCount(1, $leaveRecords);
        $record = $leaveRecords->first();
        $this->assertTrue((bool)$record->is_self_applied, '请假记录的 is_self_applied 必须为 true');
        $this->assertSame('pending', $record->approval_status, '请假记录必须保持待审批状态');
        $this->assertSame('self_applied', $record->source_type, '来源类型不能被覆盖为 roll_call');
        $this->assertNotNull($record->details['roll_call_pending'] ?? null, '应附加点名信息待教师决定');

        // 6. 验证：教师批准请假后，点名记录自动同步为 on_leave
        $teacher = $data['teacherUser'];
        Sanctum::actingAs($teacher);
        $this->postJson("/api/leave-requests/{$record->id}/approve", [])->assertOk();

        $this->assertDatabaseHas('roll_call_records', [
            'roll_call_id' => $rollCallId,
            'student_id' => $student->id,
            'status' => 'on_leave',
        ]);
    }

    public function test_full_day_late_leave_request_is_preserved_when_roll_call_with_periods_completes(): void
    {
        $data = $this->setupData('full-day-leave');
        $rollCallAdminUser = $data['rollCallAdminUser'];
        $studentUser = $data['studentUser'];
        $student = $data['student'];
        $rollCallType = $data['rollCallType'];
        $class = $data['class'];

        // 2. 开启点名窗口
        Sanctum::actingAs($rollCallAdminUser);
        $rollCallResponse = $this->postJson('/api/roll-calls', [
            'class_id' => $class->id,
            'roll_call_type_id' => $rollCallType->id,
            'roll_call_time' => '2026-09-20 08:00:00',
        ])->assertCreated();
        $rollCallId = $rollCallResponse->json('id');

        // 3. 学生发起全天请假申请 (无 specific sessions)
        Sanctum::actingAs($studentUser);
        $this->postJson('/api/leave-requests', [
            'type' => 'personal_leave',
            'start_date' => '2026-09-20',
            'end_date' => '2026-09-20',
            'reason' => '全天请假',
        ])->assertCreated();

        // 4. 完成点名
        Sanctum::actingAs($rollCallAdminUser);
        $this->postJson("/api/roll-calls/{$rollCallId}/complete", [])->assertOk();

        // 5. 验证全天请假记录未被删除
        $fullDayLeave = AttendanceRecord::withoutGlobalScope('day_attendance')
            ->where('student_id', $student->id)
            ->where('date', '2026-09-20')
            ->whereNull('period_id')
            ->first();

        $this->assertNotNull($fullDayLeave, '全天请假记录不能被删除');
        $this->assertTrue((bool)$fullDayLeave->is_self_applied);
        $this->assertSame('pending', $fullDayLeave->approval_status);
        $this->assertSame('self_applied', $fullDayLeave->source_type);
        $this->assertNotNull($fullDayLeave->details['roll_call_pending'] ?? null);
    }

    private function setupData(string $suffix): array
    {
        $school = School::create(['name' => "测试学校-$suffix"]);
        $department = Department::create(['school_id' => $school->id, 'name' => "测试系部-$suffix"]);
        $grade = Grade::create(['school_id' => $school->id, 'name' => "测试年级-$suffix"]);
        $class = SchoolClass::create([
            'school_id' => $school->id,
            'department_id' => $department->id,
            'grade_id' => $grade->id,
            'name' => "测试班级-$suffix",
        ]);

        $leaveType = LeaveType::create([
            'school_id' => $school->id,
            'name' => '事假',
            'slug' => 'personal_leave',
            'type' => 'personal_leave',
            'is_active' => true,
        ]);

        $absentLeaveType = LeaveType::create([
            'school_id' => $school->id,
            'name' => '旷课',
            'slug' => 'absent',
            'type' => 'absent',
            'is_active' => true,
        ]);

        $teacherUser = User::create([
            'uuid' => (string) Str::uuid(),
            'name' => "教师-$suffix",
            'email' => "teacher.$suffix@example.com",
            'password' => 'password',
            'role' => 'teacher',
            'status' => true,
        ]);
        $class->update(['teacher_id' => $teacherUser->id]);

        $rollCallType = RollCallType::create([
            'school_id' => $school->id,
            'class_id' => $class->id,
            'name' => '早晨点名',
            'period_ids' => [1, 2],
            'leave_type_id' => $absentLeaveType->id,
            'created_by' => $teacherUser->id,
            'is_active' => true,
        ]);

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
        ], JSON_UNESCAPED_UNICODE));

        $rollCallAdminUser = User::create([
            'uuid' => (string) Str::uuid(),
            'name' => "点名学生-$suffix",
            'email' => "rollcall.$suffix@example.com",
            'password' => 'password',
            'role' => 'student',
            'status' => true,
        ]);
        $rollCallStudent = Student::create([
            'user_id' => $rollCallAdminUser->id,
            'school_id' => $school->id,
            'class_id' => $class->id,
            'student_no' => "ADMIN-$suffix",
            'gender' => 'male',
            'is_boarding' => false,
            'is_class_admin' => true,
        ]);

        \App\Models\RollCallAdmin::create([
            'class_id' => $class->id,
            'student_id' => $rollCallStudent->id,
            'roll_call_type_ids' => [$rollCallType->id],
            'created_by' => $teacherUser->id,
            'is_active' => true,
        ]);

        $studentUser = User::create([
            'uuid' => (string) Str::uuid(),
            'name' => "普通学生-$suffix",
            'email' => "student.$suffix@example.com",
            'password' => 'password',
            'role' => 'student',
            'status' => true,
        ]);
        $student = Student::create([
            'user_id' => $studentUser->id,
            'school_id' => $school->id,
            'class_id' => $class->id,
            'student_no' => "STU-$suffix",
            'gender' => 'male',
            'is_boarding' => false,
        ]);

        return compact('school', 'department', 'grade', 'class', 'leaveType', 'absentLeaveType', 'rollCallType', 'teacherUser', 'rollCallAdminUser', 'rollCallStudent', 'studentUser', 'student');
    }
}
