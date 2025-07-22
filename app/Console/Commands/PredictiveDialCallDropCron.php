<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\PredictiveDialCallDropJob;


class PredictiveDialCallDropCron extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:send:predictive-dial-call-drop  {--clientId=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'check drop call for predictive dialer';

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
            $this->info("PredictiveDialCallDropJob($clientId)");
            dispatch(new PredictiveDialCallDropJob($clientId))->onConnection("database");
        } else {
            $clients = \App\Model\Master\Client::where('is_deleted',0)->get()->all();
            foreach ( $clients as $client ) {
                $this->info("PredictiveDialCallDropJob({$client->id})");
                dispatch(new PredictiveDialCallDropJob($client->id))->onConnection("database");
            }
        }
    }
}
