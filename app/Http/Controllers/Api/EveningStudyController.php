<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\BoardingSuspension;
use App\Models\EveningStudySession;
use App\Models\EveningStudyStatus;
use App\Models\LeaveType;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\SystemSetting;
use App\Services\AttendancePeriodService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EveningStudyController extends Controller
{
    public function periods(AttendancePeriodService $periodService)
    {
        return response()->json($periodService->eveningStudy());
    }

    public function classes(Request $request, AttendancePeriodService $periodService)
    {
        $data = $request->validate([
            'date' => 'required|date',
            'period_id' => 'required|integer',
        ]);
        $period = $this->nightPeriod($periodService, (int) $data['period_id']);
        $query = SchoolClass::with('department:id,name')->where('is_graduated', false);
        $this->scopeClasses($request, $query);

        $classes = $query->orderBy('department_id')->orderBy('name')->get();
        $sessions = EveningStudySession::where('attendance_date', $data['date'])
            ->where('period_id', $period['id'])
            ->whereIn('class_id', $classes->pluck('id'))
            ->get()->keyBy('class_id');
        $recordsBySession = AttendanceRecord::withoutGlobalScope('day_attendance')
            ->with('eveningStudyStatus:id,name,color')
            ->whereIn('evening_study_session_id', $sessions->pluck('id'))
            ->get()->groupBy('evening_study_session_id');
        $statusDefinitions = EveningStudyStatus::whereIn('school_id', $classes->pluck('school_id')->unique())
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('id')->get()->groupBy('school_id');
        $boardingCounts = Student::whereIn('class_id', $classes->pluck('id'))
            ->where('is_boarding', true)
            ->selectRaw('class_id, count(*) as aggregate')
            ->groupBy('class_id')->pluck('aggregate', 'class_id');

        return response()->json($classes->map(function ($class) use ($sessions, $recordsBySession, $statusDefinitions, $boardingCounts) {
            $session = $sessions->get($class->id);
            if ($session) {
                $session->setAttribute('status_counts', $this->statusBreakdown(
                    $recordsBySession->get($session->id, collect()),
                    $statusDefinitions->get($class->school_id, collect())
                ));
            }

            return [
                'id' => $class->id,
                'name' => $class->name,
                'department' => $class->department,
                'boarding_count' => (int) ($boardingCounts[$class->id] ?? 0),
                'session' => $session,
            ];
        }));
    }

    public function summary(Request $request, AttendancePeriodService $periodService)
    {
        $data = $request->validate([
            'date' => 'required|date',
            'period_id' => 'required|integer',
        ]);
        $period = $this->nightPeriod($periodService, (int) $data['period_id']);
        $classQuery = SchoolClass::with('department:id,name')->where('is_graduated', false);
        $this->scopeClasses($request, $classQuery);
        $classes = $classQuery->orderBy('department_id')->orderBy('name')->get();

        $boardingCounts = Student::whereIn('class_id', $classes->pluck('id'))
            ->where('is_boarding', true)
            ->selectRaw('class_id, count(*) as aggregate')
            ->groupBy('class_id')->pluck('aggregate', 'class_id');
        $sessions = EveningStudySession::where('attendance_date', $data['date'])
            ->where('period_id', $period['id'])
            ->whereIn('class_id', $classes->pluck('id'))
            ->get();
        $records = AttendanceRecord::withoutGlobalScope('day_attendance')
            ->with('eveningStudyStatus:id,name,color')
            ->whereIn('evening_study_session_id', $sessions->pluck('id'))
            ->get();
        $statusDefinitions = EveningStudyStatus::whereIn('school_id', $classes->pluck('school_id')->unique())
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('id')->get();

        $buildSummary = function ($summaryClasses) use ($boardingCounts, $sessions, $records, $statusDefinitions) {
            $classIds = $summaryClasses->pluck('id');
            $summarySessions = $sessions->whereIn('class_id', $classIds);
            $sessionIds = $summarySessions->pluck('id');
            $summaryRecords = $records->whereIn('evening_study_session_id', $sessionIds);

            return [
                'class_count' => $summaryClasses->count(),
                'started_class_count' => $summarySessions->count(),
                'completed_class_count' => $summarySessions->where('status', 'completed')->count(),
                'expected_count' => (int) $classIds->sum(fn ($classId) => (int) ($boardingCounts[$classId] ?? 0)),
                'recorded_count' => $summaryRecords->count(),
                'present_count' => $summaryRecords->where('status', 'present')->count(),
                'status_counts' => $this->statusBreakdown($summaryRecords, $statusDefinitions),
            ];
        };

        $departmentSummaries = $classes->groupBy(fn ($class) => $class->department_id ?: 0)
            ->map(function ($departmentClasses, $departmentId) use ($buildSummary) {
                return [
                    'department_id' => $departmentId ?: null,
                    'department_name' => $departmentClasses->first()?->department?->name ?? '未分配系部',
                    ...$buildSummary($departmentClasses),
                ];
            })->values();

        return response()->json([
            'date' => $data['date'],
            'period' => $period,
            'scope_type' => in_array($request->user()->role, ['system_admin', 'school_admin'], true) ? 'school' : 'department',
            'overall' => $buildSummary($classes),
            'departments' => $departmentSummaries,
        ]);
    }

    public function start(Request $request, AttendancePeriodService $periodService)
    {
        $data = $request->validate([
            'date' => 'required|date',
            'period_id' => 'required|integer',
            'class_id' => 'required|exists:classes,id',
        ]);
        $period = $this->nightPeriod($periodService, (int) $data['period_id']);
        $class = SchoolClass::with('department')->findOrFail($data['class_id']);
        $this->authorizeClass($request, $class, true);

        $session = DB::transaction(function () use ($data, $period, $class, $request) {
            $session = EveningStudySession::where('attendance_date', $data['date'])
                ->where('class_id', $class->id)
                ->where('period_id', $period['id'])
                ->lockForUpdate()->first();

            if (! $session) {
                $session = EveningStudySession::create([
                    'school_id' => $class->school_id,
                    'department_id' => $class->department_id,
                    'class_id' => $class->id,
                    'period_id' => $period['id'],
                    'period_name_snapshot' => $period['name'],
                    'attendance_date' => $data['date'],
                    'created_by' => $request->user()->id,
                    'status' => 'in_progress',
                ]);
            }

            $students = Student::with('user:id,name,email')
                ->where('class_id', $class->id)->where('is_boarding', true)->orderBy('student_no')->get();
            $statuses = EveningStudyStatus::where('school_id', $class->school_id)->where('is_active', true)->get();
            $default = $statuses->firstWhere('is_default', true) ?? $statuses->firstWhere('base_status', 'present');
            abort_unless($default, 422, '请先配置夜自习默认状态');
            $suspensionStatus = $this->suspensionStatus($class->school_id, $statuses);
            abort_unless($suspensionStatus, 422, '请先配置暂停住宿对应状态');

            $suspensions = BoardingSuspension::activeOn($data['date'])
                ->whereIn('student_id', $students->pluck('id'))->get()->keyBy('student_id');
            $existing = AttendanceRecord::withoutGlobalScope('day_attendance')
                ->whereIn('student_id', $students->pluck('id'))
                ->whereDate('date', $data['date'])
                ->where('period_id', $period['id'])
                ->lockForUpdate()->get()->keyBy('student_id');

            foreach ($students as $student) {
                $record = $existing->get($student->id);
                $suspension = $suspensions->get($student->id);
                $finalStatus = $default;
                $suspensionId = null;

                if ($record?->evening_study_status_id) {
                    $finalStatus = $statuses->firstWhere('id', $record->evening_study_status_id) ?? $default;
                } elseif ($record?->approval_status === 'approved' && $record->requested_evening_status_id) {
                    $finalStatus = $statuses->firstWhere('id', $record->requested_evening_status_id) ?? $default;
                }
                if ($suspension && ! $record?->manually_overridden_at) {
                    $finalStatus = $suspensionStatus;
                    $suspensionId = $suspension->id;
                }

                $values = [
                    'student_id' => $student->id,
                    'school_id' => $class->school_id,
                    'class_id' => $class->id,
                    'date' => $data['date'],
                    'period_id' => $period['id'],
                    'scene' => 'evening_study',
                    'counts_in_day_stats' => $period['counts_in_day_stats'],
                    'period_name_snapshot' => $period['name'],
                    'status' => $finalStatus->base_status,
                    'evening_study_status_id' => $finalStatus->id,
                    'status_name_snapshot' => $finalStatus->name,
                    'evening_study_session_id' => $session->id,
                    'boarding_suspension_id' => $suspensionId,
                    'source_type' => $record?->source_type ?? 'evening_study',
                ];
                $record ? $record->update($values) : AttendanceRecord::create($values);
            }

            $session->update(['total_students' => $students->count()]);

            return $session;
        });

        return $this->sessionResponse($session->fresh());
    }

    public function show(Request $request, EveningStudySession $eveningStudySession)
    {
        $this->authorizeClass($request, $eveningStudySession->schoolClass, false);

        return $this->sessionResponse($eveningStudySession);
    }

    public function updateRecords(Request $request, EveningStudySession $eveningStudySession)
    {
        $this->authorizeModifyClass($request, $eveningStudySession->schoolClass);
        abort_if($eveningStudySession->status === 'completed', 422, '已完成的点名请先重新打开');

        $data = $request->validate([
            'records' => 'required|array|min:1',
            'records.*.id' => 'required|integer',
            'records.*.status_id' => [
                'required',
                Rule::exists('evening_study_statuses', 'id')->where('school_id', $eveningStudySession->school_id),
            ],
            'records.*.destination' => 'nullable|string|max:500',
            'records.*.note' => 'nullable|string|max:1000',
        ]);
        DB::transaction(fn () => $this->applyRecordUpdates($eveningStudySession, $data['records']));

        return $this->sessionResponse($eveningStudySession->fresh());
    }

    public function markLeave(Request $request, AttendancePeriodService $periodService)
    {
        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
            'date' => 'required|date',
            'period_id' => 'required|integer',
            'leave_type_id' => 'required|exists:leave_types,id',
            'status_id' => 'required|exists:evening_study_statuses,id',
            'destination' => 'required|string|max:500',
            'reason' => 'nullable|string|max:1000',
        ]);
        $period = $this->nightPeriod($periodService, (int) $data['period_id']);
        $student = Student::with('schoolClass')->findOrFail($data['student_id']);
        abort_unless($student->is_boarding, 422, '夜自习请假仅适用于住宿生');
        $this->authorizeTeacherMark($request, $student->schoolClass);
        abort_if($student->activeBoardingSuspension($data['date']), 422, '该学生住宿许可已暂停');
        abort_unless(LeaveType::whereKey($data['leave_type_id'])
            ->where('school_id', $student->school_id)
            ->where('is_active', true)
            ->exists(), 422, '请选择可用的考勤类型');

        $status = EveningStudyStatus::whereKey($data['status_id'])
            ->where('school_id', $student->school_id)
            ->where('is_active', true)
            ->where('student_requestable', true)
            ->firstOrFail();
        abort_if($status->is_default, 422, '请选择请假或外出状态');

        $record = DB::transaction(function () use ($data, $period, $student, $status) {
            $record = AttendanceRecord::withoutGlobalScope('day_attendance')
                ->where('student_id', $student->id)->whereDate('date', $data['date'])
                ->where('period_id', $period['id'])->lockForUpdate()->first();
            abort_if($record?->manually_overridden_at, 422, '值班老师已人工确认该学生状态');
            abort_if($record?->eveningStudySession?->status === 'completed', 422, '该场点名已经完成');

            $values = [
                'student_id' => $student->id,
                'school_id' => $student->school_id,
                'class_id' => $student->class_id,
                'date' => $data['date'],
                'period_id' => $period['id'],
                'scene' => 'evening_study',
                'counts_in_day_stats' => $period['counts_in_day_stats'],
                'period_name_snapshot' => $period['name'],
                'status' => $status->base_status,
                'leave_type_id' => $data['leave_type_id'],
                'evening_study_status_id' => $status->id,
                'status_name_snapshot' => $status->name,
                'destination' => $data['destination'],
                'reason' => $data['reason'] ?? null,
                'source_type' => 'teacher_evening_leave',
                'is_self_applied' => false,
                'approval_status' => null,
            ];
            $record ? $record->update($values) : $record = AttendanceRecord::withoutGlobalScope('day_attendance')->create($values);

            return $record;
        });

        return response()->json($record->load(['student.user', 'eveningStudyStatus']));
    }

    public function complete(Request $request, EveningStudySession $eveningStudySession)
    {
        $this->authorizeModifyClass($request, $eveningStudySession->schoolClass);
        abort_unless($eveningStudySession->status === 'in_progress', 422, '该场点名已经完成');
        $data = $request->validate([
            'notes' => 'nullable|string|max:2000',
            'records' => 'nullable|array',
            'records.*.id' => 'required_with:records|integer',
            'records.*.status_id' => [
                'required_with:records',
                Rule::exists('evening_study_statuses', 'id')->where('school_id', $eveningStudySession->school_id),
            ],
            'records.*.destination' => 'nullable|string|max:500',
            'records.*.note' => 'nullable|string|max:1000',
        ]);

        DB::transaction(function () use ($eveningStudySession, $data) {
            if (! empty($data['records'])) {
                $this->applyRecordUpdates($eveningStudySession, $data['records']);
            }
            $records = AttendanceRecord::withoutGlobalScope('day_attendance')
                ->where('evening_study_session_id', $eveningStudySession->id)->lockForUpdate()->get();
            $normalCount = $records->where('status', 'present')->count();
            $eveningStudySession->update([
                'status' => 'completed',
                'total_students' => $records->count(),
                'normal_count' => $normalCount,
                'exception_count' => $records->count() - $normalCount,
                'notes' => $data['notes'] ?? null,
                'completed_at' => now(),
            ]);
        });

        return $this->sessionResponse($eveningStudySession->fresh());
    }

    public function reopen(Request $request, EveningStudySession $eveningStudySession)
    {
        $this->authorizeModifyClass($request, $eveningStudySession->schoolClass);
        abort_unless($eveningStudySession->status === 'completed', 422, '该场点名尚未完成');
        $eveningStudySession->update(['status' => 'in_progress', 'completed_at' => null]);

        return $this->sessionResponse($eveningStudySession->fresh());
    }

    public function destroy(Request $request, EveningStudySession $eveningStudySession)
    {
        $this->authorizeModifyClass($request, $eveningStudySession->schoolClass);

        $result = DB::transaction(function () use ($eveningStudySession) {
            $records = AttendanceRecord::withoutGlobalScope('day_attendance')
                ->where('evening_study_session_id', $eveningStudySession->id)
                ->lockForUpdate()
                ->get();
            $statuses = EveningStudyStatus::where('school_id', $eveningStudySession->school_id)
                ->get()->keyBy('id');
            $defaultStatus = $statuses->firstWhere('is_default', true)
                ?? $statuses->firstWhere('base_status', 'present');
            $retainedCount = 0;

            foreach ($records as $record) {
                $shouldRetain = $record->is_self_applied
                    || $record->leave_type_id
                    || $record->requested_evening_status_id
                    || $record->approval_status !== null;

                if (! $shouldRetain) {
                    $record->delete();

                    continue;
                }

                $values = [
                    'evening_study_session_id' => null,
                    'manually_overridden_at' => null,
                    'source_type' => $record->is_self_applied ? 'self_applied' : 'teacher_evening_leave',
                ];
                if ($record->requested_evening_status_id) {
                    $restoredStatus = $record->approval_status === 'approved'
                        ? $statuses->get($record->requested_evening_status_id)
                        : $defaultStatus;
                    if ($restoredStatus) {
                        $values = [
                            ...$values,
                            'status' => $restoredStatus->base_status,
                            'evening_study_status_id' => $restoredStatus->id,
                            'status_name_snapshot' => $restoredStatus->name,
                        ];
                    }
                }
                $record->update($values);
                $retainedCount++;
            }

            $deletedCount = $records->count() - $retainedCount;
            $eveningStudySession->delete();

            return compact('deletedCount', 'retainedCount');
        });

        return response()->json([
            'message' => '点名记录已删除',
            'deleted_record_count' => $result['deletedCount'],
            'retained_leave_count' => $result['retainedCount'],
        ]);
    }

    public function history(Request $request)
    {
        $data = $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'class_id' => 'nullable|exists:classes,id',
        ]);
        $classQuery = SchoolClass::query();
        $this->scopeClasses($request, $classQuery);
        $query = EveningStudySession::with(['schoolClass:id,name', 'department:id,name', 'creator:id,name'])
            ->whereIn('class_id', $classQuery->select('id'));
        if (! empty($data['date_from'])) {
            $query->whereDate('attendance_date', '>=', $data['date_from']);
        }
        if (! empty($data['date_to'])) {
            $query->whereDate('attendance_date', '<=', $data['date_to']);
        }
        if (! empty($data['class_id'])) {
            $query->where('class_id', $data['class_id']);
        }

        $paginator = $query->latest('attendance_date')->latest('completed_at')->latest('id')->paginate(30);
        $sessions = $paginator->getCollection();
        $recordsBySession = AttendanceRecord::withoutGlobalScope('day_attendance')
            ->with('eveningStudyStatus:id,name,color')
            ->whereIn('evening_study_session_id', $sessions->pluck('id'))
            ->get()->groupBy('evening_study_session_id');
        $statusDefinitions = EveningStudyStatus::whereIn('school_id', $sessions->pluck('school_id')->unique())
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('id')->get()->groupBy('school_id');

        $paginator->setCollection($sessions->map(function ($session) use ($recordsBySession, $statusDefinitions) {
            $session->setAttribute('status_counts', $this->statusBreakdown(
                $recordsBySession->get($session->id, collect()),
                $statusDefinitions->get($session->school_id, collect())
            ));
            $session->setAttribute('recorded_at', $session->completed_at ?? $session->updated_at ?? $session->created_at);

            return $session;
        }));

        return response()->json($paginator);
    }

    private function sessionResponse(EveningStudySession $session)
    {
        $session->load(['schoolClass.department:id,name', 'creator:id,name']);
        $records = AttendanceRecord::withoutGlobalScope('day_attendance')
            ->with([
                'student.user:id,name,email',
                'requestedEveningStatus:id,name,color',
                'eveningStudyStatus:id,name,color,base_status',
                'leaveType:id,name,slug',
                'boardingSuspension.creator:id,name',
            ])
            ->where('evening_study_session_id', $session->id)
            ->get()->sortBy(fn ($record) => $record->student?->student_no)->values();
        $statusDefinitions = EveningStudyStatus::where('school_id', $session->school_id)
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('id')->get();
        $session->setAttribute('status_counts', $this->statusBreakdown($records, $statusDefinitions));

        return response()->json(['session' => $session, 'records' => $records]);
    }

    private function applyRecordUpdates(EveningStudySession $session, array $recordItems): void
    {
        $statuses = EveningStudyStatus::where('school_id', $session->school_id)
            ->where('is_active', true)->get()->keyBy('id');

        foreach ($recordItems as $item) {
            $record = AttendanceRecord::withoutGlobalScope('day_attendance')
                ->where('evening_study_session_id', $session->id)
                ->lockForUpdate()->findOrFail($item['id']);
            $status = $statuses->get((int) $item['status_id']);
            abort_unless($status, 422, '状态已停用，请刷新后重试');
            $record->update([
                'status' => $status->base_status,
                'evening_study_status_id' => $status->id,
                'status_name_snapshot' => $status->name,
                'destination' => $item['destination'] ?? null,
                'note' => $item['note'] ?? null,
                'source_type' => 'evening_study_manual',
                'manually_overridden_at' => now(),
            ]);
        }
    }

    private function statusBreakdown($records, $statusDefinitions): array
    {
        $counts = collect($records)->countBy(function ($record) {
            return $record->status_name_snapshot
                ?: $record->eveningStudyStatus?->name
                ?: '未设置';
        });
        $breakdown = collect($statusDefinitions)->unique('name')->map(function ($status) use ($counts) {
            return [
                'name' => $status->name,
                'color' => $status->color,
                'count' => (int) $counts->get($status->name, 0),
            ];
        });
        $knownNames = $breakdown->pluck('name');
        $unknown = $counts->reject(fn ($count, $name) => $knownNames->contains($name))
            ->map(fn ($count, $name) => ['name' => $name, 'color' => 'gray', 'count' => (int) $count])
            ->values();

        return $breakdown->concat($unknown)->values()->all();
    }

    private function nightPeriod(AttendancePeriodService $periodService, int $periodId): array
    {
        $period = $periodService->find($periodId);
        abort_unless($period && $period['is_active'] && $period['scene'] === 'evening_study', 422, '请选择启用的夜自习节次');
        abort_unless($period['audience_scope'] === 'boarding', 422, '夜自习节次必须仅对住宿生开放');

        return $period;
    }

    private function suspensionStatus(int $schoolId, $statuses): ?EveningStudyStatus
    {
        $configuredId = (int) SystemSetting::get('boarding_suspension_status_id', 0);

        return $statuses->first(fn ($status) => $status->id === $configuredId && $status->school_id === $schoolId)
            ?? $statuses->firstWhere('name', '暂停住宿');
    }

    private function authorizeClass(Request $request, SchoolClass $class, bool $take): void
    {
        $query = SchoolClass::whereKey($class->id);
        $this->scopeClasses($request, $query, $take);
        abort_unless($query->exists(), 403, '无权访问该班级');
    }

    private function authorizeTeacherMark(Request $request, SchoolClass $class): void
    {
        $user = $request->user();
        if (in_array($user->role, ['system_admin', 'school_admin'], true)) {
            return;
        }
        if ($user->role === 'teacher' && $class->teacher_id === $user->id) {
            return;
        }
        if (in_array($user->role, ['department_manager', 'manager'], true)
            && $user->managedDepartments()->whereKey($class->department_id)->exists()) {
            return;
        }
        abort(403, '无权标记该学生的夜自习请假');
    }

    private function authorizeModifyClass(Request $request, SchoolClass $class): void
    {
        $user = $request->user();
        if (in_array($user->role, ['system_admin', 'school_admin'], true)) {
            return;
        }
        if ($user->role === 'duty_teacher'
            && $user->dutyDepartments()->whereKey($class->department_id)->exists()) {
            return;
        }
        if (in_array($user->role, ['department_manager', 'manager'], true)
            && $user->managedDepartments()->whereKey($class->department_id)->exists()) {
            return;
        }
        abort(403, '无权修改该班级夜自习记录');
    }

    private function scopeClasses(Request $request, $query, bool $take = false): void
    {
        $user = $request->user();
        if (in_array($user->role, ['system_admin', 'school_admin'], true)) {
            return;
        }
        if ($user->role === 'duty_teacher') {
            $query->whereIn('department_id', $user->dutyDepartments()->select('departments.id'));

            return;
        }
        if ($take) {
            abort(403, '只有值班老师或系统管理员可以执行夜自习点名');
        }
        if ($user->role === 'teacher') {
            $query->where('teacher_id', $user->id);

            return;
        }
        if (in_array($user->role, ['department_manager', 'manager'], true)) {
            $query->whereIn('department_id', $user->managedDepartments()->select('departments.id'));

            return;
        }
        abort(403, '无权访问夜自习点名');
    }
}
