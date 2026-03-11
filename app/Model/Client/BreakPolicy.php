<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class BreakPolicy extends Model
{
    protected $table = 'break_policies';

    protected $fillable = [
        'campaign_id',
        'max_concurrent_breaks',
        'max_break_minutes',
    ];

    protected $casts = [
        'max_concurrent_breaks' => 'integer',
        'max_break_minutes'     => 'integer',
    ];

    /**
     * Get active break policy: campaign-specific first, then global default.
     */
    public static function forCampaign(?int $campaignId): self
    {
        // Try campaign-specific policy
        if ($campaignId) {
            $policy = self::where('campaign_id', $campaignId)->first();
            if ($policy) return $policy;
        }

        // Fall back to global default (campaign_id IS NULL)
        $global = self::whereNull('campaign_id')->first();
        if ($global) return $global;

        // Return in-memory default if none configured
        $default = new self();
        $default->max_concurrent_breaks = 3;
        $default->max_break_minutes     = 60;
        return $default;
    }

    /**
     * Count agents currently on break for a campaign.
     */
    public static function currentBreakCount(?int $campaignId): int
    {
        $query = AgentStatus::where('status', AgentStatus::ON_BREAK);
        if ($campaignId) {
            $query->where('campaign_id', $campaignId);
        }
        return $query->count();
    }
}
