<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use App\Services\LeadVisibilityService;

/**
 * @OA\Get(
 *   path="/crm/analytics/status-distribution",
 *   summary="Lead status distribution analytics",
 *   operationId="crmAnalyticsStatusDistribution",
 *   tags={"CRM Analytics"},
 *   security={{"Bearer":{}}},
 *   @OA\Response(response=200, description="Status distribution"),
 *   @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Get(
 *   path="/crm/analytics/lead-velocity",
 *   summary="Lead velocity analytics (how fast leads move through pipeline)",
 *   operationId="crmAnalyticsLeadVelocity",
 *   tags={"CRM Analytics"},
 *   security={{"Bearer":{}}},
 *   @OA\Response(response=200, description="Lead velocity data")
 * )
 *
 * @OA\Get(
 *   path="/crm/analytics/agent-performance",
 *   summary="Agent performance analytics in CRM",
 *   operationId="crmAnalyticsAgentPerformance",
 *   tags={"CRM Analytics"},
 *   security={{"Bearer":{}}},
 *   @OA\Response(response=200, description="Agent performance data")
 * )
 *
 * @OA\Get(
 *   path="/crm/analytics/conversion-funnel",
 *   summary="CRM conversion funnel analytics",
 *   operationId="crmAnalyticsConversionFunnel",
 *   tags={"CRM Analytics"},
 *   security={{"Bearer":{}}},
 *   @OA\Response(response=200, description="Conversion funnel data")
 * )
 *
 * @OA\Get(
 *   path="/crm/analytics/lender-performance",
 *   summary="Lender performance analytics",
 *   operationId="crmAnalyticsLenderPerformance",
 *   tags={"CRM Analytics"},
 *   security={{"Bearer":{}}},
 *   @OA\Response(response=200, description="Lender performance data")
 * )
 */
class CrmAnalyticsController extends Controller
{
    /**
     * Build a visibility-aware cache key suffix.
     * Admins share a common cache; restricted users get user-specific caches.
     */
    private function visibilityCacheSuffix(Request $request): string
    {
        $vis = new LeadVisibilityService();
        $clientId = $request->auth->parent_id;
        if ($vis->hasFullAccess($request->auth, (int) $clientId)) {
            return '';
        }
        return '_u' . $request->auth->id;
    }

    /**
     * Apply lead visibility scope to a query on crm_lead_data (or aliased table).
     */
    private function applyVisibility($query, Request $request, string $alias = ''): void
    {
        (new LeadVisibilityService())->applyVisibilityScope(
            $query, $request->auth, (int) $request->auth->parent_id, $alias
        );
    }

    private function period(Request $request): array
    {
        $period = $request->input('period', 'month');
        $start  = $request->input('start_date');
        $end    = $request->input('end_date');

        if (!$start || !$end) {
            $userTz = $request->auth->timezone ?? APP_DEFAULT_USER_TIMEZONE;
            $now   = Carbon::now($userTz);
            $end   = $now->toDateString();
            $start = match ($period) {
                'today'  => $now->toDateString(),
                'week'   => $now->copy()->subDays(6)->toDateString(),
                'month'  => $now->copy()->subDays(29)->toDateString(),
                'quarter'=> $now->copy()->subDays(89)->toDateString(),
                default  => $now->copy()->subDays(29)->toDateString(),
            };
        }
        return [$start, $end];
    }

