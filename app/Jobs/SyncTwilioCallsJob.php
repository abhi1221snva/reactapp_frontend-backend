<?php

namespace App\Jobs;

use App\Model\Client\TwilioCall;
use App\Services\TwilioService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * SyncTwilioCallsJob
 *
 * Fetches the most recent calls from the Twilio API and upserts them into
 * the local twilio_calls table. This job is intended for on-demand / manual
 * sync requests triggered via POST /twilio/calls/sync.
 *
 * Under normal operation calls are kept current by TwilioIngestCallJob which
 * is dispatched automatically from the call-status webhook.
 */
class SyncTwilioCallsJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $backoff  = 60;

    /** @param int $clientId  Tenant client ID */
    public function __construct(private readonly int $clientId) {}

    public function handle(): void
    {
        $conn = 'mysql_' . $this->clientId;

        try {
            $service = TwilioService::forClient($this->clientId);
            $calls   = $service->listCalls(100);
        } catch (\Exception $e) {
            Log::error('[SyncTwilioCallsJob] Failed to fetch calls from Twilio API', [
                'client_id' => $this->clientId,
                'error'     => $e->getMessage(),
            ]);
            throw $e;
        }

        $upserted = 0;

        foreach ($calls as $c) {
            try {
                TwilioCall::on($conn)->updateOrCreate(
                    ['call_sid' => $c['call_sid']],
                    [
                        'from_number' => $c['from_number'],
                        'to_number'   => $c['to_number'],
                        'direction'   => $c['direction'],
                        'status'      => $c['status'],
                        'duration'    => $c['duration'],
                        'price'       => $c['price'],
                        'price_unit'  => $c['price_unit'],
                        'started_at'  => $c['started_at'],
                        'ended_at'    => $c['ended_at'],
                        'updated_at'  => \Carbon\Carbon::now(),
                    ]
                );
                $upserted++;
            } catch (\Exception $e) {
                Log::warning('[SyncTwilioCallsJob] Failed to upsert call', [
                    'client_id' => $this->clientId,
                    'call_sid'  => $c['call_sid'] ?? 'unknown',
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        Log::info('[SyncTwilioCallsJob] Sync complete', [
            'client_id' => $this->clientId,
            'upserted'  => $upserted,
            'fetched'   => count($calls),
        ]);
    }
}
