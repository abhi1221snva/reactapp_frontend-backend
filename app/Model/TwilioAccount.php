<?php

namespace App\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class TwilioAccount extends Model
{
    protected $connection = 'master';
    protected $table      = 'twilio_accounts';
    protected $guarded    = ['id'];

    protected $casts = [
        'blocked_countries' => 'array',
    ];

    // ── Encrypted attribute accessors ──────────────────────────────────────

    public function getAccountSidAttribute(?string $value): ?string
    {
        return $value ? Crypt::decryptString($value) : null;
    }

    public function setAccountSidAttribute(?string $value): void
    {
        $this->attributes['account_sid'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getAuthTokenAttribute(?string $value): ?string
    {
        return $value ? Crypt::decryptString($value) : null;
    }

    public function setAuthTokenAttribute(?string $value): void
    {
        $this->attributes['auth_token'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getSubaccountTokenAttribute(?string $value): ?string
    {
        return $value ? Crypt::decryptString($value) : null;
    }

    public function setSubaccountTokenAttribute(?string $value): void
    {
        $this->attributes['subaccount_token'] = $value ? Crypt::encryptString($value) : null;
    }

    // ── Relations ──────────────────────────────────────────────────────────

    public function subaccounts()
    {
        return $this->hasMany(TwilioSubaccount::class, 'twilio_account_id');
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * Return masked token for display (last 4 chars visible).
     */
    public function maskedToken(): string
    {
        $token = $this->auth_token ?? $this->subaccount_token ?? '';
        return str_repeat('*', max(0, strlen($token) - 4)) . substr($token, -4);
    }

    /**
     * Resolve the effective SID + token for this account.
     * Uses subaccount when available, falls back to master env credentials.
     */
    public function resolveCredentials(): array
    {
        if ($this->account_sid && $this->auth_token) {
            return [$this->account_sid, $this->auth_token];
        }

        if ($this->subaccount_sid && $this->subaccount_token) {
            return [$this->subaccount_sid, $this->subaccount_token];
        }

        // Fall back to master platform credentials
        return [env('TWILIO_SID'), env('TWILIO_AUTH_TOKEN')];
    }
}
