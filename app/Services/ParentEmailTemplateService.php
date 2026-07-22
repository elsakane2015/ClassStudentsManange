<?php

namespace App\Services;

use App\Models\SystemSetting;

class ParentEmailTemplateService
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

    public function configuration(): array
    {
        return [
            'subject_template' => SystemSetting::get(
                'parent_email_subject_template',
                SystemSetting::get('resend_subject_template', self::DEFAULT_SUBJECT)
            ),
            'html_template' => SystemSetting::get(
                'parent_email_html_template',
                SystemSetting::get('resend_html_template', self::DEFAULT_HTML)
            ),
        ];
    }

    public function renderSubject(array $variables): string
    {
        return $this->render($this->configuration()['subject_template'], $variables, false);
    }

    public function renderHtml(array $variables): string
    {
        return $this->render($this->configuration()['html_template'], $variables, true);
    }

    private function render(string $template, array $variables, bool $escapeHtml): string
    {
        $replacements = [];
        foreach ($variables as $key => $value) {
            $stringValue = (string) $value;
            $replacements['{{'.$key.'}}'] = $escapeHtml ? e($stringValue) : strip_tags($stringValue);
        }

        return strtr($template, $replacements);
    }
}
