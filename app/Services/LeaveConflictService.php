<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use Carbon\Carbon;

class LeaveConflictService
{
    /**
     * Check for conflicts by looking at attendance_records directly.
     * This is the unified data source approach.
     * 
     * @param int $studentId
     * @param string $start YYYY-MM-DD
     * @param string $end YYYY-MM-DD
     * @param array|null $sessions Array of period IDs or null for whole day
     * @param int|null $excludeSourceId Source ID to exclude (for updates)
     * @return \Illuminate\Support\Collection Conflicting records
     */
    public function check($studentId, $start, $end, $sessions = null, $excludeSourceId = null)
    {
        // UNIFIED DATA SOURCE: Check attendance_records directly
        // Only check for self-applied leaves (source_type = 'leave_request' or 'self_applied')
        // Teacher-marked records (manual_bulk, auto, etc.) should not block new leave requests
        $query = AttendanceRecord::where('student_id', $studentId)
            ->whereBetween('date', [$start, $end])
            // Only check self-applied leaves
            ->where(function($q) {
                $q->where('source_type', 'leave_request')
                  ->orWhere('source_type', 'self_applied')
                  ->orWhere('is_self_applied', true);
            })
            // Exclude rejected records
            ->where(function($q) {
                $q->whereNull('approval_status')
                  ->orWhereIn('approval_status', ['pending', 'approved']);
            });

        // Exclude records from a specific source (for editing existing requests)
        if ($excludeSourceId) {
            $query->where(function($q) use ($excludeSourceId) {
                $q->where('source_id', '!=', $excludeSourceId)
                  ->orWhereNull('source_id');
            });
        }

        $candidates = $query->with('leaveType')->get();

        if ($candidates->isEmpty()) {
            return collect([]);
        }

        // If requesting whole day, any existing record is a conflict
        if (empty($sessions)) {
            return $candidates;
        }

        // If requesting specific periods, check for overlap
        $conflicts = $candidates->filter(function ($record) use ($sessions) {
            // If existing record is whole day (no period_id), it conflicts
            if (is_null($record->period_id)) {
                return true;
            }

            // If existing record is for a specific period, check if it overlaps
            return in_array($record->period_id, $sessions);
        });

        return $conflicts;
    }
}
