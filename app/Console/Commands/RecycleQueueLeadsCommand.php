<?php

namespace App\Console\Commands;

use App\Model\Master\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Auto-recycle campaign_lead_queue entries based on recycle_rule rows
 * with target_system = 'campaign_queue'.
 *
 * Runs every 15 minutes via scheduler.
 * Usage: php artisan dialer:recycle-queue
 */
class RecycleQueueLeadsCommand extends Command
{
    protected $signature   = 'dialer:recycle-queue {--client= : Process a single client ID} {--dry-run : Preview without making changes}';
    protected $description = 'Re-queue completed/failed campaign_lead_queue leads per recycle rules';

    public function handle(): void
    {
        $dryRun    = $this->option('dry-run');
        $clientOpt = $this->option('client');
        $now       = Carbon::now();
        $dayName   = strtolower($now->format('l')); // e.g. "sunday"
        $timeNow   = $now->format('H:i');

        $this->info("RecycleQueue: {$now->toDateTimeString()} day={$dayName} time={$timeNow} dry-run=" . ($dryRun ? 'yes' : 'no'));

        $clients = $clientOpt
            ? Client::where('id', $clientOpt)->get()
            : Client::where('is_deleted', 0)->get();

        $totalRequeued = 0;

        foreach ($clients as $client) {
            $conn = 'mysql_' . $client->id;

            try {
                DB::connection($conn)->getPdo();
            } catch (\Throwable $e) {
                $this->warn("Client {$client->id}: DB unreachable — skipping");
                continue;
            }

            // Find matching recycle rules for campaign_queue
            $rules = DB::connection($conn)
                ->table('recycle_rule')
                ->where('is_deleted', 0)
                ->where('target_system', 'campaign_queue')
                ->where('day', $dayName)
                ->where('time', '<=', $timeNow)
                ->get();

            if ($rules->isEmpty()) {
                continue;
            }

            foreach ($rules as $rule) {
                $maxAttempts  = (int) ($rule->max_attempts ?: 3);
                $campaignId   = (int) $rule->campaign_id;
                $dispositionId = (int) $rule->disposition_id;

                $query = DB::connection($conn)
                    ->table('campaign_lead_queue')
                    ->where('campaign_id', $campaignId)
                    ->whereIn('status', ['completed', 'failed'])
                    ->where('disposition_id', $dispositionId)
                    ->where('attempts', '<', $maxAttempts);

                if ($dryRun) {
                    $count = $query->count();
                    $this->line("  [DRY-RUN] Client {$client->id} campaign={$campaignId} dispo={$dispositionId}: {$count} lead(s) would be re-queued");
                    $totalRequeued += $count;
                    continue;
                }

                $affected = $query->update([
                    'status'          => 'pending',
                    'attempts'        => 0,
                    'disposition_id'  => null,
                    'next_attempt_at' => null,
                    'called_at'       => null,
                    'completed_at'    => null,
                    'updated_at'      => $now,
                ]);

                if ($affected > 0) {
                    $this->info("  Client {$client->id} campaign={$campaignId} dispo={$dispositionId}: {$affected} lead(s) re-queued");
                    Log::info("RecycleQueue: client={$client->id} campaign={$campaignId} dispo={$dispositionId} requeued={$affected}");
                    $totalRequeued += $affected;
                }
            }
        }

        $this->info("RecycleQueue complete: {$totalRequeued} total lead(s) " . ($dryRun ? 'would be ' : '') . "re-queued");
    }
}
