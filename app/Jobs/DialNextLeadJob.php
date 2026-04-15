<?php

namespace App\Jobs;

use App\Services\AsteriskAmiService;
use App\Services\CampaignDialerService;
use Illuminate\Support\Facades\Log;

/**
 * Dispatched after each call ends (or after a start signal) to dial the next
 * lead in the campaign queue. Runs on the "campaign-dialer" queue.
 *
 * Dispatch (Lumen — no Dispatchable trait):
 *   dispatch(
 *       (new DialNextLeadJob($campaignId, $clientId, $dbConnection))
 *           ->onQueue('campaign-dialer')
 *           ->delay(now()->addSeconds(2))
 *   );
 */
class DialNextLeadJob extends Job
{
    public int $tries   = 2;
    public int $timeout = 30;

    public function __construct(
        public int    $campaignId,
        public int    $clientId,
        public string $dbConnection
    ) {}

    public function handle(AsteriskAmiService $ami): void
    {
        $service = new CampaignDialerService($ami);

        $result = $service->dialNextLead(
            $this->campaignId,
            $this->clientId,
            $this->dbConnection
        );

        if ($result === null) {
            Log::info("DialNextLeadJob: nothing to dial for campaign {$this->campaignId}");
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error("DialNextLeadJob failed for campaign {$this->campaignId}: {$e->getMessage()}");
    }
}