    /**
     * GET /crm/analytics/status-distribution
     * Current pipeline stage distribution for ALL active leads (no date filter).
     * The period param is used only to label the response.
     */
    public function statusDistribution(Request $request)
    {
        try {
            $clientId = $request->auth->parent_id;
            $suffix   = $this->visibilityCacheSuffix($request);
            $cacheKey = "analytics_{$clientId}_status_dist_current{$suffix}";
            $auth     = $request->auth;

            $data = Cache::remember($cacheKey, 120, function () use ($clientId, $auth) {
                $statuses = DB::connection("mysql_$clientId")
                    ->table('crm_lead_status')
                    ->where('status', '1')
                    ->orderBy('display_order')
                    ->get(['title', 'lead_title_url', 'color_code']);

                $totalQ = DB::connection("mysql_$clientId")->table('crm_lead_data')->where('is_deleted', 0);
                (new LeadVisibilityService())->applyVisibilityScope($totalQ, $auth, (int) $clientId);
                $total = $totalQ->count();

                $countsQ = DB::connection("mysql_$clientId")->table('crm_lead_data')->where('is_deleted', 0)
                    ->select('lead_status', DB::raw('COUNT(*) as count'))->groupBy('lead_status');
                (new LeadVisibilityService())->applyVisibilityScope($countsQ, $auth, (int) $clientId);
                $counts = $countsQ->pluck('count', 'lead_status')->toArray();

                $result = [];
                foreach ($statuses as $s) {
                    $count = $counts[$s->lead_title_url] ?? 0;
                    if ($count === 0) continue;
                    $result[] = [
                        'status'     => $s->lead_title_url,
                        'title'      => $s->title,
                        'color_code' => $s->color_code,
                        'count'      => $count,
                        'percentage' => $total > 0 ? round(($count / $total) * 100, 1) : 0,
                    ];
                }

                foreach ($counts as $slug => $cnt) {
                    $alreadyIn = collect($result)->firstWhere('status', $slug);
                    if (!$alreadyIn && $cnt > 0) {
                        $result[] = [
                            'status'     => $slug,
                            'title'      => ucwords(str_replace(['_', '-'], ' ', $slug)),
                            'color_code' => '#6B7280',
                            'count'      => $cnt,
                            'percentage' => $total > 0 ? round(($cnt / $total) * 100, 1) : 0,
                        ];
                    }
                }

                usort($result, fn($a, $b) => $b['count'] - $a['count']);
                return ['total' => $total, 'distribution' => $result];
            });

            return $this->successResponse("Status Distribution", $data);
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to load status distribution", [$e->getMessage()], $e, 500);
        }
    }

    /**
     * GET /crm/analytics/lead-velocity
     * New leads per day over the period.
     */
    public function leadVelocity(Request $request)
    {
        try {
            $clientId = $request->auth->parent_id;
            [$start, $end] = $this->period($request);
            $suffix   = $this->visibilityCacheSuffix($request);
            $cacheKey = "analytics_{$clientId}_velocity_{$start}_{$end}{$suffix}";
            $auth     = $request->auth;

            $data = Cache::remember($cacheKey, 300, function () use ($clientId, $start, $end, $auth) {
                $daily = DB::connection("mysql_$clientId")
                    ->table('crm_lead_data')
                    ->where('is_deleted', 0)
                    ->whereBetween(DB::raw('DATE(created_at)'), [$start, $end]);
                (new LeadVisibilityService())->applyVisibilityScope($daily, $auth, (int) $clientId);
                $daily = $daily->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as new_leads'))
                    ->groupBy(DB::raw('DATE(created_at)'))
                    ->orderBy('date')
                    ->get();

                if ($daily->isEmpty()) {
                    $latestQ = DB::connection("mysql_$clientId")->table('crm_lead_data')->where('is_deleted', 0);
                    (new LeadVisibilityService())->applyVisibilityScope($latestQ, $auth, (int) $clientId);
                    $latestDate = $latestQ->max(DB::raw('DATE(created_at)'));

                    if ($latestDate) {
                        $fallbackEnd   = $latestDate;
                        $fallbackStart = date('Y-m-d', strtotime($latestDate . ' -179 days'));

                        $daily = DB::connection("mysql_$clientId")
                            ->table('crm_lead_data')
                            ->where('is_deleted', 0)
                            ->whereBetween(DB::raw('DATE(created_at)'), [$fallbackStart, $fallbackEnd]);
                        (new LeadVisibilityService())->applyVisibilityScope($daily, $auth, (int) $clientId);
                        $daily = $daily->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as new_leads'))
                            ->groupBy(DB::raw('DATE(created_at)'))
                            ->orderBy('date')
                            ->get();
                    }
                }

                $total  = $daily->sum('new_leads');
                $days   = max($daily->count(), 1);
                $avgDay = round($total / $days, 1);

                return [
                    'period'      => ['start' => $start, 'end' => $end],
                    'total_leads' => $total,
                    'avg_per_day' => $avgDay,
                    'daily'       => $daily,
                ];
            });

            return $this->successResponse("Lead Velocity", $data);
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to load lead velocity", [$e->getMessage()], $e, 500);
        }
    }

