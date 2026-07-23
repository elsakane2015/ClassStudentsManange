<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\EmailNotificationLog;
use App\Services\ParentEmailNotificationService;
use App\Services\PersonalEmailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class TeacherEmailAccountController extends Controller
{
    public function __construct(
        private PersonalEmailService $personalEmail,
        private ParentEmailNotificationService $notifications
    ) {}

    public function store(Request $request)
    {
        $teacher = $this->authorizeTeacher($request);
        $validated = $request->validate([
            'provider' => ['required', Rule::in(array_keys(PersonalEmailService::SMTP_PROVIDERS))],
            'email' => 'required|email:rfc|max:255',
            'from_name' => 'nullable|string|max:255',
            'authorization_code' => 'nullable|string|max:500',
        ]);

        $this->personalEmail->saveSmtpAccount($teacher, $validated);

        return response()->json([
            'message' => '个人邮箱配置已保存，请发送测试邮件完成验证',
            'account' => $this->personalEmail->publicConfiguration($teacher->fresh()),
        ]);
    }

    public function test(Request $request)
    {
        $teacher = $this->authorizeTeacher($request);
        $result = $this->personalEmail->sendTest($teacher);
        if (! $result['success']) {
            return response()->json(['error' => $result['error'] ?? '发送失败'], 422);
        }

        return response()->json([
            'message' => '测试邮件发送成功，个人邮箱已启用',
            'account' => $this->personalEmail->publicConfiguration($teacher->fresh()),
        ]);
    }

    public function destroy(Request $request)
    {
        $teacher = $this->authorizeTeacher($request);
        $this->personalEmail->disconnect($teacher);

        return response()->json(['message' => '个人邮箱连接已删除']);
    }

    public function microsoftConnect(Request $request)
    {
        $teacher = $this->authorizeTeacher($request);

        return response()->json([
            'authorization_url' => $this->personalEmail->microsoftAuthorizationUrl($teacher),
        ]);
    }

    public function microsoftCallback(Request $request)
    {
        if ($request->filled('error')) {
            return redirect('/teacher/email-notifications?email_connection=error');
        }

        $validated = $request->validate([
            'code' => 'required|string',
            'state' => 'required|string',
        ]);

        try {
            $this->personalEmail->completeMicrosoftAuthorization($validated['code'], $validated['state']);

            return redirect('/teacher/email-notifications?email_connection=success');
        } catch (\Throwable $exception) {
            Log::warning('Microsoft email authorization failed', ['error' => $exception->getMessage()]);

            return redirect('/teacher/email-notifications?email_connection=error');
        }
    }

    public function retry(Request $request, EmailNotificationLog $emailNotificationLog)
    {
        $teacher = $this->authorizeTeacher($request);
        abort_unless($emailNotificationLog->teacher_id === $teacher->id, 403, '无权操作该发送记录');
        abort_unless($emailNotificationLog->status === 'failed', 422, '只有发送失败的邮件可以重试');

        $record = AttendanceRecord::withoutGlobalScope('day_attendance')->find($emailNotificationLog->related_id);
        abort_unless($record, 404, '关联的考勤记录不存在');

        $result = $emailNotificationLog->event_key === 'leave_request_submitted'
            ? $this->notifications->sendLeaveRequestNotification($record, $emailNotificationLog->recipient)
            : $this->notifications->sendAttendanceNotification($record, $emailNotificationLog->recipient);
        if (! ($result['success'] ?? false)) {
            return response()->json([
                'error' => $result['error'] ?? $result['skipped'] ?? '重新发送失败',
            ], 422);
        }

        return response()->json(['message' => '邮件已重新发送']);
    }

    private function authorizeTeacher(Request $request)
    {
        $user = $request->user();
        abort_unless($user?->role === 'teacher', 403, '仅班主任可配置');

        return $user;
    }
}
