<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class SystemSettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $schoolId = 1; // Default
        
        // Semesters
        \App\Models\Semester::create([
            'school_id' => $schoolId,
            'name' => '2025-2026 First Semester',
            'start_date' => '2025-09-01',
            'total_weeks' => 20,
            'is_current' => true
        ]);
        
        // Leave Types
        $types = [
            ['name' => 'Sick Leave', 'slug' => 'sick', 'description' => 'Medical reasons'],
            ['name' => 'Personal Leave', 'slug' => 'personal', 'description' => 'Personal matters'],
            ['name' => 'Menstrual Leave', 'slug' => 'menstrual', 'description' => 'Health leave'],
        ];
        
        foreach ($types as $type) {
            \App\Models\LeaveType::create(array_merge($type, ['school_id' => $schoolId]));
        }
    }
}
