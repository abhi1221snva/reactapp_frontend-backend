<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class PlivoCall extends Model
{
    protected $table   = 'plivo_calls';
    protected $guarded = ['id'];

    protected $dates = ['started_at', 'ended_at'];

    public function scopeCompleted($query)
    {
        return $query->where('call_status', 'completed');
    }

    public function scopeForCampaign($query, int $campaignId)
    {
        return $query->where('campaign_id', $campaignId);
    }

    public function scopeForAgent($query, int $agentId)
    {
        return $query->where('agent_id', $agentId);
    }

    public function formattedDuration(): string
    {
        $s = (int) $this->duration;
        return sprintf('%02d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60);
    }
}
