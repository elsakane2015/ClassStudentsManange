<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\LeaveType;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Services\MobilePushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LeaveApprovalNotificationAutoReadTest extends TestCase
{
    use RefreshDatabase;

    private function createClassFixture(): array
    {
        $school = School::create(['name' => '测试中学']);

        $teacher = User::create([
            'uuid' => (string) Str::uuid(),
            'name' => '黄老师',
            'email' => 'teacher_autoread@test.local',
            'password' => bcrypt('123456'),
            'role' => 'teacher',
        ]);

        $schoolClass = SchoolClass::create([
            'school_id' => $school->id,
            'teacher_id' => $teacher->id,
            'name' => '艺术2351',
            'is_graduated' => false,
        ]);

        $classAdminUser = User::create([
            'uuid' => (string) Str::uuid(),
            'name' => '考勤员同学',
            'email' => 'class_admin_autoread@test.local',
            'password' => bcrypt('123456'),
            'role' => 'student',
        ]);
        $classAdminStudent = Student::create([
            'user_id' => $classAdminUser->id,
            'school_id' => $school->id,
            'class_id' => $schoolClass->id,
            'student_no' => '239172700',
            'gender' => 'female',
            'is_class_admin' => true,
        ]);

        $student1User = User::create([
            'uuid' => (string) Str::uuid(),
            'name' => '方戈',
            'email' => 'stu1_autoread@test.local',
            'password' => bcrypt('123456'),
            'role' => 'student',
        ]);
        $student1 = Student::create([
            'user_id' => $student1User->id,
            'school_id' => $school->id,
            'class_id' => $schoolClass->id,
            'student_no' => '239172713',
            'gender' => 'male',
        ]);

        $student2User = User::create([
            'uuid' => (string) Str::uuid(),
            'name' => '江俊杰',
            'email' => 'stu2_autoread@test.local',
            'password' => bcrypt('123456'),
            'role' => 'student',
        ]);
        $student2 = Student::create([
            'user_id' => $student2User->id,
            'school_id' => $school->id,
            'class_id' => $schoolClass->id,
            'student_no' => '239172716',
            'gender' => 'male',
        ]);

        $leaveType = LeaveType::create([
            'school_id' => $school->id,
            'name' => '病假',
            'slug' => 'sick_leave',
            'is_active' => true,
            'student_requestable' => true,
            'color' => 'yellow',
        ]);

        return compact(
            'school',
            'schoolClass',
            'teacher',
            'classAdminUser',
            'classAdminStudent',
            'student1User',
            'student1',
            'student2User',
            'student2',
            'leaveType'
        );
    }

    public function test_approving_one_leave_marks_only_that_leave_notifications_read_and_decrements_unread_count(): void
    {
        config(['mobile_push.enabled' => false]);
        $fx = $this->createClassFixture();

        $batch1 = 'batch_' . Str::uuid();
        $batch2 = 'batch_' . Str::uuid();

        $rec1 = AttendanceRecord::create([
            'school_id' => $fx['school']->id,
            'student_id' => $fx['student1']->id,
            'class_id' => $fx['schoolClass']->id,
            'date' => '2026-11-27',
            'period_id' => 1,
            'leave_type_id' => $fx['leaveType']->id,
            'status' => 'leave',
            'is_self_applied' => true,
            'source_type' => 'self_applied',
            'approval_status' => 'pending',
            'leave_batch_id' => $batch1,
            'reason' => '肚子疼',
        ]);

        $rec2 = AttendanceRecord::create([
            'school_id' => $fx['school']->id,
            'student_id' => $fx['student2']->id,
            'class_id' => $fx['schoolClass']->id,
            'date' => '2026-11-27',
            'period_id' => 1,
            'leave_type_id' => $fx['leaveType']->id,
            'status' => 'leave',
            'is_self_applied' => true,
            'source_type' => 'self_applied',
            'approval_status' => 'pending',
            'leave_batch_id' => $batch2,
            'reason' => '头疼',
        ]);

        $push = app(MobilePushService::class);
        $push->notifyLeaveRequestSubmitted($rec1);
        $push->notifyLeaveRequestSubmitted($rec2);

        $this->assertSame(2, $fx['teacher']->appNotifications()->whereNull('read_at')->count());
        $this->assertSame(2, $fx['classAdminUser']->appNotifications()->whereNull('read_at')->count());

        Sanctum::actingAs($fx['teacher']);
        $res = $this->postJson("/api/leave-requests/{$rec1->id}/approve");

        $res->assertOk()
            ->assertJsonPath('remaining_pending_count', 0)
            ->assertJsonPath('unread_count', 1);

        // Both teacher and class admin have rec1's notification auto-read, while rec2 stays unread
        $this->assertSame(1, $fx['teacher']->appNotifications()->whereNull('read_at')->count());
        $this->assertSame(1, $fx['classAdminUser']->appNotifications()->whereNull('read_at')->count());

        $remainingNotif = $fx['teacher']->appNotifications()->whereNull('read_at')->first();
        $this->assertSame($batch2, $remainingNotif->data['leave_batch_id']);
    }

    public function test_partial_approval_keeps_notification_unread_until_last_item_is_processed(): void
    {
        config(['mobile_push.enabled' => false]);
        $fx = $this->createClassFixture();

        $batch = 'batch_' . Str::uuid();
        $p1 = AttendanceRecord::create([
            'school_id' => $fx['school']->id,
            'student_id' => $fx['student1']->id,
            'class_id' => $fx['schoolClass']->id,
            'date' => '2026-11-27',
            'period_id' => 1,
            'leave_type_id' => $fx['leaveType']->id,
            'status' => 'leave',
            'is_self_applied' => true,
            'source_type' => 'self_applied',
            'approval_status' => 'pending',
            'leave_batch_id' => $batch,
        ]);
        $p2 = AttendanceRecord::create([
            'school_id' => $fx['school']->id,
            'student_id' => $fx['student1']->id,
            'class_id' => $fx['schoolClass']->id,
            'date' => '2026-11-27',
            'period_id' => 2,
            'leave_type_id' => $fx['leaveType']->id,
            'status' => 'leave',
            'is_self_applied' => true,
            'source_type' => 'self_applied',
            'approval_status' => 'pending',
            'leave_batch_id' => $batch,
        ]);

        app(MobilePushService::class)->notifyLeaveRequestSubmitted($p1);
        $this->assertSame(1, $fx['teacher']->appNotifications()->whereNull('read_at')->count());

        Sanctum::actingAs($fx['teacher']);

        // Approve only period 1 -> still 1 pending item left -> notification stays unread
        $this->postJson("/api/leave-requests/{$p1->id}/approve", ['record_ids' => [$p1->id]])
            ->assertOk()
            ->assertJsonPath('remaining_pending_count', 1)
            ->assertJsonPath('unread_count', 1);

        // Reject remaining period 2 -> remaining_pending_count == 0 -> notification auto-marked read
        $this->postJson("/api/leave-requests/{$p2->id}/reject", [
            'record_ids' => [$p2->id],
            'reason' => '第二节有考试',
        ])
            ->assertOk()
            ->assertJsonPath('remaining_pending_count', 0)
            ->assertJsonPath('unread_count', 0);

        $this->assertSame(0, $fx['teacher']->appNotifications()->whereNull('read_at')->count());
        $this->assertNotNull($p2->fresh()->approved_at);
    }

    public function test_student_cancelling_pending_leave_auto_reads_teacher_notification(): void
    {
        config(['mobile_push.enabled' => false]);
        $fx = $this->createClassFixture();

        $batch = 'batch_' . Str::uuid();
        $rec = AttendanceRecord::create([
            'school_id' => $fx['school']->id,
            'student_id' => $fx['student1']->id,
            'class_id' => $fx['schoolClass']->id,
            'date' => '2026-11-27',
            'period_id' => 1,
            'leave_type_id' => $fx['leaveType']->id,
            'status' => 'leave',
            'is_self_applied' => true,
            'source_type' => 'self_applied',
            'approval_status' => 'pending',
            'leave_batch_id' => $batch,
        ]);

        app(MobilePushService::class)->notifyLeaveRequestSubmitted($rec);
        $this->assertSame(1, $fx['teacher']->appNotifications()->whereNull('read_at')->count());

        Sanctum::actingAs($fx['student1User']);
        $this->deleteJson("/api/leave-requests/{$rec->id}")->assertOk();

        $this->assertSame(0, $fx['teacher']->appNotifications()->whereNull('read_at')->count());
    }

    public function test_cleanup_resolved_leave_notifications_only_marks_finished_ones_read(): void
    {
        config(['mobile_push.enabled' => false]);
        $fx = $this->createClassFixture();

        $approvedRec = AttendanceRecord::create([
            'school_id' => $fx['school']->id,
            'student_id' => $fx['student1']->id,
            'class_id' => $fx['schoolClass']->id,
            'date' => '2026-11-26',
            'period_id' => 1,
            'leave_type_id' => $fx['leaveType']->id,
            'status' => 'excused',
            'is_self_applied' => true,
            'source_type' => 'self_applied',
            'approval_status' => 'approved',
            'leave_batch_id' => 'batch_done',
        ]);

        $pendingEveningRec = AttendanceRecord::create([
            'school_id' => $fx['school']->id,
            'student_id' => $fx['student2']->id,
            'class_id' => $fx['schoolClass']->id,
            'date' => '2026-11-27',
            'scene' => 'evening_study',
            'counts_in_day_stats' => false,
            'leave_type_id' => $fx['leaveType']->id,
            'status' => 'leave',
            'is_self_applied' => true,
            'source_type' => 'self_applied',
            'approval_status' => 'pending',
            'leave_batch_id' => 'batch_evening_pending',
        ]);

        $nDone = AppNotification::create([
            'user_id' => $fx['teacher']->id,
            'type' => 'leave_submitted',
            'title' => '已批准旧通知',
            'body' => 'body',
            'data' => ['leave_request_id' => $approvedRec->id, 'leave_batch_id' => 'batch_done'],
        ]);
        $nDeleted = AppNotification::create([
            'user_id' => $fx['teacher']->id,
            'type' => 'leave_submitted',
            'title' => '已删除旧通知',
            'body' => 'body',
            'data' => ['leave_request_id' => 999999, 'leave_batch_id' => 'batch_missing'],
        ]);
        $nEveningPending = AppNotification::create([
            'user_id' => $fx['teacher']->id,
            'type' => 'leave_submitted',
            'title' => '夜自习待审批',
            'body' => 'body',
            'data' => ['leave_request_id' => $pendingEveningRec->id, 'leave_batch_id' => 'batch_evening_pending'],
        ]);
        $nOtherType = AppNotification::create([
            'user_id' => $fx['teacher']->id,
            'type' => 'attendance_alert',
            'title' => '其他类型通知',
            'body' => 'body',
            'data' => [],
        ]);

        $cleaned = app(MobilePushService::class)->cleanupResolvedLeaveSubmittedNotifications();

        $this->assertSame(2, $cleaned);
        $this->assertNotNull($nDone->fresh()->read_at);
        $this->assertNotNull($nDeleted->fresh()->read_at);
        $this->assertNull($nEveningPending->fresh()->read_at);
        $this->assertNull($nOtherType->fresh()->read_at);
    }
}
