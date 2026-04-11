<?php

namespace App\Console\Commands;

use App\Model\Master\Client;
use App\Model\Master\Rvm\TenantFlag;
use App\Services\Rvm\RvmDivertService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * rvm:backfill-legacy
 *
 * Migrates in-flight legacy rvm_cdr_log rows into the v2 pipeline for a
 * tenant that has just been flipped to dry_run mode (Phase 5b cutover).
 *
 * The divert hook in SendRvmJob only fires when the queue worker
 * processes a job. Any legacy row already sitting in the database queue
 * or in rvm_cdr_log with a queued/null status at the moment of cutover
 * would eventually get picked up by the worker and diverted naturally —
 * but that can take hours on a slow queue, and operators usually want
 * a clean break. This command does the divert eagerly, in one pass,
 * so the cutover moment is unambiguous.
 *
 * Usage:
 *
 *   # Preview what would happen for client 42.
 *   php artisan rvm:backfill-legacy --client=42 --dry-run
 *
 *   # Actually run the divert for up to 500 rows.
 *   php artisan rvm:backfill-legacy --client=42
 *
 *   # Walk a bigger batch.
 *   php artisan rvm:backfill-legacy --client=42 --limit=5000
 *
 *   # Include 'initiated' rows (default is only NULL status rows).
 *   php artisan rvm:backfill-legacy --client=42 --include-initiated
 *
 * Safety:
 *   - The command refuses to run unless the tenant is in dry_run mode.
 *     A tenant in 'legacy' or 'shadow' mode has no divert contract yet,
 *     so mass-writing v2 drops for them would pollute the v2 state.
 *   - Rows that already have diverted_at != NULL are skipped by the
 *     divert service itself — running the command twice is safe.
 *   - The command is read-only when --dry-run is passed.
 */
