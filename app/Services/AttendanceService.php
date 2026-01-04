<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Student;
use Carbon\Carbon;

class AttendanceService
{
    /**
     * 记录考勤（支持时段化）
     * 
     * @param int $studentId
     * @param string $date
     * @param int|null $periodId NULL表示全天
     * @param string $status
     * @param array $options
     * @return AttendanceRecord
     */
    public function record($studentId, $date, $periodId, $status, $options = [])
    {
        // 获取学生信息
        $student = Student::find($studentId);
        
        // 如果是时段记录（不是全天），确保有基础状态
        if ($periodId !== null) {
            $hasBaseStatus = AttendanceRecord::where('student_id', $studentId)
                ->where('date', $date)
                ->whereNull('period_id')
                ->exists();
            
            if (!$hasBaseStatus) {
                // 自动创建基础状态：默认为"出勤"
                AttendanceRecord::create([
                    'student_id' => $studentId,
                    'date' => $date,
                    'period_id' => null,
                    'status' => 'present', // 默认到校
                    'school_id' => $student->school_id,
                    'class_id' => $student->class_id,
                    'source_type' => 'auto',
                    'note' => '自动创建基础状态'
                ]);
                
                \Log::info("Auto-created base status 'present' for student {$studentId} on {$date}");
            }
        }
        
        // 如果是全天记录
        if ($periodId === null) {
            // 检查 details 中的 option 来确定是否是真正的"全天"
            $detailsForCheck = $options['details'] ?? null;
            $optionForCheck = null;
            if (is_array($detailsForCheck)) {
                $optionForCheck = $detailsForCheck['option'] ?? null;
            } elseif (is_string($detailsForCheck)) {
                $parsedDetails = json_decode($detailsForCheck, true);
                $optionForCheck = $parsedDetails['option'] ?? null;
            }
            
            // 只有当 option 明确是 '全天' 时，才考虑删除其他时段记录
            $isRealFullDay = ($optionForCheck === '全天' || $optionForCheck === 'full_day');
            
            // 检查是否已有具体时段记录
            $hasSpecific = AttendanceRecord::where('student_id', $studentId)
                ->where('date', $date)
                ->whereNotNull('period_id')
                ->exists();
            
            if ($hasSpecific) {
                // 已有具体记录，更新全天记录（不删除时段记录）
                \Log::info("Student {$studentId} already has specific period records for {$date}, updating full-day record");
            }
            
            // 特殊规则：只有真正的"全天"病假/事假才删除所有时段记录
            // 时段选择（如"上午"、"下午"）不应该删除其他时段记录
            if ($isRealFullDay && isset($options['leave_type_id'])) {
                $leaveType = \App\Models\LeaveType::find($options['leave_type_id']);
                
                // 只对病假(sick_leave)和事假(personal_leave)的全天记录删除时段记录
                if ($leaveType && in_array($leaveType->slug, ['sick_leave', 'personal_leave'])) {
                    // 这是全天病假/事假，删除所有时段记录
                    AttendanceRecord::where('student_id', $studentId)
                        ->where('date', $date)
                        ->whereNotNull('period_id')
                        ->delete();
                    
                    // 同时删除其他时段选择记录（如上午、下午）
                    AttendanceRecord::where('student_id', $studentId)
                        ->where('date', $date)
                        ->whereNull('period_id')
                        ->where('id', '!=', $existingRecord->id ?? 0)
                        ->delete();
                    
                    \Log::info("Full-day {$leaveType->name} for student {$studentId} on {$date}, deleted all period records");
                }
            }
        }
        
        // 创建或更新记录
        // 对于有 option 的记录（duration_select类型），需要根据 option 和 period_ids 区分
        $details = $options['details'] ?? null;
        $option = null;
        $periodIds = null;
        if (is_array($details)) {
            $option = $details['option'] ?? null;
            $periodIds = $details['period_ids'] ?? null;
        } elseif (is_string($details)) {
            $parsedDetails = json_decode($details, true);
            $option = $parsedDetails['option'] ?? null;
            $periodIds = $parsedDetails['period_ids'] ?? null;
        }
        
        if ($option !== null && $periodId === null) {
            // 有 option 的记录：先检查是否已有相同 option 和 period_ids 的记录
            $query = AttendanceRecord::where('student_id', $studentId)
                ->where('date', $date)
                ->whereNull('period_id')
                ->where('leave_type_id', $options['leave_type_id'] ?? null);
            
            // 如果有 period_ids，用它来匹配；否则只用 option
            if ($periodIds && is_array($periodIds) && count($periodIds) > 0) {
                $periodIdsJson = json_encode(array_map('intval', $periodIds));
                $query->whereRaw("JSON_EXTRACT(details, '$.period_ids') = ?", [$periodIdsJson]);
            } else {
                $query->whereRaw("JSON_EXTRACT(details, '$.option') = ?", [$option]);
            }
            
            $existingRecord = $query->first();
            
            if ($existingRecord) {
                // 更新现有记录
                $existingRecord->update([
                    'status' => $status,
                    'details' => $options['details'] ?? null,
                    'note' => $options['note'] ?? null,
                    'source_type' => $options['source_type'] ?? 'manual',
                ]);
                $record = $existingRecord;
            } else {
                // 创建新记录（不使用 updateOrCreate，直接 create）
                $record = AttendanceRecord::create([
                    'student_id' => $studentId,
                    'date' => $date,
                    'period_id' => null,
                    'status' => $status,
                    'school_id' => $student->school_id,
                    'class_id' => $student->class_id,
                    'leave_type_id' => $options['leave_type_id'] ?? null,
                    'note' => $options['note'] ?? null,
                    'details' => $options['details'] ?? null,
                    'source_type' => $options['source_type'] ?? 'manual',
                    'source_id' => $options['source_id'] ?? null,
                    'informed_parent' => $options['informed_parent'] ?? false
                ]);
            }
        } else {
            // 普通记录：使用原有的 updateOrCreate 逻辑
            $record = AttendanceRecord::updateOrCreate(
                [
                    'student_id' => $studentId,
                    'date' => $date,
                    'period_id' => $periodId
                ],
                [
                    'status' => $status,
                    'school_id' => $student->school_id,
                    'class_id' => $student->class_id,
                    'leave_type_id' => $options['leave_type_id'] ?? null,
                    'note' => $options['note'] ?? null,
                    'details' => $options['details'] ?? null,
                    'source_type' => $options['source_type'] ?? 'manual',
                    'source_id' => $options['source_id'] ?? null,
                    'informed_parent' => $options['informed_parent'] ?? false
                ]
            );
        }


        // 智能合并功能已禁用
        // 原因：当同一天存在不同状态的记录（如病假+旷课）时，合并会导致数据覆盖问题
        // 现在保持各个时段的独立记录，不再自动合并为全天
        /*
        if ($option !== null && isset($options['leave_type_id'])) {
            // ... 原合并逻辑已移除 ...
        }
        */
        
        return $record;
    }
    
