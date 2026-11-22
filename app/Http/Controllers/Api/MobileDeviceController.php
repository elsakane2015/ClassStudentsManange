<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MobileDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

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
            'push_environment' => 'nullable|string|in:development,production',
            'device_identifier' => 'required|string|max:128',
            'push_token' => 'nullable|string|max:512',
            'device_name' => 'nullable|string|max:120',
            'app_version' => 'nullable|string|max:40',
            'os_version' => 'nullable|string|max:40',
            'locale' => 'nullable|string|max:20',
            'push_enabled' => 'nullable|boolean',
        ]);

        $provider = $validated['push_provider'] ?? ($validated['platform'] === 'ios' ? 'apns' : 'fcm');
        $incomingToken = isset($validated['push_token']) ? trim((string) $validated['push_token']) : '';
        $hasPushEnvironmentCol = Schema::hasColumn('mobile_devices', 'push_environment');

        $attributes = self::buildRegisterAttributes(
            userId: (int) $request->user()->id,
            provider: $provider,
            validated: $validated,
            incomingToken: $incomingToken,
            hasPushEnvironmentCol: $hasPushEnvironmentCol
        );

        // Reassign device ownership to the currently authenticated user when switching accounts on the same phone.
        $existing = MobileDevice::where('platform', $validated['platform'])
            ->where('device_identifier', $validated['device_identifier'])
            ->first();

        if ($existing) {
            $existing->fill($attributes);
            $existing->save();
            $device = $existing;
        } else {
            $device = MobileDevice::create(array_merge(
                [
                    'platform' => $validated['platform'],
                    'device_identifier' => $validated['device_identifier'],
                    'push_token' => $incomingToken !== '' ? $incomingToken : null,
                ],
                $attributes
            ));
        }

        return response()->json([
            'message' => 'Device registered',
            'device' => $device,
            'push_gateway_configured' => $this->isPushGatewayConfigured($validated['platform']),
        ]);
    }

    /**
     * 组装设备更新属性：当客户端本次未携带有效 push_token 时，不写入 push_token 键，
     * 避免并发注册或冷启动空 Token 请求覆盖擦除数据库中已有的真机推送令牌。
     */
    public static function buildRegisterAttributes(
        int $userId,
        string $provider,
        array $validated,
        string $incomingToken,
        bool $hasPushEnvironmentCol
    ): array {
        $attributes = [
            'user_id' => $userId,
            'push_provider' => $provider,
            'device_name' => $validated['device_name'] ?? null,
            'app_version' => $validated['app_version'] ?? null,
            'os_version' => $validated['os_version'] ?? null,
            'locale' => $validated['locale'] ?? null,
            'push_enabled' => $validated['push_enabled'] ?? true,
            'last_active_at' => now(),
        ];

        if ($incomingToken !== '') {
            $attributes['push_token'] = $incomingToken;
        }

        if ($hasPushEnvironmentCol && !empty($validated['push_environment'])) {
            $attributes['push_environment'] = $validated['push_environment'];
        }

        return $attributes;
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
            if (empty($cfg['enabled'])) {
                return false;
            }
            if (!empty($cfg['relay_url']) && !empty($cfg['relay_token'])) {
                return true;
            }
            return !empty($cfg['key_id'])
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
