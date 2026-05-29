<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->unsignedSmallInteger('limit_days')->nullable()->after('counts_as_absence');
            $table->unsignedSmallInteger('limit_times')->nullable()->after('limit_days');
        });
    }

    public function down(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropColumn(['limit_days', 'limit_times']);
        });
    }
};
