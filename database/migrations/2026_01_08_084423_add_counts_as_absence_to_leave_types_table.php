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
        Schema::table('leave_types', function (Blueprint $table) {
            // 是否计入缺勤统计，默认为 true（计入）
            // 设为 false 的类型（如"其他"：活动、学生会等）不会计入缺勤人数
            $table->boolean('counts_as_absence')->default(true)->after('use_conversion');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropColumn('counts_as_absence');
        });
    }
};
