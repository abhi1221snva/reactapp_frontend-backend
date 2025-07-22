<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\PredictiveCallJob;


class PredictiveCallCron extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:send:predictive-call-dialer  {--clientId=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Predictive Call When Campaign used as a Predictive Call';

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
            $this->info("PredictiveCallJob($clientId)");
            dispatch(new PredictiveCallJob($clientId))->onConnection("database");
        } else {
            $clients = \App\Model\Master\Client::where('is_deleted',0)->get()->all();
            foreach ( $clients as $client ) {
                $this->info("PredictiveCallJob({$client->id})");
                dispatch(new PredictiveCallJob($client->id))->onConnection("database");
            }
        }
    }
}
