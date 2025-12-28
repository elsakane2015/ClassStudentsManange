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
        // Get first school or use ID 1
        $schoolId = \App\Models\School::first()?->id ?? 1;
        
        $types = [
            [
                'name' => '迟到',
                'slug' => 'late',
                'description' => 'Late arrival',
                'input_type' => 'time',
                'input_config' => json_encode(['format' => 'HH:mm', 'require_period' => true]),
                'school_id' => $schoolId,
                'student_requestable' => false,  // 迟到不需要学生申请
            ],
            [
                'name' => '旷课',
                'slug' => 'absent',
                'description' => 'Absent without leave',
                'input_type' => 'period_select',
                'input_config' => json_encode(['max_periods' => 8]),
                'school_id' => $schoolId,
                'student_requestable' => false,  // 旷课不需要学生申请
            ],
            [
                'name' => '早退',
                'slug' => 'early_leave',
                'description' => 'Leaving early',
                'input_type' => 'time',
                'input_config' => json_encode(['format' => 'HH:mm', 'require_period' => true]),
                'school_id' => $schoolId,
                'student_requestable' => false,  // 早退不需要学生申请
            ],
            [
                'name' => '病假',
                'slug' => 'sick_leave',
                'description' => 'Sick leave',
                'input_type' => 'duration_select',
                'input_config' => json_encode(['options' => [
                    ['key' => 'morning_half', 'label' => '上午'],
                    ['key' => 'afternoon_half', 'label' => '下午'],
                    ['key' => 'full_day', 'label' => '全天'],
                    ['key' => 'zcao', 'label' => '早操'],
                    ['key' => 'wcao', 'label' => '晚操'],
                ]]),
                'school_id' => $schoolId,
                'student_requestable' => true,   // 学生可以申请
            ],
            [
                'name' => '事假',
                'slug' => 'personal_leave',
                'description' => 'Personal leave',
                'input_type' => 'duration_select',
                'input_config' => json_encode(['options' => [
                    ['key' => 'morning_half', 'label' => '上午'],
                    ['key' => 'afternoon_half', 'label' => '下午'],
                    ['key' => 'full_day', 'label' => '全天'],
                    ['key' => 'zcao', 'label' => '早操'],
                    ['key' => 'wcao', 'label' => '晚操'],
                ]]),
                'school_id' => $schoolId,
                'student_requestable' => true,   // 学生可以申请
            ],
            [
                'name' => '生理假',
                'slug' => 'health_leave',
                'description' => 'Health leave (Morning/Evening exercises)',
                'input_type' => 'duration_select',
                'input_config' => json_encode(['options' => [
                    ['key' => 'zcao', 'label' => '早操'],
                    ['key' => 'wcao', 'label' => '晚操'],
                ]]),
                'school_id' => $schoolId,
                'student_requestable' => true,   // 学生可以申请
                'gender_restriction' => 'female', // 仅限女生
            ]
        ];

        foreach ($types as $type) {
            \App\Models\LeaveType::updateOrCreate(
                ['slug' => $type['slug'], 'school_id' => $schoolId],
                $type
            );
        }
    }
}
