<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Student extends Model
{
    /** @use HasFactory<\Database\Factories\StudentFactory> */
    protected $fillable = ['user_id', 'class_id', 'school_id', 'student_no', 'gender', 'is_boarding', 'parent_contact', 'parent_email', 'is_manager', 'is_class_admin'];

    protected $casts = [
        'birthdate' => 'date',
        'is_boarding' => 'boolean',
        'is_manager' => 'boolean',
        'is_class_admin' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function attendance()
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function boardingSuspensions()
    {
        return $this->hasMany(BoardingSuspension::class);
    }

    public function activeBoardingSuspension($date = null)
    {
        return $this->boardingSuspensions()
            ->activeOn($date ?? now()->toDateString())
            ->latest('start_date')
            ->first();
    }
}
