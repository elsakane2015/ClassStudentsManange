<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 获取第一个学校，如果不存在则跳过
        $school = \App\Models\School::first();
        if (!$school) {
            // 学校不存在时跳过，等管理员在系统中添加
            return;
        }
        
        $schoolId = $school->id;
        
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