    /**
     * GET /crm/analytics/agent-performance
     * Leads per agent with status breakdown — ALL active leads (no date filter).
     */
    public function agentPerformance(Request $request)
    {
        try {
            $clientId = $request->auth->parent_id;
            $agentIds = $request->input('agent_ids', []);
            $suffix   = $this->visibilityCacheSuffix($request);
            $cacheKey = "analytics_{$clientId}_agent_perf_current_{$suffix}_" . md5(implode(',', $agentIds));
            $auth     = $request->auth;

            $data = Cache::remember($cacheKey, 120, function () use ($clientId, $agentIds, $auth) {
                $query = DB::connection("mysql_$clientId")
                    ->table('crm_lead_data')
                    ->where('is_deleted', 0);
                (new LeadVisibilityService())->applyVisibilityScope($query, $auth, (int) $clientId);
                $query->select('assigned_to', 'lead_status', DB::raw('COUNT(*) as count'))
                    ->groupBy('assigned_to', 'lead_status')
                    ->orderBy('assigned_to');

                if (!empty($agentIds)) {
                    $query->whereIn('assigned_to', $agentIds);
                }

                $rows = $query->get();

                // Group by agent
                $agents = [];
                foreach ($rows as $row) {
                    if (!$row->assigned_to) continue;
                    if (!isset($agents[$row->assigned_to])) {
                        $agents[$row->assigned_to] = [
                            'user_id'    => $row->assigned_to,
                            'total'      => 0,
                            'by_status'  => [],
                        ];
                    }
                    $agents[$row->assigned_to]['total']                    += $row->count;
                    $agents[$row->assigned_to]['by_status'][$row->lead_status] = $row->count;
                }

                // Attach user names
                $userIds   = array_keys($agents);
                $users     = \App\Model\User::whereIn('id', $userIds)->where('parent_id', $clientId)->get(['id', 'first_name', 'last_name'])->keyBy('id');
                $result    = [];
                foreach ($agents as $uid => $agentData) {
                    $agentData['user_name'] = isset($users[$uid])
                        ? $users[$uid]->first_name . ' ' . $users[$uid]->last_name : "User #$uid";
                    $result[] = $agentData;
                }

                usort($result, fn($a, $b) => $b['total'] - $a['total']);
                return $result;
            });

            return $this->successResponse("Agent Performance", $data);
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to load agent performance", [$e->getMessage()], $e, 500);
        }
    }

    /**
     * GET /crm/analytics/conversion-funnel
     * Current lead count at each pipeline stage (ordered by display_order).
     * Uses crm_lead_data current status — no date filter.
     */
    public function conversionFunnel(Request $request)
    {
        try {
            $clientId = $request->auth->parent_id;
            $suffix   = $this->visibilityCacheSuffix($request);
            $cacheKey = "analytics_{$clientId}_funnel_current{$suffix}";
            $auth     = $request->auth;

            $data = Cache::remember($cacheKey, 120, function () use ($clientId, $auth) {
                $stages = DB::connection("mysql_$clientId")
                    ->table('crm_lead_status')
                    ->where('status', '1')
                    ->orderBy('display_order')
                    ->pluck('title', 'lead_title_url');

                $countsQ = DB::connection("mysql_$clientId")
                    ->table('crm_lead_data')
                    ->where('is_deleted', 0);
                (new LeadVisibilityService())->applyVisibilityScope($countsQ, $auth, (int) $clientId);
                $counts = $countsQ->select('lead_status', DB::raw('COUNT(*) as count'))
                    ->groupBy('lead_status')
                    ->pluck('count', 'lead_status')
                    ->toArray();

                $funnel = [];
                foreach ($stages as $slug => $title) {
                    $count = $counts[$slug] ?? 0;
                    if ($count > 0) {
                        $funnel[] = ['status' => $slug, 'title' => $title, 'count' => $count];
                    }
                }

                // Use total as base for percentage of each stage
                $total = array_sum(array_column($funnel, 'count'));
                foreach ($funnel as &$stage) {
                    $stage['conversion_from_previous'] = $total > 0
                        ? round(($stage['count'] / $total) * 100, 1)
                        : 0;
                }
                unset($stage);

                return ['funnel' => $funnel, 'total' => $total];
            });

            return $this->successResponse("Conversion Funnel", $data);
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to load conversion funnel", [$e->getMessage()], $e, 500);
        }
    }

