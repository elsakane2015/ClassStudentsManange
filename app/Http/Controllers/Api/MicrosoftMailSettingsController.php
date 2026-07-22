<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Services\MicrosoftMailConfigurationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MicrosoftMailSettingsController extends Controller
{
    public function __construct(private MicrosoftMailConfigurationService $microsoftMail) {}

    public function show(Request $request)
    {
        $this->authorizeAdmin($request, false);
        $config = $this->microsoftMail->configuration();

        return response()->json([
            'microsoft_mail_enabled' => $config['enabled'],
            'microsoft_mail_client_id' => $config['client_id'],
            'microsoft_mail_client_secret' => $config['client_secret'] ? '******' : '',
            'microsoft_mail_redirect_uri' => $config['redirect_uri'],
            'is_ready' => $this->microsoftMail->isReady(),
            'source' => $config['source'],
        ]);
    }

    public function update(Request $request)
    {
        $this->authorizeAdmin($request, true);
        $validated = $request->validate([
            'microsoft_mail_enabled' => 'required|boolean',
            'microsoft_mail_client_id' => 'nullable|string|max:255',
            'microsoft_mail_client_secret' => 'nullable|string|max:2000',
            'microsoft_mail_redirect_uri' => 'required|url:http,https|max:1000',
        ]);

        $incomingSecret = trim((string) ($validated['microsoft_mail_client_secret'] ?? ''));
        $hasSecret = ($incomingSecret !== '' && $incomingSecret !== '******')
            || $this->microsoftMail->hasClientSecret();
        if ($validated['microsoft_mail_enabled']
            && (blank($validated['microsoft_mail_client_id']) || ! $hasSecret)) {
            return response()->json([
                'error' => '启用 Microsoft 邮箱前必须填写 Client ID 和 Client Secret',
            ], 422);
        }

        DB::transaction(function () use ($validated, $incomingSecret) {
            if ($incomingSecret !== '' && $incomingSecret !== '******') {
                $this->microsoftMail->storeClientSecret($incomingSecret);
            }

            SystemSetting::set(
                'microsoft_mail_enabled',
                $validated['microsoft_mail_enabled'] ? '1' : '0'
            );
            SystemSetting::set(
                'microsoft_mail_client_id',
                trim((string) ($validated['microsoft_mail_client_id'] ?? ''))
            );
            SystemSetting::set(
                'microsoft_mail_redirect_uri',
                trim($validated['microsoft_mail_redirect_uri'])
            );
        });

        $this->microsoftMail->resetConfiguration();

        return response()->json([
            'message' => 'Microsoft 邮箱配置已保存',
            'is_ready' => $this->microsoftMail->isReady(),
        ]);
    }

    private function authorizeAdmin(Request $request, bool $write): void
    {
        $roles = $write
            ? ['system_admin', 'admin']
            : ['system_admin', 'school_admin', 'admin'];

        abort_unless(in_array($request->user()?->role, $roles, true), 403, '无权限');
    }
}
