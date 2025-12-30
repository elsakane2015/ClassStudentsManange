<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_slots', function (Blueprint $table) {
            $table->json('period_ids')->nullable()->after('time_end');
        });
    }

    public function down(): void
    {
        Schema::table('time_slots', function (Blueprint $table) {
            $table->dropColumn('period_ids');
        });
    }
};
