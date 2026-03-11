<?php

namespace App\Http\Controllers;

use App\Services\LeadDeduplicationService;
use App\Services\LeadAssignmentService;
use Illuminate\Http\Request;

/**
 * Lead deduplication and assignment API.
 */
class LeadManagementController extends Controller
{
    /**
     * POST /leads/check-duplicate
     * Body: { phone: '555-1234' }
     */
    public function checkDuplicate(Request $request)
    {
        $this->validate($request, ['phone' => 'required|string|min:7']);

        $clientId   = $request->auth->parent_id;
        $existingId = LeadDeduplicationService::forClient($clientId)
            ->findDuplicateByPhone($request->input('phone'));

        return response()->json([
            'status'       => true,
            'is_duplicate' => $existingId !== null,
            'existing_id'  => $existingId,
        ]);
    }

    /**
     * POST /leads/scan-duplicates
     * Body: { list_id: 123, limit?: 500 }
     */
    public function scanDuplicates(Request $request)
    {
        $this->validate($request, [
            'list_id' => 'required|integer',
            'limit'   => 'nullable|integer|min:1|max:2000',
        ]);

        $result = LeadDeduplicationService::forClient($request->auth->parent_id)
            ->scanListForDuplicates(
                (int) $request->input('list_id'),
                (int) $request->input('limit', 500)
            );

        return response()->json(['status' => true, 'data' => $result]);
    }

    /**
     * POST /leads/assign
     * Body: { lead_ids: [1,2,3], campaign_id: 5, strategy?: 'round_robin' }
     */
    public function assignLeads(Request $request)
    {
        $this->validate($request, [
            'lead_ids'    => 'required|array|min:1|max:1000',
            'lead_ids.*'  => 'integer',
            'campaign_id' => 'required|integer',
            'strategy'    => 'nullable|in:round_robin,least_loaded,priority',
        ]);

        $result = LeadAssignmentService::forClient($request->auth->parent_id)
            ->bulkAssign(
                $request->input('lead_ids'),
                (int) $request->input('campaign_id'),
                $request->input('strategy', 'round_robin')
            );

        return response()->json(['status' => true, 'data' => $result]);
    }

    /**
     * POST /leads/auto-distribute
     * Body: { campaign_id: 5, limit?: 1000, strategy?: 'round_robin' }
     */
    public function autoDistribute(Request $request)
    {
        $this->validate($request, [
            'campaign_id' => 'required|integer',
            'limit'       => 'nullable|integer|min:1|max:5000',
            'strategy'    => 'nullable|in:round_robin,least_loaded,priority',
        ]);

        $result = LeadAssignmentService::forClient($request->auth->parent_id)
            ->autoDistribute(
                (int) $request->input('campaign_id'),
                (int) $request->input('limit', 1000),
                $request->input('strategy', 'round_robin')
            );

        return response()->json(['status' => true, 'data' => $result]);
    }

    /**
     * POST /leads/dedup-batch
     * Body: { leads: [{phone,...},...], phone_field?: 'phone', policy?: 'flag' }
     * Check a batch of leads for duplicates before import.
     */
    public function dedupBatch(Request $request)
    {
        $this->validate($request, [
            'leads'       => 'required|array|min:1|max:10000',
            'phone_field' => 'nullable|string|max:50',
            'policy'      => 'nullable|in:block,flag,merge',
        ]);

        $result = LeadDeduplicationService::forClient(
            $request->auth->parent_id,
            $request->input('policy', 'flag')
        )->processBatch(
            $request->input('leads'),
            $request->input('phone_field', 'phone')
        );

        return response()->json(['status' => true, 'data' => $result]);
    }
}
