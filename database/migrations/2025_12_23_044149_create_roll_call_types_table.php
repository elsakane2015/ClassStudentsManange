<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roll_call_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            $table->foreignId('class_id')->constrained()->onDelete('cascade');
            $table->string('name'); // 早操点名、晚自习点名等
            $table->string('description')->nullable();
            $table->string('absent_status')->default('absent'); // 未到时标记的状态
            $table->foreignId('leave_type_id')->nullable()->constrained('leave_types')->nullOnDelete(); // 关联请假类型
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();
            
            $table->index(['class_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roll_call_types');
    }
};
