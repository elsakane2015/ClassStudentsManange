<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\School;

class LeaveType extends Model
{
    protected $fillable = ['school_id', 'name', 'slug', 'description', 'is_active', 'student_requestable', 'gender_restriction', 'color', 'input_type', 'input_config', 'display_unit', 'use_conversion', 'counts_as_absence', 'affects_roll_call'];

    protected $casts = [
        'is_active' => 'boolean',
        'student_requestable' => 'boolean',
        'input_config' => 'array',
        'use_conversion' => 'boolean',
        'counts_as_absence' => 'boolean',
        'affects_roll_call' => 'boolean',
    ];

    public function school()
    {
        return $this->belongsTo(School::class);
    }
}
