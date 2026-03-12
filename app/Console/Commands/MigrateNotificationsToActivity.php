<?php

namespace App\Console\Commands;

use App\Model\Master\Client;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Migrates crm_notifications → crm_lead_activity.
 *
 * Type mapping (crm_notifications.type):
 *   0 → system       (lead created / updated / status changed / doc uploaded / email sent)
 *   1 → note_added   (manual notes entered by agents)
 *   2 → lender_submitted
 *   3 → lender_response
 *
 * Usage:
 *   php artisan activity:migrate-notifications              # all clients
 *   php artisan activity:migrate-notifications --client=11  # single client
 *   php artisan activity:migrate-notifications --client=11 --fresh  # wipe & re-migrate
 */
class MigrateNotificationsToActivity extends Command
{
    protected $signature = 'activity:migrate-notifications
                            {--client= : Migrate a single client ID}
                            {--fresh   : Delete existing migrated rows before re-inserting}';

    protected $description = 'Migrate crm_notifications → crm_lead_activity';

    /** crm_notifications.type → activity_type */
    private const TYPE_MAP = [
        '0' => 'system',
        '1' => 'note_added',
        '2' => 'lender_submitted',
        '3' => 'lender_response',
    ];

    public function handle(): int
    {
        $clientFilter = $this->option('client');

        $clients = $clientFilter
            ? Client::where('id', $clientFilter)->get()
            : Client::all();

        if ($clients->isEmpty()) {
            $this->error("No clients found" . ($clientFilter ? " for id={$clientFilter}" : '') . '.');
            return 1;
        }

        foreach ($clients as $client) {
            $this->migrateClient((string) $client->id);
        }

        $this->info('All done.');
        return 0;
    }

    private function migrateClient(string $clientId): void
    {
        $conn = "mysql_{$clientId}";
        $this->info("── Client {$clientId} ──────────────────────────────────────────");

        // Verify both tables exist
        try {
            $schema = DB::connection($conn)->getSchemaBuilder();
            if (!$schema->hasTable('crm_notifications')) {
                $this->warn("  Skipping: crm_notifications table not found.");
                return;
            }
            if (!$schema->hasTable('crm_lead_activity')) {
                $this->warn("  Skipping: crm_lead_activity table not found (run migrations first).");
                return;
            }
        } catch (\Throwable $e) {
            $this->warn("  Skipping client {$clientId}: {$e->getMessage()}");
            return;
        }

        try {
            if ($this->option('fresh')) {
                $deleted = DB::connection($conn)
                    ->table('crm_lead_activity')
                    ->where('source_type', 'crm_notifications')
                    ->delete();
                $this->line("  Cleared {$deleted} previously migrated rows.");
            }

            $total = DB::connection($conn)
                ->table('crm_notifications')
                ->whereNotNull('lead_id')
                ->count();

            // Bulk INSERT … SELECT — skips already-migrated rows via LEFT JOIN anti-join.
            // Uses database-side CASE + JSON_MERGE_PATCH; no per-row PHP loop needed.
            DB::connection($conn)->statement("
                INSERT INTO crm_lead_activity (
                    lead_id, user_id, activity_type, subject, body,
                    meta, source_type, source_id, is_pinned, created_at, updated_at
                )
                SELECT
                    n.lead_id,
                    n.user_id,
                    CASE n.type
                        WHEN '0' THEN 'system'
                        WHEN '1' THEN 'note_added'
                        WHEN '2' THEN 'lender_submitted'
                        WHEN '3' THEN 'lender_response'
                        ELSE 'system'
                    END,
                    COALESCE(NULLIF(n.title,''), LEFT(REGEXP_REPLACE(n.message,'<[^>]+>',''),490)),
                    IF(n.title IS NOT NULL AND n.title != '', n.message, NULL),
                    JSON_MERGE_PATCH(
                        JSON_OBJECT('notification_type', CAST(n.type AS CHAR)),
                        COALESCE(n.data, '{}')
                    ),
                    'crm_notifications',
                    n.id,
                    0,
                    COALESCE(n.created_at, NOW()),
                    COALESCE(n.updated_at, NOW())
                FROM crm_notifications n
                LEFT JOIN crm_lead_activity a
                    ON a.source_type = 'crm_notifications' AND a.source_id = n.id
                WHERE n.lead_id IS NOT NULL
                  AND a.id IS NULL
            ");

            $inserted   = DB::connection($conn)->table('crm_lead_activity')->where('source_type', 'crm_notifications')->count();
            $finalCount = DB::connection($conn)->table('crm_lead_activity')->count();

            $this->line("  crm_notifications (with lead_id): {$total}");
            $this->line("  Migrated rows in crm_lead_activity: {$inserted}");
            $this->line("  crm_lead_activity total rows now: {$finalCount}");
            $this->info("  Done.");

        } catch (\Throwable $e) {
            $this->error("  ERROR client {$clientId}: " . $e->getMessage());
            Log::error("MigrateNotificationsToActivity: client {$clientId}", ['error' => $e->getMessage()]);
        }
    }

    private function truncate(string $str, int $max): string
    {
        return mb_strlen($str) > $max ? mb_substr($str, 0, $max - 1) . '…' : $str;
    }
}
