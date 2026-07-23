<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\EmailNotificationLog;
use App\Models\EmailNotificationPreference;
use App\Models\LeaveType;
use App\Models\SmsNotificationLog;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\ParentEmailList;
use Illuminate\Support\Facades\Log;

class ParentEmailNotificationService
{
    public const DEFAULT_ENABLED_EVENTS = [
        'leave_request_submitted',
        'attendance_absent',
    ];

    private array $processedKeys = [];

    private array $preferenceCache = [];

    public function __construct(
        private ResendEmailService $resend,
        private PersonalEmailService $personalEmail,
        private ParentEmailTemplateService $templates,
        private SmsService $sms
    ) {}

    public function eventOptions(User $teacher): array
    {
        $options = [
            ['key' => 'leave_request_submitted', 'label' => '学生提交请假申请', 'group' => '申请通知'],
            ['key' => 'attendance_absent', 'label' => '旷课', 'group' => '考勤状态'],
            ['key' => 'attendance_late', 'label' => '迟到', 'group' => '考勤状态'],
            ['key' => 'attendance_early_leave', 'label' => '早退', 'group' => '考勤状态'],
        ];

        $schoolIds = $teacher->teacherClasses()->pluck('school_id')->filter()->unique();
        if ($schoolIds->isEmpty()) {
            return $options;
        }

        $leaveTypes = LeaveType::whereIn('school_id', $schoolIds)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        foreach ($leaveTypes as $leaveType) {
            if ($this->fixedEventKeyForSlug($leaveType->slug)) {
                continue;
            }

            $options[] = [
                'key' => 'leave_type:'.$leaveType->id,
                'label' => $leaveType->name,
                'group' => '请假/考勤类型',
            ];
        }

        return $options;
    }

    public function preferenceFor(User $teacher): array
    {
        if (isset($this->preferenceCache[$teacher->id])) {
            return $this->preferenceCache[$teacher->id];
        }

        $preference = EmailNotificationPreference::where('user_id', $teacher->id)->first();

        return $this->preferenceCache[$teacher->id] = [
            'enabled' => $preference?->enabled ?? true,
            'email_enabled' => $preference?->email_enabled ?? true,
            'email_provider' => $preference?->email_provider ?? 'system_resend',
            'email_fallback_to_resend' => $preference?->email_fallback_to_resend ?? false,
            'sms_enabled' => $preference?->sms_enabled ?? false,
            'enabled_events' => $preference?->enabled_events ?? self::DEFAULT_ENABLED_EVENTS,
        ];
    }

    public function clearPreferenceCache(?int $teacherId = null): void
    {
        if ($teacherId === null) {
            $this->preferenceCache = [];

            return;
        }

        unset($this->preferenceCache[$teacherId]);
    }

    public function sendLeaveRequestNotification(AttendanceRecord $record, ?string $emailRecipient = null): array
    {
        $record->loadMissing(['student.user', 'student.school', 'student.schoolClass.teacher', 'leaveType']);

        return $this->sendForRecord(
            $record,
            'leave_request_submitted',
            '请假申请',
            'leave_request',
            $record->id,
            $this->leaveRequestDedupeKey($record),
            $emailRecipient
        );
    }

    public function sendAttendanceNotification(AttendanceRecord $record, ?string $emailRecipient = null): array
    {
        if ($record->is_self_applied || in_array($record->source_type, ['self_applied', 'leave_request'], true)) {
            return ['success' => false, 'skipped' => 'leave_request_record'];
        }

        $record->loadMissing(['student.user', 'student.school', 'student.schoolClass.teacher', 'leaveType']);
        [$eventKey, $eventName] = $this->attendanceEvent($record);

        if (! $eventKey) {
            return ['success' => false, 'skipped' => 'unsupported_event'];
        }

        return $this->sendForRecord(
            $record,
            $eventKey,
            $eventName,
            'attendance_record',
            $record->id,
            $this->attendanceDedupeKey($record, $eventKey),
            $emailRecipient
        );
    }

