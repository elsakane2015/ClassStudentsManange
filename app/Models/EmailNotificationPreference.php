<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailNotificationPreference extends Model
{
    protected $guarded = [];

    protected $casts = [
        'enabled' => 'boolean',
        'email_enabled' => 'boolean',
        'email_fallback_to_resend' => 'boolean',
        'sms_enabled' => 'boolean',
        'enabled_events' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
