<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LeaveRequestController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\StudentController;

use App\Http\Controllers\Api\SemesterController;
use App\Http\Controllers\Api\LeaveTypeController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\SchoolClassController;
use App\Http\Controllers\Api\OptionsController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\AttendanceExportController;
use App\Http\Controllers\Api\WechatController;
use App\Http\Controllers\Api\ResendController;
use App\Http\Controllers\Api\SmsController;
use App\Http\Controllers\Api\BoardingSuspensionController;
use App\Http\Controllers\Api\EveningStudyController;
use App\Http\Controllers\Api\EveningStudyStatusController;
use App\Http\Controllers\InstallController;

// Installation routes (no auth required)
Route::prefix('install')->group(function () {
    Route::get('/check', [InstallController::class, 'checkInstalled']);
    Route::get('/requirements', [InstallController::class, 'checkRequirements']);
    Route::post('/test-database', [InstallController::class, 'testDatabase']);
    Route::post('/run', [InstallController::class, 'install']);
});

// Public school info (no auth required)
Route::get('/school/info', [\App\Http\Controllers\Api\SchoolController::class, 'info']);

// Public routes
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::get('/attendance/auto-mark', [AttendanceController::class, 'triggerAutoMark']); // Trigger auto-mark manually (debug)


// WeChat callback routes (no auth required)
Route::match(['get', 'post'], '/wechat/callback/system', [WechatController::class, 'callbackSystem']);
Route::match(['get', 'post'], '/wechat/callback/teacher/{teacherId}', [WechatController::class, 'callbackTeacher']);