    private function sendForRecord(
        AttendanceRecord $record,
        string $eventKey,
        string $eventName,
        string $relatedType,
        int $relatedId,
        string $dedupeKey,
        ?string $emailRecipient = null
    ): array {
        $student = $record->student;
        $class = $student?->schoolClass;
        $teacher = $class?->teacher;

        if (! $student || ! $class || ! $teacher) {
            return ['success' => false, 'skipped' => 'missing_student_or_teacher'];
        }

        $preference = $this->preferenceFor($teacher);
        if (! $preference['enabled'] || ! in_array($eventKey, $preference['enabled_events'], true)) {
            return ['success' => false, 'skipped' => 'event_disabled'];
        }

        if (! $preference['email_enabled'] && ! $preference['sms_enabled']) {
            return ['success' => false, 'skipped' => 'channels_disabled'];
        }

        $variables = $this->templateVariables($record, $eventName, $teacher);
        $results = [];

        if ($preference['email_enabled']) {
            $results['email'] = $this->sendEmailChannel(
                $record,
                $eventKey,
                $relatedType,
                $relatedId,
                $dedupeKey,
                $variables,
                $emailRecipient
            );
        }

        if ($preference['sms_enabled'] && $emailRecipient === null) {
            $results['sms'] = $this->sendSmsChannel(
                $record,
                $eventKey,
                $relatedType,
                $relatedId,
                $dedupeKey,
                $variables
            );
        }

        if (count($results) === 1) {
            return reset($results);
        }

        return [
            'success' => collect($results)->contains(fn ($result) => $result['success'] ?? false),
            'channels' => $results,
        ];
    }

    private function sendEmailChannel(
        AttendanceRecord $record,
        string $eventKey,
        string $relatedType,
        int $relatedId,
        string $dedupeKey,
        array $variables,
        ?string $emailRecipient = null
    ): array {
        $student = $record->student;
        $teacher = $student->schoolClass->teacher;
        $configuredRecipients = ParentEmailList::parse($student->parent_email);
        $recipients = $emailRecipient ? [$emailRecipient] : $configuredRecipients;
        if ($recipients === []) {
            return ['success' => false, 'skipped' => 'missing_email_recipient'];
        }

        $preference = $this->preferenceFor($teacher);
        $selectedProvider = $preference['email_provider'] === 'personal_email'
            ? 'personal_email'
            : 'system_resend';
        $fallbackEnabled = $selectedProvider === 'personal_email'
            && $preference['email_fallback_to_resend'];

        if ($selectedProvider === 'system_resend' && ! $this->resend->isReady()) {
            return ['success' => false, 'skipped' => 'resend_not_ready'];
        }
        if ($selectedProvider === 'personal_email'
            && ! $this->personalEmail->isReady($teacher)
            && ! ($fallbackEnabled && $this->resend->isReady())) {
            return ['success' => false, 'skipped' => 'personal_email_not_ready'];
        }

        $subject = $this->templates->renderSubject($variables);
        $html = $this->templates->renderHtml($variables);

        $results = [];
        $multipleRecipients = count($configuredRecipients) > 1;
        foreach ($recipients as $recipient) {
            $recipientDedupeKey = $multipleRecipients
                ? hash('sha256', $dedupeKey.'|'.mb_strtolower($recipient))
                : $dedupeKey;
            $results[$recipient] = $this->sendEmailToRecipient(
                $record,
                $teacher,
                $recipient,
                $eventKey,
                $relatedType,
                $relatedId,
                $recipientDedupeKey,
                $dedupeKey,
                $variables,
                $subject,
                $html,
                $selectedProvider,
                $fallbackEnabled
            );
        }

        if (count($results) === 1) {
            return reset($results);
        }

        $successfulCount = collect($results)->filter(fn ($result) => $result['success'] ?? false)->count();

        return [
            'success' => $successfulCount === count($results),
            'partial_success' => $successfulCount > 0 && $successfulCount < count($results),
            'recipient_count' => count($results),
            'successful_count' => $successfulCount,
            'recipients' => $results,
        ];
    }

