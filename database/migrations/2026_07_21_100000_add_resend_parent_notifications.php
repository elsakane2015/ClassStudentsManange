<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('parent_email')->nullable()->after('parent_contact');
        });

        Schema::create('email_notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->json('enabled_events')->nullable();
            $table->timestamps();
        });

        Schema::create('email_notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('teacher_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recipient');
            $table->string('event_key');
            $table->string('subject');
            $table->string('status');
            $table->string('provider_message_id')->nullable();
            $table->text('error_message')->nullable();
            $table->string('related_type')->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->string('dedupe_key', 64)->unique();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'created_at']);
            $table->index(['teacher_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_notification_logs');
        Schema::dropIfExists('email_notification_preferences');

        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn('parent_email');
        });
    }
};
