<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Remove foreign key from period_id since we now use JSON-configured periods
     */
    public function up(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            // Drop the foreign key constraint on period_id
            // The column name format for foreign key is: table_column_foreign
            try {
                $table->dropForeign(['period_id']);
            } catch (\Exception $e) {
                // Foreign key may not exist, ignore
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->foreign('period_id')->references('id')->on('class_periods')->onDelete('set null');
        });
    }
};