    private function sendEmailToRecipient(
        AttendanceRecord $record,
        User $teacher,
        string $recipient,
        string $eventKey,
        string $relatedType,
        int $relatedId,
        string $dedupeKey,
        string $legacyDedupeKey,
        array $variables,
        string $subject,
        string $html,
        string $selectedProvider,
        bool $fallbackEnabled
    ): array {
        if (isset($this->processedKeys[$dedupeKey])) {
            return ['success' => true, 'skipped' => 'duplicate'];
        }
        $this->processedKeys[$dedupeKey] = true;

        $existingLog = EmailNotificationLog::where('dedupe_key', $dedupeKey)->first();
        if (! $existingLog && $dedupeKey !== $legacyDedupeKey) {
            $existingLog = EmailNotificationLog::where('dedupe_key', $legacyDedupeKey)
                ->where('recipient', $recipient)
                ->first();
        }
        if ($existingLog && in_array($existingLog->status, ['pending', 'success'], true)) {
            return ['success' => true, 'skipped' => 'duplicate'];
        }

        $log = $existingLog ?: new EmailNotificationLog(['dedupe_key' => $dedupeKey]);
        $log->fill([
            'student_id' => $record->student_id,
            'teacher_id' => $teacher->id,
            'recipient' => $recipient,
            'provider' => $selectedProvider,
            'sender_address' => $selectedProvider === 'personal_email'
                ? $teacher->teacherEmailAccount?->email
                : $this->resend->configuration()['from_email'],
            'event_key' => $eventKey,
            'subject' => $subject,
            'status' => 'pending',
            'attempt_count' => 1,
            'fallback_used' => false,
            'last_attempt_at' => now(),
            'error_message' => null,
            'related_type' => $relatedType,
            'related_id' => $relatedId,
            'payload' => [
                'variables' => $variables,
                'html' => $html,
            ],
        ])->save();

        $personalReady = $selectedProvider === 'personal_email' && $this->personalEmail->isReady($teacher);
        $attemptedProviders = [];
        $fallbackUsed = false;
        if ($personalReady) {
            $attemptedProviders[] = $teacher->teacherEmailAccount?->provider ?: 'personal_email';
            $result = $this->personalEmail->send($teacher, $recipient, $subject, $html);
        } elseif ($selectedProvider === 'personal_email') {
            if ($fallbackEnabled && $this->resend->isReady()) {
                $fallbackUsed = true;
                $attemptedProviders[] = 'system_resend';
                $result = $this->resend->send(
                    $recipient,
                    $subject,
                    $html,
                    'parent-notification-fallback-'.$dedupeKey
                );
            } else {
                $result = [
                    'success' => false,
                    'provider' => $teacher->teacherEmailAccount?->provider ?: 'personal_email',
                    'sender' => $teacher->teacherEmailAccount?->email,
                    'error' => '班主任个人邮箱尚未验证，且未启用可用的系统 Resend 回退',
                ];
            }
        } else {
            $attemptedProviders[] = 'system_resend';
            $result = $this->resend->send(
                $recipient,
                $subject,
                $html,
                'parent-notification-'.$dedupeKey
            );
        }

        if ($personalReady && ! $result['success'] && $fallbackEnabled && $this->resend->isReady()) {
            $fallbackUsed = true;
            $attemptedProviders[] = 'system_resend';
            $result = $this->resend->send(
                $recipient,
                $subject,
                $html,
                'parent-notification-fallback-'.$dedupeKey
            );
        }

        $log->update([
            'status' => $result['success'] ? 'success' : 'failed',
            'provider' => $result['provider'] ?? $selectedProvider,
            'sender_address' => $result['sender'] ?? $log->sender_address,
            'attempt_count' => count($attemptedProviders),
            'fallback_used' => $fallbackUsed,
            'last_attempt_at' => now(),
            'provider_message_id' => $result['id'] ?? null,
            'error_message' => $result['success'] ? null : ($result['error'] ?? '未知错误'),
            'payload' => [
                'variables' => $variables,
                'html' => $html,
                'attempted_providers' => $attemptedProviders,
            ],
        ]);

        if (! $result['success']) {
            Log::warning('Parent email notification failed', [
                'student_id' => $record->student_id,
                'recipient' => $recipient,
                'event_key' => $eventKey,
                'provider' => $result['provider'] ?? $selectedProvider,
                'error' => $result['error'] ?? '未知错误',
            ]);
        }

        return $result;
    }

    private function sendSmsChannel(
        AttendanceRecord $record,
        string $eventKey,
        string $relatedType,
        int $relatedId,
        string $baseDedupeKey,
        array $variables
    ): array {
        if (! $this->sms->isReady()) {
            return ['success' => false, 'skipped' => 'sms_not_ready'];
        }

        $student = $record->student;
        $teacher = $student->schoolClass->teacher;
        if (! $student->parent_contact || ! $this->sms->normalizeChinesePhone($student->parent_contact)) {
            return ['success' => false, 'skipped' => 'missing_sms_recipient'];
        }

        $dedupeKey = hash('sha256', 'sms|'.$baseDedupeKey);
        if (isset($this->processedKeys[$dedupeKey])) {
            return ['success' => true, 'skipped' => 'duplicate'];
        }
        $this->processedKeys[$dedupeKey] = true;

        $existingLog = SmsNotificationLog::where('dedupe_key', $dedupeKey)->first();
        if ($existingLog && in_array($existingLog->status, ['pending', 'success'], true)) {
            return ['success' => true, 'skipped' => 'duplicate'];
        }

        $provider = $this->sms->configuration()['provider'];
        $log = $existingLog ?: new SmsNotificationLog(['dedupe_key' => $dedupeKey]);
        $log->fill([
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'recipient' => $student->parent_contact,
            'provider' => $provider,
            'event_key' => $eventKey,
            'status' => 'pending',
            'error_message' => null,
            'related_type' => $relatedType,
            'related_id' => $relatedId,
            'payload' => [
                'variables' => $variables,
                'template_variables' => $this->sms->configuration()['template_variables'],
            ],
        ])->save();

        $result = $this->sms->send($student->parent_contact, $variables);
        $log->update([
            'status' => $result['success'] ? 'success' : 'failed',
            'provider_message_id' => $result['id'] ?? null,
            'error_message' => $result['success'] ? null : ($result['error'] ?? '未知错误'),
        ]);

        if (! $result['success']) {
            Log::warning('Parent SMS notification failed', [
                'student_id' => $student->id,
                'event_key' => $eventKey,
                'provider' => $provider,
                'error' => $result['error'] ?? '未知错误',
            ]);
        }

        return $result;
    }

