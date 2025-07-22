<?php

namespace App\Http\Controllers;

use App\Model\Callback;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Model\User;

class CallBackController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    private $request;

    public function __construct(Request $request, Callback $callback)
    {
        $this->request = $request;
        $this->model = $callback;
    }

    /*
     * Fetch call data report
     * @return json
     */

/**
 * @OA\Post(
 *     path="/callback",
 *     summary="Get Callback Data Report",
 *     description="Fetches callback data with optional filters including extension, campaign, date range, and reminder. Returns callback details with associated lead data, list headers, and selected columns.",
 *     tags={"Callback"},
 *      security={{"Bearer": {}}},
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             type="object",
 *             @OA\Property(property="id", type="integer", example=1, description="Callback ID (required)"),
 *             @OA\Property(property="extension", type="string", example="1010", description="Filter by agent extension"),
 *             @OA\Property(property="campaign", type="integer", example=23, description="Filter by campaign ID"),
 *             @OA\Property(property="start_date", type="string", format="date-time", example="2023-04-03 00:00:00", description="Start datetime in user's timezone"),
 *             @OA\Property(property="end_date", type="string", format="date-time", example="2023-04-03 23:59:59", description="End datetime in user's timezone"),
 *             @OA\Property(property="reminder", type="boolean", example=true, description="Include callbacks by group extensions")
 *         )
 *     ),
 *
 *     @OA\Response(
 *         response=200,
 *         description="Callback data response (found or not found)",
 *         @OA\JsonContent(
 *             type="object",
 *             @OA\Property(property="success", type="string", example="true"),
 *             @OA\Property(property="message", type="string", example="Callback Data Report."),
 *             @OA\Property(property="record_count", type="integer", example=5, nullable=true),
 *             @OA\Property(
 *                 property="data",
 *                 type="array",
 *                 @OA\Items(type="object")
 *             )
 *         )
 *     ),
 *
 *     @OA\Response(
 *         response=400,
 *         description="Invalid request or missing parameters",
 *         @OA\JsonContent(
 *             type="object",
 *             @OA\Property(property="success", type="string", example="false"),
 *             @OA\Property(property="message", type="string", example="Callback Data Report doesn't exist.")
 *         )
 *     ),
 *
 *     @OA\Response(
 *         response=500,
 *         description="Internal server error",
 *         @OA\JsonContent(
 *             type="object",
 *             @OA\Property(property="success", type="string", example="false"),
 *             @OA\Property(property="message", type="string", example="An unexpected error occurred.")
 *         )
 *     )
 * )
 */


    public function getCallBack()
    {
        $this->validate($this->request, [

            'campaign' => 'numeric',
            'start_date' => 'date',
            'end_date' => 'date',
            'lower_limit' => 'numeric',
            'upper_limit' => 'numeric'
        ]);
        $response = $this->model->getCallBack($this->request);
        return response()->json($response);
    }

    public function editCallback(Request $request)
    {
        $this->validate($request, [
            'converted_to_utc' => 'required|date',
            'callback_identifier' => 'required|string'
        ]);

        try {
            $callbackIdentifier = explode("-",$request->get('callback_identifier'));
            $callbackTime = $request->get("converted_to_utc");
            $markAsCalled = $request->get("mark_as_called");
            $reassignCallback = $request->get("reassign_callback");

            if($markAsCalled == '#' || $markAsCalled > 2){
                $markAsCalled = null;
            }

            if($reassignCallback){
                DB::connection('mysql_'.$request->auth->parent_id)->statement('UPDATE callback SET mark_as_called="'.$markAsCalled.'", callback_time="'.$callbackTime.'", extension="'.$reassignCallback.'" WHERE cdr_id="'.$callbackIdentifier[0].'"  AND extension="'.$callbackIdentifier[1].'"  AND campaign_id="'.$callbackIdentifier[2].'"  AND lead_id="'.$callbackIdentifier[3].'" ');
            } else{
                DB::connection('mysql_'.$request->auth->parent_id)->statement('UPDATE callback SET mark_as_called="'.$markAsCalled.'", callback_time="'.$callbackTime.'" WHERE cdr_id="'.$callbackIdentifier[0].'"  AND extension="'.$callbackIdentifier[1].'"  AND campaign_id="'.$callbackIdentifier[2].'"  AND lead_id="'.$callbackIdentifier[3].'" ');
            }


            return $this->successResponse("Callback Saved", []);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to load cart items", [], $exception);
        }
    }

    public function stopReminder(Request $request){
        try{
            $objUser = User::find($request->auth->id);
            $objUser->preferences = json_encode(["callback_reminder" => "off"]);
            $objUser->saveOrFail();

            return $this->successResponse("Reminder Preferences Recorded", []);

        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update Reminder preferences", [$exception->getMessage()], $exception);
        }
    }

    public function showReminder(Request $request){
        try{
            $objUser = User::find($request->auth->id);
                $objUser->preferences = json_encode(["callback_reminder" => "on"]);
                $objUser->saveOrFail();

            return $this->successResponse("Reminder Preferences Recorded", []);

        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update Reminder preferences", [$exception->getMessage()], $exception);
        }
    }

    public function getReminderStatus(Request $request){

        try{
            $objUser = User::find($request->auth->id)->toArray();
            return $this->successResponse("Callback Reminder status", [$objUser['preferences']]);

        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to get status", [$exception->getMessage()], $exception);
        }
    }
}
