<?php

namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Model\Dids;
use App\Model\Lists;
use App\Services\ReportService;
use App\Services\CacheService;
use Carbon\Carbon;


class DashboardController extends Controller
{
    /**
 * @OA\POST(
 *     path="/dashboard",
 *     operationId="getDashboardSummary",
 *     tags={"Dashboard"},
 *     summary="Get dashboard summary for the authenticated user",
 *     description="Returns counts for users, campaigns, DIDs, leads, callbacks, lists, SMS, and voicemails for the given time range.",
 *     security={{"Bearer":{}}},
 *     
 *     @OA\Parameter(
 *         name="startTime",
 *         in="query",
 *         required=false,
 *         description="Start time for the stats range (format: Y-m-d H:i:s)",
 *         @OA\Schema(type="string", format="date-time", example="2024-01-01 00:00:00")
 *     ),
 *     @OA\Parameter(
 *         name="endTime",
 *         in="query",
 *         required=false,
 *         description="End time for the stats range (format: Y-m-d H:i:s)",
 *         @OA\Schema(type="string", format="date-time", example="2024-12-31 23:59:59")
 *     ),
 *     
 *     @OA\Response(
 *         response=200,
 *         description="Dashboard Summary",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="boolean", example=true),
 *             @OA\Property(property="message", type="string", example="Dashboard Summary"),
 *             @OA\Property(
 *                 property="data",
 *                 type="object",
 *                 @OA\Property(property="totalCallbacks", type="integer", example=42),
 *                 @OA\Property(property="totalUsers", type="integer", example=10),
 *                 @OA\Property(property="totalCampaigns", type="integer", example=5),
 *                 @OA\Property(property="totalDids", type="integer", example=7),
 *                 @OA\Property(property="totalLeads", type="integer", example=2000),
 *                 @OA\Property(property="totalList", type="integer", example=15),
 *                 @OA\Property(property="incomingSms", type="integer", example=120),
 *                 @OA\Property(property="outgoingSms", type="integer", example=95),
 *                 @OA\Property(property="unreadVoicemail", type="integer", example=8),
 *                 @OA\Property(property="receivedVoicemail", type="integer", example=20)
 *             )
 *         )
 *     ),
 *     
 *     @OA\Response(
 *         response=500,
 *         description="Failed to load dashboard",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="boolean", example=false),
 *             @OA\Property(property="message", type="string", example="Failed to load dashboard"),
 *             @OA\Property(property="errors", type="array", @OA\Items(type="string"))
 *         )
 *     )
 * )
 */

