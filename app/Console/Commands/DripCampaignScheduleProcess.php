<?php

namespace App\Console\Commands;

use App\Jobs\DripCampaignSchedules;
use Illuminate\Console\Command;


class DripCampaignScheduleProcess extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:dc:schedule-process {--clientId=}';

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
            $this->info("DripCampaignSchedules($clientId)");
            dispatch(new DripCampaignSchedules($clientId))->onConnection("database");
        } 
        else {
            $clients = \App\Model\Master\Client::all();
            foreach ( $clients as $client ) {
                $this->info("DripCampaignSchedules({$client->id})");
                dispatch(new DripCampaignSchedules($client->id))->onConnection("database");
            }
        }
    }
}
