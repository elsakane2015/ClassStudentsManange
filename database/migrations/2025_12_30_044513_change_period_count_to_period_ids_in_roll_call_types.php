<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 将 period_count 字段改为 period_ids (JSON数组)，支持多选节次
     */
    public function up(): void
    {
        // 1. 添加新字段 period_ids
        Schema::table('roll_call_types', function (Blueprint $table) {
            $table->json('period_ids')->nullable()->after('absent_status')
                ->comment('关联的节次ID数组，用于生成考勤记录');
        });

        // 2. 迁移数据：将 period_count 转换为 period_ids
        // Note: class_periods table is deprecated, period_ids will be empty initially
        // They should be configured via SystemSetting (attendance_periods)
        $types = DB::table('roll_call_types')->whereNotNull('period_count')->get();

        foreach ($types as $type) {
            $count = $type->period_count ?? 1;
            // Initialize with sequential IDs (1, 2, 3...)
            $periodIds = range(1, $count);
            
            DB::table('roll_call_types')
                ->where('id', $type->id)
                ->update(['period_ids' => json_encode($periodIds)]);
        }

        // 3. 删除旧字段 period_count
        Schema::table('roll_call_types', function (Blueprint $table) {
            $table->dropColumn('period_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 1. 添加回 period_count 字段
        Schema::table('roll_call_types', function (Blueprint $table) {
            $table->unsignedTinyInteger('period_count')->default(1)->after('absent_status');
        });

        // 2. 迁移数据：将 period_ids 数组长度转换为 period_count
        $types = DB::table('roll_call_types')->whereNotNull('period_ids')->get();
        foreach ($types as $type) {
            $periodIds = json_decode($type->period_ids, true) ?? [];
            $count = count($periodIds);
            
            DB::table('roll_call_types')
                ->where('id', $type->id)
                ->update(['period_count' => $count > 0 ? $count : 1]);
        }

        // 3. 删除 period_ids 字段
        Schema::table('roll_call_types', function (Blueprint $table) {
            $table->dropColumn('period_ids');
        });
    }
};
