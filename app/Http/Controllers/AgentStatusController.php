<?php

namespace App\Http\Controllers;

use App\Model\Client\AgentStatus;
use Illuminate\Http\Request;

/**
 * @OA\Post(
 *   path="/workforce/agent-status",
 *   summary="Update agent dialer status (supervisor override)",
 *   operationId="agentStatusUpdate",
 *   tags={"Workforce"},
 *   security={{"Bearer":{}}},
 *   @OA\RequestBody(@OA\JsonContent(required={"user_id","status"},
 *     @OA\Property(property="user_id", type="integer"),
 *     @OA\Property(property="status", type="string", enum={"available","on_call","on_break","after_call_work","offline"}),
 *     @OA\Property(property="campaign_id", type="integer")
 *   )),
 *   @OA\Response(response=200, description="Agent status updated"),
 *   @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Get(
 *   path="/workforce/agents-online",
 *   summary="Get list of currently available/on-call agent IDs",
 *   operationId="agentStatusOnline",
 *   tags={"Workforce"},
 *   security={{"Bearer":{}}},
 *   @OA\Response(response=200, description="Online agent IDs")
 * )
 */
class AgentStatusController extends Controller
{
    private $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * POST /workforce/agent-status
     * Manually update an agent's dialer status (supervisor override).
     */
    public function update()
    {
        $this->validate($this->request, [
            'user_id'    => 'required|numeric',
            'status'     => 'required|in:available,on_call,on_break,after_call_work,offline',
            'campaign_id' => 'numeric',
        ]);

        try {
            $parentId = $this->request->auth->parent_id ?: $this->request->auth->id;
            $record   = AgentStatus::setStatus(
                (int) $this->request->user_id,
                $this->request->status,
                $this->request->has('campaign_id') ? (int) $this->request->campaign_id : null,
                $parentId
            );

            return response()->json([
                'success' => 'true',
                'message' => 'Agent status updated.',
                'data'    => $record,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 'false',
                'message' => 'Error updating agent status: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /workforce/agents-online
     * Return list of currently clocked-in (available/on_call) agent IDs.
     * Used by dialer to determine routing eligibility.
     */
    public function agentsOnline()
    {
        try {
            $online = AgentStatus::whereIn('status', [
                AgentStatus::AVAILABLE,
                AgentStatus::ON_CALL,
                AgentStatus::AFTER_CALL_WORK,
            ])->pluck('user_id');

            return response()->json([
                'success' => 'true',
                'data'    => $online,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => 'false', 'message' => $e->getMessage()], 500);
        }
    }
}
