<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mobile_devices') && ! Schema::hasColumn('mobile_devices', 'push_environment')) {
            Schema::table('mobile_devices', function (Blueprint $table) {
                $table->string('push_environment', 20)->nullable()->after('push_provider');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('mobile_devices') && Schema::hasColumn('mobile_devices', 'push_environment')) {
            Schema::table('mobile_devices', function (Blueprint $table) {
                $table->dropColumn('push_environment');
            });
        }
    }
};
