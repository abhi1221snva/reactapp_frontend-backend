<?php

namespace App\Jobs;

use App\Services\ReservedPoolService;
use Illuminate\Support\Facades\Log;

/**
 * ReplenishPoolJob
 *
 * Ensures the reserved client pool stays above the minimum threshold.
 * Creates new reserved slots one at a time until the pool is full.
 *
 * Dispatched on the 'clients' connection (heavy DB work).
 * Also scheduled to run every 30 minutes via Kernel.
 */
class ReplenishPoolJob extends Job
{
    public $tries   = 2;
    public $timeout = 600; // 10 minutes — may create multiple slots

    public function handle(): void
    {
        $poolService = new ReservedPoolService();

        $currentSize = $poolService->getPoolSize();
        $needed      = max(0, ReservedPoolService::MIN_POOL_SIZE - $currentSize);

        if ($needed === 0) {
            Log::info('ReplenishPoolJob: pool is full', ['current' => $currentSize]);
            return;
        }

        Log::info('ReplenishPoolJob: replenishing pool', [
            'current' => $currentSize,
            'needed'  => $needed,
        ]);

        $created = 0;
        for ($i = 0; $i < $needed; $i++) {
            try {
                $poolService->createReservedSlot();
                $created++;
            } catch (\Throwable $e) {
                Log::error('ReplenishPoolJob: failed to create slot', [
                    'iteration' => $i + 1,
                    'error'     => $e->getMessage(),
                ]);
                // Continue trying remaining slots
            }
        }

        Log::info('ReplenishPoolJob: done', [
            'created'    => $created,
            'pool_total' => $poolService->getPoolSize(),
        ]);
    }
}
