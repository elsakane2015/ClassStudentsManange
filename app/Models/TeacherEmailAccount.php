<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeacherEmailAccount extends Model
{
    protected $guarded = [];

    protected $hidden = [
        'secret',
        'access_token',
        'refresh_token',
    ];

    protected $casts = [
        'secret' => 'encrypted',
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'token_expires_at' => 'datetime',
        'is_verified' => 'boolean',
        'verified_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
