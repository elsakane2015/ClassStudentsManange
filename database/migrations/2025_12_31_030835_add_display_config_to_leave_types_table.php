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
            // 显示单位（自定义文字，如：节、天、次、课时等）
            $table->string('display_unit', 20)->default('节')->after('color');
            // 是否按折算阈值换算（true=按leave_periods_as_day设置折算，false=直接显示记录数）
            $table->boolean('use_conversion')->default(false)->after('display_unit');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropColumn(['display_unit', 'use_conversion']);
        });
    }
};
