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
        
        // Get non-graduated class IDs for filtering
        $activeClassIds = \App\Models\SchoolClass::where('is_graduated', false)->pluck('id');
        
        // Calculate hierarchical student counts (excluding graduated classes)
        $schoolTotalStudents = \App\Models\Student::whereIn('class_id', $activeClassIds)->count();
        $departmentTotalStudents = null;
        $classTotalStudents = null;
        
        if ($user->role === 'teacher') {
            $classIds = $user->teacherClasses->where('is_graduated', false)->pluck('id');
            $query = \App\Models\Student::whereIn('class_id', $classIds);
            $classTotalStudents = $query->clone()->count();
            
            // Get department total for teacher's classes (excluding graduated)
            $deptIds = \App\Models\SchoolClass::whereIn('id', $classIds)->pluck('department_id')->unique();
            $deptClassIds = \App\Models\SchoolClass::whereIn('department_id', $deptIds)->where('is_graduated', false)->pluck('id');
            $departmentTotalStudents = \App\Models\Student::whereIn('class_id', $deptClassIds)->count();
        } elseif (in_array($user->role, ['department_manager', 'manager'])) {
            // Get classes belonging to departments managed by this user (excluding graduated)
            $deptIds = $user->managedDepartments->pluck('id');
             $classIds = \App\Models\SchoolClass::whereIn('department_id', $deptIds)->where('is_graduated', false)->pluck('id');
             $query = \App\Models\Student::whereIn('class_id', $classIds);
             $departmentTotalStudents = $query->clone()->count();
        } elseif (in_array($user->role, ['system_admin', 'school_admin', 'admin'])) {
            $classIds = $activeClassIds;
            $query = \App\Models\Student::whereIn('class_id', $activeClassIds);
        } else {
             return response()->json(['error' => 'Unauthorized'], 403);
        }

        $scope = $request->input('scope', 'today'); // today, week, month, semester
        
        // Total Students (role-based count)
        $totalStudents = $query->count();
        
        // Prepare base query for related records (Attendance)
        $attendanceQuery = AttendanceRecord::query();
        // Pending requests are usually a backlog, so we might not filter by scope unless requested. 
        // But "Leaves" breakdown should respect scope.
        
        // Get semester for date filtering (either specified or current)
        $semesterId = $request->input('semester_id');
        $semester = $semesterId 
            ? \App\Models\Semester::find($semesterId) 
            : \App\Models\Semester::where('is_current', true)->first();
        
        // Date Filtering
        if ($scope === 'today') {
            $attendanceQuery->where('date', now()->format('Y-m-d'));
        } elseif ($scope === 'week') {
            $attendanceQuery->whereBetween('date', [now()->startOfWeek()->format('Y-m-d'), now()->endOfWeek()->format('Y-m-d')]);
        } elseif ($scope === 'month') {
            $attendanceQuery->whereMonth('date', now()->month)->whereYear('date', now()->year);
        } elseif ($scope === 'semester') {
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
        // 按 leave_batch_id 统计请假申请次数（一次申请计为1）
        // 对于有 leave_batch_id 的记录按 batch 分组，没有的按旧逻辑
        $pendingWithBatch = $pendingQuery->clone()
            ->whereNotNull('leave_batch_id')
            ->distinct('leave_batch_id')
            ->count('leave_batch_id');
        $pendingWithoutBatch = $pendingQuery->clone()
            ->whereNull('leave_batch_id')
            ->selectRaw('COUNT(DISTINCT student_id, date, leave_type_id) as count')
            ->value('count') ?? 0;
        $pendingRequests = $pendingWithBatch + $pendingWithoutBatch;

        // Efficient breakdown query
        $attendanceStats = $attendanceQuery->clone()
             ->selectRaw('status, count(*) as count')
             ->groupBy('status')
             ->pluck('count', 'status')
             ->toArray();

        // Leave Types breakdown - 计算人数和节次总数
        // 1. 获取所有请假相关的记录（包括 leave 和 excused 状态，排除被驳回的）
        $leaveRecords = $attendanceQuery->clone()
             ->whereIn('status', ['leave', 'excused'])
             ->whereNotNull('leave_type_id')
             ->where(function($q) {
                 // 只统计批准的或未审批的，排除被驳回的
                 $q->where('approval_status', 'approved')
                   ->orWhereNull('approval_status');
             })
             ->with('leaveType')
             ->get();
        
        // 2. 按请假类型统计（计算节次总数）
        $leaveTypesData = [];
        foreach ($leaveRecords as $record) {
            if (!$record->leaveType) continue;
            
            $typeId = $record->leave_type_id;
            $typeName = $record->leaveType->name;
            $typeSlug = $record->leaveType->slug;
            
            if (!isset($leaveTypesData[$typeId])) {
                $leaveTypesData[$typeId] = [
                    'name' => $typeName,
                    'slug' => $typeSlug,
                    'display_unit' => $record->leaveType->display_unit ?? '次',
                    'use_conversion' => $record->leaveType->use_conversion ?? false,
                    'students' => [],
                    'period_count' => 0
                ];
            }
            
            // 统计学生
            $leaveTypesData[$typeId]['students'][$record->student_id] = true;
            
            // 统计节次数
            // 如果有 period_id，算1节
            if ($record->period_id) {
                $leaveTypesData[$typeId]['period_count'] += 1;
            } else {
                // 如果没有 period_id，从 details 读取节次信息
                $details = is_string($record->details) ? json_decode($record->details, true) : ($record->details ?? []);
                
                // 优先使用 period_ids（时段选择保存的）
                if (isset($details['period_ids']) && is_array($details['period_ids'])) {
                    $leaveTypesData[$typeId]['period_count'] += count($details['period_ids']);
                }
                // 其次使用 option_periods（时段节次数）
                elseif (isset($details['option_periods']) && is_numeric($details['option_periods'])) {
                    $leaveTypesData[$typeId]['period_count'] += (int)$details['option_periods'];
                }
                // 其他格式
                elseif (isset($details['periods']) && is_array($details['periods'])) {
                    $leaveTypesData[$typeId]['period_count'] += count($details['periods']);
                } elseif (isset($details['period_numbers']) && is_array($details['period_numbers'])) {
                    $leaveTypesData[$typeId]['period_count'] += count($details['period_numbers']);
                } else {
                    // 默认算1
                    $leaveTypesData[$typeId]['period_count'] += 1;
                }
            }
        }
        
        // Get leave conversion setting
        $leaveLessonsAsDay = (int) (\App\Models\SystemSetting::where('key', 'leave_periods_as_day')->value('value') ?? 6);
        if ($leaveLessonsAsDay < 1) $leaveLessonsAsDay = 6;
         
        // Convert to key-value format for frontend - 显示格式：X人/Y{单位}
        $leaveStats = [];
        foreach ($leaveTypesData as $typeId => $data) {
            $displayUnit = $data['display_unit'];
            $displayCount = $data['period_count'];
            $peopleCount = count($data['students']);
            
            // Apply conversion if configured
            if ($data['use_conversion'] && $leaveLessonsAsDay > 0) {
                $displayCount = intval(floor($data['period_count'] / $leaveLessonsAsDay));
            }
            
            $leaveStats[$data['name']] = "{$peopleCount}人/{$displayCount}{$displayUnit}";
        }
        
        // Add late and early_leave counts from attendanceStats
        // These use direct status values instead of leave_type_id
        // 计算迟到：人数/次数
        $lateLeaveType = \App\Models\LeaveType::where('slug', 'late')->first();
        $lateDisplayUnit = $lateLeaveType->display_unit ?? '次';
        if (isset($attendanceStats['late']) && $attendanceStats['late'] > 0) {
            $latePeopleCount = $attendanceStats['late'];
            $lateRecordCount = $attendanceQuery->clone()
                ->where('status', 'late')
                ->count();
            $leaveStats['迟到'] = "{$latePeopleCount}人/{$lateRecordCount}{$lateDisplayUnit}";
        }
        
        // 计算早退：人数/次数
        $earlyLeaveType = \App\Models\LeaveType::where('slug', 'early_leave')->first();
        $earlyDisplayUnit = $earlyLeaveType->display_unit ?? '次';
        if (isset($attendanceStats['early_leave']) && $attendanceStats['early_leave'] > 0) {
            $earlyPeopleCount = $attendanceStats['early_leave'];
            $earlyRecordCount = $attendanceQuery->clone()
                ->where('status', 'early_leave')
                ->count();
            $leaveStats['早退'] = "{$earlyPeopleCount}人/{$earlyRecordCount}{$earlyDisplayUnit}";
        }
        
        // 计算旷课总节次数
        // 旷课来源有两种：
        // 1. status='absent' - 手动标记的旷课
        // 2. status='leave' + leave_type.slug='absent' + source_type='roll_call' - 点名产生的旷课
        $absentPeopleCount = $attendanceStats['absent'] ?? 0;
        $absentPeriodCount = 0;
        
        // 查找旷课leave_type的ID
        $absenceLeaveType = \App\Models\LeaveType::where('slug', 'absent')->first();
        
        // 获取手动标记的旷课记录
        if ($absentPeopleCount > 0) {
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
        }
        
        // 获取点名产生的旷课记录（source_type='roll_call' + leave_type='absent'）
        if ($absenceLeaveType) {
            $rollCallAbsents = $attendanceQuery->clone()
                ->where('source_type', 'roll_call')
                ->where('leave_type_id', $absenceLeaveType->id)
                ->get();
            
            $rollCallAbsentStudents = $rollCallAbsents->pluck('student_id')->unique()->count();
            $rollCallAbsentCount = $rollCallAbsents->count();
            
            // 合并统计
            $absentPeopleCount += $rollCallAbsentStudents;
            $absentPeriodCount += $rollCallAbsentCount;
        }
        
        // 显示格式：2人/6{单位}
        $absentDisplayUnit = $absenceLeaveType ? ($absenceLeaveType->display_unit ?? '节') : '节';
        if ($absentPeopleCount > 0) {
            $leaveStats['旷课'] = "{$absentPeopleCount}人/{$absentPeriodCount}{$absentDisplayUnit}";
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
            'school_total_students' => $schoolTotalStudents,
            'department_total_students' => $departmentTotalStudents,
            'class_total_students' => $classTotalStudents,
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
            ],
            // Leave types config for frontend display
            '_leave_types_config' => \App\Models\LeaveType::where('is_active', true)
                ->get()
                ->mapWithKeys(function ($lt) {
                    return [$lt->slug => [
                        'name' => $lt->name,
                        'display_unit' => $lt->display_unit ?? '节',
                        'use_conversion' => $lt->use_conversion ?? false
                    ]];
                })
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
                          ->with(['leaveType'])
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
            
            // Add display_label for roll_call source records
            if ($record->source_type === 'roll_call') {
                $details = is_string($record->details) ? json_decode($record->details, true) : ($record->details ?? []);
                if (isset($details['roll_call_type'])) {
                    $originalStatus = $details['original_status'] ?? $record->status;
                    // 从 leave_type 表读取名称，而不是硬编码
                    $leaveType = \App\Models\LeaveType::where('slug', $originalStatus)->first();
                    $statusLabel = $leaveType ? $leaveType->name : $originalStatus;
                    $record->display_label = $statusLabel . '(' . $details['roll_call_type'] . ')';
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
                 
                 // 区分记录类型：
                 // 1. 真正的全天记录：period_id=null 且没有 option
                 // 2. 时段选项记录：period_id=null 但有 option（如"上午"、"下午"）
                 // 3. 具体时段记录：period_id != null
                 $fullDayRecord = $studentRecords->first(function($rec) {
                     if ($rec->period_id !== null) return false;
                     $details = is_string($rec->details) ? json_decode($rec->details, true) : $rec->details;
                     // 没有 option 或者 option 是"全天"类型的才是真正的全天记录
                     $hasOption = isset($details['option']) && $details['option'] !== null;
                     $isFullDayOption = isset($details['option_label']) && mb_strpos($details['option_label'], '全天') !== false;
                     return !$hasOption || $isFullDayOption;
                 });
                 
                 // 其他所有记录都视为"时段记录"（包括有option的记录和有period_id的记录）
                 $periodRecords = $studentRecords->filter(function($rec) use ($fullDayRecord) {
                     return $rec->id !== ($fullDayRecord->id ?? null);
                 });
                 
                 $s->attendance = $studentRecords->toArray();
                 $s->attendance_summary = [
                     'has_records' => $studentRecords->isNotEmpty(),
                     'type' => $periodRecords->isNotEmpty() ? 'periods' : 'full_day',
                     'default_status' => $fullDayRecord?->status ?? 'present',
                     'period_count' => $periodRecords->count(),
                     'statuses' => $studentRecords->pluck('status')->unique()->values()->toArray()
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

    /**
     * Get calendar summary for teacher dashboard
     * Returns attendance records grouped by date for calendar display
     */
    public function calendarSummary(Request $request)
    {
        $user = $request->user();
        
        // Only teachers and admins can access
        if (!in_array($user->role, ['teacher', 'system_admin', 'school_admin', 'admin', 'department_manager', 'manager'])) {
            return response()->json(['error' => '无权限'], 403);
        }
        
        $month = $request->input('month', now()->format('Y-m'));
        
        // Parse month to get date range
        try {
            $startDate = \Carbon\Carbon::createFromFormat('Y-m', $month)->startOfMonth();
            $endDate = $startDate->copy()->endOfMonth();
        } catch (\Exception $e) {
            return response()->json(['error' => '无效的月份格式'], 400);
        }
        
        // Get class IDs based on role
        if ($user->role === 'teacher') {
            $classIds = $user->teacherClasses->where('is_graduated', false)->pluck('id');
        } elseif (in_array($user->role, ['department_manager', 'manager'])) {
            $deptIds = $user->managedDepartments->pluck('id');
            $classIds = \App\Models\SchoolClass::whereIn('department_id', $deptIds)
                ->where('is_graduated', false)->pluck('id');
        } else {
            $classIds = \App\Models\SchoolClass::where('is_graduated', false)->pluck('id');
        }
        
        if ($classIds->isEmpty()) {
            return response()->json([]);
        }
        
        // Fetch attendance records for the month
        $records = AttendanceRecord::whereIn('class_id', $classIds)
            ->whereBetween('date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
            ->whereIn('status', ['leave', 'excused', 'absent', 'late', 'early_leave'])
            ->with(['student.user', 'leaveType'])
            ->orderBy('created_at', 'desc')
            ->get();
        
        // Group by date and format for calendar display
        $result = [];
        $leaveTypes = \App\Models\LeaveType::where('is_active', true)->get()->keyBy('id');
        
        // 加载节次配置
        $attendancePeriodsJson = \App\Models\SystemSetting::where('key', 'attendance_periods')->value('value');
        $attendancePeriods = [];
        if ($attendancePeriodsJson) {
            try {
                $attendancePeriods = json_decode($attendancePeriodsJson, true) ?: [];
            } catch (\Exception $e) {
                $attendancePeriods = [];
            }
        }
        $periodMap = collect($attendancePeriods)->keyBy('id');
        
        foreach ($records as $record) {
            // Ensure date is formatted as string for array key
            $dateKey = $record->date instanceof \Carbon\Carbon 
                ? $record->date->format('Y-m-d') 
                : (is_string($record->date) ? $record->date : (string)$record->date);
            
            if (!isset($result[$dateKey])) {
                $result[$dateKey] = [];
            }
            
            // Get type label
            $typeLabel = '未知';
            if ($record->status === 'late') {
                $typeLabel = '迟到';
            } elseif ($record->status === 'early_leave') {
                $typeLabel = '早退';
            } elseif ($record->status === 'absent') {
                $typeLabel = '旷课';
            } elseif ($record->leaveType) {
                $typeLabel = $record->leaveType->name;
            } elseif ($record->leave_type_id && isset($leaveTypes[$record->leave_type_id])) {
                $typeLabel = $leaveTypes[$record->leave_type_id]->name;
            }
            
            // Get time option label
            $optionLabel = '';
            $details = is_string($record->details) ? json_decode($record->details, true) : ($record->details ?? []);
            $timeSlotId = $details['time_slot_id'] ?? null;
            
            // 优先使用自定义的显示标签（用户自定义选择节次时生成）
            if (isset($details['display_label'])) {
                $optionLabel = $details['display_label'];
                // 附加节次数量
                if (isset($details['option_periods'])) {
                    $optionLabel .= ' (' . $details['option_periods'] . '节)';
                }
            // 其次使用 option_label（考勤标记时生成的自定义节次标签）
            } elseif (isset($details['option_label'])) {
                $optionLabel = $details['option_label'];
            // 其次使用时段名称（自主请假选择的时段，如"上午"、"下午"）—— 但需检查不是自定义选择
            } elseif (isset($details['time_slot_name']) && !($details['is_custom'] ?? false)) {
                $optionLabel = $details['time_slot_name'];
            // 其次检查 period_id（老师/管理员直接标记的具体节次，如"第9节"）
            } elseif ($record->period_id && isset($periodMap[$record->period_id])) {
                $optionLabel = $periodMap[$record->period_id]['name'] ?? "第{$record->period_id}节";
            } elseif ($record->source_type === 'roll_call' && isset($details['roll_call_type'])) {
                $optionLabel = $details['roll_call_type'];
            } elseif (isset($details['option']) && $record->leaveType && $record->leaveType->input_config) {
                $config = is_string($record->leaveType->input_config) 
                    ? json_decode($record->leaveType->input_config, true) 
                    : $record->leaveType->input_config;
                
                if (isset($config['options'])) {
                    foreach ($config['options'] as $opt) {
                        $optKey = is_array($opt) ? ($opt['key'] ?? $opt) : $opt;
                        $optLabel = is_array($opt) ? ($opt['label'] ?? $optKey) : $optKey;
                        if ($optKey === $details['option']) {
                            $optionLabel = $optLabel;
                            break;
                        }
                    }
                }
            }
            
            // Format time (with correct timezone)
            $recordTime = '';
            if ($record->source_type === 'roll_call' && isset($details['roll_call_time'])) {
                $recordTime = $details['roll_call_time'];
            } elseif ($record->created_at) {
                $recordTime = $record->created_at->setTimezone('Asia/Shanghai')->format('H:i');
            }
            
            // 创建合并键
            // 对于有 period_id 但没有 time_slot_id 的记录，使用特殊键来收集所有节次
            if ($record->period_id && !$timeSlotId && !isset($details['option_label'])) {
                // 单独节次记录，需要合并
                $mergeKey = $record->student_id . '_individual_periods_' . $record->leave_type_id . '_' . $record->status . '_' . $record->approval_status;
                
                if (!isset($result[$dateKey][$mergeKey])) {
                    $result[$dateKey][$mergeKey] = [
                        'id' => $record->id,
                        'type' => $typeLabel,
                        'option' => $optionLabel,
                        'student_name' => $record->student?->user?->name ?? '未知学生',
                        'student_no' => $record->student?->student_no ?? '',
                        'time' => $recordTime,
                        'status' => $record->status,
                        'is_self_applied' => $record->is_self_applied ?? false,
                        'approval_status' => $record->approval_status,
                        '_period_ids' => [$record->period_id], // 收集节次ID
                        '_period_names' => [$optionLabel], // 收集节次名称
                    ];
                } else {
                    // 追加节次
                    $result[$dateKey][$mergeKey]['_period_ids'][] = $record->period_id;
                    $result[$dateKey][$mergeKey]['_period_names'][] = $optionLabel;
                }
            } else {
                // 普通记录或已有 option_label 的记录
                $mergeKey = $record->student_id . '_' . ($timeSlotId ?? $details['option_label'] ?? 'no_ts') . '_' . $record->leave_type_id . '_' . $record->approval_status;
                
                $result[$dateKey][$mergeKey] = [
                    'id' => $record->id,
                    'type' => $typeLabel,
                    'option' => $optionLabel,
                    'student_name' => $record->student?->user?->name ?? '未知学生',
                    'student_no' => $record->student?->student_no ?? '',
                    'time' => $recordTime,
                    'status' => $record->status,
                    'is_self_applied' => $record->is_self_applied ?? false,
                    'approval_status' => $record->approval_status,
                ];
            }
        }
        
        // 处理合并后的单独节次记录，生成显示标签
        foreach ($result as $dateKey => &$records) {
            foreach ($records as $key => &$rec) {
                if (isset($rec['_period_ids']) && count($rec['_period_ids']) > 1) {
                    // 多个节次，生成范围显示
                    $periodIds = array_unique($rec['_period_ids']);
                    sort($periodIds);
                    
                    // 检查是否连续
                    $isConsecutive = true;
                    for ($i = 1; $i < count($periodIds); $i++) {
                        if ($periodIds[$i] - $periodIds[$i-1] != 1) {
                            $isConsecutive = false;
                            break;
                        }
                    }
                    
                    if ($isConsecutive && count($periodIds) > 1) {
                        // 连续节次，显示范围
                        $firstPeriod = $periodMap[$periodIds[0]]['name'] ?? "第{$periodIds[0]}节";
                        $lastPeriod = $periodMap[$periodIds[count($periodIds)-1]]['name'] ?? "第{$periodIds[count($periodIds)-1]}节";
                        // 提取节次数字
                        preg_match('/第?(\d+)节?/', $firstPeriod, $firstMatch);
                        preg_match('/第?(\d+)节?/', $lastPeriod, $lastMatch);
                        if ($firstMatch && $lastMatch) {
                            $rec['option'] = "第{$firstMatch[1]}-{$lastMatch[1]}节";
                        } else {
                            $rec['option'] = $firstPeriod . '-' . $lastPeriod;
                        }
                    } else {
                        // 非连续，列出所有节次
                        $names = array_unique($rec['_period_names']);
                        $rec['option'] = implode('、', $names);
                    }
                }
                // 清理临时字段
                unset($rec['_period_ids']);
                unset($rec['_period_names']);
            }
        }
        
        // 将合并后的结果转换为数组
        foreach ($result as $dateKey => $records) {
            $result[$dateKey] = array_values($records);
        }
        
        return response()->json($result);
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
                    $details = is_string($record->details) ? json_decode($record->details, true) : ($record->details ?? []);
                    
                    // Special handling for roll_call source
                    if ($record->source_type === 'roll_call' && isset($details['roll_call_type'])) {
                        // Use original_status from details if available (for accurate label)
                        $originalStatus = $details['original_status'] ?? $record->status;
                        // 从 leave_type 表读取名称，而不是硬编码
                        $leaveType = \App\Models\LeaveType::where('slug', $originalStatus)->first();
                        $statusLabel = $leaveType ? $leaveType->name : $originalStatus;
                        $detailLabel = $statusLabel . '(' . $details['roll_call_type'] . ')';
                    }
                    // 优先使用自定义的显示标签（用户自定义选择节次时生成）
                    elseif (isset($details['display_label'])) {
                        $detailLabel = $details['display_label'];
                        // 附加节次数量
                        if (isset($details['option_periods'])) {
                            $detailLabel .= ' (' . $details['option_periods'] . '节)';
                        }
                    }
                    // 其次使用时段名称（自主请假选择的时段，如"上午"、"下午"）
                    elseif (isset($details['time_slot_name'])) {
                        $detailLabel = $details['time_slot_name'];
                    }
                    // Normal leave type handling
                    elseif ($record->details && $record->leaveType && $record->leaveType->input_config) {
                        $config = $record->leaveType->input_config;
                        if (is_string($config)) {
                            try {
                                $config = json_decode($config, true);
                            } catch (\Exception $e) {
                                $config = [];
                            }
                        }
                        
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
                    // Determine record time
                    $recordTime = null;
                    if ($record->source_type === 'roll_call' && isset($details['roll_call_time'])) {
                        $recordTime = $details['roll_call_time'];
                    } elseif ($record->created_at) {
                        $recordTime = $record->created_at->setTimezone('Asia/Shanghai')->format('H:i');
                    }
                    
                    $record->detail_label = $detailLabel;
                    $record->record_time = $recordTime;
                    return $record;
                });
            
            // 合并同一天、同一时段的记录（避免"上午"显示5条）
            $grouped = [];
            foreach ($attendance as $record) {
                $details = is_string($record->details) ? json_decode($record->details, true) : ($record->details ?? []);
                $timeSlotId = $details['time_slot_id'] ?? null;
                
                // 如果有 time_slot_id，按日期+时段合并
                if ($timeSlotId) {
                    $key = $record->date . '_ts_' . $timeSlotId . '_' . $record->leave_type_id . '_' . $record->approval_status;
                    if (!isset($grouped[$key])) {
                        $grouped[$key] = $record;
                    }
                    // 已存在则跳过（保留第一条）
                } else {
                    // 没有 time_slot_id 的记录保持原样
                    $grouped[] = $record;
                }
            }
            
            $mergedAttendance = collect(array_values($grouped));
                
            // Return only attendance (leaves are now empty since all data is in attendance_records)
            return response()->json([
                'attendance' => $mergedAttendance,
                'leaves' => []  // Deprecated: all data now in attendance_records
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Server Error: ' . $e->getMessage(), 'trace' => $e->getTraceAsString()], 500);
        }
    }

    /**
     * Get student's own attendance statistics with scope support
     * 
     * Business Logic:
     * - Denominator (working_days): For 'semester' scope, this is the TOTAL semester working days
     * - Numerator (present): From semester start to TODAY, working days minus absent days
     * - Absent days = Full-day leaves (excl. period_leave) + Absent periods converted to days
     * - Absent conversion: total absent periods / absent_lessons_as_day (system setting)
     */
    public function studentStats(Request $request)
    {
        try {
            // Ensure database connection is fresh with retry
            $maxRetries = 5;
            $connected = false;
            for ($i = 0; $i < $maxRetries; $i++) {
                try {
                    \DB::purge(); // Clear any cached connection
                    \DB::reconnect();
                    \DB::select('SELECT 1'); // Test connection
                    $connected = true;
                    break;
                } catch (\Exception $e) {
                    \Log::warning('studentStats: DB connection retry ' . ($i + 1) . ': ' . $e->getMessage());
                    usleep(200000); // 200ms wait
                }
            }
            
            if (!$connected) {
                \Log::error('studentStats: DB connection failed after ' . $maxRetries . ' retries');
                return response()->json(['error' => 'Database connection failed'], 503);
            }

            $user = $request->user();
            if ($user->role !== 'student' || !$user->student) {
                return response()->json(['error' => 'Not a student'], 403);
            }

            $studentId = $user->student->id;
            $scope = $request->input('scope', 'month'); // today, month, semester

            // Get current semester with retry
            $semester = null;
            for ($i = 0; $i < $maxRetries; $i++) {
                try {
                    $semester = \App\Models\Semester::where('is_current', true)->first();
                    break;
                } catch (\Exception $e) {
                    \Log::warning('studentStats: Semester query retry ' . ($i + 1));
                    usleep(100000);
                }
            }
            
            // Get holidays from semester
            $holidays = [];
            if ($semester && $semester->holidays) {
                $holidays = is_string($semester->holidays) 
                    ? json_decode($semester->holidays, true) ?? []
                    : $semester->holidays ?? [];
            }

            // Helper function to count working days in a range
            $countWorkingDays = function($start, $end) use ($holidays) {
                if (!$start || !$end) return 0;
                $count = 0;
                $current = $start->copy();
                $maxDays = 400; // Safety limit to prevent infinite loop
                $dayCount = 0;
                while ($current <= $end && $dayCount < $maxDays) {
                    $dayOfWeek = $current->dayOfWeek;
                    if ($dayOfWeek !== 0 && $dayOfWeek !== 6) {
                        $dateStr = $current->format('Y-m-d');
                        if (!in_array($dateStr, $holidays)) {
                            $count++;
                        }
                    }
                    $current->addDay();
                    $dayCount++;
                }
                return $count;
            };

            // Calculate date ranges based on scope
            $today = now()->endOfDay();
            $statsStartDate = now()->startOfDay();
            $statsEndDate = now()->endOfDay();
            $denominatorWorkingDays = 0;

            if ($scope === 'today') {
                $statsStartDate = now()->startOfDay();
                $statsEndDate = now()->endOfDay();
                $denominatorWorkingDays = $countWorkingDays($statsStartDate, $statsEndDate);
            } elseif ($scope === 'month') {
                $statsStartDate = now()->startOfMonth();
                $statsEndDate = now()->endOfDay();
                $denominatorWorkingDays = $countWorkingDays($statsStartDate, $statsEndDate);
            } elseif ($scope === 'semester') {
                if ($semester && $semester->start_date) {
                    $semesterStart = \Carbon\Carbon::parse($semester->start_date);
                    // Calculate semester end date
                    $semesterEnd = $semesterStart->copy()->addWeeks($semester->total_weeks ?? 20)->subDay();
                    
                    // Stats are calculated from semester start to TODAY
                    $statsStartDate = $semesterStart;
                    $statsEndDate = min($today, $semesterEnd); // Don't go past semester end
                    
                    // Denominator is TOTAL semester working days (start to end)
                    $denominatorWorkingDays = $countWorkingDays($semesterStart, $semesterEnd);
                } else {
                    // Fallback when no semester is configured
                    $statsStartDate = now()->startOfMonth();
                    $statsEndDate = now()->endOfDay();
                    $denominatorWorkingDays = $countWorkingDays($statsStartDate, $statsEndDate);
                    \Log::warning('studentStats: No semester found, using month fallback');
                }
            }

            // Safety check: ensure we have valid working days
            if ($denominatorWorkingDays <= 0) {
                \Log::warning('studentStats: denominatorWorkingDays is ' . $denominatorWorkingDays . ', scope=' . $scope);
                // Recalculate with current month as fallback
                $statsStartDate = now()->startOfMonth();
                $statsEndDate = now()->endOfDay();
                $denominatorWorkingDays = $countWorkingDays($statsStartDate, $statsEndDate);
            }

            // Working days from start to today (for numerator calculation)
            $numeratorWorkingDays = $countWorkingDays($statsStartDate, $statsEndDate);

            // Build base query for the stats period
            $query = AttendanceRecord::where('student_id', $studentId)
                ->whereBetween('date', [$statsStartDate->format('Y-m-d'), $statsEndDate->format('Y-m-d')]);

            // Get system settings for conversion
            $absentLessonsAsDay = (int) (\App\Models\SystemSetting::where('key', 'absent_lessons_as_day')->value('value') ?? 6);
            $leaveLessonsAsDay = (int) (\App\Models\SystemSetting::where('key', 'leave_periods_as_day')->value('value') ?? 6);
            if ($absentLessonsAsDay < 1) $absentLessonsAsDay = 6;
            if ($leaveLessonsAsDay < 1) $leaveLessonsAsDay = 6;

            // Count full-day leaves (period_id is null, approved)
            // Exclude: absent(旷课), late(迟到), early_leave(早退), health_leave/period_leave(生理假)
            $fullDayLeaveDates = $query->clone()
                ->whereIn('status', ['leave', 'excused'])
                ->whereNull('period_id')
                ->where(function($q) {
                    $q->where('approval_status', 'approved')
                      ->orWhereNull('approval_status');
                })
                // Exclude non-leave types
                ->whereHas('leaveType', function($q) {
                    $q->whereNotIn('slug', ['absent', 'late', 'early_leave', 'period_leave', 'health_leave']);
                })
                ->distinct()
                ->pluck('date')
                ->count();

            // Count period-based leaves (period_id is NOT null, approved)
            // These are partial-day leaves
            $periodLeavePeriods = $query->clone()
                ->whereIn('status', ['leave', 'excused'])
                ->whereNotNull('period_id')
                ->where(function($q) {
                    $q->where('approval_status', 'approved')
                      ->orWhereNull('approval_status');
                })
                // Exclude non-leave types
                ->whereHas('leaveType', function($q) {
                    $q->whereNotIn('slug', ['absent', 'late', 'early_leave', 'period_leave', 'health_leave']);
                })
                ->count();

            // Convert period leaves to days
            $leaveDaysFromPeriods = intval(floor($periodLeavePeriods / $leaveLessonsAsDay));

            // Count total absent periods
            // 1. Manual absents (status = 'absent')
            $manualAbsentPeriods = $query->clone()
                ->where('status', 'absent')
                ->count();
            
            // 2. Roll call absents (source_type = 'roll_call' with original_status = 'absent' in details)
            $rollCallAbsentPeriods = $query->clone()
                ->where('source_type', 'roll_call')
                ->whereRaw("JSON_EXTRACT(details, '$.original_status') = ?", ['absent'])
                ->count();
            
            $totalAbsentPeriods = $manualAbsentPeriods + $rollCallAbsentPeriods;

            // Convert absent periods to days (floor division)
            $absentDaysFromPeriods = intval(floor($totalAbsentPeriods / $absentLessonsAsDay));

            // Total non-present days = full-day leaves + period-based leave days + absent days
            $totalNonPresentDays = $fullDayLeaveDates + $leaveDaysFromPeriods + $absentDaysFromPeriods;

            // Calculate present days: working days to today - non-present days
            $presentDays = max(0, $numeratorWorkingDays - $totalNonPresentDays);

            // Build response with leave type counts
            $stats = [
                'present' => $presentDays,
                'working_days' => $denominatorWorkingDays
            ];

            // Get leave types for mapping
            $leaveTypes = \App\Models\LeaveType::where('is_active', true)->get();
            $leaveTypesConfig = [];

            foreach ($leaveTypes as $lt) {
                // 统计该请假类型的节次总数（不是记录数）
                $records = $query->clone()
                    ->where('leave_type_id', $lt->id)
                    ->get();
                
                $periodCount = 0;
                foreach ($records as $record) {
                    if ($record->period_id) {
                        $periodCount += 1;
                    } else {
                        $details = is_string($record->details) ? json_decode($record->details, true) : ($record->details ?? []);
                        
                        // 优先使用 period_ids
                        if (isset($details['period_ids']) && is_array($details['period_ids'])) {
                            $periodCount += count($details['period_ids']);
                        }
                        // 其次使用 option_periods
                        elseif (isset($details['option_periods']) && is_numeric($details['option_periods'])) {
                            $periodCount += (int)$details['option_periods'];
                        }
                        // 其他格式
                        elseif (isset($details['periods']) && is_array($details['periods'])) {
                            $periodCount += count($details['periods']);
                        } elseif (isset($details['period_numbers']) && is_array($details['period_numbers'])) {
                            $periodCount += count($details['period_numbers']);
                        } else {
                            $periodCount += 1;
                        }
                    }
                }
                
                // For status-based records (late, absent, early_leave), count periods properly
                if (in_array($lt->slug, ['late', 'absent', 'early_leave'])) {
                    $statusRecords = $query->clone()
                        ->where('status', $lt->slug)
                        ->get();
                    
                    $statusPeriodCount = 0;
                    foreach ($statusRecords as $rec) {
                        $recDetails = is_string($rec->details) ? json_decode($rec->details, true) : ($rec->details ?? []);
                        
                        // 优先检查 details 中的节次信息
                        if (isset($recDetails['period_ids']) && is_array($recDetails['period_ids'])) {
                            $statusPeriodCount += count($recDetails['period_ids']);
                        } elseif (isset($recDetails['periods']) && is_array($recDetails['periods'])) {
                            $statusPeriodCount += count($recDetails['periods']);
                        } elseif (isset($recDetails['period_numbers']) && is_array($recDetails['period_numbers'])) {
                            $statusPeriodCount += count($recDetails['period_numbers']);
                        } elseif (isset($recDetails['option_periods']) && is_numeric($recDetails['option_periods'])) {
                            $statusPeriodCount += (int)$recDetails['option_periods'];
                        } elseif ($rec->period_id) {
                            // 只有当 details 中没有节次信息时，才按单个节次计算
                            $statusPeriodCount += 1;
                        } else {
                            $statusPeriodCount += 1;
                        }
                    }
                    $periodCount = max($periodCount, $statusPeriodCount);
                }

                // Apply conversion if configured
                $displayValue = $periodCount;
                if ($lt->use_conversion && $leaveLessonsAsDay > 0) {
                    $displayValue = intval(floor($periodCount / $leaveLessonsAsDay));
                }

                $stats[$lt->slug] = $displayValue;
                
                // Store config for frontend display
                $leaveTypesConfig[$lt->slug] = [
                    'display_unit' => $lt->display_unit ?? '节',
                    'use_conversion' => $lt->use_conversion ?? false,
                    'raw_count' => $periodCount  // Original count for reference
                ];
            }

            // For class admin: add pending requests count
            if ($user->student->is_class_admin) {
                $classId = $user->student->class_id;
                // 按 leave_batch_id 统计请假申请次数（一次申请计为1）
                $baseQuery = AttendanceRecord::where('class_id', $classId)
                    ->where('approval_status', 'pending')
                    ->where('is_self_applied', true);
                
                // 有 leave_batch_id 的按 batch 统计
                $pendingWithBatch = $baseQuery->clone()
                    ->whereNotNull('leave_batch_id')
                    ->distinct('leave_batch_id')
                    ->count('leave_batch_id');
                
                // 没有 leave_batch_id 的按旧逻辑统计（兼容旧数据）
                $pendingWithoutBatch = $baseQuery->clone()
                    ->whereNull('leave_batch_id')
                    ->selectRaw('COUNT(DISTINCT student_id, date, leave_type_id) as count')
                    ->value('count') ?? 0;
                
                $stats['pending_requests'] = $pendingWithBatch + $pendingWithoutBatch;
            }

            // Include leave types config for frontend
            $stats['_leave_types_config'] = $leaveTypesConfig;

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
                // For present, show all absence records (leaves, absents, late, early_leave)
                // This helps the student understand how the present days are calculated
                $absenceRecords = AttendanceRecord::where('student_id', $studentId)
                    ->whereBetween('date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
                    ->where(function($q) {
                        $q->whereIn('status', ['leave', 'excused', 'absent', 'late', 'early_leave'])
                          ->orWhere('source_type', 'roll_call');
                    })
                    ->where(function($q) {
                        // Only count approved or teacher-marked
                        $q->where('approval_status', 'approved')
                          ->orWhereNull('approval_status');
                    })
                    ->with('leaveType')
                    ->orderBy('date', 'desc')
                    ->get();

                // Group records by date+leave_type+source_id to avoid duplicates for multi-period leaves
                $groupedRecords = [];
                foreach ($absenceRecords as $record) {
                    // Create a unique key for grouping
                    $groupKey = $record->date->format('Y-m-d') . '_' . 
                                ($record->leave_type_id ?? 'null') . '_' . 
                                ($record->source_id ?? $record->created_at?->format('Y-m-d H:i'));
                    
                    if (!isset($groupedRecords[$groupKey])) {
                        $groupedRecords[$groupKey] = [
                            'record' => $record,
                            'period_count' => 1
                        ];
                    } else {
                        $groupedRecords[$groupKey]['period_count']++;
                    }
                }

                foreach ($groupedRecords as $grouped) {
                    $record = $grouped['record'];
                    $periodCount = $grouped['period_count'];
                    $details = is_string($record->details) ? json_decode($record->details, true) : ($record->details ?? []);
                    $detailLabel = '';
                    $typeName = '';
                    
                    // Special handling for roll_call source
                    if ($record->source_type === 'roll_call' && isset($details['roll_call_type'])) {
                        $originalStatus = $details['original_status'] ?? $record->status;
                        // 从 leave_type 表读取名称
                        $leaveTypeModel = \App\Models\LeaveType::where('slug', $originalStatus)->first();
                        $statusLabel = $leaveTypeModel ? $leaveTypeModel->name : $originalStatus;
                        $typeName = $statusLabel . '(' . $details['roll_call_type'] . ')';
                    } else {
                        // Normal leave/absent/late/early_leave
                        if ($record->leaveType) {
                            $typeName = $record->leaveType->name;
                        } else {
                            $leaveTypeModel = \App\Models\LeaveType::where('slug', $record->status)->first();
                            $typeName = $leaveTypeModel ? $leaveTypeModel->name : $record->status;
                        }
                        
                        // Add detail label (time slot name, option, period names, etc.)
                        if (isset($details['time_slot_name'])) {
                            $detailLabel = $details['time_slot_name'];
                        } elseif (isset($details['display_label'])) {
                            $detailLabel = $details['display_label'];
                        } elseif ($record->details && $record->leaveType && $record->leaveType->input_config) {
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
                        
                        // If no detail label, format period name(s)
                        if (!$detailLabel) {
                            // Get periods configuration
                            $periodsConfig = \DB::table('system_settings')
                                ->where('key', 'attendance_periods')
                                ->value('value');
                            $periodsConfig = $periodsConfig ? json_decode($periodsConfig, true) : [];
                            
                            // Get period IDs from record
                            $periodIds = [];
                            if (isset($details['period_ids']) && is_array($details['period_ids'])) {
                                $periodIds = $details['period_ids'];
                            } elseif (isset($details['periods']) && is_array($details['periods'])) {
                                $periodIds = $details['periods'];
                            } elseif ($record->period_id) {
                                $periodIds = [$record->period_id];
                            }
                            
                            // Convert period IDs to names
                            if (!empty($periodIds) && !empty($periodsConfig)) {
                                $periodNames = [];
                                foreach ($periodIds as $pid) {
                                    foreach ($periodsConfig as $p) {
                                        if ((int)$p['id'] === (int)$pid) {
                                            $periodNames[] = $p['name'];
                                            break;
                                        }
                                    }
                                }
                                if (!empty($periodNames)) {
                                    $detailLabel = implode('、', $periodNames);
                                }
                            }
                        }
                        
                        // Add period count if multiple periods and single period name already shown
                        if ($periodCount > 1 && $detailLabel) {
                            // Don't add "节" if already showing period names
                            if (strpos($detailLabel, '节') === false && strpos($detailLabel, '操') === false && strpos($detailLabel, '自习') === false && strpos($detailLabel, '读') === false) {
                                $detailLabel .= ' (' . $periodCount . '节)';
                            }
                        } elseif ($periodCount > 1 && !$detailLabel) {
                            $detailLabel = $periodCount . '节';
                        }
                    }

                    // Determine record time
                    $recordTime = null;
                    if ($record->source_type === 'roll_call' && isset($details['roll_call_time'])) {
                        $recordTime = $details['roll_call_time'];
                    } elseif ($record->created_at) {
                        $recordTime = $record->created_at->setTimezone('Asia/Shanghai')->format('H:i');
                    }

                    $records[] = [
                        'id' => $record->id,
                        'date' => $record->date->format('Y.m.d'),
                        'time' => $recordTime,
                        'type_name' => $typeName,
                        'detail_label' => $detailLabel,
                        'note' => $record->note
                    ];
                }

                return response()->json([
                    'status' => 'present',
                    'message' => '以下是影响出勤天数的缺勤记录',
                    'records' => $records
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

                // Group records by source_id or created_at to avoid showing duplicates for multi-period leaves
                $groupedRecords = [];
                foreach ($attendanceRecords as $record) {
                    // Determine group key based on source type
                    if ($record->source_type === 'leave_request' && $record->source_id) {
                        // For leave_request source, group by source_id
                        $groupKey = 'leave_' . $record->source_id;
                    } elseif (in_array($record->source_type, ['self_applied', 'manual_bulk', 'manual'])) {
                        // For self_applied/manual, group by date + leave_type_id + created_at (same batch)
                        // Records created within the same second are from the same submission
                        $groupKey = 'batch_' . $record->date->format('Y-m-d') . '_' . $record->leave_type_id . '_' . $record->created_at->format('Y-m-d_H:i:s');
                    } else {
                        // Fallback: use record id (no grouping)
                        $groupKey = 'record_' . $record->id;
                    }
                    
                    if (!isset($groupedRecords[$groupKey])) {
                        $groupedRecords[$groupKey] = $record;
                    }
                }

                foreach ($groupedRecords as $record) {
                    $details = is_string($record->details) ? json_decode($record->details, true) : ($record->details ?? []);
                    $detailLabel = '';
                    $typeName = '';
                    
                    // Special handling for roll_call source
                    if ($record->source_type === 'roll_call' && isset($details['roll_call_type'])) {
                        // Use original_status from details if available (for accurate label)
                        $originalStatus = $details['original_status'] ?? $record->status;
                        // 从 leave_type 表读取名称
                        $leaveTypeModel = \App\Models\LeaveType::where('slug', $originalStatus)->first();
                        $statusLabel = $leaveTypeModel ? $leaveTypeModel->name : $originalStatus;
                        $typeName = $statusLabel . '(' . $details['roll_call_type'] . ')';
                    } else {
                        if ($record->leaveType) {
                            $typeName = $record->leaveType->name;
                        } else {
                            $leaveTypeModel = \App\Models\LeaveType::where('slug', $record->status)->first();
                            $typeName = $leaveTypeModel ? $leaveTypeModel->name : $status;
                        }
                        
                        if ($record->details && $record->leaveType && $record->leaveType->input_config) {
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
                            
                            // Use display_label if available (for custom period selections)
                            if (isset($details['display_label'])) {
                                $detailLabel = $details['display_label'];
                            }
                        }
                    }

                    // Determine record time
                    $recordTime = null;
                    if ($record->source_type === 'roll_call' && isset($details['roll_call_time'])) {
                        $recordTime = $details['roll_call_time'];
                    } elseif ($record->created_at) {
                        $recordTime = $record->created_at->setTimezone('Asia/Shanghai')->format('H:i');
                    }

                    $records[] = [
                        'id' => $record->id,
                        'date' => $record->date->format('Y.m.d'),
                        'time' => $recordTime,
                        'type_name' => $typeName,
                        'detail_label' => $detailLabel,
                        'note' => $record->note,
                        'approval_status' => $record->approval_status,
                        'is_self_applied' => $record->is_self_applied ?? (in_array($record->source_type, ['self_applied', 'leave_request']))
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
            'period_id' => 'nullable|integer',
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
        $basePeriodId = $request->input('period_id', null);

        foreach ($request->records as $recordData) {
            $student = \App\Models\Student::find($recordData['student_id']);
            if (!$student) continue;
            
            $details = $recordData['details'] ?? null;
            
            // 使用AttendanceService创建时段记录
            $service = new \App\Services\AttendanceService();
            
            // 检查是否有 period_ids（时段选择或自定义节次）
            $hasPeriodIds = $details && 
                           isset($details['period_ids']) && 
                           is_array($details['period_ids']) && 
                           count($details['period_ids']) > 0;
            
            if ($hasPeriodIds) {
                // 有 period_ids：创建一条记录，period_id = null
                // 通过 details 中的 period_ids 来记录具体节次
                $periodIds = $details['period_ids'];
                $isCustom = $details['is_custom'] ?? false;
                $timeSlotId = $details['time_slot_id'] ?? null;
                $timeSlotName = $details['time_slot_name'] ?? null;
                
                // 智能检测是否为自定义选择：
                // 如果有 time_slot_id，检查 period_ids 是否与该时段的节次完全匹配
                if ($timeSlotId && !$isCustom) {
                    $timeSlot = \App\Models\TimeSlot::find($timeSlotId);
                    if ($timeSlot) {
                        $slotPeriodIds = $timeSlot->period_ids ?? [];
                        // 比较节次是否完全匹配
                        $currentSorted = collect($periodIds)->sort()->values()->toArray();
                        $slotSorted = collect($slotPeriodIds)->sort()->values()->toArray();
                        if ($currentSorted != $slotSorted) {
                            // 不匹配，视为自定义选择
                            $isCustom = true;
                            $timeSlotName = null;
                            \Log::info("Detected custom period selection: slot {$timeSlotId} has periods " . json_encode($slotSorted) . ", but user selected " . json_encode($currentSorted));
                        }
                    }
                }
                
                // 获取节次名称列表
                $periodNames = [];
                $periodsConfig = \App\Models\SystemSetting::where('key', 'attendance_periods')->value('value');
                $periodsArray = json_decode($periodsConfig, true) ?: [];
                foreach ($periodIds as $pid) {
                    foreach ($periodsArray as $p) {
                        if ((string)$p['id'] === (string)$pid) {
                            $periodNames[] = $p['name'];
                            break;
                        }
                    }
                }
                
                // 生成显示标签
                // 如果是自定义选择，显示具体节次名称
                // 如果匹配时段，显示时段名称
                $optionLabel = $isCustom || empty($timeSlotName)
                    ? implode('、', $periodNames) 
                    : $timeSlotName;
                
                // 创建或更新记录（使用 period_ids 作为唯一标识的一部分）
                $periodIdsKey = implode(',', $periodIds);
                
                $record = $service->record(
                    $student->id,
                    $date,
                    null, // period_id = null，通过 details 区分
                    $recordData['status'],
                    [
                        'leave_type_id' => $recordData['leave_type_id'] ?? null,
                        'details' => [
                            'option' => $isCustom ? 'custom' : ($timeSlotName ?? 'custom'),
                            'option_label' => $optionLabel,
                            'option_periods' => count($periodIds),
                            'period_ids' => $periodIds,
                            'period_names' => $periodNames,
                            'time_slot_id' => $isCustom ? null : $timeSlotId,
                            'time_slot_name' => $isCustom ? null : $timeSlotName,
                            'is_custom' => $isCustom,
                        ],
                        'source_type' => 'manual_bulk',
                    ]
                );
                
                if ($record) {
                    $updatedCount++;
                }
            } else {
                // 其他情况：使用原有逻辑
                $periodId = $basePeriodId;
                $option = null;
                
                if ($details && isset($details['option'])) {
                    $option = $details['option'];
                    $periodId = null;
                }
                
                $record = $service->record(
                    $student->id,
                    $date,
                    $periodId,
                    $recordData['status'],
                    [
                        'leave_type_id' => $recordData['leave_type_id'] ?? null,
                        'details' => $details,
                        'source_type' => 'manual_bulk',
                    ]
                );
                
                if ($record) {
                    $updatedCount++;
                }
            }
        }

        return response()->json([
            'message' => 'Bulk attendance updated.',
            'count' => $updatedCount
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
            'period_id' => 'nullable|integer',
            'option' => 'nullable|string', // 用于删除带 option 的记录
            'source_type' => 'nullable|string', // 用于删除指定来源的记录
            'source_id' => 'nullable|integer', // 用于删除指定来源的记录
        ]);
        
        // 构建查询
        $query = AttendanceRecord::where('student_id', $request->student_id)
            ->where('date', $request->date);
        
        // 如果是点名来源的记录，按 source_type 和 source_id 匹配
        if ($request->source_type === 'roll_call' && $request->source_id) {
            $query->where('source_type', 'roll_call')
                  ->where('source_id', $request->source_id);
        } else {
            // 普通记录按 period_id 匹配
            $query->where('period_id', $request->period_id);
        }
        
        // 如果提供了 option，按 option 过滤
        if ($request->has('option') && $request->option) {
            $query->whereRaw("JSON_EXTRACT(details, '$.option') = ?", [$request->option]);
        }
        
        $record = $query->first();
        
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
        
        // If the record is from roll_call, sync delete the roll_call_record and all related attendance records
        if ($record->source_type === 'roll_call' && $record->source_id) {
            $rollCallId = $record->source_id;
            $studentId = $record->student_id;
            
            // 获取 details 中保存的 roll_call_record_id（如果有）
            $details = is_string($record->details) ? json_decode($record->details, true) : ($record->details ?? []);
            $rollCallRecordId = $details['roll_call_record_id'] ?? null;
            
            // 删除该学生该点名的所有考勤记录（可能有多条，因为 period_count > 1）
            $deletedCount = AttendanceRecord::where('source_type', 'roll_call')
                ->where('source_id', $rollCallId)
                ->where('student_id', $studentId)
                ->delete();
            
            // 更新 roll_call_record 状态为 present（签到）
            $rollCallRecord = \App\Models\RollCallRecord::where('roll_call_id', $rollCallId)
                ->where('student_id', $studentId)
                ->first();
            
            if ($rollCallRecord) {
                $rollCallRecord->update([
                    'status' => 'present',
                    'marked_at' => now(),
                ]);
                
                // 更新 roll_call 的统计
                $rollCall = \App\Models\RollCall::find($rollCallId);
                if ($rollCall) {
                    $presentCount = \App\Models\RollCallRecord::where('roll_call_id', $rollCallId)
                        ->where('status', 'present')
                        ->count();
                    $rollCall->update(['present_count' => $presentCount]);
                }
                
                \Log::info('Synced roll_call_record to present and deleted attendance records', [
                    'roll_call_id' => $rollCallId,
                    'student_id' => $studentId,
                    'deleted_attendance_records' => $deletedCount
                ]);
            }
            
            return response()->json([
                'message' => '记录已撤销，学生已标记为签到',
                'deleted_count' => $deletedCount
            ]);
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
        $semesterId = $request->input('semester_id');

        // Get date range based on scope and optional semester_id
        $dateRange = $this->getDateRangeForScope($scope, $semesterId);

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

        // For 'absent' status, we need to include roll_call source records with leave_type.slug='absent'
        $absenceLeaveTypeId = null;
        if ($status === 'absent') {
            $absenceLeaveType = \App\Models\LeaveType::where('slug', 'absent')->first();
            $absenceLeaveTypeId = $absenceLeaveType?->id;
        }

        // Build the attendance query conditions
        $attendanceCondition = function($q) use ($dateRange, $status, $leaveTypeId, $absenceLeaveTypeId) {
            $q->whereBetween('date', [$dateRange['start'], $dateRange['end']]);
            
            if ($status === 'absent' && $absenceLeaveTypeId) {
                // For absent: include both status='absent' AND (source_type='roll_call' with leave_type_id=absent)
                $q->where(function($q2) use ($absenceLeaveTypeId) {
                    $q2->where('status', 'absent')
                       ->orWhere(function($q3) use ($absenceLeaveTypeId) {
                           $q3->where('source_type', 'roll_call')
                              ->where('leave_type_id', $absenceLeaveTypeId);
                       });
                });
            } else {
                $q->where('status', $status);
                if ($leaveTypeId) {
                    $q->where('leave_type_id', $leaveTypeId);
                }
            }
        };

        // Query students with matching attendance records
        $students = \App\Models\Student::whereIn('class_id', $classIds)
            ->whereHas('attendance', $attendanceCondition)
            ->with([
                'user',
                'schoolClass',
                'attendance' => function($q) use ($dateRange, $status, $leaveTypeId, $absenceLeaveTypeId) {
                    $q->whereBetween('date', [$dateRange['start'], $dateRange['end']]);
                    
                    if ($status === 'absent' && $absenceLeaveTypeId) {
                        $q->where(function($q2) use ($absenceLeaveTypeId) {
                            $q2->where('status', 'absent')
                               ->orWhere(function($q3) use ($absenceLeaveTypeId) {
                                   $q3->where('source_type', 'roll_call')
                                      ->where('leave_type_id', $absenceLeaveTypeId);
                               });
                        });
                    } else {
                        $q->where('status', $status);
                        if ($leaveTypeId) {
                            $q->where('leave_type_id', $leaveTypeId);
                        }
                    }
                    $q->with(['leaveType']);
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
    private function getDateRangeForScope($scope, $semesterId = null)
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
                // Get semester by ID or current
                $semester = $semesterId 
                    ? \App\Models\Semester::find($semesterId) 
                    : \App\Models\Semester::where('is_current', true)->first();
                
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
            ->with(['leaveType'])
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
