<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RollCallType extends Model
{
    protected $fillable = [
        'school_id',
        'class_id',
        'name',
        'description',
        'absent_status',
        'leave_type_id',
        'is_active',
        'sort_order',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

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

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function rollCalls()
    {
        return $this->hasMany(RollCall::class);
    }
}
