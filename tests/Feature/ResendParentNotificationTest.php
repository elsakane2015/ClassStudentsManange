<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\EmailNotificationLog;
use App\Models\EmailNotificationPreference;
use App\Models\Grade;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\ParentEmailNotificationService;
use App\Services\ResendEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ResendParentNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_resend_client_sends_expected_api_payload(): void
    {
        Http::fake([
            'api.resend.com/*' => Http::response(['id' => 'email_123'], 200),
        ]);

        $service = app(ResendEmailService::class);
        $service->storeApiKey('re_test_key');
        SystemSetting::set('resend_enabled', '1');
        SystemSetting::set('resend_from_email', 'attendance@example.com');
        SystemSetting::set('resend_from_name', 'Test School');
        $service->resetConfiguration();

        $result = $service->send(
            'parent@example.com',
            'Attendance notice',
            '<p>Test</p>',
            'test-idempotency-key'
        );

        $this->assertTrue($result['success']);
        $this->assertSame('email_123', $result['id']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.resend.com/emails'
                && $request->hasHeader('Authorization', 'Bearer re_test_key')
                && $request->hasHeader('User-Agent', 'ClassStudentsManange/1.0')
                && $request->hasHeader('Idempotency-Key', 'test-idempotency-key')
                && $request['from'] === 'Test School <attendance@example.com>'
                && $request['to'] === ['parent@example.com'];
        });
    }

    public function test_attendance_notification_uses_teacher_rules_and_deduplicates_periods(): void
    {
        Http::fake([
            'api.resend.com/*' => Http::response(['id' => 'email_attendance'], 200),
        ]);

        [$teacher, $student] = $this->createTeacherAndStudent();
        EmailNotificationPreference::create([
            'user_id' => $teacher->id,
            'enabled' => true,
            'enabled_events' => ['attendance_absent'],
        ]);

        $this->configureResend();

        $firstRecord = $this->createAttendanceRecord($student, 1, 'absent');
        $secondRecord = $this->createAttendanceRecord($student, 2, 'absent');
        $notifications = app(ParentEmailNotificationService::class);

        $firstResult = $notifications->sendAttendanceNotification($firstRecord);
        $secondResult = $notifications->sendAttendanceNotification($secondRecord);

        $this->assertTrue($firstResult['success']);
        $this->assertSame('duplicate', $secondResult['skipped']);
        Http::assertSentCount(1);
        $this->assertSame(1, EmailNotificationLog::count());

        Http::assertSent(function ($request) use ($student) {
            return $request['to'] === [$student->parent_email]
                && str_contains($request['subject'], '旷课')
                && str_contains($request['html'], $student->user->name)
                && str_contains($request['html'], '软件1班');
        });
    }

    public function test_disabled_teacher_event_does_not_send(): void
    {
        Http::fake();

        [$teacher, $student] = $this->createTeacherAndStudent();
        EmailNotificationPreference::create([
            'user_id' => $teacher->id,
            'enabled' => true,
            'enabled_events' => ['attendance_absent'],
        ]);
        $this->configureResend();

        $record = $this->createAttendanceRecord($student, 1, 'late');
        $result = app(ParentEmailNotificationService::class)->sendAttendanceNotification($record);

        $this->assertSame('event_disabled', $result['skipped']);
        Http::assertNothingSent();
        $this->assertSame(0, EmailNotificationLog::count());
    }

    public function test_leave_request_batch_sends_one_parent_notification(): void
    {
        Http::fake([
            'api.resend.com/*' => Http::response(['id' => 'email_leave_request'], 200),
        ]);

        [$teacher, $student] = $this->createTeacherAndStudent();
        EmailNotificationPreference::create([
            'user_id' => $teacher->id,
            'enabled' => true,
            'enabled_events' => ['leave_request_submitted'],
        ]);
        $this->configureResend();

        $batchId = (string) Str::uuid();
        $firstRecord = $this->createLeaveRequestRecord($student, $batchId, 1);
        $secondRecord = $this->createLeaveRequestRecord($student, $batchId, 2);
        $notifications = app(ParentEmailNotificationService::class);

        $firstResult = $notifications->sendLeaveRequestNotification($firstRecord);
        $secondResult = $notifications->sendLeaveRequestNotification($secondRecord);

        $this->assertTrue($firstResult['success']);
        $this->assertSame('duplicate', $secondResult['skipped']);
        Http::assertSentCount(1);
        $this->assertSame(1, EmailNotificationLog::count());

        Http::assertSent(function ($request) use ($student) {
            return $request['to'] === [$student->parent_email]
                && str_contains($request['subject'], '请假申请')
                && str_contains($request['html'], '身体不适');
        });
    }

    private function configureResend(): void
    {
        $service = app(ResendEmailService::class);
        $service->storeApiKey('re_test_key');
        SystemSetting::set('resend_enabled', '1');
        SystemSetting::set('resend_from_email', 'attendance@example.com');
        SystemSetting::set('resend_from_name', 'Test School');
        SystemSetting::set('resend_subject_template', ResendEmailService::DEFAULT_SUBJECT);
        SystemSetting::set('resend_html_template', ResendEmailService::DEFAULT_HTML);
        $service->resetConfiguration();
    }

    private function createTeacherAndStudent(): array
    {
        $school = School::create(['name' => 'Test School']);
        $grade = Grade::create(['school_id' => $school->id, 'name' => '2026级']);
        $teacher = User::create([
            'uuid' => (string) Str::uuid(),
            'name' => '王老师',
            'email' => 'teacher@example.com',
            'password' => bcrypt('password'),
            'role' => 'teacher',
            'status' => true,
        ]);
        $class = SchoolClass::create([
            'school_id' => $school->id,
            'grade_id' => $grade->id,
            'teacher_id' => $teacher->id,
            'name' => '软件1班',
        ]);
        $studentUser = User::create([
            'uuid' => (string) Str::uuid(),
            'name' => '张三',
            'email' => 'student@example.com',
            'password' => bcrypt('password'),
            'role' => 'student',
            'status' => true,
        ]);
        $student = Student::create([
            'user_id' => $studentUser->id,
            'school_id' => $school->id,
            'class_id' => $class->id,
            'student_no' => '2026001',
            'gender' => 'male',
            'parent_email' => 'parent@example.com',
        ]);

        return [$teacher, $student];
    }

    private function createAttendanceRecord(Student $student, int $periodId, string $status): AttendanceRecord
    {
        return AttendanceRecord::create([
            'student_id' => $student->id,
            'school_id' => $student->school_id,
            'class_id' => $student->class_id,
            'date' => '2026-07-21',
            'period_id' => $periodId,
            'status' => $status,
            'source_type' => 'manual',
        ]);
    }

    private function createLeaveRequestRecord(Student $student, string $batchId, int $periodId): AttendanceRecord
    {
        return AttendanceRecord::create([
            'student_id' => $student->id,
            'school_id' => $student->school_id,
            'class_id' => $student->class_id,
            'date' => '2026-07-21',
            'period_id' => $periodId,
            'status' => 'leave',
            'leave_batch_id' => $batchId,
            'is_self_applied' => true,
            'approval_status' => 'pending',
            'reason' => '身体不适',
            'source_type' => 'self_applied',
        ]);
    }
}
