<?php

namespace App\Http\Controllers;

use App\Services\CallMonitoringService;
use Illuminate\Http\Request;

/**
 * @OA\Post(
 *   path="/monitoring/listen",
 *   summary="Listen to an active call (silent monitoring)",
 *   operationId="monitoringListen",
 *   tags={"Call Monitoring"},
 *   security={{"Bearer":{}}},
 *   @OA\RequestBody(@OA\JsonContent(required={"call_sid","supervisor_endpoint"},
 *     @OA\Property(property="call_sid", type="string"),
 *     @OA\Property(property="supervisor_endpoint", type="string")
 *   )),
 *   @OA\Response(response=200, description="Monitor session started"),
 *   @OA\Response(response=403, description="Supervisor access required")
 * )
 *
 * @OA\Post(
 *   path="/monitoring/whisper",
 *   summary="Whisper to agent only (caller cannot hear)",
 *   operationId="monitoringWhisper",
 *   tags={"Call Monitoring"},
 *   security={{"Bearer":{}}},
 *   @OA\RequestBody(@OA\JsonContent(required={"call_sid","supervisor_endpoint"},
 *     @OA\Property(property="call_sid", type="string"),
 *     @OA\Property(property="supervisor_endpoint", type="string")
 *   )),
 *   @OA\Response(response=200, description="Whisper session started")
 * )
 *
 * @OA\Post(
 *   path="/monitoring/barge",
 *   summary="Barge into call (all parties can hear)",
 *   operationId="monitoringBarge",
 *   tags={"Call Monitoring"},
 *   security={{"Bearer":{}}},
 *   @OA\RequestBody(@OA\JsonContent(required={"call_sid","supervisor_endpoint"},
 *     @OA\Property(property="call_sid", type="string"),
 *     @OA\Property(property="supervisor_endpoint", type="string")
 *   )),
 *   @OA\Response(response=200, description="Barge session started")
 * )
 *
 * @OA\Post(
 *   path="/monitoring/stop",
 *   summary="Stop active monitoring session",
 *   operationId="monitoringStop",
 *   tags={"Call Monitoring"},
 *   security={{"Bearer":{}}},
 *   @OA\RequestBody(@OA\JsonContent(required={"monitor_call_sid"},
 *     @OA\Property(property="monitor_call_sid", type="string")
 *   )),
 *   @OA\Response(response=200, description="Monitor session stopped")
 * )
 *
 * @OA\Get(
 *   path="/monitoring/active",
 *   summary="List all active monitor sessions",
 *   operationId="monitoringActive",
 *   tags={"Call Monitoring"},
 *   security={{"Bearer":{}}},
 *   @OA\Response(response=200, description="Active monitor sessions")
 * )
 *
 * Real-time call monitoring for supervisors.
 * Minimum level 5 (supervisor) required for all endpoints.
 */
class CallMonitoringController extends Controller
{
    private function requireSupervisor(Request $request): void
    {
        if ((int) $request->auth->level < 5) {
            abort(403, 'Supervisor access required');
        }
    }

    /**
     * POST /monitoring/listen
     * Body: { call_sid, supervisor_endpoint }
     */
    public function listen(Request $request)
    {
        $this->requireSupervisor($request);
        $this->validate($request, [
            'call_sid'            => 'required|string',
            'supervisor_endpoint' => 'required|string',
        ]);

        $result = CallMonitoringService::forClient($request->auth->parent_id)
            ->listen($request->input('call_sid'), $request->input('supervisor_endpoint'));

        return response()->json(['status' => $result['success'] ?? false, 'data' => $result]);
    }

    /**
     * POST /monitoring/whisper
     * Body: { call_sid, supervisor_endpoint }
     */
    public function whisper(Request $request)
    {
        $this->requireSupervisor($request);
        $this->validate($request, [
            'call_sid'            => 'required|string',
            'supervisor_endpoint' => 'required|string',
        ]);

        $result = CallMonitoringService::forClient($request->auth->parent_id)
            ->whisper($request->input('call_sid'), $request->input('supervisor_endpoint'));

        return response()->json(['status' => $result['success'] ?? false, 'data' => $result]);
    }

    /**
     * POST /monitoring/barge
     * Body: { call_sid, supervisor_endpoint }
     */
    public function barge(Request $request)
    {
        $this->requireSupervisor($request);
        $this->validate($request, [
            'call_sid'            => 'required|string',
            'supervisor_endpoint' => 'required|string',
        ]);

        $result = CallMonitoringService::forClient($request->auth->parent_id)
            ->barge($request->input('call_sid'), $request->input('supervisor_endpoint'));

        return response()->json(['status' => $result['success'] ?? false, 'data' => $result]);
    }

    /**
     * POST /monitoring/stop
     * Body: { monitor_call_sid }
     */
    public function stop(Request $request)
    {
        $this->requireSupervisor($request);
        $this->validate($request, [
            'monitor_call_sid' => 'required|string',
        ]);

        $result = CallMonitoringService::forClient($request->auth->parent_id)
            ->stopMonitoring($request->input('monitor_call_sid'));

        return response()->json(['status' => $result['success'] ?? false, 'data' => $result]);
    }

    /**
     * GET /monitoring/active
     * Returns all active monitor sessions.
     */
    public function activeMonitors(Request $request)
    {
        $this->requireSupervisor($request);
        $monitors = CallMonitoringService::forClient($request->auth->parent_id)
            ->getAllActiveMonitors();

        return response()->json(['status' => true, 'data' => $monitors]);
    }
}
