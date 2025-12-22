#!/bin/bash

# Quick DB check script
echo "=== Checking MySQL Connection ==="
mysql -u root -e "SELECT 1" 2>&1 | head -5

echo ""
echo "=== Checking Database ==="
php artisan tinker <<'EOF'
try {
    DB::reconnect();
    echo "Students: " . DB::table('students')->count() . "\n";
    echo "Classes: " . DB::table('classes')->count() . "\n";
    echo "Users: " . DB::table('users')->count() . "\n";
    echo "Departments: " . DB::table('departments')->count() . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
EOF
