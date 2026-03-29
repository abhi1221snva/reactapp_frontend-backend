<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Model\Client\CrmLeadApproval;
use App\Model\Client\CrmLeadActivity;
use App\Model\User;
use Illuminate\Support\Facades\DB;

/**
 * @OA\Put(
 *   path="/crm/lead/{id}/approval/request",
 *   summary="Request approval for a lead",
 *   operationId="crmApprovalRequest",
 *   tags={"CRM Approvals"},
 *   security={{"Bearer":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Approval requested"),
 *   @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Post(
 *   path="/crm/lead/{id}/approval/{aid}/review",
 *   summary="Review (approve/reject) a lead approval",
 *   operationId="crmApprovalReview",
 *   tags={"CRM Approvals"},
 *   security={{"Bearer":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Parameter(name="aid", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\RequestBody(@OA\JsonContent(
 *     @OA\Property(property="decision", type="string", enum={"approved","rejected"}),
 *     @OA\Property(property="note", type="string")
 *   )),
 *   @OA\Response(response=200, description="Approval reviewed")
 * )
 *
 * @OA\Post(
 *   path="/crm/lead/{id}/approval/{aid}/withdraw",
 *   summary="Withdraw an approval request",
 *   operationId="crmApprovalWithdraw",
 *   tags={"CRM Approvals"},
 *   security={{"Bearer":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Parameter(name="aid", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Approval withdrawn")
 * )
 *
 * @OA\Get(
 *   path="/crm/lead/{id}/approvals",
 *   summary="List approvals for a lead",
 *   operationId="crmApprovalList",
 *   tags={"CRM Approvals"},
 *   security={{"Bearer":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Lead approvals")
 * )
 *
 * @OA\Get(
 *   path="/crm/approvals",
 *   summary="List all pending approvals",
 *   operationId="crmApprovalListAll",
 *   tags={"CRM Approvals"},
 *   security={{"Bearer":{}}},
 *   @OA\Response(response=200, description="All approvals")
 * )
 */
class CrmApprovalController extends Controller
{
    /**
     * PUT /crm/lead/{id}/approval/request
     * Any authenticated user can request an approval.
     */
    public function request(Request $request, $id)
    {
        $this->validate($request, [
            'approval_type' => 'required|string',
        ]);

        try {
            $clientId = $request->auth->parent_id;

            $approval = new CrmLeadApproval();
            $approval->setConnection("mysql_$clientId");
            $approval->lead_id          = $id;
            $approval->requested_by     = $request->auth->id;
            $approval->approval_type    = $request->input('approval_type', 'custom');
            $approval->approval_stage   = $request->input('approval_stage');
            $approval->status           = 'pending';
            $approval->request_note     = $request->input('request_note');
            $approval->requested_amount = $request->input('requested_amount');
            $approval->expires_at       = $request->input('expires_at');
            $approval->saveOrFail();

            // Log to activity timeline
            try {
                $activity = new CrmLeadActivity();
                $activity->setConnection("mysql_$clientId");
                $activity->lead_id       = $id;
                $activity->user_id       = $request->auth->id;
                $activity->activity_type = 'approval_requested';
                $activity->subject       = 'Approval requested: ' . $approval->approval_type;
                $activity->meta          = [
                    'approval_id'   => $approval->id,
                    'approval_type' => $approval->approval_type,
                    'request_note'  => $approval->request_note,
                ];
                $activity->source_type = 'manual';
                $activity->save();
            } catch (\Throwable $e) {}

            return $this->successResponse("Approval Request Submitted", $approval->toArray());
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to submit approval request", [$e->getMessage()], $e, 500);
        }
    }

    /**
     * POST /crm/lead/{id}/approval/{aid}/review
     * Only managers and above (user_level >= 5) can review approvals.
     */
    public function review(Request $request, $id, $aid)
    {
        // Role check
        if (($request->auth->user_level ?? 0) < 5) {
            return $this->failResponse("Insufficient permissions to review approvals", [], null, 403);
        }

        $this->validate($request, [
            'status' => 'required|in:approved,declined',
        ]);

        try {
            $clientId = $request->auth->parent_id;
            $approval = CrmLeadApproval::on("mysql_$clientId")
                ->where('lead_id', $id)
                ->findOrFail($aid);

            if ($approval->status !== 'pending') {
                return $this->failResponse("This approval has already been reviewed", [], null, 422);
            }

            $approval->status          = $request->input('status');
            $approval->reviewed_by     = $request->auth->id;
            $approval->review_note     = $request->input('review_note');
            $approval->approved_amount = $request->input('approved_amount');
            $approval->reviewed_at     = Carbon::now();
            $approval->save();

            // Log to activity timeline
            try {
                $activity = new CrmLeadActivity();
                $activity->setConnection("mysql_$clientId");
                $activity->lead_id       = $id;
                $activity->user_id       = $request->auth->id;
                $activity->activity_type = $approval->status === 'approved' ? 'approval_granted' : 'approval_declined';
                $activity->subject       = 'Approval ' . $approval->status . ': ' . $approval->approval_type;
                $activity->meta          = [
                    'approval_id'      => $approval->id,
                    'decision'         => $approval->status,
                    'review_note'      => $approval->review_note,
                    'approved_amount'  => $approval->approved_amount,
                ];
                $activity->source_type = 'manual';
                $activity->save();
            } catch (\Throwable $e) {}

            return $this->successResponse("Approval " . ucfirst($approval->status), $approval->toArray());
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to review approval", [$e->getMessage()], $e, 500);
        }
    }

