<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class School extends Model
{
    /** @use HasFactory<\Database\Factories\SchoolFactory> */
    protected $guarded = [];

    protected $casts = [
        'config' => 'array',
    ];

    public function grades()
    {
        return $this->hasMany(Grade::class);
    }

    public function classes()
    {
        return $this->hasMany(SchoolClass::class);
    }
}
