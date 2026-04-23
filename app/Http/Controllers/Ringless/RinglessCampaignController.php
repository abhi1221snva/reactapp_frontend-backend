<?php

namespace App\Http\Controllers\Ringless;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Model\Client\Ringless\RinglessCampaign;
use App\Model\Client\Ringless\RinglessList;


use Illuminate\Support\Facades\Log;

class RinglessCampaignController extends Controller
{
    // public function indexo(Request $request)
    // {
    //     $campaign = RinglessCampaign::on("mysql_" . $request->auth->parent_id)->where('is_deleted',0)->withCount(['RinglessList as rowCountLeadReport'])->get();
    //     $campaignsArray = $campaign->toArray();
    //     return $this->successResponse("Campaign List", $campaignsArray);
    // }

    /**
     * @OA\Get(
     *     path="/ringless/campaign",
     *     summary="Get list of ringless campaign",
     *     tags={"RinglessCampaign"},
     *     security={{"Bearer": {}}},
     * *     @OA\Parameter(
     *         name="start",
     *         in="query",
     *         required=false,
     *         description="Start index for pagination",
     *         @OA\Schema(type="integer", default=0)
     *     ),
     *     @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         required=false,
     *         description="Limit number of records returned",
     *         @OA\Schema(type="integer", default=10)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful response with ringless campaign data",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="List of ringless campaign."),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="name", type="string", example="Basic Package"),
     *                     @OA\Property(property="price", type="number", format="float", example=19.99),
     *                     @OA\Property(property="duration", type="string", example="30 days")
     *                 )
     *             )
     *         )
     *     )
     * )
     */
    public function index(Request $request)
    {
        $campaigns = RinglessCampaign::on("mysql_" . $request->auth->parent_id)
            ->where('is_deleted', 0)
            ->withCount([
                'ringlessList as rowCountLeadReport',
                'ringlessLeadTemps',
                'ringlessLeadReport',
            ])
            ->with(['ringlessList' => function ($query) {
                $query->withCount('ringlessListData');
            }])
            ->get();

        $campaignsArray = $campaigns->toArray();

        $totalRows = count($campaignsArray);
        if ($request->has('start') && $request->has('limit')) {
            $start = (int)$request->input('start'); // Start index (0-based)
            $limit = (int)$request->input('limit'); // Limit number of records to fetch

            // Show all data if start is 0 and limit is provided
            if ($start == 0 && $limit > 0) {
                $campaignsArray = array_slice($campaignsArray, 0, $limit); // Fetch only the first 'limit' records
            } else {
                // For normal pagination, calculate length from start and limit
                $length = $limit;
                $campaignsArray = array_slice($campaignsArray, $start, $length); // Fetch data from start to start+length
            }

            return response()->json([
                "success" => true,
                "message" => "Campaign list",
                'total_rows' => $totalRows,
                "data" => $campaignsArray
            ]);
        }

        return $this->successResponse("Campaign List", $campaignsArray);
    }

    public function index_old_code(Request $request)
    {
        $campaigns = RinglessCampaign::on("mysql_" . $request->auth->parent_id)
            ->where('is_deleted', 0)
            ->withCount([
                'ringlessList as rowCountLeadReport',
                'ringlessLeadTemps',
                'ringlessLeadReport',
            ])
            ->with(['ringlessList' => function ($query) {
                $query->withCount('ringlessListData');
            }])
            ->get();

        $campaignsArray = $campaigns->toArray();

        return $this->successResponse("Campaign List", $campaignsArray);
    }


    /**
     * @OA\Post(
     *     path="/ringless/campaign/add",
     *     summary="Store a new Ringless Campaign",
     *     description="Creates a new ringless voicemail campaign with the given parameters.",
     *     tags={"RinglessCampaign"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"title", "status", "caller_id", "call_time_start", "call_time_end", "country_code", "voice_template_id", "sip_gateway_id"},
     *             @OA\Property(property="title", type="string", example="Promo Blast April"),
     *             @OA\Property(property="description", type="string", example="April campaign for US clients"),
     *             @OA\Property(property="status", type="integer", example=1),
     *             @OA\Property(property="caller_id", type="string", enum={"area_code", "custom", "area_code_random"}, example="custom"),
     *             @OA\Property(property="custom_caller_id", type="integer", example=1234567890),
     *             @OA\Property(property="time_based_calling", type="integer", example=1),
     *             @OA\Property(property="call_time_start", type="string", format="time", example="09:00"),
     *             @OA\Property(property="call_time_end", type="string", format="time", example="17:00"),
     *             @OA\Property(property="call_duration", type="string", example="30s"),
     *             @OA\Property(property="call_ratio", type="string", example="1:1"),
     *             @OA\Property(property="country_code", type="integer", example=1),
     *             @OA\Property(property="voice_template_id", type="integer", example=5),
     *             @OA\Property(property="sip_gateway_id", type="integer", example=2)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Campaign created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Campaign added successfully."),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Validation error or bad request"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error"
     *     )
     * )
     */


