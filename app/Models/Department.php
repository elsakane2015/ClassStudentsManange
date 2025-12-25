<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    use HasFactory;

    protected $fillable = ['school_id', 'name'];

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Get all managers for this department (many-to-many)
     */
    public function managers()
    {
        return $this->belongsToMany(User::class, 'department_managers', 'department_id', 'user_id')
            ->withTimestamps();
    }

    public function classes()
    {
        return $this->hasMany(SchoolClass::class);
    }
}
