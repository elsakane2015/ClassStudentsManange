<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wechat_configs') && !Schema::hasColumn('wechat_configs', 'push_enabled')) {
            Schema::table('wechat_configs', function (Blueprint $table) {
                $table->boolean('push_enabled')->default(true)->after('is_verified');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('wechat_configs') && Schema::hasColumn('wechat_configs', 'push_enabled')) {
            Schema::table('wechat_configs', function (Blueprint $table) {
                $table->dropColumn('push_enabled');
            });
        }
    }
};
