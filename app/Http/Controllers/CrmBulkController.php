<?php

namespace App\Http\Controllers;

use App\Services\LeadVisibilityService;
use App\Services\LeadChangeTracker;
use Illuminate\Http\Request;
use App\Model\Client\Lead;
use App\Model\Client\CrmLeadStatusHistory;
use App\Model\Client\CrmLeadActivity;
use Illuminate\Support\Facades\DB;

/**
 * @OA\Post(
 *   path="/crm/leads/bulk/assign",
 *   summary="Bulk assign leads to an agent",
 *   operationId="crmBulkAssign",
 *   tags={"CRM Bulk"},
 *   security={{"Bearer":{}}},
 *   @OA\RequestBody(@OA\JsonContent(
 *     @OA\Property(property="lead_ids", type="array", @OA\Items(type="integer")),
 *     @OA\Property(property="agent_id", type="integer")
 *   )),
 *   @OA\Response(response=200, description="Leads assigned"),
 *   @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Post(
 *   path="/crm/leads/bulk/status-change",
 *   summary="Bulk change lead status",
 *   operationId="crmBulkStatusChange",
 *   tags={"CRM Bulk"},
 *   security={{"Bearer":{}}},
 *   @OA\RequestBody(@OA\JsonContent(
 *     @OA\Property(property="lead_ids", type="array", @OA\Items(type="integer")),
 *     @OA\Property(property="status_id", type="integer")
 *   )),
 *   @OA\Response(response=200, description="Statuses updated")
 * )
 *
 * @OA\Post(
 *   path="/crm/leads/bulk/delete",
 *   summary="Bulk delete leads",
 *   operationId="crmBulkDelete",
 *   tags={"CRM Bulk"},
 *   security={{"Bearer":{}}},
 *   @OA\RequestBody(@OA\JsonContent(
 *     @OA\Property(property="lead_ids", type="array", @OA\Items(type="integer"))
 *   )),
 *   @OA\Response(response=200, description="Leads deleted")
 * )
 *
 * @OA\Post(
 *   path="/crm/leads/bulk/export",
 *   summary="Bulk export leads as CSV",
 *   operationId="crmBulkExport",
 *   tags={"CRM Bulk"},
 *   security={{"Bearer":{}}},
 *   @OA\RequestBody(@OA\JsonContent(
 *     @OA\Property(property="lead_ids", type="array", @OA\Items(type="integer"))
 *   )),
 *   @OA\Response(response=200, description="CSV export")
 * )
 */
class CrmBulkController extends Controller
{
    private const MAX_BULK = 500;

    /**
     * POST /crm/leads/bulk/assign
     */
    public function bulkAssign(Request $request)
    {
        $this->validate($request, [
            'lead_ids'    => 'required|array|max:' . self::MAX_BULK,
            'assigned_to' => 'required|integer',
        ]);

        try {
            $clientId   = (int) $request->auth->parent_id;
            $leadIds    = $request->input('lead_ids');
            $assignedTo = $request->input('assigned_to');
            $reason     = $request->input('reason', 'Bulk assignment');
            $processed  = 0;
            $failed     = [];
            $bulkChanges = [];

            $leadIds = $this->filterAccessibleLeadIds($request, $clientId, $leadIds);

            foreach ($leadIds as $leadId) {
                try {
                    $lead = Lead::on("mysql_$clientId")->where('is_deleted', 0)->findOrFail($leadId);
                    $oldAssigned = $lead->assigned_to;
                    $lead->assigned_to = $assignedTo;
                    $lead->save();

                    // History record
                    $this->logStatusHistory($clientId, $leadId, $request->auth->id, [
                        'from_status'      => $lead->lead_status,
                        'to_status'        => $lead->lead_status,
                        'from_assigned_to' => $oldAssigned,
                        'to_assigned_to'   => $assignedTo,
                        'reason'           => $reason,
                        'triggered_by'     => 'bulk_operation',
                    ]);

                    // Activity
                    $this->logActivity($clientId, $leadId, $request->auth->id,
                        'lead_assigned',
                        "Bulk assigned to user #$assignedTo",
                        ['from_assigned_to' => $oldAssigned, 'to_assigned_to' => $assignedTo]
                    );

                    $bulkChanges[$leadId] = [
                        'assigned_to' => ['old' => $oldAssigned, 'new' => $assignedTo],
                    ];

                    $processed++;
                } catch (\Throwable $e) {
                    $failed[] = $leadId;
                }
            }

            // Record bulk changes
            if (!empty($bulkChanges)) {
                LeadChangeTracker::recordBulk(
                    (string)$clientId, $bulkChanges, 'bulk_operation', $request->auth->id, 'agent'
                );
            }

            return $this->successResponse("Bulk Assign Complete", [
                'processed' => $processed,
                'failed'    => count($failed),
                'failed_ids'=> $failed,
            ]);
        } catch (\Throwable $e) {
            return $this->failResponse("Bulk assign failed", [$e->getMessage()], $e, 500);
        }
    }

