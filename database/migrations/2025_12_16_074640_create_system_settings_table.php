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
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        // Seed default settings
        DB::table('system_settings')->insert([
            [
                'key' => 'attendance_auto_mark_time',
                'value' => '08:30',
                'description' => 'Time to automatically mark students as present if no action taken',
                'created_at' => now(), 'updated_at' => now()
            ],
            [
                'key' => 'daily_lessons_count',
                'value' => '8',
                'description' => 'Total number of lessons per day for stats',
                'created_at' => now(), 'updated_at' => now()
            ],
            [
                'key' => 'absent_lessons_as_day',
                'value' => '3',
                'description' => 'Number of absent lessons that count as one absent day',
                'created_at' => now(), 'updated_at' => now()
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
