<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
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
        $from = $request->input('from_date', Carbon::today()->subDays(7)->toDateString());
        $to   = $request->input('to_date', Carbon::today()->toDateString());
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
            ->whereBetween('c.created_at', [$from, $to])
            ->groupBy('c.campaign_id', 'camp.name')
            ->selectRaw("
                c.campaign_id,
                COALESCE(camp.name, CONCAT('Campaign #', c.campaign_id)) AS campaign_name,
                COUNT(*)                                                   AS total_calls,
                SUM(CASE WHEN c.status = 'answered' THEN 1 ELSE 0 END)   AS answered,
                SUM(CASE WHEN c.status = 'no-answer' THEN 1 ELSE 0 END)  AS no_answer,
                SUM(CASE WHEN c.status = 'busy' THEN 1 ELSE 0 END)       AS busy,
                SUM(CASE WHEN c.status = 'failed' THEN 1 ELSE 0 END)     AS failed,
                COALESCE(SUM(c.duration), 0)                              AS total_duration,
                COALESCE(AVG(NULLIF(c.duration, 0)), 0)                   AS avg_duration,
                ROUND(
                    SUM(CASE WHEN c.status = 'answered' THEN 1 ELSE 0 END) * 100.0 / NULLIF(COUNT(*), 0),
                2)                                                         AS answer_rate,
                COUNT(DISTINCT c.lead_id)                                  AS unique_leads
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
            ->leftJoin('users AS u', 'c.agent_id', '=', 'u.id')
            ->whereBetween('c.created_at', [$from, $to])
            ->whereNotNull('c.agent_id')
            ->groupBy('c.agent_id', 'u.name', 'u.username')
            ->selectRaw("
                c.agent_id,
                COALESCE(u.name, u.username, CONCAT('Agent #', c.agent_id)) AS agent_name,
                u.username AS extension,
                COUNT(*)                                                      AS total_calls,
                SUM(CASE WHEN c.status = 'answered' THEN 1 ELSE 0 END)      AS answered,
                SUM(CASE WHEN c.status = 'no-answer' THEN 1 ELSE 0 END)     AS no_answer,
                COALESCE(SUM(c.duration), 0)                                 AS total_talk_time,
                COALESCE(AVG(NULLIF(c.duration, 0)), 0)                      AS avg_talk_time,
                COALESCE(MAX(c.duration), 0)                                 AS max_call_duration,
                COUNT(DISTINCT c.lead_id)                                    AS unique_leads,
                COUNT(DISTINCT c.campaign_id)                                AS campaigns_worked,
                ROUND(
                    SUM(CASE WHEN c.status = 'answered' THEN 1 ELSE 0 END) * 100.0 / NULLIF(COUNT(*), 0),
                2)                                                            AS answer_rate,
                COUNT(DISTINCT DATE(c.created_at))                          AS active_days
            ");

        if ($agentId) {
            $query->where('c.agent_id', $agentId);
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
            ->whereBetween('created_at', [$from, $to])
            ->groupByRaw('HOUR(created_at)')
            ->selectRaw("
                HOUR(created_at) AS hour,
                COUNT(*) AS total_calls,
                SUM(CASE WHEN status = 'answered' THEN 1 ELSE 0 END) AS answered
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
            ->whereBetween('c.created_at', [$from, $to])
            ->groupBy('c.campaign_id', 'camp.name')
            ->selectRaw("COALESCE(camp.name,'Unknown') AS Campaign, COUNT(*) AS 'Total Calls', SUM(CASE WHEN c.status='answered' THEN 1 ELSE 0 END) AS Answered, ROUND(SUM(CASE WHEN c.status='answered' THEN 1 ELSE 0 END)*100.0/NULLIF(COUNT(*),0),2) AS 'Answer Rate %', COALESCE(SUM(c.duration),0) AS 'Total Duration (s)'")
            ->get();
    }

    private function agentProductivityData(Request $request)
    {
        [$from, $to] = $this->parseDateRange($request);
        return $this->db($request)->table('cdr AS c')
            ->leftJoin('users AS u', 'c.agent_id', '=', 'u.id')
            ->whereBetween('c.created_at', [$from, $to])
            ->whereNotNull('c.agent_id')
            ->groupBy('c.agent_id', 'u.name')
            ->selectRaw("COALESCE(u.name,'Unknown') AS Agent, COUNT(*) AS 'Total Calls', SUM(CASE WHEN c.status='answered' THEN 1 ELSE 0 END) AS Answered, COALESCE(SUM(c.duration),0) AS 'Talk Time (s)', ROUND(SUM(CASE WHEN c.status='answered' THEN 1 ELSE 0 END)*100.0/NULLIF(COUNT(*),0),2) AS 'Answer Rate %'")
            ->orderByRaw('COUNT(*) DESC')
            ->get();
    }

    private function dispositionData(Request $request)
    {
        [$from, $to] = $this->parseDateRange($request);
        return $this->db($request)->table('cdr AS c')
            ->leftJoin('disposition AS d', 'c.disposition_id', '=', 'd.id')
            ->whereBetween('c.created_at', [$from, $to])
            ->groupBy('c.disposition_id', 'd.title')
            ->selectRaw("COALESCE(d.title,'Not Set') AS Disposition, COUNT(*) AS Count")
            ->orderByRaw('COUNT(*) DESC')
            ->get();
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