    public function index(Request $request)
{
    $dashboard = [];

    try {
        $clientId = $request->auth->parent_id;
        $leadstatus = [];
        $level = $request->auth->user_level;

        // Get cached basic counts or fetch fresh data (5-minute TTL, tenant-scoped)
        $cachedCounts = CacheService::tenantRemember($clientId, CacheService::KEY_DASHBOARD_STATS, CacheService::TTL_MEDIUM, function () use ($request) {
            $extensionCountResult = $this->getExtensionCount($request);
            $extensionCount = ($extensionCountResult['success'] === true) ? $extensionCountResult['data'] : 0;

            $campaignCountResult = $this->getCampaignCount($request);
            $campaignCount = ($campaignCountResult['success'] === true) ? $campaignCountResult['data'] : 0;

            $didCount = $this->getDidCount($request);
            $ListCount = $this->countList($request);

            $LeadCountResult = $this->getLeadCount($request);
            $LeadCount = ($LeadCountResult['success'] === true) ? $LeadCountResult['data'] : 0;

            return [
                'totalUsers' => $extensionCount,
                'totalCampaigns' => $campaignCount,
                'totalDids' => $didCount,
                'totalList' => $ListCount,
                'totalLeads' => $LeadCount,
            ];
        });

        // Callbacks are time-sensitive, don't cache
        $callbackResult = $this->getCallBack($request);
        $callbackCount = ($callbackResult['success'] === true) ? $callbackResult['record_count'] : 0;

        $dashboard['totalCallbacks'] = $callbackCount;
        $dashboard['totalUsers'] = $cachedCounts['totalUsers'];
        $dashboard['totalCampaigns'] = $cachedCounts['totalCampaigns'];
        $dashboard['totalDids'] = $cachedCounts['totalDids'];
        $dashboard['totalLeads'] = $cachedCounts['totalLeads'];
        $dashboard['totalList'] = $cachedCounts['totalList'];
  $startTime = $request->startTime ?? Carbon::now()->subYear()->format('Y-m-d H:i:s');
$endTime = $request->endTime ?? Carbon::now()->format('Y-m-d H:i:s');


$SMSCountResponse = $this->getSmsCount($request, $startTime, $endTime);

$responseData = $SMSCountResponse->getData();

$smsCounts = (array) $responseData->data;

$dashboard['incomingSms'] = $smsCounts['incoming'] ?? 0;
$dashboard['outgoingSms'] = $smsCounts['outgoing'] ?? 0;
$VoicemailCountResponse = $this->getVoicemailCount($request, $startTime, $endTime);

// Extract data from JsonResponse object
$responseData = $VoicemailCountResponse->getData();
$voicemailData = (array) $responseData->data;

$unread = $voicemailData['unread'] ?? 0;
$read   = $voicemailData['read'] ?? 0;

$dashboard['unreadVoicemail']   = $unread;
$dashboard['receivedVoicemail']     = $read + $unread; // received = read + unread


        return $this->successResponse("Dashboard Summary", $dashboard);

    } catch (\Throwable $exception) {
        return $this->failResponse("Failed to load dashboard", [$exception->getMessage()], $exception, $exception->getCode());
    }
}
 function getCampaignCount(Request $request) {
        try {
            $data['is_deleted'] = 0;
            $sql = "SELECT count(1) as rowCount FROM campaign WHERE is_deleted = :is_deleted ";
            $record = DB::connection('mysql_' . $request->auth->parent_id)->selectOne($sql, $data);
            $data = (array) $record;
            return array(
                'success' => true,
                'message' => 'Extension is not belong to any campaign.',
                'data' => $data['rowCount']
            );
        } catch (Exception $e) {
            echo $e->getMessage();
        } catch (InvalidArgumentException $e) {
            echo $e->getMessage();
        }
    }
function getExtensionCount(Request $request)
{
    try {
        $parent_id = $request->auth->parent_id;

        if (is_numeric($parent_id)) {
            $status = 0;
            $isDeleted = 0;
            $userLevel = 9;

            $sql = "SELECT COUNT(1) as rowCount
                    FROM users
                    LEFT JOIN user_extensions ON user_extensions.name = users.extension
                    WHERE users.id IN (
                        SELECT user_id FROM permissions WHERE client_id = :parent_id
                    )
                    AND users.is_deleted = :is_deleted
                    AND users.status = :status
                    AND users.base_parent_id = :base_parent_id
                    AND users.user_level < :user_level";

            $bindings = [
                'parent_id' => $parent_id,
                'is_deleted' => $isDeleted,
                'status' => $status,
                'base_parent_id' => $parent_id,
                'user_level' => $userLevel
            ];

            $record = DB::connection('master')->selectOne($sql, $bindings);
            $userCount = (array)$record;

            return [
                'success' => true,
                'message' => 'Extension count',
                'data' => $userCount['rowCount'] ?? 0
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Invalid parent ID',
                'data' => 0
            ];
        }
    } catch (\Exception $e) {
        Log::error('getExtensionCount error: ' . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Exception occurred',
            'data' => 0
        ];
    }
}
private function getDidCount(Request $request)
{
    // Use ->count() to issue a COUNT(*) SQL query instead of fetching all rows
    return Dids::on("mysql_" . $request->auth->parent_id)->where('is_deleted', '=', '0')->count();
}

function getLeadCount(Request $request) {
        try {
            $dataBase = 'mysql_' . $request->auth->parent_id;
            $sql = "SELECT count(1) as rowCount FROM list_data ";
            $record = DB::connection($dataBase)->selectOne($sql);
            $response = (array) $record;
            $leadCount = $response['rowCount'];
            if ($leadCount > 0) {
                return array(
                    'success' => true,
                    'message' => 'Lead count',
                    'data' => $leadCount
                );
            } else {
                return array(
                    'success' => true,
                    'message' => 'Lead count not found',
                    'data' => 0
                );
            }
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
}

    public function countList(Request $request)
    {
        // Use ->count() to issue a COUNT(*) SQL query instead of fetching all rows
        return Lists::on("mysql_" . $request->auth->parent_id)->where('is_active', '=', 1)->count();
    }
 public function getSmsCount(Request $request, $startTime, $endTime)
{
    $reportService = new ReportService($request->auth->parent_id);
    return $this->successResponse("Sms", $reportService->smsCount($request, $startTime, $endTime));
}

      public function getVoicemailCount(Request $request,$startTime, $endTime)
    {
      
        $reportService = new ReportService($request->auth->parent_id);
        return $this->successResponse("Voicemails", $reportService->voicemailCount($request, $startTime, $endTime));
    }

 /**
     * @OA\POST(
     *     path="/dashboard/revenue-metrics",
     *     operationId="getRevenueMetrics",
     *     tags={"Dashboard"},
     *     summary="Get real-time revenue metrics",
     *     description="Returns comprehensive revenue metrics including total revenue, revenue by call type, by agent, by campaign, and average revenue per call.",
     *     security={{"Bearer":{}}},
     *
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *             @OA\Property(property="start_date", type="string", format="date", example="2024-01-01"),
     *             @OA\Property(property="end_date", type="string", format="date", example="2024-12-31"),
     *             @OA\Property(property="period", type="string", enum={"today", "week", "month", "custom"}, example="today")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Revenue Metrics",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Revenue Metrics"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=500,
     *         description="Failed to load revenue metrics"
     *     )
     * )
     */
    public function getRevenueMetrics(Request $request)
    {
        try {
            $clientId = $request->auth->parent_id;
            $connection = 'mysql_' . $clientId;

            // Determine date range based on period or custom dates
            $period = $request->input('period', 'today');
            $dates = $this->getDateRange($period, $request->input('start_date'), $request->input('end_date'));
            $startDate = $dates['start'];
            $endDate = $dates['end'];

            // Get revenue metrics from both cdr and cdr_archive tables
            $revenueData = $this->calculateRevenueMetrics($connection, $startDate, $endDate, $clientId);

            return $this->successResponse("Revenue Metrics", $revenueData);

        } catch (\Throwable $exception) {
            Log::error('Revenue Metrics Error: ' . $exception->getMessage());
            return $this->failResponse("Failed to load revenue metrics", [$exception->getMessage()], $exception, 500);
        }
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
            case 'custom':
            default:
                return [
                    'start' => $customStart ? Carbon::parse($customStart)->startOfDay()->format('Y-m-d H:i:s') : $now->copy()->startOfDay()->format('Y-m-d H:i:s'),
                    'end' => $customEnd ? Carbon::parse($customEnd)->endOfDay()->format('Y-m-d H:i:s') : $now->copy()->endOfDay()->format('Y-m-d H:i:s')
                ];
        }
    }

