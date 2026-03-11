<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class CampaignNumber extends Model
{
    protected $table   = 'campaign_numbers';
    protected $guarded = ['id'];

    protected $dates = ['last_used_at'];

    // ── Relations ──────────────────────────────────────────────────────────

    public function twilioNumber()
    {
        return $this->belongsTo(TwilioNumber::class, 'twilio_number_id');
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * Pick next number for a campaign using round-robin (oldest last_used_at wins).
     */
    public static function nextForCampaign(int $campaignId): ?self
    {
        return static::where('campaign_id', $campaignId)
            ->where('is_active', 1)
            ->orderByRaw('last_used_at IS NULL DESC')
            ->orderBy('last_used_at', 'asc')
            ->lockForUpdate()
            ->first();
    }
}
