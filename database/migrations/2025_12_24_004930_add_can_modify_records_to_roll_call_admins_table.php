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
        Schema::table('roll_call_admins', function (Blueprint $table) {
            $table->boolean('can_modify_records')->default(false)->after('is_active')
                ->comment('Allow roll call admin to modify records after roll call is completed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('roll_call_admins', function (Blueprint $table) {
            $table->dropColumn('can_modify_records');
        });
    }
};
