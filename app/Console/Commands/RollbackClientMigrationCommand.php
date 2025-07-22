<?php

namespace App\Console\Commands;

use App\Model\Master\Client;
use App\Model\MysqlConnections;
use Illuminate\Console\Command;

class RollbackClientMigrationCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'migrate:rollback:clients';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Runs migration:rollback for all client databases';

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
        $this->call('make:database:config');

        $this->info("Fetching clients from table master.clients");
        $clients = Client::all();
        foreach ( $clients as $client ) {
            $connection = MysqlConnections::where("client_id", "=", $client->id)->get()->first();

            try {
                $this->info("Running migrate:rollback for " . $connection->db_name);
                $this->call('migrate:rollback', ['--database' => "mysql_" . $client->id, '--path' => 'database/migrations/client']);
                $this->info("Finished migration for " . $connection->db_name);
            } catch (\Exception $exception) {
                $this->error("Failed to run migrate:rollback for " . $connection->db_name .
                    "\nMessage: " . $exception->getMessage() .
                    "\nLine: " . $exception->getLine() .
                    "\nFile: " . $exception->getFile()
                );
            }
        }
        $this->info("Completed run for all clients.");

    }
}
