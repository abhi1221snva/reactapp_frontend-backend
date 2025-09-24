<?php

namespace App\Http\Controllers;

use App\Model\Dids;
use App\Model\Client\Did;
use App\Model\Client\UploadHistoryDid;
use App\Model\Client\CallTimings;
use App\Model\Client\Departments;
use App\Model\Client\Holiday;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\DB;
use File;
use Illuminate\Auth\AuthManager;
use App\Model\Authentication;
use Session;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Plivo\RestClient;
use App\Model\Client\SmsProviders;
use App\Model\User;
use App\Model\Master\UserExtension;
use Telnyx\Telnyx;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;
use Exception;




class DidsController extends Controller
{

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    private $request;
    protected $table = 'did';

    public function __construct(
        Request $request,
        Dids $dids,
        CallTimings $callTimings,
        Departments $departments,
        Holiday $holiday
    ) {
        $this->request = $request;
        $this->model = $dids;
        $this->modelCallTimings = $callTimings;
        $this->modelDepartments = $departments;
        $this->modelHoliday = $holiday;
        $this->title = $request->route('title');
    }

    /**
     * @OA\Post(
     *      path="/did",
     *      summary="Show complete did list",
     *      operationId="getList",
     *      tags={"DID"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
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
     * *      @OA\Parameter(
     *          name="search",
     *          in="query",
     *          description="serrch cli number",
     *          required=false,
     *          @OA\Schema(type="string")
     *      ),
     *     @OA\RequestBody(
     *         @OA\MediaType(
     *             mediaType="application/json",
     *             @OA\Schema(     *
     *                 @OA\Property(
     *                     property="token",
     *                     type="string"
     *                 ),
     *                 example={"token": "token"}
     *             )
     *         )
     *     ),
     *      @OA\Response(
     *          response="200",
     *          description="Did list"
     *      )
     * )
     */
    public function getList()
    {
        $response = $this->model->getList($this->request);
        return response()->json($response);
    }

    /*
     * Fetch Lists details
     * @return json
     */

    /**
     * @OA\Post(
     *      path="/list-by-email",
     *      summary="Show did list using email",
     *      operationId="getListByEmailId",
     *      tags={"DID"},
     *      security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         @OA\MediaType(
     *             mediaType="application/json",
     *             @OA\Schema(     *
     *                 @OA\Property(
     *                     property="id",
     *                     type="string"
     *                 ),
     *                 example={"id": "xxxxxx"}
     *             )
     *         )
     *     ),
     *      @OA\Response(
     *          response="200",
     *          description="Did list"
     *      )
     * )
     */

    public function getListByEmailId()
    {
        $response = $this->model->getListByEmailId($this->request);
        return response()->json($response);
    }


    /*
     * Edit List detail
     * @return json
     */
    /**
     * @OA\Post(
     *      path="/did_detail",
     *      summary="Show did detail using id",
     *      operationId="did_detail",
     *      tags={"DID"},
     *      security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         @OA\MediaType(
     *             mediaType="application/json",
     *             @OA\Schema(     *
     *                 @OA\Property(
     *                     property="token",
     *                     type="string"
     *                 ),
     *                 @OA\Property(
     *                     property="did",
     *                     type="string"
     *                 ),
     *                 example={"token": "token" , "did": "xxxxxx"}
     *             )
     *         )
     *     ),
     *      @OA\Response(
     *          response="200",
     *          description="Did detailed information "
     *      )
     * )
     */
    public function did_detail()
    {
        $response = $this->model->didDetail($this->request);
        return response()->json($response);
    }

    /*
     * Add List
     * @return json
     */
    /**
     * @OA\Post(
     *     path="/add-did",
     *     summary="Add a new DID number",
     *     tags={"DID"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="cli", type="string", example="19027063135"),
     *             @OA\Property(property="cnam", type="string", example="John Doe"),
     *             @OA\Property(property="area_code", type="string", example="415"),
     *             @OA\Property(property="country_code", type="string", example="1"),
     *             @OA\Property(property="dest_type", type="integer", example=1),
     *             @OA\Property(property="ivr_id", type="integer", example=10),
     *             @OA\Property(property="extension", type="string", example="1001"),
     *             @OA\Property(property="voicemail_id", type="integer", example=5),
     *             @OA\Property(property="forward_number", type="string", example="+11234567890"),
     *             @OA\Property(property="conf_id", type="integer", example=2),
     *             @OA\Property(property="ingroup", type="string", example="Support"),
     *             @OA\Property(property="operator_check", type="string", example="on"),
     *             @OA\Property(property="operator", type="string", example="Operator A"),
     *             @OA\Property(property="default_did", type="string", example="1"),
     *             @OA\Property(property="option_1", type="string", example="v"),
     *             @OA\Property(property="is_sms", type="string", example="1"),
     *             @OA\Property(property="sms_phone", type="string", example="+15551234567"),
     *             @OA\Property(property="sms_email", type="string", example="notify@example.com"),
     *             @OA\Property(property="set_exclusive_for_user", type="string", example="1001"),
     *             @OA\Property(property="call_screening_status", type="string", example="1"),
     *             @OA\Property(property="call_screening_ivr_id", type="string", example="15"),
     *             @OA\Property(property="language", type="string", example="en"),
     *             @OA\Property(property="voice_name", type="string", example="Joanna"),
     *             @OA\Property(property="ivr_audio_option", type="string", example="speech"),
     *             @OA\Property(property="speech_text", type="string", example="Welcome to our service."),
     *             @OA\Property(property="prompt_option", type="string", example="1"),
     *             @OA\Property(property="redirect_last_agent", type="string", example="1"),
     *             @OA\Property(property="sms_type", type="string", example="standard"),
     *             @OA\Property(property="voip_provider", type="string", example="ProviderX"),
     *             @OA\Property(property="call_time_department_id", type="string", example="10"),
     *             @OA\Property(property="call_time_holiday", type="string", example="1"),
     *             @OA\Property(property="dest_type_ooh", type="integer", example=2),
     *             @OA\Property(property="ivr_id_ooh", type="integer", example=20),
     *             @OA\Property(property="extension_ooh", type="string", example="1002"),
     *             @OA\Property(property="voicemail_id_ooh", type="integer", example=6),
     *             @OA\Property(property="forward_number_ooh", type="string", example="+19876543210"),
     *             @OA\Property(property="conf_id_ooh", type="integer", example=3),
     *             @OA\Property(property="ingroup_ooh", type="string", example="Sales"),
     *             @OA\Property(
     *                 property="fax_did",
     *                 type="array",
     *                 @OA\Items(type="string", example="101")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="DID added successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Did added successfully."),
     *             description="extension data"
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="CLI already in list"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error"
     *     )
     * )
     */
    public function addDid()
    {
        $response = $this->model->addList($this->request);
        return response()->json($response);
    }


