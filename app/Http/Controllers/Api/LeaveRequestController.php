<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Models\AttendanceRecord;
use App\Services\LeaveConflictService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

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
            $query->where('student_id', $user->student->id);
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
        $request->validate([
            'type' => 'required|in:sick,personal',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'half_day' => 'nullable|in:am,pm',
            'sessions' => 'nullable|array', // [1, 2]
            'reason' => 'nullable|string',
        ]);

        $user = $request->user();
        $student = $user->student;

        if (!$student) {
             return response()->json(['error' => 'User is not a student'], 403);
        }

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

        return response()->json($leave, 201);
    }

    public function approve(Request $request, $id)
    {
        $user = $request->user();
        // Check permission (simple check)
        if (!in_array($user->role, ['teacher', 'admin', 'manager'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $leave = LeaveRequest::findOrFail($id);
        
        // Ensure teacher owns this class
        if ($user->role === 'teacher') {
             $ownsClass = $user->teacherClasses()->where('id', $leave->class_id)->exists();
             if (!$ownsClass) return response()->json(['error' => 'Not your class'], 403);
        }

        $leave->update([
            'status' => 'approved',
            'approver_id' => $user->id,
            'approved_at' => now(),
        ]);

        // Generate Attendance Records
        $this->generateAttendance($leave);

        return response()->json(['message' => 'Approved', 'data' => $leave]);
    }

    public function reject(Request $request, $id)
    {
        $user = $request->user();
        if (!in_array($user->role, ['teacher', 'admin', 'manager'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        
        $leave = LeaveRequest::findOrFail($id);
        $leave->update([
            'status' => 'rejected',
            'approver_id' => $user->id,
            'rejection_reason' => $request->input('reason'),
        ]);

        return response()->json(['message' => 'Rejected']);
    }

    protected function generateAttendance(LeaveRequest $leave)
    {
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
                        'source_type' => 'leave_request',
                        'source_id' => $leave->id,
                    ]
                );
            }

            $start->addDay();
        }
    }
}
