<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class AttendancePeriodService
{
    public function all(): Collection
    {
        $periods = json_decode((string) SystemSetting::get('attendance_periods', '[]'), true);
        if (!is_array($periods)) {
            return collect();
        }

        return collect($periods)
            ->map(fn ($period) => $this->normalize($period))
            ->sortBy('order')
            ->values();
    }

    public function active(): Collection
    {
        return $this->all()->where('is_active', true)->values();
    }

    public function find(int $periodId): ?array
    {
        return $this->all()->first(fn ($period) => (int) $period['id'] === $periodId);
    }

    public function eveningStudy(): Collection
    {
        return $this->active()->where('scene', 'evening_study')->values();
    }

    public function normalize(array $period): array
    {
        return [
            ...$period,
            'id' => (int) ($period['id'] ?? 0),
            'name' => trim((string) ($period['name'] ?? '')),
            'type' => $period['type'] ?? 'regular',
            'order' => (int) ($period['order'] ?? 0),
            'audience_scope' => $period['audience_scope'] ?? 'all',
            'scene' => $period['scene'] ?? 'regular',
            'counts_in_day_stats' => (bool) ($period['counts_in_day_stats'] ?? true),
            'is_active' => (bool) ($period['is_active'] ?? true),
        ];
    }

    public function validateConfiguration(array $periods): array
    {
        $normalized = collect($periods)->map(fn ($period) => $this->normalize($period));

        if ($normalized->pluck('id')->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['attendance_periods' => '节次 ID 不能重复']);
        }

        foreach ($normalized as $index => $period) {
            if ($period['id'] < 1 || $period['name'] === '') {
                throw ValidationException::withMessages(["attendance_periods.{$index}" => '节次 ID 和名称不能为空']);
            }
            if (!in_array($period['type'], ['regular', 'special'], true)) {
                throw ValidationException::withMessages(["attendance_periods.{$index}.type" => '节次类型无效']);
            }
            if (!in_array($period['audience_scope'], ['all', 'boarding'], true)) {
                throw ValidationException::withMessages(["attendance_periods.{$index}.audience_scope" => '适用学生范围无效']);
            }
            if (!in_array($period['scene'], ['regular', 'evening_study'], true)) {
                throw ValidationException::withMessages(["attendance_periods.{$index}.scene" => '考勤场景无效']);
            }
        }

        return $normalized->values()->all();
    }
}