    /**
     * Calculate all revenue metrics
     */
    private function calculateRevenueMetrics($connection, $startDate, $endDate, $clientId)
    {
        $metrics = [];

        // 1. Total Revenue Summary
        $metrics['summary'] = $this->getTotalRevenueSummary($connection, $startDate, $endDate);

        // 2. Revenue by Call Type (Inbound/Outbound/C2C)
        $metrics['byCallType'] = $this->getRevenueByCallType($connection, $startDate, $endDate);

        // 3. Revenue by Agent (Top 10)
        $metrics['byAgent'] = $this->getRevenueByAgent($connection, $startDate, $endDate, $clientId);

        // 4. Revenue by Campaign (Top 10)
        $metrics['byCampaign'] = $this->getRevenueByCampaign($connection, $startDate, $endDate);

        // 5. Period comparison (Today vs Yesterday, This Week vs Last Week, etc.)
        $metrics['comparison'] = $this->getRevenuePeriodComparison($connection, $startDate, $endDate);

        // 6. Hourly revenue breakdown (for today)
        $metrics['hourlyBreakdown'] = $this->getHourlyRevenueBreakdown($connection, $startDate, $endDate);

        // Add date range info
        $metrics['dateRange'] = [
            'start' => $startDate,
            'end' => $endDate
        ];

        return $metrics;
    }

    /**
     * Get total revenue summary
     */
    private function getTotalRevenueSummary($connection, $startDate, $endDate)
    {
        $sql = "
            SELECT
                COALESCE(SUM(charge), 0) as total_revenue,
                COUNT(*) as total_calls,
                COALESCE(SUM(duration), 0) as total_duration_seconds,
                COALESCE(SUM(unit_minute), 0) as total_billable_minutes,
                COALESCE(AVG(charge), 0) as avg_revenue_per_call,
                COALESCE(AVG(duration), 0) as avg_call_duration
            FROM (
                SELECT charge, duration, unit_minute FROM cdr
                WHERE start_time BETWEEN ? AND ? AND charge > 0
                UNION ALL
                SELECT charge, duration, unit_minute FROM cdr_archive
                WHERE start_time BETWEEN ? AND ? AND charge > 0
            ) combined
        ";

        $result = DB::connection($connection)->selectOne($sql, [$startDate, $endDate, $startDate, $endDate]);

        return [
            'totalRevenue' => round((float)$result->total_revenue, 2),
            'totalCalls' => (int)$result->total_calls,
            'totalDurationSeconds' => (int)$result->total_duration_seconds,
            'totalDurationFormatted' => $this->formatDuration($result->total_duration_seconds),
            'totalBillableMinutes' => (int)$result->total_billable_minutes,
            'avgRevenuePerCall' => round((float)$result->avg_revenue_per_call, 4),
            'avgCallDuration' => round((float)$result->avg_call_duration, 0)
        ];
    }

