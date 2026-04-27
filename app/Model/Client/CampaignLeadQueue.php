<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

/**
 * Sequential lead queue for click-to-call campaign dialer.
 * Each row = one lead's queue slot in a campaign.
 */
class CampaignLeadQueue extends Model
{
    protected $table = 'campaign_lead_queue';

    protected $fillable = [
        'campaign_id',
        'lead_id',
        'status',
        'disposition_id',
        'attempts',
        'sort_order',
        'next_attempt_at',
        'called_at',
        'completed_at',
    ];

    protected $casts = [
        'disposition_id'  => 'integer',
        'next_attempt_at' => 'datetime',
        'called_at'       => 'datetime',
        'completed_at'    => 'datetime',
    ];

    const STATUS_PENDING   = 'pending';
    const STATUS_CALLING   = 'calling';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED    = 'failed';
    const STATUS_SKIPPED   = 'skipped';

    /**
     * Get next dialable lead for a campaign (ready-to-call, not already being attempted).
     * Uses pessimistic lock to prevent concurrent workers from double-dialing.
     */
    public static function nextDialable(int $campaignId, string $dbConnection = null): ?self
    {
        $query = $dbConnection ? static::on($dbConnection) : static::query();

        return $query->where('campaign_id', $campaignId)
            ->where('status', self::STATUS_PENDING)
            ->where(function ($q) {
                $q->whereNull('next_attempt_at')
                  ->orWhere('next_attempt_at', '<=', now());
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->lockForUpdate()
            ->first();
    }

    /**
     * Populate queue from leads assigned to a campaign's lists.
     * Run once when campaign starts; idempotent (ignores existing rows).
     *
     * @param  string  $connection  tenant DB connection name
     */
    public static function populateFromCampaign(int $campaignId, string $connection = null): int
    {
        $db = \Illuminate\Support\Facades\DB::connection($connection);

        // Insert leads from campaign lists via list_data, sorted by row id
        $inserted = $db->statement("
            INSERT IGNORE INTO campaign_lead_queue (campaign_id, lead_id, status, sort_order, created_at, updated_at)
            SELECT
                cl.campaign_id,
                ld.id            AS lead_id,
                'pending'        AS status,
                ld.id            AS sort_order,
                NOW(),
                NOW()
            FROM campaign_list cl
            JOIN list_data ld ON ld.list_id = cl.list_id
            WHERE cl.campaign_id = ?
              AND (cl.is_deleted = 0 OR cl.is_deleted IS NULL)
            ORDER BY ld.id ASC
        ", [$campaignId]);

        // Return count
        return (int) $db->table('campaign_lead_queue')
            ->where('campaign_id', $campaignId)
            ->count();
    }
}
