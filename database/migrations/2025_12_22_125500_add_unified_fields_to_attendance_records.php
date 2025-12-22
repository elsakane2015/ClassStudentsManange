<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This migration unifies the data source by adding fields to track:
     * - Whether the record is from a self-applied leave request
     * - The approval status of the record
     */
    public function up(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            // Is this from a self-applied leave request (vs teacher marking)?
            $table->boolean('is_self_applied')->default(false)->after('details');
            
            // Approval status for self-applied leaves: pending, approved, rejected, null (for teacher marks)
            $table->enum('approval_status', ['pending', 'approved', 'rejected'])->nullable()->after('is_self_applied');
            
            // Who approved/rejected the request
            $table->foreignId('approver_id')->nullable()->after('approval_status')->constrained('users')->nullOnDelete();
            
            // When was it approved/rejected
            $table->timestamp('approved_at')->nullable()->after('approver_id');
            
            // Rejection reason if rejected
            $table->text('rejection_reason')->nullable()->after('approved_at');
            
            // Original reason/note for the leave request
            $table->text('reason')->nullable()->after('rejection_reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropForeign(['approver_id']);
            $table->dropColumn([
                'is_self_applied',
                'approval_status',
                'approver_id',
                'approved_at',
                'rejection_reason',
                'reason'
            ]);
        });
    }
};
