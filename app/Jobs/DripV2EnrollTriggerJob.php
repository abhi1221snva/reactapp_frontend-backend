<?php

namespace App\Jobs;

use App\Services\DripEnrollmentService;
use Illuminate\Support\Facades\Log;

class DripV2EnrollTriggerJob extends Job
{
    public $tries   = 2;
    public $timeout = 30;

    private int    $clientId;
    private int    $leadId;
    private string $triggerType;
    private array  $triggerData;

    public function __construct(int $clientId, int $leadId, string $triggerType, array $triggerData = [])
    {
        $this->clientId    = $clientId;
        $this->leadId      = $leadId;
        $this->triggerType = $triggerType;
        $this->triggerData = $triggerData;
    }

    public function handle(): void
    {
        try {
            DripEnrollmentService::processAutoTriggers(
                (string) $this->clientId,
                $this->leadId,
                $this->triggerType,
                $this->triggerData
            );
        } catch (\Throwable $e) {
            Log::error("DripV2EnrollTriggerJob failed", [
                'client_id'    => $this->clientId,
                'lead_id'      => $this->leadId,
                'trigger_type' => $this->triggerType,
                'error'        => $e->getMessage(),
            ]);
        }
    }
}