    public function storeCampaign(Request $request)
    {

        try {
            $this->validate($request, [
                'title' => 'required|string',
                'description' => 'nullable|string',
                'status' => 'numeric',
                'caller_id' => 'in:area_code,custom,area_code_random',
                'custom_caller_id' => 'numeric',
                'time_based_calling' => 'numeric',
                'call_time_start' => 'date_format:H:i',
                'call_time_end' => 'date_format:H:i',
                'call_duration' => 'string',
                'call_ratio' => 'string',
                'country_code' => 'numeric',
                'voice_template_id' => 'numeric',
                'sip_gateway_id' => 'numeric',



            ]);

            // Use all the request data directly
            $data = $request->all();
            //Log::info('reached',['data'=>$data]);
            $ringlessCampaign = new RinglessCampaign();
            $ringlessCampaign->setConnection("mysql_" . $request->auth->parent_id);
            $ringlessCampaign->fill($data);
            // Save the model to the database
            $ringlessCampaign->save();

            $result = [
                'success' => true,
                'message' => 'Campaign added successfully.',
                'data' => $ringlessCampaign->toArray(),
            ];

            return $result;
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return [
                'success' => false,
                'message' => 'An error occurred while processing the request.',
            ];
        }
    }


    /**
     * @OA\Post(
     *     path="/ringless/campaign/show",
     *     summary="Retrieves a specific ringless voicemail campaign using its ID.",
     *     tags={"RinglessCampaign"},
     *     security={{"Bearer": {}}},
     * *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"campaign_id"},
     *             @OA\Property(property="campaign_id", type="integer", example=42)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful response with ringless campaign data",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="List of ringless campaign."),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="name", type="string", example="Basic Package"),
     *                     @OA\Property(property="price", type="number", format="float", example=19.99),
     *                     @OA\Property(property="duration", type="string", example="30 days")
     *                 )
     *             )
     *         )
     *     )
     * )
     */



    public function campaignById(Request $request)
    {
        $response = RinglessCampaign::on('mysql_' . $request->auth->parent_id)->where('id', $request->campaign_id)->get();

        return response()->json($response);
    }

    /**
     * @OA\Post(
     *     path="/ringless/campaign/edit",
     *     summary="Update an existing Ringless Campaign",
     *     description="Updates the details of an existing ringless voicemail campaign by campaign_id.",
     *     operationId="updateCampaign",
     *     tags={"RinglessCampaign"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"campaign_id"},
     *             @OA\Property(property="campaign_id", type="integer", example=42),
     *             @OA\Property(property="title", type="string", example="Updated Campaign Title"),
     *             @OA\Property(property="description", type="string", example="Updated campaign description"),
     *             @OA\Property(property="status", type="integer", example=1),
     *             @OA\Property(property="caller_id", type="string", enum={"area_code", "custom", "area_code_random"}, example="custom"),
     *             @OA\Property(property="custom_caller_id", type="integer", example=1234567890),
     *             @OA\Property(property="time_based_calling", type="integer", example=1),
     *             @OA\Property(property="call_time_start", type="string", example="09:00"),
     *             @OA\Property(property="call_time_end", type="string", example="17:00"),
     *             @OA\Property(property="call_duration", type="string", example="45s"),
     *             @OA\Property(property="call_ratio", type="string", example="1:2"),
     *             @OA\Property(property="country_code", type="integer", example=1),
     *             @OA\Property(property="voice_template_id", type="integer", example=5),
     *             @OA\Property(property="sip_gateway_id", type="integer", example=2)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Campaign updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Campaign updated successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Campaign not found"
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid input"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error"
     *     )
     * )
     */


