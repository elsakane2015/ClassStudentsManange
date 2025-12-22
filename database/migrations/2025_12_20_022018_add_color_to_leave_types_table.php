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
            $table->string('color', 20)->default('gray')->after('student_requestable')->comment('显示颜色');
        });

        // Set default colors for existing types
        DB::table('leave_types')->where('slug', 'sick_leave')->update(['color' => 'purple']);
        DB::table('leave_types')->where('slug', 'personal_leave')->update(['color' => 'blue']);
        DB::table('leave_types')->where('slug', 'health_leave')->update(['color' => 'pink']);
        DB::table('leave_types')->where('slug', 'absent')->update(['color' => 'red']);
        DB::table('leave_types')->where('slug', 'late')->update(['color' => 'yellow']);
        DB::table('leave_types')->where('slug', 'early_leave')->update(['color' => 'orange']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropColumn('color');
        });
    }
};
