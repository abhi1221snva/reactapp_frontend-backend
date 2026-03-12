<?php

namespace App\Http\Controllers;

use App\Services\TelecomFailoverService;
use Illuminate\Http\Request;

/**
 * @OA\Get(
 *   path="/telecom/failover/status",
 *   summary="Get active telecom provider and per-provider health",
 *   operationId="telecomFailoverStatus",
 *   tags={"Telecom"},
 *   security={{"Bearer":{}}},
 *   @OA\Response(response=200, description="Provider status"),
 *   @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Post(
 *   path="/telecom/failover/switch",
 *   summary="Manually switch active telecom provider",
 *   operationId="telecomFailoverSwitch",
 *   tags={"Telecom"},
 *   security={{"Bearer":{}}},
 *   @OA\RequestBody(@OA\JsonContent(required={"provider"},
 *     @OA\Property(property="provider", type="string", enum={"twilio","plivo"})
 *   )),
 *   @OA\Response(response=200, description="Provider switched"),
 *   @OA\Response(response=403, description="Admin access required")
 * )
 *
 * @OA\Post(
 *   path="/telecom/failover/reset-stats",
 *   summary="Reset failure counters for a telecom provider",
 *   operationId="telecomFailoverResetStats",
 *   tags={"Telecom"},
 *   security={{"Bearer":{}}},
 *   @OA\RequestBody(@OA\JsonContent(required={"provider"},
 *     @OA\Property(property="provider", type="string", enum={"twilio","plivo"})
 *   )),
 *   @OA\Response(response=200, description="Stats reset")
 * )
 *
 * Telecom provider failover management.
 * Admin level 7+ required.
 */
class TelecomFailoverController extends Controller
{
    private function requireAdmin(Request $request): void
    {
        if ((int) $request->auth->level < 7) {
            abort(403, 'Admin access required');
        }
    }

    /**
     * GET /telecom/failover/status
     * Returns active provider and per-provider health.
     */
    public function status(Request $request)
    {
        $status = TelecomFailoverService::forClient($request->auth->parent_id)->getStatus();
        return response()->json(['status' => true, 'data' => $status]);
    }

    /**
     * POST /telecom/failover/switch
     * Body: { provider: 'twilio'|'plivo' }
     * Manually override the active provider.
     */
    public function switchProvider(Request $request)
    {
        $this->requireAdmin($request);
        $this->validate($request, ['provider' => 'required|in:twilio,plivo']);

        TelecomFailoverService::forClient($request->auth->parent_id)
            ->setProvider($request->input('provider'));

        return response()->json(['status' => true, 'message' => 'Provider switched to ' . $request->input('provider')]);
    }

    /**
     * POST /telecom/failover/reset-stats
     * Body: { provider: 'twilio'|'plivo' }
     * Reset failure counters for a provider.
     */
    public function resetStats(Request $request)
    {
        $this->requireAdmin($request);
        $this->validate($request, ['provider' => 'required|in:twilio,plivo']);

        TelecomFailoverService::forClient($request->auth->parent_id)
            ->resetStats($request->input('provider'));

        return response()->json(['status' => true, 'message' => 'Stats reset for ' . $request->input('provider')]);
    }
}
