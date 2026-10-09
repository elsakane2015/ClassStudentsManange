<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\RollCall;
use App\Models\RollCallRecord;
use App\Models\TimeSlot;

class RollCallAttendanceSyncService
{
    /**
     * Replace overlapping roll-call absences with an approved/manual leave record.
     */
    public function replaceAbsenceWithLeave(AttendanceRecord $record, ?array $rollCallPending = null): void
    {
        $details = is_array($record->details)
            ? $record->details
            : (json_decode($record->details ?? '{}', true) ?? []);
        $periodIds = $this->resolvePeriodIds($record, $details);
        $isFullDay = in_array($details['option'] ?? null, ['全天', 'full_day'], true);
        $rollCallRecordIds = collect();

        $rollCallPending = $rollCallPending ?? ($details['roll_call_pending'] ?? null);
        if (!empty($rollCallPending['roll_call_record_id'])) {
            $rollCallRecordIds->push((int) $rollCallPending['roll_call_record_id']);
        }

        $attendanceQuery = AttendanceRecord::withoutGlobalScope('day_attendance')
            ->where('student_id', $record->student_id)
            ->whereDate('date', $record->date)
            ->where('source_type', 'roll_call')
            ->where(function ($query) {
                $query->whereNull('approval_status')
                    ->orWhere('is_self_applied', false);
            });

        if (!empty($periodIds)) {
            $attendanceQuery->whereIn('period_id', $periodIds);
        } elseif (!$isFullDay) {
            $attendanceQuery->whereNull('period_id');
        }

        $rollCallAttendanceRecords = $attendanceQuery->get();
        foreach ($rollCallAttendanceRecords as $rollCallAttendance) {
            $rollCallDetails = is_array($rollCallAttendance->details)
                ? $rollCallAttendance->details
                : (json_decode($rollCallAttendance->details ?? '{}', true) ?? []);

            if (!empty($rollCallDetails['roll_call_record_id'])) {
                $rollCallRecordIds->push((int) $rollCallDetails['roll_call_record_id']);
            }
        }

        if ($rollCallAttendanceRecords->isNotEmpty()) {
            AttendanceRecord::withoutGlobalScope('day_attendance')
                ->whereIn('id', $rollCallAttendanceRecords->pluck('id'))
                ->delete();
        }

        if ($rollCallRecordIds->isEmpty()) {
            $candidateRollCalls = RollCall::where('class_id', $record->class_id)
                ->whereDate('roll_call_time', $record->date)
                ->where('status', 'completed')
                ->with('rollCallType')
                ->get();

            foreach ($candidateRollCalls as $rollCall) {
                $rollCallPeriodIds = array_map('intval', $rollCall->rollCallType?->period_ids ?? []);
                $overlaps = $isFullDay
                    || empty($periodIds)
                    || empty($rollCallPeriodIds)
                    || !empty(array_intersect($periodIds, $rollCallPeriodIds));

                if (!$overlaps) {
                    continue;
                }

                $matchingRecordId = RollCallRecord::where('roll_call_id', $rollCall->id)
                    ->where('student_id', $record->student_id)
                    ->whereIn('status', ['absent', 'pending'])
                    ->value('id');
                if ($matchingRecordId) {
                    $rollCallRecordIds->push((int) $matchingRecordId);
                }
            }
        }

        $rollCallRecordIds = $rollCallRecordIds->filter()->unique()->values();
        if ($rollCallRecordIds->isEmpty()) {
            return;
        }

        $record->loadMissing('leaveType');
        $optionLabel = $details['time_slot_name']
            ?? $details['option_label']
            ?? $details['display_label']
            ?? $details['option']
            ?? null;
        $leaveTypeName = $record->leaveType?->name ?? '请假';
        $leaveDetail = $optionLabel ? "{$leaveTypeName}({$optionLabel})" : $leaveTypeName;

        RollCallRecord::whereIn('id', $rollCallRecordIds)
            ->whereIn('status', ['absent', 'pending'])
            ->update([
                'status' => 'on_leave',
                'leave_type_id' => $record->leave_type_id,
                'leave_detail' => $leaveDetail,
                'leave_status' => 'approved',
            ]);

        $rollCallIds = RollCallRecord::whereIn('id', $rollCallRecordIds)
            ->pluck('roll_call_id')
            ->unique();

        foreach ($rollCallIds as $rollCallId) {
            $this->recalculateCounts((int) $rollCallId);
        }
    }

    private function resolvePeriodIds(AttendanceRecord $record, array $details): array
    {
        if ($record->period_id !== null) {
            return [(int) $record->period_id];
        }

        if (!empty($details['period_ids']) && is_array($details['period_ids'])) {
            return array_values(array_unique(array_map('intval', $details['period_ids'])));
        }

        $timeSlotId = $details['time_slot_id'] ?? null;
        if (!$timeSlotId && !empty($details['option']) && preg_match('/^time_slot_(\d+)$/', $details['option'], $matches)) {
            $timeSlotId = (int) $matches[1];
        }

        if ($timeSlotId) {
            $timeSlot = TimeSlot::find($timeSlotId);
            if ($timeSlot) {
                return array_values(array_unique(array_map('intval', $timeSlot->period_ids ?? [])));
            }
        }

        return [];
    }

    private function recalculateCounts(int $rollCallId): void
    {
        RollCall::where('id', $rollCallId)->update([
            'present_count' => RollCallRecord::where('roll_call_id', $rollCallId)
                ->where('status', 'present')
                ->count(),
            'on_leave_count' => RollCallRecord::where('roll_call_id', $rollCallId)
                ->where('status', 'on_leave')
                ->count(),
        ]);
    }
}
