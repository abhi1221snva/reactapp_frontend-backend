<?php

namespace App\Http\Controllers;

use App\Model\Client\Attendance;
use App\Model\Client\AttendanceBreak;
use App\Model\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class WorkforceReportController extends Controller
{
    private $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * POST /workforce/report/productivity
     * Agent productivity: attendance hours + calls + talk time + utilization score per agent.
     * Supports filters (date_from, date_to, user_id), pagination, CSV export.
     */
    public function productivity()
    {
        $this->validate($this->request, [
            'date_from' => 'required|date',
            'date_to'   => 'required|date',
            'user_id'   => 'numeric',
            'start'     => 'numeric',
            'limit'     => 'numeric',
            'export'    => 'boolean',
        ]);

        try {
            $parentId = $this->request->auth->parent_id ?: $this->request->auth->id;
            $dateFrom = $this->request->date_from;
            $dateTo   = $this->request->date_to;
            $start    = (int) $this->request->input('start', 0);
            $limit    = (int) $this->request->input('limit', 25);
            $export   = (bool) $this->request->input('export', false);

            // Get agents
            $userQuery = User::where(function ($q) use ($parentId) {
                    $q->where('parent_id', $parentId)->orWhere('id', $parentId);
                })
                ->where('is_deleted', false)
                ->select(['id', 'first_name', 'last_name', 'email']);

            if ($this->request->has('user_id')) {
                $userQuery->where('id', $this->request->user_id);
            }

            $users   = $userQuery->get();
            $userIds = $users->pluck('id')->toArray();

            // Aggregate attendance per user for the date range
            $attSummary = Attendance::whereIn('user_id', $userIds)
                ->whereBetween('date', [$dateFrom, $dateTo])
                ->select([
                    'user_id',
                    DB::raw('SUM(total_hours) as total_attendance_hours'),
                    DB::raw('SUM(break_hours) as total_break_hours'),
                    DB::raw('SUM(overtime_hours) as total_overtime_hours'),
                    DB::raw('COUNT(*) as days_present'),
                    DB::raw('SUM(CASE WHEN is_late = 1 THEN 1 ELSE 0 END) as days_late'),
                ])
                ->groupBy('user_id')
                ->get()
                ->keyBy('user_id');

            // Aggregate call metrics per user from daily_metric_snapshots
            $callMetrics = [];
            if (DB::getSchemaBuilder()->hasTable('daily_metric_snapshots')) {
                $rows = DB::table('daily_metric_snapshots')
                    ->whereIn('agent_id', $userIds)
                    ->whereBetween('snapshot_date', [$dateFrom, $dateTo])
                    ->where('granularity', 'agent')
                    ->select([
                        'agent_id',
                        DB::raw('SUM(total_calls) as total_calls'),
                        DB::raw('SUM(total_talk_time) as total_talk_time'),
                        DB::raw('SUM(answered_calls) as answered_calls'),
                    ])
                    ->groupBy('agent_id')
                    ->get();
                foreach ($rows as $row) {
                    $callMetrics[$row->agent_id] = $row;
                }
            }

            $report = $users->map(function ($user) use ($attSummary, $callMetrics) {
                $att     = $attSummary->get($user->id);
                $calls   = $callMetrics[$user->id] ?? null;

                $attendanceHours = $att ? round((float) $att->total_attendance_hours, 2) : 0;
                $breakHours      = $att ? round((float) $att->total_break_hours, 2) : 0;
                $workingHours    = max(0, $attendanceHours - $breakHours);
                $talkTimeHours   = $calls ? round($calls->total_talk_time / 3600, 2) : 0;
                $idleHours       = max(0, round($workingHours - $talkTimeHours, 2));

                // Utilization = talk time / working hours (excluding break)
                $utilization = $workingHours > 0
                    ? round(($talkTimeHours / $workingHours) * 100, 1)
                    : 0;

                return [
                    'user_id'             => $user->id,
                    'name'                => trim($user->first_name . ' ' . $user->last_name),
                    'email'               => $user->email,
                    'days_present'        => $att ? (int) $att->days_present : 0,
                    'days_late'           => $att ? (int) $att->days_late : 0,
                    'attendance_hours'    => $attendanceHours,
                    'break_hours'         => $breakHours,
                    'working_hours'       => $workingHours,
                    'overtime_hours'      => $att ? round((float) $att->total_overtime_hours, 2) : 0,
                    'total_calls'         => $calls ? (int) $calls->total_calls : 0,
                    'answered_calls'      => $calls ? (int) $calls->answered_calls : 0,
                    'talk_time_seconds'   => $calls ? (int) $calls->total_talk_time : 0,
                    'talk_time_hours'     => $talkTimeHours,
                    'idle_hours'          => $idleHours,
                    'utilization_percent' => $utilization,
                ];
            });

            // Sort by utilization descending (leaderboard)
            $sorted = $report->sortByDesc('utilization_percent')->values();
            $total  = $sorted->count();

            // CSV export
            if ($export) {
                return $this->exportCsv($sorted->toArray(), 'productivity_report');
            }

            $paginated = $sorted->slice($start, $limit)->values();

            return response()->json([
                'success' => 'true',
                'data'    => $paginated,
                'total'   => $total,
            ]);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('WorkforceReport productivity: ' . $e->getMessage());
            return response()->json(['success' => 'false', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /workforce/report/staffing
     * Campaign staffing report: required vs actual agents per campaign per day.
     */
    public function staffing()
    {
        $this->validate($this->request, [
            'date_from'   => 'required|date',
            'date_to'     => 'required|date',
            'campaign_id' => 'numeric',
        ]);

        try {
            $dateFrom = $this->request->date_from;
            $dateTo   = $this->request->date_to;

            $query = DB::table('campaign_staffing')
                ->leftJoin('campaign', 'campaign_staffing.campaign_id', '=', 'campaign.id')
                ->select([
                    'campaign_staffing.campaign_id',
                    'campaign.title as campaign_name',
                    'campaign_staffing.required_agents',
                    'campaign_staffing.min_agents',
                    'campaign.status as is_active',
                ]);

            if ($this->request->has('campaign_id')) {
                $query->where('campaign_staffing.campaign_id', $this->request->campaign_id);
            }

            $staffingRows = $query->get();

            // For each campaign get average daily agents (from attendance)
            $result = $staffingRows->map(function ($row) use ($dateFrom, $dateTo) {
                // Count daily agent clocked-in records across date range
                $dailyCount = DB::table('attendances')
                    ->whereBetween('date', [$dateFrom, $dateTo])
                    ->whereNotNull('clock_in_at')
                    ->select('date', DB::raw('COUNT(*) as agent_count'))
                    ->groupBy('date')
                    ->get();

                $avgActual = $dailyCount->isNotEmpty()
                    ? round($dailyCount->avg('agent_count'), 1)
                    : 0;

                $minActual = $dailyCount->isNotEmpty() ? (int) $dailyCount->min('agent_count') : 0;

                return [
                    'campaign_id'      => $row->campaign_id,
                    'campaign_name'    => $row->campaign_name ?? 'Campaign ' . $row->campaign_id,
                    'required_agents'  => (int) $row->required_agents,
                    'min_agents'       => (int) $row->min_agents,
                    'avg_daily_agents' => $avgActual,
                    'min_daily_agents' => $minActual,
                    'is_active'        => (bool) $row->is_active,
                    'coverage_pct'     => $row->required_agents > 0
                        ? round(($avgActual / $row->required_agents) * 100, 1)
                        : 100,
                ];
            });

            return response()->json([
                'success' => 'true',
                'data'    => $result,
                'total'   => $result->count(),
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => 'false', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /workforce/report/idle
     * Idle time report: working hours - talk time per agent.
     */
    public function idle()
    {
        $this->validate($this->request, [
            'date_from' => 'required|date',
            'date_to'   => 'required|date',
            'user_id'   => 'numeric',
        ]);

        try {
            $parentId = $this->request->auth->parent_id ?: $this->request->auth->id;
            $dateFrom = $this->request->date_from;
            $dateTo   = $this->request->date_to;

            $users = User::where(function ($q) use ($parentId) {
                    $q->where('parent_id', $parentId)->orWhere('id', $parentId);
                })
                ->where('is_deleted', false)
                ->when($this->request->has('user_id'), fn($q) => $q->where('id', $this->request->user_id))
                ->select(['id', 'first_name', 'last_name'])
                ->get();

            $userIds = $users->pluck('id')->toArray();

            $attMap = Attendance::whereIn('user_id', $userIds)
                ->whereBetween('date', [$dateFrom, $dateTo])
                ->select(['user_id', DB::raw('SUM(total_hours) as total_hours'), DB::raw('SUM(break_hours) as break_hours')])
                ->groupBy('user_id')
                ->get()
                ->keyBy('user_id');

            $callMap = [];
            if (DB::getSchemaBuilder()->hasTable('daily_metric_snapshots')) {
                $rows = DB::table('daily_metric_snapshots')
                    ->whereIn('agent_id', $userIds)
                    ->whereBetween('snapshot_date', [$dateFrom, $dateTo])
                    ->where('granularity', 'agent')
                    ->select(['agent_id', DB::raw('SUM(total_talk_time) as total_talk_time')])
                    ->groupBy('agent_id')
                    ->get();
                foreach ($rows as $row) {
                    $callMap[$row->agent_id] = $row;
                }
            }

            $result = $users->map(function ($user) use ($attMap, $callMap) {
                $att    = $attMap->get($user->id);
                $calls  = $callMap[$user->id] ?? null;

                $workHours   = $att ? max(0, (float)$att->total_hours - (float)$att->break_hours) : 0;
                $talkHours   = $calls ? round($calls->total_talk_time / 3600, 2) : 0;
                $idleHours   = max(0, round($workHours - $talkHours, 2));
                $idlePct     = $workHours > 0 ? round(($idleHours / $workHours) * 100, 1) : 0;

                return [
                    'user_id'       => $user->id,
                    'name'          => trim($user->first_name . ' ' . $user->last_name),
                    'work_hours'    => round($workHours, 2),
                    'talk_hours'    => $talkHours,
                    'idle_hours'    => $idleHours,
                    'idle_percent'  => $idlePct,
                ];
            })->sortByDesc('idle_percent')->values();

            return response()->json(['success' => 'true', 'data' => $result, 'total' => $result->count()]);

        } catch (\Exception $e) {
            return response()->json(['success' => 'false', 'message' => $e->getMessage()], 500);
        }
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function exportCsv(array $rows, string $filename): \Illuminate\Http\Response
    {
        if (empty($rows)) {
            return response('No data', 204);
        }

        $headers = array_keys($rows[0]);
        $output  = implode(',', $headers) . "\n";
        foreach ($rows as $row) {
            $output .= implode(',', array_map(fn($v) => '"' . str_replace('"', '""', (string) $v) . '"', $row)) . "\n";
        }

        return response($output, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}_" . date('Y-m-d') . ".csv\"",
        ]);
    }
}
