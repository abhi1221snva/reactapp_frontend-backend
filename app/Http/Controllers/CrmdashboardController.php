<?php

namespace App\Http\Controllers;
use App\Model\Client\LeadStatus;
use App\Model\Client\Lead;
use App\Model\Client\CrmLabel;
use App\Model\Client\Dids;
use App\Model\Master\Client;
use App\Services\LeadVisibilityService;
use Illuminate\Http\Request;
use App\Model\Role;
use App\Model\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CrmdashboardController extends Controller
{
    public function index(Request $request)
    {
        $dashboard = array();
        try
        {
            $clientId = $request->auth->parent_id;
            $leadstatus = [];

            $query = Lead::on("mysql_$clientId");
            (new LeadVisibilityService())->applyVisibilityScope($query, $request->auth, (int) $clientId);
            $leadstatus = $query->groupBy('lead_status')->select('lead_status', DB::raw('count(*) as total_lead_status'))->get()->all();
            $totalDids  = 0;
            $totalSMS   = 0;

            foreach($leadstatus as $key=> $leads)
            {
                $lead_status = LeadStatus::on("mysql_$clientId")->where('lead_title_url',$leads->lead_status)->get()->first();
            }

            $dashboard['leadstatus'] = $leadstatus;
            $dashboard['totalDids']  = $totalDids;
            $dashboard['totalSMS']   = $totalSMS;

            return $this->successResponse("Label Status", $dashboard);
        }
        catch (\Throwable $exception)
        {
            return $this->failResponse("Failed to list extension groups", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }

    /**
     * @OA\POST(
     *     path="/mca/dashboard-metrics",
     *     operationId="getMcaDashboardMetrics",
     *     tags={"MCA Dashboard"},
     *     summary="Get MCA-specific dashboard metrics",
     *     description="Returns comprehensive metrics for Merchant Cash Advance operations",
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *             @OA\Property(property="period", type="string", enum={"today", "week", "month", "custom"}, example="today"),
     *             @OA\Property(property="start_date", type="string", format="date"),
     *             @OA\Property(property="end_date", type="string", format="date")
     *         )
     *     ),
     *     @OA\Response(response=200, description="MCA Dashboard Metrics")
     * )
     */
    public function getMcaDashboardMetrics(Request $request)
    {
        try {
            $clientId = (int) $request->auth->parent_id;
            $connection = "mysql_{$clientId}";
            $auth = $request->auth;

            // Determine date range
            $period = $request->input('period', 'month');
            $dates = $this->getDateRange($period, $request->input('start_date'), $request->input('end_date'));

            $metrics = [];

            // 1. Pipeline Summary
            $metrics['pipeline'] = $this->getMcaPipelineSummary($connection, $auth, $clientId);

            // 2. Funding Metrics
            $metrics['funding'] = $this->getFundingMetrics($connection, $dates['start'], $dates['end'], $auth, $clientId);

            // 3. Conversion Metrics
            $metrics['conversions'] = $this->getConversionMetrics($connection, $dates['start'], $dates['end'], $auth, $clientId);

            // 4. Agent/User Performance
            $metrics['agentPerformance'] = $this->getAgentPerformance($connection, $clientId, $dates['start'], $dates['end'], $auth);

            // 5. Document Status
            $metrics['documentStatus'] = $this->getDocumentStatus($connection, $auth, $clientId);

            // 6. Recent Activity
            $metrics['recentDeals'] = $this->getRecentDeals($connection, $auth, $clientId, 10);

            // 7. Renewal Pipeline
            $metrics['renewals'] = $this->getRenewalMetrics($connection, $auth, $clientId);

            // 8. Period comparison
            $metrics['comparison'] = $this->getPeriodComparison($connection, $dates['start'], $dates['end'], $auth, $clientId);

            $metrics['dateRange'] = $dates;

            return $this->successResponse("MCA Dashboard Metrics", $metrics);

        } catch (\Throwable $exception) {
            Log::error('MCA Dashboard Error: ' . $exception->getMessage());
            return $this->failResponse("Failed to load MCA dashboard", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * Get MCA Pipeline Summary by Stage - uses actual lead statuses from database
     */
    private function getMcaPipelineSummary($connection, $auth, $clientId)
    {
        // Get all active lead statuses from database
        $statuses = DB::connection($connection)->table('crm_lead_status')
            ->where('status', 1)
            ->orderBy('display_order')
            ->get();

        // Get lead counts grouped by status
        $query = DB::connection($connection)->table('crm_lead_data')
            ->select('lead_status', DB::raw('COUNT(*) as count'), DB::raw('SUM(COALESCE(requested_amount, 0)) as total_requested'))
            ->whereNull('deleted_at')
            ->groupBy('lead_status');

        (new LeadVisibilityService())->applyVisibilityScope($query, $auth, $clientId);

        $results = $query->get()->keyBy('lead_status');

        $pipeline = [];
        $totalLeads = 0;
        $totalRequested = 0;

        foreach ($statuses as $status) {
            $slug = $status->lead_title_url;
            $data = $results->get($slug);
            $count = $data ? (int)$data->count : 0;
            $requested = $data ? (float)$data->total_requested : 0;

            // Only include statuses that have leads or are important pipeline stages
            if ($count > 0 || in_array($slug, ['new-lead', 'contacted', 'docs-requested', 'docs-received', 'submitted-to-underwriting', 'approved', 'funded', 'declined'])) {
                $pipeline[] = [
                    'stage' => $slug,
                    'name' => $status->title,
                    'color' => $status->color_code ?? '#999999',
                    'count' => $count,
                    'totalRequested' => round($requested, 2)
                ];
            }

            $totalLeads += $count;
            $totalRequested += $requested;
        }

        return [
            'stages' => $pipeline,
            'totalLeads' => $totalLeads,
            'totalRequested' => round($totalRequested, 2)
        ];
    }

    /**
     * Get Funding Metrics
     */
    private function getFundingMetrics($connection, $startDate, $endDate, $auth, $clientId)
    {
        $query = DB::connection($connection)->table('crm_lead_data')
            ->whereNotNull('funded_amount')
            ->where('funded_amount', '>', 0)
            ->whereBetween('funding_date', [$startDate, $endDate])
            ->whereNull('deleted_at');

        (new LeadVisibilityService())->applyVisibilityScope($query, $auth, $clientId);

        $funded = $query->selectRaw('
            COUNT(*) as total_deals,
            SUM(funded_amount) as total_funded,
            SUM(payback_amount) as total_payback,
            SUM(commission_amount) as total_commission,
            AVG(funded_amount) as avg_deal_size,
            AVG(factor_rate) as avg_factor_rate
        ')->first();

        // Calculate funding by day for the period
        $dailyQ = DB::connection($connection)->table('crm_lead_data')
            ->whereNotNull('funded_amount')
            ->where('funded_amount', '>', 0)
            ->whereBetween('funding_date', [$startDate, $endDate])
            ->whereNull('deleted_at');
        (new LeadVisibilityService())->applyVisibilityScope($dailyQ, $auth, $clientId);
        $dailyFunding = $dailyQ
            ->selectRaw('DATE(funding_date) as date, COUNT(*) as deals, SUM(funded_amount) as amount')
            ->groupBy(DB::raw('DATE(funding_date)'))
            ->orderBy('date')
            ->get();

        return [
            'totalDeals' => (int)($funded->total_deals ?? 0),
            'totalFunded' => round((float)($funded->total_funded ?? 0), 2),
            'totalPayback' => round((float)($funded->total_payback ?? 0), 2),
            'totalCommission' => round((float)($funded->total_commission ?? 0), 2),
            'avgDealSize' => round((float)($funded->avg_deal_size ?? 0), 2),
            'avgFactorRate' => round((float)($funded->avg_factor_rate ?? 0), 3),
            'dailyFunding' => $dailyFunding
        ];
    }

    /**
     * Get Conversion Metrics - counts by status from database
     */
    private function getConversionMetrics($connection, $startDate, $endDate, $auth, $clientId)
    {
        // Get all lead counts grouped by status
        $query = DB::connection($connection)->table('crm_lead_data')
            ->select('lead_status', DB::raw('COUNT(*) as count'))
            ->whereBetween('created_at', [$startDate, $endDate])
            ->whereNull('deleted_at')
            ->groupBy('lead_status');

        (new LeadVisibilityService())->applyVisibilityScope($query, $auth, $clientId);

        $statusCounts = $query->get()->keyBy('lead_status');

        // Calculate totals - aggregate by funnel stage
        $totalLeads = $statusCounts->sum('count');

        // Contacted = all except new-lead, dead, not-interested
        $newLeads = (int)($statusCounts->get('new-lead')->count ?? 0);
        $deadLeads = (int)($statusCounts->get('dead')->count ?? 0);
        $notInterested = (int)($statusCounts->get('not-interested')->count ?? 0);
        $contactedLeads = $totalLeads - $newLeads - $deadLeads - $notInterested;

        // Submitted = docs-received + submitted-to-underwriting + in-underwriting + approved + conditionally-approved + contract-sent + contract-signed + funded + declined
        $submittedLeads = (int)($statusCounts->get('submitted-to-underwriting')->count ?? 0)
            + (int)($statusCounts->get('in-underwriting')->count ?? 0)
            + (int)($statusCounts->get('approved')->count ?? 0)
            + (int)($statusCounts->get('conditionally-approved')->count ?? 0)
            + (int)($statusCounts->get('contract-sent')->count ?? 0)
            + (int)($statusCounts->get('contract-signed')->count ?? 0)
            + (int)($statusCounts->get('funded')->count ?? 0)
            + (int)($statusCounts->get('declined')->count ?? 0);

        // Approved = approved + conditionally-approved + contract-sent + contract-signed + funded
        $approvedLeads = (int)($statusCounts->get('approved')->count ?? 0)
            + (int)($statusCounts->get('conditionally-approved')->count ?? 0)
            + (int)($statusCounts->get('contract-sent')->count ?? 0)
            + (int)($statusCounts->get('contract-signed')->count ?? 0)
            + (int)($statusCounts->get('funded')->count ?? 0);

        $fundedLeads = (int)($statusCounts->get('funded')->count ?? 0);
        $declinedLeads = (int)($statusCounts->get('declined')->count ?? 0);

        return [
            'totalLeads' => $totalLeads,
            'contacted' => max(0, $contactedLeads),
            'submitted' => $submittedLeads,
            'approved' => $approvedLeads,
            'funded' => $fundedLeads,
            'declined' => $declinedLeads,
            'contactRate' => $totalLeads > 0 ? round(($contactedLeads / $totalLeads) * 100, 1) : 0,
            'submissionRate' => $contactedLeads > 0 ? round(($submittedLeads / $contactedLeads) * 100, 1) : 0,
            'approvalRate' => $submittedLeads > 0 ? round(($approvedLeads / $submittedLeads) * 100, 1) : 0,
            'fundingRate' => $approvedLeads > 0 ? round(($fundedLeads / $approvedLeads) * 100, 1) : 0,
            'overallConversion' => $totalLeads > 0 ? round(($fundedLeads / $totalLeads) * 100, 1) : 0,
            'statusBreakdown' => $statusCounts->map(function($item) {
                return (int)$item->count;
            })
        ];
    }

    /**
     * Get Agent Performance
     */
    private function getAgentPerformance($connection, $clientId, $startDate, $endDate, $auth = null)
    {
        $agentStats = DB::connection($connection)->table('crm_lead_data')
            ->whereNotNull('assigned_to')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->whereNull('deleted_at');

        if ($auth) {
            (new LeadVisibilityService())->applyVisibilityScope($agentStats, $auth, (int) $clientId);
        }

        $agentStats = $agentStats
            ->selectRaw('
                assigned_to,
                COUNT(*) as total_leads,
                SUM(CASE WHEN lead_status = "funded" THEN 1 ELSE 0 END) as funded_deals,
                SUM(CASE WHEN lead_status = "funded" THEN funded_amount ELSE 0 END) as total_funded,
                SUM(CASE WHEN lead_status = "funded" THEN commission_amount ELSE 0 END) as total_commission
            ')
            ->groupBy('assigned_to')
            ->orderByDesc('total_funded')
            ->limit(10)
            ->get();

        // Get user names
        $userIds = $agentStats->pluck('assigned_to')->toArray();
        $users = DB::connection('master')->table('users')
            ->whereIn('id', $userIds)
            ->select('id', 'first_name', 'last_name')
            ->get()
            ->keyBy('id');

        $leaderboard = [];
        foreach ($agentStats as $stat) {
            $user = $users->get($stat->assigned_to);
            $leaderboard[] = [
                'userId' => $stat->assigned_to,
                'name' => $user ? trim($user->first_name . ' ' . $user->last_name) : 'Unknown',
                'totalLeads' => (int)$stat->total_leads,
                'fundedDeals' => (int)$stat->funded_deals,
                'totalFunded' => round((float)$stat->total_funded, 2),
                'totalCommission' => round((float)$stat->total_commission, 2),
                'conversionRate' => $stat->total_leads > 0 ? round(($stat->funded_deals / $stat->total_leads) * 100, 1) : 0
            ];
        }

        return $leaderboard;
    }

    /**
     * Get Document Status Summary
     */
    private function getDocumentStatus($connection, $auth, $clientId)
    {
        $query = DB::connection($connection)->table('crm_lead_data')
            ->whereIn('lead_status', ['docs-requested', 'docs-received', 'needs-more-docs'])
            ->whereNull('deleted_at');

        (new LeadVisibilityService())->applyVisibilityScope($query, $auth, $clientId);

        $docs = $query->selectRaw('
            SUM(CASE WHEN has_bank_statements = 1 THEN 1 ELSE 0 END) as has_bank_statements,
            SUM(CASE WHEN has_application = 1 THEN 1 ELSE 0 END) as has_application,
            SUM(CASE WHEN has_drivers_license = 1 THEN 1 ELSE 0 END) as has_drivers_license,
            SUM(CASE WHEN has_voided_check = 1 THEN 1 ELSE 0 END) as has_voided_check,
            COUNT(*) as total_pending
        ')->first();

        return [
            'totalPending' => (int)($docs->total_pending ?? 0),
            'withBankStatements' => (int)($docs->has_bank_statements ?? 0),
            'withApplication' => (int)($docs->has_application ?? 0),
            'withDriversLicense' => (int)($docs->has_drivers_license ?? 0),
            'withVoidedCheck' => (int)($docs->has_voided_check ?? 0)
        ];
    }

    /**
     * Get Recent Deals
     */
    private function getRecentDeals($connection, $auth, $clientId, $limit = 10)
    {
        $query = DB::connection($connection)->table('crm_lead_data')
            ->select(
                'id', 'business_name', 'company_name', 'first_name', 'last_name',
                'lead_status', 'requested_amount', 'approved_amount', 'funded_amount',
                'funding_date', 'created_at', 'updated_at'
            )
            ->whereNull('deleted_at')
            ->orderByDesc('updated_at')
            ->limit($limit);

        (new LeadVisibilityService())->applyVisibilityScope($query, $auth, $clientId);

        return $query->get()->map(function($deal) {
            return [
                'id' => $deal->id,
                'businessName' => $deal->business_name ?: $deal->company_name ?: trim($deal->first_name . ' ' . $deal->last_name),
                'status' => $deal->lead_status,
                'requestedAmount' => (float)$deal->requested_amount,
                'approvedAmount' => (float)$deal->approved_amount,
                'fundedAmount' => (float)$deal->funded_amount,
                'fundingDate' => $deal->funding_date,
                'updatedAt' => $deal->updated_at
            ];
        });
    }

    /**
     * Get Renewal Metrics
     */
    private function getRenewalMetrics($connection, $auth, $clientId)
    {
        $query = DB::connection($connection)->table('crm_lead_data')
            ->whereNull('deleted_at');

        (new LeadVisibilityService())->applyVisibilityScope($query, $auth, $clientId);

        $renewals = $query->selectRaw('
            SUM(CASE WHEN lead_status = "renewal-eligible" THEN 1 ELSE 0 END) as eligible,
            SUM(CASE WHEN lead_status = "renewal-in-progress" THEN 1 ELSE 0 END) as in_progress,
            SUM(CASE WHEN is_renewal = 1 AND lead_status = "funded" THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN is_renewal = 1 AND lead_status = "funded" THEN funded_amount ELSE 0 END) as renewal_volume
        ')->first();

        return [
            'eligible' => (int)($renewals->eligible ?? 0),
            'inProgress' => (int)($renewals->in_progress ?? 0),
            'completed' => (int)($renewals->completed ?? 0),
            'renewalVolume' => round((float)($renewals->renewal_volume ?? 0), 2)
        ];
    }

    /**
     * Get Period Comparison (vs previous period)
     */
    private function getPeriodComparison($connection, $startDate, $endDate, $auth, $clientId)
    {
        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);
        $diffDays = $start->diffInDays($end) + 1;

        $prevStart = $start->copy()->subDays($diffDays)->format('Y-m-d H:i:s');
        $prevEnd = $start->copy()->subSecond()->format('Y-m-d H:i:s');

        $getMetrics = function($start, $end) use ($connection, $auth, $clientId) {
            $query = DB::connection($connection)->table('crm_lead_data')
                ->whereBetween('created_at', [$start, $end])
                ->whereNull('deleted_at');

            (new LeadVisibilityService())->applyVisibilityScope($query, $auth, $clientId);

            return $query->selectRaw('
                COUNT(*) as leads,
                SUM(CASE WHEN lead_status = "funded" THEN 1 ELSE 0 END) as funded,
                SUM(CASE WHEN lead_status = "funded" THEN funded_amount ELSE 0 END) as volume
            ')->first();
        };

        $current = $getMetrics($startDate, $endDate);
        $previous = $getMetrics($prevStart, $prevEnd);

        $calcChange = function($curr, $prev) {
            if ($prev == 0) return $curr > 0 ? 100 : 0;
            return round((($curr - $prev) / $prev) * 100, 1);
        };

        return [
            'current' => [
                'leads' => (int)($current->leads ?? 0),
                'funded' => (int)($current->funded ?? 0),
                'volume' => round((float)($current->volume ?? 0), 2)
            ],
            'previous' => [
                'leads' => (int)($previous->leads ?? 0),
                'funded' => (int)($previous->funded ?? 0),
                'volume' => round((float)($previous->volume ?? 0), 2)
            ],
            'change' => [
                'leads' => $calcChange($current->leads ?? 0, $previous->leads ?? 0),
                'funded' => $calcChange($current->funded ?? 0, $previous->funded ?? 0),
                'volume' => $calcChange($current->volume ?? 0, $previous->volume ?? 0)
            ]
        ];
    }

    /**
     * Get date range based on period
     */
    private function getDateRange($period, $customStart = null, $customEnd = null)
    {
        $now = Carbon::now();

        switch ($period) {
            case 'today':
                return [
                    'start' => $now->copy()->startOfDay()->format('Y-m-d H:i:s'),
                    'end' => $now->copy()->endOfDay()->format('Y-m-d H:i:s')
                ];
            case 'week':
                return [
                    'start' => $now->copy()->startOfWeek()->format('Y-m-d H:i:s'),
                    'end' => $now->copy()->endOfWeek()->format('Y-m-d H:i:s')
                ];
            case 'month':
                return [
                    'start' => $now->copy()->startOfMonth()->format('Y-m-d H:i:s'),
                    'end' => $now->copy()->endOfMonth()->format('Y-m-d H:i:s')
                ];
            case 'quarter':
                return [
                    'start' => $now->copy()->startOfQuarter()->format('Y-m-d H:i:s'),
                    'end' => $now->copy()->endOfQuarter()->format('Y-m-d H:i:s')
                ];
            case 'year':
                return [
                    'start' => $now->copy()->startOfYear()->format('Y-m-d H:i:s'),
                    'end' => $now->copy()->endOfYear()->format('Y-m-d H:i:s')
                ];
            default:
                return [
                    'start' => $customStart ? Carbon::parse($customStart)->startOfDay()->format('Y-m-d H:i:s') : $now->copy()->startOfMonth()->format('Y-m-d H:i:s'),
                    'end' => $customEnd ? Carbon::parse($customEnd)->endOfDay()->format('Y-m-d H:i:s') : $now->copy()->endOfMonth()->format('Y-m-d H:i:s')
                ];
        }
    }
}
