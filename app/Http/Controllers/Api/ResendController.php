<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailNotificationPreference;
use App\Models\Student;
use App\Models\SystemSetting;
use App\Services\ParentEmailNotificationService;
use App\Services\ResendEmailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ResendController extends Controller
{
    public function __construct(
        private ResendEmailService $resend,
        private ParentEmailNotificationService $notifications
    ) {
    }

    public function getSettings(Request $request)
    {
        $this->authorizeAdmin($request, false);
        $config = $this->resend->configuration();

        return response()->json([
            'resend_enabled' => $config['enabled'],
            'resend_api_key' => $config['api_key'] ? '******' : '',
            'resend_from_email' => $config['from_email'],
            'resend_from_name' => $config['from_name'],
            'resend_reply_to' => $config['reply_to'],
            'resend_subject_template' => $config['subject_template'],
            'resend_html_template' => $config['html_template'],
            'is_ready' => $this->resend->isReady(),
        ]);
    }

    public function saveSettings(Request $request)
    {
        $this->authorizeAdmin($request, true);

        $validated = $request->validate([
            'resend_enabled' => 'required|boolean',
            'resend_api_key' => 'nullable|string|max:500',
            'resend_from_email' => 'nullable|email:rfc|max:255',
            'resend_from_name' => 'nullable|string|max:255',
            'resend_reply_to' => 'nullable|email:rfc|max:255',
            'resend_subject_template' => 'required|string|max:500',
            'resend_html_template' => 'required|string|max:50000',
        ]);

        $incomingKey = $validated['resend_api_key'] ?? '';
        $hasKey = ($incomingKey && $incomingKey !== '******') || $this->resend->hasApiKey();
        if ($validated['resend_enabled'] && (!$hasKey || empty($validated['resend_from_email']))) {
            return response()->json(['error' => '启用 Resend 前必须填写 API Key 和发件邮箱'], 422);
        }

        DB::transaction(function () use ($validated, $incomingKey) {
            if ($incomingKey && $incomingKey !== '******') {
                $this->resend->storeApiKey($incomingKey);
            }

            $settings = [
                'resend_enabled' => $validated['resend_enabled'] ? '1' : '0',
                'resend_from_email' => $validated['resend_from_email'] ?? '',
                'resend_from_name' => $validated['resend_from_name'] ?? '',
                'resend_reply_to' => $validated['resend_reply_to'] ?? '',
                'resend_subject_template' => $validated['resend_subject_template'],
                'resend_html_template' => $validated['resend_html_template'],
            ];

            foreach ($settings as $key => $value) {
                SystemSetting::set($key, $value);
            }
        });

        $this->resend->resetConfiguration();

        return response()->json([
            'message' => 'Resend 配置已保存',
            'is_ready' => $this->resend->isReady(),
        ]);
    }

    public function sendTest(Request $request)
    {
        $this->authorizeAdmin($request, true);
        $validated = $request->validate(['email' => 'required|email:rfc|max:255']);

        $result = $this->resend->send(
            $validated['email'],
            'Resend 邮件发送测试',
            '<div style="font-family:Arial,sans-serif;padding:24px"><h2>配置成功</h2><p>这是一封来自考勤系统的 Resend 测试邮件。</p></div>',
            'resend-test-'.Str::uuid()
        );

        if (!$result['success']) {
            return response()->json(['error' => $result['error'] ?? '发送失败'], 422);
        }

        return response()->json(['message' => '测试邮件已提交发送', 'id' => $result['id'] ?? null]);
    }

    public function getTeacherSettings(Request $request)
    {
        $teacher = $this->authorizeTeacher($request);
        $preference = $this->notifications->preferenceFor($teacher);
        $classIds = $teacher->teacherClasses()->pluck('id');

        $studentQuery = Student::whereIn('class_id', $classIds);
        $studentCount = (clone $studentQuery)->count();
        $missingParentEmailCount = (clone $studentQuery)
            ->where(function ($query) {
                $query->whereNull('parent_email')->orWhere('parent_email', '');
            })
            ->count();

        return response()->json([
            'enabled' => $preference['enabled'],
            'enabled_events' => $preference['enabled_events'],
            'events' => $this->notifications->eventOptions($teacher),
            'resend_ready' => $this->resend->isReady(),
            'student_count' => $studentCount,
            'missing_parent_email_count' => $missingParentEmailCount,
        ]);
    }

    public function saveTeacherSettings(Request $request)
    {
        $teacher = $this->authorizeTeacher($request);
        $availableKeys = collect($this->notifications->eventOptions($teacher))->pluck('key')->all();

        $validated = $request->validate([
            'enabled' => 'required|boolean',
            'enabled_events' => 'required|array',
            'enabled_events.*' => 'required|string',
        ]);

        $invalidKeys = array_diff($validated['enabled_events'], $availableKeys);
        if ($invalidKeys) {
            return response()->json(['error' => '包含无效的通知类型'], 422);
        }

        EmailNotificationPreference::updateOrCreate(
            ['user_id' => $teacher->id],
            [
                'enabled' => $validated['enabled'],
                'enabled_events' => array_values(array_unique($validated['enabled_events'])),
            ]
        );
        $this->notifications->clearPreferenceCache($teacher->id);

        return response()->json(['message' => '邮件通知规则已保存']);
    }

    private function authorizeAdmin(Request $request, bool $write): void
    {
        $roles = $write
            ? ['system_admin', 'admin']
            : ['system_admin', 'school_admin', 'admin'];

        abort_unless(in_array($request->user()?->role, $roles, true), 403, '无权限');
    }

    private function authorizeTeacher(Request $request)
    {
        $user = $request->user();
        abort_unless($user?->role === 'teacher', 403, '仅班主任可配置');

        return $user;
    }
}
