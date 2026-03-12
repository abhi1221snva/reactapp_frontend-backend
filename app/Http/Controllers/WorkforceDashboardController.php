<?php

namespace App\Http\Controllers;

use App\Model\Client\AgentStatus;
use App\Model\Client\Attendance;
use App\Model\Client\AttendanceBreak;
use App\Model\Client\CampaignStaffing;
use App\Model\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @OA\Get(
 *   path="/workforce/dashboard",
 *   summary="Workforce real-time supervisor dashboard",
 *   description="Returns all agents with attendance status, dialer status, and live call metrics.",
 *   operationId="workforceDashboard",
 *   tags={"Workforce"},
 *   security={{"Bearer":{}}},
 *   @OA\Response(
 *     response=200,
 *     description="Dashboard data",
 *     @OA\JsonContent(
 *       @OA\Property(property="success", type="boolean"),
 *       @OA\Property(property="data", type="object",
 *         @OA\Property(property="agents", type="array", @OA\Items(type="object")),
 *         @OA\Property(property="summary", type="object",
 *           @OA\Property(property="total_agents", type="integer"),
 *           @OA\Property(property="clocked_in", type="integer"),
 *           @OA\Property(property="on_call", type="integer"),
 *           @OA\Property(property="on_break", type="integer")
 *         )
 *       )
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Get(
 *   path="/workforce/dashboard/agent/{userId}",
 *   summary="Get agent real-time status",
 *   operationId="workforceAgentStatus",
 *   tags={"Workforce"},
 *   security={{"Bearer":{}}},
 *   @OA\Parameter(name="userId", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Agent status details"),
 *   @OA\Response(response=404, description="Agent not found")
 * )
 */
class WorkforceDashboardController extends Controller
{
    private $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * GET /workforce/dashboard
     * Real-time supervisor dashboard: all agents with attendance + dialer status + call metrics.
     */
    public function index()
    {
        try {
            $parentId = $this->request->auth->parent_id ?: $this->request->auth->id;
            $today    = Carbon::today()->toDateString();

            // All active agents under this tenant
            $users = User::where(function ($q) use ($parentId) {
                    $q->where('parent_id', $parentId)->orWhere('id', $parentId);
                })
                ->where('is_deleted', false)
                ->select(['id', 'first_name', 'last_name', 'email', 'extension'])
                ->get();

            $userIds = $users->pluck('id')->toArray();

            // Today's attendance records
            $attendances = Attendance::whereIn('user_id', $userIds)
                ->where('date', $today)
                ->with('breaks')
                ->get()
                ->keyBy('user_id');

            // Current agent dialer statuses
            $statuses = AgentStatus::whereIn('user_id', $userIds)
                ->get()
                ->keyBy('user_id');

            // Today's call metrics from pre-aggregated snapshot (agent-level granularity)
            $metrics = [];
            if (DB::getSchemaBuilder()->hasTable('daily_metric_snapshots')) {
                $rows = DB::table('daily_metric_snapshots')
                    ->whereIn('agent_id', $userIds)
                    ->where('snapshot_date', $today)
                    ->where('granularity', 'agent')
                    ->select(['agent_id', 'total_calls', 'total_talk_time'])
                    ->get();
                foreach ($rows as $row) {
                    $metrics[$row->agent_id] = $row;
                }
            }

            // Build per-agent data
            $agents = $users->map(function ($user) use ($attendances, $statuses, $metrics) {
                $attendance = $attendances->get($user->id);
                $status     = $statuses->get($user->id);
                $metric     = $metrics[$user->id] ?? null;

                $isClockedIn = $attendance && $attendance->clock_in_at && !$attendance->clock_out_at;
                $isOnBreak   = false;
                if ($isClockedIn && $attendance->breaks) {
                    $isOnBreak = $attendance->breaks->whereNull('break_end_at')->isNotEmpty();
                }

                $dialerStatus = $status ? $status->status : 'offline';
                // If not clocked in, always show offline regardless of stored status
                if (!$isClockedIn) {
                    $dialerStatus = 'offline';
                }

                return [
                    'id'                => $user->id,
                    'name'              => trim($user->first_name . ' ' . $user->last_name),
                    'email'             => $user->email,
                    'extension'         => $user->extension,
                    'dialer_status'     => $dialerStatus,
                    'campaign_id'       => $status ? $status->campaign_id : null,
                    'login_time'        => $attendance ? $attendance->clock_in_at : null,
                    'is_clocked_in'     => $isClockedIn,
                    'is_on_break'       => $isOnBreak,
                    'calls_today'       => $metric ? (int) $metric->total_calls : 0,
                    'talk_time_today'   => $metric ? (int) $metric->total_talk_time : 0,
                    'attendance_status' => $attendance ? $attendance->status : 'absent',
                    'status_since'      => $status ? $status->last_updated_at : null,
                ];
            });

            $summary = [
                'total'           => $users->count(),
                'clocked_in'      => $agents->where('is_clocked_in', true)->count(),
                'available'       => $agents->where('dialer_status', 'available')->count(),
                'on_call'         => $agents->where('dialer_status', 'on_call')->count(),
                'on_break'        => $agents->where('dialer_status', 'on_break')->count(),
                'after_call_work' => $agents->where('dialer_status', 'after_call_work')->count(),
                'offline'         => $agents->where('dialer_status', 'offline')->count(),
            ];

            // Campaign staffing warnings
            $staffingWarnings = $this->getCampaignStaffingWarnings($parentId, $agents);

            return response()->json([
                'success' => 'true',
                'message' => 'Workforce dashboard data retrieved.',
                'data'    => [
                    'agents'           => $agents->values(),
                    'summary'          => $summary,
                    'staffing_warnings' => $staffingWarnings,
                    'last_updated'     => Carbon::now()->toIso8601String(),
                ],
            ]);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('WorkforceDashboard error: ' . $e->getMessage());
            return response()->json([
                'success' => 'false',
                'message' => 'Error loading workforce dashboard.',
                'data'    => [],
            ], 500);
        }
    }

    /**
     * GET /workforce/agent-status/{id}
     * Get a single agent's current status.
     */
    public function agentStatus(int $userId)
    {
        try {
            $status = AgentStatus::where('user_id', $userId)->first();
            $today = Carbon::today()->toDateString();
            $attendance = Attendance::where('user_id', $userId)
                ->where('date', $today)
                ->with('breaks')
                ->first();

            return response()->json([
                'success' => 'true',
                'data'    => [
                    'dialer_status' => $status ? $status->status : 'offline',
                    'campaign_id'   => $status ? $status->campaign_id : null,
                    'is_clocked_in' => $attendance && $attendance->clock_in_at && !$attendance->clock_out_at,
                    'updated_at'    => $status ? $status->last_updated_at : null,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => 'false', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Check campaign staffing levels and return warnings.
     */
    private function getCampaignStaffingWarnings(int $parentId, $agents): array
    {
        $warnings = [];

        try {
            // Active campaigns for this tenant
            $activeCampaigns = DB::table('campaign')
                ->where('status', 1)
                ->select(['id', 'title'])
                ->get();

            if ($activeCampaigns->isEmpty()) {
                return $warnings;
            }

            $staffingRows = CampaignStaffing::all()->keyBy('campaign_id');

            foreach ($activeCampaigns as $campaign) {
                $staffing = $staffingRows->get($campaign->id);
                if (!$staffing || $staffing->required_agents === 0) continue;

                // Count agents available/on_call for this campaign
                $availableForCampaign = $agents->filter(function ($a) use ($campaign) {
                    return in_array($a['dialer_status'], ['available', 'on_call'])
                        && ($a['campaign_id'] == $campaign->id || $a['campaign_id'] === null);
                })->count();

                // Broader: any clocked-in agent not on break/offline
                $clockedIn = $agents->where('is_clocked_in', true)
                    ->whereNotIn('dialer_status', ['offline', 'on_break'])->count();

                $actual = $clockedIn; // conservative: use all active agents

                if ($actual < $staffing->required_agents) {
                    $warnings[] = [
                        'campaign_id'      => $campaign->id,
                        'campaign_name'    => $campaign->title,
                        'required_agents'  => $staffing->required_agents,
                        'available_agents' => $actual,
                        'shortage'         => $staffing->required_agents - $actual,
                        'severity'         => $actual < $staffing->min_agents ? 'critical' : 'warning',
                    ];
                }
            }
        } catch (\Exception $e) {
            // Campaign table may not exist in all deployments
        }

        return $warnings;
    }
}