class RvmBackfillLegacy extends Command
{
    protected $signature = 'rvm:backfill-legacy
                            {--client= : Client id (required)}
                            {--limit=500 : Max rows to process}
                            {--dry-run : Don\'t write anything, just show what would happen}
                            {--include-initiated : Also process rows with status=initiated}
                            {--force : Skip the tenant-mode sanity check (DANGEROUS)}';

    protected $description = 'Divert in-flight legacy rvm_cdr_log rows for a tenant into the v2 pipeline';

    public function handle(RvmDivertService $divert): int
    {
        $clientId = $this->option('client');
        $limit    = (int) $this->option('limit');
        $dryRun   = (bool) $this->option('dry-run');
        $incInit  = (bool) $this->option('include-initiated');
        $force    = (bool) $this->option('force');

        if (!$clientId || !is_numeric($clientId)) {
            $this->error('--client is required and must be numeric');
            return 1;
        }
        $clientId = (int) $clientId;

        if ($limit <= 0 || $limit > 100000) {
            $this->error('--limit must be in 1..100000');
            return 1;
        }

        // ── Verify the tenant exists and is in dry_run mode ────────────
        $client = Client::find($clientId);
        if (!$client) {
            $this->error("Client {$clientId} not found.");
            return 1;
        }

        $flag = TenantFlag::on('master')->find($clientId);
        $mode = $flag?->pipeline_mode ?? TenantFlag::MODE_LEGACY;

        if ($mode !== TenantFlag::MODE_DRY_RUN && !$force) {
            $this->error(sprintf(
                'Refusing to backfill: client %d is in mode "%s", not "dry_run". Use --force to override.',
                $clientId,
                $mode,
            ));
            return 2;
        }

        if (empty($client->api_key)) {
            $this->error("Client {$clientId} has no api_key — cannot match rvm_cdr_log rows.");
            return 1;
        }

        // ── Build the candidate query ──────────────────────────────────
        $q = DB::connection('master')
            ->table('rvm_cdr_log')
            ->where('api_token', $client->api_key)
            ->whereNull('diverted_at');

        if ($incInit) {
            // NULL = never touched by legacy dispatch; 'initiated' = legacy
            // said "I sent the Originate" but no terminal disposition yet.
            $q->where(function ($w) {
                $w->whereNull('status')->orWhere('status', 'initiated');
            });
        } else {
            $q->whereNull('status');
        }

        $q->orderBy('id')
          ->limit($limit);

        $rows = $q->get();

        $this->line('');
        $this->line(sprintf(
            '<info>rvm:backfill-legacy</info> — client=%d (%s), mode=%s, rows=%d, dry_run=%s',
            $clientId,
            $client->company_name ?? '(unknown)',
            $mode,
            $rows->count(),
            $dryRun ? 'yes' : 'no',
        ));
        $this->line('');

        if ($rows->isEmpty()) {
            $this->info('Nothing to backfill.');
            return 0;
        }

        // ── Process ────────────────────────────────────────────────────
        $stats = [
            'scanned'   => 0,
            'diverted'  => 0,
            'skipped'   => 0,
            'failed'    => 0,
        ];
        $reasons = [];

        foreach ($rows as $row) {
            $stats['scanned']++;

            if ($dryRun) {
                // In dry-run we don't call the service at all — just
                // show the candidate. Counts it as "would divert".
                $stats['diverted']++;
                continue;
            }

            try {
                // Convert the DB row into the same stdClass shape that
                // SendRvmJob::$data would carry at dispatch time. The
                // json_data column holds the original serialized payload
                // if it's still present; merging preserves any fields
                // the schema doesn't have a dedicated column for.
                $payload = $this->rowToPayload($row);

                $result = $divert->divert($clientId, TenantFlag::MODE_DRY_RUN, $payload);

                if ($result->diverted) {
                    $stats['diverted']++;
                } else {
                    $stats['skipped']++;
                    $reasons[$result->reason] = ($reasons[$result->reason] ?? 0) + 1;
                }
            } catch (Throwable $e) {
                $stats['failed']++;
                $reasons['exception:' . class_basename($e)]
                    = ($reasons['exception:' . class_basename($e)] ?? 0) + 1;
            }
        }

        // ── Report ─────────────────────────────────────────────────────
        $this->table(
            ['scanned', 'diverted', 'skipped', 'failed'],
            [[$stats['scanned'], $stats['diverted'], $stats['skipped'], $stats['failed']]],
        );

        if (!empty($reasons)) {
            $this->line('');
            $this->line('<info>Breakdown of skipped/failed:</info>');
            foreach ($reasons as $reason => $n) {
                $this->line("  - {$reason}: {$n}");
            }
        }

        if ($dryRun) {
            $this->line('');
            $this->warn('DRY RUN — no rows were actually diverted. Re-run without --dry-run.');
        }

        return 0;
    }

    /**
     * Convert a DB row from rvm_cdr_log into the stdClass payload shape
     * that SendRvmJob / RvmDivertService expect.
     *
     * The `json_data` column (if present) holds the tenant's original
     * request body as serialized JSON — merge its fields in so any
     * custom metadata the tenant submitted is preserved.
     */
    private function rowToPayload(object $row): object
    {
        $out = [
            'id'                 => $row->id,
            'phone'              => $row->phone,
            'cli'                => $row->cli,
            'apiToken'           => $row->api_token,
            'user_id'            => $row->user_id,
            'rvm_domain_id'      => $row->rvm_domain_id,
            'sip_gateway_id'     => $row->sip_gateway_id,
            'voicemail_id'       => $row->voicemail_id,
            'voicemail_drop_log_id' => $row->voicemail_drop_log_id ?? null,
        ];

        if (!empty($row->json_data)) {
            $decoded = json_decode($row->json_data, true);
            if (is_array($decoded)) {
                // The cdr row's columns are authoritative for core
                // fields (phone, cli, id) — json_data fills in anything
                // the columns don't have (custom metadata, timing flags).
                foreach ($decoded as $k => $v) {
                    if (!array_key_exists($k, $out)) {
                        $out[$k] = $v;
                    }
                }
            }
        }

        return (object) $out;
    }
}