    /**
     * GET /crm/analytics/lender-performance
     * Submissions per lender with response breakdown.
     */
    public function lenderPerformance(Request $request)
    {
        try {
            $clientId = $request->auth->parent_id;
            [$start, $end] = $this->period($request);
            $suffix   = $this->visibilityCacheSuffix($request);
            $cacheKey = "analytics_{$clientId}_lender_perf_{$start}_{$end}{$suffix}";
            $auth     = $request->auth;

            $data = Cache::remember($cacheKey, 300, function () use ($clientId, $start, $end, $auth) {
                $db = DB::connection("mysql_$clientId");

                // Build visible lead IDs subquery for restricted users
                $visScope = (new LeadVisibilityService())->buildVisibilityScope($auth, (int) $clientId);
                $visibleLeadIds = null;
                if ($visScope !== null) {
                    $visibleLeadIds = $db->table('crm_lead_data')
                        ->where('is_deleted', 0)
                        ->whereRaw($visScope['condition'], $visScope['bindings'])
                        ->pluck('id')
                        ->toArray();
                }

                // Build a status-id → title map
                $statusMap = [];
                try {
                    $statuses = $db->table('crm_lender_status')
                        ->select('id', 'title')
                        ->get();
                    foreach ($statuses as $s) {
                        $statusMap[(string) $s->id] = $s->title;
                    }
                } catch (\Throwable $e) {
                    // table may not exist yet
                }

                $submissionsQ = $db
                    ->table('crm_send_lead_to_lender_record as r')
                    ->join('crm_lender as l', 'l.id', '=', DB::raw('CAST(r.lender_id AS UNSIGNED)'))
                    ->whereBetween(DB::raw('DATE(r.created_at)'), [$start, $end]);
                if ($visibleLeadIds !== null) {
                    $submissionsQ->whereIn('r.lead_id', $visibleLeadIds);
                }
                $submissions = $submissionsQ->select(
                        'r.lender_id',
                        'l.lender_name',
                        DB::raw('COUNT(*) as total_submissions'),
                        'r.lender_status_id'
                    )
                    ->groupBy('r.lender_id', 'l.lender_name', 'r.lender_status_id')
                    ->get();

                // Count funded deals per lender in the same period
                $fundedCounts = [];
                try {
                    $fundedQ = $db->table('crm_funded_deals')
                        ->whereIn('status', ['funded', 'in_repayment', 'paid_off', 'renewed'])
                        ->whereBetween(DB::raw('DATE(COALESCE(funding_date, created_at))'), [$start, $end]);
                    if ($visibleLeadIds !== null) {
                        $fundedQ->whereIn('lead_id', $visibleLeadIds);
                    }
                    $funded = $fundedQ
                        ->select('lender_id', DB::raw('COUNT(*) as cnt'), DB::raw('SUM(funded_amount) as total_amount'))
                        ->groupBy('lender_id')
                        ->get();
                    foreach ($funded as $f) {
                        $fundedCounts[(string) $f->lender_id] = [
                            'count'  => (int) $f->cnt,
                            'amount' => (float) $f->total_amount,
                        ];
                    }
                } catch (\Throwable $e) {
                    // table may not exist yet
                }

                $grouped = [];
                foreach ($submissions as $row) {
                    if (!isset($grouped[$row->lender_id])) {
                        $grouped[$row->lender_id] = [
                            'lender_id'         => $row->lender_id,
                            'lender_name'       => $row->lender_name,
                            'total_submissions' => 0,
                            'by_status'         => [],
                        ];
                    }
                    $grouped[$row->lender_id]['total_submissions'] += $row->total_submissions;

                    $statusKey = $row->lender_status_id ?? 'pending';
                    $statusTitle = $statusMap[$statusKey] ?? $statusKey;
                    $grouped[$row->lender_id]['by_status'][$statusTitle] = ($grouped[$row->lender_id]['by_status'][$statusTitle] ?? 0) + $row->total_submissions;
                }

                // Compute approval / funding rates
                foreach ($grouped as &$lender) {
                    $total = $lender['total_submissions'];
                    $approved = 0;
                    $declined = 0;
                    foreach ($lender['by_status'] as $title => $count) {
                        $lower = strtolower($title);
                        if (str_contains($lower, 'approv') || str_contains($lower, 'accept')) {
                            $approved += $count;
                        }
                        if (str_contains($lower, 'declin') || str_contains($lower, 'reject') || str_contains($lower, 'denied')) {
                            $declined += $count;
                        }
                    }

                    $fc = $fundedCounts[(string) $lender['lender_id']] ?? ['count' => 0, 'amount' => 0];
                    $lender['total_approved']  = $approved;
                    $lender['total_declined']  = $declined;
                    $lender['total_funded']    = $fc['count'];
                    $lender['funded_amount']   = $fc['amount'];
                    $lender['approval_rate']   = $total > 0 ? round($approved / $total * 100, 1) : 0;
                    $lender['funding_rate']    = $total > 0 ? round($fc['count'] / $total * 100, 1) : 0;
                }
                unset($lender);

                // Sort by total_submissions desc
                usort($grouped, fn($a, $b) => $b['total_submissions'] <=> $a['total_submissions']);

                return array_values($grouped);
            });

            return $this->successResponse("Lender Performance", $data);
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to load lender performance", [$e->getMessage()], $e, 500);
        }
    }

