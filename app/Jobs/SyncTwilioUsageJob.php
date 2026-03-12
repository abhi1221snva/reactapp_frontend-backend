<?php

namespace App\Jobs;

use App\Model\TwilioAccount;
use App\Model\Client\TwilioUsageLog;
use App\Services\TwilioService;
use Illuminate\Support\Facades\Log;

/**
 * Dispatched daily (e.g. via artisan schedule) to pull fresh usage from Twilio.
 */
class SyncTwilioUsageJob extends Job
{
    private int $clientId;

    public function __construct(int $clientId)
    {
        $this->clientId = $clientId;
    }

    public function handle(): void
    {
        $conn      = "mysql_{$this->clientId}";
        $startDate = \Carbon\Carbon::now()->startOfMonth()->toDateString();
        $endDate   = \Carbon\Carbon::now()->toDateString();

        try {
            $service = TwilioService::forClient($this->clientId);
            $records = $service->getUsage($startDate, $endDate);

            foreach ($records as $r) {
                TwilioUsageLog::on($conn)->updateOrCreate(
                    [
                        'category'   => $r['category'],
                        'start_date' => $r['start_date'],
                        'end_date'   => $r['end_date'],
                    ],
                    [
                        'description' => $r['description'] ?? null,
                        'count'       => $r['count']       ?? 0,
                        'usage'       => $r['usage']       ?? 0,
                        'usage_unit'  => $r['count_unit']  ?? null,
                        'price'       => $r['price']       ?? 0,
                        'price_unit'  => $r['price_unit']  ?? 'USD',
                        'synced_at'   => \Carbon\Carbon::now(),
                    ]
                );
            }

            Log::info('SyncTwilioUsageJob complete', ['client' => $this->clientId, 'records' => count($records)]);

        } catch (\Exception $e) {
            Log::error('SyncTwilioUsageJob failed', ['client' => $this->clientId, 'err' => $e->getMessage()]);
        }
    }
}
