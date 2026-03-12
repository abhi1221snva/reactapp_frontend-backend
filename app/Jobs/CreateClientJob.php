<?php

namespace App\Jobs;

use App\Model\Master\Client;
use App\Model\Master\ClientServers;
use App\Model\MysqlConnections;
use App\Model\User;
use App\Services\ClientService;
use App\Services\TenantProvisionService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * CreateClientJob
 *
 * Async job that fully provisions a new tenant client through stages:
 *
 *   Stage 1 (RECORD_SAVED)          — Client record created
 *   Stage 2 (ADMIN_ASSIGNED)        — Requesting admin given permission
 *   Stage 3 (SAVE_CONNECTION)       — mysql_connection record created
 *   Stage 4 (MIGRATE_SEED)          — Database created, migrations run, legacy seeders run
 *   Stage 5 (ASSIGN_ASTERISK_SERVER)— Asterisk server assignments
 *   Stage 6 (FULLY_PROVISIONED)     — Tenant storage, default settings, default admin user
 */
class CreateClientJob extends Job
{
    public $tries = 5;

    private Client $client;
    private array $asteriskServers;

    public function __construct(Client $client, array $asteriskServers)
    {
        $this->client          = $client;
        $this->asteriskServers = $asteriskServers;
    }

    public function handle(): void
    {
        Log::info("CreateClientJob.handle", [
            'client'          => $this->client->id,
            'asteriskServers' => $this->asteriskServers,
        ]);

        // ── Stage 3: Save connection record ───────────────────────────────────
        if ($this->client->stage < Client::SAVE_CONNECTION) {
            $dbConnection              = new MysqlConnections();
            $dbConnection->client_id   = $this->client->id;
            $dbConnection->db_name     = 'client_' . $this->client->id;
            $dbConnection->db_user     = env('NEW_CLIENT_USERNAME');
            $dbConnection->password    = env('NEW_CLIENT_PASSWORD');
            $dbConnection->ip          = env('NEW_CLIENT_HOST');
            $dbConnection->saveOrFail();

            $this->client->stage = Client::SAVE_CONNECTION;
            $this->client->saveOrFail();
        }

        // ── Stage 4: Create DB, run migrations, run legacy seeders ────────────
        if ($this->client->stage < Client::MIGRATE_SEED) {
            Artisan::call('migrate:all');

            // Legacy seeders (run on all clients — idempotent)
            Artisan::call('db:seed', ['--class' => 'NotificationSeeder']);
            Artisan::call('db:seed', ['--class' => 'CrmLabels']);
            Artisan::call('db:seed', ['--class' => 'LabelTableSeeder']);
            Artisan::call('db:seed', ['--class' => 'DispositionTableSeeder']);
            Artisan::call('db:seed', ['--class' => 'DefaultApiTableSeeder']);
            Artisan::call('db:seed', ['--class' => 'CampaignTypesSeeder']);

            $this->client->stage = Client::MIGRATE_SEED;
            $this->client->saveOrFail();
        }

        // ── Stage 5: Assign Asterisk servers ──────────────────────────────────
        if ($this->client->stage < Client::ASSIGN_ASTERISK_SERVER) {
            foreach ($this->asteriskServers as $server) {
                $clientServers             = new ClientServers();
                $clientServers->client_id  = $this->client->id;
                $clientServers->ip_address = $server;
                $clientServers->saveOrFail();
            }
            $this->client->stage = Client::ASSIGN_ASTERISK_SERVER;
            $this->client->saveOrFail();
        }

        // ── Stage 6: Tenant storage + company settings + admin user ───────────
        if ($this->client->stage < Client::FULLY_PROVISIONED) {
            try {
                $provisionSvc = new TenantProvisionService();

                // 6a. Create storage folder tree
                $provisionSvc->provisionStorage($this->client->id);

                // 6b. Insert default company settings into crm_system_setting
                $provisionSvc->provisionDefaultSettings($this->client->id, $this->client->company_name);

                // 6c. Create default admin user
                $provisionSvc->provisionDefaultAdminUser($this->client->id, $this->client->company_name);

                // 6d. Seed default CRM data (labels, statuses, dispositions)
                $provisionSvc->provisionDefaultCrmData($this->client->id);

                $this->client->stage = Client::FULLY_PROVISIONED;
                $this->client->saveOrFail();

                Log::info("CreateClientJob: fully provisioned client_{$this->client->id}");
            } catch (\Throwable $e) {
                Log::error("CreateClientJob: provision step failed for client_{$this->client->id}", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                // Don't re-throw — partial provisioning is recoverable via artisan tenant:provision
            }
        }

        // ── Grant super-admins permission on this client ──────────────────────
        foreach (User::getAllSuperAdmins() as $adminId) {
            $user = User::find($adminId);
            if (!empty($user) && $user->is_deleted == 0) {
                $user->addPermission($this->client->id, 6);
            }
        }

        ClientService::clearCache();
    }
}
