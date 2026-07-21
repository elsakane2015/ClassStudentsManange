<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\BoardingSuspension;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BoardingSuspensionController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
            'include_revoked' => 'nullable|boolean',
        ]);
        $student = Student::with('schoolClass')->findOrFail($data['student_id']);
        $this->authorizeStudent($request, $student, false);

        $query = $student->boardingSuspensions()->with(['creator:id,name', 'revoker:id,name'])->latest('start_date');
        if (!$request->boolean('include_revoked')) {
            $query->whereNull('revoked_at');
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'reason' => 'required|string|max:1000',
            'destination' => 'nullable|string|max:500',
        ]);
        $student = Student::with('schoolClass')->findOrFail($data['student_id']);
        $this->authorizeStudent($request, $student, true);

        if (!$student->is_boarding) {
            return response()->json(['message' => '只有住宿生可以暂停住宿许可'], 422);
        }

        $suspension = DB::transaction(function () use ($data, $student, $request) {
            $overlap = BoardingSuspension::where('student_id', $student->id)
                ->whereNull('revoked_at')
                ->whereDate('start_date', '<=', $data['end_date'])
                ->whereDate('end_date', '>=', $data['start_date'])
                ->lockForUpdate()
                ->exists();
            abort_if($overlap, 422, '该时间段已存在暂停住宿许可');

            $suspension = BoardingSuspension::create([
                ...$data,
                'school_id' => $student->school_id,
                'class_id' => $student->class_id,
                'created_by' => $request->user()->id,
            ]);

            AttendanceRecord::withoutGlobalScope('day_attendance')
                ->where('student_id', $student->id)
                ->where('scene', 'evening_study')
                ->whereBetween('date', [$data['start_date'], $data['end_date']])
                ->where('is_self_applied', true)
                ->where('approval_status', 'pending')
                ->update([
                    'approval_status' => 'rejected',
                    'approver_id' => $request->user()->id,
                    'approved_at' => now(),
                    'rejection_reason' => '住宿许可已暂停',
                ]);

            return $suspension;
        });

        return response()->json($suspension->load('creator:id,name'), 201);
    }

    public function revoke(Request $request, BoardingSuspension $boardingSuspension)
    {
        $student = $boardingSuspension->student()->with('schoolClass')->firstOrFail();
        $this->authorizeStudent($request, $student, true);
        abort_if($boardingSuspension->revoked_at, 422, '该记录已经撤销');

        $data = $request->validate(['reason' => 'required|string|max:1000']);
        $boardingSuspension->update([
            'revoked_at' => now(),
            'revoked_by' => $request->user()->id,
            'revoke_reason' => $data['reason'],
        ]);

        return response()->json(['message' => '暂停住宿许可已撤销']);
    }

    private function authorizeStudent(Request $request, Student $student, bool $write): void
    {
        $user = $request->user();
        if (in_array($user->role, ['system_admin', 'school_admin'], true)) {
            return;
        }
        if ($user->role === 'teacher' && $user->teacherClasses()->whereKey($student->class_id)->exists()) {
            return;
        }
        if (in_array($user->role, ['department_manager', 'manager'], true)
            && $user->managedDepartments()->whereKey($student->schoolClass?->department_id)->exists()) {
            return;
        }
        if (!$write && $user->role === 'duty_teacher'
            && $user->dutyDepartments()->whereKey($student->schoolClass?->department_id)->exists()) {
            return;
        }

        abort(403, '无权管理该学生的住宿许可');
    }
}