    /**
     *  @OA\Post(
     *  path="/save-edit-did",
     * summary="Edit a DID (Phone Number) configuration",
     * tags={"DID"},
     *     security={{"Bearer":{}}},
     *  *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="did_id", type="integer", example=1),
     *             @OA\Property(property="cli", type="string", example="19027063135"),
     *             @OA\Property(property="cnam", type="string", example="John Doe"),
     *             @OA\Property(property="area_code", type="string", example="415"),
     *             @OA\Property(property="country_code", type="string", example="1"),
     *             @OA\Property(property="dest_type", type="integer", example=1),
     *             @OA\Property(property="ivr_id", type="integer", example=10),
     *             @OA\Property(property="extension", type="string", example="1001"),
     *             @OA\Property(property="voicemail_id", type="integer", example=5),
     *             @OA\Property(property="forward_number", type="string", example="+11234567890"),
     *             @OA\Property(property="conf_id", type="integer", example=2),
     *             @OA\Property(property="ingroup", type="string", example="Support"),
     *             @OA\Property(property="operator_check", type="string", example="on"),
     *             @OA\Property(property="operator", type="string", example="Operator A"),
     *             @OA\Property(property="default_did", type="string", example="1"),
     *             @OA\Property(property="option_1", type="string", example="v"),
     *             @OA\Property(property="sms", type="string", example="1"),
     *             @OA\Property(property="sms_phone", type="string", example="+15551234567"),
     *             @OA\Property(property="sms_email", type="string", example="notify@example.com"),
     *             @OA\Property(property="set_exclusive_for_user", type="string", example="1001"),
     *             @OA\Property(property="call_screening_status", type="string", example="1"),
     *             @OA\Property(property="call_screening_ivr_id", type="string", example="15"),
     *             @OA\Property(property="language", type="string", example="en"),
     *             @OA\Property(property="voice_name", type="string", example="Joanna"),
     *             @OA\Property(property="ivr_audio_option", type="string", example="speech"),
     *             @OA\Property(property="speech_text", type="string", example="Welcome to our service."),
     *             @OA\Property(property="prompt_option", type="string", example="1"),
     *             @OA\Property(property="redirect_last_agent", type="string", example="1"),
     *             @OA\Property(property="sms_type", type="string", example="standard"),
     *             @OA\Property(property="voip_provider", type="string", example="telnyx"),
     *             @OA\Property(property="call_time_department_id", type="string", example="10"),
     *             @OA\Property(property="call_time_holiday", type="string", example="1"),
     *             @OA\Property(property="dest_type_ooh", type="integer", example=2),
     *             @OA\Property(property="ivr_id_ooh", type="integer", example=20),
     *             @OA\Property(property="extension_ooh", type="string", example="1002"),
     *             @OA\Property(property="voicemail_id_ooh", type="integer", example=6),
     *             @OA\Property(property="forward_number_ooh", type="string", example="+19876543210"),
     *             @OA\Property(property="country_code_ooh", type="string", example="1"),
     *             @OA\Property(property="conf_id_ooh", type="integer", example=3),
     *             @OA\Property(property="ingroup_ooh", type="string", example="Sales"),
     *             @OA\Property(
     *                 property="fax_did",
     *                 type="array",
     *                 @OA\Items(type="string", example="101")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="DID updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Phone Number has been updated successfully."),
     *             description="extension data"  
     *         ),
     *     @OA\Response(
     *         response=400,
     *         description="Phone Number already in list"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error"
     *     )
     *     )
     * )
     */

    public function saveEdit()
    {
        $response = $this->model->saveEdit($this->request);
        return response()->json($response);
    }

    /**
     * @OA\Post(
     *      path="/delete-did",
     *      summary="Delete did detail",
     *      operationId="deleteDid",
     *      tags={"DID"},
     *      security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         @OA\MediaType(
     *             mediaType="application/json",
     *             @OA\Schema(     
     *                 @OA\Property(
     *                     property="did_id",
     *                     type="integer"
     *                 ),
     *                 example={"did_id": "101"}
     *             )
     *         )
     *     ),
     *      @OA\Response(
     *          response="200",
     *          description="Did deleted successfully "
     *      )
     * )
     */
    public function deleteDid()
    {
        $response = $this->model->deleteDid($this->request);
        return response()->json($response);
    }

    /*
     * Fetch Count details
     * @return json
     */

    /**
     * @OA\Get(
     *     path="/count-dids",
     *     summary="Count DIDs",
     *     description="Returns the total number of DIDs (phone numbers) that are not deleted for the authenticated client's database.",
     *      tags={"DID"},
     *     security={{"Bearer":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Successful response with DID count",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Count Dids"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(type="integer", example=189)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized"
     *     )
     * )
     */


    public function countDids(Request $request)
    {
        $dids = Dids::on("mysql_" . $request->auth->parent_id)->where('is_deleted', '=', '0')->get();
        $didsCount = $dids->count();
        return $this->successResponse("Count Dids", [$didsCount]);
    }
    /**
     * @OA\Post(
     *      path="/did-count",
     *      summary="Did count",
     *      operationId="getListCount",
     *      tags={"DID"},
     *      security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         @OA\MediaType(
     *             mediaType="application/json",
     *             @OA\Schema(     *
     *                 @OA\Property(
     *                     property="token",
     *                     type="string"
     *                 ),
     *                 example={"token": "token" }
     *             )
     *         )
     *     ),
     *      @OA\Response(
     *          response="200",
     *          description="Did count display"
     *      )
     * )
     */
    public function getListCount()
    {
        $response = $this->model->getListCount($this->request);
        return response()->json($response);
    }

    /**
     * @OA\Post(
     *     path="/employee-directory",
     *     summary="Get Employee Directory",
     *     description="Fetches a list of up to 8 employees with their name, extension, and whether they joined today.",
     *     tags={"DID"},
     *     security={{"Bearer": {}}},
     *     @OA\Response(
     *         response=200,
     *         description="Employee directory response",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Get extension count"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(
     *                     property="user_data",
     *                     type="array",
     *                     @OA\Items(
     *                         @OA\Property(property="id", type="integer", example=15),
     *                         @OA\Property(property="date_show", type="string", example="Today"),
     *                         @OA\Property(property="first_name", type="string", example="Atul"),
     *                         @OA\Property(property="last_name", type="string", example="Chaurasia"),
     *                         @OA\Property(property="extension", type="string", example="2005")
     *                     )
     *                 ),
     *                 @OA\Property(property="new_member", type="integer", example=3)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized"
     *     )
     * )
     */