    /**
     * Get revenue by call type
     */
    private function getRevenueByCallType($connection, $startDate, $endDate)
    {
        $sql = "
            SELECT
                route,
                type,
                COALESCE(SUM(charge), 0) as revenue,
                COUNT(*) as call_count,
                COALESCE(SUM(duration), 0) as total_duration
            FROM (
                SELECT route, type, charge, duration FROM cdr
                WHERE start_time BETWEEN ? AND ?
                UNION ALL
                SELECT route, type, charge, duration FROM cdr_archive
                WHERE start_time BETWEEN ? AND ?
            ) combined
            GROUP BY route, type
            ORDER BY revenue DESC
        ";

        $results = DB::connection($connection)->select($sql, [$startDate, $endDate, $startDate, $endDate]);

        $byType = [
            'inbound' => ['revenue' => 0, 'calls' => 0, 'duration' => 0],
            'outbound_dialer' => ['revenue' => 0, 'calls' => 0, 'duration' => 0],
            'outbound_manual' => ['revenue' => 0, 'calls' => 0, 'duration' => 0],
            'outbound_c2c' => ['revenue' => 0, 'calls' => 0, 'duration' => 0]
        ];

        foreach ($results as $row) {
            if ($row->route === 'IN') {
                $byType['inbound']['revenue'] += (float)$row->revenue;
                $byType['inbound']['calls'] += (int)$row->call_count;
                $byType['inbound']['duration'] += (int)$row->total_duration;
            } elseif ($row->route === 'OUT') {
                $key = 'outbound_' . ($row->type ?: 'dialer');
                if (isset($byType[$key])) {
                    $byType[$key]['revenue'] += (float)$row->revenue;
                    $byType[$key]['calls'] += (int)$row->call_count;
                    $byType[$key]['duration'] += (int)$row->total_duration;
                }
            }
        }

        // Round revenue values
        foreach ($byType as &$type) {
            $type['revenue'] = round($type['revenue'], 2);
        }

        return $byType;
    }

    /**
     * Get revenue by agent (Top 10)
     */
    private function getRevenueByAgent($connection, $startDate, $endDate, $clientId)
    {
        $sql = "
            SELECT
                c.extension,
                COALESCE(SUM(c.charge), 0) as revenue,
                COUNT(*) as call_count,
                COALESCE(SUM(c.duration), 0) as total_duration,
                COALESCE(AVG(c.charge), 0) as avg_revenue
            FROM (
                SELECT extension, charge, duration FROM cdr
                WHERE start_time BETWEEN ? AND ? AND extension IS NOT NULL
                UNION ALL
                SELECT extension, charge, duration FROM cdr_archive
                WHERE start_time BETWEEN ? AND ? AND extension IS NOT NULL
            ) c
            GROUP BY c.extension
            ORDER BY revenue DESC
            LIMIT 10
        ";

        $results = DB::connection($connection)->select($sql, [$startDate, $endDate, $startDate, $endDate]);

        // Get agent names from master database
        $agents = [];
        foreach ($results as $row) {
            $agentInfo = DB::connection('master')->selectOne(
                "SELECT first_name, last_name, extension FROM users WHERE (extension = ? OR alt_extension = ?) AND base_parent_id = ? LIMIT 1",
                [$row->extension, $row->extension, $clientId]
            );

            $agents[] = [
                'extension' => $row->extension,
                'agentName' => $agentInfo ? trim($agentInfo->first_name . ' ' . $agentInfo->last_name) : 'Unknown',
                'revenue' => round((float)$row->revenue, 2),
                'callCount' => (int)$row->call_count,
                'totalDuration' => (int)$row->total_duration,
                'totalDurationFormatted' => $this->formatDuration($row->total_duration),
                'avgRevenue' => round((float)$row->avg_revenue, 4)
            ];
        }

        return $agents;
    }

