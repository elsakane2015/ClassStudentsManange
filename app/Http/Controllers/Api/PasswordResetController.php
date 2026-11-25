<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\User;
use App\Services\PersonalEmailService;
use App\Services\ResendEmailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    public const CODE_TTL_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public function __construct(
        private ResendEmailService $resendEmail,
        private PersonalEmailService $personalEmail,
    ) {}

    /**
     * Send a 6-digit password reset verification code to the account email.
     */
    public function sendCode(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|string|email|max:255',
        ], [
            'email.required' => '请输入登录邮箱',
            'email.email' => '请输入有效的邮箱地址',
        ]);

        if (! Schema::hasTable('password_reset_codes')) {
            return response()->json([
                'message' => '系统尚未完成数据库升级，请先联系管理员执行数据迁移。',
            ], 503);
        }

        $emailLower = mb_strtolower(trim($validated['email']));
        $user = User::whereRaw('LOWER(email) = ?', [$emailLower])->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'email' => ['未找到该邮箱对应的账号，请确认班主任已在学生信息中录入您的家长邮箱。'],
            ]);
        }

        if (! $user->status) {
            return response()->json([
                'message' => '该账号当前已停用或尚未关联在校学生，请联系班主任或管理员处理。',
            ], 403);
        }

        // Prevent rapid resends within 45 seconds
        $recent = DB::table('password_reset_codes')
            ->where('email', $emailLower)
            ->whereNull('used_at')
            ->where('created_at', '>=', now()->subSeconds(45))
            ->orderByDesc('id')
            ->first();

        if ($recent) {
            return response()->json([
                'message' => '验证码已发送，请稍候再试（45 秒后可重新获取）。',
            ], 429);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $schoolName = School::query()->value('name') ?: config('app.name', '智慧校园');
        $subject = "【{$schoolName}】重置密码验证码";
        $html = $this->buildResetEmailHtml($schoolName, $user->name, $code);

        $delivery = $this->deliverCodeEmail($user, $emailLower, $subject, $html);
        if (! $delivery['success']) {
            Log::warning('Password reset code email delivery failed', [
                'email' => $emailLower,
                'error' => $delivery['error'] ?? 'unknown',
            ]);

            return response()->json([
                'message' => $delivery['user_message'] ?? '邮件发送失败，请联系班主任为您重置密码。',
            ], 422);
        }

        // Invalidate previous unused codes for this email and store the new hashed code
        DB::table('password_reset_codes')
            ->where('email', $emailLower)
            ->whereNull('used_at')
            ->update(['used_at' => now(), 'updated_at' => now()]);

        DB::table('password_reset_codes')->insert([
            'email' => $emailLower,
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
            'used_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => '验证码已发送至您的邮箱，10 分钟内有效，请查收（如未收到请检查垃圾邮件箱）。',
            'expires_in_seconds' => self::CODE_TTL_MINUTES * 60,
        ]);
    }

    /**
     * Confirm email verification code and update password.
     */
    public function confirm(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|string|email|max:255',
            'code' => 'required|string|size:6',
            'password' => 'required|string|min:6|confirmed',
        ], [
            'email.required' => '请输入登录邮箱',
            'email.email' => '请输入有效的邮箱地址',
            'code.required' => '请输入 6 位邮箱验证码',
            'code.size' => '验证码应为 6 位数字',
            'password.required' => '请输入新密码',
            'password.min' => '新密码至少需要 6 位',
            'password.confirmed' => '两次输入的新密码不一致',
        ]);

        if (! Schema::hasTable('password_reset_codes')) {
            return response()->json([
                'message' => '系统尚未完成数据库升级，请先联系管理员。',
            ], 503);
        }

        $emailLower = mb_strtolower(trim($validated['email']));
        $codeInput = trim($validated['code']);

        $record = DB::table('password_reset_codes')
            ->where('email', $emailLower)
            ->whereNull('used_at')
            ->orderByDesc('id')
            ->first();

        if (! $record) {
            throw ValidationException::withMessages([
                'code' => ['验证码无效或已失效，请重新获取验证码。'],
            ]);
        }

        if (now()->greaterThan($record->expires_at)) {
            DB::table('password_reset_codes')->where('id', $record->id)->update([
                'used_at' => now(),
                'updated_at' => now(),
            ]);
            throw ValidationException::withMessages([
                'code' => ['验证码已过期，请重新获取。'],
            ]);
        }

        if ((int) $record->attempts >= self::MAX_ATTEMPTS) {
            throw ValidationException::withMessages([
                'code' => ['验证码尝试次数过多，请重新获取新验证码。'],
            ]);
        }

        if (! Hash::check($codeInput, $record->code_hash)) {
            DB::table('password_reset_codes')->where('id', $record->id)->increment('attempts');
            throw ValidationException::withMessages([
                'code' => ['验证码不正确，请重新核对。'],
            ]);
        }

        $user = User::whereRaw('LOWER(email) = ?', [$emailLower])->first();
        if (! $user || ! $user->status) {
            return response()->json([
                'message' => '账号不存在或已停用，无法重置密码。',
            ], 403);
        }

        DB::transaction(function () use ($user, $record, $validated) {
            $user->password = Hash::make($validated['password']);
            $user->save();

            // Revoke existing tokens for security
            $user->tokens()->delete();

            DB::table('password_reset_codes')->where('id', $record->id)->update([
                'used_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return response()->json([
            'message' => '密码已重置成功，请使用新密码登录。',
        ]);
    }

    private function deliverCodeEmail(User $user, string $recipient, string $subject, string $html): array
    {
        // 1. Primary: system Resend email service
        if ($this->resendEmail->isReady()) {
            $result = $this->resendEmail->send(
                $recipient,
                $subject,
                $html,
                'password-reset-'.hash('sha256', $recipient.'|'.now()->format('YmdHis'))
            );
            if ($result['success'] ?? false) {
                return ['success' => true, 'provider' => 'system_resend'];
            }
        }

        // 2. Fallback: teacher's verified personal email if user is linked to children whose class teacher configured one
        if (Schema::hasTable('parent_student')) {
            $teachers = $user->children()
                ->with('schoolClass.teacher.teacherEmailAccount')
                ->get()
                ->pluck('schoolClass.teacher')
                ->filter()
                ->unique('id');

            foreach ($teachers as $teacher) {
                if ($this->personalEmail->isReady($teacher)) {
                    $result = $this->personalEmail->send($teacher, $recipient, $subject, $html);
                    if ($result['success'] ?? false) {
                        return ['success' => true, 'provider' => 'teacher_personal_email'];
                    }
                }
            }
        }

        // 3. Fallback: Laravel configured mailer (smtp / sendmail / postmark / array in tests)
        $defaultMailer = (string) config('mail.default', 'log');
        $canUseLaravelMail = in_array($defaultMailer, ['array', 'smtp', 'sendmail', 'ses', 'postmark', 'mailgun', 'resend'], true)
            && ($defaultMailer === 'array' || ! empty(config('mail.mailers.'.$defaultMailer.'.host')) || $defaultMailer === 'sendmail');

        if ($canUseLaravelMail) {
            try {
                Mail::html($html, function ($message) use ($recipient, $subject) {
                    $message->to($recipient)->subject($subject);
                });

                return ['success' => true, 'provider' => 'laravel_mail:'.$defaultMailer];
            } catch (\Throwable $e) {
                return [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'user_message' => '邮件发送失败，请稍后重试或联系班主任为您重置密码。',
                ];
            }
        }

        return [
            'success' => false,
            'error' => 'no_mail_provider_configured',
            'user_message' => '系统暂未配置发信邮箱服务，请联系班主任在学生管理中为您重置密码。',
        ];
    }

    private function buildResetEmailHtml(string $schoolName, string $userName, string $code): string
    {
        $safeSchool = e($schoolName);
        $safeName = e($userName ?: '用户');
        $safeCode = e($code);
        $ttl = self::CODE_TTL_MINUTES;

        return <<<HTML
<div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 520px; margin: 0 auto; padding: 24px; color: #1f2937;">
  <h2 style="margin: 0 0 12px; font-size: 18px; color: #111827;">{$safeSchool} · 密码重置验证</h2>
  <p style="margin: 0 0 16px; font-size: 14px; line-height: 1.6;">您好，{$safeName}：</p>
  <p style="margin: 0 0 16px; font-size: 14px; line-height: 1.6;">您正在申请重置账号登录密码，本次验证码为：</p>
  <div style="background: #eef2ff; border-radius: 10px; padding: 16px; text-align: center; margin: 16px 0;">
    <span style="font-size: 28px; font-weight: 700; letter-spacing: 6px; color: #4338ca;">{$safeCode}</span>
  </div>
  <p style="margin: 0 0 8px; font-size: 13px; color: #6b7280;">验证码有效期为 {$ttl} 分钟，请勿泄露给他人。</p>
  <p style="margin: 0; font-size: 12px; color: #9ca3af;">如非本人操作，请忽略此邮件，您的账号密码不会被修改。</p>
</div>
HTML;
    }
}
