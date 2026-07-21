<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM(
                'system_admin',
                'school_admin',
                'department_manager',
                'duty_teacher',
                'teacher',
                'student'
            ) NOT NULL DEFAULT 'student'");
        }

        Schema::create('department_duty_teachers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['department_id', 'user_id']);
        });

        Schema::create('evening_study_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('name');
            $table->string('color', 20)->default('gray');
            $table->string('base_status', 20)->default('excused');
            $table->boolean('is_default')->default(false);
            $table->boolean('student_requestable')->default(false);
            $table->foreignId('leave_type_id')->nullable()->constrained('leave_types')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index(['school_id', 'is_active', 'sort_order'], 'evening_status_school_active_order');
        });

        Schema::create('evening_study_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->unsignedBigInteger('period_id');
            $table->string('period_name_snapshot');
            $table->date('attendance_date');
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('in_progress');
            $table->unsignedInteger('total_students')->default(0);
            $table->unsignedInteger('normal_count')->default(0);
            $table->unsignedInteger('exception_count')->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['attendance_date', 'class_id', 'period_id'], 'evening_session_date_class_period');
            $table->index(['created_by', 'attendance_date']);
        });

        Schema::create('boarding_suspensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('class_id')->nullable()->constrained('classes')->nullOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->text('reason');
            $table->text('destination')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('revoke_reason')->nullable();
            $table->timestamps();
            $table->index(['student_id', 'start_date', 'end_date'], 'boarding_suspension_student_dates');
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->string('scene', 30)->default('regular')->after('period_id');
            $table->boolean('counts_in_day_stats')->default(true)->after('scene');
            $table->string('period_name_snapshot')->nullable()->after('counts_in_day_stats');
            $table->foreignId('requested_evening_status_id')->nullable()->after('details')
                ->constrained('evening_study_statuses')->nullOnDelete();
            $table->string('requested_status_name_snapshot')->nullable()->after('requested_evening_status_id');
            $table->foreignId('evening_study_status_id')->nullable()->after('requested_status_name_snapshot')
                ->constrained('evening_study_statuses')->nullOnDelete();
            $table->string('status_name_snapshot')->nullable()->after('evening_study_status_id');
            $table->text('destination')->nullable()->after('status_name_snapshot');
            $table->foreignId('evening_study_session_id')->nullable()->after('destination')
                ->constrained('evening_study_sessions')->nullOnDelete();
            $table->foreignId('boarding_suspension_id')->nullable()->after('evening_study_session_id')
                ->constrained('boarding_suspensions')->nullOnDelete();
            $table->timestamp('manually_overridden_at')->nullable()->after('boarding_suspension_id');

            $table->index(['scene', 'date'], 'attendance_scene_date');
            $table->index(['counts_in_day_stats', 'date'], 'attendance_day_stats_date');
        });

        $this->seedPermissions();
        $this->seedStatuses();
        $this->upgradePeriodConfiguration();
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropForeign(['requested_evening_status_id']);
            $table->dropForeign(['evening_study_status_id']);
            $table->dropForeign(['evening_study_session_id']);
            $table->dropForeign(['boarding_suspension_id']);
            $table->dropIndex('attendance_scene_date');
            $table->dropIndex('attendance_day_stats_date');
            $table->dropColumn([
                'scene',
                'counts_in_day_stats',
                'period_name_snapshot',
                'requested_evening_status_id',
                'requested_status_name_snapshot',
                'evening_study_status_id',
                'status_name_snapshot',
                'destination',
                'evening_study_session_id',
                'boarding_suspension_id',
                'manually_overridden_at',
            ]);
        });

        Schema::dropIfExists('boarding_suspensions');
        Schema::dropIfExists('evening_study_sessions');
        Schema::dropIfExists('evening_study_statuses');
        Schema::dropIfExists('department_duty_teachers');

        $permissionIds = DB::table('permissions')
            ->whereIn('name', [
                'evening_study.take',
                'evening_study.view',
                'evening_study.modify',
                'evening_study.leave_approve',
                'evening_study.settings',
                'boarding_suspensions.manage',
            ])
            ->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        DB::table('system_settings')->where('key', 'boarding_suspension_status_id')->delete();

        if (DB::getDriverName() === 'mysql') {
            DB::table('users')->where('role', 'duty_teacher')->update(['role' => 'teacher']);
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM(
                'system_admin',
                'school_admin',
                'department_manager',
                'teacher',
                'student'
            ) NOT NULL DEFAULT 'student'");
        }
    }

    private function seedPermissions(): void
    {
        $permissions = [
            ['name' => 'evening_study.take', 'display_name' => '执行夜自习点名', 'category' => 'evening_study'],
            ['name' => 'evening_study.view', 'display_name' => '查看夜自习记录', 'category' => 'evening_study'],
            ['name' => 'evening_study.modify', 'display_name' => '修改夜自习记录', 'category' => 'evening_study'],
            ['name' => 'evening_study.leave_approve', 'display_name' => '审批夜自习请假', 'category' => 'evening_study'],
            ['name' => 'evening_study.settings', 'display_name' => '配置夜自习', 'category' => 'evening_study'],
            ['name' => 'boarding_suspensions.manage', 'display_name' => '管理暂停住宿许可', 'category' => 'evening_study'],
        ];

        foreach ($permissions as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $permission['name']],
                array_merge($permission, ['updated_at' => now(), 'created_at' => now()])
            );
            $permissionId = DB::table('permissions')->where('name', $permission['name'])->value('id');

            $roleActions = [
                'system_admin' => ['create' => true, 'read' => true, 'update' => true, 'delete' => true],
                'school_admin' => ['read' => true, 'update' => true],
                'department_manager' => ['read' => true, 'update' => true],
                'teacher' => ['read' => true, 'update' => true],
                'duty_teacher' => ['create' => true, 'read' => true, 'update' => true],
            ];

            foreach ($roleActions as $role => $actions) {
                if ($permission['name'] === 'evening_study.settings' && $role !== 'system_admin') {
                    continue;
                }
                if ($permission['name'] === 'evening_study.take' && !in_array($role, ['system_admin', 'duty_teacher'], true)) {
                    continue;
                }
                if ($permission['name'] === 'boarding_suspensions.manage' && $role === 'duty_teacher') {
                    continue;
                }

                DB::table('role_permissions')->updateOrInsert(
                    ['role' => $role, 'permission_id' => $permissionId],
                    [
                        'can_create' => $actions['create'] ?? false,
                        'can_read' => $actions['read'] ?? false,
                        'can_update' => $actions['update'] ?? false,
                        'can_delete' => $actions['delete'] ?? false,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }
    }

    private function seedStatuses(): void
    {
        foreach (DB::table('schools')->pluck('id') as $schoolId) {
            $sickTypeId = DB::table('leave_types')
                ->where('school_id', $schoolId)
                ->where('slug', 'sick_leave')
                ->value('id');
            $otherTypeId = DB::table('leave_types')
                ->where('school_id', $schoolId)
                ->whereIn('slug', ['qita', 'other'])
                ->value('id');

            $statuses = [
                ['name' => '正常', 'color' => 'green', 'base_status' => 'present', 'is_default' => true, 'student_requestable' => false, 'leave_type_id' => null],
                ['name' => '病假在宿舍', 'color' => 'blue', 'base_status' => 'excused', 'is_default' => false, 'student_requestable' => true, 'leave_type_id' => $sickTypeId],
                ['name' => '病假在家', 'color' => 'indigo', 'base_status' => 'excused', 'is_default' => false, 'student_requestable' => true, 'leave_type_id' => $sickTypeId],
                ['name' => '在学生会', 'color' => 'purple', 'base_status' => 'excused', 'is_default' => false, 'student_requestable' => (bool) $otherTypeId, 'leave_type_id' => $otherTypeId],
                ['name' => '在图书馆', 'color' => 'cyan', 'base_status' => 'excused', 'is_default' => false, 'student_requestable' => (bool) $otherTypeId, 'leave_type_id' => $otherTypeId],
                ['name' => '暂停住宿', 'color' => 'gray', 'base_status' => 'excused', 'is_default' => false, 'student_requestable' => false, 'leave_type_id' => null],
                ['name' => '未到', 'color' => 'red', 'base_status' => 'absent', 'is_default' => false, 'student_requestable' => false, 'leave_type_id' => null],
            ];

            foreach ($statuses as $index => $status) {
                DB::table('evening_study_statuses')->updateOrInsert(
                    ['school_id' => $schoolId, 'name' => $status['name']],
                    array_merge($status, [
                        'school_id' => $schoolId,
                        'is_active' => true,
                        'sort_order' => $index,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])
                );
            }
        }

        $suspensionStatusId = DB::table('evening_study_statuses')
            ->where('name', '暂停住宿')
            ->orderBy('school_id')
            ->value('id');
        if ($suspensionStatusId) {
            DB::table('system_settings')->updateOrInsert(
                ['key' => 'boarding_suspension_status_id'],
                [
                    'value' => (string) $suspensionStatusId,
                    'description' => '暂停住宿许可对应的夜自习状态',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    private function upgradePeriodConfiguration(): void
    {
        $setting = DB::table('system_settings')->where('key', 'attendance_periods')->first();
        if (!$setting) {
            return;
        }

        $periods = json_decode((string) $setting->value, true);
        if (!is_array($periods)) {
            return;
        }

        $periods = array_map(function ($period) {
            $period['audience_scope'] = $period['audience_scope'] ?? 'all';
            $period['scene'] = $period['scene'] ?? 'regular';
            $period['counts_in_day_stats'] = $period['counts_in_day_stats'] ?? true;
            $period['is_active'] = $period['is_active'] ?? true;
            return $period;
        }, $periods);

        DB::table('system_settings')->where('id', $setting->id)->update([
            'value' => json_encode($periods, JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);
    }
};
