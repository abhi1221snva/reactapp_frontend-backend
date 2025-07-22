<?php

namespace App\Jobs;

use App\Model\Master\Client;
use App\Model\Master\ClientServers;
use App\Model\MysqlConnections;
use App\Model\User;
use App\Services\ClientService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class CreateClientJob extends Job
{
    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 5;

    private $client;

    private $asteriskServers = [];

    /**
     * Create a new job instance.
     * @param Client $client
     * @param array $asteriskServers
     * @return void
     */
    public function __construct(Client $client, array $asteriskServers)
    {
        $this->client = $client;
        $this->asteriskServers = $asteriskServers;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        Log::info("CreateClientJob.handle", [
            "client" => $this->client,
            "asteriskServers" => $this->asteriskServers
        ]);

        #make entry in mysql_connection table for this user
        if ($this->client->stage < Client::SAVE_CONNECTION) {
            $dbConnection = new MysqlConnections();
            $dbConnection->client_id = $this->client->id;
            $dbConnection->db_name = "client_" . $this->client->id;
            $dbConnection->db_user = env("NEW_CLIENT_USERNAME");
            $dbConnection->password = env("NEW_CLIENT_PASSWORD");
            $dbConnection->ip = env("NEW_CLIENT_HOST");
            $dbConnection->saveOrFail();

            $this->client->stage = Client::SAVE_CONNECTION;
            $this->client->saveOrFail();
        }

        #Create db, grant permissions and Import schema
        if ($this->client->stage < Client::MIGRATE_SEED) {
            Artisan::call('migrate:all');
            Artisan::call('db:seed --class=NotificationSeeder');
            Artisan::call('db:seed --class=CrmLabels');
            Artisan::call('db:seed --class=LabelTableSeeder');
            Artisan::call('db:seed --class=DispositionTableSeeder');
            Artisan::call('db:seed --class=DefaultApiTableSeeder');
            Artisan::call('db:seed --class=CampaignTypesSeeder');
            

            $this->client->stage = Client::MIGRATE_SEED;
            $this->client->saveOrFail();
        }

        if ($this->client->stage < Client::ASSIGN_ASTERISK_SERVER) {
            foreach ( $this->asteriskServers as $server) {
                $clientServers = new ClientServers();
                $clientServers->client_id = $this->client->id;
                $clientServers->ip_address = $server;
                $clientServers->saveOrFail();
            }
            $this->client->stage = Client::ASSIGN_ASTERISK_SERVER;
            $this->client->saveOrFail();
        }

        #Assign super admin role to all existing super admins
        foreach (User::getAllSuperAdmins() as $adminId) {
            $user = User::find($adminId);
            if (!empty($user) && $user->is_deleted == 0)
                $user->addPermission($this->client->id, 6); //change role by 5 to 6 for system administrator
        }

        ClientService::clearCache();
    }
}
