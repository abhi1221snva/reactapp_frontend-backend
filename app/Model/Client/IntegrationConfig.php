<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class IntegrationConfig extends Model
{
    protected $table = 'integration_configs';

    public const PROVIDERS = [
        'balji',
        'datamerch',
        'experian',
        'persona',
        'plaid',
        'ucc_filings',
        'tracer',
        'pacer',
    ];

    protected $fillable = [
        'provider',
        'api_key',
        'api_secret',
        'endpoint_url',
        'extra_config',
        'is_enabled',
        'configured_by',
    ];

    protected $hidden = ['api_key', 'api_secret'];

    protected $casts = [
        'extra_config' => 'array',
        'is_enabled'   => 'boolean',
    ];

    // ── Encrypted mutators ─────────────────────────────────────────────────────

    public function setApiKeyAttribute(?string $value): void
    {
        $this->attributes['api_key'] = $value && !$this->isEncrypted($value)
            ? Crypt::encryptString($value)
            : $value;
    }

    public function getApiKeyAttribute(?string $value): ?string
    {
        if (!$value) return null;
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return $value;
        }
    }

    public function setApiSecretAttribute(?string $value): void
    {
        $this->attributes['api_secret'] = $value && !$this->isEncrypted($value)
            ? Crypt::encryptString($value)
            : $value;
    }

    public function getApiSecretAttribute(?string $value): ?string
    {
        if (!$value) return null;
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return $value;
        }
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function isEncrypted(string $value): bool
    {
        return strlen($value) > 100
            && str_starts_with(base64_decode(substr($value, 0, 12), true) ?: '', '{');
    }

    /**
     * Return a JSON-safe representation with has_api_key / has_api_secret
     * booleans instead of raw secret values.
     */
    public function toSafeArray(): array
    {
        return [
            'id'            => $this->id,
            'provider'      => $this->provider,
            'has_api_key'   => !empty($this->attributes['api_key']),
            'has_api_secret' => !empty($this->attributes['api_secret']),
            'endpoint_url'  => $this->endpoint_url,
            'extra_config'  => $this->extra_config,
            'is_enabled'    => $this->is_enabled,
            'configured_by' => $this->configured_by,
            'created_at'    => $this->created_at?->toDateTimeString(),
            'updated_at'    => $this->updated_at?->toDateTimeString(),
        ];
    }
}
