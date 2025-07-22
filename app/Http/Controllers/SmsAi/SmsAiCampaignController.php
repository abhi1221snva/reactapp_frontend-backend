<?php

namespace App\Http\Controllers\SmsAi;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Model\Client\SmsAi\SmsAiCampaign;
use App\Model\Client\SmsAi\SmsAiList;
use Illuminate\Support\Facades\Log;

class SmsAiCampaignController extends Controller
{

    /**
     * @OA\Get(
     *     path="/smsai/campaigns",
     *     summary="Get all SMS AI Campaigns",
     *     description="Returns a list of SMS AI campaigns for the authenticated user's parent account. Includes counts of related lead reports and lead temps.",
     *     tags={"SmsAiCampaign"},
     *     security={{"Bearer":{}}},
 *      @OA\Parameter(
 *          name="start",
 *          in="query",
 *          description="Start index for pagination",
 *          required=false,
 *          @OA\Schema(type="integer", default=0)
 *      ),
 *      @OA\Parameter(
 *          name="limit",
 *          in="query",
 *          description="Limit number of records returned",
 *          required=false,
 *          @OA\Schema(type="integer", default=10)
 *      ),
     *     @OA\Response(
     *         response=200,
     *         description="List of SMS AI campaigns retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Campaign List"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="name", type="string", example="Spring Promo Campaign"),
     *                     @OA\Property(property="rowCountLeadReport", type="integer", example=12),
     *                     @OA\Property(property="sms_ai_lead_temps_count", type="integer", example=20),
     *                     @OA\Property(property="sms_ai_lead_report_count", type="integer", example=15),
     *                     @OA\Property(
     *                         property="lead_reports",
     *                         type="array",
     *                         @OA\Items(
     *                             @OA\Property(property="id", type="integer", example=101),
     *                             @OA\Property(property="sms_ai_list_data_count", type="integer", example=50)
     *                         )
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized access"
     *     )
     * )
     */

    public function indexold(Request $request)
    {
        $campaigns = SmsAiCampaign::on("mysql_" . $request->auth->parent_id)
            ->where('is_deleted', 0)
            ->withCount(['leadReports as rowCountLeadReport', 'SmsAiLeadTemps', 'SmsAiLeadReport'])->with(['leadReports' => function ($query) {
                $query->withCount('smsAiListData');
            }])
            ->get();

        $campaignsArray = $campaigns->toArray();

        return $this->successResponse("Campaign List", $campaignsArray);
    }
public function index(Request $request)
{
    $query = SmsAiCampaign::on("mysql_" . $request->auth->parent_id)
        ->where('is_deleted', 0)
        ->withCount([
            'leadReports as rowCountLeadReport',
            'SmsAiLeadTemps',
            'SmsAiLeadReport'
        ])
        ->with(['leadReports' => function ($query) {
            $query->withCount('smsAiListData');
        }]);

    if ($request->has('start') && $request->has('limit')) {
        $start = (int) $request->input('start');
        $limit = (int) $request->input('limit');
        $query->skip($start)->take($limit);
    }

    $campaigns = $query->get();

    return $this->successResponse("Campaign List", $campaigns->toArray());
}


    /**
     * @OA\Put(
     *     path="/smsai/campaign/add",
     *     summary="Create a new SMS AI Campaign",
     *     description="Creates a new SMS AI Campaign with the specified parameters.",
     *     tags={"SmsAiCampaign"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"title", "description", "dialing_mode", "status", "call_ratio", "call_duration", "sms_ai_template_id", "caller_id", "custom_caller_id", "time_based_calling", "call_time_start", "call_time_end", "country_code"},
     *             @OA\Property(property="title", type="string", example="SMS AI Campaign"),
     *             @OA\Property(property="description", type="string", example="SMS AI Campaign"),
     *             @OA\Property(property="dialing_mode", type="string", example="sms_ai"),
     *             @OA\Property(property="status", type="string", example="1"),
     *             @OA\Property(property="call_ratio", type="string", example="1"),
     *             @OA\Property(property="call_duration", type="string", example="30"),
     *             @OA\Property(property="sms_ai_template_id", type="integer", example=1),
     *             @OA\Property(property="caller_id", type="string", example="custom"),
     *             @OA\Property(property="custom_caller_id", type="integer", example=19852610564),
     *             @OA\Property(property="time_based_calling", type="integer", example=1),
     *             @OA\Property(property="call_time_start", type="string", format="time", example="00:30:00"),
     *             @OA\Property(property="call_time_end", type="string", format="time", example="21:30:00"),
     *             @OA\Property(property="country_code", type="integer", example=1)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="SMS AI Campaign created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Campaign created successfully"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation Error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Validation failed"),
     *             @OA\Property(property="errors", type="object")
     *         )
     *     )
     * )
     */

