<?php

// Test dynamic period creation

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== Testing Dynamic Period Creation ===\n\n";

// Simulate the API logic
$dailyPeriods = \App\Models\SystemSetting::where('key', 'daily_lessons_count')->value('value');
$dailyPeriods = $dailyPeriods ? (int)$dailyPeriods : 8;
echo "1. Daily lessons count: {$dailyPeriods}\n\n";

$schoolId = 1;

// Get all periods
$allPeriods = \App\Models\ClassPeriod::where('school_id', $schoolId)
    ->orderBy('ordinal')
    ->get();
echo "2. All periods count: " . $allPeriods->count() . "\n";

// Filter regular periods
$regularPeriods = $allPeriods->filter(function($period) {
    return !in_array($period->name, ['早操', '晚操', 'Lunch', '午休']);
});
echo "3. Regular periods count (before): " . $regularPeriods->count() . "\n\n";

// Check if we need to create new periods
$currentCount = $regularPeriods->count();
if ($currentCount < $dailyPeriods) {
    echo "4. Need to create " . ($dailyPeriods - $currentCount) . " more periods\n";
    
    // Find used ordinals
    $usedOrdinals = $allPeriods->pluck('ordinal')->toArray();
    echo "   Used ordinals: " . implode(', ', $usedOrdinals) . "\n\n";
    
    // Create missing periods
    for ($i = $currentCount + 1; $i <= $dailyPeriods; $i++) {
        // Find unused ordinal (starting from 10)
        $ordinal = 10 + $i - 1;
        while (in_array($ordinal, $usedOrdinals)) {
            $ordinal++;
        }
        
        // Calculate time (45min class, 10min break)
        $startHour = 8 + floor(($i - 1) * 55 / 60);
        $startMinute = ($i - 1) * 55 % 60;
        $endHour = 8 + floor((($i - 1) * 55 + 45) / 60);
        $endMinute = (($i - 1) * 55 + 45) % 60;
        
        $period = \App\Models\ClassPeriod::create([
            'school_id' => $schoolId,
            'name' => "Period {$i}",
            'ordinal' => $ordinal,
            'start_time' => sprintf('%02d:%02d:00', $startHour, $startMinute),
            'end_time' => sprintf('%02d:%02d:00', $endHour, $endMinute)
        ]);
        
        echo "   Created: Period {$i} (ordinal={$ordinal}, {$period->start_time}-{$period->end_time})\n";
        
        $usedOrdinals[] = $ordinal;
    }
    
    echo "\n5. Re-fetching periods...\n";
    
    // Re-fetch
    $allPeriods = \App\Models\ClassPeriod::where('school_id', $schoolId)
        ->orderBy('ordinal')
        ->get();
    
    $regularPeriods = $allPeriods->filter(function($period) {
        return !in_array($period->name, ['早操', '晚操', 'Lunch', '午休']);
    });
}

echo "6. Regular periods count (after): " . $regularPeriods->count() . "\n";
echo "7. Returning first {$dailyPeriods} periods\n\n";

$result = $regularPeriods->take($dailyPeriods)->values();
echo "8. Final result:\n";
foreach ($result as $p) {
    echo "   - {$p->name} (ordinal={$p->ordinal})\n";
}

echo "\n=== Test Complete ===\n";
