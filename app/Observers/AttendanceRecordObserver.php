<?php

namespace App\Observers;

use App\Jobs\SendParentNotification;
use App\Models\AttendanceRecord;
use Illuminate\Support\Facades\DB;

class AttendanceRecordObserver
{
    public function created(AttendanceRecord $record): void
    {
        $this->scheduleNotification($record);
    }

    public function updated(AttendanceRecord $record): void
    {
        if ($record->wasChanged(['status', 'leave_type_id', 'source_type'])) {
            $this->scheduleNotification($record);
        }
    }

    private function scheduleNotification(AttendanceRecord $record): void
    {
        if ($record->scene === 'evening_study'
            || $record->is_self_applied
            || in_array($record->source_type, ['self_applied', 'leave_request'], true)) {
            return;
        }

        $recordId = $record->id;
        $connection = DB::connection($record->getConnectionName());

        $connection->afterCommit(function () use ($recordId) {
            SendParentNotification::dispatchAfterResponse($recordId, 'attendance');
        });
    }
}
