<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 为早操和晚操创建class_period记录
        $schoolId = 1; // 假设school_id为1
        
        // 检查是否已存在
        $morningExercise = \App\Models\ClassPeriod::where('name', '早操')->first();
        if (!$morningExercise) {
            \App\Models\ClassPeriod::create([
                'school_id' => $schoolId,
                'name' => '早操',
                'start_time' => '07:00:00',
                'end_time' => '07:30:00',
                'ordinal' => 0, // 在第1节之前
            ]);
        }
        
        $eveningExercise = \App\Models\ClassPeriod::where('name', '晚操')->first();
        if (!$eveningExercise) {
            \App\Models\ClassPeriod::create([
                'school_id' => $schoolId,
                'name' => '晚操',
                'start_time' => '18:00:00',
                'end_time' => '18:30:00',
                'ordinal' => 99, // 在最后
            ]);
        }
    }

    public function down(): void
    {
        \App\Models\ClassPeriod::where('name', '早操')->delete();
        \App\Models\ClassPeriod::where('name', '晚操')->delete();
    }
};
