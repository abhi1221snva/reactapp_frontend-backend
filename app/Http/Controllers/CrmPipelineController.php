<?php

namespace App\Http\Controllers;

use App\Services\LeadVisibilityService;
use Illuminate\Http\Request;
use App\Model\Client\CrmPipelineView;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

/**
 * @OA\Get(
 *   path="/crm/pipeline/board",
 *   summary="Get CRM pipeline board with leads grouped by status",
 *   operationId="crmPipelineBoard",
 *   tags={"CRM Pipeline"},
 *   security={{"Bearer":{}}},
 *   @OA\Response(response=200, description="Pipeline board"),
 *   @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Patch(
 *   path="/crm/pipeline/leads/{id}/move",
 *   summary="Move a lead to a different pipeline stage",
 *   operationId="crmPipelineMoveLead",
 *   tags={"CRM Pipeline"},
 *   security={{"Bearer":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\RequestBody(@OA\JsonContent(
 *     @OA\Property(property="status_id", type="integer"),
 *     @OA\Property(property="position", type="integer")
 *   )),
 *   @OA\Response(response=200, description="Lead moved")
 * )
 *
 * @OA\Get(
 *   path="/crm/pipeline/views",
 *   summary="List saved pipeline views",
 *   operationId="crmPipelineListViews",
 *   tags={"CRM Pipeline"},
 *   security={{"Bearer":{}}},
 *   @OA\Response(response=200, description="Pipeline views")
 * )
 *
 * @OA\Put(
 *   path="/crm/pipeline/views",
 *   summary="Create a pipeline view",
 *   operationId="crmPipelineCreateView",
 *   tags={"CRM Pipeline"},
 *   security={{"Bearer":{}}},
 *   @OA\RequestBody(@OA\JsonContent(
 *     @OA\Property(property="name", type="string"),
 *     @OA\Property(property="filters", type="object")
 *   )),
 *   @OA\Response(response=200, description="View created")
 * )
 *
 * @OA\Post(
 *   path="/crm/pipeline/views/{id}",
 *   summary="Update a pipeline view",
 *   operationId="crmPipelineUpdateView",
 *   tags={"CRM Pipeline"},
 *   security={{"Bearer":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="View updated")
 * )
 *
 * @OA\Delete(
 *   path="/crm/pipeline/views/{id}",
 *   summary="Delete a pipeline view",
 *   operationId="crmPipelineDeleteView",
 *   tags={"CRM Pipeline"},
 *   security={{"Bearer":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="View deleted")
 * )
 */
