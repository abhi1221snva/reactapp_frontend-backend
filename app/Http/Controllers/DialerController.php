<?php

namespace App\Http\Controllers;

use App\Model\Client\EmailTemplete;
use App\Model\Client\ListData;
use App\Model\Master\AreaCodeList;
use App\Model\Master\Client;


use App\Model\Dialer;
use App\Model\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Model\Client\CrmLog;
use App\Model\Client\Comments;
use App\Model\Client\LineDetail;
use App\Model\Client\ExtensionLive;
use App\Model\Client\LocalChannel;
use App\Services\EasifyCreditService;



use App\Model\Api;

class DialerController extends Controller
{

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    private $request;
    private $model;

    public function __construct(Request $request, Dialer $dialer)
    {
        $this->request = $request;
        $this->model = $dialer;
    }

    /*
     * Fetch campaign for agent
     * @return json
     */

    /**
     * @OA\Post(
     *     path="/agent-campaign",
     *     summary="Get campaigns assigned to an agent based on extension and time window",
     *     tags={"Dialer"},
     *     security={{"Bearer":{}}},
     *  @OA\RequestBody(
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
     *         description="List of campaigns for the agent",
     *         @OA\JsonContent(
     *             @OA\Property(property="utc_time", type="string", example="2025-06-17 09:30:00"),
     *             @OA\Property(property="timezone", type="string", example="Asia/Kolkata"),
     *             @OA\Property(property="timezoneValue", type="string", example="+05:30"),
     *             @OA\Property(property="time", type="string", example="10:00:00(+05:30)"),
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="List of campaign for extension."),
     *             @OA\Property(property="data", type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=12),
     *                     @OA\Property(property="name", type="string", example="Sales Campaign"),
     *                     @OA\Property(property="group_id", type="integer", example=5),
     *                     @OA\Property(property="call_time_start", type="string", example="09:00:00"),
     *                     @OA\Property(property="call_time_end", type="string", example="18:00:00"),
     *                     @OA\Property(property="status", type="integer", example=1)
     *                 )
     *             ),
     *             @OA\Property(property="login", type="object",
     *                 @OA\Property(property="extension", type="string", example="101"),
     *                 @OA\Property(property="status", type="string", example="Logged In")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="Dialing not permitted",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="You do not have dialing permission"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="object")),
     *             @OA\Property(property="login", type="boolean", example=false)
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Error message here"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="object")),
     *             @OA\Property(property="login", type="boolean", example=false)
     *         )
     *     )
     * )
     */

    public function getAgentCampaign()
    {
        $response = $this->model->getAgentCampaign($this->request);
        return response()->json($response);
    }

    /*
     * lead Count in temp
     * @return json
     */

    /**
     * @OA\Post(
     *     path="/lead-temp",
     *     tags={"Dialer"},
     *     summary="Get count of leads in temporary table for a campaign",
     *     description="Returns the count of leads currently in the lead_temp table for the given campaign. If count < 50, a background job is triggered to add leads.",
     *     operationId="getLeadCountInTemp",
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"campaign_id"},
     *             @OA\Property(property="campaign_id", type="integer", example=12, description="Campaign ID")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lead count result",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Lead Count"),
     *             @OA\Property(property="count", type="integer", example=87)
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

    public function getLeadCountInTemp()
    {
        $this->validate($this->request, [
            'campaign_id' => 'required|numeric'
        ]);
        $response = $this->model->getLeadCountInTemp($this->request->campaign_id, $this->request->auth->parent_id);
        return response()->json($response);
    }

    /*
     * lead Count in temp
     * @return json
     */


    /* CRM Webphone Example */


    /**
     * @OA\Get(
     *     path="/asterisk-login",
     *     summary="Handles CRM Webphone login or click-to-call depending on agent status",
     *     tags={"Dialer"},
     *     operationId="asteriskLoginCRM",
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(name="extension", in="query", required=true, @OA\Schema(type="string")),
     *     @OA\Parameter(name="cli", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="number", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="lead_id", in="query", required=false, @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="Success response",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Webphone In Call Status Active")
     *         )
     *     )
     * )
     */

    public function asteriskLoginCRM()
    {
        $response = $this->model->asteriskLoginCRM($this->request);
        return response()->json($response);
    }

    /**
     * @OA\get(
     *     path="/asterisk-hang-up",
     *     summary="Hang up an ongoing call for a CRM user",
     *     tags={"Dialer"},
     *     operationId="hangUpCRM",
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"extension", "number", "lead_id"},
     *             @OA\Property(property="extension", type="string", example="1001", description="Agent's SIP, Alt or App extension"),
     *             @OA\Property(property="number", type="string", example="9876543210", description="Customer's number involved in the call"),
     *             @OA\Property(property="lead_id", type="integer", example=1234, description="Associated lead ID")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Call hang up successful",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="integer", example=1),
     *             @OA\Property(property="message", type="string", example="Hang up successful for number =9876543210"),
     *             @OA\Property(property="data", type="object", nullable=true),
     *             @OA\Property(property="code", type="integer", example=303)
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized or hang up failed",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="integer", example=0),
     *             @OA\Property(property="message", type="string", example="Unable to hang up the number =9876543210"),
     *             @OA\Property(property="data", type="object", nullable=true),
     *             @OA\Property(property="code", type="integer", example=401)
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Missing required parameters",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="integer", example=0),
     *             @OA\Property(property="message", type="string", example="Extension not found"),
     *             @OA\Property(property="code", type="integer", example=404)
     *         )
     *     )
     * )
     */
    public function hangUpCRM()
    {

        $response = $this->model->hangUpCRM($this->request);
        return response()->json($response);
    }

    /**
     * @OA\Post(
     *     path="/extension-login",
     *     tags={"Dialer"},
     *     summary="Agent Extension Login",
     *     description="Logs an agent into the dialer system using their extension and campaign. Determines extension source dynamically based on dialer mode and webphone setting.",
     *     operationId="extensionLogin",
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"campaign_id"},
     *             @OA\Property(property="campaign_id", type="integer", example=10, description="Campaign ID to log in to")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Login result",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="You are logged in successfully. Lead assigned.")
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

    // public function extensionLogin()
    // {
    //     $this->validate($this->request, [
    //         'campaign_id' => 'required|numeric'
    //     ]);
    //     $response = $this->model->extensionLogin($this->request);
    //         // ✅ Set HTTP status based on success
    //     // ✅ Set HTTP status based on success (handles boolean & string)
    //     $statusCode = (
    //         isset($response['success']) &&
    //         filter_var($response['success'], FILTER_VALIDATE_BOOLEAN)
    //     )
    //         ? 200
    //         : 402;

    //     return response()->json($response, $statusCode);
    // }
    public function extensionLogin()
{
    $this->validate($this->request, [
        'campaign_id' => 'required|numeric'
    ]);

    $response = $this->model->extensionLogin($this->request);

    // ✅ If model already returned a JsonResponse, return it directly
    if ($response instanceof \Illuminate\Http\JsonResponse) {
        return $response;
    }

    // ✅ Otherwise, handle array response safely
    $statusCode = (
        isset($response['success']) &&
        filter_var($response['success'], FILTER_VALIDATE_BOOLEAN)
    ) ? 200 : 402;

    return response()->json($response, $statusCode);
}


    /*
     * dial number
     * @return json
     */

