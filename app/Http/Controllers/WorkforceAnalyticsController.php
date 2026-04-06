<?php

namespace App\Http\Controllers;

use App\Model\Client\Attendance;
use App\Model\Client\AttendanceBreak;
use App\Model\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @OA\Get(
 *   path="/workforce/analytics/attendance-trend",
 *   summary="Workforce attendance trend over time",
 *   operationId="workforceAttendanceTrend",
 *   tags={"Workforce"},
 *   security={{"Bearer":{}}},
 *   @OA\Parameter(name="days", in="query", @OA\Schema(type="integer", default=30)),
 *   @OA\Response(response=200, description="Attendance trend data"),
 *   @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Get(
 *   path="/workforce/analytics/call-availability",
 *   summary="Call vs availability analytics",
 *   operationId="workforceCallVsAvailability",
 *   tags={"Workforce"},
 *   security={{"Bearer":{}}},
 *   @OA\Parameter(name="start_date", in="query", @OA\Schema(type="string", format="date")),
 *   @OA\Parameter(name="end_date", in="query", @OA\Schema(type="string", format="date")),
 *   @OA\Response(response=200, description="Call vs availability data")
 * )
 *
 * @OA\Get(
 *   path="/workforce/analytics/break-distribution",
 *   summary="Agent break distribution analytics",
 *   operationId="workforceBreakDistribution",
 *   tags={"Workforce"},
 *   security={{"Bearer":{}}},
 *   @OA\Response(response=200, description="Break distribution data")
 * )
 *
 * @OA\Get(
 *   path="/workforce/analytics/utilization",
 *   summary="Agent utilization trend",
 *   operationId="workforceUtilizationTrend",
 *   tags={"Workforce"},
 *   security={{"Bearer":{}}},
 *   @OA\Parameter(name="start_date", in="query", @OA\Schema(type="string", format="date")),
 *   @OA\Parameter(name="end_date", in="query", @OA\Schema(type="string", format="date")),
 *   @OA\Response(response=200, description="Utilization trend data")
 * )
 *
 * @OA\Get(
 *   path="/workforce/analytics/leaderboard",
 *   summary="Agent performance leaderboard",
 *   operationId="workforceLeaderboard",
 *   tags={"Workforce"},
 *   security={{"Bearer":{}}},
 *   @OA\Parameter(name="period", in="query", @OA\Schema(type="string", enum={"today","week","month"})),
 *   @OA\Response(response=200, description="Agent leaderboard")
 * )
 */
class WorkforceAnalyticsController extends Controller
{
    private $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * GET /workforce/analytics/attendance-trend
     * Daily count of present agents over last N days.
     */
    public function attendanceTrend()
    {
        $days     = (int) $this->request->input('days', 30);
        $userTz   = $this->request->auth->timezone ?? APP_DEFAULT_USER_TIMEZONE;
        $dateFrom = Carbon::now($userTz)->subDays($days - 1)->toDateString();
        $dateTo   = Carbon::now($userTz)->toDateString();

        try {
            $rows = Attendance::whereBetween('date', [$dateFrom, $dateTo])
                ->where('status', '!=', 'absent')
                ->select(['date', DB::raw('COUNT(DISTINCT user_id) as agent_count')])
                ->groupBy('date')
                ->orderBy('date')
                ->get();

            return response()->json([
                'success' => 'true',
                'data'    => $rows,
                'labels'  => $rows->pluck('date'),
                'values'  => $rows->pluck('agent_count'),
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => 'false', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /workforce/analytics/call-vs-availability
     * Total calls vs average agents online per day over last N days.
     */
    public function callVsAvailability()
    {
        $days     = (int) $this->request->input('days', 30);
        $userTz   = $this->request->auth->timezone ?? APP_DEFAULT_USER_TIMEZONE;
        $dateFrom = Carbon::now($userTz)->subDays($days - 1)->toDateString();
        $dateTo   = Carbon::now($userTz)->toDateString();

        try {
            // Daily attendance (online agents)
            $attRows = Attendance::whereBetween('date', [$dateFrom, $dateTo])
                ->whereNotNull('clock_in_at')
                ->select(['date', DB::raw('COUNT(DISTINCT user_id) as agents_online')])
                ->groupBy('date')
                ->orderBy('date')
                ->get()
                ->keyBy('date');

            // Daily call volume from metric snapshots
            $callRows = [];
            if (DB::getSchemaBuilder()->hasTable('daily_metric_snapshots')) {
                $rows = DB::table('daily_metric_snapshots')
                    ->whereBetween('snapshot_date', [$dateFrom, $dateTo])
                    ->where('granularity', 'day')
                    ->whereNull('campaign_id')
                    ->whereNull('agent_id')
                    ->select(['snapshot_date', 'total_calls'])
                    ->orderBy('snapshot_date')
                    ->get();
                foreach ($rows as $row) {
                    $callRows[$row->snapshot_date] = $row;
                }
            }

            // Build unified daily series
            $series  = [];
            $current = Carbon::parse($dateFrom);
            $end     = Carbon::parse($dateTo);
            while ($current->lte($end)) {
                $d = $current->toDateString();
                $series[] = [
                    'date'          => $d,
                    'agents_online' => isset($attRows[$d]) ? (int) $attRows[$d]->agents_online : 0,
                    'total_calls'   => isset($callRows[$d]) ? (int) $callRows[$d]->total_calls : 0,
                ];
                $current->addDay();
            }

            return response()->json(['success' => 'true', 'data' => $series]);
        } catch (\Exception $e) {
            return response()->json(['success' => 'false', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /workforce/analytics/break-distribution
     * Break type distribution (count + total minutes) over last N days.
     */
    public function breakDistribution()
    {
        $days     = (int) $this->request->input('days', 30);
        $userTz   = $this->request->auth->timezone ?? APP_DEFAULT_USER_TIMEZONE;
        $dateFrom = Carbon::now($userTz)->subDays($days - 1)->toDateString();

        try {
            $rows = AttendanceBreak::where('break_start_at', '>=', $dateFrom)
                ->whereNotNull('break_end_at')
                ->select([
                    'break_type',
                    DB::raw('COUNT(*) as total_breaks'),
                    DB::raw('SUM(duration_minutes) as total_minutes'),
                    DB::raw('AVG(duration_minutes) as avg_minutes'),
                ])
                ->groupBy('break_type')
                ->get();

            return response()->json(['success' => 'true', 'data' => $rows]);
        } catch (\Exception $e) {
            return response()->json(['success' => 'false', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /workforce/analytics/utilization-trend
     * Average agent utilization % per day over last N days.
     */
    public function utilizationTrend()
    {
        $days     = (int) $this->request->input('days', 30);
        $userTz   = $this->request->auth->timezone ?? APP_DEFAULT_USER_TIMEZONE;
        $dateFrom = Carbon::now($userTz)->subDays($days - 1)->toDateString();
        $dateTo   = Carbon::now($userTz)->toDateString();

        try {
            // Daily attendance working hours
            $attRows = Attendance::whereBetween('date', [$dateFrom, $dateTo])
                ->whereNotNull('clock_in_at')
                ->select([
                    'date',
                    DB::raw('SUM(total_hours - break_hours) as working_hours'),
                    DB::raw('COUNT(DISTINCT user_id) as agent_count'),
                ])
                ->groupBy('date')
                ->orderBy('date')
                ->get()
                ->keyBy('date');

            // Daily talk time per day from snapshots
            $talkRows = [];
            if (DB::getSchemaBuilder()->hasTable('daily_metric_snapshots')) {
                $rows = DB::table('daily_metric_snapshots')
                    ->whereBetween('snapshot_date', [$dateFrom, $dateTo])
                    ->where('granularity', 'day')
                    ->whereNull('campaign_id')
                    ->whereNull('agent_id')
                    ->select(['snapshot_date', 'total_talk_time'])
                    ->get();
                foreach ($rows as $row) {
                    $talkRows[$row->snapshot_date] = $row;
                }
            }

            $series  = [];
            $current = Carbon::parse($dateFrom);
            $end     = Carbon::parse($dateTo);
            while ($current->lte($end)) {
                $d         = $current->toDateString();
                $workHours = isset($attRows[$d]) ? max(0, (float) $attRows[$d]->working_hours) : 0;
                $talkSecs  = isset($talkRows[$d]) ? (int) $talkRows[$d]->total_talk_time : 0;
                $talkHours = $talkSecs / 3600;
                $util      = $workHours > 0 ? round(($talkHours / $workHours) * 100, 1) : 0;

                $series[] = [
                    'date'        => $d,
                    'utilization' => min(100, $util),
                    'work_hours'  => round($workHours, 2),
                    'talk_hours'  => round($talkHours, 2),
                ];
                $current->addDay();
            }

            return response()->json(['success' => 'true', 'data' => $series]);
        } catch (\Exception $e) {
            return response()->json(['success' => 'false', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /workforce/analytics/leaderboard
     * Agent utilization leaderboard for a date range.
     */
    public function leaderboard()
    {
        $days     = (int) $this->request->input('days', 7);
        $userTz   = $this->request->auth->timezone ?? APP_DEFAULT_USER_TIMEZONE;
        $dateFrom = Carbon::now($userTz)->subDays($days - 1)->toDateString();
        $dateTo   = Carbon::now($userTz)->toDateString();
        $parentId = $this->request->auth->parent_id ?: $this->request->auth->id;

        try {
            $users = User::where(function ($q) use ($parentId) {
                    $q->where('parent_id', $parentId)->orWhere('id', $parentId);
                })
                ->where('is_deleted', false)
                ->select(['id', 'first_name', 'last_name'])
                ->get();

            $userIds = $users->pluck('id')->toArray();

            $attMap = Attendance::whereIn('user_id', $userIds)
                ->whereBetween('date', [$dateFrom, $dateTo])
                ->select(['user_id',
                    DB::raw('SUM(GREATEST(total_hours - break_hours, 0)) as work_hours'),
                    DB::raw('COUNT(*) as days_present')])
                ->groupBy('user_id')
                ->get()
                ->keyBy('user_id');

            $callMap = [];
            if (DB::getSchemaBuilder()->hasTable('daily_metric_snapshots')) {
                $rows = DB::table('daily_metric_snapshots')
                    ->whereIn('agent_id', $userIds)
                    ->whereBetween('snapshot_date', [$dateFrom, $dateTo])
                    ->where('granularity', 'agent')
                    ->select(['agent_id',
                        DB::raw('SUM(total_calls) as total_calls'),
                        DB::raw('SUM(total_talk_time) as total_talk_time')])
                    ->groupBy('agent_id')
                    ->get();
                foreach ($rows as $row) {
                    $callMap[$row->agent_id] = $row;
                }
            }

            $leaderboard = $users->map(function ($user) use ($attMap, $callMap) {
                $att       = $attMap->get($user->id);
                $calls     = $callMap[$user->id] ?? null;
                $workHrs   = $att ? (float)$att->work_hours : 0;
                $talkHrs   = $calls ? $calls->total_talk_time / 3600 : 0;
                $util      = $workHrs > 0 ? min(100, round(($talkHrs / $workHrs) * 100, 1)) : 0;

                return [
                    'user_id'          => $user->id,
                    'name'             => trim($user->first_name . ' ' . $user->last_name),
                    'days_present'     => $att ? (int)$att->days_present : 0,
                    'work_hours'       => round($workHrs, 2),
                    'talk_hours'       => round($talkHrs, 2),
                    'total_calls'      => $calls ? (int)$calls->total_calls : 0,
                    'utilization'      => $util,
                ];
            })->sortByDesc('utilization')->values()->take(20);

            return response()->json(['success' => 'true', 'data' => $leaderboard]);
        } catch (\Exception $e) {
            return response()->json(['success' => 'false', 'message' => $e->getMessage()], 500);
        }
    }
}
