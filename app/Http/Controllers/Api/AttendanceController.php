<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\LeaveRequest;
use App\Services\RollCallAttendanceSyncService;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function stats(Request $request)
    {
        try {
            \Illuminate\Support\Facades\DB::reconnect();
        } catch (\Exception $e) {}

        $user = $request->user();
        
        // 获取"不计入缺勤"的请假类型ID列表
        // 这些类型（如"其他"：活动、学生会等）不会计入缺勤统计
        $nonAbsenceLeaveTypeIds = \App\Models\LeaveType::where('counts_as_absence', false)->pluck('id')->toArray();
        
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
        $pendingQuery = AttendanceRecord::withoutGlobalScope('day_attendance')
            ->where('approval_status', 'pending')
            ->where('is_self_applied', true);
        if ($user->role === 'teacher' || $user->role === 'manager') {
             $pendingQuery->whereIn('class_id', $classIds);
        }
        $pendingRequests = (int) $pendingQuery->clone()
            ->selectRaw("COUNT(DISTINCT COALESCE(leave_batch_id, CONCAT(student_id, '_', `date`, '_', COALESCE(leave_type_id, '0')))) as total")
            ->value('total');

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
            // 人数：去重的学生数
            $latePeopleCount = $attendanceQuery->clone()
                ->where('status', 'late')
                ->distinct('student_id')
                ->count('student_id');
            // 记录数
            $lateRecordCount = $attendanceStats['late'];
            $leaveStats['迟到'] = "{$latePeopleCount}人/{$lateRecordCount}{$lateDisplayUnit}";
        }
        
        // 计算早退：人数/次数
        $earlyLeaveType = \App\Models\LeaveType::where('slug', 'early_leave')->first();
        $earlyDisplayUnit = $earlyLeaveType->display_unit ?? '次';
        if (isset($attendanceStats['early_leave']) && $attendanceStats['early_leave'] > 0) {
            // 人数：去重的学生数
            $earlyPeopleCount = $attendanceQuery->clone()
                ->where('status', 'early_leave')
                ->distinct('student_id')
                ->count('student_id');
            // 记录数
            $earlyRecordCount = $attendanceStats['early_leave'];
            $leaveStats['早退'] = "{$earlyPeopleCount}人/{$earlyRecordCount}{$earlyDisplayUnit}";
        }
        
        // 计算旷课总节次数
        // 旷课来源有两种：
        // 1. status='absent' - 手动标记的旷课
        // 2. status='leave' + leave_type.slug='absent' + source_type='roll_call' - 点名产生的旷课
        $absentStudentIds = [];  // 收集所有旷课学生ID用于去重
        $absentPeriodCount = 0;
        
        // 查找旷课leave_type的ID
        $absenceLeaveType = \App\Models\LeaveType::where('slug', 'absent')->first();
        
        // 获取手动标记的旷课记录
        $manualAbsentCount = $attendanceStats['absent'] ?? 0;
        if ($manualAbsentCount > 0) {
            $absentRecords = $attendanceQuery->clone()
                ->where('status', 'absent')
                ->get(['student_id', 'details']);
            
            // 统计学生ID和总节次数
            foreach ($absentRecords as $record) {
                $absentStudentIds[] = $record->student_id;
                
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
                ->get(['student_id']);
            
            foreach ($rollCallAbsents as $record) {
                $absentStudentIds[] = $record->student_id;
            }
            $rollCallAbsentCount = $rollCallAbsents->count();
            
            // 合并节次统计
            $absentPeriodCount += $rollCallAbsentCount;
        }
        
        // 人数：去重后的学生数
        $absentPeopleCount = count(array_unique($absentStudentIds));
        
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
        
        // 获取缺勤阈值配置
        $absentThreshold = (int) (\App\Models\SystemSetting::where('key', 'absent_lessons_as_day')->value('value') ?? 6);
        
        // 计算出勤统计
        // 逻辑：出勤人数 = 总人数 - 当日缺勤人数（当日旷课/请假节数 >= 阈值的学生）
        $presentStudentsCount = 0;
        $attendanceRate = 0;
        
        if ($scope === 'today') {
            // 今日：统计缺勤人数（旷课或请假节数 >= 阈值的学生，或有全天假的学生）
            $today = now()->format('Y-m-d');
            
            // 获取所有 period_id = NULL 的缺勤记录，然后在 PHP 层面判断是否为真正的全天假
            // 排除 counts_as_absence = false 的请假类型（如活动、学生会等）
            $nullPeriodRecords = \DB::table('attendance_records')
                ->whereIn('class_id', $classIds)
                ->where('date', $today)
                ->where('counts_in_day_stats', true)
                ->whereIn('status', ['absent', 'leave', 'excused'])
                ->whereNull('period_id')
                ->where(function($q) use ($nonAbsenceLeaveTypeIds) {
                    $q->whereNull('leave_type_id')
                      ->orWhereNotIn('leave_type_id', $nonAbsenceLeaveTypeIds);
                })
                ->select('student_id', 'details')
                ->get();
            
            // 判断是否为全天假：details 中没有 period_ids 或 option 为 full_day/morning_half/afternoon_half
            $fullDayAbsentStudents = collect();
            $periodAbsentFromNullRecords = collect(); // 虽然 period_id=NULL 但实际是分节假的
            
            foreach ($nullPeriodRecords as $record) {
                $details = is_string($record->details) ? json_decode($record->details, true) : $record->details;
                
                // 检查是否有 period_ids 数组（表示是分节假）
                $hasPeriodIds = is_array($details) && 
                               isset($details['period_ids']) && 
                               is_array($details['period_ids']) && 
                               count($details['period_ids']) > 0;
                
                // 检查 option 是否为全天类型
                $option = $details['option'] ?? null;
                $isFullDayOption = in_array($option, ['full_day', 'all_day', 'morning_half', 'afternoon_half']);
                
                if ($hasPeriodIds && !$isFullDayOption) {
                    // 虽然 period_id=NULL，但有具体的 period_ids，是分节假
                    $periodAbsentFromNullRecords->push([
                        'student_id' => $record->student_id,
                        'period_count' => count($details['period_ids'])
                    ]);
                } else {
                    // 真正的全天假
                    $fullDayAbsentStudents->push($record->student_id);
                }
            }
            
            // 统计 period_id=NULL 但实际是分节假的学生是否达到阈值
            $periodAbsentFromNullStudents = $periodAbsentFromNullRecords
                ->groupBy('student_id')
                ->filter(function($records) use ($absentThreshold) {
                    return $records->sum('period_count') >= $absentThreshold;
                })
                ->keys();
            
            // 方式2：period_id IS NOT NULL 的分节假累计达到阈值的学生
            // 排除 counts_as_absence = false 的请假类型
            $periodAbsentStudents = \DB::table('attendance_records')
                ->whereIn('class_id', $classIds)
                ->where('date', $today)
                ->where('counts_in_day_stats', true)
                ->whereIn('status', ['absent', 'leave', 'excused'])
                ->whereNotNull('period_id')
                ->where(function($q) use ($nonAbsenceLeaveTypeIds) {
                    $q->whereNull('leave_type_id')
                      ->orWhereNotIn('leave_type_id', $nonAbsenceLeaveTypeIds);
                })
                ->select('student_id', \DB::raw('COUNT(*) as period_count'))
                ->groupBy('student_id')
                ->having('period_count', '>=', $absentThreshold)
                ->pluck('student_id');
            
            // 合并三种缺勤学生（去重）
            $absentStudents = $fullDayAbsentStudents
                ->merge($periodAbsentFromNullStudents)
                ->merge($periodAbsentStudents)
                ->unique()
                ->count();
            
            $presentStudentsCount = max(0, $totalStudents - $absentStudents);
        } else {
            // 本周/本月/学期：计算出勤率
            // 获取日期范围
            if ($scope === 'week') {
                $startDate = now()->startOfWeek()->format('Y-m-d');
                $endDate = now()->endOfWeek()->format('Y-m-d');
            } elseif ($scope === 'month') {
                $startDate = now()->startOfMonth()->format('Y-m-d');
                $endDate = now()->endOfMonth()->format('Y-m-d');
            } else { // semester
                if ($semester) {
                    $startDate = $semester->start_date;
                    $semesterStart = \Carbon\Carbon::parse($semester->start_date);
                    $endDate = $semesterStart->copy()->addWeeks($semester->total_weeks)->format('Y-m-d');
                } else {
                    $startDate = now()->startOfYear()->format('Y-m-d');
                    $endDate = now()->format('Y-m-d');
                }
            }
            
            // 统计每天的缺勤人数，计算出勤人天数
            // 获取所有 period_id = NULL 的缺勤记录
            // 排除 counts_as_absence = false 的请假类型
            $nullPeriodRecords = \DB::table('attendance_records')
                ->whereIn('class_id', $classIds)
                ->whereBetween('date', [$startDate, min($endDate, now()->format('Y-m-d'))])
                ->where('counts_in_day_stats', true)
                ->whereIn('status', ['absent', 'leave', 'excused'])
                ->whereNull('period_id')
                ->where(function($q) use ($nonAbsenceLeaveTypeIds) {
                    $q->whereNull('leave_type_id')
                      ->orWhereNotIn('leave_type_id', $nonAbsenceLeaveTypeIds);
                })
                ->select('date', 'student_id', 'details')
                ->get();
            
            // 分类：真正的全天假 vs 分节假
            $fullDayAbsences = collect();
            $periodAbsencesFromNull = collect();
            
            foreach ($nullPeriodRecords as $record) {
                $details = is_string($record->details) ? json_decode($record->details, true) : $record->details;
                
                $hasPeriodIds = is_array($details) && 
                               isset($details['period_ids']) && 
                               is_array($details['period_ids']) && 
                               count($details['period_ids']) > 0;
                
                $option = $details['option'] ?? null;
                $isFullDayOption = in_array($option, ['full_day', 'all_day', 'morning_half', 'afternoon_half']);
                
                if ($hasPeriodIds && !$isFullDayOption) {
                    // 分节假
                    $periodAbsencesFromNull->push([
                        'date' => $record->date,
                        'student_id' => $record->student_id,
                        'period_count' => count($details['period_ids'])
                    ]);
                } else {
                    // 真正的全天假
                    $fullDayAbsences->push([
                        'date' => $record->date,
                        'student_id' => $record->student_id
                    ]);
                }
            }
            
            // 获取 period_id IS NOT NULL 的分节假累计达到阈值的
            // 排除 counts_as_absence = false 的请假类型
            $periodAbsences = \DB::table('attendance_records')
                ->whereIn('class_id', $classIds)
                ->whereBetween('date', [$startDate, min($endDate, now()->format('Y-m-d'))])
                ->where('counts_in_day_stats', true)
                ->whereIn('status', ['absent', 'leave', 'excused'])
                ->whereNotNull('period_id')
                ->where(function($q) use ($nonAbsenceLeaveTypeIds) {
                    $q->whereNull('leave_type_id')
                      ->orWhereNotIn('leave_type_id', $nonAbsenceLeaveTypeIds);
                })
                ->select('date', 'student_id', \DB::raw('COUNT(*) as period_count'))
                ->groupBy('date', 'student_id')
                ->having('period_count', '>=', $absentThreshold)
                ->get();
            
            // 处理 period_id=NULL 但实际是分节假的记录
            $periodAbsentFromNullStudentDates = $periodAbsencesFromNull
                ->groupBy(fn($r) => $r['date'] . '-' . $r['student_id'])
                ->filter(fn($records) => $records->sum('period_count') >= $absentThreshold)
                ->map(function($records) {
                    $first = $records->first();
                    return ['date' => $first['date'], 'student_id' => $first['student_id']];
                })
                ->values();
            
            // 合并所有缺勤记录
            $allAbsences = $fullDayAbsences
                ->merge($periodAbsentFromNullStudentDates)
                ->merge($periodAbsences->map(fn($r) => ['date' => $r->date, 'student_id' => $r->student_id]));
            
            // 按日期分组，统计每天的唯一缺勤学生数
            $absentByDate = collect($allAbsences)->groupBy('date')->map(function($records) {
                return collect($records)->pluck('student_id')->unique()->count();
            });
            
            // 获取假日配置
            $holidays = [];
            if ($semester && $semester->holidays) {
                $holidays = is_string($semester->holidays) 
                    ? json_decode($semester->holidays, true) ?? []
                    : $semester->holidays ?? [];
            }
            
            // 计算工作日函数
            $countWorkingDays = function($start, $end) use ($holidays) {
                if (!$start || !$end) return 0;
                $count = 0;
                $current = \Carbon\Carbon::parse($start)->startOfDay();
                $endDate = \Carbon\Carbon::parse($end)->endOfDay();
                $maxDays = 400;
                $dayCount = 0;
                while ($current <= $endDate && $dayCount < $maxDays) {
                    $dateStr = $current->format('Y-m-d');
                    // 只要不在假日列表中就是工作日（包括调休的周末）
                    if (!in_array($dateStr, $holidays)) {
                        $count++;
                    }
                    $current->addDay();
                    $dayCount++;
                }
                return $count;
            };
            
            // 计算到今天为止的工作日天数
            $actualEndDate = min($endDate, now()->format('Y-m-d'));
            $totalDays = $countWorkingDays($startDate, $actualEndDate);
            
            if ($totalDays > 0) {
                // 总应出勤人天数 = 总人数 × 工作日天数
                $totalPersonDays = $totalStudents * $totalDays;
                
                // 出勤人天数 = 总应出勤人天数 - 缺勤人天数
                $absentPersonDays = $absentByDate->sum();
                $presentPersonDays = $totalPersonDays - $absentPersonDays;
                
                $attendanceRate = round(($presentPersonDays / $totalPersonDays) * 100, 1);
                $presentStudentsCount = round($presentPersonDays / $totalDays); // 平均每日出勤人数(用于兼容)
            }
        }
        
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
            'present_students_count' => $presentStudentsCount, // 今日出勤人数
            'attendance_rate' => $attendanceRate, // 出勤率百分比(本周/本月/学期)
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
        if (!app()->environment('testing') && \DB::getDriverName() !== 'sqlite') {
            try { \DB::reconnect(); } catch (\Exception $e) {}
        }

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
        $attendanceQuery = \App\Models\AttendanceRecord::query();
        if ($request->boolean('include_evening')) {
            $attendanceQuery->withoutGlobalScope('day_attendance');
        }
        $attendances = $attendanceQuery->whereIn('student_id', $studentIds)
                          ->where('date', $date)
                          ->with(['leaveType', 'requestedEveningStatus:id,name', 'eveningStudyStatus:id,name'])
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

        // 旧的多日申请可能把跨日期节次写进同一个 display_label。考勤弹窗始终按当天、
        // 当前审批状态下的实际记录重新生成标签，避免三天的节次在每天重复显示。
        $attendancePeriodsJson = \App\Models\SystemSetting::where('key', 'attendance_periods')->value('value');
        $periodMap = collect($attendancePeriodsJson ? json_decode($attendancePeriodsJson, true) : [])
            ->keyBy(fn ($period) => (int) ($period['id'] ?? 0));
        $batchDisplayLabels = $attendances
            ->filter(fn ($record) => $record->leave_batch_id
                && $record->period_id
                && $record->scene !== 'evening_study')
            ->groupBy(fn ($record) => $this->attendanceBatchDisplayKey($record))
            ->map(function ($records) use ($periodMap) {
                $periodNames = $records->pluck('period_id')
                    ->map(fn ($periodId) => $periodMap->get((int) $periodId)['name'] ?? null)
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                return $this->formatAttendancePeriodNames($periodNames);
            });
        
        $attendances = $attendances->map(function($record) use ($allLeaveRequests, $leaveRequests, $batchDisplayLabels) {
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

            if ($record->leave_batch_id && $record->period_id && $record->scene !== 'evening_study') {
                $displayLabel = $batchDisplayLabels->get($this->attendanceBatchDisplayKey($record));
                if ($displayLabel) {
                    $record->setAttribute('display_label', $displayLabel);
                }
            }

            // 待审批 + 有点名记录：展示层显示为点名旷课（仅改内存，不写库）
            // 审批列表仍因 is_self_applied=true 而能看到该记录
            if ($record->approval_status === 'pending') {
                $details = is_string($record->details) ? json_decode($record->details, true) : ($record->details ?? []);
                if (isset($details['roll_call_pending'])) {
                    $rcp = $details['roll_call_pending'];
                    $ltModel = \App\Models\LeaveType::find($rcp['leave_type_id'] ?? null);
                    $statusLabel = $ltModel ? $ltModel->name : '旷课';
                    $record->display_label = $statusLabel . '(' . $rcp['roll_call_type'] . ')';
                    $record->approval_status = null; // 考勤弹窗不显示"待审:"前缀
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
     * 判断考勤记录是否属于应在日历异常汇总/学生日历中展示的记录：
     * 1. 所有非出勤状态（请假/旷课/迟到/早退）均展示；
     * 2. 夜自习场景下，正常点名记录（status=present 且无请假类型、非自主请假）不应作为异常展示，
     *    仅保留带请假类型的夜自习标记，或学生自主提交的待审/已审/驳回夜自习申请。
     */
    public static function shouldIncludeInCalendarSummary(AttendanceRecord $record): bool
    {
        if (in_array($record->status, ['leave', 'excused', 'absent', 'late', 'early_leave'], true)) {
            return true;
        }

        if ($record->scene === 'evening_study') {
            if (!empty($record->leave_type_id)) {
                return true;
            }

            if ($record->is_self_applied && in_array($record->approval_status, ['pending', 'approved', 'rejected'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 为查询追加与 shouldIncludeInCalendarSummary 一致的 SQL 过滤条件。
     */
    private static function applyCalendarAnomalyFilter($query): void
    {
        $query->where(function ($subQuery) {
            $subQuery->whereIn('status', ['leave', 'excused', 'absent', 'late', 'early_leave'])
                ->orWhere(function ($eveningQuery) {
                    $eveningQuery->where('scene', 'evening_study')
                        ->where(function ($eveningSub) {
                            $eveningSub->whereNotNull('leave_type_id')
                                ->orWhere(function ($selfApplied) {
                                    $selfApplied->where('is_self_applied', true)
                                        ->whereIn('approval_status', ['pending', 'approved', 'rejected']);
                                });
                        });
                });
        });
    }

    /**
     * 解析日历汇总中展示的类型标题与夜自习选项文案，避免无请假类型的夜自习状态落回“未知”。
     *
     * @param  AttendanceRecord  $record
     * @param  \Illuminate\Support\Collection|array  $leaveTypes
     * @return array{type: string, option: ?string}
     */
    public static function resolveCalendarSummaryLabels(AttendanceRecord $record, $leaveTypes = []): array
    {
        $leaveType = $record->relationLoaded('leaveType')
            ? $record->getRelation('leaveType')
            : ($record->leave_type_id ? $record->leaveType : null);
        $leaveTypeName = $leaveType?->name;
        if (!$leaveTypeName && $record->leave_type_id && isset($leaveTypes[$record->leave_type_id])) {
            $leaveTypeName = $leaveTypes[$record->leave_type_id]->name;
        }

        if ($record->scene === 'evening_study') {
            $periodName = $record->period_name_snapshot ?: '夜自习';
            $requestedEveningStatus = $record->relationLoaded('requestedEveningStatus')
                ? $record->getRelation('requestedEveningStatus')
                : ($record->requested_evening_status_id ? $record->requestedEveningStatus : null);
            $eveningStudyStatus = $record->relationLoaded('eveningStudyStatus')
                ? $record->getRelation('eveningStudyStatus')
                : ($record->evening_study_status_id ? $record->eveningStudyStatus : null);
            $displayStatus = $record->is_self_applied
                ? ($requestedEveningStatus ?? $eveningStudyStatus)
                : ($eveningStudyStatus ?? $requestedEveningStatus);
            $statusName = trim((string) ($displayStatus?->name
                ?? ($record->is_self_applied
                    ? ($record->requested_status_name_snapshot ?: $record->status_name_snapshot)
                    : ($record->status_name_snapshot ?: $record->requested_status_name_snapshot))
                ?? ''));

            if ($leaveTypeName) {
                return [
                    'type' => $leaveTypeName,
                    'option' => $periodName . '·' . ($statusName !== '' ? $statusName : '已标记'),
                ];
            }

            if ($statusName !== '') {
                return [
                    'type' => $statusName,
                    'option' => $periodName,
                ];
            }

            $fallbackType = match ($record->status) {
                'late' => '迟到',
                'early_leave' => '早退',
                'absent' => '旷课',
                'excused', 'leave' => '请假',
                default => $periodName,
            };

            return [
                'type' => $fallbackType,
                'option' => $fallbackType === $periodName ? '' : $periodName,
            ];
        }

        $typeLabel = match (true) {
            $record->status === 'late' => '迟到',
            $record->status === 'early_leave' => '早退',
            $record->status === 'absent' => '旷课',
            (bool) $leaveTypeName => $leaveTypeName,
            in_array($record->status, ['excused', 'leave'], true) => '请假',
            default => '未知',
        };

        return [
            'type' => $typeLabel,
            'option' => null,
        ];
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
        $today = now()->toDateString();
        $records = AttendanceRecord::withoutGlobalScope('day_attendance')
            ->whereIn('class_id', $classIds)
            ->whereBetween('date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
            ->where(function ($query) {
                $query->where('is_self_applied', false)
                    ->orWhereNull('is_self_applied')
                    ->orWhereIn('approval_status', ['pending', 'approved', 'rejected']);
            })
            ->where(function ($query) use ($today) {
                $query->whereDate('date', '<=', $today)
                    ->orWhere(function ($futureQuery) use ($today) {
                        $futureQuery->whereDate('date', '>', $today)
                            ->whereIn('source_type', [
                                'self_applied',
                                'leave_request',
                                'manual',
                                'manual_bulk',
                                'teacher_evening_leave',
                            ]);
                    });
            })
            ->tap(fn ($query) => self::applyCalendarAnomalyFilter($query))
            ->with([
                'student.user',
                'leaveType',
                'requestedEveningStatus:id,name',
                'eveningStudyStatus:id,name',
            ])
            ->orderBy('created_at', 'desc')
            ->get()
            ->filter(fn ($record) => self::shouldIncludeInCalendarSummary($record))
            ->values();
        
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
            
            // Get type & evening option label
            $resolvedLabels = self::resolveCalendarSummaryLabels($record, $leaveTypes);
            $typeLabel = $resolvedLabels['type'];

            // Get time option label & optional remark note
            $optionLabel = '';
            $noteLabel = null;
            $details = is_string($record->details) ? json_decode($record->details, true) : ($record->details ?? []);
            if (!is_array($details)) {
                $details = [];
            }
            $timeSlotId = $details['time_slot_id'] ?? null;
            $isBatchPeriodRecord = $record->leave_batch_id
                && $record->period_id
                && $record->scene !== 'evening_study';
            
            // 优先使用自定义的显示标签（用户自定义选择节次时生成）
            if ($record->scene === 'evening_study') {
                $optionLabel = $resolvedLabels['option'] ?? '';
                $noteLabel = self::resolveCalendarSummaryNote($record, $details, $optionLabel);
            } elseif ($isBatchPeriodRecord) {
                $optionLabel = $periodMap->get((int) $record->period_id)['name'] ?? "第{$record->period_id}节";
                $noteLabel = self::resolveCalendarSummaryNote($record, $details, $optionLabel);
            } else {
                $formattedOption = self::formatCompactCalendarOption(
                    $details,
                    $periodMap,
                    $record->period_id ? (int) $record->period_id : null,
                    $record->source_type,
                    $record->leaveType
                );
                $optionLabel = $formattedOption['option'];
                $noteLabel = self::resolveCalendarSummaryNote(
                    $record,
                    $details,
                    $optionLabel,
                    $formattedOption['note']
                );
            }
            
            // Format time (with correct timezone)
            $recordTime = '';
            if ($record->source_type === 'roll_call' && isset($details['roll_call_time'])) {
                $recordTime = $details['roll_call_time'];
            } elseif ($record->created_at) {
                $recordTime = $record->created_at->setTimezone('Asia/Shanghai')->format('H:i');
            }
            
            // 创建合并键
            // 对于常规课中有 period_id 但没有 time_slot_id 的记录，使用特殊键来收集所有节次
            if ($record->scene !== 'evening_study' && ($isBatchPeriodRecord || ($record->period_id && !$timeSlotId && !isset($details['option_label'])))) {
                // 单独节次记录，需要合并
                $batchKey = $isBatchPeriodRecord ? '_batch_' . $record->leave_batch_id : '';
                $mergeKey = $record->student_id . '_individual_periods_' . $record->scene . $batchKey . '_' . $record->leave_type_id . '_' . $record->status . '_' . $record->approval_status;
                
                if (!isset($result[$dateKey][$mergeKey])) {
                    $result[$dateKey][$mergeKey] = [
                        'id' => $record->id,
                        'record_ids' => [$record->id],
                        'student_id' => $record->student_id,
                        'type' => $typeLabel,
                        'option' => $optionLabel,
                        'note' => $noteLabel,
                        'student_name' => $record->student?->user?->name ?? '未知学生',
                        'student_no' => $record->student?->student_no ?? '',
                        'time' => $recordTime,
                        'status' => $record->status,
                        'is_self_applied' => $record->is_self_applied ?? false,
                        'approval_status' => $record->approval_status,
                        '_period_ids' => [$record->period_id], // 收集节次ID
                        '_period_names' => [$optionLabel], // 收集节次名称
                        '_include_period_count' => $isBatchPeriodRecord,
                    ];
                } else {
                    // 追加节次与关联记录ID
                    $result[$dateKey][$mergeKey]['record_ids'][] = $record->id;
                    $result[$dateKey][$mergeKey]['_period_ids'][] = $record->period_id;
                    $result[$dateKey][$mergeKey]['_period_names'][] = $optionLabel;
                    if (empty($result[$dateKey][$mergeKey]['note']) && $noteLabel !== null) {
                        $result[$dateKey][$mergeKey]['note'] = $noteLabel;
                    }
                }
            } else {
                // 普通记录、夜自习记录或已有 option_label 的记录
                $mergeKey = $record->scene === 'evening_study'
                    ? $record->student_id . '_evening_' . ($record->period_id ?? 'no_p') . '_' . ($record->leave_type_id ?? 'no_lt') . '_' . ($record->evening_study_status_id ?? $record->requested_evening_status_id ?? 'no_es') . '_' . ($record->approval_status ?? 'none')
                    : $record->student_id . '_' . $record->scene . '_' . ($timeSlotId ?? $details['option_label'] ?? ($optionLabel !== '' ? $optionLabel : 'no_ts')) . '_' . $record->leave_type_id . '_' . $record->approval_status;
                
                $result[$dateKey][$mergeKey] = [
                    'id' => $record->id,
                    'record_ids' => [$record->id],
                    'student_id' => $record->student_id,
                    'type' => $typeLabel,
                    'option' => $optionLabel,
                    'note' => $noteLabel,
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
                if (isset($rec['_period_ids'])) {
                    $periodIds = array_values(array_unique(array_map('intval', $rec['_period_ids'])));
                    if (!empty($rec['_include_period_count']) || count($periodIds) > 1) {
                        $periodNames = array_values(array_filter(array_map(
                            fn ($periodId) => $periodMap->get($periodId)['name'] ?? null,
                            $periodIds
                        )));
                        $rec['option'] = self::formatAttendancePeriodNames($periodNames);
                        if (!empty($rec['_include_period_count']) || count($periodIds) > 1) {
                            $rec['option'] .= ' (' . count($periodIds) . '节)';
                        }
                    }
                }
                // 清理临时字段
                unset($rec['_period_ids']);
                unset($rec['_period_names']);
                unset($rec['_include_period_count']);
            }
            unset($rec);
        }
        unset($records);
        
        // 将合并后的结果转换为数组
        foreach ($result as $dateKey => $dateRecords) {
            $result[$dateKey] = array_values($dateRecords);
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
            
            $attendancePeriodsJson = \App\Models\SystemSetting::where('key', 'attendance_periods')->value('value');
            $periodMap = collect($attendancePeriodsJson ? json_decode($attendancePeriodsJson, true) : [])
                ->keyBy(fn ($period) => (int) ($period['id'] ?? 0));

            // 学生日历需要同时读取日常考勤和不计入日常统计的夜自习异常/请假记录，排除纯正常点名记录。
            $attendance = AttendanceRecord::withoutGlobalScope('day_attendance')
                ->where('student_id', $user->student->id)
                ->whereBetween('date', [$start, $end])
                ->tap(fn ($query) => self::applyCalendarAnomalyFilter($query))
                ->with(['leaveType', 'requestedEveningStatus:id,name', 'eveningStudyStatus:id,name'])
                ->orderBy('date')
                ->orderBy('period_id')
                ->get()
                ->filter(fn ($record) => self::shouldIncludeInCalendarSummary($record))
                ->values()
                ->map(function($record) {
                    // Add detail_label for display
                    $detailLabel = '';
                    $details = is_string($record->details) ? json_decode($record->details, true) : ($record->details ?? []);
                    
                    if ($record->scene === 'evening_study') {
                        $displayStatus = $record->is_self_applied
                            ? ($record->requestedEveningStatus ?? $record->eveningStudyStatus)
                            : ($record->eveningStudyStatus ?? $record->requestedEveningStatus);
                        $statusName = $displayStatus?->name
                            ?? $record->requested_status_name_snapshot
                            ?? $record->status_name_snapshot
                            ?? '已标记';
                        $detailLabel = ($record->period_name_snapshot ?: '夜自习') . '·' . $statusName;
                    }
                    // Special handling for roll_call source
                    elseif ($record->source_type === 'roll_call' && isset($details['roll_call_type'])) {
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
            
            // 合并同一天同一次申请，但保留普通节次和夜自习的各自详情。
            $grouped = [];
            foreach ($attendance as $record) {
                $details = is_string($record->details) ? json_decode($record->details, true) : ($record->details ?? []);
                $timeSlotId = $details['time_slot_id'] ?? null;
                $dateStr = is_object($record->date) ? $record->date->format('Y-m-d') : $record->date;

                if ($record->leave_batch_id) {
                    // 优先按 leave_batch_id+date 去重：同一次申请同一天只显示一条
                    $key = $dateStr . '_batch_' . $record->leave_batch_id;
                } elseif ($timeSlotId) {
                    // 旧数据兜底：按 date+time_slot_id 去重
                    $key = $dateStr . '_ts_' . $timeSlotId . '_' . $record->leave_type_id . '_' . $record->approval_status;
                } else {
                    // 无批次无时段：每条单独显示（roll_call 等）
                    $key = 'id_' . $record->id;
                }

                $grouped[$key][] = $record;
            }

            $mergedAttendance = collect($grouped)->map(function ($group) use ($periodMap) {
                $records = collect($group);
                $primaryRecord = $records->firstWhere('scene', 'regular') ?? $records->first();
                $regularRecords = $records->where('scene', '!=', 'evening_study');
                $eveningRecord = $records->firstWhere('scene', 'evening_study');

                $regularPeriodIds = $regularRecords->flatMap(function ($record) {
                    if ($record->period_id !== null) {
                        return [(int) $record->period_id];
                    }
                    $details = is_array($record->details)
                        ? $record->details
                        : (json_decode($record->details ?? '{}', true) ?? []);
                    return array_map('intval', $details['period_ids'] ?? []);
                })->unique()->sort()->values();
                $regularPeriodNames = $regularPeriodIds
                    ->map(fn ($periodId) => $periodMap->has($periodId) ? ($periodMap->get($periodId)['name'] ?? null) : null)
                    ->filter()
                    ->values()
                    ->all();
                $regularDetailLabel = !empty($regularPeriodNames)
                    ? $this->formatAttendancePeriodNames($regularPeriodNames)
                    : ($regularRecords->first()?->detail_label ?? '');
                $eveningStudyLabel = $eveningRecord?->detail_label;
                $displayEveningStatus = $eveningRecord?->is_self_applied
                    ? ($eveningRecord?->requestedEveningStatus ?? $eveningRecord?->eveningStudyStatus)
                    : ($eveningRecord?->eveningStudyStatus ?? $eveningRecord?->requestedEveningStatus);
                $combinedLabels = array_values(array_filter([$regularDetailLabel, $eveningStudyLabel]));

                $primaryRecord->detail_label = implode('；', $combinedLabels);
                $primaryRecord->regular_detail_label = $regularDetailLabel ?: null;
                $primaryRecord->regular_period_ids = $regularPeriodIds->all();
                $primaryRecord->evening_study_label = $eveningStudyLabel;
                $primaryRecord->display_evening_status = $displayEveningStatus;
                $primaryRecord->display_evening_status_name = $displayEveningStatus?->name;
                $primaryRecord->has_evening_study = $eveningRecord !== null;
                $primaryRecord->evening_destination = $eveningRecord?->destination;
                $primaryRecord->record_ids = $records->pluck('id')->all();

                return $primaryRecord;
            })->values();
                
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
     * 将节次名称数组按校园自然顺序合并连续区间：早读/早操 -> 第1-6节 -> 晚操/晚自习
     */
    public static function formatAttendancePeriodNames(array $periodNames): string
    {
        $morning = [];
        $numbered = [];
        $otherSpecial = [];

        foreach ($periodNames as $rawName) {
            $name = trim((string) $rawName);
            if ($name === '') {
                continue;
            }
            if (preg_match('/^第(\d+)节$/u', $name, $matches)) {
                $numbered[] = (int) $matches[1];
            } elseif (mb_strpos($name, '早') !== false || mb_strpos($name, '晨') !== false) {
                $morning[] = $name;
            } else {
                $otherSpecial[] = $name;
            }
        }

        $morning = array_values(array_unique($morning));
        $numbered = array_values(array_unique($numbered));
        $otherSpecial = array_values(array_unique($otherSpecial));

        $parts = $morning;
        if (!empty($numbered)) {
            sort($numbered);
            $ranges = [];
            $start = $numbered[0];
            $end = $start;
            for ($index = 1; $index < count($numbered); $index++) {
                if ($numbered[$index] === $end + 1) {
                    $end = $numbered[$index];
                    continue;
                }
                $ranges[] = $start === $end ? (string) $start : "{$start}-{$end}";
                $start = $numbered[$index];
                $end = $start;
            }
            $ranges[] = $start === $end ? (string) $start : "{$start}-{$end}";
            $parts[] = '第' . implode(',', $ranges) . '节';
        }

        return implode('、', array_merge($parts, $otherSpecial));
    }

    /**
     * 规范化并压缩手工标记/时段/节次选项文案，使异常汇总与学生自请一样简练明了
     *
     * @param array<string, mixed> $details
     * @param \Illuminate\Support\Collection|array|null $periodMap
     * @return array{option: string, note: ?string}
     */
    public static function formatCompactCalendarOption(
        array $details,
        $periodMap = null,
        ?int $recordPeriodId = null,
        ?string $sourceType = null,
        $leaveType = null
    ): array {
        $resolvePeriodNames = static function (array $details, $periodMap): array {
            $names = [];
            if (!empty($details['period_ids']) && is_array($details['period_ids'])) {
                foreach ($details['period_ids'] as $pid) {
                    $pidInt = (int) $pid;
                    $entry = null;
                    if ($periodMap instanceof \Illuminate\Support\Collection) {
                        $entry = $periodMap->get($pidInt) ?? $periodMap->get((string) $pid);
                    } elseif (is_array($periodMap)) {
                        $entry = $periodMap[$pidInt] ?? $periodMap[(string) $pid] ?? null;
                    }
                    if (is_array($entry) && !empty($entry['name'])) {
                        $names[] = (string) $entry['name'];
                    } elseif (!empty($details['period_names']) && is_array($details['period_names'])) {
                        $names = [];
                        break;
                    } else {
                        $names[] = "第{$pidInt}节";
                    }
                }
            }
            if (empty($names) && !empty($details['period_names']) && is_array($details['period_names'])) {
                foreach ($details['period_names'] as $name) {
                    $trimmed = trim((string) $name);
                    if ($trimmed !== '') {
                        $names[] = $trimmed;
                    }
                }
            }
            return array_values(array_unique($names));
        };

        $formatWithCount = static function (array $periodNames): string {
            $formatted = self::formatAttendancePeriodNames($periodNames);
            if ($formatted === '') {
                return '';
            }
            $count = count($periodNames);
            return $count > 1 ? "{$formatted} ({$count}节)" : $formatted;
        };

        $compactRawLabel = static function (string $raw): string {
            $trimmed = trim($raw);
            if ($trimmed === '') {
                return '';
            }
            if (preg_match('/^(.*?)\s*\((\d+)节\)$/u', $trimmed, $m)) {
                $inner = trim($m[1]);
                $tokens = array_values(array_filter(array_map('trim', preg_split('/[、\/]/u', $inner) ?: [])));
                if (count($tokens) >= 2 && self::looksLikePeriodNameList($tokens)) {
                    return self::formatAttendancePeriodNames($tokens) . ' (' . $m[2] . '节)';
                }
                return $trimmed;
            }
            $tokens = array_values(array_filter(array_map('trim', preg_split('/[、\/]/u', $trimmed) ?: [])));
            if (count($tokens) >= 2 && self::looksLikePeriodNameList($tokens)) {
                $formatted = self::formatAttendancePeriodNames($tokens);
                return $formatted . ' (' . count($tokens) . '节)';
            }
            return $trimmed;
        };

        $periodNames = $resolvePeriodNames($details, $periodMap);
        $note = isset($details['text']) ? trim((string) $details['text']) : '';
        $note = $note !== '' ? $note : null;

        // 1. display_label（优先，并顺手压缩可能平铺的节次）
        if (!empty($details['display_label'])) {
            $label = $compactRawLabel((string) $details['display_label']);
            if (isset($details['option_periods']) && !preg_match('/\(\d+节\)$/u', $label)) {
                $label .= ' (' . $details['option_periods'] . '节)';
            }
            return ['option' => $label, 'note' => $note];
        }

        // 2. 带自定义文本备注（如「技能训练」）+ 节次：优先用合并后的节次格式，避免超长逐节平铺
        if ($note !== null) {
            if (!empty($periodNames)) {
                $periodSummary = $formatWithCount($periodNames);
                return [
                    'option' => "{$periodSummary} · {$note}",
                    'note' => $note,
                ];
            }
            if (!empty($details['time_slot_name']) && !($details['is_custom'] ?? false)) {
                $slotName = trim((string) $details['time_slot_name']);
                if ($slotName !== '' && $slotName !== $note) {
                    return [
                        'option' => "{$slotName} · {$note}",
                        'note' => $note,
                    ];
                }
            }
            return ['option' => $note, 'note' => null];
        }

        // 3. 非自定义时段（如"上午"、"下午"、"全天"）
        if (!empty($details['time_slot_name']) && !($details['is_custom'] ?? false)) {
            return ['option' => trim((string) $details['time_slot_name']), 'note' => null];
        }

        // 4. 明确提供了 period_ids 或 period_names（合并为 第1-6节 + 节数）
        if (!empty($periodNames)) {
            return ['option' => $formatWithCount($periodNames), 'note' => null];
        }

        // 5. option_label（兼容历史平铺格式）
        if (!empty($details['option_label'])) {
            return ['option' => $compactRawLabel((string) $details['option_label']), 'note' => null];
        }

        // 6. 单节 period_id
        if ($recordPeriodId) {
            $entry = null;
            if ($periodMap instanceof \Illuminate\Support\Collection) {
                $entry = $periodMap->get($recordPeriodId) ?? $periodMap->get((string) $recordPeriodId);
            } elseif (is_array($periodMap)) {
                $entry = $periodMap[$recordPeriodId] ?? $periodMap[(string) $recordPeriodId] ?? null;
            }
            if (is_array($entry) && !empty($entry['name'])) {
                return ['option' => (string) $entry['name'], 'note' => null];
            }
        }

        // 7. 点名来源
        if ($sourceType === 'roll_call' && !empty($details['roll_call_type'])) {
            return ['option' => trim((string) $details['roll_call_type']), 'note' => null];
        }

        // 8. 预设选项配置映射
        if (!empty($details['option']) && $leaveType && $leaveType->input_config) {
            $config = is_string($leaveType->input_config)
                ? json_decode($leaveType->input_config, true)
                : $leaveType->input_config;
            if (is_array($config) && isset($config['options']) && is_array($config['options'])) {
                foreach ($config['options'] as $opt) {
                    $optKey = is_array($opt) ? ($opt['key'] ?? $opt) : $opt;
                    $optLabel = is_array($opt) ? ($opt['label'] ?? $optKey) : $optKey;
                    if ($optKey === $details['option']) {
                        return ['option' => (string) $optLabel, 'note' => null];
                    }
                }
            }
        }

        return ['option' => '', 'note' => null];
    }

    /**
     * 统一提取考勤情况摘要备注（兼容手工标记 details.text 与学生自主请假 reason / note）
     *
     * @param array<string, mixed> $details
     */
    public static function resolveCalendarSummaryNote(
        AttendanceRecord $record,
        array $details = [],
        string $optionLabel = '',
        ?string $preferredNote = null
    ): ?string {
        $candidates = [
            $preferredNote,
            $details['text'] ?? null,
            $record->reason ?? null,
            $record->note ?? null,
            $details['reason'] ?? null,
            $details['note'] ?? null,
        ];

        $cleanOption = trim($optionLabel);
        foreach ($candidates as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            $trimmed = trim($candidate);
            if ($trimmed === '' || $trimmed === $cleanOption) {
                continue;
            }
            return $trimmed;
        }

        return null;
    }

    /**
     * 判断拆分后的词元列表是否属于校园节次列表（用于安全压缩历史平铺字符串）
     *
     * @param array<int, string> $tokens
     */
    private static function looksLikePeriodNameList(array $tokens): bool
    {
        if (empty($tokens)) {
            return false;
        }
        $matchedCount = 0;
        foreach ($tokens as $token) {
            if (
                preg_match('/^第\d+(?:[-,]\d+)*节$/u', $token)
                || preg_match('/^(早读|早操|晚操|晚自习|夜自习|晨读|晨跑|午休|课间操)$/u', $token)
            ) {
                $matchedCount++;
            }
        }
        return $matchedCount === count($tokens);
    }

    /**
     * 规范化 /attendance/details 查询模式，兼容 Web 与新旧版本移动端传参
     *
     * @return array{mode: string, status: ?string, leave_type_id: ?int}
     */
    public static function resolveDetailsQueryMode(?string $status, ?int $leaveTypeId): array
    {
        $cleanStatus = is_string($status) ? trim($status) : null;
        if ($cleanStatus === '') {
            $cleanStatus = null;
        }

        if ($cleanStatus === 'absent') {
            return [
                'mode' => 'absent',
                'status' => 'absent',
                'leave_type_id' => null,
            ];
        }

        if (in_array($cleanStatus, ['late', 'early_leave'], true)) {
            return [
                'mode' => 'direct_status',
                'status' => $cleanStatus,
                'leave_type_id' => null,
            ];
        }

        // 任何请假类型查询（含旧版客户端误传 status=sick/personal/special/menstrual 等 slug）
        if ($leaveTypeId !== null && $leaveTypeId > 0) {
            return [
                'mode' => 'leave_type',
                'status' => 'leave',
                'leave_type_id' => $leaveTypeId,
            ];
        }

        return [
            'mode' => 'fallback',
            'status' => $cleanStatus,
            'leave_type_id' => $leaveTypeId,
        ];
    }

    /**
     * 将 /attendance/details 中单个学生的记录按「同日同批次」合并去重，
     * 避免跨多天/多节次请假被统计为“45次”并刷出重复单日行。
     *
     * @param iterable<int, AttendanceRecord> $records
     * @param array<int, array<string, mixed>> $periodMap
     * @return array{detail: string, records: array<int, array<string, mixed>>, day_count: int, period_count: int}
     */
    public static function summarizeStudentDetailRecords(iterable $records, string $scope, array $periodMap = []): array
    {
        $sourceLabels = [
            'roll_call' => '点名',
            'manual' => '标记',
            'manual_bulk' => '标记',
            'leave_request' => '申请',
            'self_applied' => '申请',
        ];

        $items = [];
        foreach ($records as $record) {
            $items[] = $record;
        }

        usort($items, static function (AttendanceRecord $a, AttendanceRecord $b): int {
            $da = $a->date instanceof \Carbon\Carbon ? $a->date->format('Y-m-d') : (string) $a->date;
            $db = $b->date instanceof \Carbon\Carbon ? $b->date->format('Y-m-d') : (string) $b->date;
            if ($da !== $db) {
                return strcmp($da, $db);
            }
            return ((int) ($a->period_id ?? 0)) <=> ((int) ($b->period_id ?? 0));
        });

        $grouped = [];
        foreach ($items as $record) {
            $dateKey = $record->date instanceof \Carbon\Carbon
                ? $record->date->format('Y-m-d')
                : substr((string) $record->date, 0, 10);

            $details = is_string($record->details) ? json_decode($record->details, true) : $record->details;
            if (!is_array($details)) {
                $details = [];
            }

            $sourceType = $record->source_type ?? 'manual';
            if ($record->is_self_applied) {
                $sourceType = 'self_applied';
            }
            $scene = $record->scene ?? 'regular';

            if (!empty($record->leave_batch_id)) {
                $groupKey = implode('|', [
                    $dateKey,
                    'batch:' . $record->leave_batch_id,
                    $scene,
                    (string) ($record->leave_type_id ?? ''),
                    (string) $record->status,
                ]);
            } elseif ($record->period_id !== null && $sourceType !== 'roll_call') {
                $groupKey = implode('|', [
                    $dateKey,
                    'periods',
                    $scene,
                    (string) ($record->leave_type_id ?? ''),
                    (string) $record->status,
                    $sourceType,
                    (string) ($record->approval_status ?? ''),
                ]);
            } else {
                $optionKey = (string) ($details['option'] ?? $details['display_label'] ?? $details['roll_call_type'] ?? '');
                $periodIdsKey = isset($details['period_ids']) && is_array($details['period_ids'])
                    ? implode(',', array_map('intval', $details['period_ids']))
                    : '';
                $groupKey = implode('|', [
                    $dateKey,
                    'single',
                    $scene,
                    (string) ($record->leave_type_id ?? ''),
                    (string) $record->status,
                    $sourceType,
                    $optionKey,
                    $periodIdsKey,
                    (string) ($details['text'] ?? ''),
                ]);
            }

            if (!isset($grouped[$groupKey])) {
                $grouped[$groupKey] = [
                    'primary' => $record,
                    'date' => $dateKey,
                    'source_type' => $sourceType,
                    'details' => $details,
                    'records' => [$record],
                    'period_ids' => [],
                ];
            } else {
                $grouped[$groupKey]['records'][] = $record;
            }

            if ($record->period_id !== null) {
                $grouped[$groupKey]['period_ids'][] = (int) $record->period_id;
            } elseif (!empty($details['period_ids']) && is_array($details['period_ids'])) {
                foreach ($details['period_ids'] as $pid) {
                    $grouped[$groupKey]['period_ids'][] = (int) $pid;
                }
            } elseif (!empty($details['periods']) && is_array($details['periods'])) {
                foreach ($details['periods'] as $pid) {
                    $grouped[$groupKey]['period_ids'][] = (int) $pid;
                }
            }
        }

        $enrichedRecords = [];
        $todayParts = [];
        $uniqueDates = [];
        $totalPeriods = 0;
        $hasExplicitPeriods = false;

        foreach ($grouped as $group) {
            /** @var AttendanceRecord $primary */
            $primary = $group['primary'];
            $dateKey = $group['date'];
            $sourceType = $group['source_type'];
            $sourceLabel = $sourceLabels[$sourceType] ?? '标记';
            $details = $group['details'];
            $uniqueDates[$dateKey] = true;

            $periodIds = array_values(array_unique(array_filter($group['period_ids'], fn ($id) => $id > 0)));
            $periodNames = [];
            foreach ($periodIds as $pid) {
                if (isset($periodMap[$pid]['name'])) {
                    $periodNames[] = (string) $periodMap[$pid]['name'];
                } elseif (isset($periodMap[(string) $pid]['name'])) {
                    $periodNames[] = (string) $periodMap[(string) $pid]['name'];
                } else {
                    $periodNames[] = "第{$pid}节";
                }
            }
            if (empty($periodNames) && !empty($details['period_names']) && is_array($details['period_names'])) {
                $periodNames = array_values(array_unique(array_filter(array_map(
                    fn ($n) => trim((string) $n),
                    $details['period_names']
                ))));
            }

            $groupPeriodCount = 0;
            if (!empty($periodIds)) {
                $groupPeriodCount = count($periodIds);
                $hasExplicitPeriods = true;
            } elseif (!empty($periodNames)) {
                $groupPeriodCount = count($periodNames);
                $hasExplicitPeriods = true;
            } elseif (isset($details['option_periods']) && is_numeric($details['option_periods']) && (int) $details['option_periods'] > 0) {
                $groupPeriodCount = (int) $details['option_periods'];
                $hasExplicitPeriods = true;
            } elseif ($sourceType === 'roll_call') {
                $groupPeriodCount = count($group['records']);
                $hasExplicitPeriods = true;
            }
            $totalPeriods += $groupPeriodCount;

            $detailContent = '';
            if ($sourceType === 'roll_call') {
                if (!empty($details['roll_call_type'])) {
                    $detailContent = (string) $details['roll_call_type'];
                    if (!empty($details['roll_call_time'])) {
                        $detailContent .= '(' . $details['roll_call_time'] . ')';
                    }
                } else {
                    $detailContent = '点名记录';
                }
            } else {
                $compact = self::formatCompactCalendarOption(
                    array_merge($details, !empty($periodIds) ? ['period_ids' => $periodIds] : []),
                    $periodMap,
                    count($periodIds) === 1 ? $periodIds[0] : ($primary->period_id ? (int) $primary->period_id : null),
                    $sourceType,
                    $primary->relationLoaded('leaveType') ? $primary->leaveType : null
                );

                if (!empty($periodNames)) {
                    $mergedPeriodStr = self::formatAttendancePeriodNames($periodNames);
                    $countSuffix = count($periodNames) > 1 ? ' (' . count($periodNames) . '节)' : '';
                    $periodText = $mergedPeriodStr . $countSuffix;
                    $noteText = isset($details['text']) ? trim((string) $details['text']) : '';
                    if ($noteText !== '' && $noteText !== $mergedPeriodStr) {
                        $detailContent = "{$periodText} · {$noteText}";
                    } else {
                        $detailContent = $periodText;
                    }
                } elseif ($compact['option'] !== '') {
                    $detailContent = $compact['option'];
                } elseif (!empty($details['option'])) {
                    $optionMap = [
                        'morning_half' => '上午',
                        'afternoon_half' => '下午',
                        'full_day' => '全天',
                        'morning_exercise' => '早操',
                        'evening_exercise' => '晚操',
                    ];
                    $detailContent = $optionMap[$details['option']] ?? (string) $details['option'];
                } else {
                    $detailContent = '全天';
                }
            }

            $lineDetail = $sourceLabel . '：' . $detailContent;
            $todayParts[] = $lineDetail;

            $recordArray = $primary->toArray();
            $recordArray['date'] = $dateKey;
            $recordArray['detail'] = $lineDetail;
            $recordArray['period_names_display'] = $detailContent;
            $recordArray['period_count'] = $groupPeriodCount;
            if (!empty($periodIds)) {
                $recordDetails = is_array($recordArray['details'] ?? null) ? $recordArray['details'] : $details;
                $recordDetails['period_ids'] = $periodIds;
                $recordArray['details'] = $recordDetails;
            }
            $enrichedRecords[] = $recordArray;
        }

        $dayCount = count($uniqueDates);
        if ($scope === 'today') {
            $detailText = implode(' | ', $todayParts);
        } elseif ($hasExplicitPeriods && $totalPeriods > 0) {
            $detailText = $dayCount > 0 ? "{$dayCount}天 · {$totalPeriods}节" : "{$totalPeriods}节";
        } else {
            $detailText = $dayCount > 0 ? "{$dayCount}天" : (count($enrichedRecords) . '次');
        }

        return [
            'detail' => $detailText !== '' ? $detailText : '-',
            'records' => $enrichedRecords,
            'day_count' => $dayCount,
            'period_count' => $totalPeriods,
        ];
    }

    private function attendanceBatchDisplayKey(AttendanceRecord $record): string
    {
        $date = $record->date instanceof \Carbon\Carbon
            ? $record->date->format('Y-m-d')
            : (string) $record->date;

        return implode(':', [
            $record->student_id,
            $record->leave_batch_id,
            $date,
            $record->scene,
            $record->leave_type_id,
            $record->status,
            $record->approval_status ?? 'none',
        ]);
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

            // Helper function to count working days in a range.
            // When semester.holidays is configured, it already contains ALL non-school days
            // (normal weekends + national holidays, EXCLUDING makeup school weekends like 调休).
            // So: any day NOT in holidays = school day.
            // Fallback to weekday-only when holidays is not configured.
            $countWorkingDays = function($start, $end) use ($holidays) {
                if (!$start || !$end) return 0;
                $count = 0;
                $current = $start->copy();
                $maxDays = 400;
                $dayCount = 0;
                $useHolidayList = !empty($holidays);
                while ($current <= $end && $dayCount < $maxDays) {
                    $dateStr = $current->format('Y-m-d');
                    if ($useHolidayList) {
                        if (!in_array($dateStr, $holidays)) {
                            $count++;
                        }
                    } else {
                        $dayOfWeek = $current->dayOfWeek;
                        if ($dayOfWeek !== 0 && $dayOfWeek !== 6) {
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
                // Today is always 1 day regardless of weekday/weekend
                $denominatorWorkingDays = 1;
            } elseif ($scope === 'week') {
                $statsStartDate = now()->startOfWeek(\Carbon\Carbon::MONDAY);
                $statsEndDate = now()->endOfDay();
                $denominatorWorkingDays = $countWorkingDays($statsStartDate, $statsEndDate);
            } elseif ($scope === 'month') {
                $statsStartDate = now()->startOfMonth();
                $statsEndDate = now()->endOfDay();
                $denominatorWorkingDays = $countWorkingDays($statsStartDate, $statsEndDate);
            } elseif ($scope === 'semester') {
                if ($semester && $semester->start_date) {
                    $semesterStart = \Carbon\Carbon::parse($semester->start_date);
                    $semesterEnd = $semesterStart->copy()->addWeeks($semester->total_weeks ?? 20)->subDay();
                    $statsStartDate = $semesterStart;
                    $statsEndDate = min($today, $semesterEnd);
                    $denominatorWorkingDays = $countWorkingDays($semesterStart, $semesterEnd);
                } else {
                    $statsStartDate = now()->startOfMonth();
                    $statsEndDate = now()->endOfDay();
                    $denominatorWorkingDays = $countWorkingDays($statsStartDate, $statsEndDate);
                    \Log::warning('studentStats: No semester found, using month fallback');
                }
            }

            // Safety check: only fall back for non-today scopes when working_days is still 0
            if ($denominatorWorkingDays <= 0 && $scope !== 'today') {
                \Log::warning('studentStats: denominatorWorkingDays is 0, scope=' . $scope . ', falling back to month');
                $statsStartDate = now()->startOfMonth();
                $statsEndDate = now()->endOfDay();
                $denominatorWorkingDays = $countWorkingDays($statsStartDate, $statsEndDate);
            }

            // Numerator working days (same range; for today always 1)
            $numeratorWorkingDays = ($scope === 'today') ? 1 : $countWorkingDays($statsStartDate, $statsEndDate);

            // Build base query for the stats period
            $query = AttendanceRecord::where('student_id', $studentId)
                ->whereBetween('date', [$statsStartDate->format('Y-m-d'), $statsEndDate->format('Y-m-d')]);

            // Get system settings for conversion
            $absentLessonsAsDay = (int) (\App\Models\SystemSetting::where('key', 'absent_lessons_as_day')->value('value') ?? 6);
            $leaveLessonsAsDay = (int) (\App\Models\SystemSetting::where('key', 'leave_periods_as_day')->value('value') ?? 6);
            if ($absentLessonsAsDay < 1) $absentLessonsAsDay = 6;
            if ($leaveLessonsAsDay < 1) $leaveLessonsAsDay = 6;

            // Count full-day leaves (period_id is null AND no period_ids in details, approved)
            // Exclude: absent(旷课), late(迟到), early_leave(早退), health_leave/period_leave(生理假)
            // Also exclude records that have period_ids in details (those are period-based, not full-day)
            $fullDayLeaveDates = $query->clone()
                ->whereIn('status', ['leave', 'excused'])
                ->whereNull('period_id')
                ->where(function($q) {
                    $q->where('approval_status', 'approved')
                      ->orWhereNull('approval_status');
                })
                // Exclude records that have period_ids in details (these are period-based leaves stored differently)
                ->where(function($q) {
                    $q->whereNull('details')
                      ->orWhereRaw("JSON_LENGTH(JSON_EXTRACT(details, '$.period_ids')) IS NULL")
                      ->orWhereRaw("JSON_LENGTH(JSON_EXTRACT(details, '$.period_ids')) = 0");
                })
                // Exclude non-leave types
                ->whereHas('leaveType', function($q) {
                    $q->whereNotIn('slug', ['absent', 'late', 'early_leave', 'period_leave', 'health_leave']);
                })
                ->distinct()
                ->pluck('date')
                ->count();

            // Count period-based leaves
            // 1. Records with period_id NOT null (one record per period)
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
            
            // 2. Records with period_id = null BUT period_ids in details (stored differently)
            // Need to count the number of periods stored in details.period_ids
            $detailsBasedLeaves = $query->clone()
                ->whereIn('status', ['leave', 'excused'])
                ->whereNull('period_id')
                ->where(function($q) {
                    $q->where('approval_status', 'approved')
                      ->orWhereNull('approval_status');
                })
                ->whereRaw("JSON_LENGTH(JSON_EXTRACT(details, '$.period_ids')) > 0")
                ->whereHas('leaveType', function($q) {
                    $q->whereNotIn('slug', ['absent', 'late', 'early_leave', 'period_leave', 'health_leave']);
                })
                ->get();
            
            // Sum up period_ids counts from these records
            foreach ($detailsBasedLeaves as $record) {
                $details = is_string($record->details) ? json_decode($record->details, true) : ($record->details ?? []);
                if (isset($details['period_ids']) && is_array($details['period_ids'])) {
                    $periodLeavePeriods += count($details['period_ids']);
                }
            }

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

            // Determine if today is a rest day (only meaningful for today scope)
            $isTodayRestDay = false;
            if ($scope === 'today') {
                $todayStr = now()->format('Y-m-d');
                if (!empty($holidays)) {
                    $isTodayRestDay = in_array($todayStr, $holidays);
                } else {
                    // Fallback when no holidays configured: treat weekends as rest days
                    $isTodayRestDay = in_array(now()->dayOfWeek, [0, 6]);
                }
            }

            // Build response with leave type counts
            $stats = [
                'present' => $presentDays,
                'working_days' => $denominatorWorkingDays,
                'absent_lessons_as_day' => $absentLessonsAsDay,
                'is_rest_day' => $isTodayRestDay,
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
                $stats['pending_requests'] = (int) AttendanceRecord::where('class_id', $classId)
                    ->where('approval_status', 'pending')
                    ->where('is_self_applied', true)
                    ->selectRaw("COUNT(DISTINCT COALESCE(leave_batch_id, CONCAT(student_id, '_', `date`, '_', COALESCE(leave_type_id, '0')))) as total")
                    ->value('total');
            }

            // Include leave types config for frontend
            $stats['_leave_types_config'] = $leaveTypesConfig;

            return response()->json($stats)
                ->header('Cache-Control', 'no-store, no-cache, must-revalidate')
                ->header('Pragma', 'no-cache');
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
            } elseif ($scope === 'week') {
                $startDate = now()->startOfWeek(\Carbon\Carbon::MONDAY);
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
                        
                        // Priority 1: display_label (for custom period selections)
                        if (isset($details['display_label'])) {
                            $detailLabel = $details['display_label'];
                        }
                        // Priority 2: text (for text input types like "特殊")
                        elseif (isset($details['text'])) {
                            $detailLabel = $details['text'];
                            // If has period names, append them
                            if (isset($details['period_names']) && is_array($details['period_names']) && count($details['period_names']) > 0) {
                                $detailLabel .= '-' . implode('、', $details['period_names']);
                            }
                        }
                        // Priority 3: time_slot_name
                        elseif (isset($details['time_slot_name'])) {
                            $detailLabel = $details['time_slot_name'];
                        }
                        // Priority 4: option (for select input types)
                        elseif ($record->details && $record->leaveType && $record->leaveType->input_config) {
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

                        // Priority 5: period_id / period_ids → period name (fallback for manual marks)
                        if (!$detailLabel) {
                            $periodsConfig = \DB::table('system_settings')->where('key', 'attendance_periods')->value('value');
                            $periodsConfig = $periodsConfig ? json_decode($periodsConfig, true) : [];
                            $periodIds = [];
                            if (isset($details['period_ids']) && is_array($details['period_ids'])) {
                                $periodIds = $details['period_ids'];
                            } elseif (isset($details['periods']) && is_array($details['periods'])) {
                                $periodIds = $details['periods'];
                            } elseif ($record->period_id) {
                                $periodIds = [$record->period_id];
                            }
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

    public function bulkStore(Request $request, RollCallAttendanceSyncService $rollCallAttendanceSync)
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
                            // 保留 text 字段（文本输入类型）
                            'text' => $details['text'] ?? null,
                            'text_label' => $details['text_label'] ?? null,
                        ],
                        'source_type' => 'manual_bulk',
                    ]
                );
                
                if ($record) {
                    if (in_array($record->status, ['leave', 'excused'], true) && $record->leave_type_id) {
                        $rollCallAttendanceSync->replaceAbsenceWithLeave($record);
                    }
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
                    if (in_array($record->status, ['leave', 'excused'], true) && $record->leave_type_id) {
                        $rollCallAttendanceSync->replaceAbsenceWithLeave($record);
                    }
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
            'record_id' => 'nullable|integer|exists:attendance_records,id',
            'student_id' => 'required|exists:students,id',
            'date' => 'required|date',
            'period_id' => 'nullable|integer',
            'option' => 'nullable|string',
            'source_type' => 'nullable|string',
            'source_id' => 'nullable|integer',
            'leave_batch_id' => 'nullable|string',
            'scene' => 'nullable|in:regular,evening_study',
            'delete_scope' => 'nullable|in:record,scene_date,batch',
        ]);

        // 考勤标记页中的一个徽标代表同一天、同一场景的一组记录。
        // 撤销该徽标时只删除对应分组，不能连带删除同批次的另一场景。
        if ($request->leave_batch_id
            && $request->source_type === 'self_applied'
            && $request->delete_scope === 'scene_date') {
            $count = AttendanceRecord::withoutGlobalScope('day_attendance')
                ->where('leave_batch_id', $request->leave_batch_id)
                ->where('student_id', $request->student_id)
                ->whereDate('date', $request->date)
                ->where('scene', $request->input('scene', 'regular'))
                ->delete();

            return response()->json(['message' => '对应考勤分组已撤销', 'deleted_count' => $count]);
        }

        // 其他明确要求整批撤销的旧调用保持原行为。
        if ($request->leave_batch_id && $request->source_type === 'self_applied') {
            $count = AttendanceRecord::withoutGlobalScope('day_attendance')
                ->where('leave_batch_id', $request->leave_batch_id)
                ->where('student_id', $request->student_id)
                ->delete();
            return response()->json(['message' => '请假记录已整批撤销', 'deleted_count' => $count]);
        }

        // 构建查询
        $query = AttendanceRecord::withoutGlobalScope('day_attendance');
        if ($request->record_id) {
            $query->whereKey($request->record_id)
                ->where('student_id', $request->student_id);
        } else {
            $query->where('student_id', $request->student_id)
                ->where('date', $request->date);
        }

        // 如果是点名来源的记录，按 source_type 和 source_id 匹配
        if (!$request->record_id && $request->source_type === 'roll_call' && $request->source_id) {
            $query->where('source_type', 'roll_call')
                  ->where('source_id', $request->source_id);
        } elseif (!$request->record_id) {
            // 普通记录按 period_id 匹配
            $query->where('period_id', $request->period_id);
        }

        // 如果提供了 option，按 option 过滤
        if ($request->has('option') && $request->option) {
            $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(details, '$.option')) = ?", [$request->option]);
        }

        $record = $query->first();

        if (!$record) {
            return response()->json(['message' => 'Record not found.'], 404);
        }

        // self_applied 记录按调用方指定范围删除。
        if ($record->source_type === 'self_applied' && $record->leave_batch_id) {
            $batchQuery = AttendanceRecord::withoutGlobalScope('day_attendance')
                ->where('leave_batch_id', $record->leave_batch_id)
                ->where('student_id', $record->student_id);

            if ($request->delete_scope === 'scene_date') {
                $batchQuery->whereDate('date', $record->date)
                    ->where('scene', $request->input('scene', $record->scene ?? 'regular'));
            }

            $count = $batchQuery->delete();
            return response()->json([
                'message' => $request->delete_scope === 'scene_date'
                    ? '对应考勤分组已撤销'
                    : '请假记录已整批撤销',
                'deleted_count' => $count,
            ]);
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
                AttendanceRecord::withoutGlobalScope('day_attendance')
                    ->where('source_type', 'leave_request')
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
            $deletedCount = AttendanceRecord::withoutGlobalScope('day_attendance')
                ->where('source_type', 'roll_call')
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
                    'leave_type_id' => null,
                    'leave_detail' => null,
                    'leave_status' => 'manual_override',
                    'marked_at' => now(),
                ]);
                
                // 更新 roll_call 的统计
                $rollCall = \App\Models\RollCall::find($rollCallId);
                if ($rollCall) {
                    $presentCount = \App\Models\RollCallRecord::where('roll_call_id', $rollCallId)
                        ->where('status', 'present')
                        ->count();
                    $onLeaveCount = \App\Models\RollCallRecord::where('roll_call_id', $rollCallId)
                        ->where('status', 'on_leave')
                        ->count();
                    $rollCall->update([
                        'present_count' => $presentCount,
                        'on_leave_count' => $onLeaveCount,
                    ]);
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
     * Get attendance details for 'present' status card
     * Returns all students with their attendance count and rate
     */
    private function getAttendanceDetails($request, $classIds, $dateRange, $scope, $filterType = null)
    {
        $user = $request->user();
        
        // Get absence threshold
        $absentThreshold = (int) (\App\Models\SystemSetting::where('key', 'absent_lessons_as_day')->value('value') ?? 6);
        
        // Get semester for holiday calendar
        $semesterId = $request->input('semester_id');
        $semester = $semesterId 
            ? \App\Models\Semester::find($semesterId) 
            : \App\Models\Semester::where('is_current', true)->first();
        
        // Get holidays from semester
        $holidays = [];
        if ($semester && $semester->holidays) {
            $holidays = is_string($semester->holidays) 
                ? json_decode($semester->holidays, true) ?? []
                : $semester->holidays ?? [];
        }
        
        // Helper function to count working days in a range
        // 完全根据学期日历配置判断：只要不在 holidays 数组中就是工作日
        $countWorkingDays = function($start, $end) use ($holidays) {
            if (!$start || !$end) return 0;
            $count = 0;
            $current = \Carbon\Carbon::parse($start)->startOfDay();
            $endDate = \Carbon\Carbon::parse($end)->endOfDay();
            $maxDays = 400;
            $dayCount = 0;
            while ($current <= $endDate && $dayCount < $maxDays) {
                $dateStr = $current->format('Y-m-d');
                // 只要不在假日列表中就是工作日（包括调休的周末）
                if (!in_array($dateStr, $holidays)) {
                    $count++;
                }
                $current->addDay();
                $dayCount++;
            }
            return $count;
        };
        
        // Get all students in the classes
        $students = \App\Models\Student::whereIn('class_id', $classIds)
            ->with(['user', 'schoolClass.department'])
            ->orderBy('student_no')
            ->get();
        
        // Calculate total working days in the scope (截至今天)
        $endDate = min($dateRange['end'], now()->format('Y-m-d'));
        $totalDays = $countWorkingDays($dateRange['start'], $endDate);
        
        // 获取"不计入缺勤"的请假类型ID列表
        $nonAbsenceLeaveTypeIds = \App\Models\LeaveType::where('counts_as_absence', false)->pluck('id')->toArray();
        
        // Get all period_id = NULL absence records
        // 排除 counts_as_absence = false 的请假类型
        $nullPeriodRecords = \DB::table('attendance_records')
            ->whereIn('class_id', $classIds)
            ->whereBetween('date', [$dateRange['start'], min($dateRange['end'], now()->format('Y-m-d'))])
            ->where('counts_in_day_stats', true)
            ->whereIn('status', ['absent', 'leave', 'excused'])
            ->whereNull('period_id')
            ->where(function($q) use ($nonAbsenceLeaveTypeIds) {
                $q->whereNull('leave_type_id')
                  ->orWhereNotIn('leave_type_id', $nonAbsenceLeaveTypeIds);
            })
            ->select('student_id', 'date', 'details')
            ->get();
        
        // Categorize: real full-day vs period-based
        $fullDayAbsences = collect();
        $periodAbsencesFromNull = collect();
        
        foreach ($nullPeriodRecords as $record) {
            $details = is_string($record->details) ? json_decode($record->details, true) : $record->details;
            
            $hasPeriodIds = is_array($details) && 
                           isset($details['period_ids']) && 
                           is_array($details['period_ids']) && 
                           count($details['period_ids']) > 0;
            
            $option = $details['option'] ?? null;
            $isFullDayOption = in_array($option, ['full_day', 'all_day', 'morning_half', 'afternoon_half']);
            
            if ($hasPeriodIds && !$isFullDayOption) {
                // Period-based absence with period_id=NULL
                $periodAbsencesFromNull->push([
                    'student_id' => $record->student_id,
                    'date' => $record->date,
                    'period_count' => count($details['period_ids'])
                ]);
            } else {
                // Real full-day absence
                $fullDayAbsences->push([
                    'student_id' => $record->student_id,
                    'date' => $record->date
                ]);
            }
        }
        
        // Get period_id IS NOT NULL absences that meet threshold
        // 排除 counts_as_absence = false 的请假类型
        $periodAbsences = \DB::table('attendance_records')
            ->whereIn('class_id', $classIds)
            ->whereBetween('date', [$dateRange['start'], min($dateRange['end'], now()->format('Y-m-d'))])
            ->where('counts_in_day_stats', true)
            ->whereIn('status', ['absent', 'leave', 'excused'])
            ->whereNotNull('period_id')
            ->where(function($q) use ($nonAbsenceLeaveTypeIds) {
                $q->whereNull('leave_type_id')
                  ->orWhereNotIn('leave_type_id', $nonAbsenceLeaveTypeIds);
            })
            ->select('student_id', 'date', \DB::raw('COUNT(*) as period_count'))
            ->groupBy('student_id', 'date')
            ->having('period_count', '>=', $absentThreshold)
            ->get();
        
        // Process period_id=NULL but actually period-based records
        $periodAbsentFromNullStudentDates = $periodAbsencesFromNull
            ->groupBy(fn($r) => $r['date'] . '-' . $r['student_id'])
            ->filter(fn($records) => $records->sum('period_count') >= $absentThreshold)
            ->map(function($records) {
                $first = $records->first();
                return ['student_id' => $first['student_id'], 'date' => $first['date']];
            })
            ->values();
        
        // Merge and calculate absent days per student
        $allAbsences = $fullDayAbsences
            ->merge($periodAbsentFromNullStudentDates)
            ->merge($periodAbsences->map(fn($r) => ['student_id' => $r->student_id, 'date' => $r->date]));
        
        // Calculate absent days per student (unique student-date combinations)
        $absentDaysByStudent = $allAbsences->groupBy('student_id')->map(function($records) {
            return collect($records)->pluck('date')->unique()->count();
        });
        
        // Get any attendance record count per student (for "has_record" filter)
        $recordsByStudent = \DB::table('attendance_records')
            ->whereIn('class_id', $classIds)
            ->whereBetween('date', [$dateRange['start'], min($dateRange['end'], now()->format('Y-m-d'))])
            ->where('counts_in_day_stats', true)
            ->select('student_id', \DB::raw('COUNT(*) as record_count'))
            ->groupBy('student_id')
            ->pluck('record_count', 'student_id');
        
        // Build result
        $result = $students->map(function($student) use ($totalDays, $absentDaysByStudent, $recordsByStudent, $user) {
            $absentDays = $absentDaysByStudent->get($student->id, 0);
            $presentDays = max(0, $totalDays - $absentDays);
            $attendanceRate = $totalDays > 0 ? round(($presentDays / $totalDays) * 100, 1) : 100;
            $hasRecords = $recordsByStudent->get($student->id, 0) > 0;
            
            $data = [
                'id' => $student->id,
                'student_no' => $student->student_no,
                'name' => $student->user->name ?? '未知',
                'class_name' => $student->schoolClass->name ?? '-',
                'department_name' => $student->schoolClass->department->name ?? '-',
                'present_days' => $presentDays,
                'total_days' => $totalDays,
                'attendance_rate' => $attendanceRate,
                'is_present' => $absentDays == 0, // No absent days = fully present
                'has_records' => $hasRecords,
            ];
            
            return $data;
        });
        
        // Apply filter if specified
        if ($filterType === 'present') {
            // 出勤：没有达到缺勤阈值的学生
            $result = $result->filter(fn($s) => $s['is_present']);
        } elseif ($filterType === 'absent') {
            // 缺勤：有达到缺勤阈值的学生
            $result = $result->filter(fn($s) => !$s['is_present']);
        }
        
        // Get available departments and classes for filters
        $availableDepartments = [];
        $availableClasses = [];
        
        if (in_array($user->role, ['system_admin', 'school_admin', 'admin'])) {
            $availableDepartments = \App\Models\Department::select('id', 'name')->get();
            $availableClasses = \App\Models\SchoolClass::whereIn('id', $classIds)
                ->select('id', 'name', 'department_id')
                ->with('department:id,name')
                ->get();
        } elseif (in_array($user->role, ['department_manager', 'manager'])) {
            $deptIds = $user->managedDepartments->pluck('id');
            $availableClasses = \App\Models\SchoolClass::whereIn('department_id', $deptIds)
                ->select('id', 'name', 'department_id')
                ->with('department:id,name')
                ->get();
        }
        
        return response()->json([
            'students' => $result->values(),
            'total_days' => $totalDays,
            'filters' => [
                'departments' => $availableDepartments,
                'classes' => $availableClasses,
            ],
            'user_role' => $user->role,
        ]);
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
        
        // Optional filters for present status
        $departmentId = $request->input('department_id');
        $classId = $request->input('class_id');
        $filterType = $request->input('filter_type'); // 'all', 'present', 'has_record'

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
        
        // Apply optional filters
        if ($classId) {
            $classIds = collect([$classId])->intersect($classIds);
        } elseif ($departmentId) {
            $deptClassIds = \App\Models\SchoolClass::where('department_id', $departmentId)->pluck('id');
            $classIds = $classIds->intersect($deptClassIds);
        }

        // Special handling for status='present' - attendance list
        if ($status === 'present') {
            return $this->getAttendanceDetails($request, $classIds, $dateRange, $scope, $filterType);
        }

        $queryMode = self::resolveDetailsQueryMode(
            is_string($status) ? $status : null,
            $leaveTypeId !== null && $leaveTypeId !== '' ? (int) $leaveTypeId : null
        );

        // For 'absent' status, we need to include roll_call source records with leave_type.slug='absent'
        $absenceLeaveTypeId = null;
        if ($queryMode['mode'] === 'absent') {
            $absenceLeaveType = \App\Models\LeaveType::where('slug', 'absent')->first();
            $absenceLeaveTypeId = $absenceLeaveType?->id;
        }

        // Build the attendance query conditions (shared between whereHas & eager-load)
        $attendanceCondition = function($q) use ($dateRange, $queryMode, $absenceLeaveTypeId) {
            $q->whereBetween('date', [$dateRange['start'], $dateRange['end']]);

            if ($queryMode['mode'] === 'absent' && $absenceLeaveTypeId) {
                // For absent: include both status='absent' AND (source_type='roll_call' with leave_type_id=absent)
                $q->where(function($q2) use ($absenceLeaveTypeId) {
                    $q2->where('status', 'absent')
                       ->orWhere(function($q3) use ($absenceLeaveTypeId) {
                           $q3->where('source_type', 'roll_call')
                              ->where('leave_type_id', $absenceLeaveTypeId);
                       });
                });
            } elseif ($queryMode['mode'] === 'leave_type' && $queryMode['leave_type_id']) {
                // For leave types: include both 'leave' and 'excused' status with the specified leave_type_id
                // Also filter out rejected requests (approval_status != 'rejected')
                $q->whereIn('status', ['leave', 'excused'])
                  ->where('leave_type_id', $queryMode['leave_type_id'])
                  ->where(function($q2) {
                      $q2->where('approval_status', 'approved')
                         ->orWhereNull('approval_status');
                  });
            } elseif ($queryMode['mode'] === 'direct_status' && $queryMode['status']) {
                $q->where('status', $queryMode['status']);
            } else {
                if ($queryMode['status']) {
                    $q->where('status', $queryMode['status']);
                }
                if ($queryMode['leave_type_id']) {
                    $q->where('leave_type_id', $queryMode['leave_type_id']);
                }
            }
        };

        // Query students with matching attendance records
        $students = \App\Models\Student::whereIn('class_id', $classIds)
            ->whereHas('attendance', $attendanceCondition)
            ->with([
                'user',
                'schoolClass',
                'attendance' => function($q) use ($attendanceCondition) {
                    $attendanceCondition($q);
                    $q->with(['leaveType']);
                }
            ])
            ->orderBy('student_no')
            ->get();
        
        // Load department separately to avoid table name issues
        $students->load('schoolClass.department');

        // 一次性加载节次配置，避免循环内重复查询数据库
        $periodsConfig = \App\Models\SystemSetting::where('key', 'attendance_periods')->value('value');
        $periodsArray = json_decode($periodsConfig ?: '[]', true) ?: [];
        $periodMap = [];
        foreach ($periodsArray as $p) {
            if (isset($p['id'])) {
                $periodMap[(int) $p['id']] = $p;
            }
        }

        // Format response
        $result = $students->map(function($student) use ($scope, $periodMap) {
            try {
                $summary = self::summarizeStudentDetailRecords($student->attendance, $scope, $periodMap);

                return [
                    'id' => $student->id,
                    'student_no' => $student->student_no ?? '',
                    'name' => $student->user?->name ?? '-',
                    'department' => $student->schoolClass?->department?->name ?? '-',
                    'class' => $student->schoolClass?->name ?? '-',
                    'detail' => $summary['detail'],
                    'records' => $summary['records'],
                ];
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

        if (!$studentId) {
            return response()->json(['error' => 'student_id is required'], 400);
        }

        // Custom date range support
        if ($scope === 'custom') {
            $startDate = $request->input('start_date');
            $endDate   = $request->input('end_date');
            if (!$startDate || !$endDate) {
                return response()->json(['error' => 'start_date and end_date are required for custom scope'], 400);
            }
            $dateRange = ['start' => $startDate, 'end' => $endDate];
        } else {
            $dateRange = $this->getDateRangeForScope($scope);
        }
        
        \Log::info('[studentRecords] Date range:', $dateRange);

        // Get all abnormal attendance records for this student (exclude present = normal state)
        $records = \App\Models\AttendanceRecord::where('student_id', $studentId)
            ->whereBetween('date', [$dateRange['start'], $dateRange['end']])
            ->where('status', '!=', 'present')
            ->with(['leaveType'])
            ->orderBy('date', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();
            
        \Log::info('[studentRecords] Found records:', [
            'count' => $records->count(),
            'student_id' => $studentId
        ]);

        // 获取节次配置，用于转换ID/编号为名称
        $periodsConfig = \App\Models\SystemSetting::where('key', 'attendance_periods')->value('value');
        $periodsArray = json_decode($periodsConfig, true) ?: [];
        
        // 来源类型标签映射（与 details 方法一致）
        $sourceLabels = [
            'roll_call' => '点名',
            'manual' => '标记',
            'manual_bulk' => '标记',
            'leave_request' => '申请',
            'self_applied' => '申请',  // 自主申请
        ];
        
        // 去重：按 date + leave_type_id + option + period_ids + source_type 去重
        $uniqueRecords = $records->unique(function ($record) {
            $details = is_string($record->details) ? json_decode($record->details, true) : $record->details;
            $option = is_array($details) ? ($details['option'] ?? $details['display_label'] ?? '') : '';
            $periodIds = is_array($details) ? ($details['period_ids'] ?? []) : [];
            $periodKey = is_array($periodIds) ? implode(',', $periodIds) : '';
            return $record->date . '-' . $record->leave_type_id . '-' . $option . '-' . $periodKey . '-' . $record->source_type;
        })->values();
        
        // 为每条记录添加 detail 字段（使用与 details 方法完全相同的逻辑）
        $result = $uniqueRecords->map(function($record) use ($periodsArray, $sourceLabels) {
            // 判断来源类型：优先使用 is_self_applied 字段
            $sourceType = $record->source_type ?? 'manual';
            if ($record->is_self_applied) {
                $sourceType = 'self_applied';
            }
            $sourceLabel = $sourceLabels[$sourceType] ?? '标记';
            
            $details = is_string($record->details) ? json_decode($record->details, true) : $record->details;
            $detailContent = '';
            
            if ($sourceType === 'roll_call') {
                // 点名来源：显示点名类型名称 + 时间
                if (is_array($details) && isset($details['roll_call_type'])) {
                    $detailContent = $details['roll_call_type'];
                    if (isset($details['roll_call_time'])) {
                        $detailContent .= '(' . $details['roll_call_time'] . ')';
                    }
                } else {
                    $detailContent = '点名记录';
                }
            } elseif (in_array($sourceType, ['leave_request', 'self_applied'])) {
                // 请假/申请来源：显示请假类型 + 时段
                if ($record->leaveType) {
                    $detailContent = $record->leaveType->name;
                }
                if (is_array($details)) {
                    if (isset($details['display_label'])) {
                        $detailContent .= '(' . $details['display_label'] . ')';
                    } elseif (isset($details['option_label'])) {
                        $detailContent .= '(' . $details['option_label'] . ')';
                    } elseif (isset($details['option'])) {
                        $optionMap = [
                            'morning_half' => '上午',
                            'afternoon_half' => '下午',
                            'full_day' => '全天',
                            'morning_exercise' => '早操',
                            'evening_exercise' => '晚操'
                        ];
                        $optionText = $optionMap[$details['option']] ?? $details['option'];
                        $detailContent .= '(' . $optionText . ')';
                    }
                }
            } else {
                // 手动标记来源：优先显示display_label，否则显示option_label/time_slot_name，再否则显示节次
                if (is_array($details)) {
                    // 首先检查是否有 period_ids，用于获取节次名称
                    $periodNamesFromIds = [];
                    if (isset($details['period_ids']) && !empty($details['period_ids']) && is_array($details['period_ids'])) {
                        foreach ($details['period_ids'] as $periodId) {
                            foreach ($periodsArray as $p) {
                                if ((string)$p['id'] === (string)$periodId) {
                                    $periodNamesFromIds[] = $p['name'];
                                    break;
                                }
                            }
                        }
                    }
                    
                    if (isset($details['display_label']) && !empty($details['display_label'])) {
                        $detailContent = $details['display_label'];
                    } elseif (isset($details['text']) && !empty($details['text'])) {
                        // 文本输入类型：显示文本 + 节次（如果有）
                        $detailContent = $details['text'];
                        if (!empty($periodNamesFromIds)) {
                            $detailContent .= '-' . implode('、', $periodNamesFromIds);
                        }
                    } elseif (isset($details['option_label']) && !empty($details['option_label'])) {
                        $detailContent = $details['option_label'];
                    } elseif (isset($details['time_slot_name']) && !empty($details['time_slot_name'])) {
                        $detailContent = $details['time_slot_name'];
                    } elseif (isset($details['option']) && !empty($details['option'])) {
                        $optionMap = [
                            'morning_half' => '上午',
                            'afternoon_half' => '下午',
                            'full_day' => '全天',
                            'morning_exercise' => '早操',
                            'evening_exercise' => '晚操'
                        ];
                        $detailContent = $optionMap[$details['option']] ?? $details['option'];
                    } elseif (!empty($periodNamesFromIds)) {
                        // 只有节次信息
                        $detailContent = implode('、', $periodNamesFromIds);
                    } elseif (isset($details['period_names']) && !empty($details['period_names'])) {
                        $detailContent = implode('、', $details['period_names']);
                    } elseif (isset($details['periods']) && !empty($details['periods'])) {
                        // 根据 periods（节次ID数组）查询系统配置获取节次名称
                        $periodNames = [];
                        foreach ($details['periods'] as $periodId) {
                            foreach ($periodsArray as $p) {
                                if ((string)$p['id'] === (string)$periodId) {
                                    $periodNames[] = $p['name'];
                                    break;
                                }
                            }
                        }
                        if (!empty($periodNames)) {
                            $detailContent = implode('、', $periodNames);
                        } else {
                            $detailContent = '第' . implode(',', $details['periods']) . '节';
                        }
                    } elseif (isset($details['period_numbers']) && !empty($details['period_numbers'])) {
                        // 根据 period_numbers 查询
                        $periodNames = [];
                        foreach ($details['period_numbers'] as $periodNum) {
                            $found = false;
                            foreach ($periodsArray as $p) {
                                if ((string)$p['id'] === (string)$periodNum) {
                                    $periodNames[] = $p['name'];
                                    $found = true;
                                    break;
                                }
                            }
                            if (!$found) {
                                $index = (int)$periodNum - 1;
                                if ($index >= 0 && $index < count($periodsArray)) {
                                    $periodNames[] = $periodsArray[$index]['name'] ?? "第{$periodNum}节";
                                } else {
                                    $periodNames[] = "第{$periodNum}节";
                                }
                            }
                        }
                        $detailContent = implode('、', $periodNames);
                    }
                }
                if (!$detailContent && $record->period_id) {
                    // 尝试从period关联获取
                    $period = $record->period;
                    if ($period) {
                        $detailContent = '第' . $period->period_number . '节';
                    }
                }
                if (!$detailContent && $record->leaveType) {
                    $detailContent = $record->leaveType->name;
                }
                if (!$detailContent) {
                    $detailContent = '考勤记录';
                }
            }
            
            // 转换为数组并添加 detail 字段（确保能被 JSON 序列化）
            $recordArray = $record->toArray();
            $recordArray['detail'] = $sourceLabel . '：' . $detailContent;
            $recordArray['period_names_display'] = $detailContent;
            return $recordArray;
        });

        return response()->json($result);
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
