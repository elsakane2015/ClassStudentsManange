<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Remove foreign key from period_id since we now use JSON-configured periods
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
    }

    /**
     * Reverse the migrations.
     * Note: class_periods table has been removed, so we can't restore the foreign key
     */
    public function down(): void
    {
        // class_periods table no longer exists, period_id now references SystemSetting IDs
        // No foreign key to restore
    }
};
