<?php

namespace App\Jobs;

use App\Services\DripExecutionService;
use Illuminate\Support\Facades\Log;

class DripV2ProcessJob extends Job
{
    public $tries    = 3;
    public $timeout  = 120;
    public $backoff  = 30;

    private int $clientId;

    public function __construct(int $clientId)
    {
        $this->clientId = $clientId;
    }

    public function handle(): void
    {
        try {
            $stats = DripExecutionService::processScheduledSends((string) $this->clientId);

            if ($stats['processed'] > 0) {
                Log::info("DripV2Process: client {$this->clientId}", $stats);
            }
        } catch (\Throwable $e) {
            Log::error("DripV2ProcessJob failed", [
                'client_id' => $this->clientId,
                'error'     => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
