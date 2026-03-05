<?php

namespace App\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class TeamChatWidgetToken extends Model
{
    protected $connection = 'master';
    protected $table = 'team_chat_widget_tokens';

    protected $fillable = [
        'user_id',
        'parent_id',
        'token',
        'name',
        'allowed_domains',
        'is_active',
        'expires_at',
        'last_used_at',
    ];

    protected $casts = [
        'allowed_domains' => 'array',
        'is_active' => 'boolean',
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    /**
     * Get the user that owns this token
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Generate a new unique token
     */
    public static function generateToken()
    {
        return 'tcw_' . bin2hex(random_bytes(32));
    }

    /**
     * Check if the token is valid
     */
    public function isValid()
    {
        if (!$this->is_active) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Check if domain is allowed
     */
    public function isDomainAllowed($domain)
    {
        if (empty($this->allowed_domains)) {
            return true; // No restrictions
        }

        foreach ($this->allowed_domains as $allowedDomain) {
            if ($allowedDomain === '*') {
                return true;
            }
            if (strtolower($domain) === strtolower($allowedDomain)) {
                return true;
            }
            // Wildcard subdomain matching
            if (strpos($allowedDomain, '*.') === 0) {
                $baseDomain = substr($allowedDomain, 2);
                if (preg_match('/^([a-z0-9-]+\.)*' . preg_quote($baseDomain, '/') . '$/i', $domain)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Update last used timestamp
     */
    public function markAsUsed()
    {
        $this->update(['last_used_at' => Carbon::now()]);
    }
}
