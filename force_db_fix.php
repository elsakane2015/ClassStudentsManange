<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

function safeRun($label, $sql) {
    try {
        // Force new connection if possible or just run
        \DB::connection()->getPdo()->exec($sql);
        echo "[SUCCESS] $label\n";
    } catch (\Exception $e) {
        echo "[INFO] $label: " . $e->getMessage() . "\n";
    }
}

echo "Starting DB Fix...\n";

safeRun("Add input_type", "ALTER TABLE leave_types ADD COLUMN input_type VARCHAR(255) NULL AFTER description");
safeRun("Add input_config", "ALTER TABLE leave_types ADD COLUMN input_config JSON NULL AFTER input_type");

safeRun("Add leave_type_id", "ALTER TABLE attendance_records ADD COLUMN leave_type_id BIGINT UNSIGNED NULL AFTER status");
safeRun("Add details", "ALTER TABLE attendance_records ADD COLUMN details JSON NULL AFTER leave_type_id");

echo "Done.\n";
