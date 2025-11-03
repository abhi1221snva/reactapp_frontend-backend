<?php

namespace App\Http\Controllers;

use App\Mail\GenericMail;
use App\Mail\SystemNotificationMail;
use App\Model\Client\ReportLog;
use App\Model\Client\Dtmf;
use App\Model\Client\Campaign;
use App\Model\IvrMenu;



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

class Press1CampaignReportController extends Controller
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



    public function getIvrLogs()
    {
        $this->validate($this->request, [

            'lower_limit' => 'numeric',
            'upper_limit' => 'numeric',
        ]);
        $response = $this->model->getIvrLogs($this->request);
        return response()->json($response);
    }

    /*
     * Fetch call data report
     * @return json
     */
    /**
     * @OA\Post(
     *     path="/report-press1-campaign",
     *     tags={"Reports"},
     *     security={{"Bearer":{}}},
     *     summary="Get Press 1 Campaign Call Data Report",
     *     description="Fetches a call data report for Press 1 IVR campaigns based on filters like number, DTMF, campaign, date range, etc.",
     *      @OA\Parameter(
     *         name="number",
     *         in="query",
     *         description="Phone number to search for (partial match)",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="dtmf",
     *         in="query",
     *         description="DTMF input pressed by the user",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="campaign",
     *         in="query",
     *         description="Campaign ID to filter by",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="route",
     *         in="query",
     *         description="Call route to filter by",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="type",
     *         in="query",
     *         description="Call type to filter by",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="start_date",
     *         in="query",
     *         description="Start date (YYYY-MM-DD) for filtering by call date",
     *         required=false,
     *         @OA\Schema(type="string", format="date")
     *     ),
     *     @OA\Parameter(
     *         name="end_date",
     *         in="query",
     *         description="End date (YYYY-MM-DD) for filtering by call date",
     *         required=false,
     *         @OA\Schema(type="string", format="date")
     *     ),
     *     @OA\Parameter(
     *         name="lower_limit",
     *         in="query",
     *         description="Pagination offset (starting record index)",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="upper_limit",
     *         in="query",
     *         description="Number of records to fetch",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     * 
     *     @OA\Response(
     *         response=200,
     *         description="Successful Call Data Report",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Call Data Report."),
     *             @OA\Property(property="record_count", type="integer", example=15),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="cli", type="string", example="9876543210"),
     *                     @OA\Property(property="number", type="string", example="9876543210"),
     *                     @OA\Property(property="lead_id", type="integer", example=1122),
     *                     @OA\Property(property="route", type="string", example="promo"),
     *                     @OA\Property(property="campaign_id", type="integer", example=101),
     *                     @OA\Property(property="created_at", type="string", format="date-time", example="2024-06-01 12:00:00"),
     *                     @OA\Property(property="dtmf", type="string", example="1 - Sales")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="No Call Data Report found.",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="No Call Data Report found."),
     *             @OA\Property(property="record_count", type="integer", example=0),
     *             @OA\Property(property="data", type="array", @OA\Items())
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Call Data Report doesn't exist.")
     *         )
     *     )
     * )
     */

    public function getReport()
    {
        $this->validate($this->request, [
            'number' => 'numeric',
            'campaign' => 'numeric',
            'disposition' => 'numeric',
            'type' => 'string',
            'start_date' => 'date',
            'end_date' => 'date',
            'start' => 'numeric',
            'limit' => 'numeric'
        ]);
        $response = $this->model->getReportPress1Campaign($this->request);
        return response()->json($response);
    }

    public function dtmfList()
    {
        $dtmfList = Dtmf::where('is_deleted', '0')->get()->all();
        return $this->successResponse("dtmf List", $dtmfList);
    }


  public function allDtmf(Request $request)
{
    $clientId = $request->auth->parent_id;
    $dtmf_list = []; // ensure it's always defined

    try {
        $campaign = Campaign::on("mysql_$clientId")->findOrFail($request->campaign_id);

        if ($campaign->redirect_to == 5 && !empty($campaign->redirect_to_dropdown)) {

            $ivr_id = $campaign->redirect_to_dropdown;

            $dtmf_list = IvrMenu::on("mysql_$clientId")
                ->where('ivr_table_id', $ivr_id)
                ->get()
                ->toArray();

            return $this->successResponse("DTMF List", $dtmf_list);
        }

        // ✅ case when not IVR
        return response()->json([
            'success' => false,
            'message' => 'This campaign is not redirected to IVR',
            'errors' => [
                'redirect_to' => $campaign->redirect_to,
                'dtmf_list' => []
            ]
        ], 400);

    } catch (ModelNotFoundException $e) {
        return response()->json([
            'success' => false,
            'message' => 'Campaign Not Found',
            'errors' => [
                'campaign_id' => $request->campaign_id
            ]
        ], 404);

    } catch (\Throwable $e) {
        return response()->json([
            'success' => false,
            'message' => 'Failed to find Dtmf',
            'errors' => [$e->getMessage()]
        ], 500);
    }
}

}
