<?php

namespace App\Http\Controllers;

use App\Mail\GenericMail;
use App\Mail\SystemNotificationMail;
use App\Model\Client\ReportLog;
use App\Model\Client\SmtpSetting;
use App\Model\Master\LoginLog;

use App\Model\Master\Timezone;
use App\Model\Report;
use App\Services\MailService;
use App\Services\ReportService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use App\Model\User;

class ReportController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    private $request;

    public function __construct(Request $request, Report $report)
    {
        $this->request = $request;
        $this->model = $report;
    }

    /*
     * Fetch call data report
     * @return json
     */

    /**
     * @OA\Post(
     *     path="/report",
     *     tags={"Reports"},
     *     security={{"Bearer":{}}},
     *     summary="Fetch report data based on filters",
     *     description="Returns filtered report data including number, area code, timezone, campaign, route, disposition, date range, and extension",
     *     @OA\Parameter(
     *         name="api_key",
     *         in="query",
     *         required=false,
     *         description="API Key of the client",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="number",
     *         in="query",
     *         required=false,
     *         description="Search by number (prefix match)",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="numbers[]",
     *         in="query",
     *         required=false,
     *         description="Search by multiple numbers",
     *         @OA\Schema(type="array", @OA\Items(type="string"))
     *     ),
     *     @OA\Parameter(
     *         name="area_code[]",
     *         in="query",
     *         required=false,
     *         description="Filter by area code(s)",
     *         @OA\Schema(type="array", @OA\Items(type="string"))
     *     ),
     *     @OA\Parameter(
     *         name="timezone_value",
     *         in="query",
     *         required=false,
     *         description="Filter based on timezone",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="campaign",
     *         in="query",
     *         required=false,
     *         description="Campaign ID",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="route",
     *         in="query",
     *         required=false,
     *         description="Route value",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="disposition[]",
     *         in="query",
     *         required=false,
     *         description="List of disposition IDs",
     *         @OA\Schema(type="array", @OA\Items(type="integer"))
     *     ),
     *     @OA\Parameter(
     *         name="type",
     *         in="query",
     *         required=false,
     *         description="Call type (e.g. incoming, outgoing)",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="start_date",
     *         in="query",
     *         required=false,
     *         description="Start date in YYYY-MM-DD format",
     *         @OA\Schema(type="string", format="date")
     *     ),
     *     @OA\Parameter(
     *         name="end_date",
     *         in="query",
     *         required=false,
     *         description="End date in YYYY-MM-DD format",
     *         @OA\Schema(type="string", format="date")
     *     ),
     *     @OA\Parameter(
     *         name="extension",
     *         in="query",
     *         required=false,
     *         description="Extension number",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="did_numbers[]",
     *         in="query",
     *         required=false,
     *         description="DID numbers to filter on",
     *         @OA\Schema(type="array", @OA\Items(type="string"))
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful response",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="data", type="array", @OA\Items(type="object"))
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
     *         description="Internal server error"
     *     )
     * )
     */

    public function getReport()
    {
        $this->validate($this->request, [
            'number' => 'numeric',
            'campaign' => 'numeric',
            'type' => 'string',
            'start_date' => 'date',
            'end_date' => 'date',
            'lower_limit' => 'numeric',
            'upper_limit' => 'numeric'
        ]);
        $response = $this->model->getReport($this->request);
        return response()->json($response);
    }
    /**
     * @OA\Post(
     *     path="/login-history",
     *     summary="Retrieve login history",
     *     description="Fetch the login history for a user with optional filters.",
     *     tags={"Reports"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="number",
     *         in="query",
     *         description="Phone number to search for",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="area_code",
     *         in="query",
     *         description="Area code to filter by",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="timezone_value",
     *         in="query",
     *         description="Timezone to filter by",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="start_date",
     *         in="query",
     *         description="Start date to filter by",
     *         required=false,
     *         @OA\Schema(type="string", format="date")
     *     ),
     *     @OA\Parameter(
     *         name="end_date",
     *         in="query",
     *         description="End date to filter by",
     *         required=false,
     *         @OA\Schema(type="string", format="date")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful retrieval of login history",
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items(
     *                 @OA\Property(property="number", type="string"),
     *                 @OA\Property(property="area_code", type="string"),
     *                 @OA\Property(property="timezone_value", type="string"),
     *                 @OA\Property(property="start_time", type="string", format="date-time"),
     *                 @OA\Property(property="end_time", type="string", format="date-time")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid parameters",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="Bad Request")
     *         )
     *     )
     * )
     */

    public function loginHistory()
    {
        $this->validate($this->request, [
            //'user_id' => 'numeric',
            'start_date' => 'date',
            'end_date' => 'date',
            'lower_limit' => 'numeric',
            'upper_limit' => 'numeric'
        ]);
        $response = $this->model->loginHistory($this->request);
        return response()->json($response);
    }
    /**
     * @OA\Post(
     *     path="/report-lead-id",
     *     summary="Get Call Data Report by Lead ID",
     *     description="Fetch call data records filtered by various parameters like lead_id, campaign, disposition, etc.",
     *     tags={"Reports"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="id", type="integer", example=123),
     *             @OA\Property(property="extension", type="string", example="101"),
     *             @OA\Property(property="lead_id", type="integer", example=456),
     *             @OA\Property(property="campaign", type="integer", example=2),
     *             @OA\Property(property="route", type="string", example="inbound"),
     *             @OA\Property(property="disposition", type="integer", example=5),
     *             @OA\Property(property="type", type="string", example="manual"),
     *             @OA\Property(property="start_date", type="string", format="date", example="2024-01-01"),
     *             @OA\Property(property="end_date", type="string", format="date", example="2024-01-31"),
     *             @OA\Property(property="lower_limit", type="integer", example=0),
     *             @OA\Property(property="upper_limit", type="integer", example=10)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful Call Data Report response",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Call Data Report."),
     *             @OA\Property(property="record_count", type="integer", example=25),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="extension", type="string", example="101"),
     *                     @OA\Property(property="number", type="string", example="9876543210"),
     *                     @OA\Property(property="start_time", type="string", format="date-time", example="2024-01-01 10:00:00"),
     *                     @OA\Property(property="end_time", type="string", format="date-time", example="2024-01-01 10:30:00"),
     *                     @OA\Property(property="duration", type="string", example="1800"),
     *                     @OA\Property(property="route", type="string", example="inbound"),
     *                     @OA\Property(property="call_recording", type="string", example="http://example.com/recording.mp3"),
     *                     @OA\Property(property="campaign_id", type="integer", example=2),
     *                     @OA\Property(property="lead_id", type="integer", example=456),
     *                     @OA\Property(property="type", type="string", example="manual"),
     *                     @OA\Property(property="disposition", type="string", example="Completed")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Missing or invalid parameters",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Call Data Report doesn't exist.")
     *         )
     *     )
     * )
     */


    public function getReportByLeadId()
    {
        $this->validate($this->request, [
            'lead_id' => 'numeric',
            'campaign' => 'numeric',
            'disposition' => 'numeric',
            'type' => 'string',
            'start_date' => 'date',
            'end_date' => 'date',
            'lower_limit' => 'numeric',
            'upper_limit' => 'numeric'
        ]);
        $response = $this->model->getReportByLeadId($this->request);
        return response()->json($response);
    }

    /*
     * Fetch live calls
     * @return json
     */

    /**
     * @OA\Post(
     *     path="/live-call",
     *     tags={"Reports"},
     *     summary="Fetch live calls",
     *     description="Retrieve live calls for the authenticated user. Users with lower privileges see only group-related calls.",
     *     operationId="getLiveCall",
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Authenticated user request object",
     *         @OA\JsonContent(
     *             @OA\Property(property="auth", type="object",
     *                 @OA\Property(property="level", type="integer", example=2),
     *                 @OA\Property(property="extension", type="string", example="101"),
     *                 @OA\Property(property="parent_id", type="integer", example=1)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Live Calls or No Live Calls found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Live Calls."),
     *             @OA\Property(property="data", type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="extension", type="string", example="101"),
     *                     @OA\Property(property="number", type="string", example="9876543210"),
     *                     @OA\Property(property="start_time", type="string", format="date-time", example="2024-06-01T10:00:00Z"),
     *                     @OA\Property(property="duration", type="string", example="00:01:15")
     *                 )
     *             )
     *         )
     *     )
     * )
     */


    public function getLiveCall()
    {
        $response = $this->model->getLiveCall($this->request);
        return response()->json($response);
    }

    /*
     * Fetch transfer Report
     * @return json
     */
    /**
     * @OA\Post(
     *     path="/transfer-report",
     *     tags={"Reports"},
     *     summary="Fetch transfer report",
     *     description="Retrieve a transfer report for a given user and filters. Requires an ID and optionally filters like extension, number, campaign, transfer status, and date range.",
     *     operationId="getTransferReport",
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"id"},
     *             @OA\Property(property="id", type="integer", example=1),
     *             @OA\Property(property="extension", type="string", example="101"),
     *             @OA\Property(property="number", type="string", example="9876543210"),
     *             @OA\Property(property="campaign", type="integer", example=3),
     *             @OA\Property(property="transfer_status_id", type="integer", example=1),
     *             @OA\Property(property="start_date", type="string", format="date", example="2024-06-01"),
     *             @OA\Property(property="end_date", type="string", format="date", example="2024-06-30"),
     *             @OA\Property(property="lower_limit", type="integer", example=0),
     *             @OA\Property(property="upper_limit", type="integer", example=10),
     *             @OA\Property(property="auth", type="object",
     *                 @OA\Property(property="role", type="integer", example=2),
     *                 @OA\Property(property="extension", type="string", example="101"),
     *                 @OA\Property(property="parent_id", type="integer", example=1)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Transfer report result",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Transfer Report."),
     *             @OA\Property(property="record_count", type="integer", example=5),
     *             @OA\Property(property="data", type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="extension", type="string", example="101"),
     *                     @OA\Property(property="number", type="string", example="9876543210"),
     *                     @OA\Property(property="start_time", type="string", format="date-time", example="2024-06-01T10:00:00Z"),
     *                     @OA\Property(property="transfer_extension", type="string", example="102"),
     *                     @OA\Property(property="call_recording", type="string", example="https://example.com/recording1.mp3"),
     *                     @OA\Property(property="call_recording_transfer", type="string", example="https://example.com/recording2.mp3"),
     *                     @OA\Property(property="campaign", type="string", example="Summer Campaign"),
     *                     @OA\Property(property="status", type="string", example="Transferred")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid ID or missing input",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Transfer Report doesn't exist.")
     *         )
     *     )
     * )
     */

    public function getTransferReport()
    {
        $response = $this->model->getTransferReport($this->request);
        return response()->json($response);
    }

    /*
     * Fetch extension by group
     * @return json
     */

    /**
     * @OA\Post(
     *     path="/extension-group-list",
     *     summary="Get extensions grouped by criteria",
     *     tags={"Reports"},
     *     security={{"Bearer":{}}},
     *      @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"parent_id"},
     *             @OA\Property(property="group_id", type="integer", example=2, description="ID of the group")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Extensions grouped by group_id",
     *         @OA\JsonContent(
     *             type="object",
     *             example={
     *                 "success": true,
     *                 "message": "Extensions fetched successfully",
     *                 "data": {
     *                     {"id": 101, "name": "Ext A", "group_id": 2},
     *                     {"id": 102, "name": "Ext B", "group_id": 2}
     *                 }
     *             }
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid or missing group_id",
     *         @OA\JsonContent(
     *             example={
     *                 "success": false,
     *                 "message": "The group_id field is required."
     *             }
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error"
     *     )
     * )
     */

    public function getExtensionByGroup()
    {
        $response = $this->model->getExtensionByGroup($this->request);
        return response()->json($response);
    }

    /*
     * Fetch extension by group active
     * @return json
     */

    /**
     * @OA\POST(
     *     path="/active-extension-group-list",
     *     summary="Get active extensions by group",
     *     tags={"Reports"},
     * security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="group_id",
     *         in="query",
     *         required=true,
     *         description="ID of the group",
     *         @OA\Schema(type="integer", example=5)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *         @OA\JsonContent(
     *             type="object",
     *             example={
     *                 "success": true,
     *                 "data": {
     *                     {"id": 1, "name": "Extension A", "group_id": 5},
     *                     {"id": 2, "name": "Extension B", "group_id": 5}
     *                 },
     *                 "message": "Extensions retrieved successfully"
     *             }
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Missing or invalid group_id",
     *         @OA\JsonContent(
     *             example={
     *                 "success": false,
     *                 "message": "The group_id field is required."
     *             }
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error"
     *     )
     * )
     */
    public function getActiveExtensionByGroup()
    {
        $response = $this->model->getActiveExtensionByGroup($this->request);
        return response()->json($response);
    }

    /**
     * @OA\Post(
     *     path="/disposition-wise-call",
     *     summary="Get Disposition Summary",
     *     description="Returns the summary of call dispositions between given start and end datetime for a specific client based on parent ID.",
     *     operationId="getDispositionSummary",
     *     tags={"Reports"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"startTime", "endTime"},
     *             @OA\Property(
     *                 property="startTime",
     *                 type="string",
     *                 format="date-time",
     *                 example="2025-06-01 00:00:00",
     *                 description="Start datetime in Y-m-d H:i:s format"
     *             ),
     *             @OA\Property(
     *                 property="endTime",
     *                 type="string",
     *                 format="date-time",
     *                 example="2025-06-01 23:59:59",
     *                 description="End datetime in Y-m-d H:i:s format"
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Disposition summary result",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Disposition summary"),
     *             @OA\Property(property="data", type="array", @OA\Items(
     *                 @OA\Property(property="disposition_id", type="integer", example=101),
     *                 @OA\Property(property="disposition_name", type="string", example="Not Interested"),
     *                 @OA\Property(property="count", type="integer", example=15)
     *             ))
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="The given data was invalid."),
     *             @OA\Property(property="errors", type="object",
     *                 @OA\Property(property="startTime", type="array", @OA\Items(type="string", example="The startTime field is required.")),
     *                 @OA\Property(property="endTime", type="array", @OA\Items(type="string", example="The endTime field is required."))
     *             )
     *         )
     *     )
     * )
     */

    public function getDispositionSummary(Request $request)
    {
        $this->validate($request, [
            'startTime' => 'required|date_format:Y-m-d H:i:s',
            'endTime' => 'required|date_format:Y-m-d H:i:s'
        ]);
        $reportService = new ReportService($request->auth->parent_id);
        return $this->successResponse("Disposition summary", $reportService->dispositionSummary($this->request, $request->startTime, $request->endTime));
    }

    /**
     * @OA\Post(
     *     path="/state-wise-call",
     *     summary="Get State-wise Summary",
     *     description="Returns a summary of call data grouped by state between the given start and end datetime, filtered by client.",
     *     operationId="getStateWiseSummary",
     *     tags={"Reports"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"startTime", "endTime"},
     *             @OA\Property(
     *                 property="startTime",
     *                 type="string",
     *                 format="date-time",
     *                 example="2025-06-01 00:00:00",
     *                 description="Start datetime in Y-m-d H:i:s format"
     *             ),
     *             @OA\Property(
     *                 property="endTime",
     *                 type="string",
     *                 format="date-time",
     *                 example="2025-06-01 23:59:59",
     *                 description="End datetime in Y-m-d H:i:s format"
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="State-wise summary response",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="State Wise summary"),
     *             @OA\Property(property="data", type="array", @OA\Items(
     *                 @OA\Property(property="state", type="string", example="California"),
     *                 @OA\Property(property="total_calls", type="integer", example=150),
     *                 @OA\Property(property="total_duration", type="integer", example=3450)
     *             ))
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="The given data was invalid."),
     *             @OA\Property(property="errors", type="object",
     *                 @OA\Property(property="startTime", type="array", @OA\Items(type="string", example="The startTime field is required.")),
     *                 @OA\Property(property="endTime", type="array", @OA\Items(type="string", example="The endTime field is required."))
     *             )
     *         )
     *     )
     * )
     */

    public function getStateWiseSummary(Request $request)
    {
        $this->validate($request, [
            'startTime' => 'required|date_format:Y-m-d H:i:s',
            'endTime' => 'required|date_format:Y-m-d H:i:s'
        ]);
        $reportService = new ReportService($request->auth->parent_id);
        return $this->successResponse("State Wise summary", $reportService->stateWiseSummary($request, $request->startTime, $request->endTime));
    }
    /**
     * @OA\Post(
     *     path="/cdr-call-agent-count",
     *     summary="Get Unique Agent Call Count",
     *     description="Returns the total number of distinct agent extensions involved in calls within a given time range. Optionally filters by user ID or uses the authenticated user's extension.",
     *     tags={"Reports"},
     *     security={{"Bearer":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             required={"startTime", "endTime"},
     *             @OA\Property(
     *                 property="userId",
     *                 type="array",
     *                 @OA\Items(type="integer", example=12),
     *                 description="Optional array of user IDs to filter agents"
     *             ),
     *             @OA\Property(
     *                 property="startTime",
     *                 type="string",
     *                 format="date-time",
     *                 example="2024-06-01 00:00:00",
     *                 description="Start time for filtering call data"
     *             ),
     *             @OA\Property(
     *                 property="endTime",
     *                 type="string",
     *                 format="date-time",
     *                 example="2024-06-24 23:59:59",
     *                 description="End time for filtering call data"
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Total number of unique agent extensions",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="totalAgent", type="integer", example=8)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=400,
     *         description="Invalid input parameters",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="error", type="string", example="Missing required fields or invalid date format")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="error", type="string", example="Unexpected error occurred")
     *         )
     *     )
     * )
     */

    public function cdrCallAgentCount(Request $request)
    {
        $this->validate($request, [
            'startTime' => 'required|date_format:Y-m-d H:i:s',
            'endTime' => 'required|date_format:Y-m-d H:i:s'
        ]);
        $reportService = new ReportService($request->auth->parent_id);
        return $this->successResponse(
            "Call Count",
            $reportService->cdrCallAgentCount(
                $request,
                $request->startTime,
                $request->endTime
            )
        );
    }
    /**
     * @OA\Post(
     *     path="/cdr-call-count",
     *     summary="Get CDR Call Count with Duration",
     *     description="Returns the total number of calls, total duration, and average duration for a given route, type, and time range. Supports filtering by user ID or authenticated user's extension.",
     *     tags={"Reports"},
     *     security={{"Bearer":{}}},
     *     
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             required={"route", "type", "startTime", "endTime"},
     *             @OA\Property(
     *                 property="userId",
     *                 type="array",
     *                 description="Optional list of user IDs",
     *                 @OA\Items(type="integer", example=12)
     *             ),
     *             @OA\Property(
     *                 property="route",
     *                 type="string",
     *                 example="OUT",
     *                 description="Route type (IN or OUT)"
     *             ),
     *             @OA\Property(
     *                 property="type",
     *                 type="string",
     *                 example="dialer",
     *                 description="Call type (e.g., dialer, manual)"
     *             ),
     *             @OA\Property(
     *                 property="startTime",
     *                 type="string",
     *                 format="date-time",
     *                 example="2024-06-01 00:00:00",
     *                 description="Start datetime for filtering calls"
     *             ),
     *             @OA\Property(
     *                 property="endTime",
     *                 type="string",
     *                 format="date-time",
     *                 example="2024-06-24 23:59:59",
     *                 description="End datetime for filtering calls"
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Call statistics by route and type",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="calls", type="integer", example=120),
     *             @OA\Property(property="totalDuration", type="number", format="float", example=3600),
     *             @OA\Property(property="avgDuration", type="number", format="float", example=30.5)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=400,
     *         description="Invalid input data",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="error", type="string", example="Missing required fields or invalid format")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="error", type="string", example="Something went wrong")
     *         )
     *     )
     * )
     */

    public function cdrCallCount(Request $request)
    {
        $this->validate($request, [
            'route' => 'required|string',
            'type' => 'required|string',
            'startTime' => 'required|date_format:Y-m-d H:i:s',
            'endTime' => 'required|date_format:Y-m-d H:i:s'
        ]);
        $reportService = new ReportService($request->auth->parent_id);
        return $this->successResponse(
            "Call Count",
            $reportService->cdrCallCount(
                $request,
                $request->route,
                $request->type,
                $request->startTime,
                $request->endTime
            )
        );
    }
    /**
     * @OA\Post(
     *     path="/cdr-count-range-new",
     *     summary="Get CDR Call Counts by Time Range",
     *     description="Returns the count of IN and OUT calls grouped by route for each given time range. Filters optionally by user ID or authenticated user's extension.",
     *     tags={"Reports"},
     *     security={{"Bearer":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="range",
     *                 type="array",
     *                 description="Array of time ranges to evaluate call counts for",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="userId", type="integer", example=12, description="Optional user ID to filter by"),
     *                     @OA\Property(property="startTime", type="string", format="date-time", example="2024-06-01 00:00:00", description="Start datetime for the range"),
     *                     @OA\Property(property="endTime", type="string", format="date-time", example="2024-06-01 23:59:59", description="End datetime for the range")
     *                 )
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Call counts grouped by route for each time range",
     *         @OA\JsonContent(
     *             type="object",
     *             additionalProperties={
     *                 "type": "object",
     *                 "properties": {
     *                     "IN": { "type": "integer", "example": 23 },
     *                     "OUT": { "type": "integer", "example": 45 }
     *                 }
     *             },
     *             example={
     *                 "0": { "IN": 23, "OUT": 45 },
     *                 "1": { "IN": 12, "OUT": 31 }
     *             }
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=400,
     *         description="Invalid input or missing parameters",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="error", type="string", example="Missing start or end time in range")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="error", type="string", example="Unexpected error occurred")
     *         )
     *     )
     * )
     */
