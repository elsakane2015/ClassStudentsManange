<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Step 1: Add temporary column with new enum
        DB::statement("ALTER TABLE users ADD COLUMN role_new ENUM(
            'system_admin',
            'school_admin',
            'department_manager',
            'teacher',
            'student'
        ) NOT NULL DEFAULT 'student' AFTER role");
        
        // Step 2: Copy and transform data
        DB::statement("UPDATE users SET role_new = CASE 
            WHEN role = 'admin' THEN 'system_admin'
            WHEN role = 'manager' THEN 'department_manager'
            WHEN role = 'teacher' THEN 'teacher'
            WHEN role = 'student' THEN 'student'
            ELSE 'student'
        END");
        
        // Step 3: Drop old column
        DB::statement("ALTER TABLE users DROP COLUMN role");
        
        // Step 4: Rename new column to role
        DB::statement("ALTER TABLE users CHANGE COLUMN role_new role ENUM(
            'system_admin',
            'school_admin',
            'department_manager',
            'teacher',
            'student'
        ) NOT NULL DEFAULT 'student'");
    }

    public function down(): void
    {
        // Revert to old enum
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM(
            'admin',
            'manager',
            'teacher',
            'student'
        ) NOT NULL DEFAULT 'student'");
        
        // Revert role names
        DB::table('users')->where('role', 'system_admin')->update(['role' => 'admin']);
        DB::table('users')->where('role', 'department_manager')->update(['role' => 'manager']);
        DB::table('users')->where('role', 'school_admin')->update(['role' => 'admin']);
    }
};
