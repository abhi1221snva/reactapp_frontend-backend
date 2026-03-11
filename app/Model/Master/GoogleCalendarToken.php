<?php

namespace App\Model\Master;

use App\Model\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

class GoogleCalendarToken extends Model
{
    protected $connection = 'master';
    protected $table = 'google_calendar_tokens';

    protected $fillable = [
        'user_id',
        'calendar_email',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'scope',
        'is_active',
        'last_sync_at',
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'last_sync_at'     => 'datetime',
        'is_active'        => 'boolean',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isExpired(): bool
    {
        if (!$this->token_expires_at) {
            return true;
        }
        return $this->token_expires_at->isPast();
    }

    public function isExpiringSoon(int $minutes = 5): bool
    {
        if (!$this->token_expires_at) {
            return true;
        }
        return Carbon::now()->addMinutes($minutes)->greaterThanOrEqualTo($this->token_expires_at);
    }

    public function getDecryptedAccessToken(): ?string
    {
        if (!$this->access_token) {
            return null;
        }
        try {
            $decrypted = Crypt::decryptString($this->access_token);
            return ($decrypted !== null && $decrypted !== '') ? $decrypted : null;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('GoogleCalendarToken: access_token decryption failed — token likely corrupted', [
                'user_id' => $this->user_id,
            ]);
            return null;
        }
    }

    public function getDecryptedRefreshToken(): ?string
    {
        if (!$this->refresh_token) {
            return null;
        }
        try {
            $decrypted = Crypt::decryptString($this->refresh_token);
            // Guard against encrypted empty-string artifact (null stored via mutator bug)
            return ($decrypted !== null && $decrypted !== '') ? $decrypted : null;
        } catch (\Exception $e) {
            // Decryption failure means the stored value is corrupt; treat as missing
            \Illuminate\Support\Facades\Log::warning('GoogleCalendarToken: refresh_token decryption failed — token likely corrupted', [
                'user_id' => $this->user_id,
            ]);
            return null;
        }
    }

    public function setAccessTokenAttribute($value)
    {
        $this->attributes['access_token'] = Crypt::encryptString($value);
    }

    public function setRefreshTokenAttribute($value)
    {
        // Never encrypt null/empty — store actual NULL so the column semantics are preserved
        $this->attributes['refresh_token'] = ($value !== null && $value !== '')
            ? Crypt::encryptString($value)
            : null;
    }

    public function deactivate(): void
    {
        $this->is_active = false;
        $this->save();
    }

    public static function getActiveForUser(int $userId): ?self
    {
        return static::where('user_id', $userId)
            ->where('is_active', true)
            ->first();
    }
}
