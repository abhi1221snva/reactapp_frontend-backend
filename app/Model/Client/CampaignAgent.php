<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

/**
 * Pivot model: agents assigned to a campaign for the auto-dialer.
 *
 * Table: campaign_agents
 * Columns: id, campaign_id, user_id, created_at, updated_at
 */
class CampaignAgent extends Model
{
    protected $table = 'campaign_agents';

    protected $fillable = [
        'campaign_id',
        'user_id',
    ];

    /**
     * Assign a user to a campaign (idempotent).
     */
    public static function assign(int $campaignId, int $userId): void
    {
        static::firstOrCreate([
            'campaign_id' => $campaignId,
            'user_id'     => $userId,
        ]);
    }

    /**
     * Remove a user from a campaign.
     */
    public static function unassign(int $campaignId, int $userId): void
    {
        static::where('campaign_id', $campaignId)
            ->where('user_id', $userId)
            ->delete();
    }

    /**
     * Return all user_ids assigned to a campaign.
     *
     * @return int[]
     */
    public static function userIdsForCampaign(int $campaignId): array
    {
        return static::where('campaign_id', $campaignId)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->toArray();
    }
}