    /**
     * Get revenue by campaign (Top 10)
     */
    private function getRevenueByCampaign($connection, $startDate, $endDate)
    {
        $sql = "
            SELECT
                c.campaign_id,
                COALESCE(SUM(c.charge), 0) as revenue,
                COUNT(*) as call_count,
                COALESCE(SUM(c.duration), 0) as total_duration
            FROM (
                SELECT campaign_id, charge, duration FROM cdr
                WHERE start_time BETWEEN ? AND ? AND campaign_id IS NOT NULL
                UNION ALL
                SELECT campaign_id, charge, duration FROM cdr_archive
                WHERE start_time BETWEEN ? AND ? AND campaign_id IS NOT NULL
            ) c
            GROUP BY c.campaign_id
            ORDER BY revenue DESC
            LIMIT 10
        ";

        $results = DB::connection($connection)->select($sql, [$startDate, $endDate, $startDate, $endDate]);

        // Get campaign names
        $campaigns = [];
        foreach ($results as $row) {
            $campaignInfo = DB::connection($connection)->selectOne(
                "SELECT title FROM campaign WHERE id = ? LIMIT 1",
                [$row->campaign_id]
            );

            $campaigns[] = [
                'campaignId' => $row->campaign_id,
                'campaignName' => $campaignInfo ? $campaignInfo->title : 'Unknown Campaign',
                'revenue' => round((float)$row->revenue, 2),
                'callCount' => (int)$row->call_count,
                'totalDuration' => (int)$row->total_duration,
                'totalDurationFormatted' => $this->formatDuration($row->total_duration)
            ];
        }

        return $campaigns;
    }

    /**
     * Get revenue period comparison
     */
    private function getRevenuePeriodComparison($connection, $startDate, $endDate)
    {
        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);
        $diffDays = $start->diffInDays($end) + 1;

        // Calculate previous period
        $prevStart = $start->copy()->subDays($diffDays)->format('Y-m-d H:i:s');
        $prevEnd = $start->copy()->subSecond()->format('Y-m-d H:i:s');

        // Current period revenue
        $currentSql = "
            SELECT COALESCE(SUM(charge), 0) as revenue, COUNT(*) as calls
            FROM (
                SELECT charge FROM cdr WHERE start_time BETWEEN ? AND ?
                UNION ALL
                SELECT charge FROM cdr_archive WHERE start_time BETWEEN ? AND ?
            ) c
        ";
        $current = DB::connection($connection)->selectOne($currentSql, [$startDate, $endDate, $startDate, $endDate]);

        // Previous period revenue
        $previous = DB::connection($connection)->selectOne($currentSql, [$prevStart, $prevEnd, $prevStart, $prevEnd]);

        $currentRevenue = (float)$current->revenue;
        $previousRevenue = (float)$previous->revenue;

        $changePercent = $previousRevenue > 0
            ? round((($currentRevenue - $previousRevenue) / $previousRevenue) * 100, 2)
            : ($currentRevenue > 0 ? 100 : 0);

