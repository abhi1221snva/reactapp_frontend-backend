<?php

namespace App\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class PlivoAccount extends Model
{
    protected $connection = 'master';
    protected $table      = 'plivo_accounts';
    protected $guarded    = ['id'];

    protected $casts = [
        'blocked_countries' => 'array',
    ];

    // ── Encrypted attribute accessors ──────────────────────────────────────

    public function getAuthIdAttribute(?string $value): ?string
    {
        return $value ? Crypt::decryptString($value) : null;
    }

    public function setAuthIdAttribute(?string $value): void
    {
        $this->attributes['auth_id'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getAuthTokenAttribute(?string $value): ?string
    {
        return $value ? Crypt::decryptString($value) : null;
    }

    public function setAuthTokenAttribute(?string $value): void
    {
        $this->attributes['auth_token'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getSubaccountAuthTokenAttribute(?string $value): ?string
    {
        return $value ? Crypt::decryptString($value) : null;
    }

    public function setSubaccountAuthTokenAttribute(?string $value): void
    {
        $this->attributes['subaccount_auth_token'] = $value ? Crypt::encryptString($value) : null;
    }

    // ── Relations ──────────────────────────────────────────────────────────

    public function subaccounts()
    {
        return $this->hasMany(PlivoSubaccount::class, 'plivo_account_id');
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    public function maskedToken(): string
    {
        $token = $this->auth_token ?? $this->subaccount_auth_token ?? '';
        return str_repeat('*', max(0, strlen($token) - 4)) . substr($token, -4);
    }

    /**
     * Resolve the effective Auth ID + Auth Token for this account.
     * Uses subaccount when available, falls back to master env credentials.
     */
    public function resolveCredentials(): array
    {
        if ($this->auth_id && $this->auth_token) {
            return [$this->auth_id, $this->auth_token];
        }

        if ($this->subaccount_auth_id && $this->subaccount_auth_token) {
            return [$this->subaccount_auth_id, $this->subaccount_auth_token];
        }

        return [env('PLIVO_AUTH_ID'), env('PLIVO_AUTH_TOKEN')];
    }
}
