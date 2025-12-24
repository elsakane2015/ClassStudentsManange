<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roll_call_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('roll_call_id')->constrained('roll_calls')->onDelete('cascade');
            $table->foreignId('student_id')->constrained()->onDelete('cascade');
            $table->enum('status', ['pending', 'present', 'absent', 'on_leave'])->default('pending');
            $table->foreignId('leave_type_id')->nullable()->constrained('leave_types')->nullOnDelete();
            $table->string('leave_detail')->nullable(); // 请假描述，如"病假(上午)"
            $table->timestamp('marked_at')->nullable();
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            
            $table->unique(['roll_call_id', 'student_id']);
            $table->index(['roll_call_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roll_call_records');
    }
};