    /**
     * @OA\Post(
     *     path="/call-number",
     *     tags={"Dialer"},
     *     summary="Click-to-call a number",
     *     description="Triggers a call to a specified number for a given lead and campaign.",
     *     operationId="callNumber",
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"campaign_id", "lead_id", "number", "id"},
     *             @OA\Property(property="campaign_id", type="integer", example=101, description="Campaign ID"),
     *             @OA\Property(property="lead_id", type="integer", example=502, description="Lead ID"),
     *             @OA\Property(property="number", type="string", example="9876543210", description="Phone number to call"),
     *             @OA\Property(property="id", type="integer", example=1, description="Arbitrary required ID field")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Call response",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Number called")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad request or validation error"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error"
     *     )
     * )
     */

    public function callNumber()
    {
        $this->validate($this->request, [
            'campaign_id' => 'required|numeric',
            'lead_id' => 'required|numeric',
            'number' => 'required|numeric',
            'id' => 'required|numeric'
        ]);

        $response = $this->model->callNumber($this->request);
        // if ($response instanceof \Illuminate\Http\JsonResponse) {
        //     return $response;
        // }

        // $statusCode = $response['status'] ?? 200;

        // if (isset($response['status'])) {
        //     unset($response['status']);
        // }

        // return response()->json($response, $statusCode);
     return response()->json($response);

    }

    /*
     * Hang Up
     * @return json
     */

    /**
     * @OA\Post(
     *     path="/hang-up",
     *     tags={"Dialer"},
     *     summary="Hang up active call",
     *     description="Terminates the ongoing call for the agent's current extension.",
     *     operationId="hangUp",
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"id"},
     *             @OA\Property(property="id", type="integer", example=1, description="Arbitrary required ID field (possibly user or session id)")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Hang up result",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Hang up successful")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad request or validation error"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error"
     *     )
     * )
     */

    public function hangUp()
    {
        $this->validate($this->request, [
            'id' => 'required|numeric'
        ]);
        $response = $this->model->hangUp($this->request);
    //     $user = $this->request->auth;
    //     $db   = "mysql_" . $user->parent_id;
    //     $extension = $user->extension;

    // // Small delay to ensure CDR is inserted
    // sleep(1);

    // /* ==========================
    //  * 🔹 GET LAST OUTBOUND CDR
    //  * ========================== */

    // $cdr = DB::connection($db)->selectOne(
    //     "SELECT id, cli, duration
    //      FROM cdr
    //      WHERE extension = :extension
    //        AND route = 'OUT'
    //        AND duration > 0
    //      ORDER BY id DESC
    //      LIMIT 1",
    //     ['extension' => $extension]
    // );

    // // ❌ No CDR found → no billing
    // if (empty($cdr)) {
    //     Log::info('No outbound CDR found for billing', [
    //         'extension' => $extension
    //     ]);
    //     return response()->json($response);
    // }

    // Log::info('Outbound CDR found', [
    //     'cdr_id' => $cdr->id,
    //     'cli' => $cdr->cli,
    //     'duration' => $cdr->duration
    // ]);




    // /* ==========================
    //  * 🔹 DEDUCT CREDITS
    //  * ========================== */

    // $creditService = new EasifyCreditService();

    // $deductResponse = $creditService->deductCredits(
    //     $user->id,
    //     $user->easify_user_uuid,
    //     'outgoing_call',
    //     (string) $cdr->cli,
    //     (int) $cdr->duration
    // );

    // Log::info('Credit deduction response (hangUp)', [
    //     'user_id' => $user->id,
    //     'cdr_id' => $cdr->id,
    //     'response' => $deductResponse
    // ]);


        return response()->json($response);

    }

    /*
     * disposition campaign
     * @return json
     */

    /**
     * @OA\Post(
     *     path="/disposition-campaign",
     *     tags={"Dialer"},
     *     summary="Get campaign dispositions",
     *     description="Returns a list of dispositions available for the given campaign.",
     *     operationId="getDispositionCampaign",
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="id", type="integer", example=1, description="User ID"),
     *             @OA\Property(property="campaign_id", type="integer", example=12, description="Campaign ID")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Dispositions fetched successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Dispositions detail."),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=3),
     *                     @OA\Property(property="title", type="string", example="Call Back")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad request"
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized"
     *     )
     * )
     */

    public function dispositionCampaign()
    {
        $this->validate($this->request, [
            'id' => 'required|numeric',
            'campaign_id' => 'required|numeric'
        ]);
        $response = $this->model->dispositionCampaign($this->request);
        return response()->json($response);
    }

    /**
     * @OA\Post(
     *     path="/disposition_by_campaignId",
     *     tags={"Dialer"},
     *     summary="Get dispositions by campaign ID",
     *     description="Fetches all enabled dispositions assigned to a given campaign.",
     *     operationId="dispositionByCampaignId",
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"campaign_id"},
     *             @OA\Property(property="campaign_id", type="integer", example=12, description="Campaign ID")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Dispositions list returned successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Dispositions detail."),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=3),
     *                     @OA\Property(property="title", type="string", example="Interested"),
     *                     @OA\Property(property="enable_sms", type="integer", example=1),
     *                     @OA\Property(property="d_type", type="string", example="Callback")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad request"
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized"
     *     )
     * )
     */

    public function dispositionByCampaignId()
    {
        $this->validate($this->request, [
            'campaign_id' => 'required|numeric'
        ]);
        $response = $this->model->dispositionByCampaignId($this->request->input('campaign_id'), $this->request->auth->parent_id);
        return response()->json($response);
    }

    /*
     * Get Lead
     * @return json
     */

    /**
     * @OA\Get(
     *     path="/get-lead",
     *     tags={"Dialer"},
     *     summary="Fetch lead for agent",
     *     description="Retrieves the next lead assigned to the authenticated agent's extension (based on dialer mode and settings).",
     *     operationId="getLead",
     *     security={{"Bearer":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Lead fetched successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Lead fetched successfully."),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 additionalProperties=true,
     *                 description="Lead data details"
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad request"
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error"
     *     )
     * )
     */

    public function getLead()
    {
        $intExtensionToBeUsed = $this->request->auth->extension;

        $intWebPhoneSetting = self::getWebPhonestatus($this->request->auth->id, $this->request->auth->parent_id);
        if ($intWebPhoneSetting == 1) {
            $intExtensionToBeUsed = $this->request->auth->alt_extension;
        }

        /* new code implement*/

        $dataUser = User::where('id', $this->request->auth->id)->get()->first();

        $dialer_mode = $dataUser->dialer_mode;

        if ($dialer_mode == 3) {
            $intExtensionToBeUsed = $dataUser->app_extension;
        } else
            if ($dialer_mode == 2) {
            $intExtensionToBeUsed = $this->request->auth->alt_extension;
        } else
            if ($dialer_mode == 1) {
            $intExtensionToBeUsed =  $this->request->auth->extension;
        }

        //echo $extension;die;

        /*close new code implement*/

        $response = $this->model->getLead($this->request->auth->parent_id, $intExtensionToBeUsed);
        return response()->json($response);
    }


