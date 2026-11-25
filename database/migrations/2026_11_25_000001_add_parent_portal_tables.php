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
                'student',
                'parent'
            ) NOT NULL DEFAULT 'student'");
        } else {
            Schema::table('users', function (Blueprint $table) {
                $table->string('role', 32)->default('student')->change();
            });
        }

        if (! Schema::hasTable('parent_student')) {
            Schema::create('parent_student', function (Blueprint $table) {
                $table->id();
                $table->foreignId('parent_user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['parent_user_id', 'student_id'], 'parent_student_unique');
                $table->index('student_id', 'parent_student_student_idx');
            });
        }

        if (! Schema::hasTable('password_reset_codes')) {
            Schema::create('password_reset_codes', function (Blueprint $table) {
                $table->id();
                $table->string('email')->index();
                $table->string('code_hash');
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->timestamp('expires_at');
                $table->timestamp('used_at')->nullable();
                $table->timestamps();

                $table->index(['email', 'created_at'], 'password_reset_codes_email_created_idx');
            });
        }

        if (Schema::hasTable('system_settings')) {
            DB::table('system_settings')->updateOrInsert(
                ['key' => 'parent_default_password'],
                [
                    'value' => DB::table('system_settings')->where('key', 'parent_default_password')->value('value') ?: '123456',
                    'description' => '父母端账号默认初始密码',
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        // Backfill existing student parent_email records into parent_student & parent user accounts.
        if (Schema::hasTable('students') && Schema::hasColumn('students', 'parent_email')) {
            try {
                app(\App\Services\ParentAccountSyncService::class)->syncAllStudents();
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Parent account backfill skipped during migration', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_codes');
        Schema::dropIfExists('parent_student');

        if (DB::getDriverName() === 'mysql') {
            DB::table('users')->where('role', 'parent')->delete();
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM(
                'system_admin',
                'school_admin',
                'department_manager',
                'duty_teacher',
                'teacher',
                'student'
            ) NOT NULL DEFAULT 'student'");
        }
    }
};
