<?php

namespace App\Http\Controllers;

use App\Models\Client\CrmLeadAssignee;
use App\Services\CacheService;
use Illuminate\Http\Request;

class LeadAssigneeController extends Controller
{
    /**
     * GET /crm/lead/{id}/assignees
     * List all active assignees for a lead.
     */
    public function index(Request $request, $id)
    {
        if ($err = $this->assertLeadAccessById($request, (int) $id)) return $err;

        $conn = "mysql_{$request->auth->parent_id}";
        $assignees = CrmLeadAssignee::on($conn)
            ->where('lead_id', (int) $id)
            ->where('is_active', 1)
            ->orderBy('assigned_at')
            ->get();

        return $this->successResponse('Assignees retrieved.', $assignees->toArray());
    }

    /**
     * POST /crm/lead/{id}/assignees
     * Assign a user to a lead with a given role.
     */
    public function store(Request $request, $id)
    {
        if ($err = $this->assertLeadAccessById($request, (int) $id)) return $err;

        $this->validate($request, [
            'user_id' => 'required|integer',
            'role'    => 'string|in:assignee,opener,closer,collaborator',
        ]);

        $conn = "mysql_{$request->auth->parent_id}";
        $role = $request->input('role', 'assignee');

        $assignee = CrmLeadAssignee::on($conn)->updateOrCreate(
            [
                'lead_id' => (int) $id,
                'user_id' => (int) $request->input('user_id'),
                'role'    => $role,
            ],
            [
                'is_active'   => 1,
                'assigned_at' => now(),
                'assigned_by' => (int) $request->auth->id,
            ]
        );

        return $this->successResponse('Assignee added.', ['assignee' => $assignee->toArray()]);
    }

    /**
     * DELETE /crm/lead/{id}/assignees/{uid}
     * Remove (deactivate) a user's assignment from a lead.
     */
    public function destroy(Request $request, $id, $uid)
    {
        if ($err = $this->assertLeadAccessById($request, (int) $id)) return $err;

        $conn = "mysql_{$request->auth->parent_id}";

        $updated = CrmLeadAssignee::on($conn)
            ->where('lead_id', (int) $id)
            ->where('user_id', (int) $uid)
            ->where('is_active', 1)
            ->update(['is_active' => 0]);

        if ($updated === 0) {
            return $this->failResponse('Assignee not found or already removed.', [], null, 404);
        }

        return $this->successResponse('Assignee removed.');
    }
}
