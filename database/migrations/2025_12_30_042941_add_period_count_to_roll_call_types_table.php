<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 添加 period_count 字段，用于配置点名类型关联的节次数
     * 例如：早操=1节，晚自习=2节
     */
    public function up(): void
    {
        Schema::table('roll_call_types', function (Blueprint $table) {
            $table->unsignedTinyInteger('period_count')->default(1)->after('absent_status')
                ->comment('关联节次数，用于生成考勤记录时关联对应数量的节次');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('roll_call_types', function (Blueprint $table) {
            $table->dropColumn('period_count');
        });
    }
};
