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
        Schema::table('roll_call_records', function (Blueprint $table) {
            $table->string('leave_status', 50)->nullable()->after('leave_detail')
                ->comment('Original attendance status: leave, excused, absent, late, early_leave');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('roll_call_records', function (Blueprint $table) {
            $table->dropColumn('leave_status');
        });
    }
};
