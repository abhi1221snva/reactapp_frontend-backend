<?php

namespace App\Http\Controllers;

use App\Services\CallMonitoringService;
use Illuminate\Http\Request;

/**
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