public function cdrCallsByRangeNew(Request $request)
    {
        $this->validate($request, [
            'range' => 'required|array',
            'range.*.startTime' => 'required|date_format:Y-m-d H:i:s',
            'range.*.endTime' => 'required|date_format:Y-m-d H:i:s',
        ]);

        $reportService = new ReportService($request->auth->parent_id);
        return $this->successResponse(
            "Call Count",
            $reportService->cdrCallsByRangeNew(
                $request,
                $request->range
            )
        );
    }


    public function cdrCallsByRange(Request $request)
    {
        $this->validate($request, [
            'range' => 'required|array',
            'range.*.startTime' => 'required|date_format:Y-m-d H:i:s',
            'range.*.endTime' => 'required|date_format:Y-m-d H:i:s',
        ]);

        $reportService = new ReportService($request->auth->parent_id);
        return $this->successResponse(
            "Call Count",
            $reportService->cdrCallsByRange(
                $request,
                $request->range
            )
        );
    }

    /**
     * @OA\Post(
     *     path="/extension-summary",
     *     summary="Get CDR summary by extension",
     *     description="Fetches extension-wise call summary including total/average duration and SMS counts for a given date range.",
     *     operationId="cdrExtensionSummary",
     *     tags={"Reports"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"startTime", "endTime"},
     *             @OA\Property(property="startTime", type="string", format="date-time", example="2025-06-01 00:00:00", description="Start time (Y-m-d H:i:s)"),
     *             @OA\Property(property="endTime", type="string", format="date-time", example="2025-06-01 23:59:59", description="End time (Y-m-d H:i:s)"),
     *             @OA\Property(property="userId", type="array", @OA\Items(type="integer"), example={101, 102}, description="Optional array of user IDs to filter by specific agents")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Summary returned",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Extension cdr summary"),
     *             @OA\Property(property="data", type="array", @OA\Items(
     *                 @OA\Property(property="id", type="integer", example=101),
     *                 @OA\Property(property="first_name", type="string", example="John"),
     *                 @OA\Property(property="last_name", type="string", example="Doe"),
     *                 @OA\Property(property="extension", type="string", example="1001"),
     *                 @OA\Property(property="calls", type="integer", example=45),
     *                 @OA\Property(property="totalDuration", type="number", example=3600),
     *                 @OA\Property(property="avgDuration", type="number", example=80),
     *                 @OA\Property(property="outgoing", type="integer", example=12),
     *                 @OA\Property(property="incoming", type="integer", example=15)
     *             ))
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation Error",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="The given data was invalid."),
     *             @OA\Property(property="errors", type="object",
     *                 @OA\Property(property="startTime", type="array", @OA\Items(type="string", example="The start time field is required.")),
     *                 @OA\Property(property="endTime", type="array", @OA\Items(type="string", example="The end time field is required."))
     *             )
     *         )
     *     )
     * )
     */

    public function cdrExtensionSummary(Request $request)
    {
        $this->validate($request, [
            'startTime' => 'required|date_format:Y-m-d H:i:s',
            'endTime' => 'required|date_format:Y-m-d H:i:s'
        ]);
        $reportService = new ReportService($request->auth->parent_id);
        return $this->successResponse(
            "Extension cdr summary",
            $reportService->cdrExtensionSummary($request, $request->startTime, $request->endTime)
        );
    }
    /**
     * @OA\Post(
     *     path="/voicemail-count",
     *     summary="Get Voicemail Count",
     *     description="Returns the count of read and unread voicemails for a specified date range and optional list of user IDs.",
     *     tags={"Voicemail"},
     *      security={{"Bearer":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="userId",
     *                 type="array",
     *                 @OA\Items(type="integer", example=12),
     *                 description="Optional list of user IDs"
     *             ),
     *             @OA\Property(
     *                 property="startTime",
     *                 type="string",
     *                 format="date-time",
     *                 example="2024-06-01 00:00:00",
     *                 description="Start datetime for filtering voicemail records"
     *             ),
     *             @OA\Property(
     *                 property="endTime",
     *                 type="string",
     *                 format="date-time",
     *                 example="2024-06-24 23:59:59",
     *                 description="End datetime for filtering voicemail records"
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Voicemail count (read/unread)",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="read", type="integer", example=7),
     *             @OA\Property(property="unread", type="integer", example=3)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=400,
     *         description="Invalid request input",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="error", type="string", example="Invalid user ID or date format")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="error", type="string", example="Something went wrong")
     *         )
     *     )
     * )
     */

    public function getVoicemailCount(Request $request)
    {
        $this->validate($request, [
            'startTime' => 'required|date_format:Y-m-d H:i:s',
            'endTime' => 'required|date_format:Y-m-d H:i:s'
        ]);
        $reportService = new ReportService($request->auth->parent_id);
        return $this->successResponse("Voicemails", $reportService->voicemailCount($request, $request->startTime, $request->endTime));
    }

    /**
     * @OA\Post(
     *     path="/voicemail-unread",
     *     summary="Get Unread Voicemails",
     *     description="Fetches the count or list of unread voicemails within a given date range.",
     *     operationId="getVoicemailUnread",
     *     tags={"Reports"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"startTime", "endTime"},
     *             @OA\Property(
     *                 property="startTime",
     *                 type="string",
     *                 format="date-time",
     *                 example="2025-06-01 00:00:00",
     *                 description="Start datetime in Y-m-d H:i:s format"
     *             ),
     *             @OA\Property(
     *                 property="endTime",
     *                 type="string",
     *                 format="date-time",
     *                 example="2025-06-01 23:59:59",
     *                 description="End datetime in Y-m-d H:i:s format"
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Unread voicemail summary",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Voicemails"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="unread_count", type="integer", example=5)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="The given data was invalid."),
     *             @OA\Property(property="errors", type="object",
     *                 @OA\Property(property="startTime", type="array", @OA\Items(type="string", example="The startTime field is required.")),
     *                 @OA\Property(property="endTime", type="array", @OA\Items(type="string", example="The endTime field is required."))
     *             )
     *         )
     *     )
     * )
     */

    public function getVoicemailUnread(Request $request)
    {
        $this->validate($request, [
            'startTime' => 'required|date_format:Y-m-d H:i:s',
            'endTime' => 'required|date_format:Y-m-d H:i:s'
        ]);
        $reportService = new ReportService($request->auth->parent_id);
        return $this->successResponse("Voicemails", $reportService->voicemailCount($request, $request->startTime, $request->endTime));
    }
    /**
     * @OA\Post(
     *     path="/sms-count",
     *     summary="Get SMS Count",
     *     description="Returns the count of incoming and outgoing SMS messages for a specific date range and optional list of user IDs.",
     *     tags={"SMS"},
     *     security={{"Bearer":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="userId",
     *                 type="array",
     *                 @OA\Items(type="integer", example=12),
     *                 description="Optional list of user IDs to filter by"
     *             ),
     *             @OA\Property(
     *                 property="startTime",
     *                 type="string",
     *                 format="date-time",
     *                 example="2024-06-01 00:00:00",
     *                 description="Start datetime for filtering SMS records"
     *             ),
     *             @OA\Property(
     *                 property="endTime",
     *                 type="string",
     *                 format="date-time",
     *                 example="2024-06-24 23:59:59",
     *                 description="End datetime for filtering SMS records"
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Successful response with SMS count",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="incoming", type="integer", example=20),
     *             @OA\Property(property="outgoing", type="integer", example=45)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=400,
     *         description="Invalid input parameters",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="error", type="string", example="Invalid user ID or date format")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="error", type="string", example="Something went wrong")
     *         )
     *     )
     * )
     */

    public function getSmsCount(Request $request)
    {
        $this->validate($request, [
            'startTime' => 'required|date_format:Y-m-d H:i:s',
            'endTime' => 'required|date_format:Y-m-d H:i:s'
        ]);
        $reportService = new ReportService($request->auth->parent_id);
        return $this->successResponse("Sms", $reportService->smsCount($request, $request->startTime, $request->endTime));
    }


    /**
     * Get CDR
     * @return type
     */

    /**
     * @OA\Post(
     *     path="/get-cdr_copy",
     *     summary="Fetch Call Detail Records (CDR) by phone number",
     *     tags={"Reports"},
     *    security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"phone_number", "extension", "alt_extension"},
     *             @OA\Property(property="phone_number", type="string", example="9876543210"),
     *             @OA\Property(property="extension", type="string", example="101"),
     *             @OA\Property(property="alt_extension", type="string", example="102")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful response with lead activity",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="leadData", type="object",
     *                 example={
     *                     "id": 12,
     *                     "name": "John Doe",
     *                     "email": "john@example.com"
     *                 }
     *             ),
     *             @OA\Property(property="updateData", type="array",
     *                 @OA\Items(type="object",
     *                     example={
     *                         "type": "call",
     *                         "extension": "101",
     *                         "duration_in_time_format": "00:03:20",
     *                         "start_time": "2025-06-16 09:30:00"
     *                     }
     *                 )
     *             ),
     *             @OA\Property(property="userData", type="array",
     *                 @OA\Items(type="object",
     *                     example={
     *                         "id": 5,
     *                         "name": "Agent Smith",
     *                         "extension": "101"
     *                     }
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation failed",
     *         @OA\JsonContent(example={
     *             "success": false,
     *             "message": "phone_number is required"
     *         })
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error",
     *         @OA\JsonContent(example={
     *             "success": false,
     *             "message": "Unexpected server error"
     *         })
     *     )
     * )
     */



    public function getCDR_copy()
    {
        $this->validate($this->request, [
            'phone_number'    => 'required|numeric',
        ]);
        $response = $this->model->getCDR_copy($this->request);
        return $this->successResponse("Lead Activity", $response);
    }
    public function getCDR()
    {
        $this->validate($this->request, [
            'phone_number'    => 'required|numeric',
        ]);
        $response = $this->model->getCDR($this->request);
        return $this->successResponse("Lead Activity", $response);
    }

    public function dailyCallReport(Request $request)
    {
        $this->validate($request, [
            'email' => 'required|email',
            'send' => 'sometimes|bool',
            'date' => 'required|date'
        ]);

        $clientId = $request->auth->parent_id;
        $email = $request->get("email");
        $send = $request->get("send", false);
        $date = $request->get("date", date("Y-m-d"));
        $forceNew = $request->get("forceNew", false);
        $connection = "mysql_$clientId";

        try {
            if (!$forceNew) {
                $reportLog = ReportLog::on($connection)->where([
                    ["report_name", "=", "daily-call-report"],
                    ["report_date", "=", $date],
                    ["sent_to_email", "=", $email],
                ])->get()->last();
            }

            if (empty($reportLog)) {
                $reportService = new ReportService($clientId);
                $data = $reportService->dailyCallReport($email);
                $view = "emails.DailyCallReport.v1";

                $reportLog = new ReportLog([
                    'report_name' => "daily-call-report",
                    'report_date' => Carbon::now(),
                    'sent_to_email' => $email,
                    'data' => $data,
                    'view_file' => $view,
                    'source' => "ReportController"
                ]);
                $reportLog->setConnection("mysql_$clientId");
                $reportLog->saveOrFail();
            }

            $data = $reportLog->data;
            $view = $reportLog->view_file;

            if ($send) {
                $smtpSetting = SmtpSetting::getBySenderType("mysql_$clientId", "system");
                $from = [
                    "address" => empty($smtpSetting->from_email) ? env('DEFAULT_EMAIL') : $smtpSetting->from_email,
                    "name" => empty($smtpSetting->from_name) ? env('DEFAULT_NAME') : $smtpSetting->from_name,
                ];
                #create initiate mailable class
                $mailable = new SystemNotificationMail($from, $view, "Daily Call Report", $data);

                $mailService = new MailService($request->get("clientId"), $mailable, $smtpSetting);
                $mailService->sendEmail($email);
            }

            return view($view)->with([
                "subject" => "Daily Call Report",
                "data" => $data,
            ]);
        } catch (\Throwable $throwable) {
            $context = buildContext($throwable);
            Log::error("MailController.sendDailyCallReport.error", $context);
            $emailBody = view('emails.errorNotification', compact('context'))->render();
            $genericMail = new GenericMail(
                "MailController.sendDailyCallReport.error",
                [
                    "address" => "rohit@cafmotel.com",
                    "name" => "DailyCallReportJob"
                ],
                $emailBody
            );
            $errorEmailSetting = new SmtpSetting([
                "mail_driver" => "SMTP",
                "mail_host" => env("ERROR_MAIL_HOST"),
                "mail_port" => env("ERROR_MAIL_PORT"),
                "mail_username" => env("ERROR_MAIL_USERNAME"),
                "mail_password" => env("ERROR_MAIL_PASSWORD"),
                "mail_encryption" => env("ERROR_MAIL_ENCRYPTION"),
                "sender_type" => "system"
            ]);
            $mailService = new MailService($clientId, $genericMail, $errorEmailSetting);
            $mailService->sendEmail(["abhi2112mca@gmail.com", "mailme@rohitwanchoo.com"]);
            return response($emailBody, 500);
        }
    }

    public function getDailyCallReportLogs(Request $request)
    {
        $this->validate($request, [
            'date' => 'sometimes|date'
        ]);
        $clientId = $request->auth->parent_id;
        $email = $request->auth->email;
        $date = $request->get("date", null);
        $connection = "mysql_$clientId";
        $where = [
            ["report_name", "=", "daily-call-report"]
        ];
        if ($request->auth->level < 9) {
            array_push($where, ["sent_to_email", "=", $email]);
        }
        if (!empty($date))
            array_push($where, ["report_date", "=", $date]);

        $reportLogs = ReportLog::on($connection)->where($where)->latest()->take(10)->orderByDesc("id")->get();

        $logs = [];
        foreach ($reportLogs as $reportLog) {
            $logs[] = $reportLog->toArray();
        }
        return $this->successResponse("Last 10 daily call report logs", $logs);
    }

    public function getDailyCallReportView(Request $request, int $logId)
    {
        $clientId = $request->auth->parent_id;
        $connection = "mysql_$clientId";
        $reportLog = ReportLog::on($connection)->findOrFail($logId);
        if ($request->auth->level < 9 && $reportLog->sent_to_email !== $request->auth->email) {
            throw new UnauthorizedHttpException("You are not authorized to view this report");
        }

        return view($reportLog->view_file)->with([
            "subject" => "Daily Call Report",
            "data" => $reportLog->data,
        ]);
    }

    public function getTimeZoneList()
    {
        $timezones = Timezone::on("master")->groupBy('timezone')->get()->all();
        return $this->successResponse("Timezone List", $timezones);
    }

    /* public function loginHistory()
    {
        $LoginLog = LoginLog::on("master")->get()->all();
        return $this->successResponse("Login Log List", $LoginLog);

    }*/
    /**
 * @OA\Post(
 *     path="/cdr-dashboard-summary",
 *     summary="Get summary of CDR dashboard data including call counts and agent info",
 *     tags={"Dashboard"},
 *     operationId="getCdrDashboardSummary",
 *    security={{"Bearer":{}}},
 *
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             required={"startTime", "endTime", "userId"},
 *             @OA\Property(property="startTime", type="string", format="date-time", example="2025-07-01 00:00:00"),
 *             @OA\Property(property="endTime", type="string", format="date-time", example="2025-07-01 23:59:59"),
 *             @OA\Property(property="userId", @OA\Items(type="integer"), type="array", example=123)
 *         )
 *     ),
 *
 *     @OA\Response(
 *         response=200,
 *         description="Success - Returns CDR dashboard summary",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="boolean", example=true),
 *             @OA\Property(property="message", type="string", example="CDR Dashboard Summary"),
 *             @OA\Property(
 *                 property="data",
 *                 type="object",
 *                 @OA\Property(property="getDispositionWiseCalls", type="array", @OA\Items(type="object")),
 *                 @OA\Property(property="outBoundDialer", type="integer", example=10),
 *                 @OA\Property(property="outBoundDialerC2C", type="integer", example=5),
 *                 @OA\Property(property="outBoundPredictive", type="integer", example=8),
 *                 @OA\Property(property="outBoundManual", type="integer", example=12),
 *                 @OA\Property(property="inBoundManual", type="integer", example=6),
 *                 @OA\Property(property="inBoundDialer", type="integer", example=7),
 *                 @OA\Property(property="loggedInAgent", type="integer", example=4)
 *             )
 *         )
 *     ),
 *
 *     @OA\Response(
 *         response=422,
 *         description="Validation Error",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="boolean", example=false),
 *             @OA\Property(property="message", type="string", example="The given data was invalid."),
 *             @OA\Property(property="errors", type="object")
 *         )
 *     ),
 *
 *     @OA\Response(
 *         response=500,
 *         description="Server Error",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="boolean", example=false),
 *             @OA\Property(property="message", type="string", example="Oops! Something went wrong.")
 *         )
 *     )
 * )
 */

