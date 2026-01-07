<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * 添加 leave_batch_id 字段，用于将同一次请假申请的多条记录关联在一起
     * 这样可以实现：一次申请多天 → 显示为一条记录（带日期范围）
     */
    public function up(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            // leave_batch_id: 同一次请假申请的所有记录共享同一个 batch_id
            // 使用 UUID 格式，便于生成唯一标识
            $table->string('leave_batch_id', 36)->nullable()->after('leave_type_id')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropColumn('leave_batch_id');
        });
    }
};
