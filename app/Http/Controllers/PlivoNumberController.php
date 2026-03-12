<?php

namespace App\Http\Controllers;

use App\Model\Client\PlivoNumber;
use App\Model\Client\PlivoCampaignNumber;
use App\Services\PlivoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * @OA\Get(
 *   path="/plivo/numbers/search",
 *   summary="Search available Plivo phone numbers",
 *   operationId="plivoSearchNumbers",
 *   tags={"Plivo"},
 *   security={{"Bearer":{}}},
 *   @OA\Parameter(name="country_iso", in="query", @OA\Schema(type="string", default="US")),
 *   @OA\Parameter(name="area_code", in="query", @OA\Schema(type="string")),
 *   @OA\Response(response=200, description="Available numbers")
 * )
 *
 * @OA\Post(
 *   path="/plivo/numbers/purchase",
 *   summary="Purchase a Plivo phone number",
 *   operationId="plivoPurchaseNumber",
 *   tags={"Plivo"},
 *   security={{"Bearer":{}}},
 *   @OA\RequestBody(required=true, @OA\JsonContent(
 *     required={"number"},
 *     @OA\Property(property="number", type="string", example="+15551234567")
 *   )),
 *   @OA\Response(response=200, description="Number purchased"),
 *   @OA\Response(response=422, description="Validation error")
 * )
 *
 * @OA\Delete(
 *   path="/plivo/numbers/{number}",
 *   summary="Release a Plivo phone number",
 *   operationId="plivoReleaseNumber",
 *   tags={"Plivo"},
 *   security={{"Bearer":{}}},
 *   @OA\Parameter(name="number", in="path", required=true, @OA\Schema(type="string")),
 *   @OA\Response(response=200, description="Number released"),
 *   @OA\Response(response=404, description="Not found")
 * )
 *
 * @OA\Get(
 *   path="/plivo/numbers",
 *   summary="List purchased Plivo numbers",
 *   operationId="plivoListNumbers",
 *   tags={"Plivo"},
 *   security={{"Bearer":{}}},
 *   @OA\Parameter(name="search", in="query", @OA\Schema(type="string")),
 *   @OA\Response(response=200, description="Number list")
 * )
 *
 * @OA\Post(
 *   path="/plivo/numbers/assign-campaign",
 *   summary="Assign a Plivo number to a campaign",
 *   operationId="plivoAssignNumberToCampaign",
 *   tags={"Plivo"},
 *   security={{"Bearer":{}}},
 *   @OA\RequestBody(required=true, @OA\JsonContent(
 *     required={"number","campaign_id"},
 *     @OA\Property(property="number", type="string"),
 *     @OA\Property(property="campaign_id", type="integer")
 *   )),
 *   @OA\Response(response=200, description="Number assigned")
 * )
 *
 * @OA\Get(
 *   path="/plivo/numbers/campaign/{campaignId}",
 *   summary="Get Plivo numbers assigned to a campaign",
 *   operationId="plivoGetNumbersByCampaign",
 *   tags={"Plivo"},
 *   security={{"Bearer":{}}},
 *   @OA\Parameter(name="campaignId", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Campaign numbers")
 * )
 *
 * @OA\Post(
 *   path="/plivo/numbers/unassign-campaign",
 *   summary="Unassign a Plivo number from a campaign",
 *   operationId="plivoUnassignNumber",
 *   tags={"Plivo"},
 *   security={{"Bearer":{}}},
 *   @OA\RequestBody(required=true, @OA\JsonContent(
 *     required={"number"},
 *     @OA\Property(property="number", type="string")
 *   )),
 *   @OA\Response(response=200, description="Number unassigned")
 * )
 */
class PlivoNumberController extends Controller
{
    // -- Search available numbers -------------------------------------------------

    public function search(Request $request)
    {
        $clientId     = $request->auth->parent_id ?: $request->auth->id;
        $country      = strtoupper($request->input('country', 'US'));
        $areaCode     = $request->input('area_code');
        $capabilities = [
            'voice' => (bool) $request->input('voice', true),
            'sms'   => (bool) $request->input('sms', false),
        ];
        $limit = min((int) $request->input('limit', 20), 50);

        try {
            $service = PlivoService::forClient($clientId);
            $numbers = $service->searchNumbers($country, $areaCode, $capabilities, $limit);
            return $this->successResponse('OK', ['numbers' => $numbers, 'total' => count($numbers)]);
        } catch (\Exception $e) {
            Log::error('Plivo search numbers', ['client' => $clientId, 'err' => $e->getMessage()]);
            return $this->failResponse('Failed to search numbers.', [$e->getMessage()], $e, 500);
        }
    }

    // -- Purchase a number --------------------------------------------------------

    public function purchase(Request $request)
    {
        $clientId    = $request->auth->parent_id ?: $request->auth->id;
        $phoneNumber = $request->input('phone_number');

        if (!$phoneNumber) {
            return $this->failResponse('phone_number is required.', [], null, 422);
        }

        $conn = "mysql_{$clientId}";

        try {
            $service = PlivoService::forClient($clientId);
            $data    = $service->purchaseNumber($phoneNumber);

            $number = PlivoNumber::on($conn)->updateOrCreate(
                ['number' => $data['number']],
                [
                    'alias'       => $data['number'],
                    'country_iso' => $request->input('country', 'US'),
                    'sub_type'    => [],
                    'status'      => 'active',
                    'voice_url'   => $data['voice_url'] ?? null,
                    'sms_url'     => $data['sms_url'] ?? null,
                    'app_id'      => $data['app_id'] ?? null,
                ]
            );

            return $this->successResponse('Number purchased successfully.', ['number' => $number]);

        } catch (\Exception $e) {
            Log::error('Plivo purchase number', ['client' => $clientId, 'err' => $e->getMessage()]);
            return $this->failResponse('Failed to purchase number.', [$e->getMessage()], $e, 500);
        }
    }

    // -- Release a number ---------------------------------------------------------

    public function release(Request $request, string $number)
    {
        $clientId = $request->auth->parent_id ?: $request->auth->id;
        $conn     = "mysql_{$clientId}";

        $record = PlivoNumber::on($conn)->where('number', $number)->first();
        if (!$record) {
            return $this->failResponse('Number not found.', [], null, 404);
        }

        try {
            $service = PlivoService::forClient($clientId);
            $service->releaseNumber($number);

            // Remove campaign associations
            DB::connection($conn)
                ->table('plivo_campaign_numbers')
                ->where('plivo_number_id', $record->id)
                ->delete();

            $record->status = 'released';
            $record->save();

            return $this->successResponse('Number released.');

        } catch (\Exception $e) {
            Log::error('Plivo release number', ['client' => $clientId, 'err' => $e->getMessage()]);
            return $this->failResponse('Failed to release number.', [$e->getMessage()], $e, 500);
        }
    }

    // -- List owned numbers -------------------------------------------------------

    public function list(Request $request)
    {
        $clientId = $request->auth->parent_id ?: $request->auth->id;
        $conn     = "mysql_{$clientId}";

        // Sync live numbers from Plivo so numbers added outside this portal appear
        try {
            $service      = PlivoService::forClient($clientId);
            $liveNumbers  = $service->listNumbers(200);
            $liveList     = [];

            foreach ($liveNumbers as $n) {
                PlivoNumber::on($conn)->updateOrCreate(
                    ['number' => $n['number']],
                    [
                        'alias'       => $n['alias'] ?? $n['number'],
                        'country_iso' => $n['country_iso'] ?? 'US',
                        'sub_type'    => $n['sub_type'] ?? [],
                        'status'      => 'active',
                    ]
                );
                $liveList[] = $n['number'];
            }

            if (!empty($liveList)) {
                PlivoNumber::on($conn)
                    ->where('status', 'active')
                    ->whereNotIn('number', $liveList)
                    ->update(['status' => 'released']);
            }
        } catch (\Exception $e) {
            Log::warning('Plivo number sync failed', ['client' => $clientId, 'err' => $e->getMessage()]);
        }

        $perPage = (int) $request->input('limit', 20);
        $page    = max(1, (int) $request->input('page', 1));
        $search  = $request->input('search', '');

        $query = PlivoNumber::on($conn)->where('status', 'active');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('number', 'like', "%{$search}%")
                  ->orWhere('alias', 'like', "%{$search}%");
            });
        }

        $total   = $query->count();
        $numbers = $query->orderByDesc('id')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        return $this->successResponse('OK', [
            'numbers'      => $numbers,
            'total'        => $total,
            'current_page' => $page,
            'per_page'     => $perPage,
        ]);
    }

    // -- Campaign assignment ------------------------------------------------------

    public function assignToCampaign(Request $request)
    {
        $clientId   = $request->auth->parent_id ?: $request->auth->id;
        $conn       = "mysql_{$clientId}";
        $campaignId = (int) $request->input('campaign_id');
        $numberIds  = (array) $request->input('number_ids', []);

        if (!$campaignId || empty($numberIds)) {
            return $this->failResponse('campaign_id and number_ids are required.', [], null, 422);
        }

        $assigned = 0;
        foreach ($numberIds as $numberId) {
            DB::connection($conn)->table('plivo_campaign_numbers')->updateOrInsert(
                ['campaign_id' => $campaignId, 'plivo_number_id' => $numberId],
                ['is_active' => 1, 'updated_at' => \Carbon\Carbon::now(), 'created_at' => \Carbon\Carbon::now()]
            );
            PlivoNumber::on($conn)->where('id', $numberId)
                ->update(['campaign_id' => $campaignId]);
            $assigned++;
        }

        return $this->successResponse("{$assigned} number(s) assigned to campaign.");
    }

    public function getByCampaign(Request $request, int $campaignId)
    {
        $clientId = $request->auth->parent_id ?: $request->auth->id;
        $conn     = "mysql_{$clientId}";

        $rows = DB::connection($conn)
            ->table('plivo_campaign_numbers as pcn')
            ->join('plivo_numbers as pn', 'pcn.plivo_number_id', '=', 'pn.id')
            ->where('pcn.campaign_id', $campaignId)
            ->where('pcn.is_active', 1)
            ->select('pn.*', 'pcn.id as pcn_id', 'pcn.last_used_at')
            ->get();

        return $this->successResponse('OK', ['numbers' => $rows]);
    }

    public function unassignFromCampaign(Request $request)
    {
        $clientId   = $request->auth->parent_id ?: $request->auth->id;
        $conn       = "mysql_{$clientId}";
        $campaignId = (int) $request->input('campaign_id');
        $numberIds  = (array) $request->input('number_ids', []);

        if (!$campaignId || empty($numberIds)) {
            return $this->failResponse('campaign_id and number_ids are required.', [], null, 422);
        }

        DB::connection($conn)->table('plivo_campaign_numbers')
            ->where('campaign_id', $campaignId)
            ->whereIn('plivo_number_id', $numberIds)
            ->delete();

        PlivoNumber::on($conn)
            ->whereIn('id', $numberIds)
            ->where('campaign_id', $campaignId)
            ->update(['campaign_id' => null]);

        return $this->successResponse('Numbers unassigned from campaign.');
    }
}