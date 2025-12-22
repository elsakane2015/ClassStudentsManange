<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class LeaveTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $types = [
            [
                'name' => '迟到',
                'slug' => 'late',
                'description' => 'Late arrival',
                'input_type' => 'time',
                'input_config' => json_encode(['format' => 'HH:mm', 'require_period' => true])
            ],
            [
                'name' => '旷课',
                'slug' => 'absent',
                'description' => 'Absent without leave',
                'input_type' => 'period_select',
                'input_config' => json_encode(['max_periods' => 8])
            ],
            [
                'name' => '早退',
                'slug' => 'early_leave',
                'description' => 'Leaving early',
                'input_type' => 'time',
                'input_config' => json_encode(['format' => 'HH:mm', 'require_period' => true])
            ],
            [
                'name' => '病假',
                'slug' => 'sick_leave',
                'description' => 'Sick leave',
                'input_type' => 'duration_select',
                'input_config' => json_encode(['options' => ['morning_half', 'afternoon_half', 'full_day']])
            ],
            [
                'name' => '事假',
                'slug' => 'personal_leave',
                'description' => 'Personal leave',
                'input_type' => 'duration_select',
                'input_config' => json_encode(['options' => ['morning_half', 'afternoon_half', 'full_day']])
            ],
            [
                'name' => '生理假',
                'slug' => 'health_leave',
                'description' => 'Health leave (Morning/Evening exercises)',
                'input_type' => 'period_select', // Or maybe specific toggle?
                'input_config' => json_encode(['options' => ['morning_exercise', 'evening_exercise']])
            ]
        ];

        foreach ($types as $type) {
            \App\Models\LeaveType::updateOrCreate(
                ['name' => $type['name']],
                $type
            );
        }
    }
}
