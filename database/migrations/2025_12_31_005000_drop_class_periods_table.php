<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Drop deprecated class_periods table - periods now managed via SystemSetting (attendance_periods)
     */
    public function up(): void
    {
        // Check if the foreign key exists before trying to drop it
        $foreignKeyExists = DB::select("
            SELECT COUNT(*) as count 
            FROM information_schema.TABLE_CONSTRAINTS 
            WHERE CONSTRAINT_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'attendance_records' 
            AND CONSTRAINT_NAME = 'attendance_records_period_id_foreign'
            AND CONSTRAINT_TYPE = 'FOREIGN KEY'
        ");
        
        if ($foreignKeyExists[0]->count > 0) {
            Schema::table('attendance_records', function (Blueprint $table) {
                $table->dropForeign(['period_id']);
            });
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