    /**
     * GET /crm/lead/{id}/approvals
     */
    public function list(Request $request, $id)
    {
        try {
            $clientId  = $request->auth->parent_id;
            $approvals = CrmLeadApproval::on("mysql_$clientId")
                ->where('lead_id', $id)
                ->orderBy('created_at', 'desc')
                ->get();

            // Attach user names
            $userIds = $approvals->pluck('requested_by')
                ->merge($approvals->pluck('reviewed_by'))
                ->filter()->unique()->values();

            $users = User::whereIn('id', $userIds)->get(['id', 'first_name', 'last_name'])->keyBy('id');

            $result = $approvals->map(function ($a) use ($users) {
                $arr = $a->toArray();
                $arr['requested_by_name'] = isset($users[$a->requested_by])
                    ? $users[$a->requested_by]->first_name . ' ' . $users[$a->requested_by]->last_name : null;
                $arr['reviewed_by_name']  = $a->reviewed_by && isset($users[$a->reviewed_by])
                    ? $users[$a->reviewed_by]->first_name . ' ' . $users[$a->reviewed_by]->last_name : null;
                return $arr;
            });

            return $this->successResponse("Lead Approvals", $result->toArray());
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to load approvals", [$e->getMessage()], $e, 500);
        }
    }

    /**
     * POST /crm/lead/{id}/approval/{aid}/withdraw
     */
    public function withdraw(Request $request, $id, $aid)
    {
        try {
            $clientId = $request->auth->parent_id;
            $approval = CrmLeadApproval::on("mysql_$clientId")
                ->where('lead_id', $id)
                ->where('requested_by', $request->auth->id)
                ->findOrFail($aid);

            if ($approval->status !== 'pending') {
                return $this->failResponse("Only pending approvals can be withdrawn", [], null, 422);
            }

            $approval->status = 'withdrawn';
            $approval->save();

            return $this->successResponse("Approval Withdrawn", $approval->toArray());
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to withdraw approval", [$e->getMessage()], $e, 500);
        }
    }

    /**
     * GET /crm/approvals
     * Global approvals list (all leads, paginated).
     */
    public function listAll(Request $request)
    {
        try {
            $clientId = $request->auth->parent_id;
            $page     = max(1, (int) $request->input('page', 1));
            $perPage  = min((int) $request->input('per_page', 25), 100);
            $status   = $request->input('status'); // 'pending','approved','declined','withdrawn'

            $query = DB::connection("mysql_$clientId")
                ->table('crm_lead_approvals as a')
                ->leftJoin('crm_lead_data as l', 'l.id', '=', 'a.lead_id')
                ->select(
                    'a.*',
                    'l.first_name', 'l.last_name', 'l.company_name'
                )
                ->orderBy('a.created_at', 'desc');

            if ($status) {
                $query->where('a.status', $status);
            }

            $total    = $query->count();
            $approvals = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

            // Attach user names
            $userIds = $approvals->pluck('requested_by')
                ->merge($approvals->pluck('reviewed_by'))
                ->filter()->unique()->values();
            $users = User::whereIn('id', $userIds)->get(['id', 'first_name', 'last_name'])->keyBy('id');

            $result = $approvals->map(function ($a) use ($users) {
                $arr = (array) $a;
                $arr['lead_name']         = trim(($arr['first_name'] ?? '') . ' ' . ($arr['last_name'] ?? ''));
                $arr['requested_by_name'] = isset($users[$a->requested_by])
                    ? $users[$a->requested_by]->first_name . ' ' . $users[$a->requested_by]->last_name : null;
                $arr['reviewed_by_name']  = $a->reviewed_by && isset($users[$a->reviewed_by])
                    ? $users[$a->reviewed_by]->first_name . ' ' . $users[$a->reviewed_by]->last_name : null;
                return $arr;
            });

            return $this->successResponse("All Approvals", [
                'data'         => $result->values(),
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $page,
                'last_page'    => (int) ceil($total / $perPage),
            ]);
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to load approvals", [$e->getMessage()], $e, 500);
        }
    }
}