    /**
     * GET /crm/analytics/revenue-trend
     * Monthly funded amounts for the last 12 months.
     */
    public function revenueTrend(Request $request)
    {
        try {
            $clientId = $request->auth->parent_id;
            $suffix   = $this->visibilityCacheSuffix($request);
            $cacheKey = "analytics_{$clientId}_revenue_trend_12m{$suffix}";
            $auth     = $request->auth;

            $data = Cache::remember($cacheKey, 600, function () use ($clientId, $auth) {
                // Build visible lead IDs for restricted users
                $visScope = (new LeadVisibilityService())->buildVisibilityScope($auth, (int) $clientId);
                $visibleLeadIds = null;
                if ($visScope !== null) {
                    $visibleLeadIds = DB::connection("mysql_$clientId")->table('crm_lead_data')
                        ->where('is_deleted', 0)
                        ->whereRaw($visScope['condition'], $visScope['bindings'])
                        ->pluck('id')->toArray();
                }

                $q = DB::connection("mysql_$clientId")
                    ->table('crm_funded_deals')
                    ->whereIn('status', ['funded', 'in_repayment', 'paid_off', 'renewed'])
                    ->where('created_at', '>=', Carbon::now()->subMonths(12)->startOfMonth());
                if ($visibleLeadIds !== null) {
                    $q->whereIn('lead_id', $visibleLeadIds);
                }
                $rows = $q
                    ->select(
                        DB::raw("DATE_FORMAT(COALESCE(funding_date, created_at), '%Y-%m') as month"),
                        DB::raw('SUM(funded_amount) as total_funded'),
                        DB::raw('COUNT(*) as deal_count'),
                        DB::raw('AVG(funded_amount) as avg_deal_size')
                    )
                    ->groupBy(DB::raw("DATE_FORMAT(COALESCE(funding_date, created_at), '%Y-%m')"))
                    ->orderBy('month')
                    ->get()
                    ->keyBy('month');

                $months = [];
                for ($i = 11; $i >= 0; $i--) {
                    $dt  = Carbon::now()->subMonths($i);
                    $key = $dt->format('Y-m');
                    $row = $rows[$key] ?? null;
                    $months[] = [
                        'month'         => $key,
                        'label'         => $dt->format('M Y'),
                        'total_funded'  => $row ? (float) $row->total_funded  : 0,
                        'deal_count'    => $row ? (int)   $row->deal_count    : 0,
                        'avg_deal_size' => $row ? (float) $row->avg_deal_size : 0,
                    ];
                }

                $totalAnnual = array_sum(array_column($months, 'total_funded'));
                return [
                    'trend'        => $months,
                    'total_annual' => $totalAnnual,
                    'avg_monthly'  => round($totalAnnual / 12, 2),
                ];
            });

            return $this->successResponse("Revenue Trend", $data);
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to load revenue trend", [$e->getMessage()], $e, 500);
        }
    }

