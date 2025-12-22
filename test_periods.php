<?php

// Test script for /class-periods API logic

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== Testing /class-periods API Logic ===\n\n";

// Step 1: Get daily_lessons_count from database
$dailyPeriods = \App\Models\SystemSetting::where('key', 'daily_lessons_count')->value('value');
echo "1. Daily lessons count from DB: " . ($dailyPeriods ?? 'NULL') . "\n";

$dailyPeriods = $dailyPeriods ? (int)$dailyPeriods : 8;
echo "2. Daily lessons count (int): " . $dailyPeriods . "\n\n";

// Step 2: Get all periods
$allPeriods = \App\Models\ClassPeriod::where('school_id', 1)->orderBy('ordinal')->get();
echo "3. All periods count: " . $allPeriods->count() . "\n";
echo "   All periods:\n";
foreach ($allPeriods as $p) {
    echo "   - id={$p->id}, name={$p->name}, ordinal={$p->ordinal}\n";
}
echo "\n";

// Step 3: Filter special periods
$regularPeriods = $allPeriods->filter(function($period) {
    return !in_array($period->name, ['早操', '晚操', 'Lunch', '午休']);
});
echo "4. Regular periods count: " . $regularPeriods->count() . "\n";
echo "   Regular periods:\n";
foreach ($regularPeriods as $p) {
    echo "   - id={$p->id}, name={$p->name}, ordinal={$p->ordinal}\n";
}
echo "\n";

// Step 4: Take first N periods
$result = $regularPeriods->take($dailyPeriods)->values();
echo "5. Result count (take {$dailyPeriods}): " . $result->count() . "\n";
echo "   Result periods:\n";
foreach ($result as $p) {
    echo "   - id={$p->id}, name={$p->name}, ordinal={$p->ordinal}\n";
}
echo "\n";

echo "=== Test Complete ===\n";
