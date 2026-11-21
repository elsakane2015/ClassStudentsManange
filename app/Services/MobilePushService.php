<?php

namespace App\Services;

use App\Jobs\SendMobilePushNotification;
use App\Models\AppNotification;
use App\Models\AttendanceRecord;
use App\Models\MobileDevice;
use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MobilePushService
{
    /**
     * Persist an in-app notification and asynchronously attempt native OS push delivery.
     */
    public function notifyUser(
        int $userId,
        string $type,
        string $title,
        string $body,
        array $data = []
    ): AppNotification {
        $notification = AppNotification::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => $data,
            'push_status' => config('mobile_push.enabled', true) ? 'queued' : 'disabled',
        ]);

        if (config('mobile_push.enabled', true)) {
            SendMobilePushNotification::dispatchAfterResponse($notification->id);
        }

        return $notification;
    }

    /**
     * Notify multiple distinct users.
     */
    public function notifyUsers(
        iterable $userIds,
        string $type,
        string $title,
        string $body,
        array $data = []
    ): array {
        $created = [];
        $uniqueIds = collect($userIds)
            ->filter(fn ($id) => !empty($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        foreach ($uniqueIds as $userId) {
            $created[] = $this->notifyUser($userId, $type, $title, $body, $data);
        }

        return $created;
    }

    /**
     * Notify class teacher & class admins when a student submits a leave request.
     */
    public function notifyLeaveRequestSubmitted(AttendanceRecord $record): void
    {
        $record->loadMissing(['student.user', 'class', 'leaveType']);
        $studentName = $record->student?->user?->name ?? '学生';
        $leaveTypeName = $record->leaveType?->name ?? '请假';
        $dateText = $record->date instanceof \Carbon\Carbon
            ? $record->date->format('Y-m-d')
            : (string) $record->date;

        $recipientUserIds = [];

        $schoolClass = $record->class ?? SchoolClass::find($record->class_id);
        if ($schoolClass && $schoolClass->teacher_id) {
            $recipientUserIds[] = $schoolClass->teacher_id;
        }

        $classAdminUserIds = Student::where('class_id', $record->class_id)
            ->where('is_class_admin', true)
            ->whereNotNull('user_id')
            ->where('id', '!=', $record->student_id)
            ->pluck('user_id')
            ->all();

        $recipientUserIds = array_merge($recipientUserIds, $classAdminUserIds);

        if (empty($recipientUserIds)) {
            return;
        }

        $this->notifyUsers(
            $recipientUserIds,
            'leave_submitted',
            "新的{$leaveTypeName}待审批",
            "{$studentName} 提交了 {$dateText} 的{$leaveTypeName}申请，请及时审批。",
            [
                'route' => 'leave_approvals',
                'leave_request_id' => $record->id,
                'leave_batch_id' => $record->leave_batch_id,
                'student_id' => $record->student_id,
                'class_id' => $record->class_id,
            ]
        );
    }

    /**
     * Notify student when their leave request is approved or rejected.
     */
    public function notifyLeaveRequestReviewed(
        AttendanceRecord $record,
        string $decision,
        ?string $approverName = null,
        ?string $rejectionReason = null
    ): void {
        $record->loadMissing(['student', 'leaveType']);
        $studentUserId = $record->student?->user_id;
        if (!$studentUserId) {
            return;
        }

        $leaveTypeName = $record->leaveType?->name ?? '请假';
        $dateText = $record->date instanceof \Carbon\Carbon
            ? $record->date->format('Y-m-d')
            : (string) $record->date;
        $isApproved = $decision === 'approved';
        $reviewer = $approverName ? "（审批人：{$approverName}）" : '';

        $title = $isApproved ? "{$leaveTypeName}申请已通过" : "{$leaveTypeName}申请已驳回";
        $body = $isApproved
            ? "您 {$dateText} 的{$leaveTypeName}申请已获批准{$reviewer}。"
            : "您 {$dateText} 的{$leaveTypeName}申请已被驳回{$reviewer}" . ($rejectionReason ? "，原因：{$rejectionReason}" : '。');

        $this->notifyUser(
            $studentUserId,
            $isApproved ? 'leave_approved' : 'leave_rejected',
            $title,
            $body,
            [
                'route' => 'leave_history',
                'leave_request_id' => $record->id,
                'leave_batch_id' => $record->leave_batch_id,
                'decision' => $decision,
            ]
        );
    }

    /**
     * Notify student when an attendance record (absence/late/early/etc.) is recorded for them.
     */
    public function notifyAttendanceRecorded(AttendanceRecord $record): void
    {
        $record->loadMissing(['student', 'leaveType']);
        $studentUserId = $record->student?->user_id;
        if (!$studentUserId) {
            return;
        }

        if ($record->status === 'present') {
            return;
        }

        $dateText = $record->date instanceof \Carbon\Carbon
            ? $record->date->format('Y-m-d')
            : (string) $record->date;

        $statusLabel = $record->leaveType?->name ?? match ($record->status) {
            'absent' => '缺勤',
            'late' => '迟到',
            'early_leave' => '早退',
            'leave', 'excused' => '请假',
            default => $record->status,
        };

        $this->notifyUser(
            $studentUserId,
            'attendance_alert',
            "考勤变更提醒：{$statusLabel}",
            "{$dateText} 新增一条考勤记录（{$statusLabel}），请在个人考勤记录中查看详情。",
            [
                'route' => 'student_dashboard',
                'attendance_record_id' => $record->id,
                'date' => $dateText,
                'status' => $record->status,
            ]
        );
    }

    /**
     * Deliver OS-level push notifications for a persisted AppNotification.
     * Gracefully degrades when push certificates/credentials are not yet configured.
     */
    public function deliverPush(AppNotification $notification): void
    {
        $devices = MobileDevice::where('user_id', $notification->user_id)
            ->where('push_enabled', true)
            ->whereNotNull('push_token')
            ->where('push_token', '!=', '')
            ->get();

        if ($devices->isEmpty()) {
            $notification->update([
                'push_status' => 'skipped_no_device',
            ]);
            return;
        }

        $unreadCount = AppNotification::where('user_id', $notification->user_id)
            ->whereNull('read_at')
            ->count();

        $sentCount = 0;
        $skippedNoCredentials = 0;
        $errors = [];

        foreach ($devices as $device) {
            if ($device->platform === 'ios') {
                $result = $this->sendViaApns($device, $notification, $unreadCount);
            } elseif ($device->platform === 'android') {
                $result = $this->sendViaAndroid($device, $notification, $unreadCount);
            } else {
                continue;
            }

            if ($result['status'] === 'sent') {
                $sentCount++;
            } elseif ($result['status'] === 'skipped_no_credentials') {
                $skippedNoCredentials++;
            } elseif ($result['status'] === 'failed') {
                $errors[] = "{$device->platform}#{$device->id}: {$result['error']}";
            }
        }

        if ($sentCount > 0) {
            $notification->update([
                'pushed_at' => now(),
                'push_status' => empty($errors) ? 'sent' : 'partial',
                'push_error' => empty($errors) ? null : implode('; ', $errors),
            ]);
        } elseif (!empty($errors)) {
            $notification->update([
                'push_status' => 'failed',
                'push_error' => mb_substr(implode('; ', $errors), 0, 500),
            ]);
        } elseif ($skippedNoCredentials > 0) {
            $notification->update([
                'push_status' => 'skipped_no_credentials',
            ]);
        }
    }

    /**
     * Native Apple Push Notification service (APNs):
     * Supports centralized HTTPS APNs Relay (recommended for multi-school deployments)
     * as well as direct HTTP/2 JWT (.p8) delivery.
     */
    protected function sendViaApns(MobileDevice $device, AppNotification $notification, int $badge): array
    {
        $cfg = config('mobile_push.apns', []);
        if (empty($cfg['enabled'])) {
            return ['status' => 'skipped_no_credentials'];
        }

        $environment = $this->resolveApnsEnvironment($device, $cfg);

        if (!empty($cfg['relay_url']) && !empty($cfg['relay_token'])) {
            return $this->sendViaApnsRelay($device, $notification, $badge, $cfg, $environment);
        }

        if (empty($cfg['key_id']) || empty($cfg['team_id']) || empty($cfg['private_key_path'])) {
            return ['status' => 'skipped_no_credentials'];
        }

        $keyPath = $cfg['private_key_path'];
        if (!is_readable($keyPath)) {
            return ['status' => 'skipped_no_credentials'];
        }

        $jwt = $this->buildApnsJwt($cfg['key_id'], $cfg['team_id'], $keyPath);
        if (!$jwt) {
            return ['status' => 'failed', 'error' => 'Unable to sign APNs JWT'];
        }

        $host = $environment === 'development'
            ? 'https://api.sandbox.push.apple.com'
            : 'https://api.push.apple.com';
        $url = "{$host}/3/device/{$device->push_token}";

        $payload = json_encode([
            'aps' => [
                'alert' => [
                    'title' => $notification->title,
                    'body' => $notification->body,
                ],
                'sound' => 'default',
                'badge' => $badge,
            ],
            'notification_id' => $notification->id,
            'type' => $notification->type,
            'data' => $notification->data ?? (object) [],
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                "authorization: bearer {$jwt}",
                'apns-topic: ' . ($cfg['bundle_id'] ?? 'com.litotime.smartcampus'),
                'apns-push-type: alert',
                'apns-priority: 10',
                'content-type: application/json',
            ],
        ]);

        $responseBody = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode === 200) {
            return ['status' => 'sent'];
        }

        $decoded = json_decode((string) $responseBody, true);
        $reason = $decoded['reason'] ?? ($curlError ?: "HTTP {$httpCode}");

        // Clean up invalid / unregistered tokens automatically.
        if (in_array($httpCode, [400, 410], true) && in_array($reason, ['BadDeviceToken', 'Unregistered', 'DeviceTokenNotForTopic'], true)) {
            $device->update(['push_token' => null]);
        }

        return ['status' => 'failed', 'error' => $reason];
    }

    /**
     * Resolve per-device APNs environment ('development' or 'production'),
     * falling back to server-level APNS_SANDBOX config when device did not report one.
     */
    protected function resolveApnsEnvironment(MobileDevice $device, array $cfg): string
    {
        $reported = strtolower(trim((string) ($device->push_environment ?? '')));
        if (in_array($reported, ['development', 'production'], true)) {
            return $reported;
        }

        return !empty($cfg['sandbox']) ? 'development' : 'production';
    }

    /**
     * Deliver iOS push through the centralized SmartCampus APNs Relay service.
     */
    protected function sendViaApnsRelay(
        MobileDevice $device,
        AppNotification $notification,
        int $badge,
        array $cfg,
        string $environment
    ): array {
        $baseUrl = rtrim((string) $cfg['relay_url'], '/');
        $endpoint = str_ends_with($baseUrl, '/v1/apns/send')
            ? $baseUrl
            : "{$baseUrl}/v1/apns/send";

        try {
            $response = Http::timeout(12)
                ->acceptJson()
                ->withToken((string) $cfg['relay_token'])
                ->post($endpoint, [
                    'device_token' => $device->push_token,
                    'bundle_id' => $cfg['bundle_id'] ?? 'com.litotime.smartcampus',
                    'environment' => $environment,
                    'title' => $notification->title,
                    'body' => $notification->body,
                    'badge' => $badge,
                    'sound' => 'default',
                    'data' => array_merge(
                        is_array($notification->data) ? $notification->data : [],
                        [
                            'notification_id' => $notification->id,
                            'type' => $notification->type,
                        ]
                    ),
                ]);
        } catch (\Throwable $e) {
            return ['status' => 'failed', 'error' => $e->getMessage()];
        }

        $json = $response->json();
        if ($response->successful() && (!is_array($json) || ($json['ok'] ?? true) === true)) {
            return ['status' => 'sent'];
        }

        if (is_array($json) && !empty($json['invalid_token'])) {
            $device->update(['push_token' => null]);
        }

        $reason = is_array($json)
            ? ($json['reason'] ?? "HTTP {$response->status()}")
            : "HTTP {$response->status()}";

        return ['status' => 'failed', 'error' => (string) $reason];
    }

    /**
     * Android Push delivery: supports FCM HTTP v1 and optional self-hosted/domestic Webhook bridge.
     */
    protected function sendViaAndroid(MobileDevice $device, AppNotification $notification, int $badge): array
    {
        $webhookUrl = config('mobile_push.android_webhook.url');
        if (!empty($webhookUrl)) {
            $headers = [];
            if ($secret = config('mobile_push.android_webhook.secret')) {
                $headers['X-Push-Secret'] = $secret;
            }

            $response = Http::timeout(10)->withHeaders($headers)->post($webhookUrl, [
                'push_token' => $device->push_token,
                'title' => $notification->title,
                'body' => $notification->body,
                'badge' => $badge,
                'notification_id' => $notification->id,
                'type' => $notification->type,
                'data' => $notification->data ?? [],
            ]);

            return $response->successful()
                ? ['status' => 'sent']
                : ['status' => 'failed', 'error' => 'Webhook HTTP ' . $response->status()];
        }

        $fcm = config('mobile_push.fcm', []);
        if (empty($fcm['enabled']) || empty($fcm['project_id']) || empty($fcm['credentials_path'])) {
            return ['status' => 'skipped_no_credentials'];
        }

        if (!is_readable($fcm['credentials_path'])) {
            return ['status' => 'skipped_no_credentials'];
        }

        $accessToken = $this->getFcmAccessToken($fcm['credentials_path']);
        if (!$accessToken) {
            return ['status' => 'failed', 'error' => 'Unable to acquire FCM OAuth token'];
        }

        $stringData = collect($notification->data ?? [])
            ->mapWithKeys(fn ($value, $key) => [(string) $key => is_scalar($value) ? (string) $value : json_encode($value)])
            ->merge([
                'notification_id' => (string) $notification->id,
                'type' => $notification->type,
            ])
            ->all();

        $response = Http::timeout(10)
            ->withToken($accessToken)
            ->post("https://fcm.googleapis.com/v1/projects/{$fcm['project_id']}/messages:send", [
                'message' => [
                    'token' => $device->push_token,
                    'notification' => [
                        'title' => $notification->title,
                        'body' => $notification->body,
                    ],
                    'data' => $stringData,
                    'android' => [
                        'priority' => 'HIGH',
                        'notification' => [
                            'channel_id' => 'smartcampus_general',
                        ],
                    ],
                ],
            ]);

        if ($response->successful()) {
            return ['status' => 'sent'];
        }

        if ($response->status() === 404) {
            $device->update(['push_token' => null]);
        }

        return ['status' => 'failed', 'error' => 'FCM HTTP ' . $response->status()];
    }

    protected function buildApnsJwt(string $keyId, string $teamId, string $privateKeyPath): ?string
    {
        $cacheKey = "mobile_push:apns_jwt:{$teamId}:{$keyId}";
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $privateKeyPem = file_get_contents($privateKeyPath);
        if (!$privateKeyPem) {
            return null;
        }

        $privateKey = openssl_pkey_get_private($privateKeyPem);
        if (!$privateKey) {
            return null;
        }

        $header = $this->base64UrlEncode(json_encode(['alg' => 'ES256', 'kid' => $keyId]));
        $claims = $this->base64UrlEncode(json_encode(['iss' => $teamId, 'iat' => time()]));
        $unsigned = "{$header}.{$claims}";

        $derSignature = '';
        if (!openssl_sign($unsigned, $derSignature, $privateKey, OPENSSL_ALGO_SHA256)) {
            return null;
        }

        $rawSignature = $this->derToJoseEs256($derSignature);
        if ($rawSignature === null) {
            return null;
        }

        $jwt = $unsigned . '.' . $this->base64UrlEncode($rawSignature);
        // Apple allows APNs JWT reuse for up to 60 minutes; cache for 45 minutes.
        Cache::put($cacheKey, $jwt, now()->addMinutes(45));

        return $jwt;
    }

    protected function getFcmAccessToken(string $credentialsPath): ?string
    {
        $cacheKey = 'mobile_push:fcm_token:' . md5($credentialsPath);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $creds = json_decode((string) file_get_contents($credentialsPath), true);
        if (empty($creds['client_email']) || empty($creds['private_key'])) {
            return null;
        }

        $now = time();
        $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $this->base64UrlEncode(json_encode([
            'iss' => $creds['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => $creds['token_uri'] ?? 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]));
        $unsigned = "{$header}.{$claims}";

        $signature = '';
        if (!openssl_sign($unsigned, $signature, $creds['private_key'], OPENSSL_ALGO_SHA256)) {
            return null;
        }

        $assertion = $unsigned . '.' . $this->base64UrlEncode($signature);

        $response = Http::asForm()->timeout(10)->post($creds['token_uri'] ?? 'https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $assertion,
        ]);

        $token = $response->json('access_token');
        if (is_string($token) && $token !== '') {
            Cache::put($cacheKey, $token, now()->addMinutes(50));
            return $token;
        }

        return null;
    }

    protected function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Convert ASN.1 DER encoded ECDSA signature to 64-byte IEEE P1363 format for ES256 JWT.
     */
    protected function derToJoseEs256(string $der): ?string
    {
        $offset = 0;
        $length = strlen($der);
        if ($length < 8 || ord($der[$offset++]) !== 0x30) {
            return null;
        }

        $seqLen = ord($der[$offset++]);
        if (($seqLen & 0x80) !== 0) {
            $offset += ($seqLen & 0x7f);
        }

        if ($offset >= $length || ord($der[$offset++]) !== 0x02) {
            return null;
        }
        $rLen = ord($der[$offset++]);
        $r = substr($der, $offset, $rLen);
        $offset += $rLen;

        if ($offset >= $length || ord($der[$offset++]) !== 0x02) {
            return null;
        }
        $sLen = ord($der[$offset++]);
        $s = substr($der, $offset, $sLen);

        $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
        $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);

        if (strlen($r) !== 32 || strlen($s) !== 32) {
            return null;
        }

        return $r . $s;
    }
}
