<?php

namespace App\Console\Commands;

use App\Model\Master\Client;
use App\Services\TenantProvisionService;
use App\Services\TenantStorageService;
use Illuminate\Console\Command;

/**
 * php artisan tenant:provision {--client=*} [--all] [--storage-only] [--settings-only]
 *
 * Examples:
 *   php artisan tenant:provision --client=5          # provision single client
 *   php artisan tenant:provision --all               # provision all active clients
 *   php artisan tenant:provision --all --storage-only # create dirs only (safe on live)
 */
class ProvisionClientCommand extends Command
{
    protected $signature = 'tenant:provision
                            {--client=* : Client ID(s) to provision}
                            {--all      : Provision all active clients}
                            {--storage-only   : Only create storage directories (skip DB/users)}
                            {--settings-only  : Only insert default company settings}';

    protected $description = 'Provision one or more tenant clients (database, storage, settings, admin user)';

    public function handle(): int
    {
        $provisionSvc = new TenantProvisionService();

        $clients = $this->resolveClients();
        if ($clients->isEmpty()) {
            $this->error('No clients found. Use --client=N or --all.');
            return 1;
        }

        $storageOnly  = $this->option('storage-only');
        $settingsOnly = $this->option('settings-only');

        $this->info("Found {$clients->count()} client(s) to process.");

        foreach ($clients as $client) {
            $this->line('');
            $this->info("── client_{$client->id}: {$client->company_name} ──────────────────");

            try {
                if ($storageOnly) {
                    $this->comment("  → Creating storage directories…");
                    $provisionSvc->provisionStorage($client->id);
                    $this->info("  ✓ Storage directories created.");

                } elseif ($settingsOnly) {
                    $this->comment("  → Inserting default company settings…");
                    $provisionSvc->provisionDefaultSettings($client->id, $client->company_name);
                    $this->info("  ✓ Default settings applied.");

                } else {
                    // Full provisioning
                    $this->comment("  → Provisioning database…");
                    $provisionSvc->provisionDatabase($client);
                    $this->info("  ✓ Database ready.");

                    $this->comment("  → Creating storage directories…");
                    $provisionSvc->provisionStorage($client->id);
                    $this->info("  ✓ Storage directories created.");
                    $this->printStorageTree($client->id);

                    $this->comment("  → Inserting default company settings…");
                    $provisionSvc->provisionDefaultSettings($client->id, $client->company_name);
                    $this->info("  ✓ Default company settings applied.");

                    $this->comment("  → Creating default admin user…");
                    $password = $provisionSvc->provisionDefaultAdminUser($client->id, $client->company_name);
                    if ($password) {
                        $this->warn("  ⚠ New admin created — password logged. Check storage/logs/lumen.log");
                    } else {
                        $this->info("  ✓ Admin user already exists.");
                    }

                    $this->comment("  → Seeding default CRM data…");
                    $provisionSvc->provisionDefaultCrmData($client->id);
                    $this->info("  ✓ Default CRM data seeded.");
                }
            } catch (\Throwable $e) {
                $this->error("  ✗ Error for client_{$client->id}: " . $e->getMessage());
            }
        }

        $this->line('');
        $this->info("Provisioning run complete.");
        return 0;
    }

    private function resolveClients()
    {
        if ($this->option('all')) {
            return Client::where('is_deleted', 0)->orderBy('id')->get();
        }

        $ids = $this->option('client');
        if (!empty($ids)) {
            return Client::whereIn('id', $ids)->get();
        }

        return collect();
    }

    private function printStorageTree(int $clientId): void
    {
        $base = TenantStorageService::getBasePath($clientId);
        $this->line("     storage/app/clients/client_{$clientId}/");
        foreach (TenantStorageService::SUBDIRS as $dir) {
            $exists = is_dir($base . '/' . $dir) ? '✓' : '✗';
            $this->line("       {$exists} {$dir}/");
        }
    }
}