    /**
     * @OA\Post(
     *     path="/update-lead/{leadId}",
     *     summary="Update lead data",
     *     description="Updates an existing lead's data by lead ID using dynamic DB connection based on parent ID.",
     *     operationId="updateLeadData",
     *     tags={"Dialer"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="leadId",
     *         in="path",
     *         required=true,
     *         description="The ID of the lead to update",
     *         @OA\Schema(type="integer", example=123)
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         description="Lead data to update",
     *         @OA\JsonContent(
     *             @OA\Property(property="first_name", type="string", example="John"),
     *             @OA\Property(property="last_name", type="string", example="Doe"),
     *             @OA\Property(property="email", type="string", example="john.doe@example.com"),
     *             @OA\Property(property="phone", type="string", example="9876543210"),
     *             @OA\Property(property="status", type="string", example="active")
     *             
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lead updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Lead data updated"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Lead not found or update failed",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Lead Not Found"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string"))
     *         )
     *     )
     * )
     */

    public function updateLeadData(Request $request, int $leadId)
    {
        try {
            $listData = ListData::on("mysql_" . $request->auth->parent_id)->findOrFail($leadId);
            $listData->update($request->all());
            $listData->saveOrFail();
            return $this->successResponse("Lead data updated", $listData->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Lead Not Found", ["Invalid lead id $leadId"], $exception, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update lead data", [$exception->getMessage()], $exception, 404);
        }
    }


    /**
     * @OA\Post(
     *     path="/view-notes/{leadId}",
     *     tags={"Dialer"},
     *     summary="Get all comments (notes) for a lead",
     *     description="Fetches comments/notes for a specific lead along with user data using extension mapping.",
     *     operationId="showNotesData",
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="leadId",   
     *         in="path",
     *         required=true,
     *         description="ID of the lead",
     *         @OA\Schema(type="integer", example=123)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Comments retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="comments List"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="extension", type="string", example="101"),
     *                     @OA\Property(property="lead_id", type="integer", example=123),
     *                     @OA\Property(property="comment", type="string", example="Follow up tomorrow"),
     *                     @OA\Property(property="created_at", type="string", format="date-time", example="2025-06-17T10:30:00Z"), 
     *                     @OA\Property(property="user_id", type="integer", example=15),
     *                     @OA\Property(property="first_name", type="string", example="John"),
     *                     @OA\Property(property="last_name", type="string", example="Paul"),
     *                     @OA\Property(property="email", type="string", example="john@example.com")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Lead not found or no comments available",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="No comments found")
     *         )
     *     )
     * )
     */

    public function showNotesData(Request $request, int $leadId)
    {

        $comments = Comments::on("mysql_" . $request->auth->parent_id)->select('comment.*', 'master.users.*')->join('master.users',  function ($join) {
            $join->on('comment.extension', '=', 'users.extension');
        })
            ->where('comment.lead_id', $leadId)->get()->all();
        //$templates = Comments::on("mysql_" . $request->auth->parent_id)->where('lead_id',$leadId)
        return $this->successResponse("comments List", $comments);
    }

    /*
     * save disposition
     * @return array
     */

    /**
     * @OA\Post(
     *     path="/save-disposition",
     *     tags={"Dialer"},
     *     summary="Save call disposition and update lead/cdr info",
     *     description="Saves the call disposition, updates related tables like cdr, lead_report, extension_live, comments, callbacks, DNC, and optionally triggers an API call and dials the next lead.",
     *     operationId="saveDisposition",
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"id", "campaign_id", "disposition_id", "lead_id", "api_call", "pause_calling"},
     *             @OA\Property(property="id", type="integer", example=10),
     *             @OA\Property(property="campaign_id", type="integer", example=123),
     *             @OA\Property(property="disposition_id", type="integer", example=5),
     *             @OA\Property(property="lead_id", type="integer", example=456),
     *             @OA\Property(property="api_call", type="integer", enum={0,1}, example=0),
     *             @OA\Property(property="pause_calling", type="integer", enum={0,1}, example=1),
     *             @OA\Property(property="comment", type="string", example="Customer not interested."),
     *             @OA\Property(property="callback_comment", type="string", example="Call back in the evening."),
     *             @OA\Property(property="call_back", type="string", format="date-time", example="2025-06-17 18:30:00"),
     *             @OA\Property(property="full_name", type="string", example="John Doe")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Disposition saved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Call disposed"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 additionalProperties=true
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error"
     *     )
     * )
     */

    public function saveDisposition()
    {
        $this->validate($this->request, [
            'campaign_id' => 'required|numeric',
            'disposition_id' => 'required|numeric',
            'lead_id' => 'required|numeric',
            'api_call' => 'required|numeric',
            'comment' => 'string',
            //'comment_callback' => 'string',
            'pause_calling' => 'required|numeric',
            //'call_back' => 'date_format:Y-m-d H:i:s'
        ]);
        try {

            //return $this->request;
            $response = $this->model->saveDisposition($this->request);
            return $this->successResponse("Call disposed", $response);
        } catch (\Throwable $exception) {
            return $this->failResponse($exception->getMessage(), [], $exception);
        }
    }

    /**
     * @OA\Post(
     *     path="/redial-call",
     *     tags={"Dialer"},
     *     summary="Redial and dispose a call",
     *     description="Handles redial functionality by saving disposition, updating extension, CDR, lead report, comments, callback, and optionally making an API call and scheduling next lead.",
     *     operationId="redialCall",
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"id", "campaign_id", "disposition_id", "lead_id", "api_call", "pause_calling", "call_back"},
     *             @OA\Property(property="id", type="integer", example=101),
     *             @OA\Property(property="campaign_id", type="integer", example=201),
     *             @OA\Property(property="disposition_id", type="integer", example=5),
     *             @OA\Property(property="lead_id", type="integer", example=501),
     *             @OA\Property(property="api_call", type="integer", enum={0,1}, example=1),
     *             @OA\Property(property="pause_calling", type="integer", enum={0,1}, example=0),
     *             @OA\Property(property="call_back", type="string", format="date-time", example="2025-06-17 15:00:00"),
     *             @OA\Property(property="comment", type="string", example="Customer requested a callback"),
     *             @OA\Property(property="callback_comment", type="string", example="Follow-up on interest"),
     *             @OA\Property(property="full_name", type="string", example="Jane Doe"),
     *             @OA\Property(property="listId", type="integer", example=7)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Call disposed and redial process completed",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Call disposed"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 additionalProperties=true
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation failed"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error"
     *     )
     * )
     */

    public function redialCall()
    {
        $this->validate($this->request, [
            'id' => 'required|numeric',
            'campaign_id' => 'required|numeric',
            'disposition_id' => 'required|numeric',
            'lead_id' => 'required|numeric',
            'api_call' => 'required|numeric',
            'comment' => 'string',
            'pause_calling' => 'required|numeric',
            'call_back' => 'date_format:Y-m-d H:i:s'
        ]);
        try {
            $response = $this->model->redialCall($this->request);
            return $this->successResponse("Call disposed", $response);
        } catch (\Throwable $exception) {
            return $this->failResponse($exception->getMessage(), [], $exception);
        }
    }

    /*
     * dtmf
     * @return array
     */

    /**
     * @OA\Post(
     *     path="/dtmf",
     *     tags={"Dialer"},
     *     summary="Send DTMF tone",
     *     description="Sends DTMF digits during an ongoing call for the authenticated agent's extension.",
     *     operationId="dtmf",
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"id", "number"},
     *             @OA\Property(property="id", type="integer", example=1, description="Arbitrary identifier (may be user or session ID)"),
     *             @OA\Property(property="number", type="string", example="1234", description="DTMF digits to send (can be a sequence)")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="DTMF tone result",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="DTMF dialed successfully")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad request or validation error"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error"
     *     )
     * )
     */

