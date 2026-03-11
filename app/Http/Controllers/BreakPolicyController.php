<?php

namespace App\Http\Controllers;

use App\Model\Client\BreakPolicy;
use Illuminate\Http\Request;

class BreakPolicyController extends Controller
{
    private $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * GET /workforce/break-policy
     * List all break policies (global + campaign-specific).
     */
    public function index()
    {
        try {
            $policies = BreakPolicy::orderByRaw('campaign_id IS NULL DESC, campaign_id ASC')->get();
            return response()->json([
                'success' => 'true',
                'data'    => $policies,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => 'false', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /workforce/break-policy
     * Upsert break policy (global if campaign_id null, else campaign-specific).
     */
    public function upsert()
    {
        $this->validate($this->request, [
            'campaign_id'           => 'numeric',
            'max_concurrent_breaks' => 'required|numeric|min:1|max:50',
            'max_break_minutes'     => 'required|numeric|min:1|max:480',
        ]);

        try {
            $campaignId = $this->request->has('campaign_id') ? (int) $this->request->campaign_id : null;

            $policy = BreakPolicy::updateOrCreate(
                ['campaign_id' => $campaignId],
                [
                    'max_concurrent_breaks' => (int) $this->request->max_concurrent_breaks,
                    'max_break_minutes'     => (int) $this->request->max_break_minutes,
                ]
            );

            return response()->json([
                'success' => 'true',
                'message' => 'Break policy saved.',
                'data'    => $policy,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => 'false', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /workforce/break-policy/{id}
     */
    public function destroy(int $id)
    {
        try {
            BreakPolicy::where('id', $id)->delete();
            return response()->json(['success' => 'true', 'message' => 'Break policy removed.']);
        } catch (\Exception $e) {
            return response()->json(['success' => 'false', 'message' => $e->getMessage()], 500);
        }
    }
}
