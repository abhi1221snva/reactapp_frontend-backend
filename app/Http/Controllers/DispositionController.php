<?php

namespace App\Http\Controllers;

use App\Model\Disposition;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class DispositionController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    private $request;
    public function __construct(Request $request, Disposition $disposition)
    {
        $this->request = $request;
        $this->model = $disposition;
    }
    /*
     * Fetch Disposition details
     * @return json
     */

    /**
     * @OA\Post(
     * path="/disposition",
     * summary="List Disposition",
     * tags={"Disposition"},
     * security={{"Bearer":{}}},
     * @OA\RequestBody(
     * required=false,
     * @OA\JsonContent(
     * type="object",
     * @OA\Property(
     * property="disposition_id",
     * type="integer",
     * example=1,
     * description="ID of the disposition to fetch (optional, for single detail)"
     * ),
     * @OA\Property(
     * property="start",
     * type="integer",
     * default=0,
     * description="Start index for pagination"
     * ),
     * @OA\Property(
     * property="limit",
     * type="integer",
     * default=10,
     * description="Limit number of records returned"
     * )
     * )
     * ),
     * @OA\Response(
     * response="200",
     * description="Dispositions data",
     * @OA\JsonContent(
     * @OA\Property(property="success", type="string", example="true"),
     * @OA\Property(property="message", type="string", example="Dispositions detail."),
     * @OA\Property(
     * property="data",
     * type="array",
     * @OA\Items(
     * @OA\Property(property="id", type="integer", example=1),
     * @OA\Property(property="title", type="string", example="Answered"),
     * @OA\Property(property="is_deleted", type="integer", example=0)
     * )
     * ),
     * @OA\Property(property="total", type="integer", example=100)
     * )
     * ),
     * @OA\Response(
     * response="404",
     * description="Record not found",
     * @OA\JsonContent(
     * @OA\Property(property="success", type="string", example="true"),
     * @OA\Property(property="message", type="string", example="Record not found."),
     * @OA\Property(property="data", type="array", @OA\Items(type="object"))
     * )
     * ),
     * @OA\Response(
     * response="500",
     * description="Server error",
     * @OA\JsonContent(
     * @OA\Property(property="success", type="string", example="false"),
     * @OA\Property(property="message", type="string", example="An internal server error occurred."),
     * @OA\Property(property="data", type="array", @OA\Items(type="object"))
     * )
     * )
     * )
     */
    public function getDisposition()
    {
        $this->validate($this->request, [
            'disposition_id'    => 'numeric',
        ]);
        $response = $this->model->dispositionDetail($this->request);
        return response()->json($response);
    }
    /*
 * Update Dispositions detail
 * @return json
 */
   /**
 * @OA\Post(
 *     path="/edit-disposition",
 *     summary="Update an existing disposition",
 *     tags={"Disposition"},
 *     security={{"Bearer":{}}},
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             required={"disposition_id"},
 *             @OA\Property(property="disposition_id", type="integer", example=115, description="ID of the disposition to update"),
 *             @OA\Property(property="title", type="string", example="Busy"),
 *             @OA\Property(property="d_type", type="string", example="1"),
 *             @OA\Property(property="enable_sms", type="integer", example=1),
 *             @OA\Property(property="is_deleted", type="integer", example=0)
 *         )
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Disposition updated successfully",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="string", example="true"),
 *             @OA\Property(property="message", type="string", example="Dispositions updated successfully.")
 *         )
 *     ),
 *     @OA\Response(
 *         response=400,
 *         description="Bad request or missing fields"
 *     )
 * )
 */



    public function updateDisposition()
    {
        $this->validate($this->request, [
            'title'             => 'string|max:255',
            'd_type'        => 'numeric',
            'enable_sms'       => 'numeric',
            'disposition_id'    => 'required|numeric',
            // 'id'                => 'required|numeric'
        ]);
        $response = $this->model->dispositionUpdate($this->request);
               if ($response instanceof JsonResponse) {
        return $response;
    }
        return response()->json($response);
    }
    /*
     *Add Disposition details
     *@return json
     */
    