    public function dtmf()
    {
        $this->validate($this->request, [
            'id' => 'required|numeric',
            'number' => 'required|numeric',
        ]);
        $response = $this->model->dtmf($this->request);
        return response()->json($response);
    }

    /*
     * voice mail drop
     * @return array
     */

    /**
     * @OA\Post(
     *     path="/voicemail-drop",
     *     tags={"Dialer"},
     *     summary="Drop a voicemail",
     *     description="Triggers the voicemail drop functionality for the currently authenticated user's extension. The extension is determined based on the dialer mode and webphone setting.",
     *     operationId="voicemailDrop",
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"id"},
     *             @OA\Property(property="id", type="integer", example=101, description="User ID")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Voicemail drop result",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Voice mail dropped")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation failed"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error"
     *     )
     * )
     */

    public function voicemailDrop()
    {

        $this->validate($this->request, [
            'id' => 'required|numeric'
        ]);
        $response = $this->model->voicemailDrop($this->request);
        return response()->json($response);
    }

    /**
     * @OA\Post(
     *     path="/send-to-crm",
     *     tags={"Dialer"},
     *     summary="Send lead data to CRM",
     *     description="Sends lead data to external CRM via GET or POST APIs depending on the campaign configuration. Logs the request in CRM logs table.",
     *     operationId="sendToCrm",
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"campaign_id", "lead_id"},
     *             @OA\Property(property="campaign_id", type="integer", example=10, description="Campaign ID"),
     *             @OA\Property(property="lead_id", type="integer", example=202, description="Lead ID"),
     *             @OA\Property(property="number", type="string", example="9876543210", description="Phone number (optional)")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="CRM URLs returned successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Send to crm"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(
     *                     property="url",
     *                     type="array",
     *                     @OA\Items(type="string", example="https://crm.example.com?param=value")
     *                 ),
     *                 @OA\Property(
     *                     property="main_url",
     *                     type="array",
     *                     @OA\Items(type="string", example="https://crm.example.com")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid lead input or configuration"
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error"
     *     )
     * )
     */

    public function sendToCrm()
    {
        $this->validate($this->request, [
            // 'id'            => 'required|numeric',
            'number' => 'numeric',
            'campaign_id' => 'required|numeric',
            'lead_id' => 'required|numeric'
        ]);

        $intExtensionToBeUsed = $this->request->auth->extension;
        $intWebPhoneSetting = self::getWebPhonestatus($this->request->auth->id, $this->request->auth->parent_id);
        if ($intWebPhoneSetting == 1) {
            $intExtensionToBeUsed = $this->request->auth->alt_extension;
        }

        /* new code implement*/

        $dataUser = User::where('id', $this->request->auth->id)->get()->first();

        $dialer_mode = $dataUser->dialer_mode;

        if ($dialer_mode == 3) {
            $intExtensionToBeUsed = $dataUser->app_extension;
        } else
            if ($dialer_mode == 2) {
            $intExtensionToBeUsed = $this->request->auth->alt_extension;
        } else
            if ($dialer_mode == 1) {
            $intExtensionToBeUsed =  $this->request->auth->extension;
        }

        //echo $extension;die;

        /*close new code implement*/

        $adminId = $this->request->auth->parent_id;
        $db = "mysql_" . $this->request->auth->parent_id;
        $campaignId = $this->request->input('campaign_id');
        $leadId = $this->request->input('lead_id');
        $dispositionId = 0;
        $number = !empty($this->request->input('number')) ? $this->request->input('number') : '';
        try {
            $response = $this->model->apiData($intExtensionToBeUsed, $adminId, $db, $campaignId, $leadId, $dispositionId, '');
            /*if ($response) {
                $client_url = $response['url'] . "?" . http_build_query($response['data']);
                $crm_data = json_encode($response['data']);
                //$saveData = ['lead_id' => $leadId , 'type' => '0' , 'campaign_id' => $campaignId , 'url'=> $client_url , 'crm_data'=> $crm_data , 'phone'=> $number ];
                CrmLog::on($db)->create(array('lead_id' => $leadId, 'type' => '0', 'campaign_id' => $campaignId, 'url' => $client_url, 'crm_data' => $crm_data, 'phone' => $number));
                // $saveRecord->save();

                return $this->successResponse("Send to crm", array('url' => $client_url));
            }*/

            if ($response) {
                $count  = count($response['url']);
                $client_url = array();
                $url = array();
                $method = array();




                for ($i = 0; $i < $count; $i++) {
                    $method[$i] = $response['method'][$i];
                    $api_id = $response['param'];


                    if ($method[$i] === 'get') {
                        $client_url[$i] = $response['url'][$i] . "?" . http_build_query($response['data'][$i]);
                        $crm_data = json_encode($response['data']);
                        CrmLog::on($db)->create(array('lead_id' => $leadId, 'type' => '0', 'campaign_id' => $campaignId, 'url' => $client_url[$i], 'crm_data' => $crm_data, 'phone' => $number));
                    } else
                        if ($method[$i] == 'post') {
                        unset($response['data'][$i]['lead_source'], $response['data'][$i]['campaign'], $response['data'][$i]['phone'], $response['data'][$i]['SQLdate'], $response['data'][$i]['campaign'], $response['data'][$i]['server_ip'], $response['data'][$i]['vendor_id'], $response['data'][$i]['leadId'], $response['data'][$i]['user'], $response['data'][$i]['vendor_id'], $response['data'][$i]['vendor_id']);

                        $client_url[$i] = '/send-to-crm-post' . '?api_id=' . $api_id . '&url=' . $response['url'][$i] . "&" . http_build_query($response['data'][$i]);
                        $crm_data = json_encode($response['data']);
                        CrmLog::on($db)->create(array('lead_id' => $leadId, 'type' => '0', 'campaign_id' => $campaignId, 'url' => $client_url[$i], 'crm_data' => $crm_data, 'phone' => $number));
                    }

                    $url[$i] = $response['url'][$i];
                }
                return $this->successResponse("Send to crm", array('url' => $client_url, 'main_url' => $url));
            } else {

                return $this->failResponse('Invalid lead ip', [], []);
            }
        } catch (\Throwable $exception) {
            return $this->failResponse($exception->getMessage(), [], $exception);
        }
    }

    /**
     * @OA\Post(
     *     path="/send-to-crm-post",
     *     tags={"Dialer"},
     *     summary="Send lead data to external CRM (POST method)",
     *     description="Sends lead data to an external CRM using POST. Retrieves API parameters for the given `api_id` and maps them to lead data.",
     *     operationId="sendToCrmPost",
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"api_id"},
     *             @OA\Property(property="api_id", type="integer", example=101, description="API ID for CRM configuration"),
     *             @OA\Property(property="lead_id", type="integer", example=202, description="Lead ID (optional)"),
     *             @OA\Property(property="campaign_id", type="integer", example=10, description="Campaign ID (optional)")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="CRM POST request prepared and processed successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="CRM POST executed successfully"),
     *             @OA\Property(property="data", type="object", example={"label_id": 123, "label_title": "Lead Name"})
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid API configuration or missing parameters"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error"
     *     )
     * )
     */

