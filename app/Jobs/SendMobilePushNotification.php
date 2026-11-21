<?php

namespace App\Jobs;

use App\Models\AppNotification;
use App\Services\MobilePushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendMobilePushNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public int $notificationId)
    {
    }

    public function handle(MobilePushService $pushService): void
    {
        $notification = AppNotification::find($this->notificationId);
        if (!$notification) {
            return;
        }

        try {
            $pushService->deliverPush($notification);
        } catch (\Throwable $e) {
            Log::warning('Mobile push notification failed', [
                'notification_id' => $this->notificationId,
                'error' => $e->getMessage(),
            ]);

            $notification->update([
                'push_status' => 'failed',
                'push_error' => mb_substr($e->getMessage(), 0, 500),
            ]);
        }
    }
}