// Protected routes
Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/user/change-password', [AuthController::class, 'changePassword']);

    // School Settings (Admin only)
    Route::put('/school/update', [\App\Http\Controllers\Api\SchoolController::class, 'update']);

    // Admin Settings
    Route::apiResource('semesters', SemesterController::class);
    Route::apiResource('leave-types', LeaveTypeController::class);
    Route::apiResource('departments', DepartmentController::class);
    Route::apiResource('admin/classes', SchoolClassController::class);
    Route::apiResource('users', UserController::class); // Managing users (managers, teachers)
    Route::get('admin/teachers', [SchoolClassController::class, 'availableTeachers']);
    Route::post('admin/classes/{id}/toggle-graduated', [SchoolClassController::class, 'toggleGraduated']);

    // Permission Management (System Admin Only)
    Route::get('/permissions', [PermissionController::class, 'index']);
    Route::get('/permissions/matrix', [PermissionController::class, 'getRolePermissions']);
    Route::post('/permissions/update', [PermissionController::class, 'updateRolePermission']);
    Route::post('/permissions/batch-update', [PermissionController::class, 'batchUpdate']);

    // Leave Requests
    Route::get('/leave-requests', [LeaveRequestController::class, 'index']);
    Route::post('/leave-requests', [LeaveRequestController::class, 'store'])->middleware('throttle:10,1');
    Route::delete('/leave-requests/{id}', [LeaveRequestController::class, 'destroy']);
    Route::post('/leave-requests/{id}/approve', [LeaveRequestController::class, 'approve']);
    Route::post('/leave-requests/{id}/reject', [LeaveRequestController::class, 'reject']);

    // Evening study attendance and temporary boarding suspension
    Route::get('/evening-study/periods', [EveningStudyController::class, 'periods']);
    Route::get('/evening-study/classes', [EveningStudyController::class, 'classes']);
    Route::post('/evening-study/sessions', [EveningStudyController::class, 'start']);
    Route::get('/evening-study/sessions/{eveningStudySession}', [EveningStudyController::class, 'show']);
    Route::put('/evening-study/sessions/{eveningStudySession}/records', [EveningStudyController::class, 'updateRecords']);
    Route::post('/evening-study/teacher-leave', [EveningStudyController::class, 'markLeave']);
    Route::post('/evening-study/sessions/{eveningStudySession}/complete', [EveningStudyController::class, 'complete']);
    Route::post('/evening-study/sessions/{eveningStudySession}/reopen', [EveningStudyController::class, 'reopen']);
    Route::get('/evening-study/history', [EveningStudyController::class, 'history']);
    Route::get('/evening-study-statuses', [EveningStudyStatusController::class, 'index']);
    Route::post('/evening-study-statuses', [EveningStudyStatusController::class, 'store']);
    Route::put('/evening-study-statuses/{eveningStudyStatus}', [EveningStudyStatusController::class, 'update']);
    Route::delete('/evening-study-statuses/{eveningStudyStatus}', [EveningStudyStatusController::class, 'destroy']);
    Route::post('/evening-study-statuses/suspension-status', [EveningStudyStatusController::class, 'setSuspensionStatus']);
    Route::get('/boarding-suspensions', [BoardingSuspensionController::class, 'index']);
    Route::post('/boarding-suspensions', [BoardingSuspensionController::class, 'store']);
    Route::post('/boarding-suspensions/{boardingSuspension}/revoke', [BoardingSuspensionController::class, 'revoke']);

    // Leave Image Upload
    Route::get('/leave-image/settings', [\App\Http\Controllers\Api\LeaveImageController::class, 'settings']);
    Route::put('/leave-image/settings', [\App\Http\Controllers\Api\LeaveImageController::class, 'updateSettings']);
    Route::post('/leave-image/upload', [\App\Http\Controllers\Api\LeaveImageController::class, 'upload']);
    Route::delete('/leave-image', [\App\Http\Controllers\Api\LeaveImageController::class, 'delete']);

    // Attendance
    // Attendance
    Route::get('/attendance/stats', [AttendanceController::class, 'stats']); // Teacher Dashboard Stats
    Route::get('/attendance/overview', [AttendanceController::class, 'overview']); // Helper for hierarchical view
    Route::get('/attendance/details', [AttendanceController::class, 'details']); // Get detailed student list
    Route::get('/attendance/export', [AttendanceExportController::class, 'export']); // Export attendance to Excel
    Route::get('/attendance/export-options', [AttendanceExportController::class, 'options']); // Get export options
    Route::get('/attendance/student-records', [AttendanceController::class, 'studentRecords']); // Get all records for a student
    Route::get('/attendance/calendar-summary', [AttendanceController::class, 'calendarSummary']); // Calendar summary for teacher dashboard

    // WeChat Push Management
    Route::get('/wechat/status', [WechatController::class, 'getWechatStatus']); // Get wechat menu visibility
    Route::get('/wechat/settings', [WechatController::class, 'getSettings']); // Admin: get settings
    Route::post('/wechat/settings', [WechatController::class, 'saveSettings']); // Admin: save settings
    Route::get('/wechat/teacher-configs', [WechatController::class, 'getTeacherConfigs']); // Admin: get teacher config list
    Route::get('/wechat/binding-list', [WechatController::class, 'getBindingList']); // Admin: get all bindings
    Route::delete('/wechat/admin-unbind/{id}', [WechatController::class, 'adminUnbind']); // Admin: unbind user
    Route::get('/wechat/teacher-config', [WechatController::class, 'getTeacherConfig']); // Teacher: get my config
    Route::post('/wechat/teacher-config', [WechatController::class, 'saveTeacherConfig']); // Teacher: save my config
    Route::post('/wechat/verify-config', [WechatController::class, 'verifyTeacherConfig']); // Teacher: verify config
    Route::delete('/wechat/teacher-unbind/{bindingId}', [WechatController::class, 'teacherUnbind']); // Teacher: unbind user
    Route::get('/wechat/manager-status', [WechatController::class, 'getManagerBindStatus']); // Manager: get bind status
    Route::delete('/wechat/manager-unbind', [WechatController::class, 'managerUnbind']); // Manager: unbind self
    Route::get('/wechat/qrcode', [WechatController::class, 'getBindQrcode']); // Get bind QR code

    // Resend parent email notifications
    Route::get('/resend/settings', [ResendController::class, 'getSettings']);
    Route::post('/resend/settings', [ResendController::class, 'saveSettings']);
    Route::post('/resend/test', [ResendController::class, 'sendTest']);
    Route::get('/resend/teacher-settings', [ResendController::class, 'getTeacherSettings']);
    Route::post('/resend/teacher-settings', [ResendController::class, 'saveTeacherSettings']);
    Route::get('/sms/settings', [SmsController::class, 'getSettings']);
    Route::post('/sms/settings', [SmsController::class, 'saveSettings']);
    Route::post('/sms/test', [SmsController::class, 'sendTest']);

    Route::get('/attendance', [AttendanceController::class, 'index']);
    Route::get('/calendar', [AttendanceController::class, 'calendar']);
    Route::get('/student/stats', [AttendanceController::class, 'studentStats']); // Student's own stats
    Route::get('/student/details', [AttendanceController::class, 'studentDetails']); // Student's attendance details
    Route::post('/attendance', [AttendanceController::class, 'store']); // Manual add
    Route::post('/attendance/bulk', [AttendanceController::class, 'bulkStore']); // Manual bulk add
    Route::post('/attendance/batch', [AttendanceController::class, 'batchStore']); // Batch add for class admin
    Route::delete('/attendance/records', [AttendanceController::class, 'deleteRecord']); // Delete record

    // Students / Admin
    Route::get('/students/debug', [StudentController::class, 'debug']); // Debug
    Route::get('/students/import-template', [StudentController::class, 'template']);
    Route::post('/students/import', [StudentController::class, 'import']);
    Route::post('/students/bulk-update', [StudentController::class, 'bulkUpdate']);
    Route::apiResource('students', StudentController::class); // Added full CRUD for students
    Route::post('/students/{id}/toggle-manager', [StudentController::class, 'toggleManager']);
    Route::get('/student/is-class-admin', [StudentController::class, 'isClassAdmin']);
    Route::post('/students/{id}/toggle-class-admin', [StudentController::class, 'toggleClassAdmin']);
    Route::post('/students/bulk-delete', [StudentController::class, 'bulkDestroy']);
    
    // Class/Utility routes
    Route::get('/options/departments', [OptionsController::class, 'departments']);
    Route::get('/options/grades', [OptionsController::class, 'grades']);
    Route::get('/options/classes', [OptionsController::class, 'classes']);
    
    Route::get('/class-periods', function(Request $request) {
        // Periods are now managed via SystemSetting (attendance_periods)
        $attendancePeriods = \App\Models\SystemSetting::where('key', 'attendance_periods')->value('value');
        
        if (!$attendancePeriods) {
            return response()->json([]);
        }
        
        try {
            $periods = json_decode($attendancePeriods, true) ?: [];
            return response()->json(collect($periods)
                ->filter(fn ($period) => ($period['scene'] ?? 'regular') === 'regular')
                ->filter(fn ($period) => $period['is_active'] ?? true)
                ->values());
        } catch (\Exception $e) {
            return response()->json([]);
        }
    });
    // System Settings
    Route::get('/settings', [\App\Http\Controllers\Api\SystemSettingController::class, 'index']);
    Route::post('/settings', [\App\Http\Controllers\Api\SystemSettingController::class, 'update']);
    Route::post('/settings/cleanup-period', [\App\Http\Controllers\Api\SystemSettingController::class, 'cleanupPeriod']);

    // Time Slots (Admin only)
    Route::apiResource('time-slots', \App\Http\Controllers\Api\TimeSlotController::class);

    // Roll Call Types (Teacher manages for their classes)
    Route::post('roll-call-types/batch', [\App\Http\Controllers\Api\RollCallTypeController::class, 'batchStore']);
    Route::put('roll-call-types/batch', [\App\Http\Controllers\Api\RollCallTypeController::class, 'batchUpdate']);
    Route::apiResource('roll-call-types', \App\Http\Controllers\Api\RollCallTypeController::class);

    // Roll Call Admins (Teacher manages for their classes)
    Route::apiResource('roll-call-admins', \App\Http\Controllers\Api\RollCallAdminController::class);

    // Roll Calls
    Route::get('/roll-calls/in-progress', [\App\Http\Controllers\Api\RollCallController::class, 'inProgress']);
    Route::get('/roll-calls/stats', [\App\Http\Controllers\Api\RollCallController::class, 'stats']);
    Route::post('/roll-calls/{rollCall}/mark', [\App\Http\Controllers\Api\RollCallController::class, 'mark']);
    Route::post('/roll-calls/{rollCall}/complete', [\App\Http\Controllers\Api\RollCallController::class, 'complete']);
    Route::post('/roll-calls/{rollCall}/cancel', [\App\Http\Controllers\Api\RollCallController::class, 'cancel']);
    Route::post('/roll-calls/{rollCall}/restore', [\App\Http\Controllers\Api\RollCallController::class, 'restore']);
    Route::put('/roll-calls/{rollCall}/records/{record}', [\App\Http\Controllers\Api\RollCallController::class, 'updateRecord']);
    Route::apiResource('roll-calls', \App\Http\Controllers\Api\RollCallController::class)->only(['index', 'store', 'show', 'destroy']);

