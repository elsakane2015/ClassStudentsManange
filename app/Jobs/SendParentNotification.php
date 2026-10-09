<?php

namespace App\Jobs;

use App\Models\AttendanceRecord;
use App\Services\ParentEmailNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendParentNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 90;

    public function __construct(
        public int $attendanceRecordId,
        public string $notificationType
    ) {}

    public function handle(ParentEmailNotificationService $notifications): void
    {
        $record = AttendanceRecord::withoutGlobalScope('day_attendance')
            ->find($this->attendanceRecordId);

        if (! $record) {
            return;
        }

        if ($this->notificationType === 'leave_request') {
            $notifications->sendLeaveRequestNotification($record);

            return;
        }

        $notifications->sendAttendanceNotification($record);
    }
}
