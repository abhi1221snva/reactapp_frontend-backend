<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class TwilioNumber extends Model
{
    protected $table   = 'twilio_numbers';
    protected $guarded = ['id'];

    protected $casts = [
        'capabilities' => 'array',
    ];

    // ── Scopes ─────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeForCampaign($query, int $campaignId)
    {
        return $query->where('campaign_id', $campaignId)->where('status', 'active');
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    public function hasVoice(): bool
    {
        return (bool) ($this->capabilities['voice'] ?? false);
    }

    public function hasSms(): bool
    {
        return (bool) ($this->capabilities['SMS'] ?? $this->capabilities['sms'] ?? false);
    }
}
