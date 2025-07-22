<?php

namespace App\Console\Commands;

use App\Exceptions\RenderableException;
use App\Model\Master\Client;
use App\Model\MysqlConnections;
use Illuminate\Console\Command;

class CreateDatabaseConfig extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:database:config';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Creates config/database.php file from clients table';

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
        $database = [
            "migrations" => "migrations",
            "default" => "master",
            'redis' => [
                'client' => 'predis',
                'default' => [
                    'host' => env('REDIS_HOST', '127.0.0.1'),
                    'password' => env('REDIS_PASSWORD', null),
                    'port' => env('REDIS_PORT', 6379),
                    'database' => 0,
                ],
            ],
            "connections" => [
                'master' => [
                    'driver' => 'mysql',
                    'host' => env('DB_HOST'),
                    'database' => env('DB_DATABASE'),
                    'username' => env('DB_USERNAME'),
                    'password' => env('DB_PASSWORD'),
                    'charset' => 'utf8mb4',
                    'collation' => 'utf8mb4_unicode_ci',
                    'strict' => false,
                ]
            ]
        ];

        config([
            "database.default" => "master",
            "database.connections.master" => [
                'driver' => 'mysql',
                'host' => env('DB_HOST'),
                'database' => env('DB_DATABASE'),
                'username' => env('DB_USERNAME'),
                'password' => env('DB_PASSWORD'),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'strict' => false,
            ]
        ]);

        $this->info("Building database connections from table master.clients and master.mysql_connection");
        $clients = Client::all();
        foreach ( $clients as $client ) {
            $connection = MysqlConnections::where("client_id", "=", $client->id)->get()->first();
            if (empty($connection)) {
                throw new RenderableException("master.mysql_connection missing entry for client_id " . $client->id);
            }

            $database["connections"]["mysql_" . $client->id] = [
                'driver' => 'mysql',
                'host' => $connection->ip,
                'database' => $connection->db_name,
                'username' => $connection->db_user,
                'password' => $connection->password,
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'strict' => false,
            ];

            config(["database.connections.mysql_" . $client->id => [
                'driver' => 'mysql',
                'host' => $connection->ip,
                'database' => $connection->db_name,
                'username' => $connection->db_user,
                'password' => $connection->password,
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'strict' => false,
            ]]);
        }

        $file = app()->basePath() . DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR . "database.php";
        $fp = fopen($file, 'w');
        fwrite($fp, "<?php \n\r return " . var_export($database, true) . ";\n\r");
        fclose($fp);
        $this->info("Updated $file file for clients " . implode(", ", array_keys($database["connections"])));
    }
}
