<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClassPeriod extends Model
{
    /** @use HasFactory<\Database\Factories\ClassPeriodFactory> */
    protected $guarded = [];
    
    public function school()
    {
        return $this->belongsTo(School::class);
    }
}
