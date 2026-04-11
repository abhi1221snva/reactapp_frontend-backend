<?php

namespace App\Model\Master\Rvm;

use Illuminate\Database\Eloquent\Model;

class ApiKey extends Model
{
    protected $connection = 'master';
    protected $table = 'rvm_api_keys';

    protected $fillable = [
        'client_id', 'created_by_user_id', 'name',
        'key_prefix', 'key_hash', 'hmac_secret',
        'scopes', 'rate_limit_per_minute',
        'last_used_at', 'last_used_ip',
        'revoked_at', 'revoked_reason',
    ];

    protected $hidden = ['key_hash', 'hmac_secret'];

    protected $casts = [
        'scopes' => 'array',
        'rate_limit_per_minute' => 'int',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function hasScope(string $scope): bool
    {
        if (!$this->scopes) return true; // null = all scopes
        return in_array($scope, $this->scopes, true)
            || in_array('*', $this->scopes, true);
    }
}
