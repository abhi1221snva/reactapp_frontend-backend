<?php

namespace App\Console\Commands;

use App\Model\Client\LeadTemp;
use App\Model\Master\Client;
use Illuminate\Console\Command;

class TruncateLeadTemp extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:hopper:clean';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This will delete all records from lead_temp';

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
        $this->info("Running script for delete record from lead_temp table");
        $clients = Client::all();
        foreach ( $clients as $client ) {
            try {
                LeadTemp::on('mysql_' . $client->id)->truncate();
                $this->info("Record deleted from lead_temp table and db mysql_" . $client->id);
            } catch (\Exception $exception) {
                $this->error("Failed to run app:hopper:clean" .
                    "\nMessage: " . $exception->getMessage() .
                    "\nLine: " . $exception->getLine() .
                    "\nFile: " . $exception->getFile()
                );
            }
        }
        $this->info("Execution  completed for delete data from lead_temp table");
    }
}
