<?php

namespace App\Console\Commands;

use App\Model\Master\Client;
use App\Model\Master\Rvm\ShadowLog;
use App\Model\Master\Rvm\TenantFlag;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * rvm:shadow-report
 *
 * Aggregates master.rvm_shadow_log into a human-readable divergence
 * summary used to decide whether a tenant is ready to flip from
 * `shadow` → `dry_run` → `live`.
 *
 * The command is read-only. It writes nothing. Safe to run ad-hoc or
 * from cron.
 *
 * Usage:
 *   php artisan rvm:shadow-report                  # last 24h, all tenants
 *   php artisan rvm:shadow-report --hours=6        # last 6h only
 *   php artisan rvm:shadow-report --client=42      # one tenant only
 *   php artisan rvm:shadow-report --client=42 --details
 *
 * Columns printed per tenant:
 *   - client_id / company
 *   - pipeline_mode (from rvm_tenant_flags)
 *   - total shadows
 *   - would_dispatch count
 *   - would_reject count + breakdown by reason
 *   - providers chosen (for would_dispatch)
 *   - divergence_ratio = rejected / total
 *
 * Exit code is always 0 (report, not a test). Add `--fail-above=N` to
 * return non-zero if any tenant's divergence_ratio exceeds N. Useful
 * in CI / deploy gates.
 */
class RvmShadowReport extends Command
{
    protected $signature = 'rvm:shadow-report
                            {--hours=24 : Look-back window in hours}
                            {--client= : Restrict to a single client_id}
                            {--details : Print per-reason and per-provider breakdowns}
                            {--fail-above= : Exit non-zero if divergence_ratio for any tenant exceeds this (0-1)}';

    protected $description = 'Summarise rvm_shadow_log divergences between legacy and the v2 pipeline';

