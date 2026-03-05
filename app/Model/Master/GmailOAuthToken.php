<?php

namespace App\Model\Master;

use App\Model\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

class GmailOAuthToken extends Model
{
    protected $connection = 'master';
    protected $table = 'gmail_oauth_tokens';

    protected $fillable = [
        'user_id',
        'gmail_email',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'scope',
        'is_active',
        'last_sync_at',
        'last_history_id',
        'watch_expiration'
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'last_sync_at' => 'datetime',
        'watch_expiration' => 'datetime',
        'is_active' => 'boolean',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    /**
     * Get the user that owns the token.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Check if the access token is expired.
     */
    public function isExpired(): bool
    {
        if (!$this->token_expires_at) {
            return true;
        }
        return $this->token_expires_at->isPast();
    }

    /**
     * Check if the access token will expire soon.
     */
    public function isExpiringSoon(int $minutes = 5): bool
    {
        if (!$this->token_expires_at) {
            return true;
        }
        return Carbon::now()->addMinutes($minutes)->greaterThanOrEqualTo($this->token_expires_at);
    }

    /**
     * Get the decrypted access token.
     */
    public function getDecryptedAccessToken(): ?string
    {
        if (!$this->access_token) {
            return null;
        }
        try {
            return Crypt::decryptString($this->access_token);
        } catch (\Exception $e) {
            // Token might not be encrypted (legacy)
            return $this->access_token;
        }
    }

    /**
     * Get the decrypted refresh token.
     */
    public function getDecryptedRefreshToken(): ?string
    {
        if (!$this->refresh_token) {
            return null;
        }
        try {
            return Crypt::decryptString($this->refresh_token);
        } catch (\Exception $e) {
            // Token might not be encrypted (legacy)
            return $this->refresh_token;
        }
    }

    /**
     * Set the access token (encrypted).
     */
    public function setAccessTokenAttribute($value)
    {
        $this->attributes['access_token'] = Crypt::encryptString($value);
    }

    /**
     * Set the refresh token (encrypted).
     */
    public function setRefreshTokenAttribute($value)
    {
        $this->attributes['refresh_token'] = Crypt::encryptString($value);
    }

    /**
     * Update the last sync timestamp.
     */
    public function updateLastSync(): void
    {
        $this->last_sync_at = Carbon::now();
        $this->save();
    }

    /**
     * Deactivate the token.
     */
    public function deactivate(): void
    {
        $this->is_active = false;
        $this->save();
    }

    /**
     * Get active token for a user.
     */
    public static function getActiveForUser(int $userId): ?self
    {
        return static::where('user_id', $userId)
            ->where('is_active', true)
            ->first();
    }
}