    /**
     * POST /crm/leads/bulk/status-change
     */
    public function bulkStatusChange(Request $request)
    {
        $this->validate($request, [
            'lead_ids'    => 'required|array|max:' . self::MAX_BULK,
            'lead_status' => 'required|string',
        ]);

        try {
            $clientId  = (int) $request->auth->parent_id;
            $leadIds   = $request->input('lead_ids');
            $newStatus = $request->input('lead_status');
            $reason    = $request->input('reason', 'Bulk status change');
            $processed = 0;
            $failed    = [];
            $bulkChanges = [];

            $leadIds = $this->filterAccessibleLeadIds($request, $clientId, $leadIds);

            foreach ($leadIds as $leadId) {
                try {
                    $lead      = Lead::on("mysql_$clientId")->where('is_deleted', 0)->findOrFail($leadId);
                    $oldStatus = $lead->lead_status;
                    $lead->lead_status = $newStatus;
                    $lead->save();

                    $this->logStatusHistory($clientId, $leadId, $request->auth->id, [
                        'from_status'  => $oldStatus,
                        'to_status'    => $newStatus,
                        'reason'       => $reason,
                        'triggered_by' => 'bulk_operation',
                    ]);

                    $this->logActivity($clientId, $leadId, $request->auth->id,
                        'status_change',
                        "Status changed from {$oldStatus} to {$newStatus} (bulk)",
                        ['from_status' => $oldStatus, 'to_status' => $newStatus]
                    );

                    $bulkChanges[$leadId] = [
                        'lead_status' => ['old' => $oldStatus, 'new' => $newStatus],
                    ];

                    $processed++;
                } catch (\Throwable $e) {
                    $failed[] = $leadId;
                }
            }

            // Record bulk changes
            if (!empty($bulkChanges)) {
                LeadChangeTracker::recordBulk(
                    (string)$clientId, $bulkChanges, 'bulk_operation', $request->auth->id, 'agent'
                );
            }

            return $this->successResponse("Bulk Status Change Complete", [
                'processed' => $processed,
                'failed'    => count($failed),
                'failed_ids'=> $failed,
            ]);
        } catch (\Throwable $e) {
            return $this->failResponse("Bulk status change failed", [$e->getMessage()], $e, 500);
        }
    }

    /**
     * POST /crm/leads/bulk/delete
     */
    public function bulkDelete(Request $request)
    {
        $this->validate($request, [
            'lead_ids' => 'required|array|max:' . self::MAX_BULK,
            'confirm'  => 'required|boolean|in:1,true',
        ]);

        try {
            $clientId  = (int) $request->auth->parent_id;
            $leadIds   = $request->input('lead_ids');
            $processed = 0;
            $failed    = [];
            $bulkChanges = [];

            $leadIds = $this->filterAccessibleLeadIds($request, $clientId, $leadIds);

            foreach ($leadIds as $leadId) {
                try {
                    DB::connection("mysql_$clientId")
                        ->table('crm_leads')
                        ->where('id', $leadId)
                        ->where('is_deleted', 0)
                        ->update(['is_deleted' => 1, 'deleted_at' => \Carbon\Carbon::now()]);

                    // Also soft-delete in legacy table if it exists
                    try {
                        DB::connection("mysql_$clientId")
                            ->table('crm_lead_data')
                            ->where('id', $leadId)
                            ->where('is_deleted', 0)
                            ->update(['is_deleted' => 1, 'deleted_at' => \Carbon\Carbon::now()]);
                    } catch (\Throwable $e) {}

                    $this->logActivity($clientId, $leadId, $request->auth->id,
                        'system',
                        'Lead deleted (bulk)',
                        ['deleted_by' => $request->auth->id]
                    );

                    $bulkChanges[$leadId] = [
                        'is_deleted' => ['old' => '0', 'new' => '1'],
                    ];

                    $processed++;
                } catch (\Throwable $e) {
                    $failed[] = $leadId;
                }
            }

            // Record bulk changes
            if (!empty($bulkChanges)) {
                LeadChangeTracker::recordBulk(
                    (string)$clientId, $bulkChanges, 'bulk_operation', $request->auth->id, 'agent'
                );
            }

            return $this->successResponse("Bulk Delete Complete", [
                'processed'  => $processed,
                'failed'     => count($failed),
                'failed_ids' => $failed,
            ]);
        } catch (\Throwable $e) {
            return $this->failResponse("Bulk delete failed", [$e->getMessage()], $e, 500);
        }
    }

