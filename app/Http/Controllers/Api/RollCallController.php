<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RollCall;
use App\Models\RollCallRecord;
use App\Models\RollCallAdmin;
use App\Models\RollCallType;
use App\Models\Student;
use App\Models\AttendanceRecord;
use App\Models\TimeSlot;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RollCallController extends Controller
{
    /**
     * List roll calls (history)
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $classId = $request->query('class_id');
        $status = $request->query('status');
        $typeId = $request->query('type_id');
        $scope = $request->query('scope', 'today'); // today, week, month, semester
        
        $query = RollCall::with(['rollCallType', 'creator', 'class']);

        // Filter by user role
        if ($user->role === 'teacher') {
            $classIds = $user->teacherClasses->pluck('id');
            $query->whereIn('class_id', $classId ? [$classId] : $classIds);
        } elseif ($user->role === 'department_manager') {
            // Department manager sees all classes in their departments
            $deptIds = $user->managedDepartments->pluck('id');
            $classIds = \App\Models\SchoolClass::whereIn('department_id', $deptIds)
                ->where('is_graduated', false)
                ->pluck('id');
            $query->whereIn('class_id', $classId ? [$classId] : $classIds);
        } elseif ($user->role === 'student' && $user->student) {
            // Roll call admin only sees their own created
            $query->where('created_by', $user->id);
        } elseif (in_array($user->role, ['admin', 'system_admin', 'school_admin'])) {
            // Admin can see all, filter by class_id if provided
            if ($classId) {
                $query->where('class_id', $classId);
            }
        } else {
            return response()->json([]);
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($typeId) {
            $query->where('roll_call_type_id', $typeId);
        }

        // Date scope
        $now = Carbon::now();
        if ($scope === 'today') {
            $query->whereDate('roll_call_time', $now->toDateString());
        } elseif ($scope === 'week') {
            $query->whereBetween('roll_call_time', [
                $now->copy()->startOfWeek(),
                $now->copy()->endOfWeek()
            ]);
        } elseif ($scope === 'month') {
            $query->whereMonth('roll_call_time', $now->month)
                  ->whereYear('roll_call_time', $now->year);
        }
        // semester: no date filter

        $rollCalls = $query->orderBy('roll_call_time', 'desc')->paginate(20);
        
        return response()->json($rollCalls);
    }

    /**
     * Get in-progress roll calls
     */
    public function inProgress(Request $request)
    {
        $user = $request->user();
        
        $query = RollCall::with(['rollCallType', 'class'])
            ->where('status', 'in_progress');

        if ($user->role === 'teacher') {
            $classIds = $user->teacherClasses->pluck('id');
            $query->whereIn('class_id', $classIds);
        } elseif ($user->role === 'student') {
            $query->where('created_by', $user->id);
        }

        return response()->json($query->orderBy('created_at', 'desc')->get());
    }

    /**
     * Get roll call statistics for dashboard
     */
    public function stats(Request $request)
    {
        $user = $request->user();
        $classId = $request->query('class_id');
        $scope = $request->query('scope', 'today');
        
        // Get class IDs based on user role
        $classIds = [];
        
        if ($user->role === 'teacher') {
            $classIds = $classId ? [$classId] : $user->teacherClasses->pluck('id')->toArray();
        } elseif ($user->role === 'department_manager') {
            // Department manager sees all classes in their departments
            $deptIds = $user->managedDepartments->pluck('id');
            $classIds = \App\Models\SchoolClass::whereIn('department_id', $deptIds)
                ->where('is_graduated', false)
                ->pluck('id')->toArray();
        } elseif (in_array($user->role, ['system_admin', 'school_admin', 'admin'])) {
            // Admin sees all classes
            $classIds = \App\Models\SchoolClass::where('is_graduated', false)
                ->pluck('id')->toArray();
        } else {
            return response()->json(['error' => 'Unauthorized'], 403);
        }
        
        if (empty($classIds)) {
            return response()->json([]);
        }
        
        $query = RollCall::whereIn('class_id', $classIds)
            ->where('status', 'completed');

        // Date scope
        $now = Carbon::now();
        if ($scope === 'today') {
            $query->whereDate('roll_call_time', $now->toDateString());
        } elseif ($scope === 'week') {
            $query->whereBetween('roll_call_time', [
                $now->copy()->startOfWeek(),
                $now->copy()->endOfWeek()
            ]);
        } elseif ($scope === 'month') {
            $query->whereMonth('roll_call_time', $now->month)
                  ->whereYear('roll_call_time', $now->year);
        }

        $stats = $query->selectRaw('
            roll_call_type_id,
            COUNT(*) as total_count,
            SUM(total_students - present_count - on_leave_count) as total_absent
        ')
        ->groupBy('roll_call_type_id')
        ->get();

        // Add type info
        $typeIds = $stats->pluck('roll_call_type_id');
        $types = RollCallType::whereIn('id', $typeIds)->get()->keyBy('id');

        $result = $stats->map(function ($stat) use ($types) {
            $type = $types[$stat->roll_call_type_id] ?? null;
            return [
                'type_id' => $stat->roll_call_type_id,
                'type_name' => $type?->name ?? 'Unknown',
                'count' => $stat->total_count,
                'absent_total' => $stat->total_absent,
            ];
        });

        return response()->json($result);
    }

    /**
     * Create a new roll call
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'roll_call_type_id' => 'required|exists:roll_call_types,id',
            'roll_call_time' => 'required|date',
            'notes' => 'nullable|string',
            'class_ids' => 'nullable|array', // For department_manager: multiple classes
            'class_ids.*' => 'exists:school_classes,id',
        ]);

        $type = RollCallType::findOrFail($validated['roll_call_type_id']);
        $rollCallTime = Carbon::parse($validated['roll_call_time']);
        
        // Determine which classes to create roll calls for
        $classIds = [];
        
        if ($user->role === 'teacher') {
            // Teacher can only create for their own classes
            $ownsClass = $user->teacherClasses()->where('id', $type->class_id)->exists();
            if (!$ownsClass) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
            $classIds = [$type->class_id];
            
        } elseif ($user->role === 'department_manager') {
            // Department manager can select classes in their department
            $deptIds = $user->managedDepartments->pluck('id');
            $allowedClassIds = \App\Models\SchoolClass::whereIn('department_id', $deptIds)
                ->where('is_graduated', false)
                ->pluck('id')
                ->toArray();
            
            if (!empty($validated['class_ids'])) {
                // Use selected classes, but verify they belong to manager's departments
                $classIds = array_intersect($validated['class_ids'], $allowedClassIds);
                if (empty($classIds)) {
                    return response()->json(['error' => '选择的班级不在您管辖的系部内'], 403);
                }
            } else {
                // Default to the roll call type's class
                if (in_array($type->class_id, $allowedClassIds)) {
                    $classIds = [$type->class_id];
                } else {
                    return response()->json(['error' => '该点名类型的班级不在您管辖范围内'], 403);
                }
            }
            
        } elseif (in_array($user->role, ['system_admin', 'school_admin', 'admin'])) {
            // Admin can create for any class
            if (!empty($validated['class_ids'])) {
                $classIds = $validated['class_ids'];
            } else {
                $classIds = [$type->class_id];
            }
            
        } elseif ($user->role === 'student' && $user->student) {
            // Student roll call admin
            $admin = RollCallAdmin::where('student_id', $user->student->id)
                ->where('is_active', true)
                ->first();
            
            if (!$admin || !in_array($type->id, $admin->roll_call_type_ids ?? [])) {
                return response()->json(['error' => 'You are not authorized for this roll call type'], 403);
            }
            $classIds = [$type->class_id];
            
        } else {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        DB::beginTransaction();
        try {
            $createdRollCalls = [];
            
            foreach ($classIds as $classId) {
                // Get students in the class
                $students = Student::where('class_id', $classId)->get();
                
                // Get or create roll call type for this class
                // For department manager, we might need to use a shared type or create class-specific ones
                $classType = $type;
                if ($type->class_id !== $classId) {
                    // Try to find an existing type with same name for this class
                    $classType = RollCallType::where('class_id', $classId)
                        ->where('name', $type->name)
                        ->first();
                    
                    // If not found, use the original type but assign to this class
                    // Or create a new type for this class
                    if (!$classType) {
                        $classType = RollCallType::create([
                            'school_id' => $type->school_id,
                            'class_id' => $classId,
                            'name' => $type->name,
                            'description' => $type->description,
                            'leave_type_id' => $type->leave_type_id,
                            'is_active' => true,
                        ]);
                    }
                }

                // Create roll call
                $rollCall = RollCall::create([
                    'class_id' => $classId,
                    'school_id' => $classType->school_id,
                    'roll_call_type_id' => $classType->id,
                    'roll_call_time' => $rollCallTime,
                    'created_by' => $user->id,
                    'status' => 'in_progress',
                    'total_students' => $students->count(),
                    'notes' => $validated['notes'] ?? null,
                ]);

                // Create records for each student
                foreach ($students as $student) {
                    $leaveInfo = $this->getStudentLeaveInfo($student, $rollCallTime, $classType);
                    
                    RollCallRecord::create([
                        'roll_call_id' => $rollCall->id,
                        'student_id' => $student->id,
                        'status' => $leaveInfo ? 'on_leave' : 'pending',
                        'leave_type_id' => $leaveInfo['leave_type_id'] ?? null,
                        'leave_detail' => $leaveInfo['detail'] ?? null,
                        'leave_status' => $leaveInfo['status'] ?? null,
                    ]);
                }

                // Update on_leave_count
                $onLeaveCount = RollCallRecord::where('roll_call_id', $rollCall->id)
                    ->where('status', 'on_leave')
                    ->count();
                $rollCall->update(['on_leave_count' => $onLeaveCount]);
                
                $createdRollCalls[] = $rollCall->load('rollCallType', 'class', 'records.student.user');
            }

            DB::commit();

            // Return single roll call or array depending on count
            if (count($createdRollCalls) === 1) {
                return response()->json($createdRollCalls[0], 201);
            }
            return response()->json([
                'message' => '成功为 ' . count($createdRollCalls) . ' 个班级创建点名',
                'roll_calls' => $createdRollCalls
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get roll call detail with student list
     */
    public function show(Request $request, RollCall $rollCall)
    {
        $user = $request->user();
        
        // Verify access
        if ($user->role === 'teacher') {
            $ownsClass = $user->teacherClasses()->where('id', $rollCall->class_id)->exists();
            if (!$ownsClass) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
        } elseif ($user->role === 'student') {
            if ($rollCall->created_by !== $user->id) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
        }

        $rollCall->load([
            'rollCallType',
            'class',
            'creator',
            'records.student.user',
            'records.leaveType',
        ]);

        // 动态检查：遍历每个点名记录，检查是否有新的考勤标记
        // 这样即使在点名开始后才进行考勤标记，刷新点名界面时也能正确显示
        $rollCallTime = $rollCall->roll_call_time;
        $rollCallType = $rollCall->rollCallType;
        
        foreach ($rollCall->records as $record) {
            // 只检查非 on_leave 状态的记录（on_leave 已经是请假状态，无需再检查）
            if ($record->status !== 'on_leave') {
                $student = $record->student;
                if ($student) {
                    $leaveInfo = $this->getStudentLeaveInfo($student, $rollCallTime, $rollCallType);
                    
                    if ($leaveInfo) {
                        // 有新的考勤标记，更新点名记录状态
                        $record->update([
                            'status' => 'on_leave',
                            'leave_type_id' => $leaveInfo['leave_type_id'] ?? null,
                            'leave_detail' => $leaveInfo['detail'] ?? null,
                            'leave_status' => $leaveInfo['status'] ?? null,
                        ]);
                        
                        // 同时更新内存中的数据，确保返回正确的状态
                        $record->status = 'on_leave';
                        $record->leave_detail = $leaveInfo['detail'] ?? null;
                        $record->leave_status = $leaveInfo['status'] ?? null;
                    }
                }
            }
        }
        
        // 更新请假人数统计
        $onLeaveCount = $rollCall->records->where('status', 'on_leave')->count();
        if ($rollCall->on_leave_count !== $onLeaveCount) {
            $rollCall->update(['on_leave_count' => $onLeaveCount]);
        }

        // Determine if current user can modify records
        $canModifyRecords = false;
        
        if ($user->role === 'teacher') {
            $ownsClass = $user->teacherClasses()->where('id', $rollCall->class_id)->exists();
            if ($ownsClass) {
                $canModifyRecords = true;
            }
        } elseif (in_array($user->role, ['admin', 'system_admin'])) {
            $canModifyRecords = true;
        } elseif ($user->role === 'student' && $user->student) {
            $admin = RollCallAdmin::where('student_id', $user->student->id)
                ->where('class_id', $rollCall->class_id)
                ->where('is_active', true)
                ->first();
            
            if ($admin && $admin->can_modify_records) {
                if (in_array($rollCall->roll_call_type_id, $admin->roll_call_type_ids ?? [])) {
                    $canModifyRecords = true;
                }
            }
        }

        $response = $rollCall->toArray();
        $response['can_modify_records'] = $canModifyRecords;

        return response()->json($response);
    }

    /**
     * Mark students as present/absent
     */
    public function mark(Request $request, RollCall $rollCall)
    {
        $user = $request->user();

        if ($rollCall->status !== 'in_progress') {
            return response()->json(['error' => 'Roll call is not in progress'], 400);
        }

        // Verify access
        if ($user->role === 'teacher') {
            $ownsClass = $user->teacherClasses()->where('id', $rollCall->class_id)->exists();
            if (!$ownsClass) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
        } elseif ($user->role === 'student') {
            if ($rollCall->created_by !== $user->id) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
        }

        $validated = $request->validate([
            'student_ids' => 'required|array',
            'student_ids.*' => 'exists:students,id',
            'is_present' => 'required|boolean',
        ]);

        foreach ($validated['student_ids'] as $studentId) {
            $record = RollCallRecord::where('roll_call_id', $rollCall->id)
                ->where('student_id', $studentId)
                ->first();

            if ($record && $record->status !== 'on_leave') {
                $record->update([
                    'status' => $validated['is_present'] ? 'present' : 'pending',
                    'marked_at' => $validated['is_present'] ? now() : null,
                    'marked_by' => $validated['is_present'] ? $user->id : null,
                ]);
            }
        }

        // Update present count
        $presentCount = RollCallRecord::where('roll_call_id', $rollCall->id)
            ->where('status', 'present')
            ->count();
        $rollCall->update(['present_count' => $presentCount]);

        return response()->json(['message' => 'Marked', 'present_count' => $presentCount]);
    }

    /**
     * Complete a roll call (with batch records submission)
     */
    public function complete(Request $request, RollCall $rollCall)
    {
        $user = $request->user();

        if ($rollCall->status !== 'in_progress') {
            return response()->json(['error' => 'Roll call is not in progress'], 400);
        }

        // Verify access
        if ($user->role === 'teacher') {
            $ownsClass = $user->teacherClasses()->where('id', $rollCall->class_id)->exists();
            if (!$ownsClass) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
        } elseif ($user->role === 'student') {
            if ($rollCall->created_by !== $user->id) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
        }

        // Validate records if provided (new batch mode)
        $validated = $request->validate([
            'records' => 'nullable|array',
            'records.*.student_id' => 'required_with:records|exists:students,id',
            'records.*.status' => 'required_with:records|in:present,pending,on_leave,absent',
            'records.*.marked_at' => 'nullable|date',
        ]);

        DB::beginTransaction();
        try {
            // If records are provided, update them first (batch mode from client)
            if (!empty($validated['records'])) {
                foreach ($validated['records'] as $recordData) {
                    $record = RollCallRecord::where('roll_call_id', $rollCall->id)
                        ->where('student_id', $recordData['student_id'])
                        ->first();

                    if ($record && $record->status !== 'on_leave') {
                        // Only update if not on_leave (preserve leave status)
                        // Parse marked_at and ensure it's in correct timezone
                        $markedAt = null;
                        if ($recordData['marked_at']) {
                            $markedAt = Carbon::parse($recordData['marked_at'])->setTimezone('Asia/Shanghai');
                        }
                        $record->update([
                            'status' => $recordData['status'],
                            'marked_at' => $markedAt,
                            'marked_by' => $recordData['status'] === 'present' ? $user->id : null,
                        ]);
                    }
                }
            }

            // Mark all pending as absent
            RollCallRecord::where('roll_call_id', $rollCall->id)
                ->where('status', 'pending')
                ->update(['status' => 'absent']);

            // Write absent records to attendance_records
            $absentRecords = RollCallRecord::where('roll_call_id', $rollCall->id)
                ->where('status', 'absent')
                ->get();

            $type = $rollCall->rollCallType;
            // 使用配置的节次ID数组，如果没有配置则不创建考勤记录
            $periodIds = $type->period_ids ?? [];
            
            foreach ($absentRecords as $record) {
                $student = Student::find($record->student_id);
                
                // 如果配置了节次，为每个节次创建考勤记录
                if (!empty($periodIds)) {
                    foreach ($periodIds as $index => $periodId) {
                        AttendanceRecord::updateOrCreate(
                            [
                                'student_id' => $record->student_id,
                                'date' => $rollCall->roll_call_time->toDateString(),
                                'period_id' => $periodId,
                            ],
                            [
                                'school_id' => $rollCall->school_id,
                                'class_id' => $rollCall->class_id,
                                'status' => 'leave',
                                'leave_type_id' => $type->leave_type_id,
                                'source_type' => 'roll_call',
                                'source_id' => $rollCall->id,
                                'is_self_applied' => false,
                                'details' => [
                                    'roll_call_type' => $type->name,
                                    'roll_call_time' => $rollCall->roll_call_time->setTimezone('Asia/Shanghai')->format('H:i'),
                                    'original_status' => 'absent',
                                    'roll_call_record_id' => $record->id,
                                    'period_index' => $index + 1,
                                    'total_periods' => count($periodIds),
                                ],
                            ]
                        );
                    }
                } else {
                    // 未配置节次时，创建一条无节次关联的考勤记录
                    AttendanceRecord::updateOrCreate(
                        [
                            'student_id' => $record->student_id,
                            'date' => $rollCall->roll_call_time->toDateString(),
                            'period_id' => null,
                            'source_type' => 'roll_call',
                            'source_id' => $rollCall->id,
                        ],
                        [
                            'school_id' => $rollCall->school_id,
                            'class_id' => $rollCall->class_id,
                            'status' => 'leave',
                            'leave_type_id' => $type->leave_type_id,
                            'is_self_applied' => false,
                            'details' => [
                                'roll_call_type' => $type->name,
                                'roll_call_time' => $rollCall->roll_call_time->setTimezone('Asia/Shanghai')->format('H:i'),
                                'original_status' => 'absent',
                                'roll_call_record_id' => $record->id,
                            ],
                        ]
                    );
                }
            }

            // Update counts
            $presentCount = RollCallRecord::where('roll_call_id', $rollCall->id)
                ->where('status', 'present')
                ->count();
            $onLeaveCount = RollCallRecord::where('roll_call_id', $rollCall->id)
                ->where('status', 'on_leave')
                ->count();

            $rollCall->update([
                'status' => 'completed',
                'present_count' => $presentCount,
                'on_leave_count' => $onLeaveCount,
                'completed_at' => now(),
            ]);

            DB::commit();

            return response()->json($rollCall->fresh()->load('rollCallType', 'records'));
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }


    /**
     * Cancel a roll call (teacher or roll call admin with modify permission)
     */
    public function cancel(Request $request, RollCall $rollCall)
    {
        $user = $request->user();

        // Check permission
        $canCancel = false;
        
        if ($user->role === 'teacher') {
            $ownsClass = $user->teacherClasses()->where('id', $rollCall->class_id)->exists();
            if ($ownsClass) {
                $canCancel = true;
            }
        } elseif (in_array($user->role, ['admin', 'system_admin'])) {
            $canCancel = true;
        } elseif ($user->role === 'student' && $user->student && $rollCall->created_by === $user->id) {
            // Student can cancel their own roll call if they have modify permission
            $admin = RollCallAdmin::where('student_id', $user->student->id)
                ->where('class_id', $rollCall->class_id)
                ->where('is_active', true)
                ->first();
            
            if ($admin && $admin->can_modify_records) {
                $canCancel = true;
            }
        }

        if (!$canCancel) {
            return response()->json(['error' => '您没有权限取消此点名'], 403);
        }

        if ($rollCall->status !== 'in_progress') {
            return response()->json(['error' => 'Only in-progress roll calls can be cancelled'], 400);
        }

        $rollCall->update(['status' => 'cancelled']);
        
        return response()->json(['message' => 'Cancelled']);
    }

    /**
     * Restore a cancelled roll call (teacher or roll call admin with modify permission)
     */
    public function restore(Request $request, RollCall $rollCall)
    {
        $user = $request->user();

        // Check permission
        $canRestore = false;
        
        if ($user->role === 'teacher') {
            $ownsClass = $user->teacherClasses()->where('id', $rollCall->class_id)->exists();
            if ($ownsClass) {
                $canRestore = true;
            }
        } elseif (in_array($user->role, ['admin', 'system_admin', 'department_manager', 'school_admin'])) {
            $canRestore = true;
        } elseif ($user->role === 'student' && $user->student && $rollCall->created_by === $user->id) {
            // Student can restore their own roll call if they have modify permission
            $admin = RollCallAdmin::where('student_id', $user->student->id)
                ->where('class_id', $rollCall->class_id)
                ->where('is_active', true)
                ->first();
            
            if ($admin && $admin->can_modify_records) {
                $canRestore = true;
            }
        }

        if (!$canRestore) {
            return response()->json(['error' => '您没有权限恢复此点名'], 403);
        }

        if ($rollCall->status !== 'cancelled') {
            return response()->json(['error' => '只有已取消的点名才能被恢复'], 400);
        }

        $rollCall->update(['status' => 'in_progress']);
        
        return response()->json(['message' => '点名已恢复']);
    }

    /**
     * Update a record after completion (teacher or authorized roll call admin)
     */
    public function updateRecord(Request $request, RollCall $rollCall, RollCallRecord $record)
    {
        $user = $request->user();

        // Check if user has permission to modify records
        $canModify = false;
        $debugInfo = [
            'user_role' => $user->role,
            'user_id' => $user->id,
            'student_id' => $user->student?->id,
            'rollCall_class_id' => $rollCall->class_id,
            'rollCall_type_id' => $rollCall->roll_call_type_id,
        ];
        
        if ($user->role === 'teacher') {
            $ownsClass = $user->teacherClasses()->where('id', $rollCall->class_id)->exists();
            if ($ownsClass) {
                $canModify = true;
            }
        } elseif (in_array($user->role, ['admin', 'system_admin'])) {
            $canModify = true;
        } elseif ($user->role === 'student' && $user->student) {
            // Check if student is a roll call admin with modify permission
            $admin = RollCallAdmin::where('student_id', $user->student->id)
                ->where('class_id', $rollCall->class_id)
                ->where('is_active', true)
                ->first();
            
            $debugInfo['admin_found'] = $admin ? true : false;
            $debugInfo['admin_can_modify'] = $admin?->can_modify_records;
            $debugInfo['admin_type_ids'] = $admin?->roll_call_type_ids;
            
            if ($admin && $admin->can_modify_records) {
                // Also check if admin is authorized for this roll call type
                $typeIds = $admin->roll_call_type_ids ?? [];
                $debugInfo['type_check'] = in_array($rollCall->roll_call_type_id, $typeIds);
                if (in_array($rollCall->roll_call_type_id, $typeIds)) {
                    $canModify = true;
                }
            }
        }

        \Log::info('updateRecord permission check', $debugInfo + ['canModify' => $canModify]);

        if (!$canModify) {
            return response()->json(['error' => '您没有修改点名记录的权限', 'debug' => $debugInfo], 403);
        }

        $validated = $request->validate([
            'status' => 'required|in:present,absent,on_leave',
        ]);

        $oldStatus = $record->status;
        $record->update([
            'status' => $validated['status'],
            'marked_at' => now(),
            'marked_by' => $user->id,
        ]);

        // Update attendance_records if needed
        if ($rollCall->status === 'completed') {
            // Remove old attendance record
            AttendanceRecord::where('source_type', 'roll_call')
                ->where('source_id', $rollCall->id)
                ->where('student_id', $record->student_id)
                ->delete();

            // Add new if absent
            if ($validated['status'] === 'absent') {
                $type = $rollCall->rollCallType;
                $student = Student::find($record->student_id);
                $periodIds = $type->period_ids ?? [];
                
                // 如果配置了节次，为每个节次创建考勤记录
                if (!empty($periodIds)) {
                    foreach ($periodIds as $index => $periodId) {
                        AttendanceRecord::updateOrCreate(
                            [
                                'student_id' => $record->student_id,
                                'date' => $rollCall->roll_call_time->toDateString(),
                                'period_id' => $periodId,
                            ],
                            [
                                'school_id' => $rollCall->school_id,
                                'class_id' => $rollCall->class_id,
                                'status' => 'leave',
                                'leave_type_id' => $type->leave_type_id,
                                'source_type' => 'roll_call',
                                'source_id' => $rollCall->id,
                                'is_self_applied' => false,
                                'details' => [
                                    'roll_call_type' => $type->name,
                                    'roll_call_time' => $rollCall->roll_call_time->setTimezone('Asia/Shanghai')->format('H:i'),
                                    'original_status' => 'absent',
                                    'roll_call_record_id' => $record->id,
                                    'period_index' => $index + 1,
                                    'total_periods' => count($periodIds),
                                ],
                            ]
                        );
                    }
                } else {
                    // 未配置节次时，创建一条无节次关联的考勤记录
                    AttendanceRecord::updateOrCreate(
                        [
                            'student_id' => $record->student_id,
                            'date' => $rollCall->roll_call_time->toDateString(),
                            'period_id' => null,
                            'source_type' => 'roll_call',
                            'source_id' => $rollCall->id,
                        ],
                        [
                            'school_id' => $rollCall->school_id,
                            'class_id' => $rollCall->class_id,
                            'status' => 'leave',
                            'leave_type_id' => $type->leave_type_id,
                            'is_self_applied' => false,
                            'details' => [
                                'roll_call_type' => $type->name,
                                'roll_call_time' => $rollCall->roll_call_time->setTimezone('Asia/Shanghai')->format('H:i'),
                                'original_status' => 'absent',
                                'roll_call_record_id' => $record->id,
                            ],
                        ]
                    );
                }
            }

            // Recalculate counts
            $presentCount = RollCallRecord::where('roll_call_id', $rollCall->id)
                ->where('status', 'present')
                ->count();
            $onLeaveCount = RollCallRecord::where('roll_call_id', $rollCall->id)
                ->where('status', 'on_leave')
                ->count();

            $rollCall->update([
                'present_count' => $presentCount,
                'on_leave_count' => $onLeaveCount,
            ]);
        }

        return response()->json($record);
    }

    /**
     * Delete a roll call and related records (teacher or roll call admin with modify permission)
     */
    public function destroy(Request $request, RollCall $rollCall)
    {
        $user = $request->user();

        // Check permission: teacher owns class, admin, or roll call admin with modify permission
        $canDelete = false;
        
        if ($user->role === 'teacher') {
            $ownsClass = $user->teacherClasses()->where('id', $rollCall->class_id)->exists();
            if ($ownsClass) {
                $canDelete = true;
            }
        } elseif (in_array($user->role, ['admin', 'system_admin'])) {
            $canDelete = true;
        } elseif ($user->role === 'student' && $user->student && $rollCall->created_by === $user->id) {
            // Student can delete their own roll call if they're an active roll call admin
            $admin = RollCallAdmin::where('student_id', $user->student->id)
                ->where('class_id', $rollCall->class_id)
                ->where('is_active', true)
                ->first();
            
            if ($admin) {
                $canDelete = true;
            }
        }

        if (!$canDelete) {
            return response()->json(['error' => '您没有权限删除此点名'], 403);
        }

        DB::beginTransaction();
        try {
            // Delete related attendance records created by this roll call
            AttendanceRecord::where('source_type', 'roll_call')
                ->where('source_id', $rollCall->id)
                ->delete();

            // Delete roll call records
            RollCallRecord::where('roll_call_id', $rollCall->id)->delete();

            // Delete roll call itself
            $rollCall->delete();

            DB::commit();

            return response()->json(['message' => 'Roll call deleted successfully']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get student attendance info for roll call
     * 
     * Checks if the student already has an attendance record that covers any of the 
     * periods associated with the current roll call type.
     * 
     * For late/early_leave: requires exact period match
     * For absent/leave/excused: uses period intersection (any overlap counts)
     */
    private function getStudentLeaveInfo(Student $student, Carbon $rollCallTime, ?RollCallType $rollCallType = null): ?array
    {
        // Get the period IDs for this roll call type
        $rollCallPeriodIds = $rollCallType?->period_ids ?? [];
        $rollCallPeriodIdsInt = !empty($rollCallPeriodIds) ? array_map('intval', $rollCallPeriodIds) : [];
        
        // Find all non-present attendance records for this date (exclude roll_call source to avoid duplicates)
        $attendanceRecords = AttendanceRecord::where('student_id', $student->id)
            ->whereDate('date', $rollCallTime->toDateString())
            ->whereIn('status', ['leave', 'excused', 'absent', 'late', 'early_leave'])
            ->where('source_type', '!=', 'roll_call')  // Exclude roll call records
            ->where(function ($q) {
                // For leave/excused, require approval; for others (absent, late, early_leave), always show
                $q->whereIn('status', ['absent', 'late', 'early_leave'])
                  ->orWhere(function ($q2) {
                      $q2->whereIn('status', ['leave', 'excused'])
                         ->where(function ($q3) {
                             $q3->where('approval_status', 'approved')
                                ->orWhereNull('approval_status');
                         });
                  });
            })
            ->with('leaveType')
            // 优先处理最新的记录（按更新时间倒序）
            ->orderByDesc('updated_at')
            ->get();

        $rollCallTypeName = $rollCallType?->name ?? '';

        foreach ($attendanceRecords as $record) {
            $details = $record->details ?? [];
            $status = $record->status;
            
            // Get type name based on status
            $typeName = $record->leaveType?->name ?? match($status) {
                'absent' => '旷课',
                'late' => '迟到',
                'early_leave' => '早退',
                default => '请假',
            };
            $inputConfig = $record->leaveType?->input_config ?? [];
            
            // Get the period IDs from the record
            // Priority: details.periods/period_ids (full list) > record.period_id (single)
            $recordPeriodIds = [];
            
            // Check if details has period_ids array (time slot selection)
            if (!empty($details['period_ids']) && is_array($details['period_ids'])) {
                $recordPeriodIds = array_map('intval', $details['period_ids']);
            }
            // Check if details has periods array (旷课等)
            elseif (!empty($details['periods']) && is_array($details['periods'])) {
                $recordPeriodIds = array_map('intval', $details['periods']);
            }
            // Fallback: Check if record has period_id (single period)
            elseif ($record->period_id) {
                $recordPeriodIds = [(int)$record->period_id];
            }
            
            // Get display label for this record
            $displayLabel = $this->getLeaveDisplayLabel($record, $details, $inputConfig);
            
            // Different matching logic based on status
            if (in_array($status, ['late', 'early_leave'])) {
                // Late/Early Leave: require EXACT period match
                // Only show if the record's period_id is in the roll call's period_ids
                if (!empty($rollCallPeriodIdsInt) && !empty($recordPeriodIds)) {
                    $intersection = array_intersect($recordPeriodIds, $rollCallPeriodIdsInt);
                    if (!empty($intersection)) {
                        return [
                            'leave_type_id' => $record->leave_type_id,
                            'detail' => $typeName . '(' . $displayLabel . ')',
                            'status' => $status,
                        ];
                    }
                }
                // No match - skip this record
                continue;
            }
            
            // For absent/leave/excused: use period intersection (any overlap counts)
            if (!empty($rollCallPeriodIdsInt) && !empty($recordPeriodIds)) {
                $intersection = array_intersect($recordPeriodIds, $rollCallPeriodIdsInt);
                
                if (!empty($intersection)) {
                    // Record covers at least one period of this roll call
                    return [
                        'leave_type_id' => $record->leave_type_id,
                        'detail' => $typeName . '(' . $displayLabel . ')',
                        'status' => $status,
                    ];
                }
                // No intersection - this record doesn't cover the roll call periods
                continue;
            }
            
            // Format 1: Check for "option" field (e.g., {"option": "evening_exercise"})
            // This is used by leave types with duration_select input (like 生理假)
            $optionKey = $details['option'] ?? null;
            if ($optionKey && is_string($optionKey)) {
                $optionLabel = $details['option_label'] ?? null;
                
                // Fallback: Find the label for this option from input_config (for old records)
                if (!$optionLabel) {
                    $options = $inputConfig['options'] ?? [];
                    foreach ($options as $opt) {
                        if (($opt['key'] ?? '') === $optionKey) {
                            $optionLabel = $opt['label'] ?? $optionKey;
                            break;
                        }
                    }
                }
                
                $optionLabel = $optionLabel ?: $optionKey;
                
                // Check if this option matches the current roll call type
                $normalizedOptionLabel = str_replace('操', '', $optionLabel);
                $normalizedRollCallTypeName = str_replace(['点名', '操'], '', $rollCallTypeName);
                
                if ($normalizedOptionLabel === $normalizedRollCallTypeName || 
                    str_contains($rollCallTypeName, $optionLabel)) {
                    return [
                        'leave_type_id' => $record->leave_type_id,
                        'detail' => $typeName . '(' . $optionLabel . ')',
                        'status' => $status,
                    ];
                }
                // Option doesn't match current roll call type - skip this record
                continue;
            }
            
            // Format 2: time_slot_id - check if time slot's periods overlap with roll call
            $timeSlotId = $details['time_slot_id'] ?? null;
            if ($timeSlotId) {
                $timeSlot = TimeSlot::find($timeSlotId);
                if ($timeSlot) {
                    $timeSlotPeriodIds = array_map('intval', $timeSlot->period_ids ?? []);
                    
                    // If roll call has periods, check intersection
                    if (!empty($rollCallPeriodIdsInt)) {
                        $intersection = array_intersect($timeSlotPeriodIds, $rollCallPeriodIdsInt);
                        
                        if (!empty($intersection)) {
                            return [
                                'leave_type_id' => $record->leave_type_id,
                                'detail' => $typeName . '(' . $timeSlot->name . ')',
                                'status' => $status,
                            ];
                        }
                    } else {
                        // No roll call periods configured, use time-based check
                        $rollCallTimeOnly = $rollCallTime->format('H:i:s');
                        $start = $timeSlot->time_start;
                        $end = $timeSlot->time_end;

                        if ($rollCallTimeOnly >= $start && $rollCallTimeOnly <= $end) {
                            return [
                                'leave_type_id' => $record->leave_type_id,
                                'detail' => $typeName . '(' . $timeSlot->name . ')',
                                'status' => $status,
                            ];
                        }
                    }
                    continue;
                }
            }
            
            // Full day record (no specific period or time slot)
            // If roll call has no periods configured, treat as matching
            if (empty($recordPeriodIds) && empty($details['option']) && empty($details['time_slot_id'])) {
                return [
                    'leave_type_id' => $record->leave_type_id,
                    'detail' => $typeName . '(' . $displayLabel . ')',
                    'status' => $status,
                ];
            }
        }

        return null;
    }
    
    /**
     * Get display label for a leave record
     */
    private function getLeaveDisplayLabel($record, $details, $inputConfig): string
    {
        // Priority 1: display_label from details
        if (!empty($details['display_label'])) {
            return $details['display_label'];
        }
        
        // Priority 2: text field (for text input types like "其他")
        if (!empty($details['text'])) {
            $textLabel = $details['text'];
            // If period_names exist, append them
            if (!empty($details['period_names']) && is_array($details['period_names'])) {
                $textLabel .= '-' . implode('、', $details['period_names']);
            }
            return $textLabel;
        }
        
        // Priority 3: time_slot_name from details
        if (!empty($details['time_slot_name'])) {
            return $details['time_slot_name'];
        }
        
        // Priority 4: option_label from details
        if (!empty($details['option_label'])) {
            return $details['option_label'];
        }
        
        // Priority 5: Look up option in input_config
        $optionKey = $details['option'] ?? null;
        if ($optionKey && is_string($optionKey)) {
            $options = $inputConfig['options'] ?? [];
            foreach ($options as $opt) {
                if (($opt['key'] ?? '') === $optionKey) {
                    return $opt['label'] ?? $optionKey;
                }
            }
            return $optionKey;
        }
        
        // Default
        return '全天';
    }
}
