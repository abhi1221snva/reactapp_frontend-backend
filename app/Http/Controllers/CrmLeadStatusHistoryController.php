<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Model\Client\CrmLeadStatusHistory;
use App\Model\User;

/**
 * @OA\Get(
 *   path="/crm/lead/{id}/status-history",
 *   summary="Get status change history for a lead",
 *   operationId="crmLeadStatusHistory",
 *   tags={"CRM"},
 *   security={{"Bearer":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Lead status history"),
 *   @OA\Response(response=401, description="Unauthenticated")
 * )
 */
class CrmLeadStatusHistoryController extends Controller
{
    /**
     * GET /crm/lead/{id}/status-history
     * Returns full status change audit trail for a lead.
     */
    public function index(Request $request, $id)
    {
        try {
            $clientId = $request->auth->parent_id;

            $history = CrmLeadStatusHistory::on("mysql_$clientId")
                ->where('lead_id', $id)
                ->orderBy('created_at', 'desc')
                ->get();

            // Attach user names
            $userIds = $history->pluck('user_id')->unique()->filter()->values();
            $users   = [];
            if ($userIds->isNotEmpty()) {
                $users = User::whereIn('id', $userIds)
                    ->get(['id', 'first_name', 'last_name'])
                    ->keyBy('id');
            }

            $result = $history->map(function ($row) use ($users) {
                $arr               = $row->toArray();
                $arr['user_name']  = isset($users[$row->user_id])
                    ? $users[$row->user_id]->first_name . ' ' . $users[$row->user_id]->last_name
                    : null;
                return $arr;
            });

            return $this->successResponse("Lead Status History", [
                'lead_id' => (int)$id,
                'total'   => $result->count(),
                'items'   => $result,
            ]);
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to load status history", [$e->getMessage()], $e, 500);
        }
    }
}
