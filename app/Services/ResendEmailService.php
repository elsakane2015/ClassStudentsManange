<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ResendEmailService
{
    public const DEFAULT_SUBJECT = '【{{school_name}}】{{student_name}}的{{event_name}}通知';

    public const DEFAULT_HTML = <<<'HTML'
<div style="max-width:640px;margin:0 auto;padding:24px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI','Microsoft YaHei',sans-serif;color:#1f2937;line-height:1.7">
  <div style="border-top:4px solid #4f46e5;border-radius:6px;background:#ffffff;padding:28px;box-shadow:0 1px 3px rgba(0,0,0,.08)">
    <h2 style="margin:0 0 18px;font-size:22px;color:#111827">{{event_name}}通知</h2>
    <p style="margin:0 0 18px">家长您好，您的孩子 <strong>{{student_name}}</strong> 有一条新的考勤信息：</p>
    <table style="width:100%;border-collapse:collapse;background:#f9fafb">
      <tr><td style="width:110px;padding:10px 14px;color:#6b7280">班级</td><td style="padding:10px 14px">{{class_name}}</td></tr>
      <tr><td style="padding:10px 14px;color:#6b7280">日期</td><td style="padding:10px 14px">{{attendance_date}}</td></tr>
      <tr><td style="padding:10px 14px;color:#6b7280">时段/节次</td><td style="padding:10px 14px">{{period}}</td></tr>
      <tr><td style="padding:10px 14px;color:#6b7280">事项</td><td style="padding:10px 14px">{{event_name}}</td></tr>
      <tr><td style="padding:10px 14px;color:#6b7280">说明</td><td style="padding:10px 14px">{{reason}}</td></tr>
    </table>
    <p style="margin:20px 0 0;color:#6b7280;font-size:14px">班主任：{{teacher_name}}<br>发送时间：{{submitted_at}}</p>
  </div>
  <p style="text-align:center;color:#9ca3af;font-size:12px;margin-top:16px">本邮件由 {{school_name}} 考勤系统自动发送，请勿直接回复。</p>
</div>
HTML;

    private ?array $configuration = null;

    public function configuration(): array
    {
        if ($this->configuration !== null) {
            return $this->configuration;
        }

        return $this->configuration = [
            'enabled' => SystemSetting::get('resend_enabled', '0') === '1',
            'api_key' => $this->decryptApiKey(SystemSetting::get('resend_api_key')),
            'from_email' => trim((string) SystemSetting::get('resend_from_email', '')),
            'from_name' => trim((string) SystemSetting::get('resend_from_name', '')),
            'reply_to' => trim((string) SystemSetting::get('resend_reply_to', '')),
            'subject_template' => SystemSetting::get('resend_subject_template', self::DEFAULT_SUBJECT),
            'html_template' => SystemSetting::get('resend_html_template', self::DEFAULT_HTML),
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
        if (!$this->isReady()) {
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
                    'payload' => $payload,
                ];
            }

            return [
                'success' => false,
                'error' => $response->json('message') ?: $response->body(),
                'payload' => $payload,
            ];
        } catch (\Throwable $e) {
            Log::warning('Resend email request failed', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'payload' => $payload,
            ];
        }
    }

    private function decryptApiKey(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return $value;
        }
    }
}
