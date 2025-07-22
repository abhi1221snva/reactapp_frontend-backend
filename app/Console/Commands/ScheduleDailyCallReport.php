<?php

namespace App\Console\Commands;

use App\Jobs\DailyCallReportJob;
use Illuminate\Console\Command;

class ScheduleDailyCallReport extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:send:daily-call-report  {--clientId=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send daily call report for all clients';

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
            $this->info("DailyCallReportJob($clientId)");
            dispatch(new DailyCallReportJob($clientId))->onConnection("database");
        } else {
            $clients = \App\Model\Master\Client::all();
            foreach ( $clients as $client ) {
                $this->info("DailyCallReportJob({$client->id})");
                dispatch(new DailyCallReportJob($client->id))->onConnection("database");
            }
        }
    }
}
