<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\LeaveRequest;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $query = AttendanceRecord::query();

        if ($user->role === 'student') {
            $query->where('student_id', $user->student->id);
        } elseif ($user->role === 'teacher') {
             $classIds = $user->teacherClasses->pluck('id');
             $query->whereIn('class_id', $classIds);
        }

        if ($request->has(['start', 'end'])) {
            $query->whereBetween('date', [$request->start, $request->end]);
        }

        return response()->json($query->get());
    }
    
    // Combined Calendar View
    public function calendar(Request $request) 
    {
        $user = $request->user();
        if ($user->role !== 'student') abort(403);
        
        $start = $request->input('start');
        $end = $request->input('end');
        
        $attendance = AttendanceRecord::where('student_id', $user->student->id)
            ->whereBetween('date', [$start, $end])
            ->get();
            
        $leaves = LeaveRequest::where('student_id', $user->student->id)
            ->whereBetween('start_date', [$start, $end])
            ->orWhereBetween('end_date', [$start, $end])
            ->get();
            
        return response()->json([
            'attendance' => $attendance,
            'leaves' => $leaves
        ]);
    }

    public function store(Request $request)
    {
        // Teacher/Admin manually adding a record (e.g., Late, Absent)
        $user = $request->user();
        if (!in_array($user->role, ['teacher', 'admin'])) abort(403);

        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
            'date' => 'required|date',
            'period_id' => 'nullable|exists:class_periods,id',
            'status' => 'required|in:present,absent,late,excused,early_leave',
            'note' => 'nullable|string',
            'informed_parent' => 'boolean'
        ]);
        
        // TODO: Validate teacher owns student's class
        
        $record = AttendanceRecord::updateOrCreate(
            [
                'student_id' => $data['student_id'],
                'date' => $data['date'],
                'period_id' => $data['period_id'],
            ],
            array_merge($data, [
                'school_id' => $request->user()->student->school_id ?? 1, // Fix: Teacher might not have school_id easy access if not in user table properly. Assuming user->school? User table doesn't have school_id. Teacher->classes->school_id. 
                // Simplified: Fetch student's school
                'class_id' => \App\Models\Student::find($data['student_id'])->class_id,
                'school_id' => \App\Models\Student::find($data['student_id'])->school_id,
                'source_type' => 'manual',
            ])
        );

        return response()->json($record);
    }
}
