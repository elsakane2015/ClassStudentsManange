<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('mobile_devices')) {
            Schema::create('mobile_devices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('platform', 20); // ios, android
                $table->string('push_provider', 30)->default('apns'); // apns, fcm, webhook
                $table->string('device_identifier', 128);
                $table->string('push_token', 512)->nullable();
                $table->string('device_name', 120)->nullable();
                $table->string('app_version', 40)->nullable();
                $table->string('os_version', 40)->nullable();
                $table->string('locale', 20)->nullable();
                $table->boolean('push_enabled')->default(true);
                $table->timestamp('last_active_at')->nullable();
                $table->timestamps();

                $table->unique(['platform', 'device_identifier'], 'mobile_devices_platform_identifier_unique');
                $table->index(['user_id', 'platform'], 'mobile_devices_user_platform_idx');
            });
        }

        if (!Schema::hasTable('app_notifications')) {
            Schema::create('app_notifications', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('type', 64);
                $table->string('title', 255);
                $table->text('body');
                $table->json('data')->nullable();
                $table->timestamp('read_at')->nullable();
                $table->timestamp('pushed_at')->nullable();
                $table->string('push_status', 48)->default('pending');
                $table->text('push_error')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'read_at', 'created_at'], 'app_notifications_user_read_created_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('app_notifications');
        Schema::dropIfExists('mobile_devices');
    }
};
