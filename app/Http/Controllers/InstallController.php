<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use App\Models\User;
use App\Models\School;

class InstallController extends Controller
{
    /**
     * Check if system is already installed
     */
    public function checkInstalled()
    {
        return response()->json([
            'installed' => $this->isInstalled()
        ]);
    }

    /**
     * Check environment requirements
     */
    public function checkRequirements()
    {
        $requirements = [
            'php_version' => [
                'required' => '8.2.0',
                'current' => PHP_VERSION,
                'passed' => version_compare(PHP_VERSION, '8.2.0', '>=')
            ],
            'extensions' => $this->checkExtensions(),
            'directories' => $this->checkDirectories(),
        ];

        $allPassed = $requirements['php_version']['passed'] 
            && collect($requirements['extensions'])->every(fn($ext) => $ext['installed'])
            && collect($requirements['directories'])->every(fn($dir) => $dir['writable']);

        return response()->json([
            'requirements' => $requirements,
            'passed' => $allPassed
        ]);
    }

    /**
     * Test database connection
     */
    public function testDatabase(Request $request)
    {
        $request->validate([
            'host' => 'required|string',
            'port' => 'required|string',
            'database' => 'required|string',
            'username' => 'required|string',
            'password' => 'nullable|string',
        ]);

        try {
            $connection = @new \PDO(
                "mysql:host={$request->host};port={$request->port};dbname={$request->database}",
                $request->username,
                $request->password ?? '',
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );

            // Check if tables exist
            $stmt = $connection->query("SHOW TABLES");
            $tables = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            return response()->json([
                'success' => true,
                'message' => '数据库连接成功',
                'has_tables' => count($tables) > 0,
                'table_count' => count($tables)
            ]);
        } catch (\PDOException $e) {
            return response()->json([
                'success' => false,
                'message' => '数据库连接失败: ' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * Run installation
     */
    public function install(Request $request)
    {
        if ($this->isInstalled()) {
            return response()->json(['error' => '系统已安装'], 400);
        }

        $request->validate([
            'db_host' => 'required|string',
            'db_port' => 'required|string',
            'db_database' => 'required|string',
            'db_username' => 'required|string',
            'db_password' => 'nullable|string',
            'school_name' => 'required|string|max:100',
            'admin_name' => 'required|string|max:50',
            'admin_email' => 'required|email',
            'admin_password' => 'required|string|min:6',
        ]);

        try {
            // Step 1: Update .env file
            $this->updateEnvFile([
                'DB_HOST' => $request->db_host,
                'DB_PORT' => $request->db_port,
                'DB_DATABASE' => $request->db_database,
                'DB_USERNAME' => $request->db_username,
                'DB_PASSWORD' => $request->db_password ?? '',
            ]);

            // Reload config
            Artisan::call('config:clear');
            
            // Reconnect with new settings
            config([
                'database.connections.mysql.host' => $request->db_host,
                'database.connections.mysql.port' => $request->db_port,
                'database.connections.mysql.database' => $request->db_database,
                'database.connections.mysql.username' => $request->db_username,
                'database.connections.mysql.password' => $request->db_password ?? '',
            ]);
            
            DB::purge('mysql');
            DB::reconnect('mysql');

            // Step 2: Run migrations
            Artisan::call('migrate', ['--force' => true]);

            // Step 3: Create school
            $school = School::firstOrCreate(
                ['id' => 1],
                ['name' => $request->school_name]
            );

            // Step 4: Create admin user
            $admin = User::firstOrCreate(
                ['email' => $request->admin_email],
                [
                    'uuid' => (string) Str::uuid(),
                    'name' => $request->admin_name,
                    'password' => Hash::make($request->admin_password),
                    'role' => 'system_admin',
                ]
            );

            // Step 5: Run seeders
            Artisan::call('db:seed', ['--class' => 'PermissionSeeder', '--force' => true]);
            Artisan::call('db:seed', ['--class' => 'LeaveTypeSeeder', '--force' => true]);
            Artisan::call('db:seed', ['--class' => 'SystemSettingsSeeder', '--force' => true]);

            // Step 6: Create storage link
            try {
                Artisan::call('storage:link');
            } catch (\Exception $e) {
                // Ignore if already exists
            }

            // Step 7: Clear caches
            Artisan::call('optimize:clear');

            // Step 8: Create install lock file
            File::put(storage_path('installed'), date('Y-m-d H:i:s'));

            return response()->json([
                'success' => true,
                'message' => '安装成功！',
                'admin_email' => $request->admin_email
            ]);

        } catch (\Exception $e) {
            \Log::error('Installation failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => '安装失败: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check if system is installed
     */
    private function isInstalled(): bool
    {
        return File::exists(storage_path('installed'));
    }

    /**
     * Check required PHP extensions
     */
    private function checkExtensions(): array
    {
        $required = ['pdo', 'pdo_mysql', 'mbstring', 'openssl', 'tokenizer', 'xml', 'ctype', 'json', 'bcmath', 'fileinfo'];
        
        return collect($required)->map(function ($ext) {
            return [
                'name' => $ext,
                'installed' => extension_loaded($ext)
            ];
        })->toArray();
    }

    /**
     * Check directory permissions
     */
    private function checkDirectories(): array
    {
        $directories = [
            'storage' => storage_path(),
            'storage/app' => storage_path('app'),
            'storage/logs' => storage_path('logs'),
            'storage/framework' => storage_path('framework'),
            'bootstrap/cache' => base_path('bootstrap/cache'),
        ];

        return collect($directories)->map(function ($path, $name) {
            return [
                'name' => $name,
                'path' => $path,
                'writable' => is_writable($path)
            ];
        })->toArray();
    }

    /**
     * Update .env file
     */
    private function updateEnvFile(array $values): void
    {
        $envPath = base_path('.env');
        
        if (!File::exists($envPath)) {
            File::copy(base_path('.env.example'), $envPath);
        }

        $envContent = File::get($envPath);

        foreach ($values as $key => $value) {
            // Escape special characters in value
            $escapedValue = str_contains($value, ' ') ? "\"$value\"" : $value;
            
            $pattern = "/^{$key}=.*/m";
            
            if (preg_match($pattern, $envContent)) {
                $envContent = preg_replace($pattern, "{$key}={$escapedValue}", $envContent);
            } else {
                $envContent .= "\n{$key}={$escapedValue}";
            }
        }

        File::put($envPath, $envContent);
    }
}