    public function sendToCrmPost(Request $request)
    {
        //return $request->all();
        $sql = "SELECT type, parameter, value FROM api_parameter  WHERE api_id = :api_id AND is_deleted = :is_deleted";
        $record =  DB::connection('mysql_' . $request->auth->parent_id)->select($sql, array('api_id' => $request[0]['api_id'], 'is_deleted' => 0));
        $data['parameter'] = (array)$record;

        foreach ($data['parameter'] as $ddd) {
            $sql = "SELECT id, title FROM label where id='" . $ddd->value . "'"; //get all labels
            return $labels = DB::connection('mysql_' . $request->auth->parent_id)->select($sql);
        }
    }
    /**
     * @OA\Post(
     *     path="/user-logout",
     *     tags={"Dialer"},
     *     summary="Logout agent from dialer",
     *     description="Logs out the agent from the dialer system (Asterisk). Optionally logs out from webphone if `logout_all` is set.",
     *     operationId="logoutDialer",
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="logout_all", type="integer", example=1, description="Set to 1 to logout from webphone as well")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Logout response",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Logged out successfully")
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error"
     *     )
     * )
     */

    public function logout()
    {
        $response = $this->model->logout($this->request);
        return response()->json($response);
    }

    /*
     * listen Call
     * @return json
     */

    /**
     * @OA\Post(
     *     path="/listen-call",
     *     tags={"Dialer"},
     *     summary="Start listening to an active call",
     *     description="Initiates the listen feature on a call using the provided listen_id and extension validation.",
     *     operationId="listenCall",
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"listen_id"},
     *             @OA\Property(property="listen_id", type="integer", example=123),
     *             @OA\Property(property="extension", type="string", example="101")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Call listen attempt result",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Listen activity started")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Missing or invalid input",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Please enable the webphone first")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error while processing the request",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Unable to hang up the phone.")
     *         )
     *     )
     * )
     */

    public function listenCall()
    {
        $this->validate($this->request, [
            'listen_id' => 'required|numeric'
        ]);
        $response = $this->model->listenCall($this->request);
        return response()->json($response);
    }

    /*
     * Barge Call
     * @return json
     */

    /**
     * @OA\Post(
     *     path="/barge-call",
     *     tags={"Dialer"},
     *     summary="Barge into a live call",
     *     description="Enables the supervisor to barge into an active call using the provided listen ID and extension logic.",
     *     operationId="bargeCall",
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"listen_id"},
     *             @OA\Property(property="listen_id", type="integer", example=123),
     *             @OA\Property(property="extension", type="string", example="101", description="Your extension, must match or pass webphone check")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Barge call response",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Barge Call activity started")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Missing or invalid input",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Please enable the webphone first")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error while processing barge call",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Unable to bargeCall the phone.")
     *         )
     *     )
     * )
     */

    public function bargeCall()
    {
        $this->validate($this->request, [
            'listen_id' => 'required|numeric'
        ]);
        $response = $this->model->bargeCall($this->request);
        return response()->json($response);
    }

    /**
     * @OA\Post(
     *     path="/add-new-lead-pd",
     *     tags={"Dialer"},
     *     summary="Create a new lead for Power Dialer",
     *     description="Replicates an existing lead, updates specific dialer columns, and returns success/failure message.",
     *     operationId="addNewLeadPd",
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"list_id", "lead_id"},
     *             @OA\Property(property="list_id", type="integer", example=12),
     *             @OA\Property(property="lead_id", type="integer", example=345),
     *             @OA\Property(property="nxt_call", type="string", example="2025-06-17 15:00:00", description="Next call time (optional but commonly used)")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lead creation result",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Created new lead")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Validation failed",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="The list_id field is required.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error while creating lead",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Unable to create new lead. Column not found."),
     *             @OA\Property(property="data", type="array", @OA\Items())
     *         )
     *     )
     * )
     */

    public function addNewLeadPd()
    {
        $this->validate($this->request, [
            'list_id' => 'required|numeric',
            'lead_id' => 'required|numeric'
        ]);
        $response = $this->model->addNewLeadPd($this->request);
        return response()->json($response);
    }


    /**
     * @OA\Post(
     *     path="/webphone/switch-access",
     *     tags={"Dialer"},
     *     summary="Enable or disable the webphone for the authenticated user",
     *     description="Updates the webphone setting for the user. If disabled, it also logs out the current session.",
     *     operationId="switchWebPhoneUse",
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"is_checked"},
     *             @OA\Property(
     *                 property="is_checked",
     *                 type="string",
     *                 enum={"true", "false"},
     *                 description="Set to 'true' to enable webphone, 'false' to disable",
     *                 example="true"
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Webphone preference updated",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Webphone Enabled")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="The is_checked field is required.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Unexpected error occurred while updating webphone setting",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to update webphone preferences")
     *         )
     *     )
     * )
     */

    public function switchWebPhoneUse()
    {
        $this->validate($this->request, [
            'is_checked' => 'required|string'
        ]);

        try {
            $strActionPerformed = "Disabled";
            if ($this->request->is_checked === "true") {
                $strActionPerformed = "Enabled";
                User::where('id', $this->request->auth->id)->update(['webphone' => true]);
                Cache::put("user.webphone.{$this->request->auth->id}.{$this->request->auth->parent_id}", 1);
            } else {
                $response = $this->model->logout($this->request);
                if ($response) {
                    User::where('id', $this->request->auth->id)->update(['webphone' => false]);
                    Cache::put("user.webphone.{$this->request->auth->id}.{$this->request->auth->parent_id}", 0);
                }
            }
            return $this->successResponse("Webphone " . $strActionPerformed, []);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update webphone preferences", [], $exception);
        }
    }

    /**
     * @OA\Get(
     *     path="/webphone/status",
     *     tags={"Dialer"},
     *     summary="Get current Webphone status for the authenticated user",
     *     description="Returns whether the webphone is enabled (1) or disabled (0) for the authenticated user.",
     *     operationId="webPhoneStatus",
     *     security={{"Bearer":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Webphone status retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Webphone setting retrieved successfully."),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(type="integer", example=1)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Unexpected error while fetching webphone status",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to retrieve Webphone preferences")
     *         )
     *     )
     * )
     */

    public function webPhoneStatus()
    {
        try {
            $strWebPhonestatus = self::getWebPhonestatus($this->request->auth->id, $this->request->auth->parent_id);
            return $this->successResponse("Webphone setting retrieved successfully.", [$strWebPhonestatus]);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to retrieve Webphone preferences", [], $exception);
        }
    }

    public static function getWebPhonestatus($intUserId, $intParentId)
    {
        if (Cache::has("user.webphone.{$intUserId}.{$intParentId}")) {
            return Cache::get("user.webphone.{$intUserId}.{$intParentId}");
        } else {
            $response = DB::select("SELECT webphone FROM users where id= :id", [$intUserId]);
            return $response[0]->webphone;
        }
    }

    /**
     * @OA\Post(
     *     path="/direct-call-transfer",
     *     summary="Perform a direct call transfer",
     *     tags={"Dialer"},
     *     operationId="directCallTransfer",
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"call_transfer_type", "call_transfer_from", "call_transfer_to"},
     *             @OA\Property(property="call_transfer_type", type="integer", example=1, description="Transfer type (e.g., blind, attended)"),
     *             @OA\Property(property="call_transfer_from", type="string", example="1001", description="Extension initiating the transfer"),
     *             @OA\Property(property="call_transfer_to", type="string", example="1002", description="Extension to transfer the call to")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Call transfer success response",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Direct Call Transfer run successfully")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Call transfer failure",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Call transfer failed"),
     *             @OA\Property(property="error", type="string", example="Error message here")
     *         )
     *     )
     * )
     */

