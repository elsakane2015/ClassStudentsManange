<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Default dashboard stats configuration
        $defaultConfig = [
            'student' => [
                'show_my_pending' => true,
                'show_normal_attendance' => true,
                'show_all_leave_types' => true,  // Show all active leave types
            ],
            'class_admin' => [  // Student class admin + Teacher
                'show_pending_approval' => true,
                'show_student_count' => true,
                'show_all_leave_types' => true,
            ],
            'school_admin' => [
                'show_pending_approval' => true,
                'show_student_count' => true,
                'show_all_leave_types' => true,
            ],
        ];

        // Insert or update the setting
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'dashboard_stats_config'],
            [
                'value' => json_encode($defaultConfig),
                'description' => '仪表盘统计项配置',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('system_settings')->where('key', 'dashboard_stats_config')->delete();
    }
};
