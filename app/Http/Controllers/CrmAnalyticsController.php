<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

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
    private function period(Request $request): array
    {
        $period = $request->input('period', 'month');
        $start  = $request->input('start_date');
        $end    = $request->input('end_date');

        if (!$start || !$end) {
            $end   = Carbon::now()->toDateString();
            $start = match ($period) {
                'today'  => Carbon::now()->toDateString(),
                'week'   => Carbon::now()->subDays(6)->toDateString(),
                'month'  => Carbon::now()->subDays(29)->toDateString(),
                'quarter'=> Carbon::now()->subDays(89)->toDateString(),
                default  => Carbon::now()->subDays(29)->toDateString(),
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

            $cacheKey = "analytics_{$clientId}_status_dist_current";

            $data = Cache::remember($cacheKey, 120, function () use ($clientId) {
                $statuses = DB::connection("mysql_$clientId")
                    ->table('crm_lead_status')
                    ->where('status', '1')
                    ->orderBy('display_order')
                    ->get(['title', 'lead_title_url', 'color_code']);

                // Count ALL active leads per status (no date filter — current pipeline state)
                $total = DB::connection("mysql_$clientId")
                    ->table('crm_lead_data')
                    ->where('is_deleted', 0)
                    ->count();

                $counts = DB::connection("mysql_$clientId")
                    ->table('crm_lead_data')
                    ->where('is_deleted', 0)
                    ->select('lead_status', DB::raw('COUNT(*) as count'))
                    ->groupBy('lead_status')
                    ->pluck('count', 'lead_status')
                    ->toArray();

                $result = [];
                foreach ($statuses as $s) {
                    $count = $counts[$s->lead_title_url] ?? 0;
                    if ($count === 0) continue; // skip empty stages
                    $result[] = [
                        'status'     => $s->lead_title_url,
                        'title'      => $s->title,
                        'color_code' => $s->color_code,
                        'count'      => $count,
                        'percentage' => $total > 0 ? round(($count / $total) * 100, 1) : 0,
                    ];
                }

                // Also include statuses not in crm_lead_status (legacy/custom)
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

            $cacheKey = "analytics_{$clientId}_velocity_{$start}_{$end}";

            $data = Cache::remember($cacheKey, 300, function () use ($clientId, $start, $end) {
                $daily = DB::connection("mysql_$clientId")
                    ->table('crm_lead_data')
                    ->where('is_deleted', 0)
                    ->whereBetween(DB::raw('DATE(created_at)'), [$start, $end])
                    ->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as new_leads'))
                    ->groupBy(DB::raw('DATE(created_at)'))
                    ->orderBy('date')
                    ->get();

                // If selected period has no data, fall back to the last 180 days that have data
                if ($daily->isEmpty()) {
                    $latestDate = DB::connection("mysql_$clientId")
                        ->table('crm_lead_data')
                        ->where('is_deleted', 0)
                        ->max(DB::raw('DATE(created_at)'));

                    if ($latestDate) {
                        $fallbackEnd   = $latestDate;
                        $fallbackStart = date('Y-m-d', strtotime($latestDate . ' -179 days'));

                        $daily = DB::connection("mysql_$clientId")
                            ->table('crm_lead_data')
                            ->where('is_deleted', 0)
                            ->whereBetween(DB::raw('DATE(created_at)'), [$fallbackStart, $fallbackEnd])
                            ->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as new_leads'))
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

            $cacheKey = "analytics_{$clientId}_agent_perf_current_" . md5(implode(',', $agentIds));

            $data = Cache::remember($cacheKey, 120, function () use ($clientId, $agentIds) {
                $query = DB::connection("mysql_$clientId")
                    ->table('crm_lead_data')
                    ->where('is_deleted', 0)
                    ->select('assigned_to', 'lead_status', DB::raw('COUNT(*) as count'))
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
                $users     = \App\Model\User::whereIn('id', $userIds)->get(['id', 'first_name', 'last_name'])->keyBy('id');
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

            $cacheKey = "analytics_{$clientId}_funnel_current";

            $data = Cache::remember($cacheKey, 120, function () use ($clientId) {
                $stages = DB::connection("mysql_$clientId")
                    ->table('crm_lead_status')
                    ->where('status', '1')
                    ->orderBy('display_order')
                    ->pluck('title', 'lead_title_url');

                $counts = DB::connection("mysql_$clientId")
                    ->table('crm_lead_data')
                    ->where('is_deleted', 0)
                    ->select('lead_status', DB::raw('COUNT(*) as count'))
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

            $cacheKey = "analytics_{$clientId}_lender_perf_{$start}_{$end}";

            $data = Cache::remember($cacheKey, 300, function () use ($clientId, $start, $end) {
                $submissions = DB::connection("mysql_$clientId")
                    ->table('crm_send_lead_to_lender_record as r')
                    ->join('crm_lender as l', 'l.id', '=', DB::raw('CAST(r.lender_id AS UNSIGNED)'))
                    ->whereBetween(DB::raw('DATE(r.created_at)'), [$start, $end])
                    ->select(
                        'r.lender_id',
                        'l.lender_name',
                        DB::raw('COUNT(*) as total_submissions'),
                        'r.lender_status_id'
                    )
                    ->groupBy('r.lender_id', 'l.lender_name', 'r.lender_status_id')
                    ->get();

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
                    $grouped[$row->lender_id]['by_status'][$row->lender_status_id ?? 'pending'] = $row->total_submissions;
                }

                return array_values($grouped);
            });

            return $this->successResponse("Lender Performance", $data);
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to load lender performance", [$e->getMessage()], $e, 500);
        }
    }
}
