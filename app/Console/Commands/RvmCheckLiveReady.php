<?php

namespace App\Console\Commands;

use App\Model\Master\Client;
use App\Model\Master\Rvm\TenantFlag;
use App\Services\Rvm\RvmProviderRouter;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * rvm:check-live-ready
 *
 * Audits a tenant's readiness for the dry_run → live cutover. Run this
 * right before flipping a tenant from dry_run to live; a non-zero exit
 * means at least one red check and the flip should NOT happen.
 *
 * Usage:
 *
 *   # Audit client 42 with default wallet threshold (1000 cents)
 *   php artisan rvm:check-live-ready --client=42
 *
 *   # Raise the wallet threshold to $50
 *   php artisan rvm:check-live-ready --client=42 --wallet-threshold=5000
 *
 *   # Machine-readable JSON output (for dashboards / CI)
 *   php artisan rvm:check-live-ready --client=42 --json
 *
 * Exit codes:
 *   0  all checks passed, tenant is live-ready
 *   1  at least one check failed (see output for details)
 *   2  invalid arguments
 *
 * Check inventory:
 *   T1. Tenant exists in master.clients and has an api_key
 *   T2. Current pipeline_mode = dry_run (must soak dry_run first)
 *   T3. rvm_tenant_flags.live_provider is set
 *   T4. live_provider is enabled in config/rvm.php
 *   T5. live_provider is currently healthy in the router (circuit breaker ok)
 *   T6. Wallet balance ≥ threshold
 *   T7. Recent dry_run drops (last 24h): ≥95% delivered, 0% failed
 *   T8. No spike in divert skips (last 1h): translate_failed, no_audio_for_live,
 *       dropservice_rejected:* — count shown, threshold informational
 *   T9. At least one voice_templete row on the client DB has audio_file_url set
 *       (informational, can't verify all templates the tenant might use)
 *
 * The command is strictly read-only. It never mutates rvm_tenant_flags —
 * flipping to live is a separate manual step (see cutover-runbook.md).
 */
class RvmCheckLiveReady extends Command
{
    protected $signature = 'rvm:check-live-ready
                            {--client= : Client id (required)}
                            {--wallet-threshold=1000 : Min wallet balance in cents}
                            {--json : Emit a machine-readable JSON report instead of the ANSI table}';

    protected $description = 'Audit a tenant for dry_run → live RVM cutover readiness';

    /** @var array<int,array{id:string,label:string,status:string,detail:string}> */
    private array $checks = [];

    public function handle(RvmProviderRouter $router): int
    {
        $clientId = $this->option('client');
        $walletThreshold = (int) $this->option('wallet-threshold');
        $jsonOut = (bool) $this->option('json');

        if (!$clientId || !is_numeric($clientId)) {
            $this->error('--client is required and must be numeric');
            return 2;
        }
        $clientId = (int) $clientId;

        // ── T1. Client exists + has api_key ────────────────────────────
        $client = Client::find($clientId);
        if (!$client) {
            $this->record('T1', 'Client exists', 'fail', "client {$clientId} not found");
            return $this->finish($jsonOut);
        }
        if (empty($client->api_key)) {
            $this->record('T1', 'Client has api_key', 'fail', 'api_key column is empty');
            return $this->finish($jsonOut);
        }
        $this->record('T1', 'Client exists + has api_key', 'pass', "id={$clientId} company={$client->company_name}");

        // ── T2. Tenant is currently in dry_run mode ────────────────────
        $flag = TenantFlag::on('master')->find($clientId);
        $mode = $flag?->pipeline_mode ?? TenantFlag::MODE_LEGACY;
        if ($mode === TenantFlag::MODE_DRY_RUN) {
            $this->record('T2', 'Pipeline mode = dry_run', 'pass', "soaked on dry_run");
        } elseif ($mode === TenantFlag::MODE_LIVE) {
            $this->record('T2', 'Pipeline mode', 'warn', "already live — check is informational");
        } else {
            $this->record('T2', 'Pipeline mode = dry_run', 'fail', "mode is '{$mode}', complete shadow → dry_run soak first");
        }

        // ── T3. live_provider set ──────────────────────────────────────
        $liveProvider = $flag?->live_provider;
        if (empty($liveProvider)) {
            $this->record('T3', 'rvm_tenant_flags.live_provider set', 'fail', 'NULL — pick a carrier and update the flag row');
        } else {
            $this->record('T3', 'live_provider set', 'pass', "= '{$liveProvider}'");
        }

        // ── T4. live_provider enabled in config ────────────────────────
        if ($liveProvider) {
            $enabled = (bool) config("rvm.providers.{$liveProvider}.enabled", false);
            if ($enabled) {
                $this->record('T4', "config/rvm.php providers.{$liveProvider}.enabled", 'pass', 'true');
            } else {
                $this->record('T4', "config/rvm.php providers.{$liveProvider}.enabled", 'fail', 'false — enable via env var');
            }
        } else {
            $this->record('T4', 'Provider enabled in config', 'skip', 'no live_provider set');
        }

        // ── T5. live_provider healthy in router ────────────────────────
        if ($liveProvider) {
            try {
                $healthy = $router->isHealthy($liveProvider);
                if ($healthy) {
                    $this->record('T5', "Router circuit-breaker for {$liveProvider}", 'pass', 'healthy');
                } else {
                    $this->record('T5', "Router circuit-breaker for {$liveProvider}", 'fail', 'breaker open — wait for cooldown or debug underlying failure');
                }
            } catch (Throwable $e) {
                $this->record('T5', 'Router health check', 'fail', 'exception: ' . $e->getMessage());
            }
        } else {
            $this->record('T5', 'Router health', 'skip', 'no live_provider set');
        }

        // ── T6. Wallet balance ≥ threshold ─────────────────────────────
        try {
            $wallet = DB::connection('master')
                ->table('rvm_wallet')
                ->where('client_id', $clientId)
                ->first(['balance_cents', 'reserved_cents']);
            if (!$wallet) {
                $this->record('T6', 'rvm_wallet row', 'fail', 'no wallet row — seed it before flipping live');
            } else {
                $bal = (int) $wallet->balance_cents;
                $res = (int) $wallet->reserved_cents;
                $avail = $bal - $res;
                if ($avail >= $walletThreshold) {
                    $this->record('T6', "Wallet available ≥ {$walletThreshold}c",
                        'pass', "balance={$bal}c reserved={$res}c available={$avail}c");
                } else {
                    $this->record('T6', "Wallet available ≥ {$walletThreshold}c",
                        'fail', "available={$avail}c < threshold={$walletThreshold}c — top up before flipping");
                }
            }
        } catch (Throwable $e) {
            $this->record('T6', 'Wallet check', 'fail', 'exception: ' . $e->getMessage());
        }

        // ── T7. Recent dry_run drops (24h) ─────────────────────────────
        try {
            $since = Carbon::now('UTC')->subDay();
            $stats = DB::connection('master')
                ->table('rvm_drops')
                ->where('client_id', $clientId)
                ->where('created_at', '>=', $since)
                ->selectRaw('status, COUNT(*) as c')
                ->groupBy('status')
                ->pluck('c', 'status')
                ->all();

            $total     = array_sum($stats);
            $delivered = (int) ($stats['delivered'] ?? 0);
            $failed    = (int) ($stats['failed']    ?? 0);

            if ($total === 0) {
                $this->record('T7', 'Dry-run drops in last 24h', 'warn', 'none — no traffic to verify dry_run soak');
            } else {
                $deliveredPct = $total > 0 ? round(($delivered / $total) * 100, 1) : 0;
                $detail = sprintf(
                    'total=%d delivered=%d (%.1f%%) failed=%d other=%d',
                    $total, $delivered, $deliveredPct, $failed, $total - $delivered - $failed,
                );
                if ($deliveredPct >= 95 && $failed === 0) {
                    $this->record('T7', 'Dry-run delivered rate ≥95% and no failures', 'pass', $detail);
                } elseif ($failed > 0) {
                    $this->record('T7', 'Dry-run failures', 'fail', $detail);
                } else {
                    $this->record('T7', 'Dry-run delivered rate ≥95%', 'warn', $detail);
                }
            }
        } catch (Throwable $e) {
            $this->record('T7', 'Dry-run stats', 'fail', 'exception: ' . $e->getMessage());
        }

        // ── T8. Divert skip spike (1h window) ──────────────────────────
        //
        // We look at the recent shadow log to count translate_failed
        // patterns. These don't land in rvm_drops (they never got
        // created), so the only place to find them is the app log —
        // which this command can't easily introspect. Instead we use
        // a proxy: cdr rows with status != null (legacy proceeded)
        // and created_at > since, while the tenant was in dry_run.
        try {
            $since = Carbon::now('UTC')->subHour();
            $notDivertedRecent = DB::connection('master')
                ->table('rvm_cdr_log')
                ->where('api_token', $client->api_key)
                ->where('created_at', '>=', $since)
                ->whereNull('diverted_at')
                ->count();
            if ($notDivertedRecent === 0) {
                $this->record('T8', 'Recent cdr rows without divert (1h)', 'pass', 'none — all traffic is routing through v2');
            } elseif ($notDivertedRecent < 5) {
                $this->record('T8', 'Recent cdr rows without divert (1h)', 'warn',
                    "{$notDivertedRecent} row(s) — likely in-flight retries, monitor");
            } else {
                $this->record('T8', 'Recent cdr rows without divert (1h)', 'fail',
                    "{$notDivertedRecent} row(s) — investigate before live flip");
            }
        } catch (Throwable $e) {
            $this->record('T8', 'Divert coverage check', 'fail', 'exception: ' . $e->getMessage());
        }

        // ── T9. At least one voice_templete has audio URL configured ──
        //
        // Historical schemas differ across tenants — some have
        // `audio_file_url`, some have `audio_url`, some have neither.
        // We probe both column names defensively and also accept the
        // env-level RVM_LEGACY_AUDIO_BASE_URL fallback as a "yes".
        try {
            $conn = 'mysql_' . $clientId;
            $baseUrl = (string) env('RVM_LEGACY_AUDIO_BASE_URL', '');

            $audioCol = null;
            if (\Illuminate\Support\Facades\Schema::connection($conn)->hasColumn('voice_templete', 'audio_file_url')) {
                $audioCol = 'audio_file_url';
            } elseif (\Illuminate\Support\Facades\Schema::connection($conn)->hasColumn('voice_templete', 'audio_url')) {
                $audioCol = 'audio_url';
            }

            if ($audioCol) {
                $hasAudio = DB::connection($conn)
                    ->table('voice_templete')
                    ->whereNotNull($audioCol)
                    ->where($audioCol, '!=', '')
                    ->exists();
                if ($hasAudio) {
                    $this->record('T9', "voice_templete.{$audioCol} has ≥1 row", 'pass', 'at least one template has audio configured');
                } elseif ($baseUrl !== '') {
                    $this->record('T9', 'voice_templete audio', 'pass',
                        "no template has {$audioCol}, but RVM_LEGACY_AUDIO_BASE_URL is set — divert will construct URLs from legacy filenames");
                } else {
                    $this->record('T9', "voice_templete.{$audioCol} has ≥1 row", 'warn',
                        "no template has {$audioCol} and RVM_LEGACY_AUDIO_BASE_URL is unset — live divert will skip rows as no_audio_for_live");
                }
            } elseif ($baseUrl !== '') {
                $this->record('T9', 'voice_templete audio column', 'pass',
                    'neither audio_file_url nor audio_url column exists, but RVM_LEGACY_AUDIO_BASE_URL is set — divert will construct URLs from legacy filenames');
            } else {
                $this->record('T9', 'voice_templete audio column', 'warn',
                    'neither audio_file_url nor audio_url column exists on voice_templete, and RVM_LEGACY_AUDIO_BASE_URL is unset — live divert will skip rows as no_audio_for_live');
            }
        } catch (Throwable $e) {
            $this->record('T9', 'voice_templete audio check', 'warn',
                'could not query client DB: ' . $e->getMessage());
        }

        return $this->finish($jsonOut);
    }

    private function record(string $id, string $label, string $status, string $detail): void
    {
        $this->checks[] = [
            'id'     => $id,
            'label'  => $label,
            'status' => $status,
            'detail' => $detail,
        ];
    }

    /**
     * Print the collected checks and return the process exit code.
     * Exit 0 if no 'fail', else 1. 'warn' never fails the check.
     */
    private function finish(bool $jsonOut): int
    {
        $anyFail = false;
        foreach ($this->checks as $c) {
            if ($c['status'] === 'fail') {
                $anyFail = true;
                break;
            }
        }

        if ($jsonOut) {
            $this->line(json_encode([
                'ok'     => !$anyFail,
                'checks' => $this->checks,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $anyFail ? 1 : 0;
        }

        $this->line('');
        $this->line('<info>rvm:check-live-ready</info>');
        $this->line('');

        $rows = array_map(function ($c) {
            return [
                $c['id'],
                $this->colorStatus($c['status']),
                $c['label'],
                $c['detail'],
            ];
        }, $this->checks);
        $this->table(['id', 'status', 'check', 'detail'], $rows);
        $this->line('');

        if ($anyFail) {
            $this->error('NOT READY — fix the failed checks above before flipping to live.');
            return 1;
        }
        $this->info('READY — all checks passed. Safe to UPDATE rvm_tenant_flags SET pipeline_mode = \'live\'.');
        return 0;
    }

    private function colorStatus(string $status): string
    {
        return match ($status) {
            'pass' => '<fg=green>pass</>',
            'fail' => '<fg=red>fail</>',
            'warn' => '<fg=yellow>warn</>',
            default => $status,
        };
    }
}
