<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MobileDevice;
use Illuminate\Http\Request;

class MobileDeviceController extends Controller
{
    public function index(Request $request)
    {
        $devices = $request->user()
            ->mobileDevices()
            ->orderByDesc('last_active_at')
            ->get();

        return response()->json([
            'data' => $devices,
        ]);
    }

    public function register(Request $request)
    {
        $validated = $request->validate([
            'platform' => 'required|string|in:ios,android',
            'push_provider' => 'nullable|string|in:apns,fcm,webhook',
            'device_identifier' => 'required|string|max:128',
            'push_token' => 'nullable|string|max:512',
            'device_name' => 'nullable|string|max:120',
            'app_version' => 'nullable|string|max:40',
            'os_version' => 'nullable|string|max:40',
            'locale' => 'nullable|string|max:20',
            'push_enabled' => 'nullable|boolean',
        ]);

        $provider = $validated['push_provider'] ?? ($validated['platform'] === 'ios' ? 'apns' : 'fcm');

        // Reassign device ownership to the currently authenticated user when switching accounts on the same phone.
        $device = MobileDevice::updateOrCreate(
            [
                'platform' => $validated['platform'],
                'device_identifier' => $validated['device_identifier'],
            ],
            [
                'user_id' => $request->user()->id,
                'push_provider' => $provider,
                'push_token' => $validated['push_token'] ?? null,
                'device_name' => $validated['device_name'] ?? null,
                'app_version' => $validated['app_version'] ?? null,
                'os_version' => $validated['os_version'] ?? null,
                'locale' => $validated['locale'] ?? null,
                'push_enabled' => $validated['push_enabled'] ?? true,
                'last_active_at' => now(),
            ]
        );

        return response()->json([
            'message' => 'Device registered',
            'device' => $device,
            'push_gateway_configured' => $this->isPushGatewayConfigured($validated['platform']),
        ]);
    }

    public function unregister(Request $request)
    {
        $validated = $request->validate([
            'platform' => 'required|string|in:ios,android',
            'device_identifier' => 'required|string|max:128',
        ]);

        $deleted = MobileDevice::where('user_id', $request->user()->id)
            ->where('platform', $validated['platform'])
            ->where('device_identifier', $validated['device_identifier'])
            ->delete();

        return response()->json([
            'message' => 'Device unregistered',
            'deleted' => $deleted > 0,
        ]);
    }

    protected function isPushGatewayConfigured(string $platform): bool
    {
        if (!config('mobile_push.enabled', true)) {
            return false;
        }

        if ($platform === 'ios') {
            $cfg = config('mobile_push.apns', []);
            return !empty($cfg['enabled'])
                && !empty($cfg['key_id'])
                && !empty($cfg['team_id'])
                && !empty($cfg['private_key_path'])
                && is_readable($cfg['private_key_path']);
        }

        if (!empty(config('mobile_push.android_webhook.url'))) {
            return true;
        }

        $fcm = config('mobile_push.fcm', []);
        return !empty($fcm['enabled'])
            && !empty($fcm['project_id'])
            && !empty($fcm['credentials_path'])
            && is_readable($fcm['credentials_path']);
    }
}
