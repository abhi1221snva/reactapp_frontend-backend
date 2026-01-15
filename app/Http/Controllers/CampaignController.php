<?php

namespace App\Http\Controllers;

use App\Model\Campaign;
use App\Model\Hubspot;

use App\Model\Client\CampaignTypes;

use App\Model\CampaignDisposition;
use App\Model\CampaignList;
use Illuminate\Support\Facades\DB;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class CampaignController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    private $request;
    public function __construct(Request $request, Campaign $Campaign, Hubspot $hubspot)
    {
        $this->request = $request;
        $this->model = $Campaign;
        $this->hubspot = $hubspot;
    }

    /*
     * Fetch Campaign details
     * @return json
     */

    /*
     * Fetch Campaign details
     * @return json
     */


    public function CampaignList(Request $request)
    {
        $campaign = Campaign::on("mysql_" . $request->auth->parent_id)->where('is_deleted', '0')->get()->all();
        return $this->successResponse("Campaign List", $campaign);
    }

    /**
     * @OA\Get(
     *     path="/campaign-type",
     *     summary="Retrieve the list of Campaign Type",
     *     tags={"Campaign"},
     *     security={{"Bearer":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="List of Campaign Type retrieved successfully",
     *         @OA\JsonContent(
     *          description="extension data"
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Campaign Type not found"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error"
     *     )
     * )
     */
    public function CampaignTypeList(Request $request)
    {
        $CampaignTypes = CampaignTypes::on("mysql_" . $request->auth->parent_id)->where('status', '1')->get()->all();
        return $this->successResponse("Campaign Type List", $CampaignTypes);
    }

    /**
     * @OA\Post(
     *     path="/campaign",
     *     summary="Retrieve the list of campaigns",
     *     tags={"Campaign"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="start",
     *                 type="integer",
     *                 default=0,
     *                 description="Start index for pagination"
     *             ),
     *             @OA\Property(
     *                 property="limit",
     *                 type="integer",
     *                 default=10,
     *                 description="Limit number of records returned"
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="List of Campaigns retrieved successfully",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="string"),
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="object"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid input or campaign not created"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error"
     *     )
     * )
     */

    public function getCampaign()
    {
        $response = $this->model->campaignDetail($this->request);
        return response()->json($response);
    }
    /**
     * Update Campaign detail
     * @return json
     */
    /*
     * Update Campaign detail
     * @return json
     */
    /**
     * @OA\Post(
     *     path="/edit-campaign",
     *     summary="Update an existing campaign",
     *     tags={"Campaign"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"campaign_id"},
     *             @OA\Property(property="campaign_id", type="integer", example=101),
     *             @OA\Property(property="title", type="string", example="Updated Campaign Title"),
     *             @OA\Property(property="description", type="string", example="Updated description"),
     *             @OA\Property(
     *                 property="dial_mode",
     *                 type="string",
     *                 enum={"super_power_dial", "predictive_dial", "outbound_ai"},
     *                 example="super_power_dial",
     *                 description="Determines which fields are required conditionally"
     *             ),
     *             @OA\Property(property="status", type="integer", example=1),
     *             @OA\Property(property="time_based_calling", type="integer", example=1),
     *             @OA\Property(property="call_time_start", type="string", format="time", example="09:00"),
     *             @OA\Property(property="call_time_end", type="string", format="time", example="18:00"),
     *             @OA\Property(property="caller_id", type="string", example="1234567890"),
     *             @OA\Property(property="custom_caller_id", type="integer", example=0),
     *             @OA\Property(
     *                 property="call_ratio",
     *                 type="string",
     *                 example="0",
     *                 description="Required if dial_mode is predictive_dial"
     *             ),
     *             @OA\Property(
     *                 property="duration",
     *                 type="string",
     *                 example="0",
     *                 description="Required if dial_mode is predictive_dial"
     *             ),
     *             @OA\Property(
     *                 property="automated_duration",
     *                 type="string",
     *                 example="0",
     *                 description="Required if dial_mode is predictive_dial"
     *             ),
     *             @OA\Property(
     *                 property="amd",
     *                 type="string",
     *                 example="0",
     *                 description="Required if dial_mode is predictive_dial or outbound_ai"
     *             ),
     *             @OA\Property(
     *                 property="redirect_to",
     *                 type="string",
     *                 example="0",
     *                 description="Required if dial_mode is outbound_ai"
     *             ),
     *             @OA\Property(property="group_id", type="integer", example=5),
     *             @OA\Property(property="country_code", type="integer", example=5),
     *             @OA\Property(property="send_crm", type="integer", example=5),
     *             @OA\Property(property="email", type="integer", example=5),
     *             @OA\Property(property="send_report", type="integer", example=5),
     *             @OA\Property(property="call_transfer", type="integer", example=5),
     *             @OA\Property(property="is_deleted", type="integer", example=0),
     *             @OA\Property(property="max_lead_temp", type="integer", example=100),
     *             @OA\Property(property="min_lead_temp", type="integer", example=10),
     *             @OA\Property(property="api", type="integer", example=1),
     *             @OA\Property(property="hopper_mode", type="integer", example=0),
     *             @OA\Property(
     *                 property="disposition_id",
     *                 type="array",
     *                 @OA\Items(type="integer"),
     *                 example={1, 2, 3}
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Campaign update response",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Campaign updated successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid input"
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



    public function updateCampaign()
    {
        $this->validate($this->request, [
            'title'             => 'string|max:255',
            'status'            => 'numeric',
            'is_deleted'        => 'numeric',
            'campaign_id'       => 'required|numeric',
            // 'id'                => 'required|numeric',
            'description'       => 'string|max:255',
            'caller_id'         => 'string|max:255',
            'custom_caller_id'  => 'numeric',
            'time_based_calling' => 'numeric',
            'call_time_start'   => 'date_format:H:i',
            'call_time_end'     => 'date_format:H:i',
            'dial_mode'         => 'string|max:255',
               // 👇 CONDITIONAL RULE
             'group_id'           => 'required_if:dial_mode,super_power_dial|numeric',
            'max_lead_temp'     => 'numeric',
            'min_lead_temp'     => 'numeric',
            'api'               => 'numeric',
            'is_deleted'        => 'numeric',
            'send_report'       => 'numeric',
            'hopper_mode'       => 'numeric',
            'call_metric' => 'string',


        ]);
        $result = $this->model->updateCampaign($this->request);
       // return response()->json($response);
           return response()->json(
        [
            'success' => $result['success'],
            'message' => $result['message']
        ],
        $result['status'] ?? 200
    );
    }
    /*
     *Add Campaign details
     *@return json
     */

    /**
     * @OA\Post(
     *     path="/add-campaign",
     *     summary="Add a new campaign",
     *     description="Creates a new campaign and optionally syncs it with a CRM platform if crm_title_url is provided. Leave crm_title_url null to skip CRM sync.",
     *     operationId="addCampaign",
     *     tags={"Campaign"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"disposition_id", "group_id"},
     *             @OA\Property(property="title", type="string", maxLength=255, example="Summer Campaign"),
     *             @OA\Property(property="description", type="string", maxLength=255, example="This is a test campaign"),
     *             @OA\Property(property="status", type="integer", example=1),
     *             @OA\Property(property="is_deleted", type="integer", example=0),
     *             @OA\Property(property="caller_id", type="string", maxLength=255, example="1234567890"),
     *             @OA\Property(property="custom_caller_id", type="integer", example=0),
     *             @OA\Property(property="time_based_calling", type="integer", example=1),
     *             @OA\Property(property="call_time_start", type="string", format="time", example="09:00"),
     *             @OA\Property(property="call_time_end", type="string", format="time", example="18:00"),
     *             @OA\Property(property="dial_mode", type="string", maxLength=255, example="predictive"),
     *             @OA\Property(property="group_id", type="integer", example=5),
     *             @OA\Property(property="max_lead_temp", type="integer", example=50),
     *             @OA\Property(property="min_lead_temp", type="integer", example=10),
     *             @OA\Property(property="api", type="integer", example=1),
     *             @OA\Property(property="send_report", type="integer", example=1),
     *             @OA\Property(property="disposition_id", type="array", @OA\Items(type="integer")),
     *             @OA\Property(property="hopper_mode", type="integer", example=0),
     *             @OA\Property(property="call_ratio", type="string", example="1:1"),
     *             @OA\Property(property="duration", type="string", example="30"),
     *             @OA\Property(property="automated_duration", type="string", example="20"),
     *             @OA\Property(property="api_id", type="integer", example="40"),
     *             @OA\Property(property="crm_title_url", type="string", nullable=true, example="null", description="CRM platform to sync with or null to skip CRM sync"),
     *             @OA\Property(
     *                 property="hubspot_lists",
     *                 type="array",
     *                 @OA\Items(type="integer", example=null),
     *                 description="HubSpot list IDs (only used if crm_title_url is not null)"
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Campaign created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Campaign added successfully."),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=123),
     *                 @OA\Property(property="title", type="string", example="Summer Campaign")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error"
     *     )
     * )
     */

    public function addCampaign(Request $request)
    {
        Log::info('campaign reached', [$request->all()]);
        $this->validate($this->request, [
            // 'id'                => 'required|numeric',
            'title'             => 'required|string|max:255',
            'description'       => 'string|max:255',
            'status'            => 'numeric',
            'is_deleted'        => 'numeric',
            'caller_id'         => 'string|max:255',
            'custom_caller_id'  => 'numeric',
            'time_based_calling' => 'numeric',
            'call_time_start'   => 'date_format:H:i',
            'call_time_end'     => 'date_format:H:i',
            'dial_mode'         => 'string|max:255',
            // 'group_id'          => 'numeric',
            'max_lead_temp'     => 'numeric',
            'min_lead_temp'     => 'numeric',
            'api'               => 'numeric',
                // 👇 CONDITIONAL RULE
    'group_id'           => 'required_if:dial_mode,super_power_dial|numeric',
            // 'group_id'          => 'required|numeric',
            'send_report'       => 'numeric',
            'disposition_id'    => 'required|array',
            'hopper_mode'       => 'numeric',
            'call_ratio'        => 'string',
            'duration'          => 'string',
            'automated_duration' => 'string',
            'call_metric' => 'string',


        ]);

        if ($this->request->crm_title_url == 'hubspot') {
            $response = $this->hubspot->addCampaignHubspot($this->request);
        } else {
            $response = $this->model->addCampaign($this->request);
        }

        return response()->json($response);
    }
    /*
     * Fetch campaign for agent
     * @return json
     */
    public function getAgentCampaign()
    {
        $this->validate($this->request, [
            'id' => 'required|numeric'
        ]);
        $response = $this->model->getAgentCampaign($this->request);
        return response()->json($response);
    }
    /**
     * @OA\Post(
     *     path="/campaigns-count",
     *     summary="Get Campaign Count",
     *     description="Returns the total number of campaigns that are not deleted for the authenticated client.",
     *     tags={"Campaign"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=false,
     *         description="No body parameters required"
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful response with campaign count",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Extension is not belong to any campaign."),
     *             @OA\Property(property="data", type="integer", example=13)
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error"
     *     )
     * )
     */

    function getCampaignCount()
    {
        $response = $this->model->getCampaignCount($this->request);
        return response()->json($response);
    }


    /**
     * @OA\Post(
     *     path="/campaign-list",
     *     summary="Get campaign and associated list",
     *     tags={"Campaign"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"campaign_id"},
     *             @OA\Property(property="campaign_id", type="integer", example=101)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful response",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="data", type="object", description="Campaign and List Data")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid request or missing parameters"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Campaign not found"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error"
     *     )
     * )
     */

    public function getCampaignAndList()
    {
        $campaign = Campaign::on("mysql_" . $this->request->auth->parent_id)->where('id', $this->request->campaign_id)->get()->first();

        if ($campaign->crm_title_url == 'hubspot') {
            $response = $this->hubspot->getCampaignAndListHubspot($this->request);
        } else {
            $response = $this->model->getCampaignAndList($this->request);
        }
        return response()->json($response);
    }

    function getCampaignAndList_old()
    {


        $campaign = Campaign::on("mysql_" . $this->request->auth->parent_id)->where('id', $this->request->campaign_id)->get()->first();
        if ($campaign->crm_title_url == 'hubspot') {
            $response = $this->hubspot->getCampaignAndListHubspot($this->request);
        } else {
            $response = $this->model->getCampaignAndList($this->request);
        }


        return response()->json($response);
    }

    /**
     * @OA\Post(
     *     path="/list-disposition",
     *     summary="Get Disposition and List Count by List ID",
     *     tags={"Campaign"},
     *     description="Fetch disposition data with count for a given list ID from the lead report",
     *      security={{"Bearer":{}}},
     * 
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"list_id"},
     *             @OA\Property(property="list_id", type="integer", example=123, description="ID of the list")
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=200,
     *         description="Disposition data fetched successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Disposition List detail."),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="name", type="string", example="Contacted"),
     *                     @OA\Property(property="record_count", type="integer", example=45)
     *                 )
     *             )
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=400,
     *         description="Missing or invalid list_id",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Disposition List Not Found."),
     *             @OA\Property(property="data", type="array", @OA\Items())
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error"
     *     )
     * )
     */

    function getDispositionAndList()
    {
        $response = $this->model->getDispositionAndList($this->request);
        return response()->json($response);
    }

    /**
     * @OA\Post(
     *     path="/delete-list-disposition",
     *     summary="Delete disposition list",
     *     tags={"Campaign"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Payload containing campaign and list identifiers, dispositions to delete, and selection counts",
     *         @OA\JsonContent(
     *             required={"list_id", "campaign_id", "disposition", "select_id"},
     *             @OA\Property(
     *                 property="list_id",
     *                 type="integer",
     *                 description="Identifier of the list",
     *                 example=101
     *             ),
     *             @OA\Property(
     *                 property="campaign_id",
     *                 type="integer",
     *                 description="Identifier of the campaign",
     *                 example=202
     *             ),
     *             @OA\Property(
     *                 property="disposition",
     *                 type="array",
     *                 description="Array of disposition IDs to delete",
     *                 @OA\Items(type="integer"),
     *                 example={1, 2, 3}
     *             ),
     *             @OA\Property(
     *                 property="select_id",
     *                 type="object",
     *                 description="Mapping of disposition IDs to user selection counts",
     *                 additionalProperties={
     *                     "type": "integer"
     *                 },
     *                 example={"1": 5, "2": 10, "3": 7}
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Dispositions and associated leads deleted successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Disposition List deleted successfully."),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="list_id", type="integer", example=101),
     *                 @OA\Property(property="campaign_id", type="integer", example=202),
     *                 @OA\Property(
     *                     property="disposition_count",
     *                     type="object",
     *                     additionalProperties={
     *                         "type": "integer"
     *                     },
     *                     example={"1": 5, "2": 10, "3": 7}
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid input parameters",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Invalid input data."),
     *             @OA\Property(property="data", type="object", example={})
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="An unexpected error occurred."),
     *             @OA\Property(property="data", type="object", example={})
     *         )
     *     )
     * )
     */
    function deleteDispositionAndList()
    {
        $response = $this->model->deleteDispositionAndList($this->request);
        return response()->json($response);
    }
    /**
     * @OA\Post(
     *     path="/copy-campaign",
     *     summary="Copy an existing campaign",
     *     tags={"Campaign"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Payload containing the ID to copy",
     *         @OA\JsonContent(
     *             required={"c_id"},
     *             @OA\Property(
     *                 property="c_id",
     *                 type="integer",
     *                 description="Identifier of the ID to copy",
     *                 example=123
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Campaign copied successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="New API added successfully."),
     *            description="Extension data",
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid input parameters",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Invalid input data.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="An unexpected error occurred.")
     *         )
     *     )
     * )
     */
    function copyCampaign()
    {
        $response = $this->model->copyCampaign($this->request);
        return response()->json($response);
    }

    /**
     * @OA\Post(
     *     path="/campaign-by-id",
     *     summary="Retrieve campaign details by ID",
     *     tags={"Campaign"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Campaign ID payload",
     *         @OA\JsonContent(
     *             required={"campaign_id"},
     *             @OA\Property(
     *                 property="campaign_id",
     *                 type="integer",
     *                 description="ID of the campaign to retrieve",
     *                 example=123
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Campaign retrieved successfully",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="id", type="integer", example=123),
     *             @OA\Property(property="name", type="string", example="Summer Campaign"),
     *             @OA\Property(property="status", type="string", example="active")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Campaign not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="Campaign not found.")
     *         )
     *     )
     * )
     */
    function campaignById()
    {
        $response = $this->model->campaignById($this->request);

        return $response;
    }
    /**
     * @OA\Post(
     *     path="/delete-campaign",
     *     summary="Delete a campaign by ID",
     *     tags={"Campaign"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Campaign ID payload",
     *         @OA\JsonContent(
     *             required={"campaign_id"},
     *             @OA\Property(
     *                 property="campaign_id",
     *                 type="integer",
     *                 description="ID of the campaign to delete",
     *                 example=123
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Campaign deleted successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Campaign List"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=123),
     *                 @OA\Property(property="title", type="string", example="Spring Campaign"),
     *                 @OA\Property(property="is_deleted", type="integer", example=1)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Campaign not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to delete the Campaign"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="string"))
     *         )
     *     )
     * )
     */
    function deleteCampaign(Request $request)
    {
        $campaign_id = $request->campaign_id;
        $Campaign = Campaign::on("mysql_" . $request->auth->parent_id)->findOrFail($campaign_id);
        $Campaign->is_deleted = 1;
        $deleted = $Campaign->update();

        if ($deleted) {
            return $this->successResponse("Campaign Deleted Successfully", $Campaign->toArray());
        } else {
            return $this->failResponse("Failed to delete the Campaign ", [
                "Unkown"
            ]);
        }
    }
    /**
     * @OA\Post(
     *     path="/status-update-campaign",
     *     summary="update status a campaign by list ID",
     *     tags={"Campaign"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"listId", "status"},
     *             @OA\Property(property="listId", type="integer", example=123),
     *             @OA\Property(property="status", type="integer", example="1 or 0")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Campaign status updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="status", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Campaign status updated successfully")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Campaign not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to update status the Campaign"),
     *            )
     *     )
     * )
     */
    public function updateCampaignStatus()
    {
        $response = $this->model->updateCampaignStatus($this->request);
        return response()->json($response);
    }
    /**
     * @OA\Post(
     *     path="/status-update-hopper",
     *     summary="update hopper a campaign by list ID",
     *     tags={"Campaign"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"listId", "status"},
     *             @OA\Property(property="listId", type="integer", example=123),
     *             @OA\Property(property="status", type="integer", example="1 or 0")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Campaign hopper updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="status", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Campaign status hopper updated successfully"),
     *             description="extension data",
     *           )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Campaign not found",
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to update  status the Campaign"),
     *            )
     *     )
     * )
     */
    public function updateCampaignHopper()
    {
        $response = $this->model->updateCampaignHopper($this->request);
        return response()->json($response);
    }


    public function getCallSchedule($id, Request $request)
{

     $campaignId = $id;
    try {
        // Fetch the campaign
        $campaign = DB::connection('mysql_' . $request->auth->parent_id)
                      ->table('campaign')
                      ->where('id', $campaignId)
                      ->first();

        if (!$campaign) {
            return response()->json([
                'success' => false,
                'message' => 'Campaign not found'
            ], 404);
        }

        // Fetch the campaign schedules
        $schedules = DB::connection('mysql_' . $request->auth->parent_id)
                       ->table('campaign_schedules')
                       ->where('campaign_id', $campaignId)
                       ->get()
                       ->keyBy('day_of_week');

        // Format schedules for cleaner response
        $formattedSchedules = [];
        foreach ($schedules as $day => $schedule) {
            $formattedSchedules[$day] = [
                'enabled'    => (bool) $schedule->enabled,
                'start_time' => $schedule->start_time,
                'end_time'   => $schedule->end_time
            ];
        }

        return response()->json([
            'success' => true,
            'message' => 'Call schedule fetched successfully',
            'data'    => [
                'campaign_id' => $campaign->id,
                'title'       => $campaign->title,
                'call_schedule' => $formattedSchedules
            ]
        ]);

    } catch (Exception $e) {
        Log::error($e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Something went wrong: ' . $e->getMessage()
        ], 500);
    }
}




