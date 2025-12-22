<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Semester extends Model
{
    protected $fillable = ['school_id', 'name', 'start_date', 'total_weeks', 'holidays', 'is_current'];

    protected $casts = [
        'start_date' => 'date',
        'is_current' => 'boolean',
        'holidays' => 'array',
    ];

    public function school()
    {
        return $this->belongsTo(School::class);
    }
}
