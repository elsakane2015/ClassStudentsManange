<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\School;

class LeaveType extends Model
{
    protected $fillable = ['school_id', 'name', 'slug', 'description', 'is_active', 'student_requestable', 'color', 'input_type', 'input_config'];

    protected $casts = [
        'is_active' => 'boolean',
        'student_requestable' => 'boolean',
        'input_config' => 'array',
    ];

    public function school()
    {
        return $this->belongsTo(School::class);
    }
}
