<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Model\Client\CrmLeadActivity;
use App\Models\Client\LeadChangeLog;
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
     * Returns unified timeline for a lead — merges crm_lead_activity and
     * crm_notifications into a single chronological feed.
     */
    public function timeline(Request $request, $id)
    {
        try {
            $clientId = $request->auth->parent_id;
            $limit    = min((int)($request->input('limit', 50)), 200);
            $offset   = (int)$request->input('offset', 0);

            // Support ?type=system (single) and ?types[]=system (array)
            $types = (array) $request->input('types', []);
            if (empty($types) && $request->has('type')) {
                $types = [$request->input('type')];
            }

            // ── Base: crm_lead_activity ───────────────────────────────────────
            $query = CrmLeadActivity::on("mysql_$clientId")
                ->where('lead_id', $id)
                ->select(['id', 'lead_id', 'user_id', 'activity_type',
                          'subject', 'body', 'meta', 'is_pinned', 'created_at']);

            // When filtering by email_sent, also include email_failed
            if (!empty($types) && in_array('email_sent', $types) && !in_array('email_failed', $types)) {
                $types[] = 'email_failed';
            }

            if (!empty($types)) {
                $query->whereIn('activity_type', $types);
            }

            $total = $query->count();
            $items = $query->orderBy('created_at', 'desc')->skip($offset)->take($limit)->get();

            // ── User map ──────────────────────────────────────────────────────
            $userIds = $items->pluck('user_id')->filter()->unique()->values();
            $users   = $userIds->isNotEmpty()
                ? User::whereIn('id', $userIds)->get(['id', 'first_name', 'last_name'])->keyBy('id')
                : collect();

            $result = $items->map(function ($item) use ($users) {
                $arr              = $item->toArray();
                $arr['user_name'] = $users->has($item->user_id)
                    ? $users[$item->user_id]->first_name . ' ' . $users[$item->user_id]->last_name
                    : null;
                return $arr;
            });

            // ── Always merge crm_notifications (lender API history) ───────────
            $notes = DB::connection("mysql_$clientId")
                ->table('crm_notifications')
                ->where('lead_id', $id)
                ->orderBy('id', 'desc')
                ->limit(300)
                ->get(['id', 'user_id', 'lead_id', 'message', 'type', 'created_at']);

            if ($notes->isNotEmpty()) {
                // Extend user map with any notification-only user IDs
                $extraIds = $notes->pluck('user_id')->filter()->unique()->diff($userIds)->values();
                if ($extraIds->isNotEmpty()) {
                    $extraUsers = User::whereIn('id', $extraIds)
                        ->get(['id', 'first_name', 'last_name'])->keyBy('id');
                    $users = $users->merge($extraUsers);
                }

                foreach ($notes as $n) {
                    $mapped = $this->mapNotification($n, $users);
                    // Apply type filter
                    if (empty($types) || in_array($mapped['activity_type'], $types)) {
                        $result->push($mapped);
                    }
                }
            }

            // ── Sort merged result by created_at desc ─────────────────────────
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
     * Map a crm_notifications row to a unified timeline activity array.
     * Detects lender API messages and extracts lender name, success, doc filename.
     */
    private function mapNotification(object $n, $users): array
    {
        $rawMsg   = (string)($n->message ?? '');
        $cleanMsg = trim(html_entity_decode(strip_tags($rawMsg)));

        // Detect lender-related messages
        $isLender = (
            stripos($rawMsg,   'Lender ')             !== false ||
            stripos($cleanMsg, 'Document Sent')        !== false ||
            stripos($cleanMsg, 'consentAccepted')      !== false ||
            stripos($cleanMsg, 'attachment was received') !== false
        );

        $activityType = $isLender ? 'lender_api_result' : ($n->type == 1 ? 'note_added' : 'system');

        // Parse lender name from trailing ( LenderName ) or (LenderName)
        $lenderName = null;
        if (preg_match('/\(\s*([^)]+?)\s*\)\s*$/', $cleanMsg, $m)) {
            $lenderName = trim($m[1]);
        }

        // Determine success / failure
        $isSuccess   = null;
        $isDocUpload = stripos($cleanMsg, 'Document Sent file name is') !== false;

        if ($isLender && !$isDocUpload) {
            foreach (['successfully', 'consentAccepted', 'being processed'] as $kw) {
                if (stripos($cleanMsg, $kw) !== false) { $isSuccess = true; break; }
            }
            if ($isSuccess === null) {
                foreach (['went wrong', 'Validation Error', 'declined', 'already started',
                          'must be', 'should be', 'Error:', 'failed', 'invalid'] as $kw) {
                    if (stripos($cleanMsg, $kw) !== false) { $isSuccess = false; break; }
                }
            }
        }

        $meta = ['source' => 'notification'];
        if ($lenderName)         $meta['lender_name'] = $lenderName;
        if ($isSuccess !== null) $meta['success']      = $isSuccess;
        if ($isDocUpload && preg_match('/file name is\s+(\S+)/i', $cleanMsg, $fm)) {
            $meta['doc_filename'] = $fm[1];
        }

        $userName = null;
        if ($n->user_id && $users->has($n->user_id)) {
            $u = $users[$n->user_id];
            $userName = trim($u->first_name . ' ' . $u->last_name) ?: null;
        }

        return [
            'id'            => 'n_' . $n->id,
            'lead_id'       => $n->lead_id,
            'user_id'       => $n->user_id,
            'activity_type' => $activityType,
            'subject'       => $cleanMsg,
            'body'          => null,
            'meta'          => $meta,
            'is_pinned'     => false,
            'user_name'     => $userName,
            'created_at'    => $n->created_at,
            'source'        => 'crm_notifications',
        ];
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

    /**
     * GET /crm/lead/{id}/change-logs
     * Returns the field-level audit trail from lead_change_logs.
     */
    public function changeLogs(Request $request, $id)
    {
        try {
            $clientId = $request->auth->parent_id;
            $conn     = "mysql_{$clientId}";
            $limit    = min((int)($request->input('limit', 50)), 200);
            $offset   = (int)$request->input('offset', 0);
            $source   = $request->input('source');

            $query = DB::connection($conn)
                ->table('lead_change_logs')
                ->where('lead_id', $id);

            if ($source) {
                $query->where('source', $source);
            }

            $total = $query->count();
            $items = $query->orderBy('created_at', 'desc')
                ->skip($offset)
                ->take($limit)
                ->get();

            // Decode JSON changes column
            $items->transform(function ($item) {
                if (is_string($item->changes)) {
                    $item->changes = json_decode($item->changes, true);
                }
                return $item;
            });

            // Hydrate user names
            $userIds = $items->pluck('user_id')->filter()->unique()->values();
            $users = $userIds->isNotEmpty()
                ? User::whereIn('id', $userIds)->get(['id', 'first_name', 'last_name'])->keyBy('id')
                : collect();

            $items->transform(function ($item) use ($users) {
                $item->user_name = null;
                if ($item->user_id && $users->has($item->user_id)) {
                    $u = $users[$item->user_id];
                    $item->user_name = trim($u->first_name . ' ' . $u->last_name) ?: null;
                }
                return $item;
            });

            return $this->successResponse("Lead Change Logs", [
                'lead_id'  => (int)$id,
                'total'    => $total,
                'offset'   => $offset,
                'limit'    => $limit,
                'has_more' => ($offset + $limit) < $total,
                'items'    => $items->values(),
            ]);
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to load change logs", [$e->getMessage()], $e, 500);
        }
    }
}
