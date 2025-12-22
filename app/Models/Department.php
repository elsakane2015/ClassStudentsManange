<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    use HasFactory;

    protected $fillable = ['school_id', 'name', 'manager_id'];

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function manager()
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function classes()
    {
        return $this->hasMany(SchoolClass::class);
    }
}