    public function updateCampaign(Request $request)
    {
        try {
            if ($request->has('campaign_id') && is_numeric($request->input('campaign_id'))) {
                $this->validate($request, [
                    'title' => 'string',
                    'description' => 'nullable|string',
                    'status' => 'numeric',
                    'caller_id' => 'in:area_code,custom,area_code_random',
                    'custom_caller_id' => 'numeric',
                    'time_based_calling' => 'numeric',
                    'call_time_start' => 'string',
                    'call_time_end' => 'string',
                    'call_duration' => 'string',
                    'call_ratio' => 'string',
                    'country_code' => 'numeric',
                    'voice_template_id' => 'numeric',
                    'sip_gateway_id' => 'numeric',
                ]);

                $campaignId = $request->input('campaign_id');
                $date_time = date('Y-m-d h:i:s');

                // Retrieve validated data
                $data = $request->only([
                    'title',
                    'description',
                    'status',
                    'caller_id',
                    'custom_caller_id',
                    'time_based_calling',
                    'call_time_start',
                    'call_time_end',
                    'call_ratio',
                    'call_duration',
                    'country_code',
                    'sip_gateway_id',
                    'voice_template_id'
                ]);

                // Ensure the 'updated_at' field is set to the current timestamp
                $data['updated_at'] = $date_time;

                // Use Eloquent to update the model
                $campaign = RinglessCampaign::on("mysql_" . $request->auth->parent_id)->find($campaignId);

                if ($campaign) {
                    $campaign->update($data);

                    return [
                        'success' => true,
                        'message' => 'Campaign updated successfully.',
                    ];
                } else {
                    return [
                        'success' => false,
                        'message' => 'Campaign not found.',
                    ];
                }
            }

            return [
                'success' => false,
                'message' => 'Invalid input or campaign not found.',
            ];
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return [
                'success' => false,
                'message' => 'An error occurred while processing the request.',
            ];
        }
    }


    /**
     * @OA\Post(
     *     path="/ringless/campaign/delete",
     *     summary="Delete a specific ringless voicemail campaign using its ID.",
     *     tags={"RinglessCampaign"},
     *     security={{"Bearer": {}}},
     * *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"campaign_id"},
     *             @OA\Property(property="campaign_id", type="integer", example=42)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Delete a specific ringless voicemail campaign using its ID",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="List of ringless campaign."),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="name", type="string", example="Basic Package"),
     *                     @OA\Property(property="price", type="number", format="float", example=19.99),
     *                     @OA\Property(property="duration", type="string", example="30 days")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Campaign not found"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error"
     *     )
     * )
     */
    function deleteCampaign(Request $request)
    {
        $campaign_id = $request->campaign_id;
        $conn = "mysql_" . $request->auth->parent_id;
        $Campaign = RinglessCampaign::on($conn)->findOrFail($campaign_id);
        $Campaign->is_deleted = 1;
        $deleted = $Campaign->update();

        if ($deleted) {
            // Cascade: deactivate related ringless campaign lists
            \Illuminate\Support\Facades\DB::connection($conn)
                ->table('ringless_campaign_list')
                ->where('campaign_id', $campaign_id)
                ->update(['is_deleted' => 1]);

            return $this->successResponse("Campaign Deleted Successfully", $Campaign->toArray());
        } else {
            return $this->failResponse("Failed to delete the Campaign ", [
                "Unknown"
            ]);
        }
    }

    /**
     * @OA\Post(
     *     path="/ringless/campaign/update-status",
     *     summary="update a specific ringless voicemail campaign status using its ID.",
     *     tags={"RinglessCampaign"},
     *     security={{"Bearer": {}}},
     * *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"listId,status"},
     *             @OA\Property(property="listId", type="integer", example=42),
     *             @OA\Property(
     *             property="status",
     *             type="string",
     *             enum={"1", "0"},
     *             example="1",
     *            description="Campaign status: '1' for active, '0' for inactive")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="update a specific ringless voicemail campaign status using its ID",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="List of ringless campaign."),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="name", type="string", example="Basic Package"),
     *                     @OA\Property(property="price", type="number", format="float", example=19.99),
     *                     @OA\Property(property="duration", type="string", example="30 days")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Campaign not found"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error"
     *     )
     * )
     */
    function updateCampaignStatus(Request $request)
    {
        $listId = $request->input('listId');
        $status = $request->input('status');

        $saveRecord = RinglessCampaign::on('mysql_' . $request->auth->parent_id)
            ->where('id', $listId) // Use the actual listId received from the request
            ->update(array('status' => $status));
        Log::info('reached', ["saveRecord" => $saveRecord]);
        if ($saveRecord > 0) {
            return response()->json([
                'success' => 'true',
                'status' => 'true',
                'message' => 'Campaign status updated successfully'
            ]);
        } else {
            return response()->json([
                'success' => 'false',
                'status' => 'false',
                'message' => 'Campaign update failed'
            ]);
        }
    }

