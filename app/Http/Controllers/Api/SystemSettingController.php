<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\SystemSetting;

class SystemSettingController extends Controller
{
    public function index()
    {
        return response()->json(SystemSetting::all());
    }

    public function update(Request $request)
    {
        // Mitigate "MySQL server has gone away" issues
        try {
            \Illuminate\Support\Facades\DB::reconnect();
            
            // Workaround: Ensure table exists using raw SQL if migration failed
            \Illuminate\Support\Facades\DB::statement("
                CREATE TABLE IF NOT EXISTS system_settings (
                    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                    `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
                    `value` text COLLATE utf8mb4_unicode_ci,
                    description varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                    created_at timestamp NULL DEFAULT NULL,
                    updated_at timestamp NULL DEFAULT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY system_settings_key_unique (`key`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        } catch (\Exception $e) {}

        // Expects an array of settings: [{key: 'k', value: 'v'}, ...]
        $data = $request->validate([
            'settings' => 'required|array',
            'settings.*.key' => 'required|string',
            'settings.*.value' => 'required'
        ]);

        foreach ($data['settings'] as $setting) {
            SystemSetting::updateOrCreate(
                ['key' => $setting['key']],
                ['value' => $setting['value']]
            );
        }

        return response()->json(['message' => 'Settings updated successfully']);
    }
    
    /**
     * Clean up deleted period ID from all time_slots
     */
    public function cleanupPeriod(Request $request)
    {
        $data = $request->validate([
            'period_id' => 'required|integer'
        ]);
        
        $periodId = $data['period_id'];
        $updatedCount = 0;
        
        $timeSlots = \App\Models\TimeSlot::whereNotNull('period_ids')->get();
        
        foreach ($timeSlots as $slot) {
            $periodIds = $slot->period_ids ?? [];
            if (in_array($periodId, $periodIds)) {
                $slot->period_ids = array_values(array_filter($periodIds, fn($id) => $id !== $periodId));
                $slot->save();
                $updatedCount++;
            }
        }
        
        return response()->json([
            'message' => "Period ID {$periodId} removed from {$updatedCount} time slots"
        ]);
    }
}
