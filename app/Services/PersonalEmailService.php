<?php

namespace App\Services;

use App\Models\TeacherEmailAccount;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class PersonalEmailService
{
    public const SMTP_PROVIDERS = [
        'qq' => [
            'label' => 'QQ 邮箱',
            'host' => 'smtp.qq.com',
            'port' => 465,
            'domains' => ['qq.com'],
        ],
        'netease_163' => [
            'label' => '网易 163 邮箱',
            'host' => 'smtp.163.com',
            'port' => 465,
            'domains' => ['163.com'],
        ],
        'netease_126' => [
            'label' => '网易 126 邮箱',
            'host' => 'smtp.126.com',
            'port' => 465,
            'domains' => ['126.com'],
        ],
    ];

    public function providerOptions(): array
    {
        $providers = collect(self::SMTP_PROVIDERS)
            ->map(fn (array $config, string $key) => [
                'key' => $key,
                'label' => $config['label'],
                'auth_type' => 'authorization_code',
            ])->values()->all();

        $providers[] = [
            'key' => 'microsoft',
            'label' => 'Microsoft Outlook / Hotmail',
            'auth_type' => 'oauth2',
            'available' => $this->microsoftConfigured(),
        ];

        return $providers;
    }

    public function publicConfiguration(User $teacher): array
    {
        $account = $teacher->teacherEmailAccount;

        return [
            'provider' => $account?->provider,
            'email' => $account?->email,
            'from_name' => $account?->from_name,
            'is_verified' => $account?->is_verified ?? false,
            'verified_at' => $account?->verified_at,
            'last_error' => $account?->last_error,
            'credential_configured' => $account
                ? ($account->provider === 'microsoft' ? filled($account->refresh_token) : filled($account->secret))
                : false,
            'ready' => $this->isReady($teacher),
            'microsoft_configured' => $this->microsoftConfigured(),
        ];
    }

    public function saveSmtpAccount(User $teacher, array $data): TeacherEmailAccount
    {
        $provider = $data['provider'];
        abort_unless(isset(self::SMTP_PROVIDERS[$provider]), 422, '不支持的个人邮箱服务商');
        $this->validateProviderEmail($provider, $data['email']);

        $account = $teacher->teacherEmailAccount ?: new TeacherEmailAccount(['user_id' => $teacher->id]);
        $authorizationCode = trim((string) ($data['authorization_code'] ?? ''));
        $sameCredentials = $account->exists
            && $account->provider === $provider
            && strcasecmp($account->email, $data['email']) === 0;

        if ($authorizationCode === '' && ! ($sameCredentials && filled($account->secret))) {
            throw ValidationException::withMessages([
                'authorization_code' => ['请填写邮箱服务商生成的客户端授权码'],
            ]);
        }

        $configurationChanged = ! $sameCredentials
            || $authorizationCode !== ''
            || $account->from_name !== ($data['from_name'] ?? null);

        $account->fill([
            'provider' => $provider,
            'email' => strtolower($data['email']),
            'from_name' => $data['from_name'] ?: $teacher->name,
            'access_token' => null,
            'refresh_token' => null,
            'token_expires_at' => null,
            'last_error' => null,
        ]);
        if ($authorizationCode !== '') {
            $account->secret = $authorizationCode;
        }
        if ($configurationChanged) {
            $account->is_verified = false;
            $account->verified_at = null;
        }
        $account->save();
        $teacher->setRelation('teacherEmailAccount', $account);

        return $account;
    }

    public function isReady(User $teacher): bool
    {
        $account = $teacher->teacherEmailAccount;
        if (! $account?->is_verified) {
            return false;
        }

        return $account->provider === 'microsoft'
            ? filled($account->refresh_token)
            : isset(self::SMTP_PROVIDERS[$account->provider]) && filled($account->secret);
    }

    public function sendTest(User $teacher): array
    {
        $account = $teacher->teacherEmailAccount;
        if (! $account) {
            return ['success' => false, 'error' => '请先保存个人邮箱配置'];
        }

        $result = $this->sendUsingAccount(
            $account,
            $account->email,
            '考勤系统个人邮箱测试',
            '<div style="font-family:Arial,sans-serif;padding:24px"><h2>个人邮箱配置成功</h2><p>这是一封来自考勤系统的测试邮件。</p></div>'
        );

        $account->update([
            'is_verified' => $result['success'],
            'verified_at' => $result['success'] ? now() : null,
            'last_error' => $result['success'] ? null : ($result['error'] ?? '发送失败'),
        ]);

        return $result;
    }

    public function send(User $teacher, string $to, string $subject, string $html): array
    {
        if (! $this->isReady($teacher)) {
            return ['success' => false, 'error' => '班主任个人邮箱尚未验证'];
        }

        return $this->sendUsingAccount($teacher->teacherEmailAccount, $to, $subject, $html);
    }

    public function disconnect(User $teacher): void
    {
        $teacher->teacherEmailAccount()->delete();
        $teacher->unsetRelation('teacherEmailAccount');
    }

    public function microsoftAuthorizationUrl(User $teacher): string
    {
        abort_unless($this->microsoftConfigured(), 422, '系统管理员尚未配置 Microsoft 邮箱应用');

        $nonce = (string) Str::uuid();
        Cache::put('teacher-email-oauth:'.$nonce, $teacher->id, now()->addMinutes(10));
        $state = Crypt::encryptString(json_encode([
            'user_id' => $teacher->id,
            'nonce' => $nonce,
            'expires_at' => now()->addMinutes(10)->timestamp,
        ], JSON_THROW_ON_ERROR));

        return 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize?'.http_build_query([
            'client_id' => config('services.microsoft_mail.client_id'),
            'response_type' => 'code',
            'redirect_uri' => $this->microsoftRedirectUri(),
            'response_mode' => 'query',
            'scope' => 'openid profile email offline_access User.Read Mail.Send',
            'state' => $state,
            'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function completeMicrosoftAuthorization(string $code, string $state): User
    {
        try {
            $stateData = json_decode(Crypt::decryptString($state), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['state' => ['Microsoft 授权状态无效，请重新连接']]);
        }

        $teacherId = Cache::pull('teacher-email-oauth:'.($stateData['nonce'] ?? ''));
        if (! $teacherId
            || (int) $teacherId !== (int) ($stateData['user_id'] ?? 0)
            || (int) ($stateData['expires_at'] ?? 0) < now()->timestamp) {
            throw ValidationException::withMessages(['state' => ['Microsoft 授权已过期，请重新连接']]);
        }

        $teacher = User::whereKey($teacherId)->where('role', 'teacher')->firstOrFail();
        $token = $this->requestMicrosoftToken([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->microsoftRedirectUri(),
        ]);
        $profile = Http::withToken($token['access_token'])
            ->timeout(10)
            ->get('https://graph.microsoft.com/v1.0/me', [
                '$select' => 'displayName,mail,userPrincipalName',
            ])->throw()->json();
        $email = strtolower((string) (($profile['mail'] ?? null) ?: ($profile['userPrincipalName'] ?? '')));
        abort_unless(filter_var($email, FILTER_VALIDATE_EMAIL), 422, 'Microsoft 账号没有可用的邮箱地址');

        $account = TeacherEmailAccount::updateOrCreate(
            ['user_id' => $teacher->id],
            [
                'provider' => 'microsoft',
                'email' => $email,
                'from_name' => ($profile['displayName'] ?? null) ?: $teacher->name,
                'secret' => null,
                'access_token' => $token['access_token'],
                'refresh_token' => $token['refresh_token'],
                'token_expires_at' => now()->addSeconds(max(60, (int) $token['expires_in'] - 30)),
                'is_verified' => true,
                'verified_at' => now(),
                'last_error' => null,
            ]
        );
        $teacher->setRelation('teacherEmailAccount', $account);

        return $teacher;
    }

    public function microsoftConfigured(): bool
    {
        return filled(config('services.microsoft_mail.client_id'))
            && filled(config('services.microsoft_mail.client_secret'));
    }

    private function sendUsingAccount(TeacherEmailAccount $account, string $to, string $subject, string $html): array
    {
        try {
            if ($account->provider === 'microsoft') {
                return $this->sendMicrosoft($account, $to, $subject, $html);
            }

            $provider = self::SMTP_PROVIDERS[$account->provider] ?? null;
            if (! $provider || ! $account->secret) {
                return ['success' => false, 'error' => '个人邮箱配置不完整'];
            }

            $transport = new EsmtpTransport($provider['host'], $provider['port'], true);
            $transport->setUsername($account->email);
            $transport->setPassword($account->secret);
            $transport->getStream()->setTimeout(10);
            $message = (new Email)
                ->from(new Address($account->email, $account->from_name ?: $account->email))
                ->to($to)
                ->subject($subject)
                ->html($html);
            (new Mailer($transport))->send($message);

            return [
                'success' => true,
                'provider' => $account->provider,
                'sender' => $account->email,
            ];
        } catch (\Throwable $exception) {
            Log::warning('Personal email send failed', [
                'teacher_id' => $account->user_id,
                'provider' => $account->provider,
                'error' => $exception->getMessage(),
            ]);

            return [
                'success' => false,
                'provider' => $account->provider,
                'sender' => $account->email,
                'error' => $exception->getMessage(),
            ];
        }
    }

    private function sendMicrosoft(TeacherEmailAccount $account, string $to, string $subject, string $html): array
    {
        $accessToken = $this->validMicrosoftAccessToken($account);
        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->timeout(10)
            ->post('https://graph.microsoft.com/v1.0/me/sendMail', [
                'message' => [
                    'subject' => $subject,
                    'body' => ['contentType' => 'HTML', 'content' => $html],
                    'toRecipients' => [[
                        'emailAddress' => ['address' => $to],
                    ]],
                ],
                'saveToSentItems' => true,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException($response->json('error.message') ?: $response->body());
        }

        return [
            'success' => true,
            'provider' => 'microsoft',
            'sender' => $account->email,
        ];
    }

    private function validMicrosoftAccessToken(TeacherEmailAccount $account): string
    {
        if ($account->access_token && $account->token_expires_at?->isAfter(now()->addMinute())) {
            return $account->access_token;
        }

        abort_unless($account->refresh_token, 422, 'Microsoft 授权已失效，请重新连接');
        $token = $this->requestMicrosoftToken([
            'grant_type' => 'refresh_token',
            'refresh_token' => $account->refresh_token,
            'redirect_uri' => $this->microsoftRedirectUri(),
        ]);
        $account->update([
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'] ?? $account->refresh_token,
            'token_expires_at' => now()->addSeconds(max(60, (int) $token['expires_in'] - 30)),
            'last_error' => null,
        ]);

        return $token['access_token'];
    }

    private function requestMicrosoftToken(array $parameters): array
    {
        $response = Http::asForm()->timeout(10)
            ->post('https://login.microsoftonline.com/common/oauth2/v2.0/token', [
                ...$parameters,
                'client_id' => config('services.microsoft_mail.client_id'),
                'client_secret' => config('services.microsoft_mail.client_secret'),
                'scope' => 'openid profile email offline_access User.Read Mail.Send',
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException($response->json('error_description') ?: 'Microsoft 授权失败');
        }

        $token = $response->json();
        if (! is_array($token)
            || blank($token['access_token'] ?? null)
            || ! is_numeric($token['expires_in'] ?? null)) {
            throw new \RuntimeException('Microsoft 授权响应不完整，请重新连接');
        }

        if (($parameters['grant_type'] ?? null) === 'authorization_code'
            && blank($token['refresh_token'] ?? null)) {
            throw new \RuntimeException('Microsoft 未返回长期授权，请重新连接并允许离线访问');
        }

        return $token;
    }

    private function microsoftRedirectUri(): string
    {
        return config('services.microsoft_mail.redirect_uri')
            ?: rtrim(config('app.url'), '/').'/api/teacher-email/microsoft/callback';
    }

    private function validateProviderEmail(string $provider, string $email): void
    {
        $domain = strtolower((string) Str::afterLast($email, '@'));
        if (! in_array($domain, self::SMTP_PROVIDERS[$provider]['domains'], true)) {
            throw ValidationException::withMessages([
                'email' => ['所填邮箱地址与选择的邮箱服务商不匹配'],
            ]);
        }
    }
}
