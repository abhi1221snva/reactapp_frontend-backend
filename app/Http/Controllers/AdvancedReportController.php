<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
 * @OA\Post(
 *   path="/reports/campaign-performance",
 *   summary="Campaign performance report",
 *   operationId="reportCampaignPerformance",
 *   tags={"Reports"},
 *   security={{"Bearer":{}}},
 *   @OA\RequestBody(@OA\JsonContent(
 *     @OA\Property(property="from_date", type="string", format="date"),
 *     @OA\Property(property="to_date", type="string", format="date"),
 *     @OA\Property(property="campaign_id", type="integer")
 *   )),
 *   @OA\Response(response=200, description="Campaign performance data"),
 *   @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Post(
 *   path="/reports/agent-productivity",
 *   summary="Agent productivity report",
 *   operationId="reportAgentProductivity",
 *   tags={"Reports"},
 *   security={{"Bearer":{}}},
 *   @OA\RequestBody(@OA\JsonContent(
 *     @OA\Property(property="from_date", type="string", format="date"),
 *     @OA\Property(property="to_date", type="string", format="date"),
 *     @OA\Property(property="agent_id", type="string")
 *   )),
 *   @OA\Response(response=200, description="Agent productivity data")
 * )
 *
 * @OA\Post(
 *   path="/agent-report",
 *   summary="Agent productivity report (alias)",
 *   operationId="reportAgentProductivityAlias",
 *   tags={"Reports"},
 *   security={{"Bearer":{}}},
 *   @OA\RequestBody(@OA\JsonContent(
 *     @OA\Property(property="from_date", type="string", format="date"),
 *     @OA\Property(property="to_date", type="string", format="date")
 *   )),
 *   @OA\Response(response=200, description="Agent productivity data")
 * )
 *
 * @OA\Post(
 *   path="/reports/hourly-volume",
 *   summary="Hourly call volume report",
 *   operationId="reportHourlyVolume",
 *   tags={"Reports"},
 *   security={{"Bearer":{}}},
 *   @OA\RequestBody(@OA\JsonContent(
 *     @OA\Property(property="from_date", type="string", format="date"),
 *     @OA\Property(property="to_date", type="string", format="date"),
 *     @OA\Property(property="campaign_id", type="integer")
 *   )),
 *   @OA\Response(response=200, description="Hourly volume by 24h buckets")
 * )
 *
 * @OA\Post(
 *   path="/reports/export",
 *   summary="Export report as CSV",
 *   operationId="reportExport",
 *   tags={"Reports"},
 *   security={{"Bearer":{}}},
 *   @OA\RequestBody(@OA\JsonContent(
 *     required={"report_type"},
 *     @OA\Property(property="report_type", type="string", enum={"campaign","agent","cdr","disposition"}),
 *     @OA\Property(property="from_date", type="string", format="date"),
 *     @OA\Property(property="to_date", type="string", format="date")
 *   )),
 *   @OA\Response(response=200, description="CSV file download"),
 *   @OA\Response(response=422, description="Validation error")
 * )
 *
 * @OA\Post(
 *   path="/reports/daily",
 *   summary="Daily call report grouped by date and campaign",
 *   operationId="reportDaily",
 *   tags={"Reports"},
 *   security={{"Bearer":{}}},
 *   @OA\RequestBody(@OA\JsonContent(
 *     @OA\Property(property="from_date", type="string", format="date"),
 *     @OA\Property(property="to_date", type="string", format="date"),
 *     @OA\Property(property="campaign_id", type="integer")
 *   )),
 *   @OA\Response(response=200, description="Daily call data")
 * )
 *
 * @OA\Post(
 *   path="/reports/disposition",
 *   summary="Disposition breakdown report",
 *   operationId="reportDisposition",
 *   tags={"Reports"},
 *   security={{"Bearer":{}}},
 *   @OA\RequestBody(@OA\JsonContent(
 *     @OA\Property(property="from_date", type="string", format="date"),
 *     @OA\Property(property="to_date", type="string", format="date"),
 *     @OA\Property(property="campaign_id", type="integer")
 *   )),
 *   @OA\Response(response=200, description="Disposition counts and percentages")
 * )
 *
 * Advanced reporting endpoints:
 * - Campaign performance report
 * - Agent productivity report
 * - Disposition analysis
 * - CSV export for all reports
 */
class AdvancedReportController extends Controller
{
    private function db(Request $request)
    {
        return DB::connection('mysql_' . $request->auth->parent_id);
    }

    private function parseDateRange(Request $request): array
    {
        // Accept both from_date/to_date and date_from/date_to parameter names
        $from = $request->input('from_date',
            $request->input('date_from', Carbon::today()->subDays(7)->toDateString()));
        $to   = $request->input('to_date',
            $request->input('date_to', Carbon::today()->toDateString()));
        return [
            Carbon::parse($from)->startOfDay(),
            Carbon::parse($to)->endOfDay(),
        ];
    }

    // --- Campaign Performance Report ---

    /**
     * POST /reports/campaign-performance
     * Body: { from_date, to_date, campaign_id? }
     */
    public function campaignPerformance(Request $request)
    {
        [$from, $to] = $this->parseDateRange($request);
        $db          = $this->db($request);
        $campaignId  = $request->input('campaign_id');

        $query = $db->table('cdr AS c')
            ->leftJoin('campaign AS camp', 'c.campaign_id', '=', 'camp.id')
            ->whereBetween('c.start_time', [$from, $to])
            ->groupBy('c.campaign_id', 'camp.title')
            ->selectRaw("
                c.campaign_id,
                COALESCE(camp.title, CONCAT('Campaign #', c.campaign_id)) AS campaign_name,
                COUNT(*)                                                    AS total_calls,
                SUM(CASE WHEN c.duration > 0 THEN 1 ELSE 0 END)           AS answered,
                SUM(CASE WHEN COALESCE(c.duration,0) = 0 THEN 1 ELSE 0 END) AS no_answer,
                0                                                           AS busy,
                0                                                           AS failed,
                COALESCE(SUM(c.duration), 0)                               AS total_duration,
                COALESCE(AVG(NULLIF(c.duration, 0)), 0)                    AS avg_duration,
                ROUND(
                    SUM(CASE WHEN c.duration > 0 THEN 1 ELSE 0 END) * 100.0 / NULLIF(COUNT(*), 0),
                2)                                                          AS answer_rate,
                COUNT(DISTINCT c.lead_id)                                   AS unique_leads
            ");

        if ($campaignId) {
            $query->where('c.campaign_id', $campaignId);
        }

        $rows    = $query->orderByRaw('SUM(c.duration) DESC')->get();
        $summary = $this->summarizeCampaignReport($rows);

        return response()->json([
            'status'  => true,
            'data'    => $rows,
            'summary' => $summary,
            'filters' => ['from_date' => $from->toDateString(), 'to_date' => $to->toDateString()],
        ]);
    }

    private function summarizeCampaignReport($rows): array
    {
        return [
            'total_campaigns'     => $rows->count(),
            'total_calls'         => $rows->sum('total_calls'),
            'total_answered'      => $rows->sum('answered'),
            'overall_answer_rate' => $rows->sum('total_calls') > 0
                ? round($rows->sum('answered') / $rows->sum('total_calls') * 100, 2)
                : 0,
            'total_duration_hours' => round($rows->sum('total_duration') / 3600, 1),
        ];
    }

    // --- Agent Productivity Report ---

    /**
     * POST /reports/agent-productivity
     * Body: { from_date, to_date, agent_id? }
     */
    public function agentProductivity(Request $request)
    {
        [$from, $to] = $this->parseDateRange($request);
        $db          = $this->db($request);
        $agentId     = $request->input('agent_id');

        $query = $db->table('cdr AS c')
            ->leftJoin(DB::raw('master.users AS u'), 'u.extension', '=', 'c.extension')
            ->whereBetween('c.start_time', [$from, $to])
            ->groupBy('c.extension', 'u.id', 'u.first_name', 'u.last_name')
            ->selectRaw("
                c.extension                                                           AS agent_id,
                COALESCE(
                    NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))), ''),
                    CONCAT('Ext #', c.extension)
                )                                                                     AS agent_name,
                c.extension,
                COUNT(*)                                                              AS total_calls,
                SUM(CASE WHEN c.duration > 0 THEN 1 ELSE 0 END)                     AS answered,
                SUM(CASE WHEN COALESCE(c.duration,0) = 0 THEN 1 ELSE 0 END)         AS no_answer,
                COALESCE(SUM(c.duration), 0)                                         AS total_talk_time,
                COALESCE(AVG(NULLIF(c.duration, 0)), 0)                              AS avg_talk_time,
                COALESCE(MAX(c.duration), 0)                                         AS max_call_duration,
                COUNT(DISTINCT c.lead_id)                                            AS unique_leads,
                COUNT(DISTINCT c.campaign_id)                                        AS campaigns_worked,
                ROUND(
                    SUM(CASE WHEN c.duration > 0 THEN 1 ELSE 0 END) * 100.0 / NULLIF(COUNT(*), 0),
                2)                                                                    AS answer_rate,
                COUNT(DISTINCT DATE(c.start_time))                                   AS active_days
            ");

        if ($agentId) {
            $query->where('c.extension', $agentId);
        }

        $rows    = $query->orderByRaw('SUM(c.duration) DESC')->get();
        $summary = $this->summarizeAgentReport($rows);

        // Add rank
        $rows = $rows->values()->map(function ($row, $idx) {
            $row->rank = $idx + 1;
            return $row;
        });

        return response()->json([
            'status'  => true,
            'data'    => $rows,
            'summary' => $summary,
            'filters' => ['from_date' => $from->toDateString(), 'to_date' => $to->toDateString()],
        ]);
    }

    private function summarizeAgentReport($rows): array
    {
        return [
            'total_agents'     => $rows->count(),
            'total_calls'      => $rows->sum('total_calls'),
            'total_talk_hours' => round($rows->sum('total_talk_time') / 3600, 1),
            'avg_answer_rate'  => $rows->avg('answer_rate') ? round($rows->avg('answer_rate'), 2) : 0,
            'top_agent'        => $rows->sortByDesc('total_calls')->first()?->agent_name ?? 'N/A',
        ];
    }

    // --- Hourly Call Volume ---

    /**
     * POST /reports/hourly-volume
     * Body: { from_date, to_date, campaign_id? }
     * Returns call volume grouped by hour of day.
     */
    public function hourlyVolume(Request $request)
    {
        [$from, $to] = $this->parseDateRange($request);
        $db          = $this->db($request);

        $rows = $db->table('cdr')
            ->whereBetween('start_time', [$from, $to])
            ->groupByRaw('HOUR(start_time)')
            ->selectRaw("
                HOUR(start_time) AS hour,
                COUNT(*) AS total_calls,
                SUM(CASE WHEN duration > 0 THEN 1 ELSE 0 END) AS answered
            ")
            ->orderBy('hour')
            ->get();

        // Fill missing hours with 0
        $byHour = $rows->keyBy('hour');
        $result = collect(range(0, 23))->map(function ($h) use ($byHour) {
            $row = $byHour->get($h);
            return [
                'hour'        => $h,
                'label'       => sprintf('%02d:00', $h),
                'total_calls' => $row?->total_calls ?? 0,
                'answered'    => $row?->answered ?? 0,
            ];
        });

        return response()->json(['status' => true, 'data' => $result]);
    }

    // --- CSV Export ---

    /**
     * POST /reports/export
     * Body: { report_type: 'campaign'|'agent'|'cdr', from_date, to_date, ... }
     * Returns CSV download.
     */
    public function export(Request $request)
    {
        $this->validate($request, [
            'report_type' => 'required|in:campaign,agent,cdr,disposition',
        ]);

        $reportType = $request->input('report_type');
        $filename   = "report-{$reportType}-" . now()->format('Y-m-d') . '.csv';

        $data = match ($reportType) {
            'campaign'    => $this->campaignPerformanceData($request),
            'agent'       => $this->agentProductivityData($request),
            'disposition' => $this->dispositionData($request),
            default       => collect(),
        };

        if ($data->isEmpty()) {
            return response()->json(['status' => false, 'message' => 'No data to export']);
        }

        $csv = $this->toCsv($data);

        return response($csv, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    private function campaignPerformanceData(Request $request)
    {
        [$from, $to] = $this->parseDateRange($request);
        return $this->db($request)->table('cdr AS c')
            ->leftJoin('campaign AS camp', 'c.campaign_id', '=', 'camp.id')
            ->whereBetween('c.start_time', [$from, $to])
            ->groupBy('c.campaign_id', 'camp.title')
            ->selectRaw("COALESCE(camp.title,'Unknown') AS Campaign, COUNT(*) AS 'Total Calls', SUM(CASE WHEN c.duration > 0 THEN 1 ELSE 0 END) AS Answered, ROUND(SUM(CASE WHEN c.duration > 0 THEN 1 ELSE 0 END)*100.0/NULLIF(COUNT(*),0),2) AS 'Answer Rate %', COALESCE(SUM(c.duration),0) AS 'Total Duration (s)'")
            ->get();
    }

    private function agentProductivityData(Request $request)
    {
        [$from, $to] = $this->parseDateRange($request);
        return $this->db($request)->table('cdr AS c')
            ->leftJoin(DB::raw('master.users AS u'), 'u.extension', '=', 'c.extension')
            ->whereBetween('c.start_time', [$from, $to])
            ->groupBy('c.extension', 'u.first_name', 'u.last_name')
            ->selectRaw("COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),''),CONCAT('Ext #',c.extension)) AS Agent, c.extension AS Extension, COUNT(*) AS 'Total Calls', SUM(CASE WHEN c.duration > 0 THEN 1 ELSE 0 END) AS Answered, COALESCE(SUM(c.duration),0) AS 'Talk Time (s)', ROUND(SUM(CASE WHEN c.duration > 0 THEN 1 ELSE 0 END)*100.0/NULLIF(COUNT(*),0),2) AS 'Answer Rate %'")
            ->orderByRaw('COUNT(*) DESC')
            ->get();
    }

    private function dispositionData(Request $request)
    {
        [$from, $to] = $this->parseDateRange($request);
        return $this->db($request)->table('cdr AS c')
            ->leftJoin('disposition AS d', 'c.disposition_id', '=', 'd.id')
            ->whereBetween('c.start_time', [$from, $to])
            ->groupBy('c.disposition_id', 'd.title')
            ->selectRaw("COALESCE(d.title,'Not Set') AS Disposition, COUNT(*) AS Count")
            ->orderByRaw('COUNT(*) DESC')
            ->get();
    }

    // --- Daily Report ---

    /**
     * POST /reports/daily
     * Body: { from_date|date_from, to_date|date_to, campaign_id? }
     * Returns call counts grouped by date (and optional campaign).
     */
    public function dailyReport(Request $request)
    {
        [$from, $to] = $this->parseDateRange($request);
        $db         = $this->db($request);
        $campaignId = $request->input('campaign_id');

        $query = $db->table('cdr AS c')
            ->leftJoin('campaign AS camp', 'c.campaign_id', '=', 'camp.id')
            ->whereBetween('c.start_time', [$from, $to])
            ->groupByRaw('DATE(c.start_time), c.campaign_id, camp.title')
            ->selectRaw("
                DATE(c.start_time)                                              AS date,
                COALESCE(camp.title, CONCAT('Campaign #', c.campaign_id), 'Unknown') AS campaign,
                c.campaign_id,
                COUNT(*)                                                        AS total_calls,
                SUM(CASE WHEN c.duration > 0 THEN 1 ELSE 0 END)               AS answered,
                SUM(CASE WHEN COALESCE(c.duration,0) = 0 THEN 1 ELSE 0 END)   AS missed,
                0                                                               AS busy,
                0                                                               AS failed,
                COALESCE(SUM(c.duration), 0)                                   AS total_duration,
                COALESCE(AVG(NULLIF(c.duration, 0)), 0)                        AS avg_duration,
                ROUND(
                    SUM(CASE WHEN c.duration > 0 THEN 1 ELSE 0 END) * 100.0 / NULLIF(COUNT(*), 0),
                2)                                                              AS answer_rate
            ")
            ->orderByRaw('DATE(c.start_time) DESC');

        if ($campaignId) {
            $query->where('c.campaign_id', $campaignId);
        }

        $rows = $query->get();

        return response()->json([
            'status'  => true,
            'data'    => $rows,
            'filters' => ['from_date' => $from->toDateString(), 'to_date' => $to->toDateString()],
        ]);
    }

    // --- Disposition Report ---

    /**
     * POST /reports/disposition
     * Body: { from_date|date_from, to_date|date_to, campaign_id? }
     * Returns call counts grouped by disposition.
     */
    public function dispositionReport(Request $request)
    {
        [$from, $to] = $this->parseDateRange($request);
        $db         = $this->db($request);
        $campaignId = $request->input('campaign_id');

        $query = $db->table('cdr AS c')
            ->leftJoin('disposition AS d', 'c.disposition_id', '=', 'd.id')
            ->whereBetween('c.start_time', [$from, $to])
            ->groupBy('c.disposition_id', 'd.title')
            ->selectRaw("
                COALESCE(d.title, 'Not Set')  AS disposition,
                COALESCE(d.title, 'Not Set')  AS title,
                COUNT(*)                      AS count,
                COUNT(*)                      AS total
            ")
            ->orderByRaw('COUNT(*) DESC');

        if ($campaignId) {
            $query->where('c.campaign_id', $campaignId);
        }

        $rows    = $query->get();
        $total   = $rows->sum('count');

        $rows = $rows->map(function ($r) use ($total) {
            $r->percentage = $total > 0 ? round($r->count / $total * 100, 1) : 0;
            return $r;
        });

        return response()->json([
            'status'  => true,
            'data'    => $rows,
            'total'   => $total,
            'filters' => ['from_date' => $from->toDateString(), 'to_date' => $to->toDateString()],
        ]);
    }

    private function toCsv($collection): string
    {
        if ($collection->isEmpty()) return '';

        $rows  = $collection->map(fn($r) => (array) $r)->values();
        $heads = array_keys($rows->first());

        $lines = [$this->csvLine($heads)];
        foreach ($rows as $row) {
            $lines[] = $this->csvLine(array_values($row));
        }
        return implode("\n", $lines);
    }

    private function csvLine(array $values): string
    {
        return implode(',', array_map(function ($v) {
            $v = str_replace('"', '""', (string) $v);
            return '"' . $v . '"';
        }, $values));
    }
}
