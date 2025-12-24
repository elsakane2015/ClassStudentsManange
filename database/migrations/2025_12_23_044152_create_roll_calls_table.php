<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roll_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained()->onDelete('cascade');
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            $table->foreignId('roll_call_type_id')->constrained('roll_call_types')->onDelete('cascade');
            $table->dateTime('roll_call_time'); // 点名时间
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->enum('status', ['in_progress', 'completed', 'cancelled'])->default('in_progress');
            $table->integer('total_students')->default(0);
            $table->integer('present_count')->default(0);
            $table->integer('on_leave_count')->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            
            $table->index(['class_id', 'status']);
            $table->index(['roll_call_type_id', 'roll_call_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roll_calls');
    }
};