    public function getEmployeeDirectory()
    {
        $response = $this->model->getEmployeeDirectory($this->request);
        return response()->json($response);
    }


    /**
     * @OA\Post(
     *     path="/inbound-count-avg",
     *     tags={"DID"},
     *     summary="Get average duration for inbound calls",
     *     description="Fetches average duration from cdr and cdr_archive tables based on filters.",
     *     operationId="getInboundCountAvg",
     *      security={{"Bearer": {}}},
     *      @OA\Parameter(
     *         name="start_date",
     *         in="query",
     *         required=true,
     *         description="Start date (Y-m-d format)",
     *         @OA\Schema(type="string", format="date")
     *     ),
     *     @OA\Parameter(
     *         name="end_date",
     *         in="query",
     *         required=true,
     *         description="End date (Y-m-d format)",
     *         @OA\Schema(type="string", format="date")
     *     ),
     *     @OA\Parameter(
     *         name="route",
     *         in="query",
     *         required=true,
     *         description="Route name or identifier",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="type",
     *         in="query",
     *         required=true,
     *         description="Call type (e.g. inbound, outbound)",
     *         @OA\Schema(type="string")
     *     ),
     * 
     *     @OA\Response(
     *         response=200,
     *         description="Successful response",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Average for inbound"),
     *             @OA\Property(property="data", type="number", format="float", example=23.5)
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad Request"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server Error"
     *     )
     * )
     */

    public function getInboundCountAvg()
    {
        $response = $this->model->getInboundCountAvg($this->request);
        return response()->json($response);
    }

    /**
     * * @OA\Post(
     *     path="/fax-did",
     *     summary="Get Fax DID list",
     *     tags={"DID"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"did"},
     *             @OA\Property(property="did", type="string", example="1234567890")
     *          )
     *       ),
     *          @OA\Response(response=200,
     *              description="List of Fax DIDs retrieved successfully",
     *              @OA\JsonContent(
     *              type="array",
     *              @OA\Items(
     *              @OA\Property(property="id", type="integer", example=1),
     *              @OA\Property(property="did", type="string", example="1234567890"),
     *              @OA\Property(property="status", type="string", example="active")
     *             )
     *            )
     *          ),
     *      @OA\Response(
     *         response=400,
     *         description="Bad Request",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="The given data was invalid.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Something went wrong")
     *         )
     *     )
     * )
     */
    function faxDidList()
    {
        $response = $this->model->faxDidList($this->request);
        return response()->json($response);
    }


    /**
     * @OA\Post(
     *     path="/fax-did-user",
     *     summary="Get Fax DID list by logged-in user",
     *     tags={"DID"},
     *     security={{"Bearer":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="List of Fax DIDs for the authenticated user",
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items(
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="did", type="string", example="1234567890"),
     *                 @OA\Property(property="userId", type="integer", example=25),
     *                 @OA\Property(property="status", type="string", example="active")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Unauthenticated.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Something went wrong")
     *         )
     *     )
     * )
     */

    function faxDidUserList()
    {
        $response = $this->model->faxDidUserList($this->request);
        return response()->json($response);
    }

    public function checkDefaultDid(Request $request)
    {
        $default_did = Did::on("mysql_" . $request->auth->parent_id)->where('default_did', $request->default_did)->get()->all();
        return $this->successResponse("Default Did List", $default_did);
    }

    /**
     * Get Offce Hours
     * @return type
     */

    /**
     * @OA\Post(
     *     path="/get-call-timings",
     *     tags={"DID"},
     *     summary="Get all call timings",
     *     description="This endpoint retrieves all call timings and their corresponding departments for the authenticated user’s parent account.",
     *     operationId="getCallTimings",
     *     security={{"Bearer":{}}},
     * 
     *     @OA\Response(
     *         response=200,
     *         description="Call timings retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Call Timings."),
     *             description="extenstion data"
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=404,
     *         description="No call timings found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="No Call Timings Found."),
     *             @OA\Property(property="data", type="array", @OA\Items())
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Something went wrong."),
     *             @OA\Property(property="data", type="array", @OA\Items())
     *         )
     *     )
     * )
     */

    public function getCallTimings()
    {
        $response = $this->modelCallTimings->getCallTimings($this->request);
        return response()->json($response);
    }

    /**
     * Get Department Offce Hours
     * @return type
     * 
     */
    /**
     * @OA\Post(
     *     path="/get-department-call-timings",
     *     tags={"DID"},
     *     summary="Get all department call timings",
     *     description="Retrieves all department call timings.",
     *     operationId="getCallTimings",
     *     security={{"Bearer":{}}},
     * 
     *     @OA\Response(
     *         response=200,
     *         description="Department call timings retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Department Call Timings."),
     *             description="extenstion data"
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=404,
     *         description="No department call timings found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="No department Call Timings Found."),
     *             @OA\Property(property="data", type="array", @OA\Items())
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Something went wrong."),
     *             @OA\Property(property="data", type="array", @OA\Items())
     *         )
     *     )
     * )
     */
    public function getDepartmentCallTimings()
    {
        $response = $this->modelCallTimings->getDepartmentCallTimings($this->request);
        return response()->json($response);
    }

    /**
     * Save Office Hours
     * @return type
     */
    /**
     * @OA\Post(
     *     path="/save-call-timings",
     *     tags={"DID"},
     *     summary="Save or update call timings for a department",
     *     description="Saves call timings for each weekday for a department.",
     *     operationId="saveCallTimings",
     *     security={{"Bearer":{}}},
     * 
     *     @OA\RequestBody(
     *         required=true,
     *         description="Call timing details with department info",
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="name", type="string", example="Support"),
     *                 @OA\Property(property="description", type="string", example="description"),
     *                 @OA\Property(property="dept_id", type="integer", example=1),
     *                 @OA\Property(
     *                     property="day",
     *                     type="array",
     *                     @OA\Items(type="string", example="Monday")
     *                 ),
     *                 @OA\Property(
     *                     property="from",
     *                     type="array",
     *                     @OA\Items(type="string", example="09:00:00")
     *                 ),
     *                 @OA\Property(
     *                     property="to",
     *                     type="array",
     *                     @OA\Items(type="string", example="18:00:00")
     *                 )
     *             )
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=200,
     *         description="Success response",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Call Time have been saved successfully"),
     *             @OA\Property(property="data", type="array", @OA\Items())
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=409,
     *         description="Department name already in use",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Department name already in use"),
     *             @OA\Property(property="data", type="array", @OA\Items())
     *         )
     *     ),
     * 
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Something went wrong"),
     *             @OA\Property(property="data", type="array", @OA\Items())
     *         )
     *     )
     * )
     */


