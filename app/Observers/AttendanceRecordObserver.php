<?php

namespace App\Observers;

use App\Jobs\SendParentNotification;
use App\Models\AttendanceRecord;
use Illuminate\Support\Facades\DB;

class AttendanceRecordObserver
{
    public function created(AttendanceRecord $record): void
    {
        $this->scheduleNotification($record, true);
    }

    public function updated(AttendanceRecord $record): void
    {
        if ($record->wasChanged(['status', 'leave_type_id', 'source_type'])) {
            $this->scheduleNotification($record, false);
        }
    }

    private function scheduleNotification(AttendanceRecord $record, bool $isCreated): void
    {
        if ($record->scene === 'evening_study'
            || $record->is_self_applied
            || in_array($record->source_type, ['self_applied', 'leave_request'], true)) {
            return;
        }

        $recordId = $record->id;
        $connection = DB::connection($record->getConnectionName());

        $connection->afterCommit(function () use ($recordId, $isCreated) {
            SendParentNotification::dispatchAfterResponse($recordId, 'attendance');

            if ($isCreated) {
                try {
                    if (\Illuminate\Support\Facades\Schema::hasTable('app_notifications')) {
                        $freshRecord = AttendanceRecord::find($recordId);
                        if ($freshRecord) {
                            app(\App\Services\MobilePushService::class)->notifyAttendanceRecorded($freshRecord);
                        }
                    }
                } catch (\Throwable $e) {
                    \Log::warning('Mobile attendance push failed', ['error' => $e->getMessage()]);
                }
            }
        });
    }
}
