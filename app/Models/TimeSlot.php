<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TimeSlot extends Model
{
    protected $fillable = [
        'school_id',
        'name',
        'time_start',
        'time_end',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'time_start' => 'datetime:H:i',
        'time_end' => 'datetime:H:i',
    ];

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Check if a given time falls within this time slot
     */
    public function containsTime($time): bool
    {
        $checkTime = is_string($time) ? strtotime($time) : $time->timestamp;
        $startTime = strtotime($this->time_start);
        $endTime = strtotime($this->time_end);
        
        // Handle overnight slots (e.g., 22:00 - 06:00)
        if ($endTime < $startTime) {
            return $checkTime >= $startTime || $checkTime <= $endTime;
        }
        
        return $checkTime >= $startTime && $checkTime <= $endTime;
    }
}