    public function directCallTransfer()
    {
        $this->validate($this->request, [
            'call_transfer_type' => 'required|numeric',
            'call_transfer_from' => 'required',
            'call_transfer_to' => 'required'
        ]);


        return array(
            'success' => true,
            'message' => 'Direct Call Transfer run successfully'
        );

        $response = $this->model->directCallTransfer($this->request, $this->request->auth->parent_id);
        return response()->json($response);
    }



    /**
     * @OA\Post(
     *     path="/warm-call-transfer-c2c-crm",
     *     summary="Initiate a warm call transfer (extension, ring group, or DID)",
     *     tags={"Dialer"},
     *     operationId="warmCallTransfer",
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"lead_id", "customer_phone_number", "warm_call_transfer_type"},
     *             @OA\Property(property="lead_id", type="integer", example=101, description="Lead ID involved in the call transfer"),
     *             @OA\Property(property="customer_phone_number", type="string", example="9876543210", description="Customer's phone number"),
     *             @OA\Property(property="warm_call_transfer_type", type="string", example="extension", description="Transfer type: extension, ring_group, or did"),
     *             @OA\Property(property="forward_extension", type="string", example="1002", description="Extension to transfer the call to (if type is extension)"),
     *             @OA\Property(property="ring_group", type="string", example="support-group", description="Ring group to transfer the call to (if type is ring_group)"),
     *             @OA\Property(property="did_number", type="string", example="18005551234", description="DID number to transfer the call to (if type is did)"),
     *             @OA\Property(property="domain", type="string", example="domain.com", description="Domain name for the transfer context")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Transfer initiated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Warm Call Transfer C2C initiating on Extension is successful")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Transfer failed",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Unable to Run Warm Call Transfer C2C"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     )
     * )
     */


    public function warmCallTransfer(Request $request)
    {



        $this->validate($this->request, [
            'lead_id' => 'required|numeric',
            'forward_extension' => '',
            'ring_group' => '',
            'did_number' => '',
            'customer_phone_number' => 'required',
            'warm_call_transfer_type' => 'required'
        ]);

        if ($request->warm_call_transfer_type == 'did') {
            $response = $this->model->warmCallTransferDid($this->request, $this->request->auth->parent_id);
        } else {
            $response = $this->model->warmCallTransfer($this->request, $this->request->auth->parent_id);
        }


        return response()->json($response);
    }


    /**
     * @OA\Post(
     *     path="/click2call",
     *     summary="Initiate an AI-powered outbound call",
     *     description="Triggers an outbound AI call using Asterisk AMI. CLI is selected based on campaign settings and area code logic.",
     *     operationId="outboundAIDial",
     *     tags={"Dialer"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"number", "campaign_id", "lead_id", "clientId", "redirect_to", "file_name"},
     *             @OA\Property(property="number", type="string", example="9876543210", description="Customer mobile number"),
     *             @OA\Property(property="campaign_id", type="integer", example=101, description="Campaign ID"),
     *             @OA\Property(property="lead_id", type="integer", example=2001, description="Lead ID"),
     *             @OA\Property(property="clientId", type="integer", example=3, description="Client ID"),
     *             @OA\Property(property="redirect_to", type="string", example="ivr", description="Call redirect destination type (e.g., ivr, audio, agent)"),
     *             @OA\Property(property="redirect_to_dropdown", type="string", example="main_menu", description="Redirect target ID or name"),
     *             @OA\Property(property="file_name", type="string", example="welcome_message", description="Audio file or IVR to play"),
     *             @OA\Property(property="amd_drop_action", type="string", example="hangup", description="Action to take if AMD detects voicemail"),
     *             @OA\Property(property="amd_drop_message_output", type="string", example="voicemail_detected", description="Message or flag output if AMD triggers")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Call initiated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Call initiated successfully"),
     *             @OA\Property(property="data", type="object", example={"cli": "1234567890", "lead_id": 2001})
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid request or missing parameters",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Error: Invalid parameters"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error or AMI connection failure",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to initiate outbound call"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string"))
     *         )
     *     )
     * )
     */