    public function saveCallTimings()
    {
        $response = $this->modelCallTimings->saveCallTimings($this->request);
        return response()->json($response);
    }

    /**
     * Get All holidays
     * @return type
     */
    /**
     * @OA\Post(
     *     path="/get-all-holidays",
     *     tags={"DID"},
     *     summary="Get list of all holidays",
     *    security={{"Bearer":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Holidays List",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Holidays."),
     *             description="extension data"
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="No Holidays Found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="No Holidays Found."),
     *             @OA\Property(property="data", type="array", @OA\Items(type="string"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error"
     *     )
     * )
     */

    public function getAllHolidays()
    {
        $response = $this->modelHoliday->getAllHolidays($this->request);
        return response()->json($response);
    }

    /**
     * Get holiday detail
     * @return type
     */
    /**
     * @OA\Post(
     *     path="/get-holiday-datail",
     *     tags={"DID"},
     *     summary="get holiday datail",
     *     security={{"Bearer":{}}},
     *     description="get holiday datail by ID.",
     *     @OA\Parameter(
     *         name="holiday_id",
     *         in="query",
     *         required=true,
     *         description="ID of the holiday to delete",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Get holiday datail successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Holiday has been deleted successfully"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="string"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Something went wrong"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="string"))
     *         )
     *     )
     * )
     */
    public function getHolidayDetail()
    {
        $response = $this->modelHoliday->getHolidayDetail($this->request);
        return response()->json($response);
    }

    /**
     * Save Office Hours
     * @return type
     */
    /**
     * @OA\Post(
     *     path="/save-holiday-detail",
     *     tags={"DID"},
     *     summary="Add  a holiday",
     *     security={{"Bearer":{}}},
     *     description="Saves a holiday detail.",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="holiday_id", type="integer", example=0, description="ID of the holiday (0 or omit for new holiday)"),
     *                 @OA\Property(property="name", type="string", example="Republic Day"),
     *                 @OA\Property(property="date", type="string", format="date", example="2025-01-26"),
     *                 @OA\Property(property="month", type="integer", example=1)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Holiday saved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Holiday has been saved successfully."),
     *             @OA\Property(property="data", type="array", @OA\Items(type="string"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Duplicate holiday found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Date already marked as holiday"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="string"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Something went wrong"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="string"))
     *         )
     *     )
     * )
     */

    public function saveHolidayDetail()
    {
        $response = $this->modelHoliday->saveHolidayDetail($this->request);
        return response()->json($response);
    }

    /**
     * delete holiday
     * @return type
     */

    /**
     * @OA\Post(
     *     path="/delete-holiday",
     *     tags={"DID"},
     *     summary="Delete a holiday",
     *     security={{"Bearer":{}}},
     *     description="Deletes a specific holiday by ID.",
     *     operationId="deleteHoliday",
    
     *     @OA\Parameter(
     *         name="holiday_id",
     *         in="query",
     *         required=true,
     *         description="ID of the holiday to delete",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Holiday deleted successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Holiday has been deleted successfully"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="string"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Something went wrong"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="string"))
     *         )
     *     )
     * )
     */

    public function deleteHoliday()
    {
        $response = $this->modelHoliday->deleteHoliday($this->request);
        return response()->json($response);
    }

    /**
     * Get Department List
     * @return type
     */
    /**
     * @OA\Post(
     *      path="/get-department-list",
     *      summary="Get department list",
     *      tags={"DID"},
     *      security={{"Bearer":{}}},
     *      @OA\Response(
     *          response="200",
     *          description="extension data"
     *      )
     * )
     */
    public function getDepartmentList()
    {
        $response = $this->modelDepartments->getDepartments($this->request);
        return response()->json($response);
    }