        return [
            'currentPeriod' => [
                'revenue' => round($currentRevenue, 2),
                'calls' => (int)$current->calls,
                'start' => $startDate,
                'end' => $endDate
            ],
            'previousPeriod' => [
                'revenue' => round($previousRevenue, 2),
                'calls' => (int)$previous->calls,
                'start' => $prevStart,
                'end' => $prevEnd
            ],
            'change' => [
                'amount' => round($currentRevenue - $previousRevenue, 2),
                'percentage' => $changePercent,
                'trend' => $changePercent >= 0 ? 'up' : 'down'
            ]
        ];
    }

    /**
     * Get hourly revenue breakdown
     */
    private function getHourlyRevenueBreakdown($connection, $startDate, $endDate)
    {
        $sql = "
            SELECT
                HOUR(start_time) as hour,
                COALESCE(SUM(charge), 0) as revenue,
                COUNT(*) as call_count
            FROM (
                SELECT start_time, charge FROM cdr
                WHERE start_time BETWEEN ? AND ?
                UNION ALL
                SELECT start_time, charge FROM cdr_archive
                WHERE start_time BETWEEN ? AND ?
            ) c
            GROUP BY HOUR(start_time)
            ORDER BY hour
        ";

        $results = DB::connection($connection)->select($sql, [$startDate, $endDate, $startDate, $endDate]);

        // Initialize all hours
        $hourlyData = [];
        for ($i = 0; $i < 24; $i++) {
            $hourlyData[$i] = [
                'hour' => sprintf('%02d:00', $i),
                'revenue' => 0,
                'callCount' => 0
            ];
        }

        // Fill in actual data
        foreach ($results as $row) {
            $hourlyData[$row->hour] = [
                'hour' => sprintf('%02d:00', $row->hour),
                'revenue' => round((float)$row->revenue, 2),
                'callCount' => (int)$row->call_count
            ];
        }

        return array_values($hourlyData);
    }

    /**
     * Format duration in seconds to human readable format
     */
    private function formatDuration($seconds)
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }

        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%dh %dm %ds', $hours, $minutes, $secs);
        }

        return sprintf('%dm %ds', $minutes, $secs);
    }

    public function getCallBack($request)
    {
        try {
        $timezone = $request->auth->timezone 
            ?? env('APP_TIMEZONE') 
            ?? 'UTC';

        if (empty($timezone) || !in_array($timezone, timezone_identifiers_list())) {
            $timezone = env('APP_TIMEZONE') ?? 'Asia/Kolkata';
        }

        date_default_timezone_set($timezone);

        //date_default_timezone_set($request->auth->timezone); // your user's timezone
        $my_datetime=$request->start_date;//'2023-04-03 07:57:37';
        $my_datetime1=$request->end_date;//'2023-04-03 07:57:37';

        $request['start_date'] = date('Y-m-d H:i:s',strtotime("$my_datetime UTC"));
        $request['end_date'] = date('Y-m-d H:i:s',strtotime("$my_datetime1 UTC"));
        
        $id = $request->input('id');
        if (!empty($id) && is_numeric($id)) {
                $search = array();
                $searchString = array();

                // for Agent it will show his records only
                if ($request->auth->role == 2) {
                    $search['extension'] = $request->auth->extension;
                    $search['alt_extension'] = $request->auth->alt_extension;
                    array_push($searchString, '( c.extension = :extension OR c.extension = :alt_extension)');

                } elseif ($request->has('extension') && !empty($request->input('extension'))) {
                    // filter option, consider alt_extension bacause call maybe made using webRTC.
                    $search['extension'] = $request->input('extension');
                    $objTempUser = User::where('extension', $request->input('extension'))->where('is_deleted', '=', 0)->first();
                    $search['alt_extension'] = $objTempUser->alt_extension;
                    array_push($searchString, '(extension = :extension OR c.extension = :alt_extension)');
                }

                if ($request->has('campaign') && !empty($request->input('campaign'))) {
                    $search['campaign_id'] = $request->input('campaign');
                    array_push($searchString, 'c.campaign_id = :campaign_id');
                }

                if ($request->has('start_date') && $request->has('end_date') && !empty($request->input('start_date')) && !empty($request->input('end_date'))) {
                    $start = date('Y-m-d', strtotime($request->input('start_date'))) . " 00:00:00";
                    $end = date('Y-m-d', strtotime($request->input('end_date'))) . " 23:59:59";
                    $search['start_time'] = $start;
                    $search['end_time'] = $end;
                    array_push($searchString, 'c.callback_time BETWEEN :start_time AND :end_time');
                }

                if ($request->has('reminder') && !empty($request->input('reminder'))) {
                    $sql_extension = "SELECT GROUP_CONCAT(extension) as extensions FROM master.users WHERE extension IN (
                        SELECT extension FROM " . 'client_' . $request->auth->parent_id . ".extension_group_map WHERE is_deleted =0 and group_id IN (SELECT group_id FROM " . 'client_' . $request->auth->parent_id . ".extension_group_map WHERE is_deleted =0 and extension = " . $request->auth->extension . ")
                    ) AND user_level <= '" . $request->auth->level . "' ";

                    $arrExtensions = DB::connection('mysql_' . $request->auth->parent_id)->select($sql_extension);

                    $sql_extension = "SELECT GROUP_CONCAT(alt_extension) as alt_extension FROM master.users WHERE alt_extension IN (
                        SELECT extension FROM " . 'client_' . $request->auth->parent_id . ".extension_group_map WHERE is_deleted =0 and group_id IN (SELECT group_id FROM " . 'client_' . $request->auth->parent_id . ".extension_group_map WHERE is_deleted =0 and extension = " . $request->auth->extension . ")
                    ) AND user_level <= '" . $request->auth->level . "' ";

                    $arrExtensions1 = DB::connection('mysql_' . $request->auth->parent_id)->select($sql_extension);


                    $strExtensions = $arrExtensions[0]->extensions;
                    $strExtensions1 = $arrExtensions1[0]->alt_extension;

                    $originateRequest = $strExtensions.','.$strExtensions1;

                    array_push($searchString, " c.extension IN ($originateRequest)");

                    $search['start_time'] = date('Y-m-d H:i:s', strtotime($request->input('start_date')));
                    $search['end_time'] = date('Y-m-d H:i:s', strtotime($request->input('end_date')));
                }

                $filter = (!empty($searchString)) ? " WHERE " . implode(" AND ", $searchString) : '';

                $sql = "SELECT c.*,
                                ld.*,
                                CONCAT_WS(', ', option_1, option_2, option_3, option_4, option_5, option_6, option_7, option_8, option_9, option_10, option_11, option_12, option_13, option_14, option_15, option_16, option_17, option_18, option_19, option_20, option_21, option_22, option_23, option_24, option_25, option_26, option_27, option_28, option_29, option_30 ) as list_values,
                                x.list_headers,
                                y.is_dialing_selected_column
                        from callback as c
                           JOIN list_data as ld ON ( c.lead_id = ld.id )
                            JOIN (SELECT lh.list_id, GROUP_CONCAT(lh.header ORDER BY lh.id SEPARATOR ', ') as list_headers FROM list_header as lh GROUP BY lh.list_id) x ON x.list_id = ld.list_id
                            JOIN (SELECT lhh.column_name as is_dialing_selected_column, lhh.list_id FROM list_header as lhh WHERE is_dialing = 1 GROUP BY lhh.list_id) y ON y.list_id = ld.list_id
                           " . $filter . " ORDER BY c.callback_time DESC";

                $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql, $search);

                if (!empty($record)) {
                    $data = (array)$record;
                    return array(
                        'success' => true,
                        'message' => 'Callback Data Report.',
                        'data' => $data,
                        'record_count' => count($data),

                    );
                } else {
                    return array(
                        'success' => true,
                        'message' => 'No Callback Data Report found.',
                        'record_count' => 0,
                        'data' => array()
                    );
                }
            }
            return array(
                'success' => false,
                'message' => 'Callback Data Report doesn\'t exist.'
            );
        } catch (Exception $e) {
            Log::log($e->getMessage());
            return array(
                'success' => false,
                'message' => $e->getMessage()
            );
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
            return array(
                'success' => false,
                'message' => $e->getMessage()
            );
        }
    }

    /**
     * @OA\POST(
     *     path="/dashboard-state",
     *     operationId="setDashboardState",
     *     tags={"Dashboard"},
     *     summary="Set user's preferred dashboard type",
     *     description="Saves the user's dashboard preference (Dialer or CRM)",
     *     security={{"Bearer":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="state", type="integer", enum={1, 2}, example=1, description="1=Dialer Dashboard, 2=CRM Dashboard")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Dashboard state saved successfully"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to save dashboard state"
     *     )
     * )
     */
    public function setDashboardState(Request $request)
    {
        try {
            $userId = $request->auth->id;
            $state = (int) $request->input('state', 1);

            // Validate state value
            if (!in_array($state, [1, 2])) {
                return $this->failResponse("Invalid state value. Must be 1 (Dialer) or 2 (CRM)", [], null, 400);
            }

            // Update user's dashboard_type in the database
            $updated = DB::connection('master')->table('users')
                ->where('id', $userId)
                ->update(['dashboard_type' => $state]);

            if ($updated !== false) {
                return $this->successResponse("Dashboard state saved successfully", [
                    'dashboard_type' => $state,
                    'dashboard_name' => $state === 1 ? 'Dialer' : 'CRM'
                ]);
            }

            return $this->failResponse("Failed to save dashboard state", [], null, 500);

        } catch (\Throwable $exception) {
            Log::error('Dashboard State Error: ' . $exception->getMessage());
            return $this->failResponse("Failed to save dashboard state", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * @OA\GET(
     *     path="/dashboard-state",
     *     operationId="getDashboardState",
     *     tags={"Dashboard"},
     *     summary="Get user's preferred dashboard type",
     *     security={{"Bearer":{}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Dashboard state retrieved"
     *     )
     * )
     */
    public function getDashboardState(Request $request)
    {
        try {
            $userId = $request->auth->id;

            $user = DB::connection('master')->table('users')
                ->select('dashboard_type')
                ->where('id', $userId)
                ->first();

            $state = $user->dashboard_type ?? 1;

            return $this->successResponse("Dashboard state", [
                'dashboard_type' => (int) $state,
                'dashboard_name' => $state == 1 ? 'Dialer' : 'CRM'
            ]);

        } catch (\Throwable $exception) {
            Log::error('Dashboard State Error: ' . $exception->getMessage());
            return $this->failResponse("Failed to get dashboard state", [$exception->getMessage()], $exception, 500);
        }
    }

    // ─── Fast stats from pre-aggregated snapshots ────────────────────────────────

    /**
     * GET /dashboard/fast-stats?days=7
     *
     * Returns pre-aggregated metrics from daily_metric_snapshots.
     * Target response time: < 100ms (data served from Redis cache or snapshot table).
     *
     * @param Request $request
     */
    public function getFastStats(Request $request)
    {
        try {
            $clientId = $request->auth->parent_id;
            $days     = min(90, max(1, (int) $request->input('days', 7)));
            $cacheKey = "dashboard:fast:{$clientId}:{$days}";

            $data = Cache::remember($cacheKey, 300, function () use ($clientId, $days) {
                $db    = DB::connection('mysql_' . $clientId);
                $since = Carbon::today()->subDays($days)->toDateString();
                $today = Carbon::today()->toDateString();

                // Overall summary from snapshots
                $overall = $db->table('daily_metric_snapshots')
                    ->where('granularity', 'day')
                    ->whereBetween('snapshot_date', [$since, $today])
                    ->selectRaw("
                        COALESCE(SUM(total_calls), 0)     AS total_calls,
                        COALESCE(SUM(answered_calls), 0)  AS answered_calls,
                        COALESCE(SUM(missed_calls), 0)    AS missed_calls,
                        COALESCE(SUM(inbound_calls), 0)   AS inbound_calls,
                        COALESCE(SUM(outbound_calls), 0)  AS outbound_calls,
                        COALESCE(SUM(total_talk_time), 0) AS total_talk_time,
                        COALESCE(AVG(answer_rate), 0)     AS avg_answer_rate
                    ")
                    ->first();

                // Daily trend for charts
                $trend = $db->table('daily_metric_snapshots')
                    ->where('granularity', 'day')
                    ->whereBetween('snapshot_date', [$since, $today])
                    ->orderBy('snapshot_date')
                    ->select(['snapshot_date', 'total_calls', 'answered_calls', 'answer_rate'])
                    ->get();

                // Top 5 campaigns by call volume
                $campaigns = $db->table('daily_metric_snapshots')
                    ->where('granularity', 'campaign')
                    ->whereBetween('snapshot_date', [$since, $today])
                    ->whereNotNull('campaign_id')
                    ->groupBy('campaign_id')
                    ->selectRaw("campaign_id,
                        SUM(total_calls) AS total_calls,
                        SUM(answered_calls) AS answered_calls,
                        AVG(answer_rate) AS answer_rate")
                    ->orderByRaw('SUM(total_calls) DESC')
                    ->limit(5)
                    ->get();

                // Top 10 agents by talk time
                $agents = $db->table('daily_metric_snapshots')
                    ->where('granularity', 'agent')
                    ->whereBetween('snapshot_date', [$since, $today])
                    ->whereNotNull('agent_id')
                    ->groupBy('agent_id')
                    ->selectRaw("agent_id,
                        SUM(total_calls) AS total_calls,
                        SUM(answered_calls) AS answered_calls,
                        SUM(total_talk_time) AS total_talk_time,
                        AVG(answer_rate) AS answer_rate")
                    ->orderByRaw('SUM(total_talk_time) DESC')
                    ->limit(10)
                    ->get();

                return [
                    'period_days'    => $days,
                    'overall'        => $overall,
                    'daily_trend'    => $trend,
                    'top_campaigns'  => $campaigns,
                    'top_agents'     => $agents,
                    'from_snapshots' => true,
                    'generated_at'   => now()->toIso8601String(),
                ];
            });

            return response()->json(['status' => true, 'data' => $data]);

        } catch (\Throwable $exception) {
            Log::error('FastStats Error: ' . $exception->getMessage());
            return response()->json(['status' => false, 'message' => $exception->getMessage()], 500);
        }
    }

    /**
     * POST /dashboard/trigger-aggregation
     * Manually dispatch MetricsAggregationJob for today.
     */
    public function triggerAggregation(Request $request)
    {
        try {
            $clientId = $request->auth->parent_id;
            $date     = $request->input('date', Carbon::today()->toDateString());

            \App\Jobs\MetricsAggregationJob::dispatch($clientId, $date);

            return response()->json(['status' => true, 'message' => "Aggregation queued for {$date}"]);

        } catch (\Throwable $exception) {
            Log::error('TriggerAggregation Error: ' . $exception->getMessage());
            return response()->json(['status' => false, 'message' => $exception->getMessage()], 500);
        }
    }

}