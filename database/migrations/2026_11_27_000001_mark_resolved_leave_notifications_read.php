<?php

use App\Services\MobilePushService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('app_notifications') || ! Schema::hasTable('attendance_records')) {
            return;
        }

        try {
            app(MobilePushService::class)->cleanupResolvedLeaveSubmittedNotifications();
        } catch (\Throwable $e) {
            Log::warning('Cleanup resolved leave notifications during migration failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function down(): void
    {
        // Data cleanup migration; nothing to revert.
    }
};
