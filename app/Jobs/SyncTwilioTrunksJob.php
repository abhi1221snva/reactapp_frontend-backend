<?php

namespace App\Jobs;

use App\Model\Client\TwilioTrunk;
use App\Services\TwilioService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * SyncTwilioTrunksJob
 *
 * Fetches the current SIP trunk list from the Twilio API and upserts active
 * trunks into the local twilio_trunks table. Intended for on-demand / manual
 * sync triggered via POST /twilio/trunks/sync.
 */
class SyncTwilioTrunksJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries  = 3;
    public int $backoff = 60;

    /** @param int $clientId  Tenant client ID */
    public function __construct(private readonly int $clientId) {}

    public function handle(): void
    {
        $conn = 'mysql_' . $this->clientId;

        try {
            $service = TwilioService::forClient($this->clientId);
            $trunks  = $service->listSipTrunks();
        } catch (\Exception $e) {
            Log::error('[SyncTwilioTrunksJob] Failed to fetch trunks from Twilio API', [
                'client_id' => $this->clientId,
                'error'     => $e->getMessage(),
            ]);
            throw $e;
        }

        $upserted = 0;

        foreach ($trunks as $t) {
            try {
                TwilioTrunk::on($conn)->updateOrCreate(
                    ['sid' => $t['sid']],
                    [
                        'friendly_name' => $t['friendly_name'],
                        'domain_name'   => $t['domain_name'] ?? null,
                        'status'        => 'active',
                        'updated_at'    => \Carbon\Carbon::now(),
                    ]
                );
                $upserted++;
            } catch (\Exception $e) {
                Log::warning('[SyncTwilioTrunksJob] Failed to upsert trunk', [
                    'client_id' => $this->clientId,
                    'sid'       => $t['sid'] ?? 'unknown',
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        Log::info('[SyncTwilioTrunksJob] Sync complete', [
            'client_id' => $this->clientId,
            'upserted'  => $upserted,
            'fetched'   => count($trunks),
        ]);
    }
}
