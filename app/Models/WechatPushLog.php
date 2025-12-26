<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WechatPushLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'config_id',
        'user_id',
        'openid',
        'template_id',
        'content',
        'status',
        'error_msg',
        'related_type',
        'related_id',
    ];

    protected $casts = [
        'content' => 'array',
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