public function getCdrDashboardSummary(Request $request)
{
    $this->validate($request, [
        'startTime' => 'required|date_format:Y-m-d H:i:s',
        'endTime' => 'required|date_format:Y-m-d H:i:s',
        'userId' => 'required|array'
    ]);

    $reportService = new ReportService($request->auth->parent_id);

    $startTime = $request->startTime;
    $endTime = $request->endTime;
    $userId = $request->userId;

    $data = [];
    $data['extListDashboard'] = $reportService->cdrExtensionSummary($request, $startTime, $endTime, $userId);

    // Disposition-wise calls
    $data['getDispositionWiseCalls'] = $reportService->dispositionSummary($request, $startTime, $endTime, $userId);

    // CDR Call Count
    $data['outBoundDialer'] = $reportService->cdrCallCount($request, 'OUT', 'dialer', $startTime, $endTime, $userId);
    $data['outBoundDialerC2C'] = $reportService->cdrCallCount($request, 'OUT', 'c2c', $startTime, $endTime, $userId);
    $data['outBoundPredictive'] = $reportService->cdrCallCount($request, 'OUT', 'predictive_dial', $startTime, $endTime, $userId);
    $data['outBoundManual'] = $reportService->cdrCallCount($request, 'OUT', 'manual', $startTime, $endTime, $userId);
    $data['inBoundManual'] = $reportService->cdrCallCount($request, 'IN', 'manual', $startTime, $endTime, $userId);
    $data['inBoundDialer'] = $reportService->cdrCallCount($request, 'IN', 'dialer', $startTime, $endTime, $userId);

    // Logged-in agent count
    $data['loggedInAgent'] = $reportService->cdrCallAgentCountNew($startTime, $endTime, $userId);
    $data['getStatewiseCalls'] = $reportService->stateWiseSummaryNew($startTime, $endTime, $userId);

    return $this->successResponse("CDR Dashboard Summary", $data);
}


}
