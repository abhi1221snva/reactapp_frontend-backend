<?php

namespace App\Console\Commands;

use App\Model\Master\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One-time backfill: sync wallet balances from each client DB's wallet table
 * to the master clients.wallet_balance_cents column.
 *
 * Safe to run multiple times — idempotent.
 *
 * Usage:
 *   php artisan billing:backfill-wallets              # all clients
 *   php artisan billing:backfill-wallets --client=11   # single client
 */
class BackfillWalletBalances extends Command
{
    protected $signature = 'billing:backfill-wallets {--client= : Process a single client ID}';
    protected $description = 'Sync wallet balances from client DBs to master clients.wallet_balance_cents';

    public function handle()
    {
        $singleClient = $this->option('client');

        $query = Client::where('is_deleted', 0);
        if ($singleClient) {
            $query->where('id', (int) $singleClient);
        }

        $clients = $query->get(['id', 'company_name']);

        $synced  = 0;
        $skipped = 0;
        $errors  = 0;

        foreach ($clients as $client) {
            try {
                $connName = "mysql_{$client->id}";

                // Check if the connection config exists
                if (!config("database.connections.{$connName}")) {
                    $this->line("  SKIP client #{$client->id} ({$client->company_name}) — no DB config");
                    $skipped++;
                    continue;
                }

                // Check if wallet table exists
                if (!DB::connection($connName)->getSchemaBuilder()->hasTable('wallet')) {
                    $this->line("  SKIP client #{$client->id} ({$client->company_name}) — no wallet table");
                    $skipped++;
                    continue;
                }

                $walletRow = DB::connection($connName)->table('wallet')
                    ->where('currency_code', 'USD')
                    ->first();

                $balanceDollars = $walletRow ? (float) $walletRow->amount : 0;
                $balanceCents   = (int) round($balanceDollars * 100);

                Client::where('id', $client->id)->update([
                    'wallet_balance_cents' => $balanceCents,
                ]);

                $synced++;
                $this->info("  OK client #{$client->id} ({$client->company_name}): \${$balanceDollars} → {$balanceCents} cents");
            } catch (\Throwable $e) {
                $errors++;
                $this->error("  ERR client #{$client->id} ({$client->company_name}): {$e->getMessage()}");
                Log::error('BackfillWalletBalances: failed', [
                    'client_id' => $client->id,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        $this->info("Done. Synced: {$synced}, Skipped: {$skipped}, Errors: {$errors}");

        return 0;
    }
}
