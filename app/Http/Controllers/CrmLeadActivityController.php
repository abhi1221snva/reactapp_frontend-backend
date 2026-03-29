<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Model\Client\CrmLeadActivity;
use App\Model\Client\Lead;
use App\Model\User;
use Illuminate\Support\Facades\DB;

/**
 * @OA\Get(
 *   path="/crm/lead/{id}/activity",
 *   summary="Get activity timeline for a lead",
 *   operationId="crmLeadActivityTimeline",
 *   tags={"CRM"},
 *   security={{"Bearer":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Activity timeline"),
 *   @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Put(
 *   path="/crm/lead/{id}/activity",
 *   summary="Add a manual activity entry to a lead",
 *   operationId="crmLeadActivityAdd",
 *   tags={"CRM"},
 *   security={{"Bearer":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\RequestBody(@OA\JsonContent(
 *     @OA\Property(property="type", type="string"),
 *     @OA\Property(property="note", type="string")
 *   )),
 *   @OA\Response(response=200, description="Activity added")
 * )
 *
 * @OA\Post(
 *   path="/crm/lead/{id}/activity/{aid}/pin",
 *   summary="Pin an activity entry",
 *   operationId="crmLeadActivityPin",
 *   tags={"CRM"},
 *   security={{"Bearer":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Parameter(name="aid", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Activity pinned")
 * )
 */
class CrmLeadActivityController extends Controller
{
    /**
     * GET /crm/lead/{id}/activity
     * Returns unified timeline for a lead — merges crm_lead_activity,
     * crm_notifications, and crm_log into a single chronological feed.
     */
    public function timeline(Request $request, $id)
    {
        try {
            $clientId    = $request->auth->parent_id;
            $limit       = min((int)($request->input('limit', 50)), 200);
            $offset      = (int)$request->input('offset', 0);
            // Support ?type=system (single) and ?types[]=system (array)
            $types = (array) $request->input('types', []);
            if (empty($types) && $request->has('type')) {
                $types = [$request->input('type')];
            }
            $includeSrc  = $request->input('include_sources', 'crm_lead_activity');

            // Base: crm_lead_activity
            $query = CrmLeadActivity::on("mysql_$clientId")
                ->where('lead_id', $id)
                ->select([
                    'id', 'lead_id', 'user_id', 'activity_type',
                    'subject', 'body', 'meta', 'is_pinned', 'created_at',
                ]);

            if (!empty($types)) {
                $query->whereIn('activity_type', $types);
            }

            $total   = $query->count();
            $items   = $query->orderBy('created_at', 'desc')
                             ->skip($offset)
                             ->take($limit)
                             ->get();

            // Attach user names
            $userIds = $items->pluck('user_id')->filter()->unique()->values();
            $users   = [];
            if ($userIds->isNotEmpty()) {
                $users = User::whereIn('id', $userIds)
                    ->get(['id', 'first_name', 'last_name'])
                    ->keyBy('id');
            }

            $result = $items->map(function ($item) use ($users) {
                $arr              = $item->toArray();
                $arr['user_name'] = isset($users[$item->user_id])
                    ? $users[$item->user_id]->first_name . ' ' . $users[$item->user_id]->last_name
                    : null;
                return $arr;
            });

            // Optionally merge crm_notifications (notes & updates)
            if (str_contains($includeSrc, 'crm_notifications')) {
                $notes = DB::connection("mysql_$clientId")
                    ->table('crm_notifications')
                    ->where('lead_id', $id)
                    ->orderBy('id', 'desc')
                    ->get(['id', 'user_id', 'lead_id', 'message', 'type', 'created_at']);

                foreach ($notes as $n) {
                    $result->push([
                        'id'            => 'n_' . $n->id,
                        'lead_id'       => $n->lead_id,
                        'user_id'       => $n->user_id,
                        'activity_type' => $n->type == 1 ? 'note_added' : 'system',
                        'subject'       => $n->message,
                        'body'          => null,
                        'meta'          => ['notification_type' => $n->type],
                        'is_pinned'     => false,
                        'user_name'     => isset($users[$n->user_id])
                            ? $users[$n->user_id]->first_name . ' ' . $users[$n->user_id]->last_name
                            : null,
                        'created_at'    => $n->created_at,
                        'source'        => 'crm_notifications',
                    ]);
                }
            }

            // Sort merged result by created_at desc
            $sorted = $result->sortByDesc('created_at')->values();

            return $this->successResponse("Lead Activity Timeline", [
                'lead_id'  => (int)$id,
                'total'    => $total,
                'offset'   => $offset,
                'limit'    => $limit,
                'has_more' => ($offset + $limit) < $total,
                'items'    => $sorted,
            ]);
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to load activity timeline", [$e->getMessage()], $e, 500);
        }
    }

    /**
     * PUT /crm/lead/{id}/activity
     * Manually log an activity entry (e.g. a call note, external email).
     */
    public function addManualEntry(Request $request, $id)
    {
        $this->validate($request, [
            'activity_type' => 'required|string',
            'subject'       => 'required|string|max:500',
        ]);

        try {
            $clientId = $request->auth->parent_id;

            $activity = new CrmLeadActivity();
            $activity->setConnection("mysql_$clientId");
            $activity->lead_id       = $id;
            $activity->user_id       = $request->auth->id;
            $activity->activity_type = $request->input('activity_type', 'system');
            $activity->subject       = $request->input('subject');
            $activity->body          = $request->input('body');
            $activity->meta          = $request->input('meta') ?: null;
            $activity->source_type   = 'manual';
            $activity->is_pinned     = $request->input('is_pinned', false);
            $activity->saveOrFail();

            return $this->successResponse("Activity logged successfully", $activity->toArray());
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to log activity", [$e->getMessage()], $e, 500);
        }
    }

    /**
     * POST /crm/lead/{id}/activity/{aid}/pin
     * Toggle pin on an activity entry.
     */
    public function pin(Request $request, $id, $aid)
    {
        try {
            $clientId = $request->auth->parent_id;
            $activity = CrmLeadActivity::on("mysql_$clientId")
                ->where('lead_id', $id)
                ->findOrFail($aid);

            $activity->is_pinned = !$activity->is_pinned;
            $activity->save();

            return $this->successResponse(
                $activity->is_pinned ? "Activity pinned" : "Activity unpinned",
                $activity->toArray()
            );
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to pin activity", [$e->getMessage()], $e, 500);
        }
    }
}
