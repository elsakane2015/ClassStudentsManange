<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Crypt;

class MicrosoftMailConfigurationService
{
    private ?array $configuration = null;

    public function configuration(): array
    {
        if ($this->configuration !== null) {
            return $this->configuration;
        }

        $storedEnabled = SystemSetting::get('microsoft_mail_enabled');
        $storedClientId = trim((string) SystemSetting::get('microsoft_mail_client_id', ''));
        $storedSecret = SystemSetting::get('microsoft_mail_client_secret');
        $storedRedirectUri = trim((string) SystemSetting::get('microsoft_mail_redirect_uri', ''));

        $clientId = $storedClientId ?: trim((string) config('services.microsoft_mail.client_id'));
        $clientSecret = $storedSecret
            ? $this->decryptSecret($storedSecret)
            : trim((string) config('services.microsoft_mail.client_secret'));
        $redirectUri = $storedRedirectUri
            ?: trim((string) config('services.microsoft_mail.redirect_uri'))
            ?: rtrim((string) config('app.url'), '/').'/api/teacher-email/microsoft/callback';
        $enabled = $storedEnabled === null
            ? filled($clientId) && filled($clientSecret)
            : $storedEnabled === '1';

        return $this->configuration = [
            'enabled' => $enabled,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'source' => $storedClientId || $storedSecret || $storedRedirectUri || $storedEnabled !== null
                ? 'system_settings'
                : (filled($clientId) || filled($clientSecret) ? 'environment' : 'none'),
        ];
    }

    public function isReady(): bool
    {
        $config = $this->configuration();

        return $config['enabled']
            && filled($config['client_id'])
            && filled($config['client_secret'])
            && filter_var($config['redirect_uri'], FILTER_VALIDATE_URL);
    }

    public function hasClientSecret(): bool
    {
        return filled($this->configuration()['client_secret']);
    }

    public function storeClientSecret(string $clientSecret): void
    {
        SystemSetting::set(
            'microsoft_mail_client_secret',
            Crypt::encryptString($clientSecret),
            'Microsoft 邮箱 OAuth Client Secret（加密存储）'
        );
        $this->resetConfiguration();
    }

    public function resetConfiguration(): void
    {
        $this->configuration = null;
    }

    private function decryptSecret(string $value): string
    {
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return '';
        }
    }
}
