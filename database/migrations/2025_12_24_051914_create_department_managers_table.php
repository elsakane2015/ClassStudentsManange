<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Step 1: Create the pivot table for many-to-many relationship
        Schema::create('department_managers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->timestamps();
            
            // Ensure unique combination
            $table->unique(['department_id', 'user_id']);
        });

        // Step 2: Migrate existing data from departments.manager_id to pivot table
        $departments = DB::table('departments')->whereNotNull('manager_id')->get();
        foreach ($departments as $dept) {
            DB::table('department_managers')->insert([
                'department_id' => $dept->id,
                'user_id' => $dept->manager_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Step 3: Drop the manager_id column from departments table
        Schema::table('departments', function (Blueprint $table) {
            $table->dropForeign(['manager_id']);
            $table->dropColumn('manager_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Step 1: Re-add the manager_id column
        Schema::table('departments', function (Blueprint $table) {
            $table->foreignId('manager_id')->nullable()->constrained('users')->onDelete('set null');
        });

        // Step 2: Migrate data back (take first manager for each department)
        $pivotData = DB::table('department_managers')
            ->select('department_id', DB::raw('MIN(user_id) as user_id'))
            ->groupBy('department_id')
            ->get();
        
        foreach ($pivotData as $row) {
            DB::table('departments')
                ->where('id', $row->department_id)
                ->update(['manager_id' => $row->user_id]);
        }

        // Step 3: Drop the pivot table
        Schema::dropIfExists('department_managers');
    }
};
