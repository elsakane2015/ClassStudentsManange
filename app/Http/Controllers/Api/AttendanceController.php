<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\LeaveRequest;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function stats(Request $request)
    {
        try {
            \Illuminate\Support\Facades\DB::reconnect();
        } catch (\Exception $e) {}

        $user = $request->user();
        
        if ($user->role === 'teacher') {
            $classIds = $user->teacherClasses->pluck('id');
            $query = \App\Models\Student::whereIn('class_id', $classIds);
        } elseif (in_array($user->role, ['department_manager', 'manager'])) {
            // Get classes belonging to departments managed by this user
            $deptIds = $user->managedDepartments->pluck('id');
             // Assuming Class model has department_id
             // And we need student ids from those classes
             // Easiest is to query students where schoolClass.department_id in $deptIds
             // Or get all class IDs first.
             $classIds = \App\Models\SchoolClass::whereIn('department_id', $deptIds)->pluck('id');
             $query = \App\Models\Student::whereIn('class_id', $classIds);
        } elseif (in_array($user->role, ['system_admin', 'school_admin', 'admin'])) {
            $query = \App\Models\Student::query();
        } else {
             return response()->json(['error' => 'Unauthorized'], 403);
        }

        $scope = $request->input('scope', 'today'); // today, week, month, semester
        
        // Total Students (Snapshot, invariant of time scope usually, unless we track enrollment history)
        $totalStudents = $query->count();
        
        // Prepare base query for related records (Attendance)
        $attendanceQuery = AttendanceRecord::query();
        // Pending requests are usually a backlog, so we might not filter by scope unless requested. 
        // But "Leaves" breakdown should respect scope.
        
        // Date Filtering
        if ($scope === 'today') {
            $attendanceQuery->where('date', now()->format('Y-m-d'));
        } elseif ($scope === 'week') {
            $attendanceQuery->whereBetween('date', [now()->startOfWeek()->format('Y-m-d'), now()->endOfWeek()->format('Y-m-d')]);
        } elseif ($scope === 'month') {
            $attendanceQuery->whereMonth('date', now()->month)->whereYear('date', now()->year);
        } elseif ($scope === 'semester') {
            $semester = \App\Models\Semester::where('is_current', true)->first();
            if ($semester) {
                // Calculate end_date from start_date + total_weeks
                $startDate = \Carbon\Carbon::parse($semester->start_date);
                $endDate = $startDate->copy()->addWeeks($semester->total_weeks);
                $attendanceQuery->whereBetween('date', [$semester->start_date, $endDate->format('Y-m-d')]);
            } else {
                $attendanceQuery->whereYear('date', now()->year);
            }
        }

        // Apply Role Filters
        if ($user->role === 'teacher' || $user->role === 'manager') {
             $attendanceQuery->whereIn('class_id', $classIds);
             // $leaveQuery->whereIn('class_id', $classIds); // Pending requests query
        }
        
        // Pending Requests from unified data source (attendance_records with approval_status='pending')
        $pendingQuery = AttendanceRecord::where('approval_status', 'pending');
        if ($user->role === 'teacher' || $user->role === 'manager') {
             $pendingQuery->whereIn('class_id', $classIds);
        }
        $pendingRequests = $pendingQuery->distinct('student_id', 'date', 'period_id')->count();

        // Efficient breakdown query
        $attendanceStats = $attendanceQuery->clone()
             ->selectRaw('status, count(*) as count')
             ->groupBy('status')
             ->pluck('count', 'status')
             ->toArray();

        // Leave Types breakdown - 计算人数和次数
        $leaveStatsRaw = $attendanceQuery->clone()
             ->where('status', 'leave')
             ->join('leave_types', 'attendance_records.leave_type_id', '=', 'leave_types.id')
             ->selectRaw('leave_types.name as type_name, leave_types.id as type_id, 
                          COUNT(DISTINCT attendance_records.student_id) as people_count,
                          COUNT(*) as record_count')
             ->groupBy('leave_types.id', 'leave_types.name')
             ->get();
         
        // Convert to key-value format for frontend - 显示格式：X人/Y次
        $leaveStats = [];
        foreach ($leaveStatsRaw as $stat) {
            $leaveStats[$stat->type_name] = "{$stat->people_count}人/{$stat->record_count}次";
        }
        
        // Add late and early_leave counts from attendanceStats
        // These use direct status values instead of leave_type_id
        // 计算迟到：人数/次数
        if (isset($attendanceStats['late']) && $attendanceStats['late'] > 0) {
            $latePeopleCount = $attendanceStats['late'];
            $lateRecordCount = $attendanceQuery->clone()
                ->where('status', 'late')
                ->count();
            $leaveStats['迟到'] = "{$latePeopleCount}人/{$lateRecordCount}次";
        }
        
        // 计算早退：人数/次数
        if (isset($attendanceStats['early_leave']) && $attendanceStats['early_leave'] > 0) {
            $earlyPeopleCount = $attendanceStats['early_leave'];
            $earlyRecordCount = $attendanceQuery->clone()
                ->where('status', 'early_leave')
                ->count();
            $leaveStats['早退'] = "{$earlyPeopleCount}人/{$earlyRecordCount}次";
        }
        
        // 计算旷课总节次数（从 details 中解析）
        $absentPeopleCount = $attendanceStats['absent'] ?? 0;
        $absentPeriodCount = 0;
        
        if ($absentPeopleCount > 0) {
            // 获取所有旷课记录
            $absentRecords = $attendanceQuery->clone()
                ->where('status', 'absent')
                ->get(['details']);
            
            // 统计总节次数
            foreach ($absentRecords as $record) {
                $details = $record->details;
                
                // 如果 details 是字符串，解析为数组
                if (is_string($details)) {
                    try {
                        $details = json_decode($details, true);
                    } catch (\Exception $e) {
                        $details = null;
                    }
                }
                
                // 优先使用 period_numbers，否则使用 periods
                if (is_array($details)) {
                    if (isset($details['period_numbers']) && is_array($details['period_numbers'])) {
                        $absentPeriodCount += count($details['period_numbers']);
                    } elseif (isset($details['periods']) && is_array($details['periods'])) {
                        $absentPeriodCount += count($details['periods']);
                    } else {
                        // 如果没有 periods 信息，按1节计算
                        $absentPeriodCount += 1;
                    }
                } else {
                    // 如果没有 details，按1节计算
                    $absentPeriodCount += 1;
                }
            }
            
            // 显示格式：2人/6节
            $leaveStats['旷课'] = "{$absentPeopleCount}人/{$absentPeriodCount}节";
        }
             
        // Calculate Effective Attendance
        // For Today: Persons present.
        // For Week/Month: Total "Present Records" (Person-Days or Person-Periods).
        $present = $attendanceStats['present'] ?? 0;
        $late = $attendanceStats['late'] ?? 0;
        $early = $attendanceStats['early_leave'] ?? 0;
        $effectiveAttendance = $present + $late + $early;
        
        // Period-based statistics (时段统计)
        $periodStats = $attendanceQuery->clone()
            ->whereNotNull('period_id')
            ->selectRaw('
                COUNT(*) as total_periods,
                SUM(CASE WHEN status = "present" THEN 1 ELSE 0 END) as present_periods,
                SUM(CASE WHEN status = "late" THEN 1 ELSE 0 END) as late_periods,
                SUM(CASE WHEN status = "absent" THEN 1 ELSE 0 END) as absent_periods,
                SUM(CASE WHEN status = "excused" THEN 1 ELSE 0 END) as excused_periods,
                SUM(CASE WHEN status = "early_leave" THEN 1 ELSE 0 END) as early_leave_periods
            ')
            ->first();
        
        // Full-day records count
        $fullDayRecords = $attendanceQuery->clone()
            ->whereNull('period_id')
            ->count();
            
        return response()->json([
            'total_students' => $totalStudents,
            'present_count' => $effectiveAttendance,
            'pending_requests' => $pendingRequests,
            'scope' => $scope,
            'details' => [
                 'stats' => $attendanceStats,
                 'leaves' => $leaveStats
            ],
            // 新增：时段化统计
            'period_stats' => [
                'total_periods' => $periodStats->total_periods ?? 0,
                'present_periods' => $periodStats->present_periods ?? 0,
                'late_periods' => $periodStats->late_periods ?? 0,
                'absent_periods' => $periodStats->absent_periods ?? 0,
                'excused_periods' => $periodStats->excused_periods ?? 0,
                'early_leave_periods' => $periodStats->early_leave_periods ?? 0,
                'full_day_records' => $fullDayRecords,
                'attendance_rate' => $periodStats && $periodStats->total_periods > 0 
                    ? round(($periodStats->present_periods + $periodStats->late_periods) / $periodStats->total_periods * 100, 2)
                    : 0
            ]
        ]);
    }

    /**
     * Get hierarchical overview: Department -> Class -> Students with Attendance
     */
    public function overview(Request $request)
    {
        // 1. Reconnect
        try { \DB::reconnect(); } catch (\Exception $e) {}

        $user = $request->user();
        $date = $request->input('date', now()->format('Y-m-d'));

        // 2. Fetch Departments
        $departments = collect([]);
        $isClassAdmin = $user->student && $user->student->is_class_admin;
        
        if ($user->role === 'teacher') {
            // Teachers might not manage departments, but let's fetch all for structure or relevant ones
            // Logic: Fetch all depts, we filter classes later.
             $departments = \App\Models\Department::all();
        } elseif (in_array($user->role, ['department_manager', 'manager'])) {
             $departments = $user->managedDepartments;
        } elseif (in_array($user->role, ['system_admin', 'school_admin', 'admin'])) {
             $departments = \App\Models\Department::all();
        } elseif ($isClassAdmin) {
             // Class admin student - get their class's department
             $departments = \App\Models\Department::all();
        }

        // 3. Fetch Classes relevant to the user
        $classQuery = \App\Models\SchoolClass::query();
        if ($user->role === 'teacher') {
             $classQuery->whereIn('id', $user->teacherClasses->pluck('id'));
        } elseif (in_array($user->role, ['department_manager', 'manager'])) {
             $classQuery->whereIn('department_id', $departments->pluck('id'));
        } elseif ($isClassAdmin) {
             // Class admin student - only their own class
             $classQuery->where('id', $user->student->class_id);
        }
        // Admin sees all
        $classes = $classQuery->get();

        // 4. Fetch Students for these classes
        if ($classes->isEmpty()) {
             return response()->json([]);
        }
        $classIds = $classes->pluck('id');
        $students = \App\Models\Student::whereIn('class_id', $classIds)->with('user')->get();
        // Manually Attach Attendance (支持时段化)
        $studentIds = $students->pluck('id');
        $attendances = \App\Models\AttendanceRecord::whereIn('student_id', $studentIds)
                          ->where('date', $date)
                          ->with(['leaveType', 'period'])
                          ->orderByRaw('period_id IS NULL DESC') // NULL在前
                          ->orderBy('period_id')
                          ->get();
        
        // For excused records without leave_type_id, try to get from source (leave_request)
        $leaveRequestIds = $attendances->where('source_type', 'leave_request')
            ->whereNull('leave_type_id')
            ->pluck('source_id')
            ->unique()
            ->filter();
        
        $leaveRequests = [];
        if ($leaveRequestIds->isNotEmpty()) {
            $requests = \App\Models\LeaveRequest::whereIn('id', $leaveRequestIds)->get();
            foreach ($requests as $req) {
                // Get leave type by slug
                $lt = \App\Models\LeaveType::where('slug', $req->type)
                    ->where('school_id', $req->school_id)
                    ->first();
                $leaveRequests[$req->id] = [
                    'leave_type' => $lt,
                    'details' => $req->details,
                    'half_day' => $req->half_day,
                ];
            }
        }
        
        // Attach leave_type from source for records without leave_type_id OR without details
        // Also get all source IDs to fetch details
        $allSourceIds = $attendances->where('source_type', 'leave_request')
            ->pluck('source_id')
            ->unique()
            ->filter();
        
        $allLeaveRequests = [];
        if ($allSourceIds->isNotEmpty()) {
            $requests = \App\Models\LeaveRequest::whereIn('id', $allSourceIds)->get();
            foreach ($requests as $req) {
                // Get leave type by slug
                $lt = \App\Models\LeaveType::where('slug', $req->type)
                    ->where('school_id', $req->school_id)
                    ->first();
                $allLeaveRequests[$req->id] = [
                    'leave_type' => $lt,
                    'half_day' => $req->half_day,
                ];
            }
        }
        
        $attendances = $attendances->map(function($record) use ($allLeaveRequests, $leaveRequests) {
            if ($record->status === 'excused' && $record->source_type === 'leave_request') {
                $sourceData = $allLeaveRequests[$record->source_id] ?? null;
                
                // If no leave_type_id, set it from source
                if (!$record->leave_type_id && $sourceData && $sourceData['leave_type']) {
                    $record->setRelation('leaveType', $sourceData['leave_type']);
                    $record->leave_type_id = $sourceData['leave_type']->id;
                }
                
                // If no details, set from source data
                if (!$record->details && $sourceData) {
                    $optionKey = $sourceData['half_day'];
                    
                    // If half_day is null (full day), get default option from leave_type input_config
                    if (!$optionKey && $sourceData['leave_type']) {
                        $lt = $sourceData['leave_type'];
                        if ($lt->input_config) {
                            try {
                                $config = is_string($lt->input_config) 
                                    ? json_decode($lt->input_config, true) 
                                    : $lt->input_config;
                                
                                // Find default option or first option marked as full_day/all_day
                                if (isset($config['options']) && is_array($config['options'])) {
                                    foreach ($config['options'] as $opt) {
                                        $key = is_array($opt) ? ($opt['key'] ?? '') : $opt;
                                        $isDefault = is_array($opt) && (($opt['is_default'] ?? false) || ($opt['full_day'] ?? false) || $key === 'all_day' || $key === 'full_day');
                                        if ($isDefault) {
                                            $optionKey = $key;
                                            break;
                                        }
                                    }
                                    // If no default found, use first option
                                    if (!$optionKey && !empty($config['options'])) {
                                        $firstOpt = $config['options'][0];
                                        $optionKey = is_array($firstOpt) ? ($firstOpt['key'] ?? '') : $firstOpt;
                                    }
                                }
                            } catch (\Exception $e) {
                                // Ignore parse errors
                            }
                        }
                    }
                    
                    if ($optionKey) {
                        $record->details = ['option' => $optionKey];
                    }
                }
            }
            return $record;
        });
        
        $attendances = $attendances->groupBy('student_id'); // 按学生分组，支持多条记录
        
        // 5. Stitch Data Structure
        // Map Students to Classes
        $classesWithStudents = $classes->map(function($cls) use ($students, $attendances) {
             $clsStudents = $students->where('class_id', $cls->id)->map(function($s) use ($attendances) {
                 // 获取该学生的所有考勤记录（可能有多条）
                 $studentRecords = $attendances->has($s->id) ? $attendances->get($s->id) : collect([]);
                 
                 // 分离全天记录和时段记录
                 $fullDayRecord = $studentRecords->firstWhere('period_id', null);
                 $periodRecords = $studentRecords->where('period_id', '!=', null);
                 
                 $s->attendance = $studentRecords->toArray();
                 $s->attendance_summary = [
                     'has_records' => $studentRecords->isNotEmpty(),
                     'type' => $periodRecords->isNotEmpty() ? 'periods' : 'full_day',
                     'default_status' => $fullDayRecord?->status ?? 'present',
                     'period_count' => $periodRecords->count(),
                     'statuses' => $periodRecords->pluck('status')->unique()->values()->toArray()
                 ];
                 return $s;
             })->values();
             $cls->setRelation('students', $clsStudents);
             return $cls;
        });

        // Map Classes to Departments
        $finalStructure = $departments->map(function($dept) use ($classesWithStudents) {
             $deptClasses = $classesWithStudents->where('department_id', $dept->id)->values();
             $dept->setRelation('classes', $deptClasses);
             return $dept;
        });

        // Handle Uncategorized Classes (No Department)
        $uncategorizedClasses = $classesWithStudents->whereNull('department_id')->values();
        if ($uncategorizedClasses->isNotEmpty()) {
             $finalStructure->push([
                 'id' => 'uncategorized',
                 'name' => '未分类',
                 'classes' => $uncategorizedClasses
             ]);
        }
        
        // Remove empty departments from view? Optional.
        // For now, keep them.

        return response()->json($finalStructure->values());
    }

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
    
    public function calendar(Request $request) 
    {
        try {
            $user = $request->user();
            if ($user->role !== 'student') {
                return response()->json(['error' => 'Not a student role'], 403);
            }
            
            if (!$user->student) {
                return response()->json(['error' => 'User has no student profile linked. Please contact admin.'], 500);
            }
            
            $start = $request->input('start');
            $end = $request->input('end');
            
            if (!$start || !$end) {
                return response()->json(['error' => 'Missing start/end dates'], 400);
            }
            
            // UNIFIED DATA SOURCE: All attendance data comes from attendance_records
            // Including self-applied leaves (with approval_status)
            $attendance = AttendanceRecord::where('student_id', $user->student->id)
                ->whereBetween('date', [$start, $end])
                ->with('leaveType')
                ->get()
                ->map(function($record) {
                    // Add detail_label for display
                    $detailLabel = '';
                    if ($record->details && $record->leaveType && $record->leaveType->input_config) {
                        $config = $record->leaveType->input_config;
                        if (is_string($config)) {
                            try {
                                $config = json_decode($config, true);
                            } catch (\Exception $e) {
                                $config = [];
                            }
                        }
                        
                        $details = is_string($record->details) ? json_decode($record->details, true) : $record->details;
                        if (isset($details['option']) && isset($config['options'])) {
                            foreach ($config['options'] as $opt) {
                                $optKey = is_array($opt) ? ($opt['key'] ?? $opt) : $opt;
                                $optLabel = is_array($opt) ? ($opt['label'] ?? $optKey) : $optKey;
                                if ($optKey === $details['option']) {
                                    $detailLabel = $optLabel;
                                    break;
                                }
                            }
                        }
                    }
                    
                    $record->detail_label = $detailLabel;
                    return $record;
                });
                
            // Return only attendance (leaves are now empty since all data is in attendance_records)
            return response()->json([
                'attendance' => $attendance,
                'leaves' => []  // Deprecated: all data now in attendance_records
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Server Error: ' . $e->getMessage(), 'trace' => $e->getTraceAsString()], 500);
        }
    }

    /**
     * Get student's own attendance statistics with scope support
     */
    public function studentStats(Request $request)
    {
        try {
            $user = $request->user();
            if ($user->role !== 'student' || !$user->student) {
                return response()->json(['error' => 'Not a student'], 403);
            }

            $studentId = $user->student->id;
            $scope = $request->input('scope', 'month'); // today, month, semester

            // Get current semester
            $semester = \App\Models\Semester::where('is_current', true)->first();
            
            // Calculate date range based on scope
            $startDate = now()->startOfDay();
            $endDate = now()->endOfDay();

            if ($scope === 'today') {
                $startDate = now()->startOfDay();
                $endDate = now()->endOfDay();
            } elseif ($scope === 'month') {
                $startDate = now()->startOfMonth();
                $endDate = now()->endOfDay();
            } elseif ($scope === 'semester') {
                if ($semester) {
                    $startDate = \Carbon\Carbon::parse($semester->start_date);
                    $endDate = now()->endOfDay();
                } else {
                    $startDate = now()->startOfYear();
                    $endDate = now()->endOfDay();
                }
            }

            // Build base query
            $query = AttendanceRecord::where('student_id', $studentId)
                ->whereBetween('date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')]);

            // Get attendance count by status
            $attendanceStats = $query->clone()
                ->selectRaw('status, count(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray();

            // Calculate working days in range (excluding weekends and holidays)
            $workingDays = 0;
            $holidays = [];
            if ($semester && $semester->holidays) {
                $holidays = is_string($semester->holidays) 
                    ? json_decode($semester->holidays, true) ?? []
                    : $semester->holidays ?? [];
            }

            $currentDay = $startDate->copy();
            while ($currentDay <= $endDate) {
                // Skip weekends (Saturday = 6, Sunday = 0)
                $dayOfWeek = $currentDay->dayOfWeek;
                if ($dayOfWeek !== 0 && $dayOfWeek !== 6) {
                    // Skip holidays
                    $dateStr = $currentDay->format('Y-m-d');
                    if (!in_array($dateStr, $holidays)) {
                        $workingDays++;
                    }
                }
                $currentDay->addDay();
            }

            // Count full-day leaves (unified data source - no need to check leave_requests separately)
            $fullDayLeaves = $query->clone()
                ->whereIn('status', ['leave', 'excused'])
                ->whereNull('period_id')
                ->where(function($q) {
                    // Only count approved or teacher-marked
                    $q->where('approval_status', 'approved')
                      ->orWhereNull('approval_status');
                })
                ->count();

            // Calculate present days: working days - leave days
            $presentDays = max(0, $workingDays - $fullDayLeaves);

            // Build response with leave type counts
            $stats = [
                'present' => $presentDays,
                'working_days' => $workingDays
            ];

            // Get leave types for mapping
            $leaveTypes = \App\Models\LeaveType::where('is_active', true)->get();

            foreach ($leaveTypes as $lt) {
                // Count records with this leave_type_id (for 'leave' status)
                $count = $query->clone()
                    ->where('leave_type_id', $lt->id)
                    ->count();
                
                // Also check for status matching slug (for late, absent, early_leave)
                if (in_array($lt->slug, ['late', 'absent', 'early_leave'])) {
                    $count = max($count, $attendanceStats[$lt->slug] ?? 0);
                }

                $stats[$lt->slug] = $count;
            }

            // For class admin: add pending requests count
            if ($user->student->is_class_admin) {
                $classId = $user->student->class_id;
                $pendingCount = AttendanceRecord::where('class_id', $classId)
                    ->where('approval_status', 'pending')
                    ->distinct('student_id', 'date', 'period_id')
                    ->count();
                $stats['pending_requests'] = $pendingCount;
            }

            return response()->json($stats);
        } catch (\Exception $e) {
            \Log::error('studentStats error: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
            return response()->json(['error' => 'Server Error: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get student's attendance detail records for a specific status
     */
    public function studentDetails(Request $request)
    {
        try {
            $user = $request->user();
            if ($user->role !== 'student' || !$user->student) {
                return response()->json(['error' => 'Not a student'], 403);
            }

            $studentId = $user->student->id;
            $scope = $request->input('scope', 'month');
            $status = $request->input('status'); // e.g., 'sick_leave', 'late', 'present'

            // Get current semester
            $semester = \App\Models\Semester::where('is_current', true)->first();
            
            // Calculate date range based on scope
            $startDate = now()->startOfDay();
            $endDate = now()->endOfDay();

            if ($scope === 'today') {
                $startDate = now()->startOfDay();
                $endDate = now()->endOfDay();
            } elseif ($scope === 'month') {
                $startDate = now()->startOfMonth();
                $endDate = now()->endOfDay();
            } elseif ($scope === 'semester') {
                if ($semester) {
                    $startDate = \Carbon\Carbon::parse($semester->start_date);
                    $endDate = now()->endOfDay();
                } else {
                    $startDate = now()->startOfYear();
                    $endDate = now()->endOfDay();
                }
            }

            $records = [];

            if ($status === 'present') {
                // For present, we calculate working days without full-day leaves
                return response()->json([
                    'status' => 'present',
                    'message' => '正常出勤天数是根据工作日减去请假天数计算的',
                    'records' => []
                ]);
            }

            // Get leave type
            $leaveType = \App\Models\LeaveType::where('slug', $status)->first();

            if ($leaveType) {
                // Get attendance records with this leave type
                $attendanceRecords = AttendanceRecord::where('student_id', $studentId)
                    ->whereBetween('date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
                    ->where(function($q) use ($leaveType, $status) {
                        $q->where('leave_type_id', $leaveType->id);
                        if (in_array($status, ['late', 'absent', 'early_leave'])) {
                            $q->orWhere('status', $status);
                        }
                    })
                    ->with('leaveType')
                    ->orderBy('date', 'desc')
                    ->get();

                foreach ($attendanceRecords as $record) {
                    $detailLabel = '';
                    if ($record->details && $record->leaveType && $record->leaveType->input_config) {
                        $details = is_string($record->details) ? json_decode($record->details, true) : $record->details;
                        $config = is_string($record->leaveType->input_config) ? json_decode($record->leaveType->input_config, true) : $record->leaveType->input_config;
                        
                        if (isset($details['option']) && isset($config['options'])) {
                            foreach ($config['options'] as $opt) {
                                $optKey = is_array($opt) ? ($opt['key'] ?? $opt) : $opt;
                                $optLabel = is_array($opt) ? ($opt['label'] ?? $optKey) : $optKey;
                                if ($optKey === $details['option']) {
                                    $detailLabel = $optLabel;
                                    break;
                                }
                            }
                        }
                    }

                    $records[] = [
                        'id' => $record->id,
                        'date' => $record->date,
                        'type_name' => $record->leaveType ? $record->leaveType->name : $status,
                        'detail_label' => $detailLabel,
                        'note' => $record->note
                    ];
                }
            }

            return response()->json([
                'status' => $status,
                'records' => $records
            ]);
        } catch (\Exception $e) {
            \Log::error('studentDetails error: ' . $e->getMessage());
            return response()->json(['error' => 'Server Error: ' . $e->getMessage()], 500);
        }
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

    public function triggerAutoMark(Request $request)
    {
        // Replicate logic from Console Command
        // Check setting
        $setting = \App\Models\SystemSetting::where('key', 'attendance_auto_mark_time')->first();
        if (!$setting) {
             return response()->json(['message' => 'Auto-mark time not configured.'], 400);
        }

        $autoMarkTimeStr = $setting->value; 
        try {
            $autoMarkTime = \Carbon\Carbon::createFromFormat('H:i', $autoMarkTimeStr);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Invalid time format setting.'], 500);
        }

        $now = \Carbon\Carbon::now();
        // Allow force via query param ?force=true
        if (!$request->has('force') && $now->format('H:i') < $autoMarkTime->format('H:i')) {
             return response()->json(['message' => 'Not yet time.', 'current' => $now->format('H:i'), 'target' => $autoMarkTimeStr]);
        }

        $date = $now->format('Y-m-d');
        $count = 0;

        // Fetch students chunked
        \App\Models\Student::chunk(100, function ($students) use ($date, &$count) {
            foreach ($students as $student) {
                $exists = AttendanceRecord::where('student_id', $student->id)
                            ->where('date', $date)
                            ->exists();
                
                if ($exists) continue;

                // Check if student has an approved leave for this date (unified data source)
                $onLeave = AttendanceRecord::where('student_id', $student->id)
                            ->where('date', $date)
                            ->whereIn('status', ['leave', 'excused'])
                            ->where(function($q) {
                                $q->where('approval_status', 'approved')
                                  ->orWhereNull('approval_status');
                            })
                            ->exists();

                $status = $onLeave ? 'leave' : 'present';
                
                AttendanceRecord::create([
                    'student_id' => $student->id,
                    'class_id' => $student->class_id,
                    'school_id' => $student->school_id,
                    'date' => $date,
                    'status' => $status, // If no leave, mark as present automatically
                    'source_type' => 'auto',
                    'period_id' => null, // Day attendance
                ]);
                $count++;
            }
        });

        return response()->json(['message' => 'Auto-mark completed.', 'marked_count' => $count]);
    }

    public function bulkStore(Request $request)
    {
        $request->validate([
            'date' => 'required|date',
            'records' => 'required|array',
            'records.*.student_id' => 'required|exists:students,id',
            'records.*.status' => 'required|in:present,absent,late,leave,early_leave', 
            'records.*.leave_type_id' => 'nullable|exists:leave_types,id',
            'records.*.details' => 'nullable|array',
        ]);

        // Self-Healing: Fix missing columns if needed
        try {
            if (!\Illuminate\Support\Facades\Schema::hasColumn('attendance_records', 'leave_type_id')) {
                 \Illuminate\Support\Facades\DB::statement("ALTER TABLE attendance_records ADD COLUMN leave_type_id BIGINT UNSIGNED NULL AFTER status");
                 \Illuminate\Support\Facades\DB::statement("ALTER TABLE attendance_records ADD CONSTRAINT attendance_records_leave_type_id_foreign FOREIGN KEY (leave_type_id) REFERENCES leave_types(id) ON DELETE SET NULL");
            }
            if (!\Illuminate\Support\Facades\Schema::hasColumn('attendance_records', 'details')) {
                 \Illuminate\Support\Facades\DB::statement("ALTER TABLE attendance_records ADD COLUMN details JSON NULL AFTER leave_type_id");
            }
            
            // Fix Enum to include 'leave'
            // We use raw try catch because checking exact enum val is hard
            try {
                 \Illuminate\Support\Facades\DB::statement("ALTER TABLE attendance_records MODIFY COLUMN status ENUM('present', 'absent', 'late', 'excused', 'early_leave', 'leave') DEFAULT 'present'");
            } catch (\Exception $e) { /* Ignore */ }

        } catch (\Exception $e) {
            \Log::error('Auto-Fix Schema Failed for Attendance: ' . $e->getMessage());
        }

        try {
            \DB::reconnect();
        } catch(\Exception $e) {}

        $date = $request->date;
        $updatedCount = 0;
        
        // 获取请求中的period_id（如果有）
        $periodId = $request->input('period_id', null);

        foreach ($request->records as $recordData) {
            $student = \App\Models\Student::find($recordData['student_id']);
            if (!$student) continue;
            
            // 使用AttendanceService创建时段记录
            $service = new \App\Services\AttendanceService();
            
            $record = $service->record(
                $student->id,
                $date,
                $periodId, // 如果为null，则创建全天记录；否则创建时段记录
                $recordData['status'],
                [
                    'leave_type_id' => $recordData['leave_type_id'] ?? null,
                    'details' => $recordData['details'] ?? null,
                    'source_type' => 'manual_bulk',
                ]
            );
            
            if ($record) {
                $updatedCount++;
            }
        }

        return response()->json([
            'message' => 'Bulk attendance updated.',
            'count' => $updatedCount,
            'period_id' => $periodId
        ]);
    }
    
    
    /**
     * 删除考勤记录
     */
    public function deleteRecord(Request $request)
    {
        $request->validate([
            'student_id' => 'required|exists:students,id',
            'date' => 'required|date',
            'period_id' => 'nullable|exists:class_periods,id',
        ]);
        
        // First, find the record to check if it's from a leave_request
        $record = AttendanceRecord::where('student_id', $request->student_id)
            ->where('date', $request->date)
            ->where('period_id', $request->period_id)
            ->first();
        
        if (!$record) {
            return response()->json(['message' => 'Record not found.'], 404);
        }
        
        // If the record is from a leave_request, cancel the leave request
        if ($record->source_type === 'leave_request' && $record->source_id) {
            $leaveRequest = \App\Models\LeaveRequest::find($record->source_id);
            if ($leaveRequest) {
                // Cancel the leave request
                $leaveRequest->update([
                    'status' => 'cancelled',
                ]);
                
                // Delete ALL attendance records generated from this leave request
                AttendanceRecord::where('source_type', 'leave_request')
                    ->where('source_id', $record->source_id)
                    ->delete();
                
                return response()->json([
                    'message' => 'Record and associated leave request cancelled successfully.',
                    'leave_request_cancelled' => true
                ]);
            }
        }
        
        // Regular delete for non-leave records
        $record->delete();
        
        return response()->json(['message' => 'Record deleted successfully.']);
    }

    /**
     * Get detailed student list for a specific status type and time scope
     */
    public function details(Request $request)
    {
        try {
            \DB::reconnect();
        } catch (\Exception $e) {}

        $user = $request->user();
        $scope = $request->input('scope', 'today');
        $status = $request->input('status');
        $leaveTypeId = $request->input('leave_type_id');

        // Get date range based on scope
        $dateRange = $this->getDateRangeForScope($scope);

        // Get authorized class IDs
        if ($user->role === 'teacher') {
            $classIds = $user->teacherClasses->pluck('id');
        } elseif (in_array($user->role, ['department_manager', 'manager'])) {
            $deptIds = $user->managedDepartments->pluck('id');
            $classIds = \App\Models\SchoolClass::whereIn('department_id', $deptIds)->pluck('id');
        } elseif (in_array($user->role, ['system_admin', 'school_admin', 'admin'])) {
            $classIds = \App\Models\SchoolClass::pluck('id');
        } else {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Query students with matching attendance records
        $students = \App\Models\Student::whereIn('class_id', $classIds)
            ->whereHas('attendance', function($q) use ($dateRange, $status, $leaveTypeId) {
                $q->whereBetween('date', [$dateRange['start'], $dateRange['end']])
                  ->where('status', $status);
                if ($leaveTypeId) {
                    $q->where('leave_type_id', $leaveTypeId);
                }
            })
            ->with([
                'user',
                'schoolClass',
                'attendance' => function($q) use ($dateRange, $status, $leaveTypeId) {
                    $q->whereBetween('date', [$dateRange['start'], $dateRange['end']])
                      ->where('status', $status);
                    if ($leaveTypeId) {
                        $q->where('leave_type_id', $leaveTypeId);
                    }
                    $q->with(['leaveType', 'period']);
                }
            ])
            ->get();
        
        // Load department separately to avoid table name issues
        $students->load('schoolClass.department');

        // Format response
        $result = $students->map(function($student) use ($scope) {
            try {
                $records = $student->attendance;
                
                // Calculate details based on scope
                if ($scope === 'today') {
                    // For today, show period details
                    $detailText = '';
                    if ($records->isNotEmpty()) {
                        $record = $records->first();
                        $details = is_string($record->details) ? json_decode($record->details, true) : $record->details;
                        
                        if (is_array($details)) {
                            if (isset($details['period_numbers'])) {
                                $detailText = '第' . implode(',', $details['period_numbers']) . '节';
                            } elseif (isset($details['periods'])) {
                                $detailText = '第' . implode(',', $details['periods']) . '节';
                            } elseif (isset($details['time'])) {
                                $detailText = $details['time'];
                            } elseif (isset($details['option'])) {
                                $optionMap = [
                                    'morning_half' => '上午',
                                    'afternoon_half' => '下午',
                                    'full_day' => '全天'
                                ];
                                $detailText = $optionMap[$details['option']] ?? $details['option'];
                            }
                        }
                    }
                } else {
                    // For week/month/semester, show count
                    $detailText = $records->count() . '次';
                }

                $studentData = [
                    'id' => $student->id,
                    'student_no' => $student->student_no ?? '',
                    'name' => $student->user?->name ?? '-',
                    'department' => $student->schoolClass?->department?->name ?? '-',
                    'class' => $student->schoolClass?->name ?? '-',
                    'detail' => $detailText ?? '-',
                    'records' => $records->toArray()
                ];
                
                \Log::info('[details] Student data:', [
                    'student_id' => $student->id,
                    'student_no' => $student->student_no,
                    'returned_id' => $studentData['id']
                ]);
                
                return $studentData;
            } catch (\Exception $e) {
                \Log::error('Error formatting student data: ' . $e->getMessage(), [
                    'student_id' => $student->id ?? 'unknown',
                    'trace' => $e->getTraceAsString()
                ]);
                
                return [
                    'student_no' => $student->student_no ?? 'N/A',
                    'name' => 'Error',
                    'department' => '-',
                    'class' => '-',
                    'detail' => 'Error: ' . $e->getMessage(),
                    'records' => []
                ];
            }
        });

        return response()->json($result);
    }

    /**
     * Get date range for a given scope
     */
    private function getDateRangeForScope($scope)
    {
        $now = now();
        
        switch ($scope) {
            case 'today':
                return [
                    'start' => $now->format('Y-m-d'),
                    'end' => $now->format('Y-m-d')
                ];
            case 'week':
                return [
                    'start' => $now->copy()->startOfWeek()->format('Y-m-d'),
                    'end' => $now->copy()->endOfWeek()->format('Y-m-d')
                ];
            case 'month':
                return [
                    'start' => $now->copy()->startOfMonth()->format('Y-m-d'),
                    'end' => $now->copy()->endOfMonth()->format('Y-m-d')
                ];
            case 'semester':
                // Get current semester using is_current flag
                $semester = \App\Models\Semester::where('is_current', true)->first();
                
                if ($semester) {
                    // Calculate end_date from start_date + total_weeks
                    $startDate = \Carbon\Carbon::parse($semester->start_date);
                    $endDate = $startDate->copy()->addWeeks($semester->total_weeks);
                    
                    return [
                        'start' => $semester->start_date,
                        'end' => $endDate->format('Y-m-d')
                    ];
                } else {
                    // Fallback to current month if no semester found
                    return [
                        'start' => $now->copy()->startOfMonth()->format('Y-m-d'),
                        'end' => $now->copy()->endOfMonth()->format('Y-m-d')
                    ];
                }
            default:
                return [
                    'start' => $now->format('Y-m-d'),
                    'end' => $now->format('Y-m-d')
                ];
        }
    }

    /**
     * Get all attendance records for a specific student in a given scope
     */
    public function studentRecords(Request $request)
    {
        $user = auth()->user();
        $studentId = $request->input('student_id');
        $scope = $request->input('scope', 'today');

        \Log::info('[studentRecords] Request params:', [
            'student_id' => $studentId,
            'scope' => $scope
        ]);

        if (!$studentId) {
            return response()->json(['error' => 'student_id is required'], 400);
        }

        // Get date range for scope
        $dateRange = $this->getDateRangeForScope($scope);
        
        \Log::info('[studentRecords] Date range:', $dateRange);

        // Get all attendance records for this student in the date range
        $records = \App\Models\AttendanceRecord::where('student_id', $studentId)
            ->whereBetween('date', [$dateRange['start'], $dateRange['end']])
            ->with(['leaveType', 'period'])
            ->orderBy('date', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();
            
        \Log::info('[studentRecords] Found records:', [
            'count' => $records->count(),
            'student_id' => $studentId
        ]);

        return response()->json($records);
    }

    public function batchStore(Request $request)
    {
        $user = $request->user();
        
        // Check if user is a class admin (student with is_class_admin = true)
        // or a teacher/admin
        $isAuthorized = false;
        
        if ($user->student && $user->student->is_class_admin) {
            $isAuthorized = true;
        } elseif (in_array($user->role, ['teacher', 'system_admin', 'school_admin', 'admin'])) {
            $isAuthorized = true;
        }
        
        if (!$isAuthorized) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'records' => 'required|array',
            'records.*.student_id' => 'required|exists:students,id',
            'records.*.date' => 'required|date',
            'records.*.status' => 'required|string',
            'class_id' => 'nullable|exists:classes,id'
        ]);

        $createdRecords = [];
        
        foreach ($validated['records'] as $recordData) {
            // Check if record already exists
            $existing = AttendanceRecord::where('student_id', $recordData['student_id'])
                ->where('date', $recordData['date'])
                ->first();

            if ($existing) {
                // Update existing record
                $existing->status = $recordData['status'];
                $existing->marked_by = $user->id;
                $existing->save();
                $createdRecords[] = $existing;
            } else {
                // Create new record
                $record = AttendanceRecord::create([
                    'student_id' => $recordData['student_id'],
                    'date' => $recordData['date'],
                    'status' => $recordData['status'],
                    'marked_by' => $user->id,
                    'source_type' => 'manual'
                ]);
                $createdRecords[] = $record;
            }
        }

        return response()->json([
            'message' => 'Attendance records saved successfully',
            'count' => count($createdRecords)
        ]);
    }
}
