<?php

namespace App\Jobs;

use App\Model\PlivoAccount;
use App\Model\Client\PlivoUsageLog;
use App\Services\PlivoService;
use Illuminate\Support\Facades\Log;

/**
 * Dispatched daily (e.g. via artisan schedule) to sync Plivo usage stats.
 */
class SyncPlivoUsageJob extends Job
{
    private int $clientId;

    public function __construct(int $clientId)
    {
        $this->clientId = $clientId;
    }

    public function handle(): void
    {
        $conn      = "mysql_{$this->clientId}";
        $dateFrom  = \Carbon\Carbon::now()->startOfMonth()->toDateString();
        $dateTill  = \Carbon\Carbon::now()->toDateString();

        try {
            $service = PlivoService::forClient($this->clientId);

            $callRecords = $service->getCallCdr($dateFrom, $dateTill, 500);
            $msgRecords  = $service->getMessageRecords($dateFrom, $dateTill, 500);

            $totalCallDuration = collect($callRecords)->sum(fn($c) => (int)($c['duration'] ?? 0));
            $callAmount        = collect($callRecords)->sum(fn($c) => (float)($c['total_amount'] ?? 0));
            $msgAmount         = collect($msgRecords)->sum(fn($m) => (float)($m['total_amount'] ?? 0));

            if (!empty($callRecords)) {
                PlivoUsageLog::on($conn)->updateOrCreate(
                    ['resource' => 'calls', 'date_from' => $dateFrom, 'date_till' => $dateTill],
                    [
                        'total_count'    => count($callRecords),
                        'total_amount'   => round($callAmount, 6),
                        'total_duration' => round($totalCallDuration / 60, 2),
                        'duration_unit'  => 'min',
                        'synced_at'      => \Carbon\Carbon::now(),
                    ]
                );
            }

            if (!empty($msgRecords)) {
                PlivoUsageLog::on($conn)->updateOrCreate(
                    ['resource' => 'messages', 'date_from' => $dateFrom, 'date_till' => $dateTill],
                    [
                        'total_count'    => count($msgRecords),
                        'total_amount'   => round($msgAmount, 6),
                        'total_duration' => 0,
                        'synced_at'      => \Carbon\Carbon::now(),
                    ]
                );
            }

            Log::info('SyncPlivoUsageJob complete', [
                'client'   => $this->clientId,
                'calls'    => count($callRecords),
                'messages' => count($msgRecords),
            ]);

        } catch (\Exception $e) {
            Log::error('SyncPlivoUsageJob failed', ['client' => $this->clientId, 'err' => $e->getMessage()]);
        }
    }
}
