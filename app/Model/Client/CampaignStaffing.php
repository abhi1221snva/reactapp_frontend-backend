<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class CampaignStaffing extends Model
{
    protected $table = 'campaign_staffing';

    protected $fillable = [
        'campaign_id',
        'required_agents',
        'min_agents',
    ];

    protected $casts = [
        'required_agents' => 'integer',
        'min_agents'      => 'integer',
    ];

    /**
     * Get or create staffing record for a campaign.
     */
    public static function forCampaign(int $campaignId): self
    {
        return self::firstOrNew(
            ['campaign_id' => $campaignId],
            ['required_agents' => 0, 'min_agents' => 0]
        );
    }
}