/**
 * @OA\Post(
 *     path="/add-disposition",
 *     summary="Add a new disposition",
 *     tags={"Disposition"},
 *     security={{"Bearer":{}}},
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             required={"title", "d_type", "enable_sms"},
 *             @OA\Property(property="title", type="string", example="Busy"),
 *             @OA\Property(property="d_type", type="string", example="1"),
 *             @OA\Property(property="enable_sms", type="integer", example=1)
 *         )
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Disposition added successfully",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="string", example="true"),
 *             @OA\Property(property="message", type="string", example="Dispositions added successfully."),
 *             @OA\Property(property="data", type="object",
 *                 @OA\Property(property="id", type="integer", example=115),
 *                 @OA\Property(property="title", type="string", example="Busy"),
 *                 @OA\Property(property="d_type", type="string", example="1"),
 *                 @OA\Property(property="enable_sms", type="integer", example=1),
 *                 @OA\Property(property="is_deleted", type="integer", example=0)
 *             )
 *         )
 *     ),
 *     @OA\Response(
 *         response=400,
 *         description="Bad request or missing fields"
 *     )
 * )
 */

    public function addDisposition()
    {
        $this->validate($this->request, [
            'title'     => 'required|string|max:255',
        ]);
        $response = $this->model->addDisposition($this->request);
            if ($response instanceof JsonResponse) {
        return $response;
    }

    // Otherwise, convert array to JSON
    return response()->json($response);
    }
    /*
     *Fetch Campaign Disposition Detail
     *@return json
     */
    /**
 * @OA\Post(
 *     path="/campaign-disposition",
 *     summary="Get dispositions for a specific campaign",
 *     description="Fetches the list of dispositions associated with a given campaign ID.",
 *     tags={"Campaign"},
 *     security={{"Bearer":{}}},
 *     @OA\Parameter(
 *         name="campaign_id",
 *         in="query",
 *         required=true,
 *         description="ID of the campaign to fetch dispositions for",
 *         @OA\Schema(type="integer", example=101)
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Dispositions detail",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="string", example="true"),
 *             @OA\Property(property="message", type="string", example="Dispositions detail."),
 *             @OA\Property(
 *                 property="data",
 *                 type="array",
 *                 @OA\Items(
 *                     type="object",
 *                     @OA\Property(property="id", type="integer", example=12),
 *                     @OA\Property(property="campaign_id", type="integer", example=101),
 *                     @OA\Property(property="disposition_id", type="integer", example=3),
 *                     @OA\Property(property="is_deleted", type="integer", example=0),
 *                     @OA\Property(property="title", type="string", example="Follow-up Needed")
 *                 )
 *             )
 *         )
 *     ),
 *     @OA\Response(
 *         response=404,
 *         description="Campaign is not associated to any dispositions",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="string", example="false"),
 *             @OA\Property(property="message", type="string", example="Campaign is not associated to any dispositions."),
 *             @OA\Property(property="data", type="array", @OA\Items(type="string"))
 *         )
 *     ),
 *     @OA\Response(
 *         response=500,
 *         description="Internal server error",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="string", example="false"),
 *             @OA\Property(property="message", type="string", example="Server error occurred.")
 *         )
 *     )
 * )
 */

    public function getCampaignDisposition()
    {
        $this->validate($this->request, [
            'campaign_id' => 'required|numeric'
        ]);
        $response = $this->model->getCampaignDisposition($this->request);
        return response()->json($response);
    }
    /*
     *Edit Campaign Disposition Detail
     *@return json
     */
    public function editCampaignDisposition()
    {
        $this->validate($this->request, [
            'campaign_id'   => 'required|numeric',
            'id'            => 'required|numeric',
            'disposition_id' => 'array'
        ]);
        $response = $this->model->editCampaignDisposition($this->request);
        return response()->json($response);
    }
    
    /**
 * @OA\Post(
 *     path="/status-update-disposition",
 *     summary="Update the status of a disposition",
 *     tags={"Disposition"},
 *     security={{"Bearer":{}}},
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             required={"listId", "status"},
 *             @OA\Property(property="listId", type="integer", example=101, description="ID of the disposition to update"),
 *             @OA\Property(property="status", type="integer", example=1, description="New status value")
 *         )
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Status updated successfully or failed",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="string", example="true"),
 *             @OA\Property(property="status", type="string", example="true"),
 *             @OA\Property(property="message", type="string", example="Disposition status updated successfully")
 *         )
 *     ),
 *     @OA\Response(
 *         response=400,
 *         description="Invalid input data",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="string", example="false"),
 *             @OA\Property(property="status", type="string", example="false"),
 *             @OA\Property(property="message", type="string", example="Invalid or missing list ID")
 *         )
 *     ),
 *     @OA\Response(
 *         response=500,
 *         description="Internal server error",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="string", example="false"),
 *             @OA\Property(property="status", type="string", example="false"),
 *             @OA\Property(property="message", type="string", example="An error occurred while updating status")
 *         )
 *     )
 * )
 */

    public function updateDispositionStatus()
    {
        $response = $this->model->updateDispositionStatus($this->request);
        return response()->json($response);
    }
}
