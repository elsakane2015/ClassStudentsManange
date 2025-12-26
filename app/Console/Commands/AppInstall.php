<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AppInstall extends Command
{
    protected $signature = 'app:install 
                            {--fresh : Drop all tables and re-migrate (WARNING: destroys all data)}
                            {--demo : Include demo data (students, teachers, etc.)}
                            {--force : Force run in production}';

    protected $description = 'Install the attendance system (migrate, seed, configure)';

    public function handle()
    {
        $this->info('');
        $this->info('╔═══════════════════════════════════════════════════════════╗');
        $this->info('║            智慧校园考勤系统 - 安装向导                    ║');
        $this->info('╚═══════════════════════════════════════════════════════════╝');
        $this->info('');

        // Check environment
        if (app()->environment('production') && !$this->option('force')) {
            if (!$this->confirm('You are running in PRODUCTION. Continue?', false)) {
                $this->warn('Installation cancelled.');
                return 1;
            }
        }

        // Check database connection
        $this->info('📦 Step 1/5: Checking database connection...');
        try {
            DB::connection()->getPdo();
            $this->info('   ✅ Database connection successful: ' . config('database.connections.' . config('database.default') . '.database'));
        } catch (\Exception $e) {
            $this->error('   ❌ Database connection failed: ' . $e->getMessage());
            $this->error('   Please check your .env database configuration.');
            return 1;
        }

        // Run migrations
        $this->info('');
        $this->info('📦 Step 2/5: Running database migrations...');
        
        if ($this->option('fresh')) {
            $this->warn('   ⚠️  Fresh install - dropping all tables...');
            Artisan::call('migrate:fresh', ['--force' => true]);
        } else {
            Artisan::call('migrate', ['--force' => true]);
        }
        $this->info('   ✅ Migrations completed');

        // Run essential seeders
        $this->info('');
        $this->info('📦 Step 3/5: Seeding initial data...');
        
        // Always run these
        Artisan::call('db:seed', ['--class' => 'PermissionSeeder', '--force' => true]);
        $this->info('   ✅ Permissions seeded');
        
        Artisan::call('db:seed', ['--class' => 'LeaveTypeSeeder', '--force' => true]);
        $this->info('   ✅ Leave types seeded');
        
        Artisan::call('db:seed', ['--class' => 'SystemSettingsSeeder', '--force' => true]);
        $this->info('   ✅ System settings seeded');

        // Create admin user if not exists
        $this->createAdminUser();

        // Demo data
        if ($this->option('demo')) {
            $this->info('');
            $this->info('📦 Step 4/5: Seeding demo data...');
            Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
            $this->info('   ✅ Demo data seeded');
        } else {
            $this->info('');
            $this->info('📦 Step 4/5: Skipping demo data (use --demo to include)');
        }

        // Create storage link
        $this->info('');
        $this->info('📦 Step 5/5: Creating storage link...');
        try {
            Artisan::call('storage:link', ['--force' => true]);
            $this->info('   ✅ Storage link created');
        } catch (\Exception $e) {
            $this->warn('   ⚠️  Storage link may already exist');
        }

        // Clear caches
        Artisan::call('optimize:clear');
        
        // Summary
        $this->info('');
        $this->info('═══════════════════════════════════════════════════════════');
        $this->info('');
        $this->info('🎉 Installation completed successfully!');
        $this->info('');
        $this->info('   Admin Login:');
        $this->info('   📧 Email:    admin@demo.com');
        $this->info('   🔑 Password: password');
        $this->info('');
        $this->warn('   ⚠️  Please change the admin password after first login!');
        $this->info('');
        $this->info('═══════════════════════════════════════════════════════════');
        
        return 0;
    }

    private function createAdminUser()
    {
        $adminEmail = 'admin@demo.com';
        
        $exists = \App\Models\User::where('email', $adminEmail)->exists();
        
        if (!$exists) {
            \App\Models\User::create([
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'name' => 'Admin User',
                'email' => $adminEmail,
                'password' => \Illuminate\Support\Facades\Hash::make('password'),
                'role' => 'admin',
            ]);
            $this->info('   ✅ Admin user created');
        } else {
            $this->info('   ✅ Admin user already exists');
        }

        // Also create a school if none exists
        if (\App\Models\School::count() === 0) {
            \App\Models\School::create(['name' => '智慧校园']);
            $this->info('   ✅ Default school created');
        }
    }
}
