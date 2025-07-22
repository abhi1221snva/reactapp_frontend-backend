<?php

namespace App\Console\Commands;

use App\Model\Master\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ArchiveCdr extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:archive:cdr';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This command move records from client_x.cdr to client_x.cdr_archive';

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
        $before = date('Y-m-d 00:00:00', strtotime("2 days"));

        $this->info("Running app:archive:cdr for < $before");
        $clients = Client::all();
        foreach ( $clients as $client ) {
            try {
                $deleted = false;
                $inserted = DB::connection("mysql_" . $client->id)->statement("INSERT INTO cdr_archive SELECT * FROM cdr WHERE start_time < '$before'");
                if ($inserted) {
                    $this->info("Archived " . $client->id. " INSERTED");
                    $deleted = DB::connection("mysql_" . $client->id)->statement("DELETE FROM cdr WHERE start_time < '$before';");
                    if ($deleted) $this->info("Archived " . $client->id. " DELETED");
                }
            } catch (\Exception $exception) {
                $this->error("Failed to app:archive:cdr for " . $client->id .
                    "\nMessage: " . $exception->getMessage() .
                    "\nLine: " . $exception->getLine() .
                    "\nFile: " . $exception->getFile()
                );
            }
        }
        $this->info("Execution  completed for app:archive:cdr");
    }
}
