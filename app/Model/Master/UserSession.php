<?php

namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;

class UserSession extends Model
{
    protected $connection = 'master';
    protected $table      = 'user_sessions';

    protected $fillable = [
        'user_id',
        'token_hash',
        'device_type',
        'browser',
        'os',
        'ip_address',
        'last_active_at',
    ];

    protected $casts = [
        'last_active_at' => 'datetime',
    ];
}