    private function attendanceEvent(AttendanceRecord $record): array
    {
        $details = $record->details ?? [];
        $originalStatus = is_array($details) ? ($details['original_status'] ?? null) : null;

        $fixedKey = $this->fixedEventKeyForSlug((string) ($originalStatus ?: $record->status));
        if ($fixedKey) {
            return [$fixedKey, $this->eventLabel($fixedKey)];
        }

        if ($record->leaveType) {
            $fixedKey = $this->fixedEventKeyForSlug($record->leaveType->slug);
            if ($fixedKey) {
                return [$fixedKey, $this->eventLabel($fixedKey)];
            }

            if (in_array($record->status, ['leave', 'excused'], true)) {
                return ['leave_type:'.$record->leaveType->id, $record->leaveType->name];
            }
        }

        return [null, null];
    }

    private function fixedEventKeyForSlug(?string $slug): ?string
    {
        return match ($slug) {
            'absent', 'absence' => 'attendance_absent',
            'late' => 'attendance_late',
            'early_leave', 'early' => 'attendance_early_leave',
            default => null,
        };
    }

    private function eventLabel(string $eventKey): string
    {
        return match ($eventKey) {
            'attendance_absent' => '旷课',
            'attendance_late' => '迟到',
            'attendance_early_leave' => '早退',
            default => '考勤',
        };
    }

    private function templateVariables(AttendanceRecord $record, string $eventName, User $teacher): array
    {
        $student = $record->student;
        $class = $student->schoolClass;
        $school = $student->school;

        return [
            'student_name' => $student->user?->name ?: '学生',
            'student_no' => $student->student_no ?: '-',
            'class_name' => $class?->name ?: '-',
            'event_name' => $eventName,
            'attendance_date' => $this->dateLabel($record),
            'period' => $this->periodLabel($record),
            'reason' => $record->reason ?: ($record->note ?: '无'),
            'teacher_name' => $teacher->name ?: '-',
            'school_name' => $school?->name ?: config('app.name', '学校'),
            'submitted_at' => now()->format('Y-m-d H:i'),
        ];
    }

    private function dateLabel(AttendanceRecord $record): string
    {
        if ($record->leave_batch_id) {
            $range = AttendanceRecord::where('leave_batch_id', $record->leave_batch_id)
                ->selectRaw('MIN(date) as start_date, MAX(date) as end_date')
                ->first();

            if ($range?->start_date && $range?->end_date && $range->start_date !== $range->end_date) {
                return $range->start_date.' 至 '.$range->end_date;
            }

            if ($range?->start_date) {
                return $range->start_date;
            }
        }

        return $record->date?->format('Y-m-d') ?: '-';
    }

    private function periodLabel(AttendanceRecord $record): string
    {
        $details = $record->details ?? [];
        if (is_array($details)) {
            foreach (['display_label', 'time_slot_name', 'option_label'] as $key) {
                if (! empty($details[$key])) {
                    return (string) $details[$key];
                }
            }

            if (! empty($details['period_names']) && is_array($details['period_names'])) {
                return implode('、', $details['period_names']);
            }

            $optionLabels = [
                'am' => '上午',
                'pm' => '下午',
                'morning_half' => '上午',
                'afternoon_half' => '下午',
                'full_day' => '全天',
            ];
            if (! empty($details['option']) && isset($optionLabels[$details['option']])) {
                return $optionLabels[$details['option']];
            }
        }

        if ($record->period_id !== null) {
            $periods = json_decode((string) SystemSetting::get('attendance_periods', '[]'), true) ?: [];
            foreach ($periods as $period) {
                if ((int) ($period['id'] ?? 0) === (int) $record->period_id) {
                    return $period['name'] ?? '第'.$record->period_id.'节';
                }
            }

            return '第'.$record->period_id.'节';
        }

        return '全天';
    }

    private function leaveRequestDedupeKey(AttendanceRecord $record): string
    {
        return hash('sha256', 'leave-request|'.($record->leave_batch_id ?: $record->id));
    }

    private function attendanceDedupeKey(AttendanceRecord $record, string $eventKey): string
    {
        $timeBucket = ($record->updated_at ?: now())->format('YmdHi');
        $sourceIdentity = $record->source_id ?: 'manual';

        return hash('sha256', implode('|', [
            $eventKey,
            $record->student_id,
            $record->date?->format('Y-m-d'),
            $record->source_type,
            $sourceIdentity,
            $timeBucket,
        ]));
    }
}
