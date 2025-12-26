<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class WechatConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'use_system',
        'appid',
        'appsecret',
        'token',
        'template_id',
        'is_verified',
        'verified_at',
    ];

    protected $casts = [
        'use_system' => 'boolean',
        'is_verified' => 'boolean',
        'verified_at' => 'datetime',
    ];

    protected $hidden = [
        'appsecret',
    ];

    // 加密存储 appsecret
    public function setAppsecretAttribute($value)
    {
        if ($value) {
            $this->attributes['appsecret'] = Crypt::encryptString($value);
        } else {
            $this->attributes['appsecret'] = null;
        }
    }

    // 解密读取 appsecret
    public function getDecryptedAppsecret()
    {
        if ($this->attributes['appsecret']) {
            try {
                return Crypt::decryptString($this->attributes['appsecret']);
            } catch (\Exception $e) {
                return null;
            }
        }
        return null;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function bindings()
    {
        return $this->hasMany(WechatBinding::class, 'config_id');
    }
}
