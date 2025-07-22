<?php

namespace App\Http\Controllers;

use App\Model\Fax;
use App\Model\Dids;

use App\Model\Client\FaxDid;
use Illuminate\Http\Request;

class FaxController extends Controller
{

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    private $request;
    private $model;

    public function __construct(Request $request, Fax $fax)
    {
        $this->request = $request;
        $this->model = $fax;
    }

    /*
     * Fetch excludeNumber details
     * @return json
     */

    /**
     * @OA\Post(
     *     path="/fax/{id}",
     *     summary="Get Fax Record",
     *     description="Fetches a fax record by ID from the client-specific database.",
     *     tags={"Fax"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Fax record ID",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Fax record retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Fax record"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="subject", type="string", example="Test Fax"),
     *                 @OA\Property(property="file_path", type="string", example="/storage/faxes/fax_1.pdf"),
     *                 @OA\Property(property="status", type="string", example="sent"),
     *                 @OA\Property(property="created_at", type="string", example="2025-04-25 13:45:00"),
     *                 @OA\Property(property="updated_at", type="string", example="2025-04-25 13:45:00")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Fax record not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="fax record not found"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string", example="Invalid fax id 1"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="fax not Found"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string", example="Database connection failed"))
     *         )
     *     )
     * )
     */

    public function getFaxPdf(Request $request, int $id)
    {
        try {
            $fax = Fax::on("mysql_" . $request->auth->parent_id)->findOrFail($id);
            return $this->successResponse("Fax record", $fax->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("fax record not found", ["Invalid fax id $id"], $exception, 404);
        } catch (\Exception $exception) {
            return $this->failResponse("fax not Found", [$exception->getMessage()], $exception, 500);
        }
    }




    /**
     * @OA\Post(
     *     path="/fax",
     *     summary="Get Fax List",
     *     description="Fetches a list of received fax records for the authenticated user's extension.",
     *     tags={"Fax"},
     *     security={{"Bearer":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Fax list retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Fax List"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="extension", type="string", example="101"),
     *                     @OA\Property(property="callerid", type="string", example="1234567890"),
     *                     @OA\Property(property="file_path", type="string", example="/storage/faxes/fax_1.pdf"),
     *                     @OA\Property(property="faxstatus", type="string", example="1"),
     *                     @OA\Property(property="start_time", type="string", example="2025-04-25 14:00:00"),
     *                     @OA\Property(property="created_at", type="string", example="2025-04-25 14:01:00"),
     *                     @OA\Property(property="updated_at", type="string", example="2025-04-25 14:01:00")
     *                 )
     *             )
     *         )
     *     )
     * )
     */

    public function getFax(Request $request)
    {
        // $dids = FaxDid::on("mysql_" . $request->auth->parent_id)->where('userId', $request->auth->id)->select('did')->pluck('did')->all();

        // //$dids = Dids::on("mysql_" . $request->auth->parent_id)->where('sms_email',$request->auth->id)->select('cli')->pluck('cli')->all();
        // if (empty($dids)) {
        //     return $this->successResponse("Did Not Find", $dids);
        // }

        //$fax = Fax::on("mysql_" . $request->auth->parent_id)->where('faxstatus','1')->whereIn('callerid',$dids)->orderBy('start_time','DESC')->get()->all();

        $fax = Fax::on("mysql_" . $request->auth->parent_id)->where([['extension', '=', $request->auth->extension], ['faxstatus', '=', '1']])->orderBy('id', 'DESC')->get()->all();


        return $this->successResponse("Fax List", $fax);
    }

    /*public function getFax() {
        $response = $this->model->faxDetails($this->request);
        return response()->json($response);
    }*/

    /*
     * Update Exclude Number detail
     * @return json
     */

    public function editExcludeNumber()
    {
        $this->validate($this->request, [
            'number' => 'required|numeric|regex:/^([0-9\s\-\+\(\)]*)$/|min:10',
            'campaign_id' => 'required|numeric',
            'first_name' => 'string',
            'last_name' => 'string',
            'company_name' => 'string',
            'id' => 'required|numeric'
        ]);
        $response = $this->model->excludeNumberUpdate($this->request);
        return response()->json($response);
    }

    /*
     * Add Exclude Number details
     * @return json
     */

    public function addExcludeNumber()
    {
        $this->validate($this->request, [
            'number' => 'required|numeric|regex:/^([0-9\s\-\+\(\)]*)$/|min:10',
            'campaign_id' => 'required|numeric',
            'first_name' => 'string',
            'last_name' => 'string',
            'company_name' => 'string',
            'id' => 'required|numeric'
        ]);
        $response = $this->model->addExcludeNumber($this->request);
        return response()->json($response);
    }

    /*
     * Delete excludeNumber
     * @return json
     */

    public function excludeNumberDelete()
    {
        $this->validate($this->request, [
            'number' => 'required|numeric|regex:/^([0-9\s\-\+\(\)]*)$/|min:10',
            'campaign_id' => 'required|numeric',
            'id' => 'required|numeric'
        ]);
        $response = $this->model->excludeNumberDelete($this->request);
        return response()->json($response);
    }

    /*
     * Fetch fax form didforsale
     * @return json
     */

    /**
     * @OA\Post(
     *     path="/receiver-fax",
     *     summary="Receive and process a fax",
     *     description="Receives an incoming fax, stores it in the database, bills the client if needed, sends notifications and email.",
     *     operationId="receiverFax",
     *     tags={"Fax"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"dialednumber", "callerid", "faxurl", "faxstatus", "numofpages", "received"},
     *             @OA\Property(property="dialednumber", type="string", example="15963255"),
     *             @OA\Property(property="callerid", type="string", example="12365488"),
     *             @OA\Property(property="faxurl", type="string", example="https://example.com/fax/123456789.pdf"),
     *             @OA\Property(property="faxstatus", type="string", example="COMPLETE", description="Fax status e.g. COMPLETE, FAILED"),
     *             @OA\Property(property="numofpages", type="string", example="5"),
     *             @OA\Property(property="received", type="string", example="2025-06-17 10:00:00")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Fax processed successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="New Fax detail saved")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Error processing the fax",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to save receive fax detail."),
     *             @OA\Property(property="code", type="integer", example=500),
     *             @OA\Property(property="dialednumber", type="string", example="15963255"),
     *             @OA\Property(property="line", type="integer", example=234),
     *             @OA\Property(property="count", type="integer", example=0)
     *         )
     *     )
     * )
     */

    public function receiverFax()
    {
        $faxModel = new Fax();
        $response = $faxModel->receiverFax($this->request);
        return response()->json($response);
    }




    /**
     * @OA\Post(
     *      path="/send-fax",
     *      summary="Send fax",
     *      operationId="sendFax",
     *      tags={"Fax"},
     *      security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         @OA\MediaType(
     *             mediaType="application/json",
     *             @OA\Schema(
     *                 @OA\Property(
     *                     property="callid",
     *                     type="integer"
     *                 ),
     *                 @OA\Property(
     *                     property="dialednumber",
     *                     type="string"
     *                 ),
     *                 @OA\Property(
     *                     property="faxurl",
     *                     type="string"
     *                 ),
     *                 example={"callid": "xxxxxx", "dialednumber": "xxxxxx", "faxurl": "https://"}
     *             )
     *         )
     *     ),
     *      @OA\Response(
     *          response="200",
     *          description="Fax sent"
     *      )
     * )
     */
    public function sendFax()
    {
        $this->validate($this->request, [
            'callid' => 'required|numeric',
            'dialednumber' => 'required|numeric',
            'faxurl' => 'required'
        ]);
        return $response = $this->model->sendFax($this->request);
    }

    /*public function receiveFaxList() {
        $this->validate($this->request, [
            'fax_type' => 'required|numeric'
        ]);
        return $response = $this->model->receiveFaxList($this->request);
    }*/


    //for inbox fax

    /**
     * @OA\Post(
     *     path="/receive-fax-list",
     *     summary="Get Received Fax List",
     *     description="Fetches a list of received fax records filtered by the user's DIDs and extension.",
     *     tags={"Fax"},
     *     security={{"Bearer":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="List of received faxes",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Fax List"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=10),
     *                     @OA\Property(property="extension", type="string", example="101"),
     *                     @OA\Property(property="callerid", type="string", example="1234567890"),
     *                     @OA\Property(property="dialednumber", type="string", example="9876543210"),
     *                     @OA\Property(property="faxstatus", type="string", example="COMPLETE"),
     *                     @OA\Property(property="file_path", type="string", example="/storage/faxes/inbound_10.pdf"),
     *                     @OA\Property(property="start_time", type="string", example="2025-04-25 14:00:00"),
     *                     @OA\Property(property="created_at", type="string", example="2025-04-25 14:02:00"),
     *                     @OA\Property(property="updated_at", type="string", example="2025-04-25 14:02:00")
     *                 )
     *             )
     *         )
     *     )
     * )
     */

    public function receiveFaxList(Request $request)
    {
        $dids = FaxDid::on("mysql_" . $request->auth->parent_id)->where('userId', $request->auth->id)->select('did')->pluck('did')->all();

        if (empty($dids)) {
            return $this->successResponse("Did Not Find", $dids);
        }
        $fax = Fax::on("mysql_" . $request->auth->parent_id)->where([['faxstatus', '=', 'COMPLETE'], ['extension', '=', $request->auth->extension]])->whereIn('dialednumber', $dids)->orderBy('id', 'DESC')->get()->all();
        return $this->successResponse("Fax List", $fax);
    }

    /**
     * Get Unread Fax Count
     * @param Request $request
     * @return type
     */
    /**
     * @OA\post(
     *     path="/get-unread-fax-count",
     *     summary="Get Unread Fax Count",
     *     description="Returns the number of unread faxes for the authenticated user's extension and associated DIDs.",
     *     tags={"Fax"},
     *     security={{"Bearer":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Unread fax count",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Unread Fax Count"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="unreadFaxCount", type="integer", example=5)
     *             )
     *         )
     *     )
     * )
     */
    public function getUnreadFaxCount(Request $request)
    {
        $dids = FaxDid::on("mysql_" . $request->auth->parent_id)->where('userId', $request->auth->id)->select('did')->pluck('did')->all();

        if (empty($dids)) {
            return $this->successResponse("Did Not Find", $dids);
        }
        $fax = Fax::on("mysql_" . $request->auth->parent_id)->where(['faxstatus' => '1', 'extension' => $request->auth->extension, "fax_type" => '1'])
            ->whereIn('dialednumber', $dids)->count();
        return $this->successResponse("Unread Fax Count", ['unreadFaxCount' => $fax]);
    }
}
