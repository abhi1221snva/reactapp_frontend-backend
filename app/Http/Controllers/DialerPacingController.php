<?php

namespace App\Http\Controllers;

use App\Services\CallPacingService;
use App\Services\AgentAvailabilityService;
use App\Services\PredictiveDialerService;
use Illuminate\Http\Request;

/**
 * @OA\Get(
 *   path="/dialer/pacing/{campaignId}",
 *   summary="Get pacing snapshot for a campaign",
 *   operationId="dialerPacingSnapshot",
 *   tags={"Dialer"},
 *   security={{"Bearer":{}}},
 *   @OA\Parameter(name="campaignId", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Pacing snapshot with agent breakdown"),
 *   @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Post(
 *   path="/dialer/pacing/{campaignId}/agent-state",
 *   summary="Agent updates their availability state",
 *   operationId="dialerPacingAgentState",
 *   tags={"Dialer"},
 *   security={{"Bearer":{}}},
 *   @OA\Parameter(name="campaignId", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\RequestBody(@OA\JsonContent(required={"state"},
 *     @OA\Property(property="state", type="string", enum={"available","wrapping","paused"})
 *   )),
 *   @OA\Response(response=200, description="Agent state updated")
 * )
 *
 * @OA\Post(
 *   path="/dialer/pacing/{campaignId}/heartbeat",
 *   summary="Agent heartbeat to stay alive in Redis",
 *   operationId="dialerPacingHeartbeat",
 *   tags={"Dialer"},
 *   security={{"Bearer":{}}},
 *   @OA\Parameter(name="campaignId", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Heartbeat recorded")
 * )
 *
 * @OA\Post(
 *   path="/dialer/pacing/{campaignId}/record-outcome",
 *   summary="Record call outcome for pacing algorithm",
 *   operationId="dialerPacingRecordOutcome",
 *   tags={"Dialer"},
 *   security={{"Bearer":{}}},
 *   @OA\Parameter(name="campaignId", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\RequestBody(@OA\JsonContent(required={"outcome"},
 *     @OA\Property(property="outcome", type="string", enum={"answered","abandoned","no_answer","busy","failed"}),
 *     @OA\Property(property="handle_time", type="integer", example=45)
 *   )),
 *   @OA\Response(response=200, description="Outcome recorded")
 * )
 *
 * @OA\Post(
 *   path="/dialer/pacing/{campaignId}/reset",
 *   summary="Reset pacing counters for a campaign",
 *   operationId="dialerPacingReset",
 *   tags={"Dialer"},
 *   security={{"Bearer":{}}},
 *   @OA\Parameter(name="campaignId", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Pacing counters reset"),
 *   @OA\Response(response=403, description="Supervisor access required")
 * )
 *
 * Dialer Pacing API — exposes real-time pacing data and agent state updates.
 */
class DialerPacingController extends Controller
{
    /**
     * GET /dialer/pacing/{campaignId}
     * Full pacing snapshot (supervisor dashboard).
     */
    public function snapshot(Request $request, int $campaignId)
    {
        $clientId    = $request->auth->parent_id;
        $pacing      = CallPacingService::for($clientId, $campaignId);
        $availability = AgentAvailabilityService::forClient($clientId);

        $data = array_merge(
            $pacing->getSnapshot(),
            ['agent_breakdown' => $availability->getCampaignBreakdown($campaignId)]
        );

        return response()->json(['status' => true, 'data' => $data]);
    }

    /**
     * POST /dialer/pacing/{campaignId}/agent-state
     * Agent updates their availability state.
     * Body: { state: 'available'|'wrapping'|'paused' }
     */
    public function updateAgentState(Request $request, int $campaignId)
    {
        $this->validate($request, [
            'state' => 'required|in:available,wrapping,paused',
        ]);

        $clientId = $request->auth->parent_id;
        $agentId  = $request->auth->id;

        $availability = AgentAvailabilityService::forClient($clientId);
        $availability->setAgentState($agentId, $request->input('state'), $campaignId);

        // Sync available count into pacing service
        $availCount = $availability->getAvailableAgentCount($campaignId);
        CallPacingService::for($clientId, $campaignId)->updateAvailableAgents($availCount);

        return response()->json(['status' => true, 'message' => 'Agent state updated']);
    }

    /**
     * POST /dialer/pacing/{campaignId}/heartbeat
     * Keep agent alive in Redis (every 30s).
     */
    public function heartbeat(Request $request, int $campaignId)
    {
        AgentAvailabilityService::forClient($request->auth->parent_id)
            ->heartbeat($request->auth->id);

        return response()->json(['status' => true]);
    }

    /**
     * POST /dialer/pacing/{campaignId}/record-outcome
     * Record a call outcome for pacing algorithm.
     * Body: { outcome: 'answered'|'abandoned'|'no_answer'|'busy'|'failed', handle_time?: 45 }
     */
    public function recordOutcome(Request $request, int $campaignId)
    {
        $this->validate($request, [
            'outcome'     => 'required|in:answered,abandoned,no_answer,busy,failed',
            'handle_time' => 'nullable|integer|min:0',
        ]);

        $clientId = $request->auth->parent_id;
        $pacing   = CallPacingService::for($clientId, $campaignId);
        $pacing->recordCallPlaced();

        $outcome     = $request->input('outcome');
        $handleTime  = (int) $request->input('handle_time', 0);

        if ($outcome === 'answered') {
            $pacing->recordCallAnswered($handleTime);
        } elseif ($outcome === 'abandoned') {
            $pacing->recordCallAbandoned();
        }

        // Also update the legacy service (backward compatibility)
        PredictiveDialerService::recordCallOutcome($clientId, $campaignId, $outcome === 'answered' ? 'connected' : $outcome);

        return response()->json(['status' => true]);
    }

    /**
     * POST /dialer/pacing/{campaignId}/reset
     * Reset pacing counters (admin/supervisor).
     */
    public function reset(Request $request, int $campaignId)
    {
        if ($request->auth->level < 5) {
            return response()->json(['status' => false, 'message' => 'Supervisor access required'], 403);
        }

        CallPacingService::for($request->auth->parent_id, $campaignId)->reset();
        return response()->json(['status' => true, 'message' => 'Pacing counters reset']);
    }
}
