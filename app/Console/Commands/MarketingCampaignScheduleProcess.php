<?php

namespace App\Console\Commands;

use App\Jobs\MarketingCampaignSchedules;
use Illuminate\Console\Command;

class MarketingCampaignScheduleProcess extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:mc:schedule-process {--clientId=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send Marketing Campaign Email and Sms for all clients';

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
            $this->info("MarketingCampaignSchedules($clientId)");
            dispatch(new MarketingCampaignSchedules($clientId))->onConnection("mc_schedule_job");
        } else {
            $clients = \App\Model\Master\Client::all();
            foreach ( $clients as $client ) {
                $this->info("MarketingCampaignSchedules({$client->id})");
                dispatch(new MarketingCampaignSchedules($client->id))->onConnection("mc_schedule_job");
            }
        }
    }
}
