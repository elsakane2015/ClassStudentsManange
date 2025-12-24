<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RollCall extends Model
{
    protected $fillable = [
        'class_id',
        'school_id',
        'roll_call_type_id',
        'roll_call_time',
        'created_by',
        'status',
        'total_students',
        'present_count',
        'on_leave_count',
        'notes',
        'completed_at',
    ];

    protected $casts = [
        'roll_call_time' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function class()
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function rollCallType()
    {
        return $this->belongsTo(RollCallType::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function records()
    {
        return $this->hasMany(RollCallRecord::class);
    }

    public function isInProgress(): bool
    {
        return $this->status === 'in_progress';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function getAbsentCountAttribute(): int
    {
        return $this->total_students - $this->present_count - $this->on_leave_count;
    }
}
