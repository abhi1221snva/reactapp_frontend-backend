<?php

namespace App\Services\Rvm;

use App\Model\Master\Rvm\Campaign;
use App\Services\Rvm\Support\Ulid;
use Illuminate\Support\Carbon;

/**
 * Campaign lifecycle skeleton.
 *
 * Phase 0 only implements the CRUD surface; start/pause/resume and
 * bulk-enqueue logic land in Phase 1 alongside EnqueueCampaignBatchJob.
 */
class RvmCampaignService
{
    public function create(int $clientId, array $attrs, ?int $userId = null, ?int $apiKeyId = null): Campaign
    {
        $campaign = new Campaign();
        $campaign->id = Ulid::generate();
        $campaign->client_id = $clientId;
        $campaign->created_by_user_id = $userId;
        $campaign->created_by_api_key_id = $apiKeyId;
        $campaign->name = $attrs['name'];
        $campaign->description = $attrs['description'] ?? null;
        $campaign->status = 'draft';
        $campaign->caller_id = $attrs['caller_id'];
        $campaign->voice_template_id = (int) $attrs['voice_template_id'];
        $campaign->provider_strategy = $attrs['provider_strategy'] ?? 'auto';
        $campaign->pinned_provider = $attrs['pinned_provider'] ?? null;
        $campaign->quiet_start = $attrs['quiet_start'] ?? '09:00:00';
        $campaign->quiet_end = $attrs['quiet_end'] ?? '20:00:00';
        $campaign->respect_dnc = (bool) ($attrs['respect_dnc'] ?? true);
        $campaign->max_per_minute = (int) ($attrs['max_per_minute'] ?? 100);
        $campaign->scheduled_start = isset($attrs['scheduled_start']) ? Carbon::parse($attrs['scheduled_start']) : null;
        $campaign->save();
        return $campaign;
    }

    public function pause(int $clientId, string $campaignId): Campaign
    {
        $campaign = Campaign::on('master')
            ->where('client_id', $clientId)
            ->where('id', $campaignId)
            ->firstOrFail();
        $campaign->status = 'paused';
        $campaign->save();
        return $campaign;
    }

    public function resume(int $clientId, string $campaignId): Campaign
    {
        $campaign = Campaign::on('master')
            ->where('client_id', $clientId)
            ->where('id', $campaignId)
            ->firstOrFail();
        $campaign->status = 'running';
        $campaign->save();
        return $campaign;
    }

    // TODO Phase 1:
    //   start()        — enqueue EnqueueCampaignBatchJob per 500 leads
    //   attachLeads()  — CSV + JSON + existing list_id
    //   stats()        — read Redis counters + fall back to stats_cache
}