    /**
     * @OA\Post(
     *     path="/ringless/campaign/copy",
     *     summary="Copy an existing ringless campaign",
     *     description="Duplicates a campaign by its ID for the authenticated user's parent account.",
     *     operationId="copyCampaign",
     *     tags={"RinglessCampaign"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"c_id"},
     *             @OA\Property(property="c_id", type="integer", example=123, description="ID of the campaign to copy")
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=200,
     *         description="Copy successful",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Copy campaign successfully."),
     *             @OA\Property(property="data", type="integer", example=456, description="New campaign ID")
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=404,
     *         description="Original campaign not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Original campaign not found."),
     *             @OA\Property(property="data", type="array", @OA\Items(type="string"))
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="An error occurred while processing the request."),
     *             @OA\Property(property="data", type="array", @OA\Items(type="string"))
     *         )
     *     )
     * )
     */

    public function copyCampaign(Request $request)
    {
        try {
            $campaignId = $request->input('c_id');
            $originalCampaign = RinglessCampaign::on('mysql_' . $request->auth->parent_id)->find($campaignId);

            if ($originalCampaign) {
                $newCampaign = new RinglessCampaign();
                $newCampaign->setConnection("mysql_" . $request->auth->parent_id);

                // Copy attributes from the original campaign
                $newCampaign->title = 'Copy ' . $originalCampaign->title;
                $newCampaign->description = $originalCampaign->description;
                $newCampaign->status = $originalCampaign->status;
                $newCampaign->is_deleted = $originalCampaign->is_deleted;
                $newCampaign->caller_id = $originalCampaign->caller_id;
                $newCampaign->custom_caller_id = $originalCampaign->custom_caller_id;
                $newCampaign->time_based_calling = $originalCampaign->time_based_calling;
                $newCampaign->call_time_start = $originalCampaign->call_time_start;
                $newCampaign->call_time_end = $originalCampaign->call_time_end;
                $newCampaign->country_code = $originalCampaign->country_code;

                // Save the new campaign
                $newCampaign->save();

                return [
                    'success' => true,
                    'message' => 'Copy campaign successfully.',
                    'data' => $newCampaign->id,
                ];
            }

            return [
                'success' => false,
                'message' => 'Original campaign not found.',
                'data' => [],
            ];
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return [
                'success' => false,
                'message' => 'An error occurred while processing the request.',
                'data' => [],
            ];
        }
    }

    /**
     * @OA\Post(
     *     path="/ringless/campaign-list",
     *     summary="Retrive a ringless campaign detail by id",
     *     tags={"RinglessCampaign"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"campaign_id"},
     *             @OA\Property(property="campaign_id", type="integer", example=123, description="ID of the campaign to copy")
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=200,
     *         description="successful Retrive a ringless campaign detail by id",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Copy campaign successfully."),
     *             @OA\Property(property="data", type="integer", example=456, description="New campaign ID")
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=404,
     *         description="campaign not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Original campaign not found."),
     *             @OA\Property(property="data", type="array", @OA\Items(type="string"))
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="An error occurred while processing the request."),
     *             @OA\Property(property="data", type="array", @OA\Items(type="string"))
     *         )
     *     )
     * )
     */
    function getCampaignAndList(Request $request)
    {
        $response = RinglessList::on("mysql_" . $request->auth->parent_id)
            ->where('campaign_id', $request->campaign_id)
            ->withCount([

                'ringlessLeadReport',
            ])

            ->get();
        $responseArray = $response->toArray();

        return response()->json($responseArray);
    }
}
