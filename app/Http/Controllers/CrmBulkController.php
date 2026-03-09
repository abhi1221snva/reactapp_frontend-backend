<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Model\Client\Lead;
use App\Model\Client\CrmLeadStatusHistory;
use App\Model\Client\CrmLeadActivity;
use Illuminate\Support\Facades\DB;

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
            $clientId   = $request->auth->parent_id;
            $leadIds    = $request->input('lead_ids');
            $assignedTo = $request->input('assigned_to');
            $reason     = $request->input('reason', 'Bulk assignment');
            $processed  = 0;
            $failed     = [];

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

                    $processed++;
                } catch (\Throwable $e) {
                    $failed[] = $leadId;
                }
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
            $clientId  = $request->auth->parent_id;
            $leadIds   = $request->input('lead_ids');
            $newStatus = $request->input('lead_status');
            $reason    = $request->input('reason', 'Bulk status change');
            $processed = 0;
            $failed    = [];

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

                    $processed++;
                } catch (\Throwable $e) {
                    $failed[] = $leadId;
                }
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
            $clientId  = $request->auth->parent_id;
            $leadIds   = $request->input('lead_ids');
            $processed = 0;
            $failed    = [];

            foreach ($leadIds as $leadId) {
                try {
                    DB::connection("mysql_$clientId")
                        ->table('crm_lead_data')
                        ->where('id', $leadId)
                        ->where('is_deleted', 0)
                        ->update(['is_deleted' => 1, 'deleted_at' => now()]);

                    $this->logActivity($clientId, $leadId, $request->auth->id,
                        'system',
                        'Lead deleted (bulk)',
                        ['deleted_by' => $request->auth->id]
                    );

                    $processed++;
                } catch (\Throwable $e) {
                    $failed[] = $leadId;
                }
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
            $clientId = $request->auth->parent_id;
            $leadIds  = $request->input('lead_ids');
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
                ->table('crm_lead_data')
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
            $h->created_at       = now();
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
