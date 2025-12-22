<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->boolean('student_requestable')->default(false)->after('is_active')->comment('学生是否可以申请此类型');
        });

        // Set default values for common student-requestable types
        DB::table('leave_types')->where('slug', 'sick_leave')->update(['student_requestable' => true]);
        DB::table('leave_types')->where('slug', 'personal_leave')->update(['student_requestable' => true]);
        DB::table('leave_types')->where('slug', 'health_leave')->update(['student_requestable' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropColumn('student_requestable');
        });
    }
};
