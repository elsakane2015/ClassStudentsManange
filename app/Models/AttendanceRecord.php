<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttendanceRecord extends Model
{
    /** @use HasFactory<\Database\Factories\AttendanceRecordFactory> */
    protected $guarded = [];

    protected $casts = [
        'date' => 'date',
        'informed_parent' => 'boolean',
        'details' => 'array',
        'images' => 'array',
        'is_self_applied' => 'boolean',
        'approved_at' => 'datetime',
        'counts_in_day_stats' => 'boolean',
        'manually_overridden_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('day_attendance', function ($query) {
            $query->where($query->getModel()->qualifyColumn('counts_in_day_stats'), true);
        });
    }

    public function scopeDayAttendance($query)
    {
        return $query->withoutGlobalScope('day_attendance')->where('counts_in_day_stats', true);
    }

    public function scopeEveningStudy($query)
    {
        return $query->withoutGlobalScope('day_attendance')->where('scene', 'evening_study');
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }
    
    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function class()
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function leaveType()
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function requestedEveningStatus()
    {
        return $this->belongsTo(EveningStudyStatus::class, 'requested_evening_status_id');
    }

    public function eveningStudyStatus()
    {
        return $this->belongsTo(EveningStudyStatus::class, 'evening_study_status_id');
    }

    public function eveningStudySession()
    {
        return $this->belongsTo(EveningStudySession::class);
    }

    public function boardingSuspension()
    {
        return $this->belongsTo(BoardingSuspension::class);
    }
}
