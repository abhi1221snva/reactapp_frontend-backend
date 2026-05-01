<?php

namespace App\Services;

use App\Model\Master\Client;
use App\Model\MysqlConnections;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * TenantProvisionService
 *
 * Orchestrates the full provisioning pipeline for a new (or re-provisioned) tenant:
 *   1. provisionDatabase()       — CREATE DATABASE + grant + migrate
 *   2. provisionStorage()        — Create storage/app/clients/client_{id}/ subdirs
 *   3. provisionDefaultSettings()— Insert default row into crm_system_setting
 *   4. provisionDefaultAdminUser()— Create role-6 admin in master.users
 *   5. provisionDefaultCrmData() — Seed default labels / dispositions for CRM
 *
 * All steps are idempotent — safe to call multiple times.
 */
class TenantProvisionService
{
    // ── Full pipeline ─────────────────────────────────────────────────────────

    /**
     * Run the entire provisioning pipeline for a client.
     */
    public function provision(Client $client): void
    {
        Log::info("TenantProvisionService: starting provisioning for client_{$client->id}");

        $this->provisionDatabase($client);
        $this->provisionStorage($client->id);
        $this->provisionDefaultSettings($client->id, $client->company_name);
        $this->provisionDefaultAdminUser($client->id, $client->company_name);
        $this->provisionDefaultCrmData($client->id);

        Log::info("TenantProvisionService: provisioning complete for client_{$client->id}");
    }

    // ── Step 1 — Database ─────────────────────────────────────────────────────

    /**
     * Create the client database (if absent) and run all client migrations.
     */
    public function provisionDatabase(Client $client): void
    {
        $conn = MysqlConnections::where('client_id', $client->id)->first();

        if (!$conn) {
            throw new \RuntimeException("No mysql_connection record for client_{$client->id}. Create it first.");
        }

        // Refresh runtime connection config
        Artisan::call('make:database:config');

        // Create database if it does not yet exist
        try {
            DB::connection("mysql_{$client->id}")->getPdo();
            Log::info("TenantProvisionService: database {$conn->db_name} already exists");
        } catch (\Exception $e) {
            Log::info("TenantProvisionService: creating database {$conn->db_name}");
            DB::connection('master')->statement("CREATE DATABASE IF NOT EXISTS `{$conn->db_name}`");
            DB::connection('master')->statement(
                "GRANT ALL PRIVILEGES ON `{$conn->db_name}`.* TO '{$conn->db_user}'@'{$conn->ip}'"
            );
            DB::connection('master')->statement("FLUSH PRIVILEGES");
            Artisan::call('make:database:config'); // reload so new DB is accessible
        }

        // Run client migrations
        Artisan::call('migrate', [
            '--database' => "mysql_{$client->id}",
            '--path'     => 'database/migrations/client',
            '--force'    => true,
        ]);

        Log::info("TenantProvisionService: migrations complete for client_{$client->id}");
    }

    // ── Step 2 — Storage ──────────────────────────────────────────────────────

    /**
     * Create the standard folder tree under storage/app/clients/client_{id}/.
     */
    public function provisionStorage(int $clientId): void
    {
        TenantStorageService::ensureDirectories($clientId);
    }

    // ── Step 3 — Default company settings ────────────────────────────────────