public function assignLists(Request $request)
{
    // 1. Basic request validation (format only)
    $validator = Validator::make($request->all(), [
        'campaign_id'    => 'required|integer',
        'lead_list_ids'  => 'required|array|min:1',
        'lead_list_ids.*'=> 'integer',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'errors'  => $validator->errors()
        ], 422);
    }

    $campaignId = $request->input('campaign_id');
    $leadListIds = $request->input('lead_list_ids');

    // 2. Check if campaign exists
    $campaignExists = DB::connection('mysql_' . $request->auth->parent_id)->table('campaign')->where('id', $campaignId)->exists();
    if (!$campaignExists) {
        return response()->json([
            'success' => false,
            'message' => "Campaign ID {$campaignId} does not exist."
        ], 404);
    }

    // 3. Check each lead list id exists
    $invalidLists = [];
    foreach ($leadListIds as $listId) {
        $exists = DB::connection('mysql_' . $request->auth->parent_id)->table('list')->where('id', $listId)->exists();
        if (!$exists) {
            $invalidLists[] = $listId;
        }
    }

    if (!empty($invalidLists)) {
        return response()->json([
            'success' => false,
            'message' => 'Some lead list IDs do not exist.',
            'invalid_list_ids' => $invalidLists
        ], 404);
    }
        // 4. Deactivate all existing assigned lists (DO NOT DELETE)
    DB::connection('mysql_' . $request->auth->parent_id)
        ->table('campaign_list')
        ->where('campaign_id', $campaignId)
        ->update([
            'status'     => '0',
            'updated_at' => Carbon::now()
        ]);

    // 4. Insert/update mapping
    foreach ($leadListIds as $listId) {
        DB::connection('mysql_' . $request->auth->parent_id)->table('campaign_list')->updateOrInsert(
            [
                'campaign_id' => $campaignId,
                'list_id'     => $listId,
            ],
            [
                'is_deleted' => 0,
                'status'     => '1',
                'updated_at' => Carbon::now(),
            ]
        );
    }

    return response()->json([
        'success'       => true,
        'message'       => 'Campaign assigned to lead lists successfully.',
        'campaign_id'   => $campaignId,
        'lead_list_ids' => $leadListIds,
    ]);
}




}