    public function outboundAIDial(Request $request) //mobile,$campaignId, $leadId, $clientId,$redirect_to,$file_name
    {
        $this->admin = 3;
        $this->extension = '1001';
        //echo "<pre>";print_r($request);die;
        $mobile = preg_replace('/[^0-9]/', '', $request['number']);
        $numberAreacode = substr(trim($mobile), 0, 3);
        $area_code = $numberAreacode;

        $sqlCli = "SELECT caller_id,custom_caller_id,amd FROM campaign WHERE id = :id";
        $sqlCliStatus = DB::connection('mysql_' . $this->admin)->selectOne($sqlCli, array('id' => $request['campaign_id']));

        if ($sqlCliStatus->caller_id == 'custom') {
            $cli = $sqlCliStatus->custom_caller_id;
        } else
        if ($sqlCliStatus->caller_id == 'area_code') {
            $sqlCliDid = "SELECT cli from did where area_code =:area_code and set_exclusive_for_user='0' and is_deleted='0' ORDER BY RAND() limit 0,1";
            $sqlCliDidStatus = DB::connection('mysql_' . $this->admin)->selectOne($sqlCliDid, array('area_code' => $numberAreacode));

            if (!empty($sqlCliDidStatus)) {
                $cli = $sqlCliDidStatus->cli;
            } else {
                $areacode = AreaCodeList::where('areacode', $numberAreacode)->get()->first();
                //echo "<pre>";print_r($areacode);die;
                if (!empty($areacode)) {
                    $statecode = $areacode->state_code;
                    $all_areacode = AreaCodeList::where('state_code', $statecode)->get()->all();
                    //echo "<pre>";print_r($all_areacode);die;

                    foreach ($all_areacode as $state) {
                        $code_area[] = $state->areacode;
                    }

                    //echo "<pre>";print_r($code_area);die;

                    $array_to_remove = array($area_code);
                    $final_array = array_diff($code_area, $array_to_remove);
                    $area_codes = implode(',', $final_array);

                    //echo "<pre>";print_r($area_codes);die;

                    $sql_area_code_new = "SELECT cli from did where area_code IN ($area_codes) and set_exclusive_for_user= :set_exclusive_for_user and is_deleted='0' ORDER BY RAND() limit 0,1";

                    $area_code_details = DB::connection('mysql_' . $this->admin)->selectOne($sql_area_code_new, array('set_exclusive_for_user' => '0'));
                    //echo "<pre>";print_r($area_code_details);die;

                    if (!empty($area_code_details)) {
                        $cli = $area_code_details->cli;
                    } else {
                        $sql_area_code_default_did = "SELECT cli from did where  set_exclusive_for_user=:set_exclusive_for_user  and is_deleted='0' ORDER BY RAND() limit 0,1";
                        $area_code_default_did_details = DB::connection('mysql_' . $this->admin)->selectOne($sql_area_code_default_did, array('set_exclusive_for_user' => '0'));
                        $cli = $area_code_default_did_details->cli;
                    }
                } else {
                    $sql_area_code_default_did = "SELECT cli from did where  set_exclusive_for_user=:set_exclusive_for_user  and is_deleted='0' ORDER BY RAND() limit 0,1";
                    $area_code_default_did_details = DB::connection('mysql_' . $this->admin)->selectOne($sql_area_code_default_did, array('set_exclusive_for_user' => '0'));
                    $cli = $area_code_default_did_details->cli;
                }
                /*else
                {
                    $sql_area_code_default_did = "SELECT cli from did where  set_exclusive_for_user=:set_exclusive_for_user  and is_deleted='0' ORDER BY RAND() limit 0,1";
                    $area_code_default_did_details =DB::connection('mysql_'.$this->admin)->selectOne($sql_area_code_default_did, array('set_exclusive_for_user' => '0'));
                    $cli = $area_code_default_did_details->cli;
                }*/
            }
        }
        /*if($sqlCliStatus->caller_id == 'area_code')
        {
            $sqlCliDid = "SELECT cli from did where area_code =:area_code and set_exclusive_for_user='0' and is_deleted='0' ORDER BY RAND() limit 0,1";
            $sqlCliDidStatus =DB::connection('mysql_'.$this->admin)->selectOne($sqlCliDid, array('area_code' => $numberAreacode));
            
            if(!empty($sqlCliDidStatus))
            {
                $cli = $sqlCliDidStatus->cli;
            }
            else
            {
                $sqlCliDid = "SELECT cli from did where default_did = :default_did and set_exclusive_for_user='0' and is_deleted='0' ORDER BY RAND() limit 0,1";
                $sqlCliDidStatus =DB::connection('mysql_'.$this->admin)->selectOne($sqlCliDid, array('default_did' => 0));
                if(!empty($sqlCliDidStatus))
                {
                    $cli = $sqlCliDidStatus->cli;
                }
            }
        }*/ else
        if ($sqlCliStatus->caller_id == 'area_code_random') {
            $sqlCliDid = "SELECT cli from did where area_code =:area_code and set_exclusive_for_user='0' and is_deleted='0' ORDER BY RAND() limit 0,1";
            $sqlCliDidStatus = DB::connection('mysql_' . $this->admin)->selectOne($sqlCliDid, array('area_code' => $numberAreacode));

            if (!empty($sqlCliDidStatus)) {
                $cli = $sqlCliDidStatus->cli;
            } else {
                $sqlCliDid = "SELECT cli from did where  set_exclusive_for_user='0' and is_deleted='0' ORDER BY RAND() limit 0,1";
                $sqlCliDidStatus = DB::connection('mysql_' . $this->admin)->selectOne($sqlCliDid, array('default_did' => 1));
                if (!empty($sqlCliDidStatus)) {
                    $cli = $sqlCliDidStatus->cli;
                }
            }
        }

        //tech prefix

        $client_details = Client::findOrFail($this->admin);
        if (!empty($client_details)) {
            if (!empty($client_details->tech_prefix)) {
                $tech_prefix = $client_details->tech_prefix;
            } else {
                $tech_prefix = '';
            }
        }

        //closed tech prefix



        if ($sqlCliStatus->amd == 1) {
            $amd_on = $sqlCliStatus->amd;
        } else {
            $amd_on = '';
        }

        $sql = "SELECT extension FROM extension_live WHERE extension = :extension and status = :status";
        // $agentLoginStatus =DB::connection('mysql_'.$this->admin)->selectOne($sql, array('extension' => $this->extension, 'status' => 0));

        $agentLoginStatus = 1; //DB::connection('mysql_'.$this->admin)->selectOne($sql, array('extension' => $this->extension, 'status' => 0));

        if ($agentLoginStatus == 1) {
            if ($mobile != '' && $this->extension != '') {
                if (app()->environment() == "local") return true;

                //$callerId = "19499914823";
                $callerId   = $cli;
                $destType   = $request['redirect_to'];
                $destId     = $request['redirect_to_dropdown'];
                $dest       = $request['file_name'];
                $leadId     = $request['lead_id'];
                $campaignId = $request['campaign_id'];
                $clientId   = $request['clientId'];
                $amd_drop_action = $request['amd_drop_action'];
                $amd_drop_message_output = $request['amd_drop_message_output'];


                if ($amd_on == 1) {
                    $extenStr = $mobile . "-" . $campaignId . "-" . $leadId . "-" . $clientId . "-" . $destType . "-" . $destId . "-" . $dest . "-" . $amd_drop_action . "-" . $callerId;
                } else {
                    $extenStr = $mobile . "-" . $campaignId . "-" . $leadId . "-" . $clientId . "-" . $destType . "-" . $destId . "-" . $callerId;
                }

                //echo $extenStr;die;
                $originateRequest = "Action: originate\r\n";
                //  $originateRequest .= "Channel: SIP/telnyx/#13519621$mobile\r\n"; //airespring/#13517131  // for v g  Channel: SIP/Airespring1/1$mobile\r\n

                $originateRequest .= "Channel: SIP/telnyx/$tech_prefix$mobile\r\n";

                $originateRequest .= "Timeout: $this->waitTime\r\n";
                $originateRequest .= "Callerid: $callerId\r\n";
                $originateRequest .= "Exten: $extenStr\r\n";
                if ($amd_on == 1) {
                    $originateRequest .= "Context: dialler-room-outbund-ai-amd\r\n";
                } else {
                    $originateRequest .= "Context: dialler-room-outbund-ai\r\n";
                }
                $originateRequest .= "Variable: var1=$extenStr\r\n";
                $originateRequest .= "Priority: 1\r\n";
                $originateRequest .= "Async: yes\r\n";
                $originateRequest .= "Action: Logoff\r\n\r\n";

                // Send originate request
                $param['action'] = 'outbound_ai';
                $param['campaign_id'] = $campaignId;
                $param['mobile'] = $mobile;
                $param['lead_id'] = $leadId;
                $param['cli'] = $cli;
                $param['amd_status'] = $sqlCliStatus->amd;
                $param['area_code'] = $numberAreacode;



                $response = $this->amiCommand($originateRequest, $param);
                if ($response == "true") {
                    Log::error("Dialer.outboundAIDial.success", [
                        "message" => $response,
                        "originateRequest" => $originateRequest,
                        "param" => $param
                    ]);

                    return true;
                } else {
                    Log::error("Dialer.outboundAIDial.error", [
                        "message" => $response,
                        "originateRequest" => $originateRequest,
                        "param" => $param
                    ]);
                }
            }
            /*else
            {
                // $this->database->deleteData('lead_report' , array('campaign_id' => $campaignId, 'lead_id' => $leadId));
                return false;
            }*/
        } else {
            echo json_encode(array('status' => 'fail', 'msg' => "Error : Your are not logged in from extension for Outbound AI dial : $this->extension"));
        }
    }


    /**
     * @OA\Post(
     *     path="/check-line-details",
     *     summary="Check line details of a lead in a campaign",
     *     tags={"Dialer"},
     *     operationId="checkLineDetails",
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"lead_id", "alt_extension", "customer_phone_number"},
     *             @OA\Property(property="lead_id", type="integer", example=123, description="ID of the lead"),
     *             @OA\Property(property="campaign_id", type="integer", example=45, description="Campaign ID (must be included in request even if not validated)"),
     *             @OA\Property(property="alt_extension", type="string", example="1002", description="Agent's alternative extension"),
     *             @OA\Property(property="customer_phone_number", type="string", example="9876543210", description="Phone number of the customer")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Line detail status response",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="line detail data is found"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="No line details found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Call is Already Hung Up")
     *         )
     *     )
     * )
     */