    public function handle(): int
    {
        $hours      = (int) $this->option('hours');
        $clientId   = $this->option('client');
        $showDetails = (bool) $this->option('details');
        $failAbove  = $this->option('fail-above');
        $failAbove  = $failAbove !== null ? (float) $failAbove : null;

        if ($hours <= 0) {
            $this->error('--hours must be a positive integer');
            return 1;
        }

        $since = Carbon::now()->subHours($hours);

        $this->line('');
        $this->line(sprintf(
            '<info>RVM shadow report</info> — since %s (%d hours), global_kill_switch=%s',
            $since->toDateTimeString(),
            $hours,
            config('rvm.use_new_pipeline', false) ? '<info>ON</info>' : '<comment>off</comment>'
        ));
        $this->line('');

        // ── Aggregate per-tenant totals in a single grouped query ───────
        $query = ShadowLog::on('master')
            ->where('created_at', '>=', $since)
            ->selectRaw('
                client_id,
                COUNT(*)                                                              AS total,
                SUM(CASE WHEN would_dispatch = 1 THEN 1 ELSE 0 END)                   AS dispatched,
                SUM(CASE WHEN would_dispatch = 0 THEN 1 ELSE 0 END)                   AS rejected,
                SUM(CASE WHEN would_reject_reason = "dnc_blocked"  THEN 1 ELSE 0 END) AS dnc,
                SUM(CASE WHEN would_reject_reason = "quiet_hours"  THEN 1 ELSE 0 END) AS quiet,
                SUM(CASE WHEN would_reject_reason = "invalid_phone" THEN 1 ELSE 0 END) AS invalid,
                SUM(CASE WHEN would_reject_reason LIKE "compliance_error:%" THEN 1 ELSE 0 END) AS compliance_err,
                SUM(CASE WHEN would_reject_reason LIKE "routing_error:%"    THEN 1 ELSE 0 END) AS routing_err
            ')
            ->groupBy('client_id');

        if ($clientId !== null) {
            $query->where('client_id', (int) $clientId);
        }

        $rows = $query->get();

        if ($rows->isEmpty()) {
            $this->warn('No shadow rows found in window.');
            return 0;
        }

        // ── Pull tenant names + flag modes in one go ────────────────────
        $clientIds = $rows->pluck('client_id')->all();

        $clients = Client::whereIn('id', $clientIds)
            ->get(['id', 'company_name'])
            ->keyBy('id');

        $flags = TenantFlag::on('master')
            ->whereIn('client_id', $clientIds)
            ->get()
            ->keyBy('client_id');

        // ── Table ───────────────────────────────────────────────────────
        $tableRows = [];
        $offenders = [];

        foreach ($rows as $r) {
            $cid   = (int) $r->client_id;
            $total = (int) $r->total;
            $disp  = (int) $r->dispatched;
            $rej   = (int) $r->rejected;
            $ratio = $total > 0 ? round($rej / $total, 3) : 0.0;

            $tableRows[] = [
                'client_id' => $cid,
                'company'   => $clients[$cid]->company_name ?? '(unknown)',
                'mode'      => $flags[$cid]->pipeline_mode ?? TenantFlag::MODE_LEGACY,
                'total'     => $total,
                'disp'      => $disp,
                'rej'       => $rej,
                'dnc'       => (int) $r->dnc,
                'quiet'     => (int) $r->quiet,
                'invalid'   => (int) $r->invalid,
                'compl_err' => (int) $r->compliance_err,
                'route_err' => (int) $r->routing_err,
                'div_ratio' => $ratio,
            ];

            if ($failAbove !== null && $ratio > $failAbove) {
                $offenders[] = [$cid, $ratio];
            }
        }

        // Sort by total descending so the busiest tenants are at the top.
        usort($tableRows, fn($a, $b) => $b['total'] <=> $a['total']);

        $this->table(
            ['client', 'company', 'mode', 'total', 'disp', 'rej', 'dnc', 'quiet', 'inval', 'c-err', 'r-err', 'div'],
            array_map(fn($r) => [
                $r['client_id'],
                $this->truncate($r['company'], 22),
                $r['mode'],
                $r['total'],
                $r['disp'],
                $r['rej'],
                $r['dnc'],
                $r['quiet'],
                $r['invalid'],
                $r['compl_err'],
                $r['route_err'],
                sprintf('%.2f', $r['div_ratio']),
            ], $tableRows)
        );

        // ── Details: per-tenant provider + reason breakdown ─────────────
        if ($showDetails) {
            foreach ($tableRows as $r) {
                $cid = $r['client_id'];
                $this->line('');
                $this->line("<info>── Tenant {$cid}</info> ({$r['company']}) ──");

                $providers = ShadowLog::on('master')
                    ->where('client_id', $cid)
                    ->where('created_at', '>=', $since)
                    ->where('would_dispatch', 1)
                    ->selectRaw('would_provider, COUNT(*) AS total')
                    ->groupBy('would_provider')
                    ->orderByDesc('total')
                    ->get();

                if ($providers->isNotEmpty()) {
                    $this->line('  providers:');
                    foreach ($providers as $p) {
                        $name = $p->would_provider ?? '(none)';
                        $this->line("    • {$name}: {$p->total}");
                    }
                }

                $reasons = ShadowLog::on('master')
                    ->where('client_id', $cid)
                    ->where('created_at', '>=', $since)
                    ->where('would_dispatch', 0)
                    ->selectRaw('would_reject_reason, COUNT(*) AS total')
                    ->groupBy('would_reject_reason')
                    ->orderByDesc('total')
                    ->get();

                if ($reasons->isNotEmpty()) {
                    $this->line('  reject reasons:');
                    foreach ($reasons as $rr) {
                        $name = $rr->would_reject_reason ?? '(null)';
                        $this->line("    • {$name}: {$rr->total}");
                    }
                }
            }
            $this->line('');
        }

        // ── Threshold gate ──────────────────────────────────────────────
        if ($failAbove !== null && !empty($offenders)) {
            $this->line('');
            $this->error(sprintf(
                'FAIL: %d tenant(s) exceed divergence ratio %s',
                count($offenders),
                $failAbove
            ));
            foreach ($offenders as [$cid, $ratio]) {
                $this->line("  - client {$cid}: {$ratio}");
            }
            return 2;
        }

        return 0;
    }

    private function truncate(string $s, int $max): string
    {
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
    }
}
