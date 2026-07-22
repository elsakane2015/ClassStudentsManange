<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ResendEmailService
{
    public const DEFAULT_SUBJECT = ParentEmailTemplateService::DEFAULT_SUBJECT;

    public const DEFAULT_HTML = ParentEmailTemplateService::DEFAULT_HTML;

    private ?array $configuration = null;

    public function __construct(private ParentEmailTemplateService $templates) {}

    public function configuration(): array
    {
        if ($this->configuration !== null) {
            return $this->configuration;
        }

        $templates = $this->templates->configuration();

        return $this->configuration = [
            'enabled' => SystemSetting::get('resend_enabled', '0') === '1',
            'api_key' => $this->decryptApiKey(SystemSetting::get('resend_api_key')),
            'from_email' => trim((string) SystemSetting::get('resend_from_email', '')),
            'from_name' => trim((string) SystemSetting::get('resend_from_name', '')),
            'reply_to' => trim((string) SystemSetting::get('resend_reply_to', '')),
            'subject_template' => $templates['subject_template'],
            'html_template' => $templates['html_template'],
        ];
    }

    public function resetConfiguration(): void
    {
        $this->configuration = null;
    }

    public function isReady(): bool
    {
        $config = $this->configuration();

        return $config['enabled'] && $config['api_key'] && $config['from_email'];
    }

    public function hasApiKey(): bool
    {
        return filled($this->configuration()['api_key']);
    }

    public function storeApiKey(string $apiKey): void
    {
        SystemSetting::set('resend_api_key', Crypt::encryptString($apiKey), 'Resend API Key（加密存储）');
        $this->resetConfiguration();
    }

    public function send(string $to, string $subject, string $html, string $idempotencyKey): array
    {
        $config = $this->configuration();
        if (! $this->isReady()) {
            return ['success' => false, 'error' => 'Resend 尚未启用或配置不完整'];
        }

        $from = $config['from_email'];
        if ($config['from_name']) {
            $from = $config['from_name'].' <'.$from.'>';
        }

        $payload = [
            'from' => $from,
            'to' => [$to],
            'subject' => $subject,
            'html' => $html,
        ];

        if ($config['reply_to']) {
            $payload['reply_to'] = $config['reply_to'];
        }

        try {
            $response = Http::acceptJson()
                ->withToken($config['api_key'])
                ->withHeaders([
                    'User-Agent' => 'ClassStudentsManange/1.0',
                    'Idempotency-Key' => $idempotencyKey,
                ])
                ->timeout(10)
                ->post('https://api.resend.com/emails', $payload);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'id' => $response->json('id'),
                    'provider' => 'system_resend',
                    'sender' => $config['from_email'],
                    'payload' => $payload,
                ];
            }

            return [
                'success' => false,
                'provider' => 'system_resend',
                'sender' => $config['from_email'],
                'error' => $response->json('message') ?: $response->body(),
                'payload' => $payload,
            ];
        } catch (\Throwable $e) {
            Log::warning('Resend email request failed', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'provider' => 'system_resend',
                'sender' => $config['from_email'],
                'error' => $e->getMessage(),
                'payload' => $payload,
            ];
        }
    }

    private function decryptApiKey(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return $value;
        }
    }
}
