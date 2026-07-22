<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\EmailNotificationLog;
use App\Models\EmailNotificationPreference;
use App\Models\Grade;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\SmsNotificationLog;
use App\Models\Student;
use App\Models\SystemSetting;
use App\Models\TeacherEmailAccount;
use App\Models\User;
use App\Services\ParentEmailNotificationService;
use App\Services\PersonalEmailService;
use App\Services\ResendEmailService;
use App\Services\Sms\AlibabaSmsProvider;
use App\Services\Sms\TencentSmsProvider;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
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

    public function test_sms_providers_build_expected_template_parameters(): void
    {
        $aliyun = new AlibabaSmsProvider;
        $aliyunRequest = $aliyun->buildRequestData([
            'sign_name' => '测试签名',
            'template_code' => 'SMS_123456',
        ], '13800000000', [
            'student_name' => '张三',
            'event_name' => '旷课',
        ]);

        $this->assertSame('13800000000', $aliyunRequest['phoneNumbers']);
        $this->assertSame('SMS_123456', $aliyunRequest['templateCode']);
        $this->assertSame(
            ['student_name' => '张三', 'event_name' => '旷课'],
            json_decode($aliyunRequest['templateParam'], true)
        );

        $tencent = new TencentSmsProvider;
        $tencentRequest = $tencent->buildRequestData([
            'sdk_app_id' => '1400000000',
            'sign_name' => '测试签名',
            'template_id' => '123456',
        ], '+8613800000000', ['张三', '旷课']);

        $this->assertSame(['+8613800000000'], $tencentRequest['PhoneNumberSet']);
        $this->assertSame(['张三', '旷课'], $tencentRequest['TemplateParamSet']);
        $this->assertSame('1400000000', $tencentRequest['SmsSdkAppId']);
    }

    public function test_teacher_can_send_sms_without_sending_email(): void
    {
        Http::fake();
        [$teacher, $student] = $this->createTeacherAndStudent();
        EmailNotificationPreference::create([
            'user_id' => $teacher->id,
            'enabled' => true,
            'email_enabled' => false,
            'sms_enabled' => true,
            'enabled_events' => ['attendance_absent'],
        ]);
        $this->configureSms();

        $provider = \Mockery::mock(AlibabaSmsProvider::class);
        $provider->shouldReceive('send')
            ->once()
            ->withArgs(function ($config, $phone, $named, $ordered) {
                return $phone === '13800000000'
                    && $named['student_name'] === '张三'
                    && $named['event_name'] === '旷课'
                    && $ordered[0] === '张三';
            })
            ->andReturn(['success' => true, 'id' => 'sms_123', 'provider' => 'aliyun']);
        $this->app->instance(AlibabaSmsProvider::class, $provider);

        $firstRecord = $this->createAttendanceRecord($student, 1, 'absent');
        $secondRecord = $this->createAttendanceRecord($student, 2, 'absent');
        $result = app(ParentEmailNotificationService::class)->sendAttendanceNotification($secondRecord);

        $this->assertSame('duplicate', $result['skipped']);
        $this->assertSame(1, SmsNotificationLog::count());
        $this->assertSame(0, EmailNotificationLog::count());
        Http::assertNothingSent();
    }

    public function test_teacher_smtp_authorization_code_is_encrypted_and_provider_domain_is_validated(): void
    {
        [$teacher] = $this->createTeacherAndStudent();
        Sanctum::actingAs($teacher);

        $this->postJson('/api/teacher-email/account', [
            'provider' => 'qq',
            'email' => 'teacher@qq.com',
            'from_name' => '王老师',
            'authorization_code' => 'qq-authorization-code',
        ])->assertOk()
            ->assertJsonPath('account.provider', 'qq')
            ->assertJsonPath('account.email', 'teacher@qq.com')
            ->assertJsonPath('account.is_verified', false)
            ->assertJsonMissingPath('account.secret');

        $account = TeacherEmailAccount::where('user_id', $teacher->id)->firstOrFail();
        $this->assertSame('qq-authorization-code', $account->secret);
        $this->assertNotSame(
            'qq-authorization-code',
            DB::table('teacher_email_accounts')->where('user_id', $teacher->id)->value('secret')
        );

        $this->postJson('/api/teacher-email/account', [
            'provider' => 'netease_163',
            'email' => 'teacher@qq.com',
            'authorization_code' => 'another-code',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_parent_notification_uses_verified_teacher_personal_email(): void
    {
        [$teacher, $student] = $this->createTeacherAndStudent();
        EmailNotificationPreference::create([
            'user_id' => $teacher->id,
            'enabled' => true,
            'email_enabled' => true,
            'email_provider' => 'personal_email',
            'email_fallback_to_resend' => false,
            'enabled_events' => ['attendance_absent'],
        ]);
        TeacherEmailAccount::create([
            'user_id' => $teacher->id,
            'provider' => 'qq',
            'email' => 'teacher@qq.com',
            'from_name' => '王老师',
            'secret' => 'authorization-code',
            'is_verified' => true,
            'verified_at' => now(),
        ]);

        $personalEmail = \Mockery::mock(PersonalEmailService::class);
        $personalEmail->shouldReceive('isReady')->andReturn(true);
        $personalEmail->shouldReceive('send')
            ->once()
            ->withArgs(fn ($sender, $to, $subject, $html) => $sender->is($teacher)
                && $to === $student->parent_email
                && str_contains($subject, '旷课')
                && str_contains($html, '张三'))
            ->andReturn([
                'success' => true,
                'provider' => 'qq',
                'sender' => 'teacher@qq.com',
            ]);
        $this->app->instance(PersonalEmailService::class, $personalEmail);

        $record = $this->createAttendanceRecord($student, 1, 'absent');
        $result = app(ParentEmailNotificationService::class)->sendAttendanceNotification($record);

        $this->assertTrue($result['success']);
        $this->assertDatabaseHas('email_notification_logs', [
            'teacher_id' => $teacher->id,
            'provider' => 'qq',
            'sender_address' => 'teacher@qq.com',
            'status' => 'success',
            'fallback_used' => false,
        ]);
    }

    public function test_personal_email_failure_can_fallback_to_system_resend(): void
    {
        Http::fake(['api.resend.com/*' => Http::response(['id' => 'fallback_email'], 200)]);
        [$teacher, $student] = $this->createTeacherAndStudent();
        EmailNotificationPreference::create([
            'user_id' => $teacher->id,
            'enabled' => true,
            'email_enabled' => true,
            'email_provider' => 'personal_email',
            'email_fallback_to_resend' => true,
            'enabled_events' => ['attendance_absent'],
        ]);
        TeacherEmailAccount::create([
            'user_id' => $teacher->id,
            'provider' => 'netease_163',
            'email' => 'teacher@163.com',
            'secret' => 'authorization-code',
            'is_verified' => true,
        ]);
        $this->configureResend();

        $personalEmail = \Mockery::mock(PersonalEmailService::class);
        $personalEmail->shouldReceive('isReady')->andReturn(true);
        $personalEmail->shouldReceive('send')->once()->andReturn([
            'success' => false,
            'provider' => 'netease_163',
            'sender' => 'teacher@163.com',
            'error' => 'SMTP rate limited',
        ]);
        $this->app->instance(PersonalEmailService::class, $personalEmail);

        $record = $this->createAttendanceRecord($student, 1, 'absent');
        $result = app(ParentEmailNotificationService::class)->sendAttendanceNotification($record);

        $this->assertTrue($result['success']);
        $this->assertDatabaseHas('email_notification_logs', [
            'provider' => 'system_resend',
            'attempt_count' => 2,
            'fallback_used' => true,
            'provider_message_id' => 'fallback_email',
            'status' => 'success',
        ]);
        Http::assertSentCount(1);
    }

    public function test_unverified_personal_email_uses_system_resend_fallback_once(): void
    {
        Http::fake(['api.resend.com/*' => Http::response(['id' => 'unverified_fallback'], 200)]);
        [$teacher, $student] = $this->createTeacherAndStudent();
        EmailNotificationPreference::create([
            'user_id' => $teacher->id,
            'enabled' => true,
            'email_enabled' => true,
            'email_provider' => 'personal_email',
            'email_fallback_to_resend' => true,
            'enabled_events' => ['attendance_absent'],
        ]);
        TeacherEmailAccount::create([
            'user_id' => $teacher->id,
            'provider' => 'qq',
            'email' => 'teacher@qq.com',
            'secret' => 'authorization-code',
            'is_verified' => false,
        ]);
        $this->configureResend();

        $record = $this->createAttendanceRecord($student, 1, 'absent');
        $result = app(ParentEmailNotificationService::class)->sendAttendanceNotification($record);

        $this->assertTrue($result['success']);
        $this->assertDatabaseHas('email_notification_logs', [
            'provider' => 'system_resend',
            'attempt_count' => 1,
            'fallback_used' => true,
            'provider_message_id' => 'unverified_fallback',
            'status' => 'success',
        ]);
        Http::assertSentCount(1);
    }

    public function test_unverified_personal_email_does_not_silently_use_resend_without_fallback(): void
    {
        Http::fake();
        [$teacher, $student] = $this->createTeacherAndStudent();
        EmailNotificationPreference::create([
            'user_id' => $teacher->id,
            'enabled' => true,
            'email_enabled' => true,
            'email_provider' => 'personal_email',
            'email_fallback_to_resend' => false,
            'enabled_events' => ['attendance_absent'],
        ]);
        TeacherEmailAccount::create([
            'user_id' => $teacher->id,
            'provider' => 'qq',
            'email' => 'teacher@qq.com',
            'secret' => 'authorization-code',
            'is_verified' => false,
        ]);
        $this->configureResend();

        $record = $this->createAttendanceRecord($student, 1, 'absent');
        app(ParentEmailNotificationService::class)->sendAttendanceNotification($record);

        $this->assertDatabaseCount('email_notification_logs', 0);
        Http::assertNothingSent();
    }

    public function test_teacher_can_connect_and_send_with_microsoft_graph(): void
    {
        config([
            'services.microsoft_mail.client_id' => 'microsoft-client-id',
            'services.microsoft_mail.client_secret' => 'microsoft-client-secret',
            'services.microsoft_mail.redirect_uri' => 'https://school.example.com/api/teacher-email/microsoft/callback',
        ]);
        [$teacher] = $this->createTeacherAndStudent();
        Sanctum::actingAs($teacher);

        $connect = $this->postJson('/api/teacher-email/microsoft/connect')->assertOk();
        $authorizationUrl = $connect->json('authorization_url');
        parse_str(parse_url($authorizationUrl, PHP_URL_QUERY), $query);
        $this->assertSame('microsoft-client-id', $query['client_id']);

        Http::fake([
            'login.microsoftonline.com/*' => Http::response([
                'access_token' => 'microsoft-access-token',
                'refresh_token' => 'microsoft-refresh-token',
                'expires_in' => 3600,
            ], 200),
            'graph.microsoft.com/v1.0/me?*' => Http::response([
                'displayName' => '王老师',
                'mail' => 'teacher@outlook.com',
                'userPrincipalName' => 'teacher@outlook.com',
            ], 200),
            'graph.microsoft.com/v1.0/me/sendMail' => Http::response(null, 202),
        ]);

        $this->get('/api/teacher-email/microsoft/callback?'.http_build_query([
            'code' => 'authorization-code',
            'state' => $query['state'],
        ]))->assertRedirect('/teacher/email-notifications?email_connection=success');

        $account = TeacherEmailAccount::where('user_id', $teacher->id)->firstOrFail();
        $this->assertTrue($account->is_verified);
        $this->assertSame('microsoft-refresh-token', $account->refresh_token);
        $this->assertNotSame(
            'microsoft-refresh-token',
            DB::table('teacher_email_accounts')->where('user_id', $teacher->id)->value('refresh_token')
        );

        $teacher->unsetRelation('teacherEmailAccount');
        $result = app(PersonalEmailService::class)->send(
            $teacher,
            'parent@example.com',
            '考勤通知',
            '<p>测试</p>'
        );
        $this->assertTrue($result['success']);
        $this->assertSame('microsoft', $result['provider']);
        Http::assertSent(fn ($request) => $request->url() === 'https://graph.microsoft.com/v1.0/me/sendMail'
            && $request['message']['toRecipients'][0]['emailAddress']['address'] === 'parent@example.com');
    }

    public function test_teacher_can_view_masked_logs_and_retry_failed_email(): void
    {
        [$teacher, $student] = $this->createTeacherAndStudent();
        $record = $this->createAttendanceRecord($student, 1, 'absent');
        $this->app->forgetInstance(ParentEmailNotificationService::class);
        $this->configureResend();
        Http::fake([
            'api.resend.com/*' => Http::sequence()
                ->push(['message' => 'temporary failure'], 500)
                ->push(['id' => 'retry_success'], 200),
        ]);

        $failed = app(ParentEmailNotificationService::class)->sendAttendanceNotification($record);
        $this->assertFalse($failed['success']);
        $log = EmailNotificationLog::where('teacher_id', $teacher->id)->firstOrFail();
        $this->assertSame('failed', $log->status);

        $this->app->forgetInstance(ParentEmailNotificationService::class);
        Sanctum::actingAs($teacher);

        $settings = $this->getJson('/api/resend/teacher-settings')->assertOk();
        $this->assertSame('p***@example.com', $settings->json('email_logs.0.recipient'));
        $this->assertSame('failed', $settings->json('email_logs.0.status'));

        $this->postJson("/api/teacher-email/logs/{$log->id}/retry")
            ->assertOk()
            ->assertJsonPath('message', '邮件已重新发送');

        $this->assertDatabaseHas('email_notification_logs', [
            'id' => $log->id,
            'status' => 'success',
            'provider_message_id' => 'retry_success',
        ]);
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

    private function configureSms(): void
    {
        $service = app(SmsService::class);
        $service->storeCredential('sms_aliyun_access_key_id', 'test-access-key-id');
        $service->storeCredential('sms_aliyun_access_key_secret', 'test-access-key-secret');
        SystemSetting::set('sms_enabled', '1');
        SystemSetting::set('sms_provider', 'aliyun');
        SystemSetting::set('sms_aliyun_sign_name', '测试签名');
        SystemSetting::set('sms_aliyun_template_code', 'SMS_123456');
        SystemSetting::set('sms_template_variables', json_encode(SmsService::DEFAULT_TEMPLATE_VARIABLES));
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
            'parent_contact' => '13800000000',
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
