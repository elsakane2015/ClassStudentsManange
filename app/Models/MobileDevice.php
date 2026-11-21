<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobileDevice extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'platform',
        'push_provider',
        'push_environment',
        'device_identifier',
        'push_token',
        'device_name',
        'app_version',
        'os_version',
        'locale',
        'push_enabled',
        'last_active_at',
    ];

    protected $casts = [
        'push_enabled' => 'boolean',
        'last_active_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
