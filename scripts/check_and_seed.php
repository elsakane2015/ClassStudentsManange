<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\Student;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

function robust_db($callback) {
    $retry = 3;
    while ($retry > 0) {
        try {
            DB::reconnect();
            return $callback();
        } catch (\Exception $e) {
            $retry--;
            sleep(1);
            if ($retry == 0) echo "DB Error: " . $e->getMessage() . PHP_EOL;
        }
    }
}

echo "Checking Database...\n";

robust_db(function() {
    $sCount = Student::count();
    echo "Students: $sCount\n";
    
    if ($sCount == 0) {
        echo "No students found. Attempting to seed...\n";
        // Manual seeding logic to avoid 'gone away' in large seeder class
        // Create 1 School
        $schoolId = DB::table('schools')->insertGetId(['name' => 'Demo School', 'created_at' => now(), 'updated_at' => now()]);
        
        // Create 1 User (Teacher)
        $tUserId = DB::table('users')->insertGetId([
            'uuid' => \Illuminate\Support\Str::uuid(),
            'name' => 'Teacher Wang',
            'email' => 'teacher@demo.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'role' => 'teacher',
            'created_at' => now(), 'updated_at' => now()
        ]);
        
        // Create Class
        $classId = DB::table('classes')->insertGetId([
            'school_id' => $schoolId,
            'name' => 'Class 1-A',
            'teacher_id' => $tUserId,
            'created_at' => now(), 'updated_at' => now()
        ]);
        
        // Create Students
        for ($i=1; $i<=5; $i++) {
            $sUserId = DB::table('users')->insertGetId([
                'uuid' => \Illuminate\Support\Str::uuid(),
                'name' => "Student $i",
                'email' => "student$i@demo.com",
                'password' => \Illuminate\Support\Facades\Hash::make('password'),
                'role' => 'student',
                'created_at' => now(), 'updated_at' => now()
            ]);
            
            DB::table('students')->insert([
                'user_id' => $sUserId,
                'school_id' => $schoolId,
                'class_id' => $classId,
                'student_no' => "202400$i",
                'created_at' => now(), 'updated_at' => now()
            ]);
        }
        echo "Seeded 5 students.\n";
    }
    
    // Check Leave Types
    $ltCount = DB::table('leave_types')->count();
    if ($ltCount == 0) {
         echo "Seeding Leave Types...\n";
         $types = [
             ['name' => '事假', 'code' => 'personal', 'color' => '#fbbf24', 'icon' => 'user'],
             ['name' => '病假', 'code' => 'sick', 'color' => '#ef4444', 'icon' => 'heart'],
             ['name' => '迟到', 'code' => 'late', 'color' => '#f59e0b', 'icon' => 'clock'],
             ['name' => '早退', 'code' => 'early_leave', 'color' => '#f97316', 'icon' => 'logout']
         ];
         DB::table('leave_types')->insert($types);
         echo "Seeded Leave Types.\n";
    }
});

echo "Done.\n";
