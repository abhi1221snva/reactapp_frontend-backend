<?php

namespace App\Http\Controllers;

use App\Model\Client\CampaignStaffing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @OA\Get(
 *   path="/workforce/campaign-staffing",
 *   summary="List staffing requirements for all campaigns",
 *   operationId="campaignStaffingIndex",
 *   tags={"Workforce"},
 *   security={{"Bearer":{}}},
 *   @OA\Response(response=200, description="Campaign staffing list"),
 *   @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Post(
 *   path="/workforce/campaign-staffing",
 *   summary="Upsert staffing requirement for a campaign",
 *   operationId="campaignStaffingUpsert",
 *   tags={"Workforce"},
 *   security={{"Bearer":{}}},
 *   @OA\RequestBody(@OA\JsonContent(required={"campaign_id","required_agents"},
 *     @OA\Property(property="campaign_id", type="integer"),
 *     @OA\Property(property="required_agents", type="integer"),
 *     @OA\Property(property="min_agents", type="integer")
 *   )),
 *   @OA\Response(response=200, description="Staffing requirement saved")
 * )
 *
 * @OA\Delete(
 *   path="/workforce/campaign-staffing/{campaign_id}",
 *   summary="Remove staffing requirement for a campaign",
 *   operationId="campaignStaffingDestroy",
 *   tags={"Workforce"},
 *   security={{"Bearer":{}}},
 *   @OA\Parameter(name="campaign_id", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Staffing requirement removed")
 * )
 */
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
            $conn = $this->tenantDb($this->request);

            $rows = DB::connection($conn)->table('campaign_staffing')
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
            return response()->json(['success' => 'false', 'message' => 'Server error.'], 500);
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
            'required_agents' => 'required|numeric|min:1',
            'min_agents'      => 'numeric|min:1',
        ]);

        try {
            $conn = $this->tenantDb($this->request);

            $record = CampaignStaffing::on($conn)->updateOrCreate(
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
            return response()->json(['success' => 'false', 'message' => 'Server error.'], 500);
        }
    }

    /**
     * DELETE /workforce/campaign-staffing/{campaign_id}
     * Remove staffing requirement for a campaign.
     */
    public function destroy(int $campaignId)
    {
        try {
            $conn = $this->tenantDb($this->request);

            CampaignStaffing::on($conn)->where('campaign_id', $campaignId)->delete();
            return response()->json(['success' => 'true', 'message' => 'Staffing requirement removed.']);
        } catch (\Exception $e) {
            return response()->json(['success' => 'false', 'message' => 'Server error.'], 500);
        }
    }
}