    public function checkLineDetails(Request $request)
    {
        $this->validate($this->request, [
            'lead_id' => 'required|numeric',
            'alt_extension' => 'required',
            'customer_phone_number' => 'required'
        ]);


        $line_details = LineDetail::on("mysql_" . $request->auth->parent_id)->where('lead_id', $request->lead_id)->where('campaign_id', $request->campaign_id)->where('number', $request->customer_phone_number)->where('extension', $request->alt_extension)->get()->first();


        if ($line_details) {
            $response =  array(
                'success' => true,
                'message' => 'line detail data is found',
                'data' => $line_details
            );
        } else {
            $response =  array(
                'success' => false,
                'message' => 'Call is Already Hung Up',
                'data' => $line_details
            );
        }
        return response()->json($response);
    }

    /**
     * @OA\Post(
     *     path="/check-extension-live-for-transfer",
     *     summary="Check live extension status and transfer call if needed",
     *     tags={"Dialer"},
     *     operationId="checkExtensionLiveDetails",
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"forward_extension", "domain"},
     *             @OA\Property(property="forward_extension", type="string", example="1002", description="Extension to transfer the call to"),
     *             @OA\Property(property="domain", type="string", example="crm", description="Domain type, e.g., 'crm' or any other system"),
     *             @OA\Property(property="campaign_id", type="integer", example=55, description="Campaign ID (optional if domain is 'crm')")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Extension live status and redirection outcome",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Please click to connect button for merge call with customer"),
     *             @OA\Property(property="data", type="object", description="Live extension details if available")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="No extension live details found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Extension Live detail Not Found"),
     *             @OA\Property(property="data", type="object", nullable=true)
     *         )
     *     )
     * )
     */


    public function checkExtensionLiveDetails(Request $request)
    {
        $this->validate($this->request, [
            // 'campaign_id' => 'required|numeric',
            'forward_extension' => 'required',
            'domain' => 'required'

        ]);


        if ($request->domain == 'crm') {
            $campaign_id = 45;
        } else {
            $campaign_id = $request->campaign_id;
        }


        $live_details = ExtensionLive::on("mysql_" . $request->auth->parent_id)->where('campaign_id', $campaign_id)->where('extension', $request->forward_extension)->where('transfer_status', 1)->get()->first();

        if ($live_details) {

            $channel_live_details = ExtensionLive::on("mysql_" . $request->auth->parent_id)->where('campaign_id', $campaign_id)->where('extension', $request->auth->alt_extension)->get()->first();

            $response = $this->model->channelRedirect($this->request, $channel_live_details['channel'], $request->forward_extension);


            $sql = "UPDATE extension_live set transfer_status = :transfer_status WHERE campaign_id = :campaign_id and extension =:extension";
            DB::connection('mysql_' . $request->auth->parent_id)->update($sql, array('transfer_status' => 2, 'campaign_id' => $campaign_id, 'extension' => $request->forward_extension));
            $response =  array(
                'success' => true,
                'message' => 'Please click to connect button for merge call with customer',
                'data' => $live_details
            );
        } else {
            $response =  array(
                'success' => false,
                'message' => 'Extension Live detail Not Found',
                'data' => $live_details
            );
        }

        return response()->json($response);


        /*$channel_live_details = ExtensionLive::on("mysql_" . $request->auth->parent_id)->where('campaign_id', $request->campaign_id)->where('extension',$request->auth->alt_extension)->get()->first();

        $response = $this->model->channelRedirect($this->request,$channel_live_details['channel'],$request->forward_extension);


        if($live_details) {
            $response =  array(
                'success' => true,
                'message' => 'Please click to connect button for conferencing',
                'data' => $live_details
            );
        } else {
            $response =  array(
                'success' => false,
                'message' => 'Extension Live detail Not Found',
                'data' => $live_details
            );
        }
        return response()->json($response);*/
    }

    /**
     * @OA\Post(
     *     path="/merge-call-with-transfer",
     *     summary="Merge an ongoing call with another extension using warm transfer",
     *     tags={"Dialer"},
     *     operationId="mergeCallWithTransfer",
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"lead_id", "customer_phone_number", "warm_call_transfer_type", "domain"},
     *             @OA\Property(property="lead_id", type="integer", example=101, description="Lead ID of the customer"),
     *             @OA\Property(property="forward_extension", type="string", example="1002", description="Extension to transfer call to (optional depending on transfer type)"),
     *             @OA\Property(property="customer_phone_number", type="string", example="9876543210", description="Customer's phone number"),
     *             @OA\Property(property="warm_call_transfer_type", type="string", example="extension", description="Type of warm call transfer"),
     *             @OA\Property(property="domain", type="string", example="domain.com", description="Client domain or tenant domain name")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Call successfully merged",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Call Merge with Customer Phone Number")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to merge call",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Unable to Call Merge with Customer Phone Number")
     *         )
     *     )
     * )
     */

    public function mergeCallWithTransfer(Request $request)
    {



        $this->validate($this->request, [
            'lead_id' => 'required|numeric',
            'forward_extension' => '',
            'customer_phone_number' => 'required',
            'warm_call_transfer_type' => 'required',
            'domain' => 'required'

        ]);


        $local_channel_details = LocalChannel::on("mysql_" . $request->auth->parent_id)->where('confno', $request->auth->alt_extension)->get()->first();

        //echo "<pre>";print_r($local_channel_details);die;

        if ($local_channel_details) {
            $response = $this->model->mergeCallWithTransfer($this->request, $local_channel_details['local_channel']);
        } else {
            $response =  array(
                'success' => false,
                'message' => 'Unable to Merge Customer Call',
                'data' => $local_channel_details
            );
        }


        return response()->json($response);
    }

    /**
     * @OA\Post(
     *     path="/leave-conference-transfer",
     *     summary="Leave the conference call and transfer control",
     *     tags={"Dialer"},
     *     operationId="leaveConferenceTransfer",
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"lead_id", "customer_phone_number", "domain"},
     *             @OA\Property(property="lead_id", type="integer", example=1234, description="Lead ID associated with the call"),
     *             @OA\Property(property="forward_extension", type="string", example="1002", description="(Optional) Extension for forwarding"),
     *             @OA\Property(property="customer_phone_number", type="string", example="9182736450", description="Customer's phone number"),
     *             @OA\Property(property="domain", type="string", example="crm", description="Source domain like 'crm' or 'dialer'"),
     *             @OA\Property(property="campaign_id", type="integer", example=45, description="Campaign ID (only required if domain is not 'crm')")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Conference transfer result",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Successfully left the conference call"),
     *             @OA\Property(property="data", type="object", nullable=true, description="Additional transfer info if available")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Channel not found or transfer failed",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Unable to leave Conference"),
     *             @OA\Property(property="data", type="object", nullable=true)
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error"
     *     )
     * )
     */



    public function leaveConferenceTransfer(Request $request)
    {



        $this->validate($this->request, [
            'lead_id' => 'required|numeric',
            'forward_extension' => '',
            'customer_phone_number' => 'required',
            'domain' => 'required'
        ]);

        if ($request->domain == 'crm') {
            $campaign_id = 45;
        } else {
            $campaign_id = $request->campaign_id;
        }

        $channel_live_details = ExtensionLive::on("mysql_" . $request->auth->parent_id)->where('campaign_id', $campaign_id)->where('extension', $request->auth->alt_extension)->get()->first();

        //echo "<pre>";print_r($channel_live_details);die;


        if ($channel_live_details) {
            $response = $this->model->leaveConferenceTransfer($this->request, $channel_live_details['channel']);
        } else {
            $response =  array(
                'success' => false,
                'message' => 'Unable to leave Conference',
                'data' => $channel_live_details
            );
        }

        return response()->json($response);
    }
}
