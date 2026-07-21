<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EveningStudySession extends Model
{
    protected $guarded = [];

    protected $casts = [
        'attendance_date' => 'date',
        'completed_at' => 'datetime',
    ];

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function records()
    {
        return $this->hasMany(AttendanceRecord::class, 'evening_study_session_id')
            ->withoutGlobalScope('day_attendance');
    }
}
