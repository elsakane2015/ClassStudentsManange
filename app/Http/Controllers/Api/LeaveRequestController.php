<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Services\LeaveConflictService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Validation\Rule;

/**
 * UNIFIED DATA SOURCE: All leave/attendance data is stored in attendance_records table.
 * LeaveRequest table is DEPRECATED and no longer used.
 * 
 * Key fields in attendance_records:
 * - is_self_applied: true = student applied, false = teacher marked
 * - approval_status: pending/approved/rejected (for self-applied)
 * - source_type: 'self_applied' for student requests
 * - leave_type_id: the type of leave
 * - details: JSON with option (am/pm/etc)
 * - reason: the reason for leave
 */
class LeaveRequestController extends Controller
{
    protected $conflictService;

    public function __construct(LeaveConflictService $conflictService)
    {
        $this->conflictService = $conflictService;
    }

    /**
     * List leave records (self-applied attendance records with pending status)
     */
    public function index(Request $request)
    {
        $user = $request->user();
        
        // Query attendance_records for self-applied leaves
        $query = AttendanceRecord::with(['student.user', 'class', 'leaveType', 'approver'])
            ->where('is_self_applied', true);

        if ($user->role === 'student') {
            // Check if student is class admin
            if ($user->student && $user->student->is_class_admin) {
                // Class admin can see their class's requests
                $query->where('class_id', $user->student->class_id);
            } else {
                // Regular student only sees their own
                $query->where('student_id', $user->student->id);
            }
        } elseif ($user->role === 'teacher') {
            // Show requests for classes the teacher manages
            $classIds = $user->teacherClasses->pluck('id');
            $query->whereIn('class_id', $classIds);
        }
        // Admin sees all

        if ($request->has('status')) {
            $query->where('approval_status', $request->status);
        }

        // Group by student_id, date, approval_status for list display
        $records = $query->orderBy('created_at', 'desc')->get();
        
        // Group records into "requests" (by student + date range + approval_status)
        $groupedRequests = $records->groupBy(function($record) {
            return $record->student_id . '_' . $record->date->format('Y-m-d') . '_' . $record->approval_status;
        })->map(function($group) {
            $first = $group->first();
            return [
                'id' => $first->id, // Use first record's id as request id
                'student_id' => $first->student_id,
                'student' => $first->student,
                'class' => $first->class,
                'class_id' => $first->class_id,
                'type' => $first->leaveType?->slug ?? 'leave',
                'leave_type' => $first->leaveType,
                'start_date' => $first->date->format('Y-m-d'),
                'end_date' => $group->max('date')->format('Y-m-d'),
                'half_day' => $first->details['option'] ?? null,
                'half_day_label' => $this->getOptionLabel($first),
                'reason' => $first->reason,
                'images' => $first->images,
                'status' => $first->approval_status ?? 'approved',
                'approval_status' => $first->approval_status,
                'approved_at' => $first->approved_at,
                'approver_name' => $first->approver?->name,
                'created_at' => $first->created_at,
                'record_ids' => $group->pluck('id')->toArray(),
            ];
        })->values();

        return response()->json([
            'data' => $groupedRequests,
            'total' => $groupedRequests->count(),
        ]);
    }

