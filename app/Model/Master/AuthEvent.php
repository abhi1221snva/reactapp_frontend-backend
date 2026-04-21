<?php

namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;

class AuthEvent extends Model
{
    protected $connection = 'master';
    protected $table      = 'auth_events';
    public $timestamps    = false;

    protected $fillable = [
        'user_id',
        'event_type',
        'ip_address',
        'user_agent',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'metadata'   => 'array',
        'created_at' => 'datetime',
    ];
}