    /**
     * 批量记录全天出勤（用于自动标记）
     * 
     * @param array $studentIds
     * @param string $date
     * @return int 创建的记录数
     */
    public function markFullDayPresent($studentIds, $date)
    {
        $count = 0;
        
        foreach ($studentIds as $studentId) {
            $record = $this->record($studentId, $date, null, 'present', [
                'source_type' => 'system',
                'note' => '自动标记出勤'
            ]);
            
            if ($record) {
                $count++;
            }
        }
        
        return $count;
    }
    
    /**
     * 获取学生某天的完整考勤状态
     * 
     * @param int $studentId
     * @param string $date
     * @return array
     */
    public function getDayStatus($studentId, $date)
    {
        $records = AttendanceRecord::where('student_id', $studentId)
            ->where('date', $date)
            ->with(['period', 'leaveType'])
            ->orderByRaw('period_id IS NULL DESC') // NULL在前
            ->orderBy('period_id')
            ->get();
        
        if ($records->isEmpty()) {
            return [
                'type' => 'no_record',
                'status' => null,
                'records' => []
            ];
        }
        
        // 如果只有全天记录
        if ($records->count() === 1 && $records->first()->period_id === null) {
            return [
                'type' => 'full_day',
                'status' => $records->first()->status,
                'record' => $records->first(),
                'records' => $records
            ];
        }
        
        // 有具体时段记录
        $fullDayRecord = $records->firstWhere('period_id', null);
        $periodRecords = $records->where('period_id', '!=', null);
        
        return [
            'type' => 'periods',
            'records' => $periodRecords,
            'default_status' => $fullDayRecord?->status ?? 'present',
            'full_day_record' => $fullDayRecord
        ];
    }
    