    /**
     * POST /crm/leads/bulk/export
     */
    public function bulkExport(Request $request)
    {
        $this->validate($request, [
            'lead_ids' => 'required|array|max:' . self::MAX_BULK,
        ]);

        try {
            $clientId = (int) $request->auth->parent_id;
            $leadIds  = $request->input('lead_ids');

            $leadIds = $this->filterAccessibleLeadIds($request, $clientId, $leadIds);

            $columns  = $request->input('columns', ['id', 'first_name', 'last_name', 'phone_number', 'email', 'lead_status', 'assigned_to', 'created_at']);

            // Whitelist columns to prevent injection
            $allowed = array_merge(
                ['id', 'first_name', 'last_name', 'email', 'phone_number', 'company_name',
                 'lead_status', 'lead_type', 'assigned_to', 'created_at', 'updated_at',
                 'city', 'state', 'country', 'address', 'dob', 'gender'],
                array_map(fn($i) => "option_$i", range(1, 750))
            );

            $safeColumns = array_intersect($columns, $allowed);
            if (empty($safeColumns)) {
                $safeColumns = ['id', 'first_name', 'last_name', 'phone_number', 'email', 'lead_status'];
            }

            $leads = DB::connection("mysql_$clientId")
                ->table('crm_leads')
                ->whereIn('id', $leadIds)
                ->where('is_deleted', 0)
                ->select($safeColumns)
                ->get();

            return $this->successResponse("Export Data", [
                'count'  => $leads->count(),
                'format' => $request->input('format', 'json'),
                'data'   => $leads,
            ]);
        } catch (\Throwable $e) {
            return $this->failResponse("Bulk export failed", [$e->getMessage()], $e, 500);
        }
    }

    /**
     * Pre-filter an array of lead IDs to only those the user can access.
     */
    private function filterAccessibleLeadIds(Request $request, int $clientId, array $leadIds): array
    {
        $service = new LeadVisibilityService();
        if ($service->hasFullAccess($request->auth, $clientId)) {
            return $leadIds;
        }

        $scope = $service->buildVisibilityScope($request->auth, $clientId);
        if ($scope === null) {
            return $leadIds;
        }

        $conn = "mysql_{$clientId}";
        $query = DB::connection($conn)->table('crm_leads')
            ->whereIn('id', $leadIds)
            ->where('is_deleted', 0)
            ->whereRaw($scope['condition'], $scope['bindings']);

        return $query->pluck('id')->map(fn($id) => (int) $id)->toArray();
    }

    private function logStatusHistory($clientId, $leadId, $userId, array $data)
    {
        try {
            $h = new CrmLeadStatusHistory();
            $h->setConnection("mysql_$clientId");
            $h->lead_id          = $leadId;
            $h->user_id          = $userId;
            $h->from_status      = $data['from_status'] ?? null;
            $h->to_status        = $data['to_status'] ?? '';
            $h->from_assigned_to = $data['from_assigned_to'] ?? null;
            $h->to_assigned_to   = $data['to_assigned_to'] ?? null;
            $h->reason           = $data['reason'] ?? null;
            $h->triggered_by     = $data['triggered_by'] ?? 'bulk_operation';
            $h->created_at       = \Carbon\Carbon::now();
            $h->save();
        } catch (\Throwable $e) {}
    }

    private function logActivity($clientId, $leadId, $userId, $type, $subject, array $meta = [])
    {
        try {
            $a = new CrmLeadActivity();
            $a->setConnection("mysql_$clientId");
            $a->lead_id       = $leadId;
            $a->user_id       = $userId;
            $a->activity_type = $type;
            $a->subject       = $subject;
            $a->meta          = json_encode($meta);
            $a->source_type   = 'api';
            $a->save();
        } catch (\Throwable $e) {}
    }
}
