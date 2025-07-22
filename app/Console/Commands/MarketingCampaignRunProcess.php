<?php

namespace App\Console\Commands;

use App\Jobs\MarketingCampaignRunJob;
use Illuminate\Console\Command;


class MarketingCampaignRunProcess extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:mc:run-process {--clientId=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run Marketing Campaign Email and Sms for all clients';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $clientId = $this->option('clientId');
        if ($clientId) {
            $this->info("MarketingCampaignRunningProcess($clientId)");
            dispatch(new MarketingCampaignRunJob($clientId))->onConnection("mc_run_job");
        } else {
            $clients = \App\Model\Master\Client::all();
            foreach ( $clients as $client ) {
                $this->info("MarketingCampaignRunningProcess({$client->id})");
                dispatch(new MarketingCampaignRunJob($client->id))->onConnection("mc_run_job");
            }
        }
    }
}
