<?php

namespace App\Http\Controllers;

use App\Model\Master\AuditLog;
use App\Model\Master\Client;
use App\Model\Master\Rvm\ShadowLog;
use App\Model\Master\Rvm\TenantFlag;
use App\Services\Rvm\RvmFeatureFlagService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * Admin-only controller for the RVM v2 cutover dashboard.
 *
 * All routes in this controller sit behind:
 *   ['jwt.auth', 'auth.superadmin', 'audit.log', 'route.access']
 *
 * Provides the four operations operators need during the migration:
 *
 *   1. LIST     — every tenant with its pipeline_mode, 24h shadow counts,
 *                 and last-seen legacy dispatch time.
 *   2. DETAIL   — drill-down for one tenant: recent shadow rows + a
 *                 divergence summary by reject reason.
 *   3. SET MODE — flip a single tenant to shadow / dry_run / live / legacy.
 *   4. ROLLBACK — emergency "everything back to legacy". Idempotent.
 *
 * This controller is intentionally read-through for shadow data — it
 * never writes to rvm_shadow_log itself. The only writes are to
 * rvm_tenant_flags (via RvmFeatureFlagService::setTenantMode).
 */
class AdminRvmCutoverController extends Controller
{
    public function __construct(private RvmFeatureFlagService $flags)
    {
    }

