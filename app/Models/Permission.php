<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    protected $fillable = [
        'name',
        'display_name',
        'description',
        'category'
    ];

    public function rolePermissions()
    {
        return $this->hasMany(RolePermission::class);
    }
}
