<?php

namespace App\Console\Commands;

use App\Jobs\DripV2ProcessJob;
use App\Model\Master\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Dispatches DripV2ProcessJob for each active client.
 *
 * Usage:
 *   php artisan drip:process              # all clients
 *   php artisan drip:process --client=42  # single client
 *
 * Designed to run via cron every minute: * * * * *
 */
class DripV2ScheduleCommand extends Command
{
    protected $signature   = 'drip:process {--client= : Process a single client ID}';
    protected $description = 'Dispatch drip campaign processing jobs for all (or one) client(s)';

    public function handle(): int
    {
        $clientIdFilter = $this->option('client');

        if ($clientIdFilter && $clientIdFilter !== 'all') {
            $this->processClient((int) $clientIdFilter);
            return 0;
        }

        // Load all active clients
        try {
            $clients = Client::where('status', 1)->get(['id']);
        } catch (\Throwable $e) {
            $this->error("Failed to load clients: " . $e->getMessage());
            return 1;
        }

        $dispatched = 0;
        foreach ($clients as $client) {
            $this->processClient($client->id);
            $dispatched++;
        }

        $this->info("Dispatched drip processing for {$dispatched} client(s).");
        return 0;
    }

    private function processClient(int $clientId): void
    {
        try {
            // Quick check: does this client have any active campaigns?
            $conn = "mysql_{$clientId}";
            $hasActive = DB::connection($conn)
                ->table('drip_v2_campaigns')
                ->where('status', 'active')
                ->exists();

            if (!$hasActive) return;

            dispatch(new DripV2ProcessJob($clientId));
            $this->line("  Dispatched for client {$clientId}");
        } catch (\Throwable $e) {
            // Table may not exist on this client yet — skip silently
            Log::debug("drip:process skip client {$clientId}: " . $e->getMessage());
        }
    }
}
