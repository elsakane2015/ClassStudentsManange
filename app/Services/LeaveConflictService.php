<?php

namespace App\Services;

use App\Models\LeaveRequest;
use Carbon\Carbon;

class LeaveConflictService
{
    /**
     * Check for conflicts.
     * 
     * @param int $studentId
     * @param string $start YYYY-MM-DD
     * @param string $end YYYY-MM-DD
     * @param array|null $sessions Array of period IDs or null for whole day
     * @param int|null $excludeId ID to exclude (for updates)
     * @return \Illuminate\Support\Collection Conflicting requests
     */
    public function check($studentId, $start, $end, $sessions = null, $excludeId = null)
    {
        $query = LeaveRequest::where('student_id', $studentId)
            ->whereIn('status', ['pending', 'approved']);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        // Basic date overlap: (StartA <= EndB) and (EndA >= StartB)
        $query->where(function ($q) use ($start, $end) {
             $q->whereBetween('start_date', [$start, $end])
               ->orWhereBetween('end_date', [$start, $end])
               ->orWhere(function ($sub) use ($start, $end) {
                   $sub->where('start_date', '<=', $start)
                       ->where('end_date', '>=', $end);
               });
        });

        $candidates = $query->get();

        if ($candidates->isEmpty()) {
            return collect([]);
        }

        // Refined check for partial days
        // Case 1: If current request is WHOLE DAY ($sessions is null), any overlap is a conflict.
        if (empty($sessions)) {
            return $candidates;
        }

        // Case 2: Current request is PARTIAL (sessions defined).
        // We only care if the overlapping candidate is ALSO partial and sessions do not overlap.
        // If candidate is WHOLE DAY, it conflicts.
        
        $conflicts = $candidates->filter(function ($candidate) use ($sessions, $start, $end) {
            // If candidate is whole day, it conflicts.
            if (empty($candidate->sessions)) {
                return true;
            }

            // If candidate is partial, we need to check if dates match exactly.
            // Complex case: Multi-day partials? Usually partials are single day.
            // Assumption: Partial requests are usually single day or logic simplifies.
            // Let's assume strict checking: if dates overlap, check sessions intersection.
            
            // If the dates are different (but range overlaps), getting sessions correct is hard without day-by-day mapping.
            // Simplified Rule: If ranges overlap and both are partial, check session intersection.
            // If ranges cover multiple days, usually we treat it as blocking those days.
            
            // Intersection of sessions
            $candidateSessions = $candidate->sessions ?? [];
            $intersection = array_intersect($sessions, $candidateSessions);
            
            return count($intersection) > 0;
        });

        return $conflicts;
    }
}
