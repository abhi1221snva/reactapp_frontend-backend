<?php

use App\Model\Master\Client;
use App\Model\MysqlConnections;
use Illuminate\Database\Seeder;

class ClientMySqlConnectionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        //$this->call('UsersTableSeeder');

        $clients = Client::all();
        foreach ( $clients as $client ) {
            $connection = MysqlConnections::where("client_id", "=", $client->id)->get()->first();
            if (empty($connection)) {
                $dbConnection = new MysqlConnections();
                $dbConnection->client_id = $client->id;
                $dbConnection->db_name = "client_" . $client->id;
                $dbConnection->db_user = env("NEW_CLIENT_USERNAME");
                $dbConnection->password = env("NEW_CLIENT_PASSWORD");
                $dbConnection->ip = env("NEW_CLIENT_HOST");
                $dbConnection->saveOrFail();
            }
        }
    }
}
