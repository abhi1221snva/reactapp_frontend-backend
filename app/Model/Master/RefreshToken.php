<?php

namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;

class RefreshToken extends Model
{
    protected $connection = 'master';
    protected $table      = 'refresh_tokens';

    protected $fillable = [
        'user_id',
        'token_hash',
        'family_id',
        'expires_at',
        'revoked',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked'    => 'boolean',
    ];
}