    /**
     * GET /crm/analytics/pipeline-velocity
     * Average days leads spend in each pipeline stage (estimated from updated_at).
     */
    public function pipelineVelocity(Request $request)
    {
        try {
            $clientId = $request->auth->parent_id;
            [$start, $end] = $this->period($request);
            $suffix   = $this->visibilityCacheSuffix($request);
            $cacheKey = "analytics_{$clientId}_pipeline_velocity_{$start}_{$end}{$suffix}";
            $auth     = $request->auth;

            $data = Cache::remember($cacheKey, 300, function () use ($clientId, $start, $end, $auth) {
                $q = DB::connection("mysql_$clientId")
                    ->table('crm_lead_data as ld')
                    ->join('crm_lead_status as s', 's.lead_title_url', '=', 'ld.lead_status')
                    ->where('ld.is_deleted', 0)
                    ->whereBetween(DB::raw('DATE(ld.created_at)'), [$start, $end]);
                (new LeadVisibilityService())->applyVisibilityScope($q, $auth, (int) $clientId, 'ld');
                $rows = $q
                    ->select(
                        'ld.lead_status as status_slug',
                        's.title as status_name',
                        's.color_code',
                        's.display_order',
                        DB::raw('COUNT(*) as lead_count'),
                        DB::raw('AVG(GREATEST(0, DATEDIFF(ld.updated_at, ld.created_at))) as avg_days')
                    )
                    ->groupBy('ld.lead_status', 's.title', 's.color_code', 's.display_order')
                    ->orderBy('s.display_order')
                    ->get();

                $stages = $rows->map(fn($r) => [
                    'status_slug' => $r->status_slug,
                    'status_name' => $r->status_name,
                    'color'       => $r->color_code,
                    'lead_count'  => (int)   $r->lead_count,
                    'avg_days'    => round((float) $r->avg_days, 1),
                ])->values()->toArray();

                $maxDays    = !empty($stages) ? max(array_column($stages, 'avg_days')) : 0;
                $bottleneck = null;
                if ($maxDays > 0) {
                    foreach ($stages as $s) {
                        if ((float)$s['avg_days'] === (float)$maxDays) { $bottleneck = $s; break; }
                    }
                }

                return ['stages' => $stages, 'max_days' => $maxDays, 'bottleneck' => $bottleneck];
            });

            return $this->successResponse("Pipeline Velocity", $data);
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to load pipeline velocity", [$e->getMessage()], $e, 500);
        }
    }

