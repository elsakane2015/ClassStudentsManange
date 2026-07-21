<?php

namespace App\Services;

use App\Models\SystemSetting;
use App\Services\Sms\AlibabaSmsProvider;
use App\Services\Sms\SmsProvider;
use App\Services\Sms\TencentSmsProvider;
use Illuminate\Support\Facades\Crypt;

class SmsService
{
    public const TEMPLATE_VARIABLES = [
        'student_name',
        'student_no',
        'class_name',
        'event_name',
        'attendance_date',
        'period',
        'reason',
        'teacher_name',
        'school_name',
        'submitted_at',
    ];

    public const DEFAULT_TEMPLATE_VARIABLES = [
        'student_name',
        'event_name',
        'attendance_date',
        'period',
        'teacher_name',
    ];

    private ?array $configuration = null;

    public function configuration(): array
    {
        if ($this->configuration !== null) {
            return $this->configuration;
        }

        $variables = json_decode((string) SystemSetting::get('sms_template_variables', '[]'), true);
        if (!is_array($variables) || !$variables) {
            $variables = self::DEFAULT_TEMPLATE_VARIABLES;
        }
        $variables = array_values(array_intersect($variables, self::TEMPLATE_VARIABLES));

        return $this->configuration = [
            'enabled' => SystemSetting::get('sms_enabled', '0') === '1',
            'provider' => SystemSetting::get('sms_provider', 'aliyun'),
            'template_variables' => $variables,
            'aliyun' => [
                'access_key_id' => $this->decrypt(SystemSetting::get('sms_aliyun_access_key_id')),
                'access_key_secret' => $this->decrypt(SystemSetting::get('sms_aliyun_access_key_secret')),
                'sign_name' => trim((string) SystemSetting::get('sms_aliyun_sign_name', '')),
                'template_code' => trim((string) SystemSetting::get('sms_aliyun_template_code', '')),
            ],
            'tencent' => [
                'secret_id' => $this->decrypt(SystemSetting::get('sms_tencent_secret_id')),
                'secret_key' => $this->decrypt(SystemSetting::get('sms_tencent_secret_key')),
                'sdk_app_id' => trim((string) SystemSetting::get('sms_tencent_sdk_app_id', '')),
                'sign_name' => trim((string) SystemSetting::get('sms_tencent_sign_name', '')),
                'template_id' => trim((string) SystemSetting::get('sms_tencent_template_id', '')),
                'region' => trim((string) SystemSetting::get('sms_tencent_region', 'ap-guangzhou')),
            ],
        ];
    }

    public function resetConfiguration(): void
    {
        $this->configuration = null;
    }

    public function isReady(): bool
    {
        $config = $this->configuration();
        if (!$config['enabled']) {
            return false;
        }

        return match ($config['provider']) {
            'aliyun' => collect($config['aliyun'])->every(fn ($value) => filled($value)),
            'tencent' => collect($config['tencent'])->every(fn ($value) => filled($value)),
            default => false,
        };
    }

    public function hasCredential(string $key): bool
    {
        return filled($this->decrypt(SystemSetting::get($key)));
    }

    public function storeCredential(string $key, string $value): void
    {
        SystemSetting::set($key, Crypt::encryptString($value), '短信服务凭据（加密存储）');
        $this->resetConfiguration();
    }

    public function send(string $phone, array $variables): array
    {
        if (!$this->isReady()) {
            return ['success' => false, 'error' => '短信服务尚未启用或配置不完整'];
        }

        $normalized = $this->normalizeChinesePhone($phone);
        if (!$normalized) {
            return ['success' => false, 'error' => '家长手机号格式不正确'];
        }

        $config = $this->configuration();
        $ordered = [];
        $named = [];
        foreach ($config['template_variables'] as $key) {
            $value = (string) ($variables[$key] ?? '');
            $ordered[] = $value;
            $named[$key] = $value;
        }

        $provider = $this->provider($config['provider']);
        $providerConfig = $config[$config['provider']];
        $providerPhone = $config['provider'] === 'tencent' ? $normalized['e164'] : $normalized['domestic'];

        return $provider->send($providerConfig, $providerPhone, $named, $ordered);
    }

    public function normalizeChinesePhone(?string $phone): ?array
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if (str_starts_with($digits, '0086')) {
            $digits = substr($digits, 4);
        } elseif (strlen($digits) === 13 && str_starts_with($digits, '86')) {
            $digits = substr($digits, 2);
        }

        if (!preg_match('/^1[3-9]\d{9}$/', $digits)) {
            return null;
        }

        return ['domestic' => $digits, 'e164' => '+86'.$digits];
    }

    private function provider(string $provider): SmsProvider
    {
        return match ($provider) {
            'aliyun' => app(AlibabaSmsProvider::class),
            'tencent' => app(TencentSmsProvider::class),
            default => throw new \InvalidArgumentException('不支持的短信服务商'),
        };
    }

    private function decrypt(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
