<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_notification_preferences', function (Blueprint $table) {
            $table->string('email_provider', 30)->default('system_resend')->after('email_enabled');
            $table->boolean('email_fallback_to_resend')->default(false)->after('email_provider');
        });

        Schema::create('teacher_email_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider', 30);
            $table->string('email');
            $table->string('from_name')->nullable();
            $table->text('secret')->nullable();
            $table->longText('access_token')->nullable();
            $table->longText('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['provider', 'is_verified']);
        });

        Schema::table('email_notification_logs', function (Blueprint $table) {
            $table->string('provider', 30)->nullable()->after('recipient');
            $table->string('sender_address')->nullable()->after('provider');
            $table->unsignedSmallInteger('attempt_count')->default(1)->after('status');
            $table->boolean('fallback_used')->default(false)->after('attempt_count');
            $table->timestamp('last_attempt_at')->nullable()->after('fallback_used');

            $table->index(['provider', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('email_notification_logs', function (Blueprint $table) {
            $table->dropIndex(['provider', 'status', 'created_at']);
            $table->dropColumn(['provider', 'sender_address', 'attempt_count', 'fallback_used', 'last_attempt_at']);
        });

        Schema::dropIfExists('teacher_email_accounts');

        Schema::table('email_notification_preferences', function (Blueprint $table) {
            $table->dropColumn(['email_provider', 'email_fallback_to_resend']);
        });
    }
};