    /**
     * Create a new leave request (creates attendance_records with pending status)
     */
    public function store(Request $request)
    {
        $user = $request->user();
        
        // Ensure user is student
        if (!$user->student) {
             return response()->json(['error' => 'User is not a student'], 403);
        }
        
        $request->validate([
            'type' => [
                'required',
                Rule::exists('leave_types', 'slug')->where(function ($query) use ($user) {
                     $query->where('school_id', $user->student->school_id);
                }),
            ],
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'half_day' => 'nullable|in:am,pm',
            'time_slot_id' => 'nullable|exists:time_slots,id',
            'sessions' => 'nullable|array',
            'reason' => 'nullable|string',
            'images' => 'nullable|array',
            'images.*' => 'string',
        ]);
        
        $student = $user->student;
        $images = $request->images ?? [];
        
        // Get leave type
        $leaveType = \App\Models\LeaveType::where('slug', $request->type)
            ->where('school_id', $student->school_id)
            ->first();

        // Conflict Check
        $conflicts = $this->conflictService->check(
            $student->id,
            $request->start_date,
            $request->end_date,
            $request->sessions
        );

        if ($conflicts->isNotEmpty()) {
            return response()->json([
                'error' => 'Conflict detected with existing requests.',
                'conflicts' => $conflicts
            ], 409);
        }

        // Build details
        $details = [];
        $requestDetails = $request->details;
        if (is_string($requestDetails)) {
            $requestDetails = json_decode($requestDetails, true);
        }
        
        if (!empty($requestDetails['option'])) {
            $details['option'] = $requestDetails['option'];
        }
        if ($request->half_day) {
            $details['option'] = $request->half_day;
        }
        if (!empty($requestDetails)) {
            foreach ($requestDetails as $key => $value) {
                if (!isset($details[$key])) {
                    $details[$key] = $value;
                }
            }
        }
        
        // 处理时段：如果提供了 time_slot_id，获取其关联的节次
        $periodIds = $request->sessions ?? [];
        $timeSlotId = $request->time_slot_id;
        
        if ($timeSlotId) {
            $timeSlot = \App\Models\TimeSlot::find($timeSlotId);
            if ($timeSlot && !empty($timeSlot->period_ids)) {
                $periodIds = $timeSlot->period_ids;
                $details['time_slot_id'] = $timeSlotId;
                $details['time_slot_name'] = $timeSlot->name;
                $details['period_ids'] = $periodIds;
            }
        }

        // Create attendance records (UNIFIED DATA SOURCE)
        $start = Carbon::parse($request->start_date);
        $end = Carbon::parse($request->end_date);
        $createdRecords = [];

        DB::beginTransaction();
        try {
            while ($start->lte($end)) {
                if (!empty($periodIds)) {
                    // 为每个节次创建独立的考勤记录
                    foreach ($periodIds as $periodId) {
                        $record = AttendanceRecord::create([
                            'student_id' => $student->id,
                            'school_id' => $student->school_id,
                            'class_id' => $student->class_id,
                            'date' => $start->toDateString(),
                            'period_id' => $periodId,
                            'status' => 'leave',
                            'leave_type_id' => $leaveType?->id,
                            'details' => !empty($details) ? $details : null,
                            'images' => !empty($images) ? $images : null,
                            'is_self_applied' => true,
                            'approval_status' => 'pending',
                            'reason' => $request->reason,
                            'source_type' => 'self_applied',
                        ]);
                        $createdRecords[] = $record;
                    }
                } else {
                    // 无特定节次，创建一条全天记录
                    $record = AttendanceRecord::create([
                        'student_id' => $student->id,
                        'school_id' => $student->school_id,
                        'class_id' => $student->class_id,
                        'date' => $start->toDateString(),
                        'period_id' => null,
                        'status' => 'leave',
                        'leave_type_id' => $leaveType?->id,
                        'details' => !empty($details) ? $details : null,
                        'images' => !empty($images) ? $images : null,
                        'is_self_applied' => true,
                        'approval_status' => 'pending',
                        'reason' => $request->reason,
                        'source_type' => 'self_applied',
                    ]);
                    $createdRecords[] = $record;
                }
                $start->addDay();
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Failed to create leave request: ' . $e->getMessage()], 500);
        }

        // Return first record as the "request" representation
        $firstRecord = $createdRecords[0] ?? null;

        // Trigger WeChat push notification
        if ($firstRecord) {
            try {
                $pushService = app(\App\Services\WechatPushService::class);
                $pushService->sendLeaveRequestNotification($firstRecord);
            } catch (\Exception $e) {
                // Log but don't fail the request
                \Log::warning('WeChat push failed', ['error' => $e->getMessage()]);
            }
        }
        
        return response()->json([
            'id' => $firstRecord?->id,
            'student_id' => $student->id,
            'type' => $request->type,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'half_day' => $details['option'] ?? null,
            'reason' => $request->reason,
            'status' => 'pending',
            'record_count' => count($createdRecords),
        ], 201);
    }

    /**
     * Approve a leave request (update attendance_records)
     */
    public function approve(Request $request, $id)
    {
        $user = $request->user();
        
        $isClassAdmin = $user->student && $user->student->is_class_admin;
        
        if (!in_array($user->role, ['teacher', 'admin', 'manager']) && !$isClassAdmin) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $record = AttendanceRecord::findOrFail($id);
        
        // Ensure teacher/class admin owns this class
        if ($user->role === 'teacher') {
            $ownsClass = $user->teacherClasses()->where('id', $record->class_id)->exists();
            if (!$ownsClass) return response()->json(['error' => 'Not your class'], 403);
        }
        
        if ($isClassAdmin && $record->class_id !== $user->student->class_id) {
            return response()->json(['error' => 'Not your class'], 403);
        }

        // Find all related records (same student, same date range, same source)
        // For now, approve just this single record or all records for same student on same date
        $relatedRecords = AttendanceRecord::where('student_id', $record->student_id)
            ->where('date', $record->date)
            ->where('approval_status', 'pending')
            ->where('is_self_applied', true)
            ->get();

        foreach ($relatedRecords as $r) {
            $r->update([
                'status' => 'excused',
                'approval_status' => 'approved',
                'approver_id' => $user->id,
                'approved_at' => now(),
            ]);
        }

        return response()->json(['message' => 'Approved', 'approved_count' => $relatedRecords->count()]);
    }

    /**
     * Reject a leave request
     */
    public function reject(Request $request, $id)
    {
        $user = $request->user();
        
        $isClassAdmin = $user->student && $user->student->is_class_admin;
        
        if (!in_array($user->role, ['teacher', 'admin', 'manager']) && !$isClassAdmin) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        
        $record = AttendanceRecord::findOrFail($id);
        
        if ($isClassAdmin && $record->class_id !== $user->student->class_id) {
            return response()->json(['error' => 'Not your class'], 403);
        }
        
        // Find and delete all related pending records
        $relatedRecords = AttendanceRecord::where('student_id', $record->student_id)
            ->where('date', $record->date)
            ->where('approval_status', 'pending')
            ->where('is_self_applied', true)
            ->get();
        
        foreach ($relatedRecords as $r) {
            $r->update([
                'approval_status' => 'rejected',
                'approver_id' => $user->id,
                'rejection_reason' => $request->input('reason'),
            ]);
        }
        
        // Optionally delete rejected records
        // AttendanceRecord::whereIn('id', $relatedRecords->pluck('id'))->delete();

        return response()->json(['message' => 'Rejected', 'rejected_count' => $relatedRecords->count()]);
    }

    /**
     * Cancel/Delete a pending leave request (student can only cancel their own)
     */
    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        $record = AttendanceRecord::findOrFail($id);
        
        // Students can only cancel their own pending requests
        if ($user->role === 'student') {
            if ($record->student_id !== $user->student->id) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
            if ($record->approval_status !== 'pending') {
                return response()->json(['error' => 'Only pending requests can be cancelled'], 400);
            }
        }
        
        // Find and delete all related pending records for same date
        $relatedRecords = AttendanceRecord::where('student_id', $record->student_id)
            ->where('date', $record->date)
            ->where('approval_status', 'pending')
            ->where('is_self_applied', true);
            
        $count = $relatedRecords->count();
        $relatedRecords->delete();
        
        return response()->json(['message' => 'Leave request cancelled successfully.', 'deleted_count' => $count]);
    }

    /**
     * Get option label from leave type config
     */
    protected function getOptionLabel($record)
    {
        $details = is_array($record->details) ? $record->details : (is_string($record->details) ? json_decode($record->details, true) : []);
        
        // 优先使用 time_slot_name（新的时段选择方式）
        if (isset($details['time_slot_name'])) {
            return $details['time_slot_name'];
        }
        
        $option = $details['option'] ?? null;
        if (!$option || !$record->leaveType) {
            return null;
        }
        
        $config = $record->leaveType->input_config;
        if (is_string($config)) {
            try {
                $config = json_decode($config, true);
            } catch (\Exception $e) {
                return $option;
            }
        }
        
        $options = $config['options'] ?? [];
        foreach ($options as $opt) {
            $key = is_array($opt) ? ($opt['key'] ?? $opt) : $opt;
            $label = is_array($opt) ? ($opt['label'] ?? $key) : $key;
            if ($key === $option) {
                return $label;
            }
        }
        
        return $option;
    }
}
