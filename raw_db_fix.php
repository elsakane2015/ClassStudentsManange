<?php
$host = 'mysql'; // Try '127.0.0.1' if this fails, but 'mysql' is in env
$db   = 'laravel';
$user = 'sail';
$pass = 'password';
$charset = 'utf8mb4';

echo "Using host: $host\n";

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::ATTR_TIMEOUT => 300, 
];

function connect($dsn, $user, $pass, $options) {
    $attempts = 0;
    while ($attempts < 10) {
        try {
            $pdo = new PDO($dsn, $user, $pass, $options);
            return $pdo;
        } catch (\PDOException $e) {
            $attempts++;
            echo "Connection attempt $attempts failed: " . $e->getMessage() . "\n";
            sleep(2);
        }
    }
    throw new Exception("Failed to connect after 10 attempts");
}

function safeRun($sql, $dsn, $user, $pass, $options) {
    $attempts = 0;
    while ($attempts < 5) {
        try {
            $pdo = connect($dsn, $user, $pass, $options);
            $pdo->exec($sql);
            echo "[SUCCESS] Executed: " . substr($sql, 0, 50) . "...\n";
            return;
        } catch (\Exception $e) {
            $msg = $e->getMessage();
            if (strpos($msg, 'Duplicate column name') !== false) {
                 echo "[SKIPPED] Column already exists.\n";
                 return;
            }
            $attempts++;
            echo "[RETRY $attempts] Error: " . $msg . "\n";
            sleep(1);
        }
    }
    echo "[FAIL] Could not execute sql after 5 attempts.\n";
}

safeRun("ALTER TABLE leave_types ADD COLUMN input_type VARCHAR(255) NULL AFTER description", $dsn, $user, $pass, $options);
safeRun("ALTER TABLE leave_types ADD COLUMN input_config JSON NULL AFTER input_type", $dsn, $user, $pass, $options);
safeRun("ALTER TABLE attendance_records ADD COLUMN leave_type_id BIGINT UNSIGNED NULL AFTER status", $dsn, $user, $pass, $options);
safeRun("ALTER TABLE attendance_records ADD COLUMN details JSON NULL AFTER leave_type_id", $dsn, $user, $pass, $options);

echo "Done.\n";
