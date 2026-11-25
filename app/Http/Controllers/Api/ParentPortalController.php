<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\LeaveType;
use App\Models\Semester;
use App\Models\Student;
use App\Models\SystemSetting;
use App\Models\TimeSlot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class ParentPortalController extends Controller
{
    /**
     * List all children linked to the currently authenticated parent (or staff-parent) user.
     */
    public function children(Request $request)
    {
        $user = $request->user();
        $children = $this->loadAuthorizedChildren($user);

        $today = now()->toDateString();
        $childIds = $children->pluck('id')->all();

        $todayAnomaliesByStudent = collect();
        $pendingCountsByStudent = collect();
        $latestRecordByStudent = collect();

        if (! empty($childIds)) {
            $todayAnomaliesByStudent = AttendanceRecord::withoutGlobalScope('day_attendance')
                ->with(['leaveType:id,name,slug,color', 'eveningStudyStatus:id,name,color', 'requestedEveningStatus:id,name,color'])
                ->whereIn('student_id', $childIds)
                ->whereDate('date', $today)
                ->get()
                ->filter(fn (AttendanceRecord $rec) => AttendanceController::shouldIncludeInCalendarSummary($rec))
                ->groupBy('student_id');

            $pendingCountsByStudent = AttendanceRecord::withoutGlobalScope('day_attendance')
                ->whereIn('student_id', $childIds)
                ->where('is_self_applied', true)
                ->where('approval_status', 'pending')
                ->get()
                ->groupBy('student_id')
                ->map(fn (Collection $items) => $items->groupBy(fn ($r) => $r->leave_batch_id ?: ($r->date?->format('Y-m-d').'_'.$r->leave_type_id))->count());

            $latestRecordByStudent = AttendanceRecord::withoutGlobalScope('day_attendance')
                ->with(['leaveType:id,name,slug,color', 'eveningStudyStatus:id,name,color', 'requestedEveningStatus:id,name,color'])
                ->whereIn('student_id', $childIds)
                ->orderByDesc('created_at')
                ->limit(100)
                ->get()
                ->filter(fn (AttendanceRecord $rec) => AttendanceController::shouldIncludeInCalendarSummary($rec))
                ->groupBy('student_id')
                ->map(fn (Collection $items) => $items->first());
        }

        $payload = $children->map(function (Student $student) use ($todayAnomaliesByStudent, $pendingCountsByStudent, $latestRecordByStudent) {
            $todayItems = $todayAnomaliesByStudent->get($student->id, collect());
            $latest = $latestRecordByStudent->get($student->id);

            return [
                ...self::formatChildSummary($student),
                'today_anomaly_count' => $todayItems->count(),
                'pending_leave_count' => (int) $pendingCountsByStudent->get($student->id, 0),
                'latest_event' => $latest ? $this->formatRecordItem($latest) : null,
            ];
        })->values();

        return response()->json([
            'data' => $payload,
        ]);
    }

    /**
     * Aggregated overview for one linked child: profile + stats + active leave types/periods/time slots.
     */
    public function overview(Request $request, Student $student)
    {
        $child = $this->authorizeChild($request->user(), $student);
        $scope = (string) $request->input('scope', 'month');
        if (! in_array($scope, ['today', 'week', 'month', 'semester'], true)) {
            $scope = 'month';
        }

        $stats = $this->buildChildStats($child, $scope);

        $leaveTypes = LeaveType::where('school_id', $child->school_id)
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->filter(function (LeaveType $lt) use ($child) {
                if (! $lt->gender_restriction || $lt->gender_restriction === 'all') {
                    return true;
                }

                return $lt->gender_restriction === $child->gender;
            })
            ->values();

        $periods = json_decode((string) SystemSetting::get('attendance_periods', '[]'), true) ?: [];
        $timeSlots = TimeSlot::where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();

        return response()->json([
            'child' => self::formatChildSummary($child),
            'scope' => $scope,
            'stats' => $stats,
            'leave_types' => $leaveTypes,
            'periods' => $periods,
            'time_slots' => $timeSlots,
        ]);
    }

    /**
     * Monthly/range calendar records for one linked child (includes regular + evening study anomalies).
     */
    public function calendar(Request $request, Student $student)
    {
        $child = $this->authorizeChild($request->user(), $student);

        $start = $request->input('start', now()->startOfMonth()->toDateString());
        $end = $request->input('end', now()->endOfMonth()->toDateString());

        $periodMap = collect(json_decode((string) SystemSetting::get('attendance_periods', '[]'), true) ?: [])
            ->keyBy(fn ($period) => (int) ($period['id'] ?? 0));

        $records = AttendanceRecord::withoutGlobalScope('day_attendance')
            ->where('student_id', $child->id)
            ->whereBetween('date', [$start, $end])
            ->with([
                'leaveType:id,name,slug,color,input_config',
                'requestedEveningStatus:id,name,color',
                'eveningStudyStatus:id,name,color,base_status',
                'approver:id,name',
            ])
            ->orderBy('date')
            ->orderBy('period_id')
            ->get()
            ->filter(fn (AttendanceRecord $rec) => AttendanceController::shouldIncludeInCalendarSummary($rec))
            ->values();

        // Group same day + leave_batch_id so multi-period leaves render cleanly as 1 item per day.
        $grouped = [];
        foreach ($records as $record) {
            $dateStr = $record->date instanceof Carbon ? $record->date->format('Y-m-d') : (string) $record->date;
            $details = is_array($record->details) ? $record->details : (json_decode((string) $record->details, true) ?: []);
            $timeSlotId = $details['time_slot_id'] ?? null;

            if ($record->leave_batch_id) {
                $key = $dateStr.'_batch_'.$record->leave_batch_id;
            } elseif ($record->source_type === 'roll_call' && $record->source_id) {
                $key = $dateStr.'_rc_'.$record->source_id.'_'.($record->leave_type_id ?? 0);
            } elseif ($timeSlotId) {
                $key = $dateStr.'_ts_'.$timeSlotId.'_'.($record->leave_type_id ?? 0).'_'.($record->approval_status ?? '');
            } else {
                $key = 'id_'.$record->id;
            }

            $grouped[$key][] = $record;
        }

        $attendance = collect($grouped)->map(function (array $group) use ($periodMap) {
            $items = collect($group);
            $primary = $items->firstWhere('scene', '!=', 'evening_study') ?? $items->first();
            $regularItems = $items->where('scene', '!=', 'evening_study');
            $eveningItem = $items->firstWhere('scene', 'evening_study');

            $periodNames = $regularItems
                ->pluck('period_id')
                ->filter(fn ($id) => $id !== null)
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->sort()
                ->map(fn ($id) => $periodMap->get($id)['name'] ?? "第{$id}节")
                ->values()
                ->all();

            $regularLabel = ! empty($periodNames)
                ? implode('、', $periodNames)
                : $this->resolveDetailLabel($primary, $periodMap);

            $eveningLabel = $eveningItem ? $this->resolveEveningLabel($eveningItem) : null;
            $combined = implode('；', array_values(array_filter([$regularLabel, $eveningLabel])));

            $formatted = $this->formatRecordItem($primary, $periodMap);
            $formatted['detail_label'] = $combined ?: $formatted['detail_label'];
            $formatted['regular_detail_label'] = $regularLabel ?: null;
            $formatted['evening_study_label'] = $eveningLabel;
            $formatted['has_evening_study'] = $eveningItem !== null;
            $formatted['evening_destination'] = $eveningItem?->destination;
            $formatted['record_ids'] = $items->pluck('id')->all();

            return $formatted;
        })->values();

        return response()->json([
            'attendance' => $attendance,
        ]);
    }

    /**
     * Leave request history (self-applied) for one linked child, grouped by leave_batch_id.
     */
    public function leaveRequests(Request $request, Student $student)
    {
        $child = $this->authorizeChild($request->user(), $student);

        $query = AttendanceRecord::withoutGlobalScope('day_attendance')
            ->with([
                'leaveType:id,name,slug,color',
                'approver:id,name',
                'requestedEveningStatus:id,name,color',
                'eveningStudyStatus:id,name,color',
            ])
            ->where('student_id', $child->id)
            ->where('is_self_applied', true);

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('approval_status', $request->status);
        }

        $records = $query->orderByDesc('created_at')->limit(400)->get();
        $periodMap = collect(json_decode((string) SystemSetting::get('attendance_periods', '[]'), true) ?: [])
            ->keyBy(fn ($period) => (int) ($period['id'] ?? 0));

        $grouped = $records->groupBy(function (AttendanceRecord $record) {
            if ($record->leave_batch_id) {
                return 'batch_'.$record->leave_batch_id.'_'.($record->approval_status ?? 'approved');
            }
            $dateStr = $record->date instanceof Carbon ? $record->date->format('Y-m-d') : (string) $record->date;

            return $record->student_id.'_'.$dateStr.'_'.($record->leave_type_id ?? 0).'_'.($record->approval_status ?? '');
        })->map(function (Collection $group) use ($periodMap) {
            $first = $group->first();
            $primary = $group->firstWhere('scene', '!=', 'evening_study') ?? $first;
            $eveningRecord = $group->firstWhere('scene', 'evening_study');

            $dates = $group->map(fn ($r) => $r->date instanceof Carbon ? $r->date->copy() : Carbon::parse($r->date))->sort();
            $startDate = $dates->first();
            $endDate = $dates->last();
            $totalDays = $startDate->diffInDays($endDate) + 1;

            $dateRangeText = $startDate->format('Y年m月d日');
            if ($startDate->format('Y-m-d') !== $endDate->format('Y-m-d')) {
                $dateRangeText .= $startDate->format('Y-m') === $endDate->format('Y-m')
                    ? '-'.$endDate->format('d日')
                    : '-'.$endDate->format('m月d日');
            }

            $periodNames = $group->where('scene', '!=', 'evening_study')
                ->pluck('period_id')
                ->filter(fn ($id) => $id !== null)
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->sort()
                ->map(fn ($id) => $periodMap->get($id)['name'] ?? "第{$id}节")
                ->values()
                ->all();

            $periodLabel = ! empty($periodNames)
                ? implode('、', $periodNames)
                : $this->resolveDetailLabel($primary, $periodMap);

            $eveningLabel = $eveningRecord ? $this->resolveEveningLabel($eveningRecord) : null;

            $reason = $group->map(function ($r) {
                $details = is_array($r->details) ? $r->details : (json_decode((string) $r->details, true) ?: []);

                return trim((string) ($r->reason ?: ($details['text'] ?? '') ?: ($r->note ?? '')));
            })->first(fn ($v) => $v !== '') ?: '';

            return [
                'id' => $primary->id,
                'leave_batch_id' => $primary->leave_batch_id,
                'student_id' => $primary->student_id,
                'leave_type' => $primary->leaveType,
                'type_name' => $primary->leaveType?->name ?? '请假',
                'color' => $primary->leaveType?->color ?? 'blue',
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
                'date_range_text' => $dateRangeText,
                'total_days' => $totalDays,
                'period_label' => $periodLabel ?: null,
                'evening_study_label' => $eveningLabel,
                'has_evening_study' => $eveningRecord !== null,
                'destination' => $eveningRecord?->destination,
                'reason' => $reason,
                'images' => $primary->images ?? [],
                'status' => $primary->approval_status ?? 'approved',
                'approval_status' => $primary->approval_status,
                'approver_name' => $primary->approver?->name,
                'approved_at' => $primary->approved_at,
                'rejection_reason' => $primary->rejection_reason,
                'created_at' => $primary->created_at,
            ];
        })->values();

        $page = max(1, (int) $request->input('page', 1));
        $perPage = max(1, min(100, (int) $request->input('per_page', 20)));
        $paginated = LeaveRequestController::paginateGroupedRequests($grouped, $page, $perPage);

        return response()->json($paginated);
    }

    /**
     * Attendance & anomaly records list for one linked child (marked by teachers/roll-calls/evening study + leaves).
     */
    public function records(Request $request, Student $student)
    {
        $child = $this->authorizeChild($request->user(), $student);
        $scope = (string) $request->input('scope', 'month');
        $statusFilter = trim((string) $request->input('status', ''));

        [$startDate, $endDate] = $this->resolveDateRange($scope, $request->input('start_date'), $request->input('end_date'));
        $periodMap = collect(json_decode((string) SystemSetting::get('attendance_periods', '[]'), true) ?: [])
            ->keyBy(fn ($period) => (int) ($period['id'] ?? 0));

        $records = AttendanceRecord::withoutGlobalScope('day_attendance')
            ->where('student_id', $child->id)
            ->whereBetween('date', [$startDate, $endDate])
            ->with([
                'leaveType:id,name,slug,color,input_config',
                'requestedEveningStatus:id,name,color',
                'eveningStudyStatus:id,name,color,base_status',
                'approver:id,name',
            ])
            ->orderByDesc('date')
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn (AttendanceRecord $rec) => AttendanceController::shouldIncludeInCalendarSummary($rec));

        if ($statusFilter !== '' && $statusFilter !== 'all' && $statusFilter !== 'present') {
            $records = $records->filter(function (AttendanceRecord $rec) use ($statusFilter) {
                if ($rec->status === $statusFilter) {
                    return true;
                }
                if ($rec->leaveType?->slug === $statusFilter) {
                    return true;
                }
                $details = is_array($rec->details) ? $rec->details : [];

                return ($details['original_status'] ?? null) === $statusFilter;
            });
        }

        $items = $records->map(fn (AttendanceRecord $rec) => $this->formatRecordItem($rec, $periodMap))->values();

        return response()->json([
            'data' => $items,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
    }

    public static function formatChildSummary(Student $student): array
    {
        $student->loadMissing(['user:id,name,email', 'schoolClass.department:id,name', 'schoolClass.teacher:id,name']);

        return [
            'id' => $student->id,
            'name' => $student->user?->name ?? '学生',
            'student_no' => $student->student_no,
            'gender' => $student->gender ?? 'male',
            'is_boarding' => (bool) $student->is_boarding,
            'class_id' => $student->class_id,
            'class_name' => $student->schoolClass?->name ?? '-',
            'department_name' => $student->schoolClass?->department?->name ?? '-',
            'teacher_name' => $student->schoolClass?->teacher?->name ?? '-',
            'is_graduated' => (bool) ($student->schoolClass?->is_graduated ?? false),
        ];
    }

    private function loadAuthorizedChildren(User $user): Collection
    {
        return $user->children()
            ->with(['user:id,name,email', 'schoolClass.department:id,name', 'schoolClass.teacher:id,name'])
            ->orderBy('students.id')
            ->get();
    }

    private function authorizeChild(User $user, Student $student): Student
    {
        $linked = $user->children()->where('students.id', $student->id)->exists();
        abort_unless($linked, 403, '无权查看该学生的信息');

        return $student->loadMissing(['user:id,name,email', 'schoolClass.department:id,name', 'schoolClass.teacher:id,name']);
    }

    private function resolveDateRange(string $scope, ?string $customStart = null, ?string $customEnd = null): array
    {
        $today = now();
        if ($scope === 'custom' && $customStart && $customEnd) {
            return [$customStart, $customEnd];
        }
        if ($scope === 'today') {
            return [$today->toDateString(), $today->toDateString()];
        }
        if ($scope === 'week') {
            return [$today->copy()->startOfWeek(Carbon::MONDAY)->toDateString(), $today->toDateString()];
        }
        if ($scope === 'semester') {
            $semester = Semester::where('is_current', true)->first();
            if ($semester?->start_date) {
                $start = Carbon::parse($semester->start_date);
                $end = $start->copy()->addWeeks($semester->total_weeks ?? 20)->subDay();

                return [$start->toDateString(), min($today, $end)->toDateString()];
            }
        }

        return [$today->copy()->startOfMonth()->toDateString(), $today->toDateString()];
    }

    /**
     * Cross-DB (MySQL & SQLite) compatible student attendance stats computation.
     */
    private function buildChildStats(Student $student, string $scope): array
    {
        $semester = Semester::where('is_current', true)->first();
        $holidays = [];
        if ($semester?->holidays) {
            $holidays = is_string($semester->holidays)
                ? (json_decode($semester->holidays, true) ?: [])
                : (is_array($semester->holidays) ? $semester->holidays : []);
        }

        $countWorkingDays = function (Carbon $start, Carbon $end) use ($holidays): int {
            $count = 0;
            $cursor = $start->copy();
            $useHolidayList = ! empty($holidays);
            $guard = 0;
            while ($cursor->lte($end) && $guard < 400) {
                if ($useHolidayList) {
                    if (! in_array($cursor->toDateString(), $holidays, true)) {
                        $count++;
                    }
                } elseif (! in_array($cursor->dayOfWeek, [Carbon::SUNDAY, Carbon::SATURDAY], true)) {
                    $count++;
                }
                $cursor->addDay();
                $guard++;
            }

            return $count;
        };

        $today = now()->endOfDay();
        $startDate = now()->startOfDay();
        $endDate = now()->endOfDay();
        $denominatorWorkingDays = 1;

        if ($scope === 'week') {
            $startDate = now()->startOfWeek(Carbon::MONDAY);
            $denominatorWorkingDays = $countWorkingDays($startDate, $endDate);
        } elseif ($scope === 'month') {
            $startDate = now()->startOfMonth();
            $denominatorWorkingDays = $countWorkingDays($startDate, $endDate);
        } elseif ($scope === 'semester' && $semester?->start_date) {
            $semesterStart = Carbon::parse($semester->start_date);
            $semesterEnd = $semesterStart->copy()->addWeeks($semester->total_weeks ?? 20)->subDay();
            $startDate = $semesterStart;
            $endDate = min($today, $semesterEnd);
            $denominatorWorkingDays = $countWorkingDays($semesterStart, $semesterEnd);
        }

        if ($denominatorWorkingDays <= 0 && $scope !== 'today') {
            $startDate = now()->startOfMonth();
            $endDate = now()->endOfDay();
            $denominatorWorkingDays = max(1, $countWorkingDays($startDate, $endDate));
        }

        $numeratorWorkingDays = $scope === 'today' ? 1 : $countWorkingDays($startDate, $endDate);

        $records = AttendanceRecord::where('student_id', $student->id)
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->with('leaveType:id,name,slug,display_unit,use_conversion')
            ->get();

        $absentLessonsAsDay = max(1, (int) (SystemSetting::get('absent_lessons_as_day', 6) ?: 6));
        $leaveLessonsAsDay = max(1, (int) (SystemSetting::get('leave_periods_as_day', 6) ?: 6));

        $excludedLeaveSlugs = ['absent', 'late', 'early_leave', 'period_leave', 'health_leave'];
        $fullDayLeaveDateSet = [];
        $periodLeavePeriods = 0;
        $totalAbsentPeriods = 0;

        foreach ($records as $rec) {
            $details = is_array($rec->details) ? $rec->details : (json_decode((string) $rec->details, true) ?: []);
            $isApprovedOrMarked = $rec->approval_status === 'approved' || $rec->approval_status === null;
            $slug = $rec->leaveType?->slug;

            if (in_array($rec->status, ['leave', 'excused'], true) && $isApprovedOrMarked && $slug && ! in_array($slug, $excludedLeaveSlugs, true)) {
                $detailPeriodIds = is_array($details['period_ids'] ?? null) ? $details['period_ids'] : [];
                if ($rec->period_id !== null) {
                    $periodLeavePeriods += 1;
                } elseif (! empty($detailPeriodIds)) {
                    $periodLeavePeriods += count($detailPeriodIds);
                } else {
                    $dKey = $rec->date instanceof Carbon ? $rec->date->toDateString() : (string) $rec->date;
                    $fullDayLeaveDateSet[$dKey] = true;
                }
            }

            if ($rec->status === 'absent') {
                $totalAbsentPeriods += $this->recordPeriodWeight($rec, $details);
            } elseif ($rec->source_type === 'roll_call' && ($details['original_status'] ?? null) === 'absent') {
                $totalAbsentPeriods += 1;
            }
        }

        $totalNonPresentDays = count($fullDayLeaveDateSet)
            + intdiv($periodLeavePeriods, $leaveLessonsAsDay)
            + intdiv($totalAbsentPeriods, $absentLessonsAsDay);
        $presentDays = max(0, $numeratorWorkingDays - $totalNonPresentDays);

        $isTodayRestDay = false;
        if ($scope === 'today') {
            $isTodayRestDay = ! empty($holidays)
                ? in_array(now()->toDateString(), $holidays, true)
                : in_array(now()->dayOfWeek, [Carbon::SUNDAY, Carbon::SATURDAY], true);
        }

        $stats = [
            'present' => $presentDays,
            'working_days' => $denominatorWorkingDays,
            'absent_lessons_as_day' => $absentLessonsAsDay,
            'is_rest_day' => $isTodayRestDay,
        ];

        $leaveTypes = LeaveType::where('school_id', $student->school_id)->where('is_active', true)->get();
        $leaveTypesConfig = [];

        foreach ($leaveTypes as $lt) {
            $byTypeCount = 0;
            foreach ($records->where('leave_type_id', $lt->id) as $rec) {
                $details = is_array($rec->details) ? $rec->details : (json_decode((string) $rec->details, true) ?: []);
                $byTypeCount += $this->recordPeriodWeight($rec, $details);
            }

            if (in_array($lt->slug, ['late', 'absent', 'early_leave'], true)) {
                $byStatusCount = 0;
                foreach ($records->where('status', $lt->slug) as $rec) {
                    $details = is_array($rec->details) ? $rec->details : (json_decode((string) $rec->details, true) ?: []);
                    $byStatusCount += $this->recordPeriodWeight($rec, $details);
                }
                $byTypeCount = max($byTypeCount, $byStatusCount);
            }

            $displayValue = ($lt->use_conversion && $leaveLessonsAsDay > 0)
                ? intdiv($byTypeCount, $leaveLessonsAsDay)
                : $byTypeCount;

            $stats[$lt->slug] = $displayValue;
            $leaveTypesConfig[$lt->slug] = [
                'display_unit' => $lt->display_unit ?? '节',
                'use_conversion' => (bool) ($lt->use_conversion ?? false),
                'raw_count' => $byTypeCount,
            ];
        }

        $stats['pending_requests'] = AttendanceRecord::withoutGlobalScope('day_attendance')
            ->where('student_id', $student->id)
            ->where('is_self_applied', true)
            ->where('approval_status', 'pending')
            ->get()
            ->groupBy(fn ($r) => $r->leave_batch_id ?: ($r->date?->format('Y-m-d').'_'.$r->leave_type_id))
            ->count();

        $stats['_leave_types_config'] = $leaveTypesConfig;

        return $stats;
    }

    private function recordPeriodWeight(AttendanceRecord $record, array $details): int
    {
        if ($record->period_id !== null) {
            return 1;
        }
        if (isset($details['period_ids']) && is_array($details['period_ids']) && count($details['period_ids']) > 0) {
            return count($details['period_ids']);
        }
        if (isset($details['option_periods']) && is_numeric($details['option_periods']) && (int) $details['option_periods'] > 0) {
            return (int) $details['option_periods'];
        }
        if (isset($details['periods']) && is_array($details['periods']) && count($details['periods']) > 0) {
            return count($details['periods']);
        }
        if (isset($details['period_numbers']) && is_array($details['period_numbers']) && count($details['period_numbers']) > 0) {
            return count($details['period_numbers']);
        }

        return 1;
    }

    private function formatRecordItem(AttendanceRecord $record, ?Collection $periodMap = null): array
    {
        $details = is_array($record->details) ? $record->details : (json_decode((string) $record->details, true) ?: []);
        $dateStr = $record->date instanceof Carbon ? $record->date->format('Y-m-d') : (string) $record->date;
        $resolvedLabels = AttendanceController::resolveCalendarSummaryLabels($record);
        $detailLabel = $record->scene === 'evening_study'
            ? $this->resolveEveningLabel($record)
            : $this->resolveDetailLabel($record, $periodMap);

        $recordTime = null;
        if ($record->source_type === 'roll_call' && ! empty($details['roll_call_time'])) {
            $recordTime = (string) $details['roll_call_time'];
        } elseif ($record->created_at) {
            $recordTime = $record->created_at->setTimezone('Asia/Shanghai')->format('H:i');
        }

        return [
            'id' => $record->id,
            'student_id' => $record->student_id,
            'date' => $dateStr,
            'period_id' => $record->period_id,
            'scene' => $record->scene ?? 'regular',
            'status' => $record->status,
            'type_name' => $resolvedLabels['type'],
            'color' => $record->leaveType?->color ?? $record->eveningStudyStatus?->color ?? null,
            'leave_type_id' => $record->leave_type_id,
            'leave_type' => $record->leaveType,
            'leave_batch_id' => $record->leave_batch_id,
            'is_self_applied' => (bool) $record->is_self_applied,
            'approval_status' => $record->approval_status,
            'approver_name' => $record->approver?->name,
            'rejection_reason' => $record->rejection_reason,
            'source_type' => $record->source_type,
            'detail_label' => $detailLabel,
            'record_time' => $recordTime,
            'reason' => $record->reason ?: ($details['text'] ?? null),
            'note' => $record->note,
            'destination' => $record->destination,
            'images' => $record->images ?? [],
            'created_at' => $record->created_at,
        ];
    }

    private function resolveEveningLabel(AttendanceRecord $record): string
    {
        $displayStatus = $record->is_self_applied
            ? ($record->requestedEveningStatus ?? $record->eveningStudyStatus)
            : ($record->eveningStudyStatus ?? $record->requestedEveningStatus);
        $statusName = $displayStatus?->name
            ?? $record->requested_status_name_snapshot
            ?? $record->status_name_snapshot
            ?? '已标记';

        return ($record->period_name_snapshot ?: '夜自习').'·'.$statusName;
    }

    private function resolveDetailLabel(AttendanceRecord $record, ?Collection $periodMap = null): string
    {
        $details = is_array($record->details) ? $record->details : (json_decode((string) $record->details, true) ?: []);

        if ($record->source_type === 'roll_call' && ! empty($details['roll_call_type'])) {
            return (string) $details['roll_call_type'];
        }
        if (! empty($details['display_label'])) {
            return (string) $details['display_label'];
        }
        if (! empty($details['time_slot_name'])) {
            return (string) $details['time_slot_name'];
        }
        if (! empty($details['option_label'])) {
            return (string) $details['option_label'];
        }
        if (! empty($details['period_names']) && is_array($details['period_names'])) {
            return implode('、', $details['period_names']);
        }
        if ($record->period_id !== null) {
            if ($periodMap && $periodMap->has((int) $record->period_id)) {
                return (string) ($periodMap->get((int) $record->period_id)['name'] ?? "第{$record->period_id}节");
            }

            return $record->period_name_snapshot ?: "第{$record->period_id}节";
        }

        $optionMap = [
            'am' => '上午',
            'pm' => '下午',
            'morning_half' => '上午',
            'afternoon_half' => '下午',
            'full_day' => '全天',
            'all_day' => '全天',
        ];
        if (! empty($details['option']) && isset($optionMap[$details['option']])) {
            return $optionMap[$details['option']];
        }

        return '';
    }
}
