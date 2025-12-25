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

        // Build data rows
        $data = $this->buildDataRows($students, $attendanceRecords, $leaveTypes, $rollCallTypes, $exportFormat, $rollCallRecords);

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

        // Add roll call type columns
        foreach ($rollCallTypes as $rollCallType) {
            $columns[] = [
                'key' => 'rollcall_' . $rollCallType->id,
                'label' => $rollCallType->name . '缺勤(次)',
                'type' => 'rollcall',
                'roll_call_type_id' => $rollCallType->id,
            ];
        }

        return $columns;
    }

    private function buildDataRows($students, $attendanceRecords, $leaveTypes, $rollCallTypes, $exportFormat, $rollCallRecords = null): array
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
                
                if (!empty($options)) {
                    // Process each option separately
                    foreach ($options as $opt) {
                        $optKey = $opt['key'] ?? '';
                        $isTimeOption = in_array($optKey, ['morning_half', 'afternoon_half', 'full_day']);
                        
                        // Filter records for this specific option
                        $optionRecords = $typeRecords->filter(function($rec) use ($optKey) {
                            $recDetails = is_string($rec->details) ? json_decode($rec->details, true) : ($rec->details ?? []);
                            return ($recDetails['option'] ?? '') === $optKey;
                        });
                        
                        $columnKey = 'leave_' . $leaveType->id . '_' . $optKey;
                        
                        if ($exportFormat === 'detail') {
                            // Detail format: show dates
                            $dates = $optionRecords->map(function($rec) {
                                return Carbon::parse($rec->date)->format('n/j');
                            })->toArray();
                            $row[$columnKey] = !empty($dates) ? implode(', ', $dates) : '';
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
                    // No options, just count
                    $columnKey = 'leave_' . $leaveType->id . '_count';
                    if ($exportFormat === 'detail') {
                        $dates = $typeRecords->map(function($rec) {
                            return Carbon::parse($rec->date)->format('n/j');
                        })->toArray();
                        $row[$columnKey] = !empty($dates) ? implode(', ', $dates) : '';
                    } else {
                        $row[$columnKey] = $typeRecords->count() ?: '';
                    }
                }
            }

            // Process roll call types (absent records from roll_call_records table)
            $studentRollCallRecords = $rollCallRecords->get($student->id, collect([]));
            foreach ($rollCallTypes as $rollCallType) {
                $typeAbsentRecords = $studentRollCallRecords->filter(function($rec) use ($rollCallType) {
                    return $rec->roll_call_type_id == $rollCallType->id;
                });

                if ($exportFormat === 'detail') {
                    $dates = $typeAbsentRecords->map(function($rec) {
                        return Carbon::parse($rec->roll_call_time)->format('n/j');
                    })->toArray();
                    $row['rollcall_' . $rollCallType->id] = !empty($dates) ? implode(', ', $dates) : '';
                } else {
                    $row['rollcall_' . $rollCallType->id] = $typeAbsentRecords->count() ?: '';
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
