<?php

namespace App\Jobs;

use App\Model\Cron;
use App\Services\ExecutionProfiler;
use Illuminate\Support\Facades\Log;

class LoadHopperJob extends Job
{
    private $clientId;
    private $campaignId;

    /**
     * Create a new LoadHopperJob instance.
     * @param int $clientId
     * @param int $campaignId
     */
    public function __construct(int $clientId, int $campaignId)
    {
        $this->clientId = $clientId;
        $this->campaignId = $campaignId;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $cron = new Cron();
        $profiler = new ExecutionProfiler();
        $result = $cron->addLeadTemp($this->clientId, $this->campaignId);
        $profile = $profiler->calculate();
        Log::info("Loading hopper", [
            "clientId" => $this->clientId,
            "campaignId" => $this->campaignId,
            "result" => $result,
            "execution_time" => $profile
        ]);
    }
}
