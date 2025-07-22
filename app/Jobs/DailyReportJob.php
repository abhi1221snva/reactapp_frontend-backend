<?php

namespace App\Jobs;

use App\Model\Client\SystemNotification;


class DailyReportJob extends Job
{
    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    //private $campaignId;

    private $clientId;

    //private $data;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(int $clientId)
    {
        $this->clientId = $clientId;
       // $this->campaignId = $campaignId;
        //$this->data = $data;
    }

    /**
     * Execute the job.
     *
     */
    public function handle()
    {
        #prepare sender list
        $subscription = SystemNotification::on("mysql_" . $this->clientId)->findOrFail("daily_report");
        if (empty($subscription->subscribers) or !$subscription->active) {
            return;
        }
    }

}