    /**
     * GET /crm/analytics/deal-quality
     * Portfolio health: default rate, renewal rate, avg time-to-fund.
     */
    public function dealQuality(Request $request)
    {
        try {
            $clientId = $request->auth->parent_id;
            [$start, $end] = $this->period($request);
            $suffix   = $this->visibilityCacheSuffix($request);
            $cacheKey = "analytics_{$clientId}_deal_quality_{$start}_{$end}{$suffix}";
            $auth     = $request->auth;

            $data = Cache::remember($cacheKey, 300, function () use ($clientId, $start, $end, $auth) {
                // Build visible lead IDs for restricted users
                $visScope = (new LeadVisibilityService())->buildVisibilityScope($auth, (int) $clientId);
                $visibleLeadIds = null;
                if ($visScope !== null) {
                    $visibleLeadIds = DB::connection("mysql_$clientId")->table('crm_lead_data')
                        ->where('is_deleted', 0)
                        ->whereRaw($visScope['condition'], $visScope['bindings'])
                        ->pluck('id')->toArray();
                }

                $dealQ = DB::connection("mysql_$clientId")
                    ->table('crm_funded_deals')
                    ->whereBetween(DB::raw('DATE(created_at)'), [$start, $end]);
                if ($visibleLeadIds !== null) {
                    $dealQ->whereIn('lead_id', $visibleLeadIds);
                }
                $deal = $dealQ
                    ->select(
                        DB::raw('COUNT(*) as total_deals'),
                        DB::raw("SUM(CASE WHEN status = 'defaulted'             THEN 1 ELSE 0 END) as defaulted"),
                        DB::raw("SUM(CASE WHEN status IN ('paid_off','renewed') THEN 1 ELSE 0 END) as completed"),
                        DB::raw("SUM(CASE WHEN status = 'renewed'               THEN 1 ELSE 0 END) as renewed"),
                        DB::raw('AVG(funded_amount) as avg_deal_size'),
                        DB::raw('AVG(factor_rate)   as avg_factor_rate'),
                        DB::raw('AVG(GREATEST(0, DATEDIFF(COALESCE(funding_date, created_at), created_at))) as avg_days_to_fund')
                    )
                    ->first();

                $total     = (int) ($deal->total_deals ?? 0);
                $defaulted = (int) ($deal->defaulted   ?? 0);
                $completed = (int) ($deal->completed   ?? 0);
                $renewed   = (int) ($deal->renewed     ?? 0);

                return [
                    'total_deals'      => $total,
                    'defaulted'        => $defaulted,
                    'completed'        => $completed,
                    'renewed'          => $renewed,
                    'default_rate'     => $total     > 0 ? round(($defaulted / $total)     * 100, 1) : 0,
                    'renewal_rate'     => $completed > 0 ? round(($renewed   / $completed) * 100, 1) : 0,
                    'avg_deal_size'    => round((float)($deal->avg_deal_size    ?? 0), 2),
                    'avg_factor_rate'  => round((float)($deal->avg_factor_rate  ?? 0), 3),
                    'avg_days_to_fund' => round((float)($deal->avg_days_to_fund ?? 0), 1),
                ];
            });

            return $this->successResponse("Deal Quality", $data);
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to load deal quality", [$e->getMessage()], $e, 500);
        }
    }

    /**
     * GET /crm/analytics/stale-leads
     * Leads unchanged for more than ?days= (default 14) — by stage.
     */
    public function staleLeads(Request $request)
    {
        try {
            $clientId = $request->auth->parent_id;
            $days     = max(1, min(90, (int) $request->input('days', 14)));
            $suffix   = $this->visibilityCacheSuffix($request);
            $cacheKey = "analytics_{$clientId}_stale_leads_{$days}{$suffix}";
            $auth     = $request->auth;

            $data = Cache::remember($cacheKey, 120, function () use ($clientId, $days, $auth) {
                $cutoff = Carbon::now()->subDays($days)->toDateTimeString();

                $q = DB::connection("mysql_$clientId")
                    ->table('crm_lead_data as ld')
                    ->leftJoin('crm_lead_status as s', 's.lead_title_url', '=', 'ld.lead_status')
                    ->where('ld.is_deleted', 0)
                    ->where('ld.updated_at', '<', $cutoff)
                    ->whereNotIn('ld.lead_status', ['funded', 'closed_lost', 'declined', 'dead', 'closed_won']);
                (new LeadVisibilityService())->applyVisibilityScope($q, $auth, (int) $clientId, 'ld');
                $rows = $q
                    ->select(
                        'ld.lead_status as status_slug',
                        DB::raw("COALESCE(s.title, ld.lead_status) as status_name"),
                        's.color_code',
                        DB::raw('COUNT(*) as count'),
                        DB::raw('AVG(DATEDIFF(NOW(), ld.updated_at)) as avg_days_stale')
                    )
                    ->groupBy('ld.lead_status', 's.title', 's.color_code')
                    ->orderByDesc('count')
                    ->get();

                $byStage    = $rows->map(fn($r) => [
                    'status_slug'    => $r->status_slug,
                    'status_name'    => $r->status_name,
                    'color'          => $r->color_code ?? '#6B7280',
                    'count'          => (int)   $r->count,
                    'avg_days_stale' => (int) round((float) $r->avg_days_stale),
                ])->values()->toArray();

                return [
                    'threshold_days' => $days,
                    'total_stale'    => array_sum(array_column($byStage, 'count')),
                    'by_stage'       => $byStage,
                ];
            });

            return $this->successResponse("Stale Leads", $data);
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to load stale leads", [$e->getMessage()], $e, 500);
        }
    }

}