    /**
     * Insert a default crm_system_setting row for the tenant (once only).
     */
    public function provisionDefaultSettings(int $clientId, string $companyName): void
    {
        $conn = "mysql_{$clientId}";

        try {
            if (!DB::connection($conn)->getSchemaBuilder()->hasTable('crm_system_setting')) {
                Log::warning("TenantProvisionService: crm_system_setting missing for client_{$clientId} — skipping");
                return;
            }

            $existing = DB::connection($conn)->table('crm_system_setting')->first();
            if ($existing) {
                Log::info("TenantProvisionService: crm_system_setting already has a row for client_{$clientId}");
                return;
            }

            DB::connection($conn)->table('crm_system_setting')->insert([
                'company_name'    => $companyName,
                'company_email'   => '',
                'company_phone'   => '',
                'company_address' => '',
                'company_domain'  => '',
                'logo'            => null,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

            Log::info("TenantProvisionService: default company settings created for client_{$clientId}");
        } catch (\Throwable $e) {
            Log::error("TenantProvisionService: could not create default settings for client_{$clientId}", [
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ── Step 4 — Default admin user ───────────────────────────────────────────

    /**
     * Create a role-6 admin user in master.users for the tenant.
     * Returns the generated password (logged once — retrieve from logs).
     */
    public function provisionDefaultAdminUser(int $clientId, string $companyName): ?string
    {
        try {
            $existing = DB::table('users')
                ->where('parent_id', $clientId)
                ->where('role', 6)
                ->where('is_deleted', 0)
                ->first();

            if ($existing) {
                Log::info("TenantProvisionService: admin user already exists for client_{$clientId} (id={$existing->id})");
                return null;
            }

            // Build a unique email address
            $slug  = strtolower(preg_replace('/[^a-z0-9]/i', '', $companyName)) ?: 'client' . $clientId;
            $email = 'admin@' . $slug . '.local';
            $i     = 1;
            while (DB::table('users')->where('email', $email)->exists()) {
                $email = 'admin' . $i . '@' . $slug . '.local';
                $i++;
            }

            $password = Str::random(16);

            DB::table('users')->insert([
                'parent_id'       => $clientId,
                'base_parent_id'  => $clientId,
                'first_name'      => 'Admin',
                'last_name'       => $companyName,
                'email'           => $email,
                'password'        => Hash::make($password),
                'role'            => 6,
                'user_level'      => 6,
                'is_deleted'      => 0,
                'pusher_uuid'     => (string) Str::uuid(),
                'extension'       => '',
                'asterisk_server_id' => 0,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

            Log::info("TenantProvisionService: default admin user created for client_{$clientId}", [
                'email'    => $email,
                'password' => $password,  // one-time — retrieve from logs
            ]);

            return $password;
        } catch (\Throwable $e) {
            Log::error("TenantProvisionService: could not create admin user for client_{$clientId}", [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    // ── Step 5 — Default CRM data ─────────────────────────────────────────────

    /**
     * Run CRM seeders scoped to this single client.
     * Only seeds if data is absent (idempotent).
     */
    public function provisionDefaultCrmData(int $clientId): void
    {
        $seeders = [
            'seedCrmLabels', 'seedCrmLeadStatuses', 'seedDispositions',
            'seedNotifications', 'seedLabels', 'seedDefaultApi', 'seedCampaignTypes',
        ];

        foreach ($seeders as $method) {
            try {
                $this->{$method}($clientId);
            } catch (\Throwable $e) {
                Log::warning("TenantProvisionService::{$method} failed for client_{$clientId}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info("TenantProvisionService: default CRM data seeded for client_{$clientId}");
    }

    /**
     * Seed default crm_label rows for a single client.
     */
    private function seedCrmLabels(int $clientId): void
    {
        $conn = "mysql_{$clientId}";

        if (!DB::connection($conn)->getSchemaBuilder()->hasTable('crm_label')) {
            return;
        }

        // Skip if already seeded
        if (DB::connection($conn)->table('crm_label')->exists()) {
            return;
        }

        $labels = [
            ['title' => 'First Name',           'label_title_url' => 'first_name',         'data_type' => 'text',          'values' => '', 'required' => 1, 'display_order' => 1,  'column_name' => 'first_name',   'status' => 1, 'label_type' => 'system', 'edit_mode' => 1],
            ['title' => 'Last Name',            'label_title_url' => 'last_name',          'data_type' => 'text',          'values' => '', 'required' => 1, 'display_order' => 2,  'column_name' => 'last_name',    'status' => 1, 'label_type' => 'system', 'edit_mode' => 1],
            ['title' => 'Email',                'label_title_url' => 'email',              'data_type' => 'email',         'values' => '', 'required' => 1, 'display_order' => 3,  'column_name' => 'email',        'status' => 1, 'label_type' => 'system', 'edit_mode' => 1],
            ['title' => 'Mobile',               'label_title_url' => 'mobile',             'data_type' => 'phone_number',  'values' => '', 'required' => 1, 'display_order' => 4,  'column_name' => 'phone_number', 'status' => 1, 'label_type' => 'system', 'edit_mode' => 1],
            ['title' => 'Gender',               'label_title_url' => 'gender',             'data_type' => 'select_option', 'values' => '["male","female","other"]', 'required' => 0, 'display_order' => 5, 'column_name' => 'gender', 'status' => 1, 'label_type' => 'system', 'edit_mode' => 1],
            ['title' => 'DOB',                  'label_title_url' => 'dob',                'data_type' => 'date',          'values' => '', 'required' => 0, 'display_order' => 6,  'column_name' => 'dob',          'status' => 1, 'label_type' => 'system', 'edit_mode' => 1],
            ['title' => 'Country',              'label_title_url' => 'country',            'data_type' => 'text',          'values' => '', 'required' => 0, 'display_order' => 8,  'column_name' => 'country',      'status' => 1, 'label_type' => 'system', 'edit_mode' => 1],
            ['title' => 'State',                'label_title_url' => 'state',              'data_type' => 'text',          'values' => '', 'required' => 0, 'display_order' => 9,  'column_name' => 'state',        'status' => 1, 'label_type' => 'system', 'edit_mode' => 1],
            ['title' => 'City',                 'label_title_url' => 'city',               'data_type' => 'text',          'values' => '', 'required' => 0, 'display_order' => 7,  'column_name' => 'city',         'status' => 1, 'label_type' => 'system', 'edit_mode' => 1],
            ['title' => 'Address',              'label_title_url' => 'address',            'data_type' => 'text',          'values' => '', 'required' => 0, 'display_order' => 10, 'column_name' => 'address',      'status' => 1, 'label_type' => 'system', 'edit_mode' => 1],
            ['title' => 'Legal Company Name',   'label_title_url' => 'legal_company_name', 'data_type' => 'text',          'values' => '', 'required' => 1, 'display_order' => 11, 'column_name' => 'company_name', 'status' => 1, 'label_type' => 'system', 'edit_mode' => 1],
            ['title' => 'Application URL',      'label_title_url' => 'unique_url',         'data_type' => 'text',          'values' => '', 'required' => 0, 'display_order' => 12, 'column_name' => 'unique_url',   'status' => 1, 'label_type' => 'system', 'edit_mode' => 0],
        ];

        $now = now();
        foreach ($labels as &$row) {
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
        }

        DB::connection($conn)->table('crm_label')->insert($labels);
    }

    /**
     * Seed default crm_lead_status rows for a single client.
     */
    private function seedCrmLeadStatuses(int $clientId): void
    {
        $conn = "mysql_{$clientId}";

        if (!DB::connection($conn)->getSchemaBuilder()->hasTable('crm_lead_status')) {
            return;
        }
        if (DB::connection($conn)->table('crm_lead_status')->exists()) {
            return;
        }

        $statuses = [
            ['title' => 'New Lead',     'color_code' => '#3B82F6', 'display_order' => 1, 'status' => 1],
            ['title' => 'In Review',    'color_code' => '#F59E0B', 'display_order' => 2, 'status' => 1],
            ['title' => 'Approved',     'color_code' => '#10B981', 'display_order' => 3, 'status' => 1],
            ['title' => 'Declined',     'color_code' => '#EF4444', 'display_order' => 4, 'status' => 1],
            ['title' => 'Funded',       'color_code' => '#8B5CF6', 'display_order' => 5, 'status' => 1],
            ['title' => 'Closed',       'color_code' => '#6B7280', 'display_order' => 6, 'status' => 1],
        ];

        $now = now();
        foreach ($statuses as &$row) {
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
        }

        DB::connection($conn)->table('crm_lead_status')->insert($statuses);
    }

    /**
     * Seed default disposition rows for a single client.
     */
    private function seedDispositions(int $clientId): void
    {
        $conn = "mysql_{$clientId}";

        if (!DB::connection($conn)->getSchemaBuilder()->hasTable('disposition')) {
            return;
        }
        if (DB::connection($conn)->table('disposition')->exists()) {
            return;
        }

        $dispositions = [
            ['title' => 'No Answer',       'd_type' => 'system', 'status' => 1],
            ['title' => 'Busy',            'd_type' => 'system', 'status' => 1],
            ['title' => 'Callback',        'd_type' => 'system', 'status' => 1],
            ['title' => 'Not Interested',  'd_type' => 'system', 'status' => 1],
            ['title' => 'DNC',             'd_type' => 'system', 'status' => 1],
            ['title' => 'Qualified',       'd_type' => 'system', 'status' => 1],
        ];

        DB::connection($conn)->table('disposition')->insert($dispositions);
    }

    /**
     * Seed system notification subscriptions for a single client.
     */
    private function seedNotifications(int $clientId): void
    {
        $conn = "mysql_{$clientId}";

        if (!DB::connection($conn)->getSchemaBuilder()->hasTable('system_notifications')) {
            return;
        }
        if (DB::connection($conn)->table('system_notifications')->exists()) {
            return;
        }

        $types = [
            'list_add_delete', 'extension_add_delete', 'campaign_low_lead',
            'daily_call_report', 'recycle_delete', 'ip_whitelist',
            'send_fax_email', 'send_callback',
        ];

        $rows = [];
        foreach ($types as $typeId) {
            $rows[] = [
                'notification_id' => $typeId,
                'active'          => 0,
                'active_sms'      => 0,
                'subscribers'     => '[]',
            ];
        }

        DB::connection($conn)->table('system_notifications')->insert($rows);
    }

    /**
     * Seed default label rows for a single client.
     */
    private function seedLabels(int $clientId): void
    {
        $conn = "mysql_{$clientId}";

        if (!DB::connection($conn)->getSchemaBuilder()->hasTable('label')) {
            return;
        }
        if (DB::connection($conn)->table('label')->exists()) {
            return;
        }

        $labels = [
            ['id' => 1,  'title' => 'First Name'],
            ['id' => 2,  'title' => 'Last Name'],
            ['id' => 3,  'title' => 'Legal Company Name'],
            ['id' => 4,  'title' => 'Address'],
            ['id' => 5,  'title' => 'Work Phone'],
            ['id' => 6,  'title' => 'Mobile'],
            ['id' => 7,  'title' => 'City'],
            ['id' => 8,  'title' => 'State'],
            ['id' => 9,  'title' => 'Zip'],
            ['id' => 10, 'title' => 'Funding Amount'],
            ['id' => 11, 'title' => 'Email'],
            ['id' => 12, 'title' => 'Business Type'],
            ['id' => 13, 'title' => 'Monthly Revenue'],
            ['id' => 14, 'title' => 'Lead Source'],
            ['id' => 15, 'title' => 'Credit Score'],
            ['id' => 16, 'title' => 'Business Age'],
            ['id' => 17, 'title' => 'Annual Revenue'],
            ['id' => 18, 'title' => 'Factor Rate'],
        ];

        DB::connection($conn)->table('label')->insert($labels);
    }

    /**
     * Seed default API row for a single client.
     */
    private function seedDefaultApi(int $clientId): void
    {
        $conn = "mysql_{$clientId}";

        if (!DB::connection($conn)->getSchemaBuilder()->hasTable('api')) {
            return;
        }
        if (DB::connection($conn)->table('api')->where('campaign_id', '0')->exists()) {
            return;
        }

        DB::connection($conn)->table('api')->insert([
            'title'       => 'API',
            'url'         => 'https://www.test.com/',
            'campaign_id' => '0',
            'is_default'  => '1',
        ]);
    }

    /**
     * Seed default campaign types for a single client.
     */
    private function seedCampaignTypes(int $clientId): void
    {
        $conn = "mysql_{$clientId}";

        if (!DB::connection($conn)->getSchemaBuilder()->hasTable('campaign_types')) {
            return;
        }
        if (DB::connection($conn)->table('campaign_types')->exists()) {
            return;
        }

        DB::connection($conn)->table('campaign_types')->insert([
            ['title' => 'Super Power Dial', 'title_url' => 'super_power_dial', 'status' => '1'],
            ['title' => 'Predictive Dial',  'title_url' => 'predictive_dial',  'status' => '0'],
            ['title' => 'Outbound AI',      'title_url' => 'outbound_ai',      'status' => '0'],
        ]);
    }

    // ── Bulk re-provisioning (storage only) ───────────────────────────────────

    /**
     * Ensure storage directories exist for all active clients.
     * Safe to run on a live system — creates missing dirs only.
     */
    public function reprovisionStorageAll(): void
    {
        $clients = Client::where('is_deleted', 0)->get();
        foreach ($clients as $client) {
            TenantStorageService::ensureDirectories($client->id);
        }
        Log::info("TenantProvisionService: storage directories ensured for {$clients->count()} clients");
    }
}
