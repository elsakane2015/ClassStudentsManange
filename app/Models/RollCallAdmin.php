<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RollCallAdmin extends Model
{
    protected $fillable = [
        'class_id',
        'student_id',
        'roll_call_type_ids',
        'created_by',
        'is_active',
        'can_modify_records',
    ];

    protected $casts = [
        'roll_call_type_ids' => 'array',
        'is_active' => 'boolean',
        'can_modify_records' => 'boolean',
    ];

    public function class()
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the roll call types this admin is authorized for
     */
    public function authorizedTypes()
    {
        return RollCallType::whereIn('id', $this->roll_call_type_ids ?? [])->get();
    }
}