class CrmPipelineController extends Controller
{
    /**
     * GET /crm/pipeline/board
     * Kanban-style pipeline board — leads grouped by status.
     * Agents (user_level <= 1) only see their own assigned leads.
     */
    public function board(Request $request)
    {
        try {
            $clientId   = $request->auth->parent_id;
            $userId     = $request->auth->id;
            $userLevel  = $request->auth->user_level ?? 0;
            $cardsLimit = min((int)$request->input('cards_limit', 50), 200);
            $offset     = (int)$request->input('cards_offset', 0);
            $filterStatus    = $request->input('status', []);
            $filterAssigned  = $request->input('assigned_to', []);

            // Get pipeline stages
            $statusQuery = DB::connection("mysql_$clientId")
                ->table('crm_lead_status')
                ->where('status', '1')
                ->orderBy('display_order');

            if (!empty($filterStatus)) {
                $statusQuery->whereIn('lead_title_url', $filterStatus);
            }

            $stages = $statusQuery->get(['id', 'title', 'lead_title_url', 'color_code', 'display_order']);

            $columns    = [];
            $totalLeads = 0;

            foreach ($stages as $stage) {
                $cacheKey = "pipeline_board_{$clientId}_{$stage->lead_title_url}_count";

                // Base lead query for this stage
                $baseQuery = DB::connection("mysql_$clientId")
                    ->table('crm_lead_data')
                    ->where('lead_status', $stage->lead_title_url)
                    ->where('is_deleted', 0);

                // Role-based visibility scope
                (new LeadVisibilityService())->applyVisibilityScope($baseQuery, $request->auth, (int) $clientId);

                if (!empty($filterAssigned)) {
                    $baseQuery->whereIn('assigned_to', $filterAssigned);
                }

                // Count (cached 60s)
                $count = Cache::remember($cacheKey, 60, function () use ($baseQuery) {
                    return (clone $baseQuery)->count();
                });

                // Cards (not cached — paginated)
                $cards = (clone $baseQuery)
                    ->orderBy('updated_at', 'desc')
                    ->skip($offset)
                    ->take($cardsLimit)
                    ->get([
                        'id', 'first_name', 'last_name', 'company_name',
                        'phone_number', 'email', 'lead_status', 'assigned_to', 'updated_at',
                    ]);

                // Check tasks due
                $leadIds = $cards->pluck('id');
                $tasksDue = [];
                if ($leadIds->isNotEmpty()) {
                    $tasksDue = DB::connection("mysql_$clientId")
                        ->table('crm_scheduled_task')
                        ->whereIn('lead_id', $leadIds)
                        ->where('is_sent', 0)
                        ->orderBy('date')
                        ->get(['lead_id', 'date'])
                        ->keyBy('lead_id');
                }

                // Check documents
                $docsExist = [];
                if ($leadIds->isNotEmpty()) {
                    $docsExist = DB::connection("mysql_$clientId")
                        ->table('crm_documents')
                        ->whereIn('lead_id', $leadIds)
                        ->pluck('lead_id')
                        ->unique()
                        ->flip()
                        ->toArray();
                }

                $cardData = $cards->map(function ($lead) use ($tasksDue, $docsExist) {
                    return [
                        'id'           => $lead->id,
                        'first_name'   => $lead->first_name,
                        'last_name'    => $lead->last_name,
                        'company_name' => $lead->company_name,
                        'phone_number' => $lead->phone_number,
                        'email'        => $lead->email,
                        'lead_status'  => $lead->lead_status,
                        'assigned_to'  => $lead->assigned_to,
                        'updated_at'   => $lead->updated_at,
                        'has_documents'=> isset($docsExist[$lead->id]),
                        'task_due'     => isset($tasksDue[$lead->id]) ? $tasksDue[$lead->id]->date : null,
                    ];
                });

                $totalLeads += $count;

                $columns[] = [
                    'status_slug'    => $stage->lead_title_url,
                    'status_title'   => $stage->title,
                    'color_code'     => $stage->color_code,
                    'display_order'  => $stage->display_order,
                    'total_count'    => $count,
                    'cards'          => $cardData,
                ];
            }

            return $this->successResponse("Pipeline Board", [
                'columns'         => $columns,
                'total_leads'     => $totalLeads,
                'cards_limit'     => $cardsLimit,
                'cards_offset'    => $offset,
                'applied_filters' => [
                    'status'      => $filterStatus,
                    'assigned_to' => $filterAssigned,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to load pipeline board", [$e->getMessage()], $e, 500);
        }
    }

    /**
     * GET /crm/pipeline/views
     */
    public function listViews(Request $request)
    {
        try {
            $clientId = $request->auth->parent_id;
            $userId   = $request->auth->id;

            $views = CrmPipelineView::on("mysql_$clientId")
                ->where(function ($q) use ($userId) {
                    $q->where('user_id', $userId)->orWhere('is_shared', 1);
                })
                ->orderBy('is_default', 'desc')
                ->orderBy('name')
                ->get();

            return $this->successResponse("Pipeline Views", $views->toArray());
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to load views", [$e->getMessage()], $e, 500);
        }
    }

    /**
     * PUT /crm/pipeline/views
     */
    public function createView(Request $request)
    {
        $this->validate($request, ['name' => 'required|string|max:255']);

        try {
            $clientId = $request->auth->parent_id;

            $view = new CrmPipelineView();
            $view->setConnection("mysql_$clientId");
            $view->name           = $request->input('name');
            $view->user_id        = $request->input('is_shared') ? null : $request->auth->id;
            $view->is_default     = $request->input('is_default', false);
            $view->is_shared      = $request->input('is_shared', false);
            $view->view_type      = $request->input('view_type', 'kanban');
            $view->filters        = $request->input('filters') ? json_encode($request->input('filters')) : null;
            $view->column_config  = $request->input('column_config') ? json_encode($request->input('column_config')) : null;
            $view->sort_config    = $request->input('sort_config') ? json_encode($request->input('sort_config')) : null;
            $view->status_columns = $request->input('status_columns') ? json_encode($request->input('status_columns')) : null;
            $view->created_by     = $request->auth->id;
            $view->saveOrFail();

            return $this->successResponse("Pipeline View Created", $view->toArray());
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to create view", [$e->getMessage()], $e, 500);
        }
    }

    /**
     * POST /crm/pipeline/views/{id}
     */
    public function updateView(Request $request, $id)
    {
        try {
            $clientId = $request->auth->parent_id;
            $view     = CrmPipelineView::on("mysql_$clientId")->findOrFail($id);

            if ($request->has('name'))           $view->name           = $request->input('name');
            if ($request->has('is_default'))     $view->is_default     = $request->input('is_default');
            if ($request->has('is_shared'))      $view->is_shared      = $request->input('is_shared');
            if ($request->has('view_type'))      $view->view_type      = $request->input('view_type');
            if ($request->has('filters'))        $view->filters        = json_encode($request->input('filters'));
            if ($request->has('column_config'))  $view->column_config  = json_encode($request->input('column_config'));
            if ($request->has('sort_config'))    $view->sort_config    = json_encode($request->input('sort_config'));
            if ($request->has('status_columns')) $view->status_columns = json_encode($request->input('status_columns'));
            $view->save();

            return $this->successResponse("Pipeline View Updated", $view->toArray());
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to update view", [$e->getMessage()], $e, 500);
        }
    }

    /**
     * DELETE /crm/pipeline/views/{id}
     */
    public function deleteView(Request $request, $id)
    {
        try {
            $clientId = $request->auth->parent_id;
            CrmPipelineView::on("mysql_$clientId")->findOrFail($id)->delete();
            return $this->successResponse("Pipeline View Deleted", []);
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to delete view", [$e->getMessage()], $e, 500);
        }
    }

    /**
     * PATCH /crm/pipeline/leads/{id}/move
     * Persist drag-and-drop card movement to a new pipeline status.
     * Body: { status_slug: 'new-status-slug' }
     */
    public function moveLead(Request $request, $id)
    {
        $this->validate($request, [
            'status_slug' => 'required|string',
        ]);

        try {
            $clientId  = $request->auth->parent_id;
            $userId    = $request->auth->id;
            $userLevel = $request->auth->user_level ?? 0;
            $db        = DB::connection("mysql_$clientId");
            $newSlug   = $request->input('status_slug');

            // Verify lead exists and user has access
            $lead = $db->table('crm_lead_data')
                ->where('id', $id)
                ->where('is_deleted', 0)
                ->first(['id', 'lead_status', 'assigned_to', 'created_by']);

            if (!$lead) {
                return $this->failResponse("Lead not found", [], null, 404);
            }

            if ($err = $this->assertLeadAccess($request, $lead)) return $err;

            // Verify the target status exists and is active
            $newStatus = $db->table('crm_lead_status')
                ->where('lead_title_url', $newSlug)
                ->where('status', '1')
                ->first(['id', 'title', 'lead_title_url', 'webhook_status', 'webhook_method', 'webhook_url', 'webhook_token']);

            if (!$newStatus) {
                return $this->failResponse("Invalid or inactive pipeline status", [], null, 422);
            }

            $oldSlug = $lead->lead_status;

            // No-op if already in target status
            if ($oldSlug === $newSlug) {
                return $this->successResponse("Lead already in this status", ['lead_id' => (int) $id]);
            }

            // Update the lead
            $db->table('crm_lead_data')
                ->where('id', $id)
                ->update(['lead_status' => $newSlug, 'updated_at' => now()]);

            // Log to status history
            try {
                $db->table('crm_lead_status_history')->insert([
                    'lead_id'    => (int) $id,
                    'old_status' => $oldSlug,
                    'new_status' => $newSlug,
                    'changed_by' => $userId,
                    'changed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (\Throwable $e) {}

            // Log to activity timeline
            try {
                $db->table('crm_lead_activity')->insert([
                    'lead_id'       => (int) $id,
                    'user_id'       => $userId,
                    'activity_type' => 'status_change',
                    'subject'       => "Moved from \"{$oldSlug}\" to \"{$newSlug}\"",
                    'source_type'   => 'pipeline',
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
            } catch (\Throwable $e) {}

            // Bust cached counts for both affected columns
            Cache::forget("pipeline_board_{$clientId}_{$oldSlug}_count");
            Cache::forget("pipeline_board_{$clientId}_{$newSlug}_count");

            // Fire webhook if the new status has one configured
            if ($newStatus->webhook_status && $newStatus->webhook_url) {
                try {
                    $payload = json_encode([
                        'lead_id'    => (int) $id,
                        'old_status' => $oldSlug,
                        'new_status' => $newSlug,
                        'moved_by'   => $userId,
                        'timestamp'  => now()->toIso8601String(),
                    ]);
                    $ch = curl_init($newStatus->webhook_url);
                    curl_setopt_array($ch, [
                        CURLOPT_POST           => true,
                        CURLOPT_POSTFIELDS     => $payload,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT        => 5,
                        CURLOPT_HTTPHEADER     => [
                            'Content-Type: application/json',
                            'X-Webhook-Token: ' . $newStatus->webhook_token,
                        ],
                    ]);
                    curl_exec($ch);
                    curl_close($ch);
                } catch (\Throwable $e) {}
            }

            return $this->successResponse("Lead moved", [
                'lead_id'    => (int) $id,
                'old_status' => $oldSlug,
                'new_status' => $newSlug,
            ]);
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to move lead", [$e->getMessage()], $e, 500);
        }
    }
}
