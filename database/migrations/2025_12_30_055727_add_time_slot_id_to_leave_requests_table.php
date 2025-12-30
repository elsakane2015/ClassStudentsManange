<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->foreignId('time_slot_id')->nullable()->after('half_day')
                  ->constrained('time_slots')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropForeign(['time_slot_id']);
            $table->dropColumn('time_slot_id');
        });
    }
};
