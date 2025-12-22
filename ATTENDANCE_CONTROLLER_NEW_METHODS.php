
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
            ->whereHas('attendanceRecords', function($q) use ($dateRange, $status, $leaveTypeId) {
                $q->whereBetween('date', [$dateRange['start'], $dateRange['end']])
                  ->where('status', $status);
                if ($leaveTypeId) {
                    $q->where('leave_type_id', $leaveTypeId);
                }
            })
            ->with([
                'user',
                'schoolClass.department',
                'attendanceRecords' => function($q) use ($dateRange, $status, $leaveTypeId) {
                    $q->whereBetween('date', [$dateRange['start'], $dateRange['end']])
                      ->where('status', $status);
                    if ($leaveTypeId) {
                        $q->where('leave_type_id', $leaveTypeId);
                    }
                    $q->with(['leaveType', 'period']);
                }
            ])
            ->get();

        // Format response
        $result = $students->map(function($student) use ($scope) {
            $records = $student->attendanceRecords;
            
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

            return [
                'student_no' => $student->student_no,
                'name' => $student->user->name ?? '-',
                'department' => $student->schoolClass->department->name ?? '-',
                'class' => $student->schoolClass->name ?? '-',
                'detail' => $detailText,
                'records' => $records->toArray()
            ];
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
                // Get current semester
                $semester = \App\Models\Semester::where('start_date', '<=', $now)
                    ->where('end_date', '>=', $now)
                    ->first();
                
                if ($semester) {
                    return [
                        'start' => $semester->start_date,
                        'end' => $semester->end_date
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