    /**
     * Get DId Lit from api.didforsale.com
     * @return type
     */
    public function getDidListFromSale(Request $request)
    {
        try {
            $result = $this->getDidfromDidSale($request);
            return response()->json($result);
        } catch (Exception $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }


    public function getDidListFromPlivo(Request $request)
    {
        try {
            $result = $this->getDidfromDidPlivo($request);
            return response()->json($result);
        } catch (Exception $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
    public function getDidListFromTelnyx(Request $request)
    {
        try {
            $result = $this->getDidfromDidTelnyx($request);
            return response()->json($result);
        } catch (Exception $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }


    public function getDidListFromTwilio(Request $request)
    {
        try {
            $result = $this->getDidfromDidTwilio($request);
            return response()->json($result);
        } catch (Exception $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Get Did From Did Sale
     * @param type $request
     * @return string
     */
    private function getDidfromDidSale($request)
    {
        $result = [];
        try {
            $number = str_replace(array('(', ')', '_', '-', ' '), array(''), $request->data['phone']);
            $show = isset($request->data['show']) ? $request->data['show'] : 10;
            $country_code = isset($request->data['country']) ? $request->data['country'] : 1;
            $number_type = isset($request->data['numberType']) ? $request->data['numberType'] : 'local';

            $url = env('DID_SALE_API_URL') . "products/ListNumberAPI?number=$number&page_number=1&show=$show";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
            curl_setopt($ch, CURLOPT_HEADER, FALSE);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array("Accept: application/json", "Authorization: Basic " . base64_encode(env('DID_SALE_SERVICE_KEY') . ':' . env('DID_SALE_SERVICE_TOKEN'))));
            $response = curl_exec($ch);
            $response = json_decode($response, 1);
            Log::info('reached didforsale response', ['response' => $response]);
            if ($response['status']) {
                foreach ($response['numbers'] as $row) {
                    $temp = [
                        "<input type='checkbox' id='select_all_checkbox_" . $row['number'] . "' value='" . $row['number'] . "' data-ratecenter='" . $row['ratecenter'] . "' data-referenceid='" . $row['reference_id'] . "' data-state='" . $row['state'] . "' data-didtype='Metered' class='did_checkbox' /><label for='select_all_checkbox_" . $row['number'] . "'></label>",
                        $row['number'],
                        $row['state'],
                        "Metered"
                    ];
                    $result[] = $temp;
                }
            } else {
                throw new Exception(json_encode($response['message']));
            }
        } catch (Exception $e) {
            Log::error('didforsale REST Exception', ['message' => $e->getMessage()]);
            throw new Exception($e->getMessage());
            // return response()->json(['message' => 'Error fetching phone numbers from Twilio: ' . $e->getMessage()], 500);
        } catch (Exception $e) {
            Log::error('General Exception', ['message' => $e->getMessage()]);
            throw new Exception('Error: ' . $e->getMessage());
        }
        return $result;
    }

    private function getDidfromDidPlivo($request)
    {
        $result = [];
        try {
            $number = str_replace(array('(', ')', '_', '-', ' '), array(''), $request->data['phone']);
            $show = isset($request->data['show']) ? $request->data['show'] : 10;
            $country_code = isset($request->data['country']) ? $request->data['country'] : 1;
            $number_type = isset($request->data['numberType']) ? $request->data['numberType'] : 'local';

            $params = array(
                'limit' => $show,
                'country_iso' => 'US', # The ISO code A2 of the country
                'type' => $number_type, # The type of number you are looking for. The possible number types are local, national and tollfree.
                'pattern' => $number, # Represents the pattern of the number to be searched. 
                //'region' => 'Texas' # This filter is only applicable when the number_type is local. Region based filtering can be performed.
            );

            $database = "mysql_" . $request->auth->parent_id;


            $sms_setting = SmsProviders::on($database)->where("status", '1')->where('provider', 'plivo')->get()->first();
            if (!$sms_setting) {
                throw new Exception('Plivo settings not found in the database.');
            }
            $auth_id = $sms_setting->auth_id;
            $api_key = $sms_setting->api_key;

            if (empty($auth_id) || empty($api_key)) {
                throw new Exception('Invalid Plivo credentials: Auth ID or key is missing.');
            }

            $client = new RestClient($auth_id, $api_key);
            $response = $client->phonenumbers->list('US', $params);
            ///  return $response;

            foreach ($response as $list) {
                $temp = [
                    "<input type='checkbox' id='select_all_checkbox_" . $list->properties['number'] . "' value='" . $list->properties['number'] . "' data-ratecenter='" . $list->properties['rateCenter'] . "' data-referenceid='" . $list->properties['rateCenter'] . "' data-state='" . $list->properties['region'] . "' data-didtype='fixed' class='did_checkbox' /><label for='select_all_checkbox_" . $list->properties['number'] . "'></label>",
                    $list->properties['number'],
                    $list->properties['region'],
                    "Metered"
                ];
                $result[] = $temp;
            }
        } catch (\Plivo\Exceptions\RestException $e) {
            Log::error('Plivo REST Exception', ['message' => $e->getMessage()]);
            throw new Exception('Error fetching phone numbers from Twilio: ' . $e->getMessage());
            // return response()->json(['message' => 'Error fetching phone numbers from Twilio: ' . $e->getMessage()], 500);
        } catch (Exception $e) {
            Log::error('General Exception', ['message' => $e->getMessage()]);
            throw new Exception('Error: ' . $e->getMessage());
        }
        return $result;
    }
    //   private function getDidfromDidTelnyx($request)
    // {
    //     $result = [];

    //     $number = str_replace(array('(',')', '_', '-',' '), array(''), $request->data['phone']);
    //     $show = isset($request->data['show']) ? $request->data['show'] : 10;
    //     $country_code = isset($request->data['country']) ? $request->data['country'] : 1;
    //     $number_type = isset($request->data['numberType']) ? $request->data['numberType'] : 'local';

    //     $params = [
    //         'limit' => $show,
    //         'country_iso' => 'US',
    //         'type' => $number_type,
    //         'pattern' => $number,
    //     ];

    //     $database = "mysql_" . $request->auth->parent_id;

    //     $sms_setting = SmsProviders::on($database)->where("status",'1')->where('provider','telnyx')->get()->first();

    //     $api_key = $sms_setting->api_key;
    //     $client = new Telnyx(['api_key' => $api_key]);
    //     Log::info('Telnyx response', ['response' => $client]);

    //     try {
    //         $response = $client->phoneNumbers->list($params);
    //         $phoneNumbers = $response->data;

    //         foreach ($phoneNumbers as $list) {
    //             $temp = [
    //                 "<input type='checkbox' id='select_all_checkbox_" . $list->id . "' value='" . $list->phone_number . "' data-ratecenter='" . $list->rate_center . "' data-referenceid='" . $list->rate_center . "' data-state='" . $list->region . "' data-didtype='fixed' class='did_checkbox' /><label for='select_all_checkbox_" . $list->id . "'></label>",
    //                 $list->phone_number,
    //                 $list->region,
    //                 "Metered"
    //             ];
    //             $result[] = $temp;
    //         }
    //     } catch (\Exception $e) {
    //         Log::error('Telnyx API error', ['message' => $e->getMessage(), 'code' => $e->getCode()]);

    //     }}


    private function getDidfromDidTelnyxOld($request)
    {
        $result = [];

        $searchTerm = isset($request->data['phone']) ? $request->data['phone'] : '';
        $number = str_replace(array('(', ')', '_', '-', ' '), array(''), $searchTerm);
        $show = isset($request->data['show']) ? $request->data['show'] : 10;
        $country_code = isset($request->data['country']) ? $request->data['country'] : 1;
        $number_type = isset($request->data['numberType']) ? $request->data['numberType'] : 'local';

        $params = array(

            "filter['country_code']" => "US",
            "filter['limit']" =>  $show,
            "filter['rate_center']" => "CHICAGO HEIGHTS",
            "filter['phone_number_type']" => $number_type,
            "filter['administrative_area']" => "IL",
            "filter['phone_number']['contains']" => $number
        );



        $database = "mysql_" . $request->auth->parent_id;

        $sms_setting = SmsProviders::on($database)->where("status", '1')->where('provider', 'telnyx')->get()->first();
        $api_key = $sms_setting->api_key;

        $client = new Client([
            'base_uri' => "https://api.telnyx.com/v2/available_phone_numbers",
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ],
        ]);
        $response = $client->get('phone_numbers', ['query' => $params]);
        $body = json_decode($response->getBody());
        Log::info('reached', ['body' => $body]);
        foreach ($body->data as $list) {


            if (strpos($list->phone_number, $number) !== false) {
                $rateCenter = isset($list->rate_center) ? $list->rate_center : 'N/A';
                $region = isset($list->administrative_area) ? $list->administrative_area : 'N/A';

                $temp = [
                    "<input type='checkbox' id='select_all_checkbox_" . $list->id . "' value='" . $list->phone_number . "' data-ratecenter='" . $rateCenter . "' data-referenceid='" . $rateCenter . "' data-state='" . $region . "' data-didtype='fixed' class='did_checkbox' /><label for='select_all_checkbox_" . $list->id . "'></label>",
                    $list->phone_number,
                    $region,
                    "Metered",
                ];
                $result[] = $temp;
            }
        }

        return $result;
    }


    private function getDidfromDidTelnyx($request)
    {
        $result = [];

        try {
            $searchTerm = isset($request->data['phone']) ? $request->data['phone'] : '';
            $number = str_replace(array('(', ')', '_', '-', ' '), array(''), $searchTerm);
            $show = isset($request->data['show']) ? $request->data['show'] : 10;
            $country_code = isset($request->data['country']) ? $request->data['country'] : 1;
            $number_type = isset($request->data['numberType']) ? $request->data['numberType'] : 'local';

            $database = "mysql_" . $request->auth->parent_id;

            $sms_setting = SmsProviders::on($database)->where("status", '1')->where('provider', 'telnyx')->get()->first();
            if (!$sms_setting) {
                throw new Exception('Telnyx settings not found in the database.');
            }

            $api_key = $sms_setting->api_key;
            if (empty($api_key)) {
                throw new Exception('Invalid Telnyx credentials: Api key is missing.');
            }

            $telnyxApiEndpoint = 'https://api.telnyx.com/v2/available_phone_numbers';

            $filters = [
                'country_code' => $country_code, // Change the country code if needed
                'best_effort' => true, // Best effort search disabled,
                'limit' => $show,
                'national_destination_code' => $number,
                'phone_number_type' => $number_type
            ];

            $ch = curl_init($telnyxApiEndpoint . '?' . http_build_query(['filter' => $filters]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Accept: application/json',
                'Authorization: Bearer ' . $api_key,
            ]);

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            $response = curl_exec($ch);
            $result_data = json_decode($response, true);
            Log::info("telnyx error", ['result_data' => $result_data]);
            if (isset($result_data['data']) && is_array($result_data['data'])) {
                foreach ($result_data['data'] as $key => $list) {
                    $phone_number = $list['phone_number'];

                    foreach ($list['region_information'] as $region) {
                        if ($region['region_type'] == 'state') {
                            $rateCenter = $region['region_name'];
                            $stateCodeInfo = \App\Model\Master\AreaCodeList::where('state_code', $rateCenter)->first();

                            // Check if matching record is found
                            if ($stateCodeInfo) {
                                $stateName = $stateCodeInfo->state_name;
                            } else {
                                $stateName = $rateCenter; // Set a default value if no match is found
                            }
                        }
                    }
                    $type = $list['phone_number_type'];

                    $temp = [
                        "<input type='checkbox' id='select_all_checkbox_" . $phone_number . "' value='" . $list['phone_number'] . "' data-ratecenter='" . $rateCenter . "' data-referenceid='" . $rateCenter . "' data-state='" . $rateCenter . "'  data-didtype='fixed' class='did_checkbox' /><label for='select_all_checkbox_" . $list['phone_number'] . "'></label>",
                        $list['phone_number'],
                        $stateName,
                        $type,
                    ];

                    $result[] = $temp;
                }
            } else {
                $errorTitle = 'No data found';
                if (isset($result_data['errors'][0]['title'])) {
                    $errorTitle = $result_data['errors'][0]['title'];
                }
                throw new Exception($errorTitle);
            }
        } catch (\Telnyx\Exceptions\RestException $e) {
            Log::error('Telnyx REST Exception', ['message' => $e->getMessage()]);
            throw new Exception('Error fetching phone numbers from Telnyx: ' . $e->getMessage());
            // return response()->json(['message' => 'Error fetching phone numbers from Twilio: ' . $e->getMessage()], 500);
        }
        return $result;
        Log::info("telnyx result", ['result' => $result]);
    }


    private function getDidfromDidTwilio($request)
    {
        $result = [];
        try {
            $number = str_replace(array('(', ')', '_', '-', ' '), array(''), $request->data['phone']);
            $show = isset($request->data['show']) ? $request->data['show'] : 10;
            $country_code = isset($request->data['country']) ? $request->data['country'] : 1;
            $number_type = isset($request->data['numberType']) ? $request->data['numberType'] : 'local';

            /*$params = array(
            'limit' => $show,
            'country_iso' => 'US', # The ISO code A2 of the country
            'type' => $number_type, # The type of number you are looking for. The possible number types are local, national and tollfree.
            'pattern' => $number, # Represents the pattern of the number to be searched. 
            //'region' => 'Texas' # This filter is only applicable when the number_type is local. Region based filtering can be performed.
        );*/

            $database = "mysql_" . $request->auth->parent_id;


            $sms_setting = SmsProviders::on($database)->where("status", '1')->where('provider', 'twilio')->get()->first();
            if (!$sms_setting) {
                throw new Exception('Twilio settings not found in the database.');
            }

            $sid = $sms_setting->auth_id;
            $token = $sms_setting->api_key;

            if (empty($sid) || empty($token)) {
                throw new Exception('Invalid Twilio credentials: Auth ID or Token is missing.');
            }

            $twilio = new \Twilio\Rest\Client($sid, $token);

            try {
                $local = $twilio->availablePhoneNumbers("US")->local->read(["areaCode" => $number], $show);
            } catch (\Twilio\Exceptions\RestException $e) {
                throw new Exception('Error fetching phone numbers from Twilio: ' . $e->getMessage());
            }


foreach ($local as $list) {
    $temp = [
        'phoneNumber' => $list->phoneNumber,
        'region'      => $list->region,
        'rateCenter'  => $list->rateCenter,
        'type'        => 'Metered'
    ];
    $result[] = $temp;
}


            // foreach ($local as $list) {
            //     $temp = [
            //         "<input type='checkbox' id='select_all_checkbox_" . $list->phoneNumber . "' value='" . $list->phoneNumber . "' data-ratecenter='" . $list->rateCenter . "' data-referenceid='" . $list->rateCenter . "' data-state='" . $list->region . "' data-didtype='fixed' class='did_checkbox' /><label for='select_all_checkbox_" . $list->phoneNumber . "'></label>",
            //         $list->phoneNumber,
            //         $list->region,
            //         "Metered"
            //     ];
            //     $result[] = $temp;
            // }
            Log::info('result reached', ['result' => $result]);
        } catch (\Twilio\Exceptions\RestException $e) {
            Log::error('Twilio REST Exception', ['message' => $e->getMessage()]);
            throw new Exception('Error fetching phone numbers from Twilio: ' . $e->getMessage());
            // return response()->json(['message' => 'Error fetching phone numbers from Twilio: ' . $e->getMessage()], 500);
        } catch (Exception $e) {
            Log::error('General Exception', ['message' => $e->getMessage()]);
            throw new Exception('Error: ' . $e->getMessage());
        }

        return $result;
    }


    public function buySaveDidPlivo()
    {
        $response = $this->model->buySaveDidPlivo($this->request);
        return response()->json($response);
    }

    public function buySaveDidTelnyx()
    {
        $response = $this->model->buySaveDidTelnyx($this->request);
        return response()->json($response);
    }


    public function buySaveDidTwilio()
    {
        $response = $this->model->buySaveDidTwilio($this->request);
        return response()->json($response);
    }



    /**
     * buy and save Did
     * @return type
     */
    public function buySaveDid()
    {
        $response = $this->model->buySaveDid($this->request);
        return response()->json($response);
    }

    /**
     * @OA\Post(
     *     path="/upload-did",
     *     tags={"DID"},
     *     summary="Upload a DID Excel file",
     *     description="Uploads a DID Excel file (.xlsx or .xls), processes CLI records, checks for duplicates, and inserts into both master and client-specific databases.",
     *     operationId="uploadDid",
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"file", "upload_title"},
     *                 @OA\Property(
     *                     property="file",
     *                     type="string",
     *                    
     *                     description="The Excel file (.xlsx or .xls) containing DID CLIs"
     *                 ),
     *                 @OA\Property(
     *                     property="upload_title",
     *                     type="string",
     *                     example="Weekly Upload",
     *                     description="Title to describe this upload batch"
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="DID file processed successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Did added successfully")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Missing file or invalid input",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="The did file field is required.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=409,
     *         description="Duplicate CLI found in file or database",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Duplicate CLIs found in the file.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Excel file could not be read or is malformed",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Unable to read excel. No ReaderType or WriterType could be detected.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error during file processing",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Did not added successfully, File is empty")
     *         )
     *     )
     * )
     */


    public function uploadDid(Request $request)
    {
        if (!$request->has('file')) {
            return response()->json([
                'success' => false,
                'message' => 'The did file field is required.',
            ], 400);
        }
        if ($request->has('file')) {
            //path of uploaded file
            $filename = $this->request->input('file');
            $filePath = env('FILE_UPLOAD_PATH') . '/' . $filename;

            Log::info('reached filepath', ['filePath' => $filePath]);
            //  $filePath = base_path()."/uploads/".$this->request->input('file');
            //$filePath = base_path()."\public\api/".$this->request->input('file');
            // $filePath= ('C:/xampp/htdocs/uploads/'.$this->request->input('file'));


            try {
                if (!empty($filePath)) {
                    $database = "mysql_" . $request->auth->parent_id;

                    try {
                        // switch to dynamic connection
                        $connection = DB::connection($database);
                        $reader = Excel::toArray(new Excel(), $filePath);
                        Log::info('reached reader', ['reader' => $reader]);
                    } catch (\Exception $e) {
                        return array(
                            'success' => 'false',
                            'message' => 'Unable to read excel' . $e->getMessage()
                        );
                    }

                    $cliArray = array(); // Create an empty array to store unique CLIs
                    $did = null; // Initialize the variable
                    $masterDid = null;
                    if (!empty($reader)) {
                        $count = 0;

                        foreach ($reader as $row) {
                            $i = 0;
                            $add = 0;
                            foreach ($row as $item => $value) {
                                if ($item != 0) {
                                    // Check if the CLI already exists in the array
                                    if (in_array($value[0], $cliArray)) {
                                        return array(
                                            'success' => 'false',
                                            'message' => 'Duplicate CLIs found in the file.',
                                        );
                                    }

                                    // Add the CLI to the array
                                    $cliArray[] = $value[0];
                                    // Check if the CLI already exists in the database
                                    $existingCli = $connection->table('did')
                                        ->where('cli', $value[0])
                                        ->first();
                                    Log::info('reached cliArray', ['cliArray' => $cliArray]);

                                    if ($existingCli) {
                                        // CLI already exists in the database, skip insertion
                                        continue;
                                        return array(
                                            'success' => 'false',
                                            'message' => 'Duplicate CLI found in the database.',
                                        );
                                    }

                                    // Insert the CLI into the database client_3
                                    $did = new \App\Model\Client\Did([
                                        'cli' => $value[0],
                                        'area_code' => substr($value[0], 1, 3),
                                        'sms' => 0,
                                        'voip_provider' => $value[1]
                                    ]);
                                    Log::info('reached did', ['did' => $did]);

                                    $connection->table('did')->insert($did->toArray());

                                    // Insert the CLI into the master database
                                    $data1 = [
                                        'parent_id' => $request->auth->parent_id,
                                        'cli' => $value[0],
                                        'user_id' => $request->auth->id,
                                        'area_code' => substr($value[0], 1, 3),
                                        'country_code' => '+1',
                                        'provider' => 1,
                                        'voip_provider' => $value[1]

                                    ];
                                    Log::info('reached data1', ['data1' => $data1]);

                                    $masterDid = \App\Model\Master\Did::create($data1);
                                    Log::info('reached masterDid', ['masterDid' => $masterDid]);
                                }
                            }
                        }
                        $fileName = $request->input('file');
                        // Insert the CLI into the upload_history_did table
                        $currentUrl = URL::current();
                        $uploadTitle = $request->input('upload_title');

                        $uploadHistoryDid = new \App\Model\Client\UploadHistoryDid([
                            'user_id' => $request->auth->id,
                            'file_name' => $fileName,
                            'upload_url' => $currentUrl,
                            'url_title' => $uploadTitle

                        ]);
                        Log::info('reached uploadHistoryDid', ['uploadHistoryDid' => $uploadHistoryDid]);

                        $connection->table('upload_history_did')->insert($uploadHistoryDid->toArray());
                        if ($did && $masterDid) {
                            return array(
                                'success' => 'true',
                                'message' => 'Did added successfully',
                            );
                        }
                    } else {
                        return array(
                            'success' => 'false',
                            'message' => 'Did not added successfully, File is empty',
                        );
                    }
                }
            } catch (Exception $e) {
                Log::error($e->getMessage()); // Corrected line
            } catch (InvalidArgumentException $e) {
                Log::error($e->getMessage()); // Corrected line
            }
        }
    }


    //     public function uploadDid(Request $request)
    // {   
    //     if (!$request->has('file')) 
    //     {

    //         return response()->json([
    //             'success' => false,
    //             'message' => 'The did file field is required.',
    //         ], 400);
    //     }
    //     if($this->request->has('file'))
    //     {
    //         //path of uploaded file
    //         $filePath = base_path()."\public\api/".$this->request->input('file');
    //         try
    //         {
    //             if (!empty($filePath))
    //             {
    //                 //$database = "client_" . $request->auth->parent_id;

    //                 try
    //                 {
    //                     $reader = Excel::toArray(new Excel(), $filePath);
    //                 }
    //                 catch (\Exception $e)
    //                 {
    //                     return array(
    //                         'success'=> 'false',
    //                         'message'=> 'Unable to read excel'. $e->getMessage()
    //                     );
    //                 }

    //                 $cliArray = array(); // Create an empty array to store unique CLIs

    //                 if (!empty($reader))
    //                 {
    //                     $count = 0;

    //                     foreach ($reader as $row)
    //                     {
    //                         $i=0;
    //                         $add = 0; 
    //                         foreach ($row as $item => $value)
    //                         {
    //                             if ($item != 0)
    //                             {
    //                                 // Check if the CLI already exists in the array
    //                                 if (in_array($value[0], $cliArray))
    //                                 {
    //                                     return array(
    //                                         'success'=> 'false',
    //                                         'message'=> 'Duplicate CLIs found in the file.',
    //                                     );
    //                                 }

    //                                 // Add the CLI to the array
    //                                 $cliArray[] = $value[0];

    //                                 // Insert the CLI into the database client_3
    //                                 $did = new Did([
    //                                     'cli' => $value[0],
    //                                     'area_code' => substr($value[0], 1, 3),
    //                                 ]);
    //                                 $did=new \App\Model\Client\Did($data);
    //                                 $did->save();

    //                                 // Insert the CLI into the master database
    //                                 $data1 = [
    //                                     'parent_id' => $request->auth->parent_id,
    //                                     'cli' => $value[0],
    //                                     'user_id' => $request->auth->id,
    //                                     'area_code' => substr($value[0], 1, 3),
    //                                     'country_code' => +1,
    //                                     'provider' => 1,
    //                                 ];
    //                                 $masterDid = new \App\Model\Master\Did($data1);
    //                                 $masterDid->save();

    //                             }
    //                         }
    //                     }

    //                     if ($did && $masterDid)
    //                     {
    //                         return array(
    //                             'success'=> 'true',
    //                             'message'=> 'Did added successfully.'
    //                         );
    //                     }
    //                 }
    //                 else
    //                 {
    //                     return array(
    //                         'success'=> 'false',
    //                         'message'=> 'Did not added successfully, File is empty',
    //                     );
    //                 }
    //             }
    //         }
    //         catch (Exception $e)
    //         {
    //             Log::log($e->getMessage());
    //         }
    //         catch (InvalidArgumentException $e)
    //         {
    //             Log::log($e->getMessage());
    //         }



    //  }

    // }

    function setAppExtension(Request $request)
    {
        $users = User::where('base_parent_id', '2')->where('is_deleted', '0')->get()->all();
        foreach ($users as $list_user) {
            $app_extension = $list_user->app_extension;
            $first_name = $list_user->first_name;
            $last_name = $list_user->last_name;
            $alt_extension = $list_user->extension;

            //for password

            $user_data_alt_extension = UserExtension::where('username', "=", $alt_extension)->first();

            if (!empty($user_data_alt_extension)) {

                $secret = $user_data_alt_extension->secret;
                $user_data_extension = UserExtension::where('username', "=", $app_extension)->first();

                if (!empty($user_data_extension)) {
                    $dt['name'] = $app_extension;
                    $dt['context'] = 'user-extensions-phones';
                    $dt['username'] = $app_extension;
                    $dt['fullname'] = $first_name . ' ' . $last_name;
                    $insertData = "UPDATE user_extensions SET name= :name , fullname= :fullname, context= :context WHERE username= :username ";
                    $record_ustext = DB::connection('master')->select($insertData, $dt);

                    //echo "<pre>";print_r($dt);die;
                    //echo "yes-".$app_extension.'<br>';
                } else {
                    $dt['name'] = $app_extension;
                    $dt['username'] = $app_extension;
                    $dt['secret'] = $secret;
                    $dt['context'] = 'user-extensions-phones'; //'default';
                    $dt['host'] = 'dynamic';
                    $dt['nat'] = 'force_rport,comedia';
                    $dt['qualify'] = 'no';
                    $dt['type'] = 'friend';
                    $dt['fullname'] = $first_name . ' ' . $last_name;
                    $dt['rtptimeout'] = '7200';
                    $dt['rtpholdtimeout'] = '7200';
                    $dt['sendrpid'] = 'yes';
                    $dt['subscribemwi'] = 'yes';
                    $dt['t38pt_udptl'] = 'no';
                    $dt['transport'] = 'TLS,WS,WSS,TCP,UDP';
                    $dt['trustrpid'] = 'no';
                    $dt['useclientcode'] = 'no';
                    $dt['usereqphone'] = 'no';
                    $dt['videosupport'] = 'yes';
                    $dt['icesupport'] = 'yes';
                    $dt['force_avp'] = 'no';
                    $dt['dtlsenable'] = 'yes';
                    $dt['dtlsverify'] = 'fingerprint';
                    $dt['dtlscertfile'] = '/etc/asterisk/asterisk.pem';
                    $dt['dtlssetup'] = 'actpass';
                    $dt['rtcp_mux'] = 'no';
                    $dt['avpf'] = 'no';
                    $dt['webrtc'] = 'no';

                    $insertData = "INSERT INTO user_extensions SET fullname= :fullname, context= :context, name= :name, type= :type , qualify= :qualify , nat= :nat , host= :host, secret= :secret,username= :username, rtptimeout= :rtptimeout, rtpholdtimeout= :rtpholdtimeout,sendrpid= :sendrpid,subscribemwi= :subscribemwi,t38pt_udptl= :t38pt_udptl,transport= :transport,trustrpid= :trustrpid,useclientcode= :useclientcode,usereqphone= :usereqphone,videosupport= :videosupport,icesupport= :icesupport,force_avp =:force_avp,dtlsenable=:dtlsenable,dtlsverify=:dtlsverify,dtlscertfile= :dtlscertfile,dtlssetup= :dtlssetup,rtcp_mux= :rtcp_mux,avpf= :avpf,
                webrtc= :webrtc";

                    $record_ustextSav = DB::connection('master')->select($insertData, $dt);
                    //echo "no-".$app_extension.'<br>';
                }
            }
        }
    }
}

//select app_extension,secret,context from users inner join  user_extensions on user_extensions.username=users.app_extension where users.base_parent_id=3