<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Models\AttendanceRecord;
use App\Services\LeaveConflictService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Validation\Rule;

class LeaveRequestController extends Controller
{
    protected $conflictService;

    public function __construct(LeaveConflictService $conflictService)
    {
        $this->conflictService = $conflictService;
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $query = LeaveRequest::with(['student.user', 'class']);

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
            $query->where('status', $request->status);
        }

        return response()->json($query->latest()->paginate(20));
    }

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
                // Validate slug exists in leave_types for THIS school
                Rule::exists('leave_types', 'slug')->where(function ($query) use ($user) {
                     $query->where('school_id', $user->student->school_id);
                }),
            ],
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'half_day' => 'nullable|in:am,pm',
            'sessions' => 'nullable|array', // [1, 2]
            'reason' => 'nullable|string',
        ]);
        
        $student = $user->student;
        
        // Get leave type
        $leaveType = \App\Models\LeaveType::where('slug', $request->type)
            ->where('school_id', $student->school_id)
            ->first();

        // Conflict Check - check attendance_records with pending or approved status
        $conflicts = $this->conflictService->check(
            $student->id,
            $request->start_date,
            $request->end_date,
            $request->sessions
        );

        if ($conflicts->isNotEmpty()) {
            // Log for debugging
            \Log::info('Leave conflict detected', [
                'student_id' => $student->id,
                'start' => $request->start_date,
                'end' => $request->end_date,
                'sessions' => $request->sessions,
                'conflicts' => $conflicts->toArray()
            ]);
            
            return response()->json([
                'error' => 'Conflict detected with existing requests.',
                'conflicts' => $conflicts
            ], 409);
        }

        // Build details
        $details = [];
        if ($request->half_day) {
            $details['option'] = $request->half_day;
        }

        // Create attendance records directly (unified data source)
        $start = Carbon::parse($request->start_date);
        $end = Carbon::parse($request->end_date);
        $createdRecords = [];

        while ($start->lte($end)) {
            if ($request->sessions) {
                // Partial day - create record for each period
                foreach ($request->sessions as $periodId) {
                    $record = AttendanceRecord::create([
                        'student_id' => $student->id,
                        'school_id' => $student->school_id,
                        'class_id' => $student->class_id,
                        'date' => $start->toDateString(),
                        'period_id' => $periodId,
                        'status' => 'leave',  // Will be changed to 'excused' when approved
                        'leave_type_id' => $leaveType?->id,
                        'details' => !empty($details) ? $details : null,
                        'is_self_applied' => true,
                        'approval_status' => 'pending',
                        'reason' => $request->reason,
                        'source_type' => 'self_applied',
                        'source_id' => null,
                    ]);
                    $createdRecords[] = $record;
                }
            } else {
                // Whole day
                $record = AttendanceRecord::create([
                    'student_id' => $student->id,
                    'school_id' => $student->school_id,
                    'class_id' => $student->class_id,
                    'date' => $start->toDateString(),
                    'period_id' => null,
                    'status' => 'leave',  // Will be changed to 'excused' when approved
                    'leave_type_id' => $leaveType?->id,
                    'details' => !empty($details) ? $details : null,
                    'is_self_applied' => true,
                    'approval_status' => 'pending',
                    'reason' => $request->reason,
                    'source_type' => 'self_applied',
                    'source_id' => null,
                ]);
                $createdRecords[] = $record;
            }

            $start->addDay();
        }

        // Also create a LeaveRequest for backward compatibility and tracking
        $leave = LeaveRequest::create([
            'student_id' => $student->id,
            'user_id' => $user->id,
            'school_id' => $student->school_id,
            'class_id' => $student->class_id,
            'type' => $request->type,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'half_day' => $request->half_day,
            'sessions' => $request->sessions,
            'reason' => $request->reason,
            'status' => 'pending',
        ]);

        // Link attendance records to leave request
        foreach ($createdRecords as $record) {
            $record->update([
                'source_type' => 'leave_request',
                'source_id' => $leave->id,
            ]);
        }

        return response()->json($leave, 201);
    }

    public function approve(Request $request, $id)
    {
        $user = $request->user();
        
        // Check if user is class admin student
        $isClassAdmin = $user->student && $user->student->is_class_admin;
        
        // Check permission
        if (!in_array($user->role, ['teacher', 'admin', 'manager']) && !$isClassAdmin) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $leave = LeaveRequest::findOrFail($id);
        
        // Ensure teacher owns this class
        if ($user->role === 'teacher') {
             $ownsClass = $user->teacherClasses()->where('id', $leave->class_id)->exists();
             if (!$ownsClass) return response()->json(['error' => 'Not your class'], 403);
        }
        
        // Ensure class admin can only approve requests from their own class
        if ($isClassAdmin) {
            if ($leave->class_id !== $user->student->class_id) {
                return response()->json(['error' => 'Not your class'], 403);
            }
        }

        $leave->update([
            'status' => 'approved',
            'approver_id' => $user->id,
            'approved_at' => now(),
        ]);

        // Update existing attendance records (created when leave was submitted)
        AttendanceRecord::where('source_type', 'leave_request')
            ->where('source_id', $leave->id)
            ->update([
                'status' => 'excused',
                'approval_status' => 'approved',
                'approver_id' => $user->id,
                'approved_at' => now(),
            ]);

        return response()->json(['message' => 'Approved', 'data' => $leave]);
    }

    public function reject(Request $request, $id)
    {
        $user = $request->user();
        
        // Check if user is class admin student
        $isClassAdmin = $user->student && $user->student->is_class_admin;
        
        // Check permission
        if (!in_array($user->role, ['teacher', 'admin', 'manager']) && !$isClassAdmin) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        
        $leave = LeaveRequest::findOrFail($id);
        
        // Ensure class admin can only reject requests from their own class
        if ($isClassAdmin) {
            if ($leave->class_id !== $user->student->class_id) {
                return response()->json(['error' => 'Not your class'], 403);
            }
        }
        
        $leave->update([
            'status' => 'rejected',
            'approver_id' => $user->id,
            'rejection_reason' => $request->input('reason'),
        ]);

        // Delete associated attendance records (they were created when leave was submitted)
        AttendanceRecord::where('source_type', 'leave_request')
            ->where('source_id', $leave->id)
            ->delete();

        return response()->json(['message' => 'Rejected']);
    }

    /**
     * Delete/Cancel a pending leave request (student can only cancel their own pending requests)
     */
    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        $leave = LeaveRequest::findOrFail($id);
        
        // Students can only cancel their own pending requests
        if ($user->role === 'student') {
            if ($leave->student_id !== $user->student->id) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
            if ($leave->status !== 'pending') {
                return response()->json(['error' => 'Only pending requests can be cancelled'], 400);
            }
        }
        
        // Delete associated attendance records
        AttendanceRecord::where('source_type', 'leave_request')
            ->where('source_id', $leave->id)
            ->delete();
        
        // Update status to cancelled
        $leave->update(['status' => 'cancelled']);
        
        return response()->json(['message' => 'Leave request cancelled successfully.']);
    }

    protected function generateAttendance(LeaveRequest $leave)
    {
        // Get leave type by slug to get the ID
        $leaveType = \App\Models\LeaveType::where('slug', $leave->type)
            ->where('school_id', $leave->school_id)
            ->first();
        
        $leaveTypeId = $leaveType ? $leaveType->id : null;

        // Build details from leave request
        $details = [];
        if ($leave->details) {
            $details = is_string($leave->details) ? json_decode($leave->details, true) : $leave->details;
        }
        // Add half_day info if present
        if ($leave->half_day) {
            $details['option'] = $leave->half_day; // 'am' or 'pm'
        }

        // Simple logic: Loop days and create record
        $start = Carbon::parse($leave->start_date);
        $end = Carbon::parse($leave->end_date);

        while ($start->lte($end)) {
            // Skip weekends? Configuration dependent. Assuming simple flow for now.
            
            if ($leave->sessions) {
                // Partial day
                foreach ($leave->sessions as $periodId) {
                    AttendanceRecord::updateOrCreate(
                        [
                            'student_id' => $leave->student_id,
                            'date' => $start->toDateString(),
                            'period_id' => $periodId,
                        ],
                        [
                            'school_id' => $leave->school_id,
                            'class_id' => $leave->class_id,
                            'status' => 'excused',
                            'leave_type_id' => $leaveTypeId,
                            'details' => !empty($details) ? $details : null,
                            'source_type' => 'leave_request',
                            'source_id' => $leave->id,
                        ]
                    );
                }
            } else {
                // Whole day
                AttendanceRecord::updateOrCreate(
                    [
                        'student_id' => $leave->student_id,
                        'date' => $start->toDateString(),
                        'period_id' => null, // Whole day
                    ],
                    [
                        'school_id' => $leave->school_id,
                        'class_id' => $leave->class_id,
                        'status' => 'excused',
                        'leave_type_id' => $leaveTypeId,
                        'details' => !empty($details) ? $details : null,
                        'source_type' => 'leave_request',
                        'source_id' => $leave->id,
                    ]
                );
            }

            $start->addDay();
        }
    }
}
