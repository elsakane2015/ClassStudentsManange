<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailNotificationLog;
use App\Services\ParentEmailTemplateService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmailNotificationLogController extends Controller
{
    public function __construct(private ParentEmailTemplateService $templates) {}

    public function index(Request $request)
    {
        $teacher = $this->authorizeTeacher($request);
        $validated = $request->validate([
            'page' => 'sometimes|integer|min:1',
            'per_page' => ['sometimes', 'integer', Rule::in([10, 20, 50])],
        ]);

        $logs = EmailNotificationLog::where('teacher_id', $teacher->id)
            ->latest()
            ->paginate($validated['per_page'] ?? 10);

        return response()->json([
            'data' => collect($logs->items())->map(fn (EmailNotificationLog $log) => $this->summary($log)),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'from' => $logs->firstItem(),
                'to' => $logs->lastItem(),
            ],
        ]);
    }

    public function show(Request $request, EmailNotificationLog $emailNotificationLog)
    {
        $teacher = $this->authorizeTeacher($request);
        $this->authorizeLog($emailNotificationLog, $teacher->id);

        $payload = $emailNotificationLog->payload ?: [];
        $variables = is_array($payload['variables'] ?? null) ? $payload['variables'] : [];

        return response()->json([
            ...$this->summary($emailNotificationLog),
            'recipient' => $emailNotificationLog->recipient,
            'sender_address' => $emailNotificationLog->sender_address,
            'provider_message_id' => $emailNotificationLog->provider_message_id,
            'last_attempt_at' => $emailNotificationLog->last_attempt_at,
            'html' => $payload['html'] ?? ($variables ? $this->templates->renderHtml($variables) : ''),
        ]);
    }

    public function destroy(Request $request, EmailNotificationLog $emailNotificationLog)
    {
        $teacher = $this->authorizeTeacher($request);
        $this->authorizeLog($emailNotificationLog, $teacher->id);
        $emailNotificationLog->delete();

        return response()->json(['message' => '邮件发送记录已删除']);
    }

    public function bulkDestroy(Request $request)
    {
        $teacher = $this->authorizeTeacher($request);
        $validated = $request->validate([
            'ids' => 'required|array|min:1|max:50',
            'ids.*' => 'required|integer|distinct|exists:email_notification_logs,id',
        ]);

        $ownedQuery = EmailNotificationLog::where('teacher_id', $teacher->id)
            ->whereIn('id', $validated['ids']);
        abort_unless($ownedQuery->count() === count($validated['ids']), 403, '包含无权删除的发送记录');

        $deleted = $ownedQuery->delete();

        return response()->json([
            'message' => "已删除 {$deleted} 条邮件发送记录",
            'deleted_count' => $deleted,
        ]);
    }

    private function summary(EmailNotificationLog $log): array
    {
        return [
            'id' => $log->id,
            'recipient' => $this->maskEmail($log->recipient),
            'event_key' => $log->event_key,
            'subject' => $log->subject,
            'provider' => $log->provider ?: 'system_resend',
            'status' => $log->status,
            'attempt_count' => $log->attempt_count,
            'fallback_used' => $log->fallback_used,
            'error_message' => $log->error_message,
            'created_at' => $log->created_at,
        ];
    }

    private function authorizeTeacher(Request $request)
    {
        $user = $request->user();
        abort_unless($user?->role === 'teacher', 403, '仅班主任可查看邮件发送记录');

        return $user;
    }

    private function authorizeLog(EmailNotificationLog $log, int $teacherId): void
    {
        abort_unless($log->teacher_id === $teacherId, 403, '无权操作该发送记录');
    }

    private function maskEmail(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');
        if ($domain === '') {
            return '***';
        }

        return mb_substr($name, 0, 1).'***@'.$domain;
    }
}
