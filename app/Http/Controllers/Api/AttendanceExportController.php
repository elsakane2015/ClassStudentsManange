<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Student;
use App\Models\AttendanceRecord;
use App\Models\LeaveType;
use App\Models\RollCallType;
use App\Models\SchoolClass;
use App\Models\Semester;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use Carbon\Carbon;

class AttendanceExportController extends Controller
{
    public function export(Request $request)
    {
        $user = $request->user();
        
        // Check permission
        if (!$user->hasPermission('attendance.export')) {
            return response()->json(['error' => '没有导出权限'], 403);
        }

        // Get parameters
        $scope = $request->input('scope', 'today');
        $semesterId = $request->input('semester_id');
        $classIds = $request->input('class_ids', []);
        $studentRange = $request->input('student_range', 'all'); // 'all' or 'with_records'
        $exportFormat = $request->input('export_format', 'count'); // 'count' or 'detail'
        $leaveTypeIds = $request->input('leave_type_ids', []);
        $rollCallTypeIds = $request->input('roll_call_type_ids', []);
        $includeRollCall = $request->input('include_roll_call', true);

        // Validate class access based on role
        $authorizedClassIds = $this->getAuthorizedClassIds($user);
        if (empty($classIds)) {
            $classIds = $authorizedClassIds;
        } else {
            $classIds = array_intersect($classIds, $authorizedClassIds);
        }

        if (empty($classIds)) {
            return response()->json(['error' => '没有可导出的班级'], 400);
        }

        // Get date range
        $dateRange = $this->getDateRange($scope, $semesterId);

        // Get students
        $studentsQuery = Student::whereIn('class_id', $classIds)
            ->with(['user', 'schoolClass'])
            ->orderBy('student_no', 'asc');

        $students = $studentsQuery->get();

        if ($students->isEmpty()) {
            return response()->json(['error' => '暂无学生数据'], 400);
        }

        // Get leave types and roll call types
        $leaveTypes = LeaveType::when(!empty($leaveTypeIds), function($q) use ($leaveTypeIds) {
            $q->whereIn('id', $leaveTypeIds);
        })->orderBy('id')->get();

        $rollCallTypes = collect([]);
        if ($includeRollCall) {
            $rollCallTypes = RollCallType::when(!empty($rollCallTypeIds), function($q) use ($rollCallTypeIds) {
                $q->whereIn('id', $rollCallTypeIds);
            })->orderBy('id')->get();
        }

        // Get attendance records
        $attendanceRecords = AttendanceRecord::whereIn('student_id', $students->pluck('id'))
            ->whereBetween('date', [$dateRange['start'], $dateRange['end']])
            ->get()
            ->groupBy('student_id');

        // Check if there's any data
        if ($studentRange === 'with_records' && $attendanceRecords->isEmpty()) {
            return response()->json(['error' => '暂无考勤数据'], 400);
        }

        // Filter students if only those with records
        if ($studentRange === 'with_records') {
            $students = $students->filter(function($student) use ($attendanceRecords) {
                return $attendanceRecords->has($student->id);
            });
        }

        if ($students->isEmpty()) {
            return response()->json(['error' => '暂无数据'], 400);
        }

        // Build column headers
        $columns = $this->buildColumns($leaveTypes, $rollCallTypes);

        // Get roll call absent records from roll_call_records table
        $rollCallRecords = collect([]);
        if ($includeRollCall && $rollCallTypes->isNotEmpty()) {
            $rollCallRecords = \DB::table('roll_call_records')
                ->join('roll_calls', 'roll_call_records.roll_call_id', '=', 'roll_calls.id')
                ->whereIn('roll_call_records.student_id', $students->pluck('id'))
                ->where('roll_call_records.status', 'absent')
                ->whereBetween(\DB::raw('DATE(roll_calls.roll_call_time)'), [$dateRange['start'], $dateRange['end']])
                ->select('roll_call_records.*', 'roll_calls.roll_call_type_id', 'roll_calls.roll_call_time')
                ->get()
                ->groupBy('student_id');
        }

        // Build data rows - pass columns to use merged roll call types
        $data = $this->buildDataRows($students, $attendanceRecords, $leaveTypes, $columns, $exportFormat, $rollCallRecords);

        // Generate Excel
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('考勤记录');

        // Write headers
        $colIndex = 1;
        foreach ($columns as $column) {
            $sheet->setCellValueByColumnAndRow($colIndex, 1, $column['label']);
            $colIndex++;
        }

        // Style headers
        $headerRange = 'A1:' . $this->getColumnLetter(count($columns)) . '1';
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'color' => ['argb' => 'FFE0E0E0']
            ],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN]
            ]
        ]);

        // Write data rows
        $rowIndex = 2;
        foreach ($data as $row) {
            $colIndex = 1;
            foreach ($columns as $column) {
                $value = $row[$column['key']] ?? '';
                $sheet->setCellValueByColumnAndRow($colIndex, $rowIndex, $value);
                $colIndex++;
            }
            $rowIndex++;
        }

        // Write summary row
        $summaryRow = $this->buildSummaryRow($data, $columns);
        $colIndex = 1;
        foreach ($columns as $column) {
            $value = $summaryRow[$column['key']] ?? '';
            $sheet->setCellValueByColumnAndRow($colIndex, $rowIndex, $value);
            $colIndex++;
        }

        // Style summary row
        $summaryRange = 'A' . $rowIndex . ':' . $this->getColumnLetter(count($columns)) . $rowIndex;
        $sheet->getStyle($summaryRange)->applyFromArray([
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'color' => ['argb' => 'FFFFF0C0']
            ],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN]
            ]
        ]);

        // Auto-size columns
        foreach (range(1, count($columns)) as $colNum) {
            $sheet->getColumnDimensionByColumn($colNum)->setAutoSize(true);
        }

        // Style all data cells
        $dataRange = 'A2:' . $this->getColumnLetter(count($columns)) . ($rowIndex - 1);
        if ($rowIndex > 2) {
            $sheet->getStyle($dataRange)->applyFromArray([
                'borders' => [
                    'allBorders' => ['borderStyle' => Border::BORDER_THIN]
                ]
            ]);
        }

        // Generate file
        $fileName = $this->generateFileName($classIds, $scope, $semesterId);
        
        $writer = new Xlsx($spreadsheet);
        
        // Return as streamed download
        return response()->streamDownload(function() use ($writer) {
            $writer->save('php://output');
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    private function getAuthorizedClassIds($user): array
    {
        if (in_array($user->role, ['system_admin', 'school_admin', 'admin'])) {
            return SchoolClass::where('is_graduated', false)->pluck('id')->toArray();
        }

        if (in_array($user->role, ['department_manager', 'manager'])) {
            $deptIds = $user->managedDepartments->pluck('id');
            return SchoolClass::whereIn('department_id', $deptIds)
                ->where('is_graduated', false)
                ->pluck('id')
                ->toArray();
        }

        if ($user->role === 'teacher') {
            return $user->teacherClasses->pluck('id')->toArray();
        }

        return [];
    }

    private function getDateRange($scope, $semesterId = null): array
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
                $semester = $semesterId 
                    ? Semester::find($semesterId) 
                    : Semester::where('is_current', true)->first();
                
                if ($semester) {
                    $startDate = Carbon::parse($semester->start_date);
                    $endDate = $startDate->copy()->addWeeks($semester->total_weeks);
                    return [
                        'start' => $semester->start_date,
                        'end' => $endDate->format('Y-m-d')
                    ];
                }
                // Fallback
                return [
                    'start' => $now->copy()->startOfMonth()->format('Y-m-d'),
                    'end' => $now->copy()->endOfMonth()->format('Y-m-d')
                ];
            default:
                return [
                    'start' => $now->format('Y-m-d'),
                    'end' => $now->format('Y-m-d')
                ];
        }
    }

    private function buildColumns($leaveTypes, $rollCallTypes): array
    {
        $columns = [
            ['key' => 'student_no', 'label' => '学号'],
            ['key' => 'name', 'label' => '姓名'],
            ['key' => 'class', 'label' => '班级'],
        ];

        // Add leave type columns - each option gets its own column
        foreach ($leaveTypes as $leaveType) {
            $config = $leaveType->input_config ?? [];
            $options = $config['options'] ?? [];
            
            if (!empty($options)) {
                // Create a column for each option
                foreach ($options as $opt) {
                    $optKey = $opt['key'] ?? '';
                    $optLabel = $opt['label'] ?? $optKey;
                    
                    // Determine unit based on option type
                    $isTimeOption = in_array($optKey, ['morning_half', 'afternoon_half', 'full_day']);
                    $unit = $isTimeOption ? '天' : '次';
                    
                    $columns[] = [
                        'key' => 'leave_' . $leaveType->id . '_' . $optKey,
                        'label' => $leaveType->name . '(' . $optLabel . ')',
                        'type' => 'leave_option',
                        'leave_type_id' => $leaveType->id,
                        'option_key' => $optKey,
                        'is_time_option' => $isTimeOption,
                        'unit' => $unit,
                    ];
                }
            } else {
                // No options, just count times
                $columns[] = [
                    'key' => 'leave_' . $leaveType->id . '_count',
                    'label' => $leaveType->name . '(次)',
                    'type' => 'leave_count',
                    'leave_type_id' => $leaveType->id,
                ];
            }
        }

        // Add roll call type columns - MERGE by name
        // 按名称合并点名类型，相同名称的类型共享一列
        $mergedRollCallTypes = [];
        foreach ($rollCallTypes as $rollCallType) {
            $name = $rollCallType->name;
            if (!isset($mergedRollCallTypes[$name])) {
                $mergedRollCallTypes[$name] = [
                    'name' => $name,
                    'ids' => [$rollCallType->id],
                ];
            } else {
                $mergedRollCallTypes[$name]['ids'][] = $rollCallType->id;
            }
        }

        foreach ($mergedRollCallTypes as $name => $merged) {
            // 使用所有 ID 的 JSON 作为 key，确保唯一性
            $columns[] = [
                'key' => 'rollcall_merged_' . md5(implode(',', $merged['ids'])),
                'label' => $name . '缺勤(次)',
                'type' => 'rollcall_merged',
                'roll_call_type_ids' => $merged['ids'],
            ];
        }

        return $columns;
    }

    private function buildDataRows($students, $attendanceRecords, $leaveTypes, $columns, $exportFormat, $rollCallRecords = null): array
    {
        $rows = [];
        $rollCallRecords = $rollCallRecords ?? collect([]);

        foreach ($students as $student) {
            $row = [
                'student_no' => $student->student_no,
                'name' => $student->user->name ?? '未知',
                'class' => $student->schoolClass->name ?? '',
            ];

            $records = $attendanceRecords->get($student->id, collect([]));

            // Process leave types
            foreach ($leaveTypes as $leaveType) {
                $typeRecords = $records->where('leave_type_id', $leaveType->id);
                $config = $leaveType->input_config ?? [];
                $options = $config['options'] ?? [];
                
                // 按 leave_batch_id 去重（同一次请假申请只算一次）
                $uniqueRecords = $typeRecords->groupBy(function($rec) {
                    // 如果有 leave_batch_id，用它分组；否则用 id
                    return $rec->leave_batch_id ?? 'single_' . $rec->id;
                })->map(function($group) {
                    return $group->first(); // 每组只取第一条
                });
                
                if (!empty($options)) {
                    // Process each option separately
                    foreach ($options as $opt) {
                        $optKey = $opt['key'] ?? '';
                        $optLabel = $opt['label'] ?? $optKey;
                        $isTimeOption = in_array($optKey, ['morning_half', 'afternoon_half', 'full_day']);
                        
                        // Filter records for this specific option
                        // 需要同时匹配 option、time_slot_name 和 display_label
                        $optionRecords = $uniqueRecords->filter(function($rec) use ($optKey, $optLabel, $options) {
                            $recDetails = is_string($rec->details) ? json_decode($rec->details, true) : ($rec->details ?? []);
                            
                            $recordOption = $recDetails['option'] ?? '';
                            $timeSlotName = $recDetails['time_slot_name'] ?? '';
                            $displayLabel = $recDetails['display_label'] ?? '';
                            
                            // 关键逻辑：如果当前列不是 full_day，但记录是 full_day，则不匹配
                            // 这样可以防止"全天"请假出现在"早操"等其他列中
                            if ($optKey !== 'full_day') {
                                // 检查记录是否是全天类型
                                $isFullDayRecord = in_array($recordOption, ['full_day', 'all_day']) ||
                                                   in_array($timeSlotName, ['全天', '全日']) ||
                                                   in_array($displayLabel, ['全天', '全日']);
                                if ($isFullDayRecord) {
                                    return false; // 全天记录不应出现在非全天的列中
                                }
                            }
                            
                            // 方式1: 直接匹配 option
                            if ($recordOption === $optKey) {
                                return true;
                            }
                            
                            // 方式2: 匹配 time_slot_name（如"全天"、"上午"）
                            if ($timeSlotName && $timeSlotName === $optLabel) {
                                return true;
                            }
                            
                            // 方式3: 匹配 display_label
                            if ($displayLabel && $displayLabel === $optLabel) {
                                return true;
                            }
                            
                            return false;
                        });
                        
                        $columnKey = 'leave_' . $leaveType->id . '_' . $optKey;
                        
                        if ($exportFormat === 'detail') {
                            // Detail format: 日期来源(详细信息) | 日期来源(详细信息)
                            $details = $optionRecords->map(function($rec) {
                                $date = Carbon::parse($rec->date)->format('Y-m-d');
                                $source = $this->getSourceLabel($rec->source_type, $rec->is_self_applied ?? false);
                                $detail = $this->getRecordDetail($rec);
                                return $date . $source . '(' . $detail . ')';
                            })->toArray();
                            $row[$columnKey] = !empty($details) ? implode(' | ', $details) : '';
                        } else {
                            // Count format
                            if ($isTimeOption) {
                                // Time options: count as days (0.5 for half day, 1 for full day)
                                $count = $optionRecords->count();
                                if ($optKey === 'full_day') {
                                    $row[$columnKey] = $count > 0 ? $count : '';
                                } else {
                                    // Half day = 0.5 per record
                                    $row[$columnKey] = $count > 0 ? ($count * 0.5) : '';
                                }
                            } else {
                                // Period options: count as times
                                $row[$columnKey] = $optionRecords->count() ?: '';
                            }
                        }
                    }
                } else {
                    // No options, just count (also deduplicated)
                    $columnKey = 'leave_' . $leaveType->id . '_count';
                    if ($exportFormat === 'detail') {
                        // Detail format: 日期来源(详细信息) | 日期来源(详细信息)
                        $details = $uniqueRecords->map(function($rec) {
                            $date = Carbon::parse($rec->date)->format('Y-m-d');
                            $source = $this->getSourceLabel($rec->source_type, $rec->is_self_applied ?? false);
                            $detail = $this->getRecordDetail($rec);
                            return $date . $source . '(' . $detail . ')';
                        })->toArray();
                        $row[$columnKey] = !empty($details) ? implode(' | ', $details) : '';
                    } else {
                        $row[$columnKey] = $uniqueRecords->count() ?: '';
                    }
                }
            }

            // Process merged roll call types (absent records from roll_call_records table)
            // 使用 columns 中的合并信息
            $studentRollCallRecords = $rollCallRecords->get($student->id, collect([]));
            foreach ($columns as $column) {
                if (($column['type'] ?? '') !== 'rollcall_merged') {
                    continue;
                }
                
                $typeIds = $column['roll_call_type_ids'] ?? [];
                $columnKey = $column['key'];
                
                // 统计所有合并类型的缺勤记录
                $typeAbsentRecords = $studentRollCallRecords->filter(function($rec) use ($typeIds) {
                    return in_array($rec->roll_call_type_id, $typeIds);
                });

                if ($exportFormat === 'detail') {
                    // 点名缺勤的详细格式：日期点名缺勤
                    $details = $typeAbsentRecords->map(function($rec) {
                        $date = Carbon::parse($rec->roll_call_time)->format('Y-m-d');
                        return $date . '点名缺勤';
                    })->toArray();
                    $row[$columnKey] = !empty($details) ? implode(' | ', $details) : '';
                } else {
                    $row[$columnKey] = $typeAbsentRecords->count() ?: '';
                }
            }

            $rows[] = $row;
        }

        return $rows;
    }

    private function buildSummaryRow($data, $columns): array
    {
        $summary = [
            'student_no' => '总计',
            'name' => '',
            'class' => '',
        ];

        foreach ($columns as $column) {
            if (in_array($column['key'], ['student_no', 'name', 'class'])) {
                continue;
            }

            $total = 0;
            foreach ($data as $row) {
                $value = $row[$column['key']] ?? '';
                if (is_numeric($value)) {
                    $total += $value;
                } elseif (is_string($value) && !empty($value)) {
                    // Count dates for detail format
                    $total += substr_count($value, '/') > 0 ? substr_count($value, ',') + 1 : 0;
                }
            }
            $summary[$column['key']] = $total > 0 ? $total : '';
        }

        return $summary;
    }

    private function generateFileName($classIds, $scope, $semesterId): string
    {
        $classes = SchoolClass::whereIn('id', $classIds)->pluck('name')->toArray();
        $classNames = count($classes) > 2 
            ? $classes[0] . '等' . count($classes) . '个班级' 
            : implode('_', $classes);

        $scopeNames = [
            'today' => date('Y-m-d'),
            'week' => date('Y') . '年第' . date('W') . '周',
            'month' => date('Y年n月'),
            'semester' => '',
        ];

        if ($scope === 'semester') {
            $semester = $semesterId 
                ? Semester::find($semesterId) 
                : Semester::where('is_current', true)->first();
            $scopeNames['semester'] = $semester ? $semester->name : date('Y年');
        }

        $scopeName = $scopeNames[$scope] ?? date('Y-m-d');
        $exportDate = date('Ymd');

        return "考勤记录_{$classNames}_{$scopeName}_{$exportDate}.xlsx";
    }

    private function getColumnLetter($columnNumber): string
    {
        $letter = '';
        while ($columnNumber > 0) {
            $columnNumber--;
            $letter = chr(65 + ($columnNumber % 26)) . $letter;
            $columnNumber = intval($columnNumber / 26);
        }
        return $letter;
    }

    /**
     * 获取来源标签
     * @param string|null $sourceType 来源类型
     * @param bool $isSelfApplied 是否是学生自主申请
     */
    private function getSourceLabel($sourceType, $isSelfApplied = false): string
    {
        // 优先检查 is_self_applied 字段
        if ($isSelfApplied) {
            return '申请';
        }
        
        return match($sourceType) {
            'manual', 'manual_bulk' => '标记',
            'leave_request', 'self_applied' => '申请',
            'roll_call' => '点名',
            default => '标记',  // 默认改为"标记"而不是"系统"
        };
    }

    /**
     * 获取记录详细信息
     */
    private function getRecordDetail($record): string
    {
        $details = is_string($record->details) 
            ? json_decode($record->details, true) 
            : ($record->details ?? []);
        
        // 优先级1: display_label
        if (!empty($details['display_label'])) {
            return $details['display_label'];
        }
        
        // 优先级2: text + period_names
        if (!empty($details['text'])) {
            $text = $details['text'];
            if (!empty($details['period_names']) && is_array($details['period_names'])) {
                $text .= '-' . implode('、', $details['period_names']);
            }
            return $text;
        }
        
        // 优先级3: option_label
        if (!empty($details['option_label'])) {
            return $details['option_label'];
        }
        
        // 优先级4: time_slot_name
        if (!empty($details['time_slot_name'])) {
            return $details['time_slot_name'];
        }
        
        // 优先级5: period_names
        if (!empty($details['period_names']) && is_array($details['period_names'])) {
            return implode('、', $details['period_names']);
        }
        
        // 默认
        return '全天';
    }

    /**
     * Get export options (leave types, roll call types, classes)
     */
    public function options(Request $request)
    {
        $user = $request->user();
        
        if (!$user->hasPermission('attendance.export')) {
            return response()->json(['error' => '没有导出权限'], 403);
        }

        // Get authorized classes
        $classIds = $this->getAuthorizedClassIds($user);
        $classes = SchoolClass::whereIn('id', $classIds)
            ->with('department')
            ->get()
            ->map(function($class) {
                return [
                    'id' => $class->id,
                    'name' => $class->name,
                    'department' => $class->department->name ?? '',
                ];
            });

        // Get leave types
        $leaveTypes = LeaveType::orderBy('id')->get()->map(function($lt) {
            return [
                'id' => $lt->id,
                'name' => $lt->name,
                'slug' => $lt->slug,
            ];
        });

        // Get roll call types
        $rollCallTypes = RollCallType::orderBy('id')->get()->map(function($rt) {
            return [
                'id' => $rt->id,
                'name' => $rt->name,
            ];
        });

        return response()->json([
            'classes' => $classes,
            'leave_types' => $leaveTypes,
            'roll_call_types' => $rollCallTypes,
        ]);
    }
}
