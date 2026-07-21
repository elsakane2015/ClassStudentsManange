<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Services\SmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SmsController extends Controller
{
    public function __construct(private SmsService $sms)
    {
    }

    public function getSettings(Request $request)
    {
        $this->authorizeAdmin($request, false);
        $config = $this->sms->configuration();

        return response()->json([
            'sms_enabled' => $config['enabled'],
            'sms_provider' => $config['provider'],
            'sms_template_variables' => $config['template_variables'],
            'available_template_variables' => SmsService::TEMPLATE_VARIABLES,
            'aliyun' => [
                'access_key_id' => $config['aliyun']['access_key_id'] ? '******' : '',
                'access_key_secret' => $config['aliyun']['access_key_secret'] ? '******' : '',
                'sign_name' => $config['aliyun']['sign_name'],
                'template_code' => $config['aliyun']['template_code'],
            ],
            'tencent' => [
                'secret_id' => $config['tencent']['secret_id'] ? '******' : '',
                'secret_key' => $config['tencent']['secret_key'] ? '******' : '',
                'sdk_app_id' => $config['tencent']['sdk_app_id'],
                'sign_name' => $config['tencent']['sign_name'],
                'template_id' => $config['tencent']['template_id'],
                'region' => $config['tencent']['region'],
            ],
            'is_ready' => $this->sms->isReady(),
        ]);
    }

    public function saveSettings(Request $request)
    {
        $this->authorizeAdmin($request, true);
        $validated = $request->validate([
            'sms_enabled' => 'required|boolean',
            'sms_provider' => ['required', Rule::in(['aliyun', 'tencent'])],
            'sms_template_variables' => 'required|array|min:1',
            'sms_template_variables.*' => ['required', Rule::in(SmsService::TEMPLATE_VARIABLES)],
            'aliyun' => 'required|array',
            'aliyun.access_key_id' => 'nullable|string|max:255',
            'aliyun.access_key_secret' => 'nullable|string|max:500',
            'aliyun.sign_name' => 'nullable|string|max:100',
            'aliyun.template_code' => 'nullable|string|max:100',
            'tencent' => 'required|array',
            'tencent.secret_id' => 'nullable|string|max:255',
            'tencent.secret_key' => 'nullable|string|max:500',
            'tencent.sdk_app_id' => 'nullable|string|max:100',
            'tencent.sign_name' => 'nullable|string|max:100',
            'tencent.template_id' => 'nullable|string|max:100',
            'tencent.region' => 'nullable|string|max:100',
        ]);

        if ($validated['sms_enabled']) {
            $error = $this->configurationError($validated);
            if ($error) {
                return response()->json(['error' => $error], 422);
            }
        }

        DB::transaction(function () use ($validated) {
            $credentialFields = [
                'sms_aliyun_access_key_id' => $validated['aliyun']['access_key_id'] ?? '',
                'sms_aliyun_access_key_secret' => $validated['aliyun']['access_key_secret'] ?? '',
                'sms_tencent_secret_id' => $validated['tencent']['secret_id'] ?? '',
                'sms_tencent_secret_key' => $validated['tencent']['secret_key'] ?? '',
            ];
            foreach ($credentialFields as $key => $value) {
                if ($value && $value !== '******') {
                    $this->sms->storeCredential($key, $value);
                }
            }

            $settings = [
                'sms_enabled' => $validated['sms_enabled'] ? '1' : '0',
                'sms_provider' => $validated['sms_provider'],
                'sms_template_variables' => json_encode(array_values(array_unique($validated['sms_template_variables']))),
                'sms_aliyun_sign_name' => $validated['aliyun']['sign_name'] ?? '',
                'sms_aliyun_template_code' => $validated['aliyun']['template_code'] ?? '',
                'sms_tencent_sdk_app_id' => $validated['tencent']['sdk_app_id'] ?? '',
                'sms_tencent_sign_name' => $validated['tencent']['sign_name'] ?? '',
                'sms_tencent_template_id' => $validated['tencent']['template_id'] ?? '',
                'sms_tencent_region' => $validated['tencent']['region'] ?? 'ap-guangzhou',
            ];
            foreach ($settings as $key => $value) {
                SystemSetting::set($key, $value);
            }
        });

        $this->sms->resetConfiguration();

        return response()->json([
            'message' => '短信配置已保存',
            'is_ready' => $this->sms->isReady(),
        ]);
    }

    public function sendTest(Request $request)
    {
        $this->authorizeAdmin($request, true);
        $validated = $request->validate(['phone' => 'required|string|max:50']);
        $result = $this->sms->send($validated['phone'], [
            'student_name' => '测试学生',
            'student_no' => '2026001',
            'class_name' => '测试班级',
            'event_name' => '短信配置测试',
            'attendance_date' => now()->format('Y-m-d'),
            'period' => '第1节',
            'reason' => '系统配置测试',
            'teacher_name' => '测试老师',
            'school_name' => '考勤系统',
            'submitted_at' => now()->format('Y-m-d H:i'),
        ]);

        if (!$result['success']) {
            return response()->json(['error' => $result['error'] ?? '发送失败'], 422);
        }

        return response()->json(['message' => '测试短信已提交发送', 'id' => $result['id'] ?? null]);
    }

    private function configurationError(array $validated): ?string
    {
        if ($validated['sms_provider'] === 'aliyun') {
            $aliyun = $validated['aliyun'];
            $hasId = ($aliyun['access_key_id'] ?? '') === '******'
                ? $this->sms->hasCredential('sms_aliyun_access_key_id')
                : filled($aliyun['access_key_id'] ?? null);
            $hasSecret = ($aliyun['access_key_secret'] ?? '') === '******'
                ? $this->sms->hasCredential('sms_aliyun_access_key_secret')
                : filled($aliyun['access_key_secret'] ?? null);

            if (!$hasId || !$hasSecret || empty($aliyun['sign_name']) || empty($aliyun['template_code'])) {
                return '启用阿里云短信前必须填写 AccessKey、签名名称和模板 Code';
            }

            return null;
        }

        $tencent = $validated['tencent'];
        $hasId = ($tencent['secret_id'] ?? '') === '******'
            ? $this->sms->hasCredential('sms_tencent_secret_id')
            : filled($tencent['secret_id'] ?? null);
        $hasKey = ($tencent['secret_key'] ?? '') === '******'
            ? $this->sms->hasCredential('sms_tencent_secret_key')
            : filled($tencent['secret_key'] ?? null);

        if (!$hasId || !$hasKey || empty($tencent['sdk_app_id']) || empty($tencent['sign_name']) || empty($tencent['template_id']) || empty($tencent['region'])) {
            return '启用腾讯云短信前必须填写 Secret、SdkAppId、签名、模板 ID 和地域';
        }

        return null;
    }

    private function authorizeAdmin(Request $request, bool $write): void
    {
        $roles = $write
            ? ['system_admin', 'admin']
            : ['system_admin', 'school_admin', 'admin'];

        abort_unless(in_array($request->user()?->role, $roles, true), 403, '无权限');
    }
}
