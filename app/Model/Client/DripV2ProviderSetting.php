<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class DripV2ProviderSetting extends Model
{
    protected $table = 'drip_v2_provider_settings';

    protected $fillable = [
        'provider',
        'config',
        'is_default',
        'status',
        'created_by',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    protected $hidden = ['config'];

    public function setConfigAttribute($value): void
    {
        $this->attributes['config'] = $value
            ? Crypt::encryptString(is_string($value) ? $value : json_encode($value))
            : null;
    }

    public function getConfigAttribute(?string $value): ?array
    {
        if (!$value) return null;
        try {
            return json_decode(Crypt::decryptString($value), true);
        } catch (\Throwable) {
            return json_decode($value, true);
        }
    }
}
