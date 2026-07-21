<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $setting = DB::table('system_settings')->where('key', 'attendance_periods')->first();
        if (!$setting) {
            return;
        }

        $periods = json_decode((string) $setting->value, true);
        if (!is_array($periods)) {
            return;
        }

        $changed = false;
        foreach ($periods as &$period) {
            $name = trim((string) ($period['name'] ?? ''));
            if (($period['scene'] ?? 'regular') === 'regular' && str_contains($name, '夜自习')) {
                $period['scene'] = 'evening_study';
                $period['audience_scope'] = 'boarding';
                $period['counts_in_day_stats'] = false;
                $changed = true;
            }
        }
        unset($period);

        if ($changed) {
            DB::table('system_settings')->where('id', $setting->id)->update([
                'value' => json_encode($periods, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Historical configuration cannot be reconstructed safely.
    }
};
