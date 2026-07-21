<?php

namespace App\Observers;

use App\Models\AttendanceRecord;
use App\Services\ParentEmailNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        $recordId = $record->id;
        $connection = DB::connection($record->getConnectionName());

        $connection->afterCommit(function () use ($recordId) {
            try {
                $freshRecord = AttendanceRecord::find($recordId);
                if ($freshRecord) {
                    app(ParentEmailNotificationService::class)->sendAttendanceNotification($freshRecord);
                }
            } catch (\Throwable $e) {
                Log::warning('Parent email attendance observer failed', [
                    'attendance_record_id' => $recordId,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }
}
