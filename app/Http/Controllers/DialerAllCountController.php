<?php

namespace App\Http\Controllers;

use App\Model\Client\EmailTemplete;
use App\Model\Client\ListData;
use App\Model\Client\ListHeader;
use App\Model\Client\Label;
use App\Model\Client\CustomFieldLabelsValues;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

use App\Mail\GenericMail;
use App\Mail\SystemNotificationMail;
use App\Model\Client\ReportLog;
use App\Model\Client\SmtpSetting;
use App\Model\Client\SystemNotification;
use App\Model\User;
use App\Services\MailService;
use App\Services\ReportService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use App\Services\SmsService;


class DialerAllCountController extends Controller
{

    /**
     * @OA\Post(
     *     path="/dialer-all-count",
     *     summary="Get dialer call statistics",
     *     description="Returns total dialer/c2c/desktop call stats (count and duration) for all agents under a given parentId (client).",
     *     operationId="dialerAllCount",
     *     tags={"Dialer"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"parentId", "start_date", "end_date"},
     *             @OA\Property(property="parentId", type="integer", example=3, description="Parent/client ID to identify DB connection"),
     *             @OA\Property(property="start_date", type="string", format="date", example="2024-06-01", description="Start date for reporting range"),
     *             @OA\Property(property="end_date", type="string", format="date", example="2024-06-01", description="End date for reporting range")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful response with call stats",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Dialer Count List"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="start_time", type="string", example="2024-06-01 00:00:00"),
     *                 @OA\Property(property="end_time", type="string", example="2024-06-01 23:59:59"),
     *                 @OA\Property(property="company_name", type="string", example="ACME Corp"),
     *                 @OA\Property(property="agent", type="array", @OA\Items(
     *                     @OA\Property(property="extension", type="string", example="1001"),
     *                     @OA\Property(property="agentName", type="string", example="John Doe"),
     *                     @OA\Property(property="dialer_call", type="integer", example=15),
     *                     @OA\Property(property="c2c_call", type="integer", example=5),
     *                     @OA\Property(property="desktop_call", type="integer", example=8),
     *                     @OA\Property(property="dialer_call_time_spent_in_second", type="integer", example=900),
     *                     @OA\Property(property="c2c_call_time_spent_in_second", type="integer", example=300),
     *                     @OA\Property(property="desktop_call_time_spent_in_second", type="integer", example=400),
     *                     @OA\Property(property="totalcalls", type="integer", example=28)
     *                 ))
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad Request or Missing Input",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Invalid input"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string"))
     *         )
     *     )
     * )
     */

    public function index(Request $request)
    {
        //return $request->all();

        $parentId = $request->parentId;
        $reportService = new ReportService($parentId);

        //$email = 'abhi2112mca@gmail.com';

        $data = $reportService->dialerAllCount($request);

        return $this->successResponse("Dialer Count List", $data);


        //  echo "<pre>";print_r($data);die;


    }


    /**
     * @OA\Post(
     *     path="/dialer-all-count-crm",
     *     summary="Get agent-wise dialer call statistics",
     *     description="Returns count and duration of calls (dialer, click-to-call, desktop) per agent for the given client and date range.",
     *     tags={"Dialer"},
     *     operationId="dialerAllCountCRM",
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"api_key", "start_date", "end_date"},
     *             @OA\Property(property="api_key", type="string", example="xyz123", description="Client's API key for identifying DB connection"),
     *             @OA\Property(property="start_date", type="string", format="date", example="2024-06-01", description="Start date of reporting range"),
     *             @OA\Property(property="end_date", type="string", format="date", example="2024-06-01", description="End date of reporting range")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful response with call stats",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Dialer Count List"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="company_name", type="string", example="ACME Corp"),
     *                 @OA\Property(property="start_time", type="string", example="2024-06-01 00:00:00"),
     *                 @OA\Property(property="end_time", type="string", example="2024-06-01 23:59:59"),
     *                 @OA\Property(property="agent", type="array", @OA\Items(
     *                     @OA\Property(property="extension", type="string", example="1001"),
     *                     @OA\Property(property="agentName", type="string", example="John Doe"),
     *                     @OA\Property(property="dialer_call", type="integer", example=10),
     *                     @OA\Property(property="c2c_call", type="integer", example=5),
     *                     @OA\Property(property="desktop_call", type="integer", example=3),
     *                     @OA\Property(property="dialer_call_time_spent_in_second", type="integer", example=1800),
     *                     @OA\Property(property="c2c_call_time_spent_in_second", type="integer", example=600),
     *                     @OA\Property(property="desktop_call_time_spent_in_second", type="integer", example=900),
     *                     @OA\Property(property="totalcalls", type="integer", example=18)
     *                 ))
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid input",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Validation failed"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string"))
     *         )
     *     )
     * )
     */

    public function indexCrm(Request $request)
    {
        $parentId = $request->auth->parent_id;
        $reportService = new ReportService($parentId);

        $data = $reportService->dialerAllCountCRM($request);
        return $this->successResponse("Dialer Count List", $data);
    }
}
