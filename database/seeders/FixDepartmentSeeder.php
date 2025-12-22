<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\SchoolClass;
use App\Models\Department;

class FixDepartmentSeeder extends Seeder
{
    public function run()
    {
        try {
            DB::reconnect();
            
            // 1. Create a Department if none
            $dept = Department::firstOrCreate(
                ['name' => 'High School Dept'],
                ['created_at' => now(), 'updated_at' => now()]
            );
            
            // 2. Assign all Orphan Classes to this Department
            $count = SchoolClass::whereNull('department_id')->update(['department_id' => $dept->id]);
            
            $this->command->info("Assigned $count classes to {$dept->name}");
            
            // 3. Ensure Students have attendance records for today (for testing '0' vs data)
            // (Optional, just to ensure data exists)
            
        } catch (\Exception $e) {
            $this->command->error($e->getMessage());
        }
    }
}
