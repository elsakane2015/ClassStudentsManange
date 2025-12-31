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
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_id')->nullable()->constrained()->nullOnDelete();
            
            $table->date('date');
            $table->unsignedBigInteger('period_id')->nullable(); // References SystemSetting attendance_periods ID, NULL = Whole day
            
            $table->enum('status', ['present', 'absent', 'late', 'excused', 'early_leave'])->default('present');
            
            $table->string('source_type')->default('manual'); // manual, leave_request, system
            $table->unsignedBigInteger('source_id')->nullable(); 
            
            $table->boolean('informed_parent')->default(false);
            $table->text('note')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Index for fast query
            $table->index(['student_id', 'date']);
            $table->index(['class_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};
