<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

function aggressive_reconnect() {
    try {
        DB::purge('mysql');
        DB::reconnect('mysql');
        DB::connection()->getPdo(); // Trigger connection
    } catch (\Exception $e) {
        echo "Reconnect failed: " . $e->getMessage() . "\n";
    }
}

echo "Connect...\n";
aggressive_reconnect();

try {
    $count = DB::table('students')->count();
    echo "Students Count: $count\n";

    if ($count == 0) {
        echo "Seeding...\n";
        
        $schoolId = DB::table('schools')->insertGetId(['name' => 'Demo School', 'created_at' => now(), 'updated_at' => now()]);
        
        $tId = DB::table('users')->insertGetId([
            'uuid' => \Illuminate\Support\Str::uuid(),
            'name' => 'Teacher', 'email' => 't@t.com', 'password' => '$2y$12$K.x.x', 
            'role' => 'teacher', 'created_at' => now(), 'updated_at' => now()
        ]);
        
        $cId = DB::table('classes')->insertGetId([
            'school_id' => $schoolId, 'name' => 'Class 1', 'teacher_id' => $tId,
            'created_at' => now(), 'updated_at' => now()
        ]);
        
        // Batch insert students
        $users = [];
        $students = [];
        
        for ($i=0; $i<6; $i++) {
            $uId = DB::table('users')->insertGetId([
                'uuid' => \Illuminate\Support\Str::uuid(),
                'name' => "Student $i", 'email' => "s$i@t.com", 'password' => '$2y$12$K.x.x',
                'role' => 'student', 'created_at' => now(), 'updated_at' => now()
            ]);
            
            $students[] = [
                'user_id' => $uId,
                'school_id' => $schoolId,
                'class_id' => $cId,
                'student_no' => "STU$i",
                'created_at' => now(), 'updated_at' => now()
            ];
        }
        
        DB::table('students')->insert($students);
        echo "Seeding Done.\n";
    }
    
    // Check Leave Types
    if (DB::table('leave_types')->count() == 0) {
         DB::table('leave_types')->insert([
             ['name' => '事假', 'code' => 'personal', 'color' => '#fbbf24', 'icon' => 'user', 'created_at' => now(), 'updated_at' => now()],
             ['name' => '病假', 'code' => 'sick', 'color' => '#ef4444', 'icon' => 'heart', 'created_at' => now(), 'updated_at' => now()],
             ['name' => '迟到', 'code' => 'late', 'color' => '#f59e0b', 'icon' => 'clock', 'created_at' => now(), 'updated_at' => now()],
             ['name' => '早退', 'code' => 'early_leave', 'color' => '#f97316', 'icon' => 'logout', 'created_at' => now(), 'updated_at' => now()]
         ]);
         echo "Leave Types Seeded.\n";
    }

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