    public function create(Request $request)
    {
        $this->validate($request, ['title'      => 'required']);

        $data = $request->all();
        $smsAiCampaign = new SmsAiCampaign();
        $smsAiCampaign->setConnection("mysql_" . $request->auth->parent_id);
        $smsAiCampaign->fill($data);
        $smsAiCampaign->last_time_cron_run = null;

        $smsAiCampaign->save();

        return $this->successResponse("SMS Ai Campaign created", $smsAiCampaign->toArray());
    }

    /**
     * @OA\Get(
     *     path="/smsai/campaign/view/{id}",
     *     summary="Get SMS AI Campaign details",
     *     description="Fetches the details of a specific SMS AI Campaign by ID.",
     *     tags={"SmsAiCampaign"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID of the SMS AI Campaign",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Campaign details fetched successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="SMS AI Campaign info"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="object"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Campaign not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="No SMS AI Campaign with id 1"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="string"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to fetch SMS AI Campaign info"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="string"))
     *         )
     *     )
     * )
     */

    public function show(Request $request, int $id)
    {
        try {
            $tariff_label = SmsAiCampaign::on("mysql_" . $request->auth->parent_id)->where('id', $id)->get()->all();
            return $this->successResponse("SMS AI Campaign info", $tariff_label);
        } catch (ModelNotFoundException $exception) {
            throw new NotFoundHttpException("No SMS AI Campaign with id $id");
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch SMS AI Campaign info", [], $exception);
        }
    }

    /**
     * @OA\Post(
     *     path="/smsai/campaign/update/{id}",
     *     summary="update SMS AI Campaign details",
     *     description="Fetches the details of a specific SMS AI Campaign by ID.",
     *     tags={"SmsAiCampaign"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID of the SMS AI Campaign",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     * *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"title", "description", "dialing_mode", "status", "call_ratio", "call_duration", "sms_ai_template_id", "caller_id", "custom_caller_id", "time_based_calling", "call_time_start", "call_time_end", "country_code"},
     *             @OA\Property(property="title", type="string", example="SMS AI Campaign"),
     *             @OA\Property(property="description", type="string", example="SMS AI Campaign"),
     *             @OA\Property(property="dialing_mode", type="string", example="sms_ai"),
     *             @OA\Property(property="status", type="string", example="1"),
     *             @OA\Property(property="call_ratio", type="string", example="1"),
     *             @OA\Property(property="call_duration", type="string", example="30"),
     *             @OA\Property(property="sms_ai_template_id", type="integer", example=1),
     *             @OA\Property(property="caller_id", type="string", example="custom"),
     *             @OA\Property(property="custom_caller_id", type="integer", example=19852610564),
     *             @OA\Property(property="time_based_calling", type="integer", example=1),
     *             @OA\Property(property="call_time_start", type="string", format="time", example="00:30:00"),
     *             @OA\Property(property="call_time_end", type="string", format="time", example="21:30:00"),
     *             @OA\Property(property="country_code", type="integer", example=1)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Campaign details updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="SMS AI Campaign info"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="object"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Campaign not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="No SMS AI Campaign with id 1"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="string"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to fetch SMS AI Campaign info"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="string"))
     *         )
     *     )
     * )
     */
    public function update(Request $request, int $id)
    {

        try {
            $data = $request->all();

            SmsAiCampaign::on("mysql_" . $request->auth->parent_id)->where('id', $id)->update($data);


            return $this->successResponse("SMS AI Campaign updated");
        } catch (ModelNotFoundException $exception) {
            throw new NotFoundHttpException("No SMS AI Campaign with id $id");
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update SMS AI Campaign", [], $exception);
        }
    }


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




