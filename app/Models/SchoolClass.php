<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SchoolClass extends Model
{
    /** @use HasFactory<\Database\Factories\SchoolClassFactory> */
    protected $table = 'classes';
    protected $guarded = [];
    
    protected $casts = [
        'is_graduated' => 'boolean',
        'graduated_at' => 'datetime',
    ];

    public function school()
    {
        return $this->belongsTo(School::class);
    }
    
    public function grade()
    {
        return $this->belongsTo(Grade::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }
    
    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function students()
    {
        return $this->hasMany(Student::class, 'class_id');
    }
}
