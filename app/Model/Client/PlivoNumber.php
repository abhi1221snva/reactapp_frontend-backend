<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class PlivoNumber extends Model
{
    protected $table   = 'plivo_numbers';
    protected $guarded = ['id'];

    protected $casts = [
        'sub_type' => 'array',
    ];

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeForCampaign($query, int $campaignId)
    {
        return $query->where('campaign_id', $campaignId);
    }

    public function hasVoice(): bool
    {
        return (bool) ($this->sub_type['voice'] ?? true);
    }

    public function hasSms(): bool
    {
        return (bool) ($this->sub_type['sms'] ?? false);
    }
}
