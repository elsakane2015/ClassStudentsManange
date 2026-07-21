<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_notification_preferences', function (Blueprint $table) {
            $table->boolean('email_enabled')->default(true)->after('enabled');
            $table->boolean('sms_enabled')->default(false)->after('email_enabled');
        });

        Schema::create('sms_notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('teacher_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recipient');
            $table->string('provider', 20);
            $table->string('event_key');
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
            $table->index(['provider', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_notification_logs');

        Schema::table('email_notification_preferences', function (Blueprint $table) {
            $table->dropColumn(['email_enabled', 'sms_enabled']);
        });
    }
};
