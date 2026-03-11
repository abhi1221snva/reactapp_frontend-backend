<?php

namespace App\Http\Controllers;

use App\Model\Client\CampaignStaffing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CampaignStaffingController extends Controller
{
    private $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * GET /workforce/campaign-staffing
     * List staffing requirements for all campaigns.
     */
    public function index()
    {
        try {
            // Join with campaign table for names
            $rows = DB::table('campaign_staffing')
                ->leftJoin('campaign', 'campaign_staffing.campaign_id', '=', 'campaign.id')
                ->select([
                    'campaign_staffing.id',
                    'campaign_staffing.campaign_id',
                    'campaign_staffing.required_agents',
                    'campaign_staffing.min_agents',
                    'campaign.title as campaign_name',
                    'campaign.status as campaign_status',
                ])
                ->orderBy('campaign_staffing.campaign_id')
                ->get();

            return response()->json([
                'success' => 'true',
                'data'    => $rows,
                'total'   => $rows->count(),
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => 'false', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /workforce/campaign-staffing
     * Upsert staffing requirement for a campaign.
     */
    public function upsert()
    {
        $this->validate($this->request, [
            'campaign_id'     => 'required|numeric',
            'required_agents' => 'required|numeric|min:0',
            'min_agents'      => 'numeric|min:0',
        ]);

        try {
            $record = CampaignStaffing::updateOrCreate(
                ['campaign_id' => (int) $this->request->campaign_id],
                [
                    'required_agents' => (int) $this->request->required_agents,
                    'min_agents'      => (int) $this->request->input('min_agents', 0),
                ]
            );

            return response()->json([
                'success' => 'true',
                'message' => 'Campaign staffing requirement saved.',
                'data'    => $record,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => 'false', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /workforce/campaign-staffing/{campaign_id}
     * Remove staffing requirement for a campaign.
     */
    public function destroy(int $campaignId)
    {
        try {
            CampaignStaffing::where('campaign_id', $campaignId)->delete();
            return response()->json(['success' => 'true', 'message' => 'Staffing requirement removed.']);
        } catch (\Exception $e) {
            return response()->json(['success' => 'false', 'message' => $e->getMessage()], 500);
        }
    }
}