    /**
     * GET /admin/rvm/cutover
     *
     * Returns every tenant's flag row joined against a 24h shadow
     * aggregate. Clients without an explicit flag row are surfaced as
     * `pipeline_mode = 'legacy'` so operators see the full fleet in one
     * view — not just tenants that have already been touched.
     *
     * Shape:
     *   {
     *     success: true,
     *     message: "...",
     *     data: {
     *       global_kill_switch: bool,
     *       tenants: [
     *         { client_id, company_name, pipeline_mode, shadow_24h, rejected_24h, updated_at },
     *         ...
     *       ]
     *     }
     *   }
     */
    public function index(Request $request)
    {
        // Only list clients that currently exist + aren't soft-deleted.
        // Deleted clients can stay in rvm_tenant_flags for audit but
        // there's nothing actionable to show for them here.
        $clients = Client::where('is_deleted', 0)
            ->orderBy('id')
            ->get(['id', 'company_name']);

        $flagsByClient = TenantFlag::on('master')
            ->whereIn('client_id', $clients->pluck('id')->all())
            ->get()
            ->keyBy('client_id');

        // 24h shadow counters — one GROUP BY keeps this to one query.
        $since = Carbon::now()->subHours(24);
        $shadowCounts = ShadowLog::on('master')
            ->where('created_at', '>=', $since)
            ->whereIn('client_id', $clients->pluck('id')->all())
            ->selectRaw('client_id, COUNT(*) AS total, SUM(CASE WHEN would_dispatch = 0 THEN 1 ELSE 0 END) AS rejected')
            ->groupBy('client_id')
            ->get()
            ->keyBy('client_id');

        $tenants = $clients->map(function (Client $c) use ($flagsByClient, $shadowCounts) {
            $flag  = $flagsByClient->get($c->id);
            $stat  = $shadowCounts->get($c->id);

            return [
                'client_id'       => (int) $c->id,
                'company_name'    => (string) $c->company_name,
                'pipeline_mode'   => $flag?->pipeline_mode ?? TenantFlag::MODE_LEGACY,
                'live_provider'   => $flag?->live_provider,
                'live_daily_cap'  => $flag?->live_daily_cap !== null ? (int) $flag->live_daily_cap : null,
                'live_enabled_at' => $flag?->live_enabled_at,
                'notes'           => $flag?->notes,
                'enabled_by'      => $flag?->enabled_by_user_id,
                'shadow_24h'      => $stat ? (int) $stat->total : 0,
                'rejected_24h'    => $stat ? (int) $stat->rejected : 0,
                'updated_at'      => $flag?->updated_at,
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'RVM cutover snapshot',
            'data'    => [
                'global_kill_switch' => (bool) config('rvm.use_new_pipeline', false),
                'tenants'            => $tenants,
            ],
        ]);
    }

    /**
     * GET /admin/rvm/cutover/{clientId}
     *
     * Drill-down view for one tenant: the flag row, aggregated 24h
     * reject-reason breakdown, and the last 50 shadow rows for ops to
     * spot-check.
     */
    public function show(int $clientId)
    {
        $client = Client::find($clientId);
        if (!$client) {
            return response()->json([
                'success' => false,
                'message' => "Client {$clientId} not found",
            ], 404);
        }

        $flag = TenantFlag::on('master')->find($clientId);
        $since = Carbon::now()->subHours(24);

        $breakdown = ShadowLog::on('master')
            ->where('client_id', $clientId)
            ->where('created_at', '>=', $since)
            ->selectRaw('would_reject_reason, COUNT(*) AS total')
            ->groupBy('would_reject_reason')
            ->orderByDesc('total')
            ->get()
            ->map(fn($r) => [
                'reason' => $r->would_reject_reason ?? 'would_dispatch',
                'total'  => (int) $r->total,
            ]);

        $providerBreakdown = ShadowLog::on('master')
            ->where('client_id', $clientId)
            ->where('created_at', '>=', $since)
            ->where('would_dispatch', 1)
            ->selectRaw('would_provider, COUNT(*) AS total')
            ->groupBy('would_provider')
            ->get()
            ->map(fn($r) => [
                'provider' => $r->would_provider ?? 'unknown',
                'total'    => (int) $r->total,
            ]);

        $recent = ShadowLog::on('master')
            ->where('client_id', $clientId)
            ->orderByDesc('id')
            ->limit(50)
            ->get([
                'id',
                'legacy_rvm_cdr_log_id',
                'phone_e164',
                'would_dispatch',
                'would_provider',
                'would_cost_cents',
                'would_reject_reason',
                'divergence_flags',
                'legacy_dispatched_at',
                'created_at',
            ]);

        return response()->json([
            'success' => true,
            'message' => 'RVM cutover detail',
            'data'    => [
                'client' => [
                    'id'           => (int) $client->id,
                    'company_name' => (string) $client->company_name,
                ],
                'flag'          => $flag,
                'breakdown_24h' => $breakdown,
                'providers_24h' => $providerBreakdown,
                'recent_shadow' => $recent,
            ],
        ]);
    }

    /**
     * POST /admin/rvm/cutover/{clientId}
     *
     * Body:
     *   {
     *     pipeline_mode:   legacy|shadow|dry_run|live  (required),
     *     notes?:          string (max 1000),
     *     live_provider?:  mock|twilio|plivo|slybroadcast  (required iff mode=live),
     *     live_daily_cap?: int (1..1000000),
     *   }
     *
     * Flips a tenant to a new mode + optionally writes the live-mode
     * columns + invalidates the Redis cache so the next SendRvmJob
     * attempt picks it up without a restart.
     *
     * When pipeline_mode=live, live_provider MUST be provided — the
     * divert service will otherwise refuse with reason=live_provider_not_set
     * on the very next row, which is a confusing operator experience.
     * We enforce it here instead.
     */
    public function setMode(Request $request, int $clientId)
    {
        $this->validate($request, [
            'pipeline_mode'  => 'required|string|in:' . implode(',', TenantFlag::ALL_MODES),
            'notes'          => 'nullable|string|max:1000',
            'live_provider'  => 'nullable|string|in:mock,twilio,plivo,slybroadcast',
            'live_daily_cap' => 'nullable|integer|min:1|max:1000000',
        ]);

        if ($request->input('pipeline_mode') === TenantFlag::MODE_LIVE
            && empty($request->input('live_provider'))) {
            return response()->json([
                'success' => false,
                'message' => 'live_provider is required when pipeline_mode = live',
            ], 422);
        }

        if (!Client::where('id', $clientId)->exists()) {
            return response()->json([
                'success' => false,
                'message' => "Client {$clientId} not found",
            ], 404);
        }

        $adminUserId = $request->auth->id ?? null;

        try {
            $flag = $this->flags->setTenantMode(
                clientId:         $clientId,
                mode:             $request->input('pipeline_mode'),
                enabledByUserId:  $adminUserId,
                notes:            $request->input('notes'),
                liveProvider:     $request->input('live_provider'),
                liveDailyCap:     $request->input('live_daily_cap') !== null
                    ? (int) $request->input('live_daily_cap')
                    : null,
                touchLiveColumns: true,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        Log::info('RVM cutover: tenant mode changed', [
            'client_id'      => $clientId,
            'new_mode'       => $flag->pipeline_mode,
            'live_provider'  => $flag->live_provider,
            'live_daily_cap' => $flag->live_daily_cap,
            'admin_user_id'  => $adminUserId,
            'notes'          => $flag->notes,
        ]);

        return response()->json([
            'success' => true,
            'message' => "Tenant {$clientId} set to {$flag->pipeline_mode}",
            'data'    => $flag,
        ]);
    }

    /**
     * POST /admin/rvm/cutover/{clientId}/check-readiness
     *
     * Thin HTTP wrapper around `php artisan rvm:check-live-ready --client=X --json`.
     * Returns the command's JSON verbatim plus the exit code, so the UI
     * can colour the overall banner without re-running the check.
     *
     * NB: we use Artisan::call() rather than Process::run() to avoid
     * spawning a second PHP process per request. The readiness check is
     * read-only and all of T1–T8 touch only the master DB, which is
     * always registered in the web process. T9 attempts `mysql_{clientId}`
     * and catches its own exceptions (it downgrades failures to 'warn'),
     * so worst-case the web process returns a warn instead of a pass.
     */
    public function checkReadiness(Request $request, int $clientId)
    {
        if (!Client::where('id', $clientId)->exists()) {
            return response()->json([
                'success' => false,
                'message' => "Client {$clientId} not found",
            ], 404);
        }

        $buf = new BufferedOutput();
        try {
            $exit = Artisan::call('rvm:check-live-ready', [
                '--client' => $clientId,
                '--json'   => true,
            ], $buf);
        } catch (Throwable $e) {
            Log::error('rvm:check-live-ready failed', [
                'client_id' => $clientId,
                'error'     => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Readiness check threw an exception: ' . $e->getMessage(),
                'data'    => ['error' => $e->getMessage()],
            ], 500);
        }

        $raw = trim($buf->fetch());
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            Log::warning('rvm:check-live-ready returned non-JSON', [
                'client_id' => $clientId,
                'exit_code' => $exit,
                'raw'       => $raw,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Readiness check returned malformed JSON',
                'data'    => ['raw' => $raw, 'exit_code' => $exit],
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Readiness check complete',
            'data'    => array_merge($decoded, ['exit_code' => $exit]),
        ]);
    }

    /**
     * GET /admin/rvm/dashboard?window=24h|7d
     *
     * Fleet-wide observability snapshot for the RVM v2 migration. Aggregates
     * across every tenant and returns everything an operator needs to watch
     * a wave cutover in one screen:
     *
     *   - kpis:              total rows / dispatched / rejected / cost
     *   - mode_distribution: tenant counts per pipeline_mode
     *   - provider_breakdown:would_provider histogram (dispatched only)
     *   - reject_reasons:    top reject reasons with counts + %
     *   - top_tenants:       top 10 tenants by shadow volume
     *   - hourly_buckets:    last 24 hours, one row per hour
     *
     * Deliberately read-only — every query hits rvm_shadow_log + the flags
     * table on master. No per-client connections needed.
     *
     * The "window" is clamped to either 24h or 7d to keep query cost
     * predictable; 7d on a busy fleet is already a ~O(millions) row scan.
     */
    public function dashboard(Request $request)
    {
        $window = $request->query('window', '24h');
        if (!in_array($window, ['24h', '7d'], true)) {
            $window = '24h';
        }

        $hours = $window === '7d' ? 24 * 7 : 24;
        $since = Carbon::now()->subHours($hours);

        // ── KPIs (one row) ─────────────────────────────────────────────────
        $totals = ShadowLog::on('master')
            ->where('created_at', '>=', $since)
            ->selectRaw('
                COUNT(*)                                                 AS total,
                SUM(CASE WHEN would_dispatch = 1 THEN 1 ELSE 0 END)      AS dispatched,
                SUM(CASE WHEN would_dispatch = 0 THEN 1 ELSE 0 END)      AS rejected,
                COALESCE(SUM(CASE WHEN would_dispatch = 1 THEN would_cost_cents ELSE 0 END), 0) AS cost_cents
            ')
            ->first();

        $total       = (int) ($totals->total       ?? 0);
        $dispatched  = (int) ($totals->dispatched  ?? 0);
        $rejected    = (int) ($totals->rejected    ?? 0);
        $costCents   = (int) ($totals->cost_cents  ?? 0);
        $rejectRate  = $total > 0 ? round($rejected / $total, 4) : 0.0;

        // ── Mode distribution ──────────────────────────────────────────────
        // Explicit modes come from the flags table. Everything else is
        // implicit legacy (no flag row means legacy default).
        $totalClients = (int) Client::where('is_deleted', 0)->count();

        $modeCounts = TenantFlag::on('master')
            ->selectRaw('pipeline_mode, COUNT(*) AS total')
            ->groupBy('pipeline_mode')
            ->pluck('total', 'pipeline_mode')
            ->all();

        $explicitTotal = array_sum($modeCounts);
        $implicitLegacy = max(0, $totalClients - $explicitTotal);

        $modeDistribution = [
            'legacy'  => (int) ($modeCounts['legacy']  ?? 0) + $implicitLegacy,
            'shadow'  => (int) ($modeCounts['shadow']  ?? 0),
            'dry_run' => (int) ($modeCounts['dry_run'] ?? 0),
            'live'    => (int) ($modeCounts['live']    ?? 0),
        ];

        // ── Provider breakdown (dispatched only) ───────────────────────────
        $providerBreakdown = ShadowLog::on('master')
            ->where('created_at', '>=', $since)
            ->where('would_dispatch', 1)
            ->selectRaw('
                would_provider,
                COUNT(*) AS total,
                COALESCE(SUM(would_cost_cents), 0) AS cost_cents
            ')
            ->groupBy('would_provider')
            ->orderByDesc('total')
            ->get()
            ->map(fn($r) => [
                'provider'   => $r->would_provider ?? 'unknown',
                'count'      => (int) $r->total,
                'cost_cents' => (int) $r->cost_cents,
            ])
            ->values();

        // ── Reject reason histogram ────────────────────────────────────────
        $rejectReasons = ShadowLog::on('master')
            ->where('created_at', '>=', $since)
            ->where('would_dispatch', 0)
            ->selectRaw('would_reject_reason, COUNT(*) AS total')
            ->groupBy('would_reject_reason')
            ->orderByDesc('total')
            ->limit(15)
            ->get()
            ->map(fn($r) => [
                'reason' => $r->would_reject_reason ?? 'unknown',
                'total'  => (int) $r->total,
                'pct'    => $rejected > 0
                    ? round(((int) $r->total / $rejected) * 100, 1)
                    : 0.0,
            ])
            ->values();

        // ── Top 10 tenants by shadow volume ────────────────────────────────
        $topTenants = ShadowLog::on('master')
            ->where('created_at', '>=', $since)
            ->selectRaw('
                client_id,
                COUNT(*) AS total,
                SUM(CASE WHEN would_dispatch = 0 THEN 1 ELSE 0 END) AS rejected
            ')
            ->groupBy('client_id')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        $clientNames = Client::whereIn('id', $topTenants->pluck('client_id')->all())
            ->pluck('company_name', 'id')
            ->all();

        $flagModes = TenantFlag::on('master')
            ->whereIn('client_id', $topTenants->pluck('client_id')->all())
            ->pluck('pipeline_mode', 'client_id')
            ->all();

        $topTenants = $topTenants->map(function ($row) use ($clientNames, $flagModes) {
            $total    = (int) $row->total;
            $rejected = (int) $row->rejected;
            return [
                'client_id'     => (int) $row->client_id,
                'company_name'  => (string) ($clientNames[$row->client_id] ?? "Client #{$row->client_id}"),
                'pipeline_mode' => (string) ($flagModes[$row->client_id] ?? TenantFlag::MODE_LEGACY),
                'total'         => $total,
                'rejected'      => $rejected,
                'rejection_rate'=> $total > 0 ? round($rejected / $total, 4) : 0.0,
            ];
        })->values();

        // ── Hourly buckets (always last 24h regardless of $window) ────────
        // Hourly granularity over 7d is too noisy for the UI — we always
        // show the last-24h timeline.
        $hourlySince = Carbon::now()->subHours(24);
        $hourlyRows = ShadowLog::on('master')
            ->where('created_at', '>=', $hourlySince)
            ->selectRaw("
                DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') AS bucket,
                COUNT(*) AS total,
                SUM(CASE WHEN would_dispatch = 1 THEN 1 ELSE 0 END) AS dispatched,
                SUM(CASE WHEN would_dispatch = 0 THEN 1 ELSE 0 END) AS rejected
            ")
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get()
            ->keyBy('bucket');

        // Densify — fill in the zero hours so the UI can render a
        // contiguous 24-bar timeline without gaps.
        $hourlyBuckets = [];
        for ($i = 23; $i >= 0; $i--) {
            $ts  = Carbon::now()->subHours($i)->startOfHour();
            $key = $ts->format('Y-m-d H:i:s');
            $row = $hourlyRows->get($key);
            $hourlyBuckets[] = [
                'hour'       => $key,
                'total'      => $row ? (int) $row->total      : 0,
                'dispatched' => $row ? (int) $row->dispatched : 0,
                'rejected'   => $row ? (int) $row->rejected   : 0,
            ];
        }

        return response()->json([
            'success' => true,
            'message' => 'RVM dashboard snapshot',
            'data'    => [
                'window'             => $window,
                'generated_at'       => Carbon::now()->toIso8601String(),
                'global_kill_switch' => (bool) config('rvm.use_new_pipeline', false),
                'kpis' => [
                    'total'           => $total,
                    'dispatched'      => $dispatched,
                    'rejected'        => $rejected,
                    'rejection_rate'  => $rejectRate,
                    'total_cost_cents'=> $costCents,
                    'tenant_count'    => $totalClients,
                ],
                'mode_distribution'  => $modeDistribution,
                'provider_breakdown' => $providerBreakdown,
                'reject_reasons'     => $rejectReasons,
                'top_tenants'        => $topTenants,
                'hourly_buckets'     => $hourlyBuckets,
            ],
        ]);
    }

    /**
     * GET /admin/rvm/cutover/{clientId}/history
     *
     * Returns recent audit-log rows relevant to this tenant so operators
     * can see who flipped the mode when (and what the payload was). The
     * audit_log table is populated by the 'audit.log' middleware for
     * every mutating admin request — we just filter + decorate it.
     *
     * Three path patterns match for a single tenant:
     *
     *   1. admin/rvm/cutover/{clientId}                 → set_mode
     *   2. admin/rvm/cutover/{clientId}/check-readiness → check_readiness
     *   3. admin/rvm/cutover/rollback-all               → rollback_all (global,
     *      still shown on the per-tenant page because it affected this tenant
     *      if it was non-legacy at the time)
     *
     * Note: audit_log.client_id stores the *acting user's* parent_id, not
     * the target of the admin action. We therefore filter by path, not by
     * client_id. The (user_id, created_at) index is the fallback here;
     * the LIKE scan is bounded by limit + ordering to keep cost low.
     */
    public function history(Request $request, int $clientId)
    {
        if (!Client::where('id', $clientId)->exists()) {
            return response()->json([
                'success' => false,
                'message' => "Client {$clientId} not found",
            ], 404);
        }

        $exactPath       = 'admin/rvm/cutover/' . $clientId;
        $readinessPath   = 'admin/rvm/cutover/' . $clientId . '/check-readiness';
        $rollbackAllPath = 'admin/rvm/cutover/rollback-all';

        $rows = AuditLog::on('master')
            ->whereIn('path', [$exactPath, $readinessPath, $rollbackAllPath])
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        // Decorate with actor name/email via one extra query.
        $userIds = $rows->pluck('user_id')->filter()->unique()->values()->all();
        $users = empty($userIds)
            ? collect()
            : DB::connection('master')
                ->table('users')
                ->whereIn('id', $userIds)
                ->get(['id', 'first_name', 'last_name', 'email'])
                ->keyBy('id');

        $history = $rows->map(function (AuditLog $row) use ($users, $exactPath, $readinessPath) {
            $user = $users->get($row->user_id);
            $fullName = $user
                ? trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''))
                : '';

            // Classify for the UI.
            if ($row->path === $exactPath) {
                $actionType = 'set_mode';
            } elseif ($row->path === $readinessPath) {
                $actionType = 'check_readiness';
            } else {
                $actionType = 'rollback_all';
            }

            return [
                'id'          => (int) $row->id,
                'created_at'  => $row->created_at,
                'user_id'     => (int) $row->user_id,
                'actor_name'  => $fullName !== '' ? $fullName : null,
                'actor_email' => $user->email ?? null,
                'method'      => (string) $row->method,
                'path'        => (string) $row->path,
                'action_type' => $actionType,
                'payload'     => $row->payload, // already array-cast
                'ip'          => (string) $row->ip,
            ];
        });

        // ── CSV export branch ─────────────────────────────────────────
        // ?format=csv returns a text/csv file download instead of the
        // JSON shape. The column set is intentionally flat — no nested
        // payload object — so it pastes cleanly into a spreadsheet.
        if (strtolower((string) $request->query('format')) === 'csv') {
            $filename = sprintf(
                'rvm-cutover-history-client-%d-%s.csv',
                $clientId,
                date('Ymd-His')
            );

            $callback = function () use ($history) {
                $out = fopen('php://output', 'w');
                fputcsv($out, ['when', 'actor', 'email', 'action', 'method', 'path', 'ip', 'payload']);
                foreach ($history as $row) {
                    fputcsv($out, [
                        (string) ($row['created_at'] ?? ''),
                        (string) ($row['actor_name']  ?? ''),
                        (string) ($row['actor_email'] ?? ''),
                        (string) $row['action_type'],
                        (string) $row['method'],
                        (string) $row['path'],
                        (string) $row['ip'],
                        $row['payload'] ? json_encode($row['payload']) : '',
                    ]);
                }
                fclose($out);
            };

            return response()->stream($callback, 200, [
                'Content-Type'        => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Cache-Control'       => 'no-store',
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'RVM cutover change history',
            'data'    => [
                'client_id' => $clientId,
                'history'   => $history,
            ],
        ]);
    }

    /**
     * POST /admin/rvm/cutover/rollback-all
     *
     * Emergency "everything back to legacy" — used if the new pipeline
     * is misbehaving during a wave cutover. Writes legacy to every non-
     * legacy tenant flag row in ONE statement and flushes caches.
     *
     * Idempotent: calling it twice leaves the system in the same state.
     * Returns the list of tenant ids that were actually changed so ops
     * have an audit trail.
     */
    public function rollbackAll(Request $request)
    {
        $affected = [];

        try {
            // Snapshot the rows we're about to flip so we can report them
            // and invalidate their cache entries afterwards.
            $nonLegacy = TenantFlag::on('master')
                ->where('pipeline_mode', '!=', TenantFlag::MODE_LEGACY)
                ->get(['client_id', 'pipeline_mode']);

            $affected = $nonLegacy->map(fn($f) => [
                'client_id'     => (int) $f->client_id,
                'previous_mode' => $f->pipeline_mode,
            ])->values()->all();

            if (!empty($affected)) {
                DB::connection('master')->transaction(function () use ($nonLegacy, $request) {
                    TenantFlag::on('master')
                        ->where('pipeline_mode', '!=', TenantFlag::MODE_LEGACY)
                        ->update([
                            'pipeline_mode'      => TenantFlag::MODE_LEGACY,
                            'enabled_by_user_id' => $request->auth->id ?? null,
                            'notes'              => 'emergency rollback: ' . Carbon::now()->toIso8601String(),
                        ]);
                });

                // Flush caches one by one — safer than a wildcard delete.
                foreach ($nonLegacy as $f) {
                    $this->flags->flushTenant((int) $f->client_id);
                }
            }

            Log::warning('RVM cutover: emergency rollback executed', [
                'admin_user_id'  => $request->auth->id ?? null,
                'tenant_count'   => count($affected),
                'affected'       => $affected,
            ]);
        } catch (Throwable $e) {
            Log::error('RVM cutover rollback failed', [
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Rollback failed: ' . $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => count($affected) > 0
                ? 'Rolled back ' . count($affected) . ' tenant(s) to legacy'
                : 'No tenants to roll back — all already on legacy',
            'data'    => [
                'affected_count' => count($affected),
                'affected'       => $affected,
            ],
        ]);
    }
}