    /**
     * 获取学生某个时段的状态
     * 
     * @param int $studentId
     * @param string $date
     * @param int|null $periodId
     * @return string|null
     */
    public function getPeriodStatus($studentId, $date, $periodId = null)
    {
        // 先查找具体时段记录
        $record = AttendanceRecord::where('student_id', $studentId)
            ->where('date', $date)
            ->where('period_id', $periodId)
            ->first();
        
        if ($record) {
            return $record->status;
        }
        
        // 没有具体记录，查找全天记录
        $fullDayRecord = AttendanceRecord::where('student_id', $studentId)
            ->where('date', $date)
            ->whereNull('period_id')
            ->first();
        
        return $fullDayRecord?->status ?? null;
    }
    
    /**
     * 删除考勤记录
     * 
     * @param int $studentId
     * @param string $date
     * @param int|null $periodId
     * @return bool
     */
    public function deleteRecord($studentId, $date, $periodId = null)
    {
        return AttendanceRecord::where('student_id', $studentId)
            ->where('date', $date)
            ->where('period_id', $periodId)
            ->delete() > 0;
    }
    
    /**
     * 获取学生的考勤统计（按时段）
     * 
     * @param int $studentId
     * @param string $startDate
     * @param string $endDate
     * @return array
     */
    public function getStatistics($studentId, $startDate, $endDate)
    {
        $records = AttendanceRecord::where('student_id', $studentId)
            ->whereBetween('date', [$startDate, $endDate])
            ->whereNotNull('period_id') // 只统计具体时段
            ->get();
        
        $stats = [
            'total_periods' => $records->count(),
            'present' => $records->where('status', 'present')->count(),
            'late' => $records->where('status', 'late')->count(),
            'absent' => $records->where('status', 'absent')->count(),
            'excused' => $records->where('status', 'excused')->count(),
            'early_leave' => $records->where('status', 'early_leave')->count(),
        ];
        
        // 计算出勤率
        if ($stats['total_periods'] > 0) {
            $stats['attendance_rate'] = round(
                ($stats['present'] + $stats['late']) / $stats['total_periods'] * 100, 
                2
            );
        } else {
            $stats['attendance_rate'] = 0;
        }
        
        return $stats;
    }
    
    /**
     * 从请假申请创建考勤记录
     * 
     * @param \App\Models\LeaveRequest $leaveRequest
     * @return array 创建的记录
     */
    public function createFromLeaveRequest($leaveRequest)
    {
        $records = [];
        
        // 如果是全天请假
        if ($leaveRequest->is_full_day) {
            $record = $this->record(
                $leaveRequest->student_id,
                $leaveRequest->start_date,
                null, // 全天
                'excused',
                [
                    'leave_type_id' => $leaveRequest->leave_type_id,
                    'source_type' => 'leave_request',
                    'source_id' => $leaveRequest->id,
                    'note' => $leaveRequest->reason,
                    'details' => [
                        'leave_request_id' => $leaveRequest->id,
                        'approved_at' => $leaveRequest->approved_at,
                        'approved_by' => $leaveRequest->approved_by
                    ]
                ]
            );
            
            if ($record) {
                $records[] = $record;
            }
        } else {
            // 部分时段请假
            // 这里需要根据start_time和end_time确定具体时段
            // 简化处理：创建一个时段记录
            $record = $this->record(
                $leaveRequest->student_id,
                $leaveRequest->start_date,
                $leaveRequest->period_id ?? 1, // 需要从请假申请中获取
                'excused',
                [
                    'leave_type_id' => $leaveRequest->leave_type_id,
                    'source_type' => 'leave_request',
                    'source_id' => $leaveRequest->id,
                    'note' => $leaveRequest->reason,
                    'details' => [
                        'leave_request_id' => $leaveRequest->id,
                        'start_time' => $leaveRequest->start_time,
                        'end_time' => $leaveRequest->end_time
                    ]
                ]
            );
            
            if ($record) {
                $records[] = $record;
            }
        }
        
        return $records;
    }
}
