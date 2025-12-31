<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Drop deprecated class_periods table - periods now managed via SystemSetting (attendance_periods)
     */
    public function up(): void
    {
        // First drop the foreign key if it exists
        try {
            Schema::table('attendance_records', function (Blueprint $table) {
                $table->dropForeign(['period_id']);
            });
        } catch (\Exception $e) {
            // Foreign key may already be dropped
        }
        
        Schema::dropIfExists('class_periods');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Recreate class_periods table if needed (for rollback)
        if (!Schema::hasTable('class_periods')) {
            Schema::create('class_periods', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->time('start_time');
                $table->time('end_time');
                $table->integer('ordinal');
                $table->timestamps();
            });
        }
    }
};
