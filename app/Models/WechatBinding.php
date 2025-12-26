<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WechatBinding extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'config_id',
        'openid',
        'nickname',
        'headimgurl',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function config()
    {
        return $this->belongsTo(WechatConfig::class, 'config_id');
    }
}