    public function campaignById(Request $request)
    {
        $response = RinglessCampaign::on('mysql_' . $request->auth->parent_id)->where('id', $request->campaign_id)->get();

        return response()->json($response);
    }


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
                    'call_time_end'
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
     *     path="/smsai/campaign/delete",
     *     summary="delete SMS AI Campaign details",
     *     description="delete SMS AI Campaign details by ID.",
     *     tags={"SmsAiCampaign"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"campaign_id"},
     *             @OA\Property(property="campaign_id", type="integer", example="1"),
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Sms AI Campaign details deleted successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="SMS AI Campaign info"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="object"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Campaign not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="No SMS AI Campaign with id 1"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="string"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to fetch SMS AI Campaign info"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="string"))
     *         )
     *     )
     * )
     */
    function deleteCampaign(Request $request)
    {
        $campaign_id = $request->campaign_id;
        $Campaign = SmsAiCampaign::on("mysql_" . $request->auth->parent_id)->findOrFail($campaign_id);
        $Campaign->is_deleted = 1;
        $deleted = $Campaign->update();

        if ($deleted) {
            return $this->successResponse("Campaign List", $Campaign->toArray());
        } else {
            return $this->failResponse("Failed to delete the Campaign ", [
                "Unkown"
            ]);
        }
    }
    /**
     * @OA\Post(
     *     path="/smsai/campaign/update-status",
     *     summary="update SMS AI Campaign  status",
     *     description="update SMS AI Campaign  status by ID.",
     *     tags={"SmsAiCampaign"},
     *     security={{"Bearer":{}}},
     *      @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"listId", "status"},
     *             @OA\Property(property="listId", type="string", example="12"),
     *             @OA\Property(property="status", type="string", example="1")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="SMS AI Campaign  status updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="SMS AI Campaign info"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="object"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Campaign not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="No SMS AI Campaign with id 1"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="string"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to fetch SMS AI Campaign info"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="string"))
     *         )
     *     )
     * )
     */

    function updateStatus(Request $request)
    {
        $listId = $request->input('listId');
        $status = $request->input('status');

        $saveRecord = SmsAiCampaign::on('mysql_' . $request->auth->parent_id)
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
     *     path="/smsai/campaign/copy",
     *     summary="Copy an existing SMS AI Campaign",
     *     description="Creates a duplicate of an existing SMS AI campaign.",
     *     tags={"SmsAiCampaign"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"c_id"},
     *             @OA\Property(property="c_id", type="integer", example=1, description="ID of the campaign to copy")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Campaign copied successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Copy campaign successfully."),
     *             @OA\Property(property="data", type="integer", example=10, description="ID of the newly copied campaign")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Original campaign not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Original campaign not found."),
     *             @OA\Property(property="data", type="array", @OA\Items(type="string"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error",
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
            $originalCampaign = SmsAiCampaign::on('mysql_' . $request->auth->parent_id)->find($campaignId);

            if ($originalCampaign) {
                $newCampaign = new SmsAiCampaign();
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
     *     path="/smsai/campaign-list",
     *     summary="Get SMS AI Campaign list detail",
     *     description="Get SMS AI Campaign list detail.",
     *     tags={"SmsAiCampaign"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"c_id"},
     *             @OA\Property(property="c_id", type="integer", example=1, description="ID of the campaign to copy")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="SMS AI Campaign list detail fetched successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Copy campaign successfully."),
     *             @OA\Property(property="data", type="integer", example=10, description="ID of the newly copied campaign")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Original campaign not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Original campaign not found."),
     *             @OA\Property(property="data", type="array", @OA\Items(type="string"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error",
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


        $response = SmsAiList::on("mysql_" . $request->auth->parent_id)
            ->where('campaign_id', $request->campaign_id)
            ->withCount([

                'smsAiLeadReport',
            ])

            ->get();
        $responseArray = $response->toArray();

        return response()->json($responseArray);
    }
}
