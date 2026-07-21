<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EveningStudyStatus extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_default' => 'boolean',
        'student_requestable' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function leaveType()
    {
        return $this->belongsTo(LeaveType::class);
    }
}
