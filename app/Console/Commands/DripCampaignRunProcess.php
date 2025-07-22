<?php

namespace App\Console\Commands;

use App\Jobs\DripCampaignRunJob;
use Illuminate\Console\Command;


class DripCampaignRunProcess extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:dc:run-process {--clientId=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run Drip Campaign Email for all clients';

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
        $clientId = 3;//$this->option('clientId');
        if ($clientId) {
            $this->info("DripCampaignRunningProcess($clientId)");
            dispatch(new DripCampaignRunJob($clientId))->onConnection("database");
        }
        else {
            $clients = \App\Model\Master\Client::all();
            foreach ( $clients as $client ) {
                $this->info("DripCampaignRunningProcess({$client->id})");
                dispatch(new DripCampaignRunJob($client->id))->onConnection("database");
            }
        }
    }
}
