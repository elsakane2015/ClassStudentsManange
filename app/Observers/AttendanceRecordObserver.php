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

            try {
                if (\Illuminate\Support\Facades\Schema::hasTable('app_notifications')) {
                    $freshRecord = AttendanceRecord::find($recordId);
                    if ($freshRecord && $freshRecord->status !== 'present') {
                        $pushService = app(\App\Services\MobilePushService::class);
                        if ($isCreated) {
                            $pushService->notifyAttendanceRecorded($freshRecord);
                        }
                        // Roll-call absences are aggregated and pushed once per student in RollCallController::complete
                        // so multi-period roll calls do not spam parents; other marks & status updates push here.
                        if ($freshRecord->source_type !== 'roll_call') {
                            $pushService->notifyParentsAttendanceMarked($freshRecord);
                        }
                    }
                }
            } catch (\Throwable $e) {
                \Log::warning('Mobile attendance push failed', ['error' => $e->getMessage()]);
            }
        });
    }
}