Route::get('/debug-migrate', function () {
    try {
        \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
        return response()->json(['output' => \Illuminate\Support\Facades\Artisan::output()]);
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
}); // End debug-migrate

}); // End middleware group

Route::get('/debug-seed-leave-types', function () {
    try {
        $types = [
            ['name' => '迟到', 'slug' => 'late', 'description' => '迟到', 'input_type' => 'time', 'input_config' => ['format' => 'HH:mm']],
            ['name' => '旷课', 'slug' => 'absent', 'description' => '无故缺席', 'input_type' => 'period_select', 'input_config' => ['max_periods' => 8]],
            ['name' => '早退', 'slug' => 'early_leave', 'description' => '早退', 'input_type' => 'time', 'input_config' => ['format' => 'HH:mm']],
            ['name' => '病假', 'slug' => 'sick_leave', 'description' => '因病请假', 'input_type' => 'duration_select', 'input_config' => ['options' => ['1 period', 'half_day', 'full_day']]],
            ['name' => '事假', 'slug' => 'personal_leave', 'description' => '因事请假', 'input_type' => 'duration_select', 'input_config' => ['options' => ['1 period', 'half_day', 'full_day']]],
            ['name' => '生理假', 'slug' => 'menstrual_leave', 'description' => '生理期假', 'input_type' => 'duration_select', 'input_config' => ['options' => ['half_day', 'full_day', 'morning_exercise', 'evening_exercise']]],
        ];

        foreach ($types as $type) {
            \App\Models\LeaveType::updateOrCreate(
                ['slug' => $type['slug']],
                $type
            );
        }
        return response()->json(['message' => 'Leave types seeded successfully']);
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
});

Route::get('/debug-fix-db', function () {
    try {
        \DB::reconnect();
        
        // Use raw SQL to avoid information_schema queries that might be timing out
        try {
            \DB::statement("ALTER TABLE leave_types ADD COLUMN input_type VARCHAR(255) NULL AFTER description");
        } catch (\Exception $e) { /* Ignore if exists */ }
        
        try {
            \DB::statement("ALTER TABLE leave_types ADD COLUMN input_config JSON NULL AFTER input_type");
        } catch (\Exception $e) { /* Ignore if exists */ }

        try {
            \DB::statement("ALTER TABLE attendance_records ADD COLUMN leave_type_id BIGINT UNSIGNED NULL AFTER status");
            \DB::statement("ALTER TABLE attendance_records ADD CONSTRAINT attendance_records_leave_type_id_foreign FOREIGN KEY (leave_type_id) REFERENCES leave_types(id) ON DELETE SET NULL");
        } catch (\Exception $e) { /* Ignore if exists */ }

        try {
            \DB::statement("ALTER TABLE attendance_records ADD COLUMN details JSON NULL AFTER leave_type_id");
        } catch (\Exception $e) { /* Ignore if exists */ }

        $columns = \DB::select("SHOW COLUMNS FROM leave_types");
        return response()->json(['message' => 'Database schema fixed manually (Raw SQL).', 'columns' => $columns]);
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
});
