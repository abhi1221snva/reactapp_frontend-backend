<?php

namespace App\Console\Commands;

use App\Model\Master\Client;
use App\Model\MysqlConnections;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MigrateAllCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'migrate:all';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Runs migration for all the databases';

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

        try {
            $this->info("Running migration for master database");
            Log::info("MigrateAllCommand:handle", ["Running migration for master database"]);
            $this->call('migrate', array('--database' => 'master', '--path' => 'database/migrations/master', '--force' => null, '--step' => null));
        } catch (\Throwable $exception) {
            $this->error("Failed to run migration for master\n".$exception->getMessage());
        }

        $this->info("Fetching clients from table master.clients");
        Log::info("MigrateAllCommand:handle", ["Running migration for master database"]);
        $clients = Client::all();
        foreach ( $clients as $client ) {
            $connection = MysqlConnections::where("client_id", "=", $client->id)->get()->first();

            try {
                $this->info("Searching database for $client->id");
                DB::connection("mysql_" . $client->id)->getPdo();
                $this->info("Detected database: " . DB::connection()->getDatabaseName());
                Log::info("MigrateAllCommand:handle", ["Detected database: " . DB::connection()->getDatabaseName()]);
            } catch (\Exception $exception) {
                $this->warn("Database " . $connection->db_name . " does not exists. Creating database.");
                try {
                    $stmt = "CREATE DATABASE IF NOT EXISTS " . $connection->db_name;
                    $this->info("Executing: $stmt");
                    Log::info("MigrateAllCommand:handle", ["Executing: $stmt"]);
                    DB::connection('master')->statement($stmt);

                    // Try granting to wildcard user first, then specific host
                    try {
                        $stmt = "GRANT ALL PRIVILEGES ON " . $connection->db_name . ".* TO '" . $connection->db_user . "'@'".$connection->ip."'";
                        $this->info("Executing: $stmt");
                        Log::info("MigrateAllCommand:handle", ["Executing: $stmt"]);
                        DB::connection('master')->statement($stmt);
                    } catch (\Throwable $grantEx) {
                        $stmt = "GRANT ALL PRIVILEGES ON " . $connection->db_name . ".* TO '" . $connection->db_user . "'@'%'";
                        $this->info("Fallback: $stmt");
                        Log::info("MigrateAllCommand:handle", ["Fallback grant: $stmt"]);
                        DB::connection('master')->statement($stmt);
                    }

                    $stmt = "FLUSH PRIVILEGES";
                    $this->info("Executing: $stmt");
                    Log::info("MigrateAllCommand:handle", ["Executing: $stmt"]);
                    DB::connection('master')->statement($stmt);
                } catch (\Throwable $throwable) {
                    Log::error("Error creating database", buildContext($throwable));
                    $this->error($throwable->getMessage());
                    // Continue to next client instead of stopping all migrations
                    continue;
                }
            }

            try {
                $this->info("Running migration for " . $connection->db_name." on connection ".$client->id);
                Log::info("MigrateAllCommand:handle", ["Running migration for " . $connection->db_name." on connection ".$client->id]);
                $this->call('migrate', ['--database' => "mysql_" . $client->id, '--path' => 'database/migrations/client', '--force' => null, '--step' => null]);
                $this->info("Finished migration for " . $connection->db_name);
                Log::info("MigrateAllCommand:handle", ["Finished migration for " . $connection->db_name]);
            } catch (\Throwable $exception) {
                $this->error("Failed to run migration for ". $connection->db_name .
                    "\nMessage: ".$exception->getMessage().
                    "\nLine: ".$exception->getLine().
                    "\nFile: ".$exception->getFile()
                );
            }
        }
        $this->info("Completed run for all clients.");

    }
}
