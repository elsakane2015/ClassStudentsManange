<?php

namespace Database\Seeders;

use App\Models\School;
use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\User;
use App\Models\Student;
use App\Models\ClassPeriod;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Create School
        $school = School::create(['name' => 'Demo High School']);

        // 2. Create Periods
        $periodsData = [
            ['name' => 'Period 1', 'start_time' => '08:00', 'end_time' => '08:45'],
            ['name' => 'Period 2', 'start_time' => '08:55', 'end_time' => '09:40'],
            ['name' => 'Period 3', 'start_time' => '10:00', 'end_time' => '10:45'],
            ['name' => 'Period 4', 'start_time' => '10:55', 'end_time' => '11:40'],
            ['name' => 'Lunch',   'start_time' => '11:40', 'end_time' => '13:00'], // Usually skipped for attendance or special
            ['name' => 'Period 5', 'start_time' => '13:00', 'end_time' => '13:45'],
            ['name' => 'Period 6', 'start_time' => '13:55', 'end_time' => '14:40'],
        ];
        
        foreach ($periodsData as $index => $p) {
            ClassPeriod::create([
                'school_id' => $school->id,
                'name' => $p['name'],
                'start_time' => $p['start_time'],
                'end_time' => $p['end_time'],
                'ordinal' => $index + 1,
            ]);
        }

        // 3. Create Grades
        $grade1 = Grade::create(['school_id' => $school->id, 'name' => 'Grade 10', 'ordinal' => 10]);
        $grade2 = Grade::create(['school_id' => $school->id, 'name' => 'Grade 11', 'ordinal' => 11]);

        // 4. Create Teacher
        $teacherUser = User::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Teacher Wang',
            'email' => 'teacher@demo.com',
            'password' => Hash::make('password'),
            'role' => 'teacher',
        ]);

        // 5. Create Class
        $classA = SchoolClass::create([
            'school_id' => $school->id,
            'grade_id' => $grade1->id,
            'name' => 'Class 10-A',
            'teacher_id' => $teacherUser->id,
        ]);

        // 6. Create Students
        for ($i = 1; $i <= 5; $i++) {
            $sUser = User::create([
                'uuid' => (string) Str::uuid(),
                'name' => "Student $i",
                'email' => "student$i@demo.com",
                'password' => Hash::make('password'),
                'role' => 'student',
            ]);

            Student::create([
                'user_id' => $sUser->id,
                'school_id' => $school->id,
                'class_id' => $classA->id,
                'student_no' => "202400$i",
            ]);
        }
        
        // 7. Admin
        User::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Admin User',
            'email' => 'admin@demo.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);
        
        // 8. Manager (Student Leader)
        $managerUser = User::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Student Manager',
            'email' => 'manager@demo.com',
            'password' => Hash::make('password'),
            'role' => 'manager',
        ]);
        Student::create([
             'user_id' => $managerUser->id,
             'school_id' => $school->id,
             'class_id' => $classA->id,
             'student_no' => "2024999",
        ]);
    }
}
