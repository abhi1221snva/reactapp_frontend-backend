<?php

namespace App\Http\Controllers;

use App\Jobs\LoadHopperJob;
use App\Model\Cron;
use App\Model\User;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CronController extends Controller
{

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    private $request;

    public function __construct(Request $request, Cron $cron)
    {
        $this->request = $request;
        $this->model = $cron;
    }

    /*
     * Add records to temp table
     * @return json
     */
    /**
     * @OA\Post(
     *     path="/add-lead-temp",
     *     summary="Add lead to campaign hopper (cron-triggered)",
     *     description="Adds a lead to the hopper if total leads in hopper are less than 30. Typically called by a cron job.",
     *     operationId="addLeadTemp",
     *     tags={"Cron"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"campaign_id", "id"},
     *             @OA\Property(property="campaign_id", type="integer", example=5, description="Campaign ID for which leads are being loaded"),
     *             @OA\Property(property="id", type="integer", example=123, description="Lead ID to be added to the campaign hopper")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Lead load request accepted",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Request accepted")
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Lead not added due to existing threshold",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Request declined due to minimum lead is 30")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="The given data was invalid."),
     *             @OA\Property(property="errors", type="object",
     *                 @OA\Property(property="campaign_id", type="array", @OA\Items(type="string", example="The campaign_id field is required.")),
     *                 @OA\Property(property="id", type="array", @OA\Items(type="string", example="The id field is required."))
     *             )
     *         )
     *     )
     * )
     */

    public function addLeadTemp(Request $request)
    {
        #Note: this is called from cron so clientId needs to be taken from input
        $this->validate($request, [
            'campaign_id' => 'required|numeric',
            'id' => 'required|numeric'
        ]);
        //$cron = new Cron();
        //$response = $cron->addLeadTemp($request->input('id'), $request->input('campaign_id'));
        //return response()->json($response);

        $getLead = $this->getTempLead($request->input('campaign_id'), $request->input('id'));
        $lead_tempcount = count($getLead);

        if ($lead_tempcount < 30) {
            dispatch(new LoadHopperJob($request->input('id'), $request->input('campaign_id')))->onConnection("database");
            return response("Request accepted", 201);
        } else {
            return response("Request declined due to minimum lead is 30 ", 401);
        }
    }

    public function getTempLead($campaignId, $parentId)
    {
        $leadTemp = DB::connection("mysql_" . $parentId)->select("SELECT * FROM lead_temp WHERE campaign_id = :campaign_id", array('campaign_id' => $campaignId));
        $leadTemp = (array)$leadTemp;
        return $leadTemp;
    }

    public function cronEmail(Request $request)
    {
        $this->validate($request, [
            'email' => 'required|email',
            'clientId' => 'required|int'
        ]);
        $reportService = new ReportService($request->get("clientId"));
        $data = $reportService->dailyCallReport($request->get("email"));
        return view("emails.DailyCallReport.v1")->with([
            "subject" => "Daily Call Report",
            "data" => $data,
        ]);
    }
}
