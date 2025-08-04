<?php

namespace App\Http\Controllers;
use App\Model\RingGroup;
use Illuminate\Http\Request;
class RingGroupController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    private $request;
    public function __construct(Request $request, RingGroup $ringgroup)
    {
        $this->request = $request;
        $this->model = $ringgroup;
    }
    
    /*
     * Fetch Dnc details
     * @return json
     */
    /**
 * @OA\Post(
 *     path="/ring-group",
 *     summary="Get Ring Group Details",
 *     tags={"Ring Group"},
 *     security={{"Bearer":{}}},
   * @OA\RequestBody(
     * required=false,
     * @OA\JsonContent(
     * type="object",
     * @OA\Property(
     * property="start",
     * type="integer",
     * default=0,
     * description="Start index for pagination (offset of the returned array)"
     * ),
     * @OA\Property(
     * property="limit",
     * type="integer",
     * default=10,
     * description="Number of records to return (limit of the returned array)"
     * ),
     * )
     * ),
 *     @OA\Response(
 *         response=200,
 *         description="Ring Group detail",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="string", example="true"),
 *             @OA\Property(property="message", type="string", example="Ring Group detail."),
 *             @OA\Property(
 *                 property="data",
 *                 type="array",
 *                 @OA\Items(
 *                     @OA\Property(property="id", type="integer", example=1),
 *                     @OA\Property(property="name", type="string", example="Sales Group"),
 *                     @OA\Property(property="extensions", type="string", example="SIP/1001-SIP/1002"),
 *                     @OA\Property(property="extension_name", type="string", example="John Doe-1001, Jane Smith-1002")
 *                 )
 *             )
 *         )
 *     ),
 *     @OA\Response(
 *         response=404,
 *         description="Record not found",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="string", example="true"),
 *             @OA\Property(property="message", type="string", example="Record not found."),
 *             @OA\Property(property="data", type="array", @OA\Items(type="object"))
 *         )
 *     ),
 *     @OA\Response(
 *         response=500,
 *         description="Server error",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="string", example="false"),
 *             @OA\Property(property="message", type="string", example="Ring Group not created."),
 *             @OA\Property(property="data", type="array", @OA\Items(type="object"))
 *         )
 *     )
 * )
 */

    public function getRingGroup()
    {
        $response = $this->model->ringGroupDetail($this->request);
        return response()->json($response);
    }
    /*
     * Update Dnc detail
     * @return json
     */
     /**
     * @OA\Post(
     *     path="/edit-ring-group",
     *     summary="Edit an existing ring group",
     *     tags={"Ring Group"},
     *      security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="application/json",
     *             @OA\Schema(
     *                 type="object",
     *                 required={"ring_id", "title"},
     *                 @OA\Property(property="ring_id", type="integer", example=12),
     *                 @OA\Property(property="title", type="string", example="Support Group"),
     *                 @OA\Property(property="description", type="string", example="Handles support queries"),
     *                 @OA\Property(property="extension", type="array", @OA\Items(type="string"), example={"SIP/31001", "SIP/31002"}),
     *                 @OA\Property(property="emails", type="array", @OA\Items(type="string"), example={"support@example.com"}),
     *                 @OA\Property(property="ring_type", type="integer", enum={1, 2}, example=1),
     *                 @OA\Property(property="receive_on", type="string", example="mobile")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response="200",
     *         description="Ring Group updated successfully",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Ring Group updated successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response="400",
     *         description="Invalid request",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Invalid request data.")
     *         )
     *     )
     * )
     */
    public function editRingGroup()
    {

      // echo $this->request;die;
        /*$this->validate($this->request, [
            'number'    => 'required|numeric|regex:/^([0-9\s\-\+\(\)]*)$/|min:10',
            'extension' => 'numeric',
            'comment'   => 'string',
            'id'        => 'required|numeric'
        ]);*/
        $response = $this->model->ringGroupUpdate($this->request);
        return response()->json($response);
    }
    /*
     *Add Dnc details
     *@return json
     */
    /**
 * @OA\Post(
 *     path="/add-ring-group",
 *     summary="Add a new Ring Group",
 *     tags={"Ring Group"},
 *     security={{"Bearer":{}}},
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             required={"title", "extension", "ring_type"},
 *             @OA\Property(property="title", type="string", example="Sales Group"),
 *             @OA\Property(property="description", type="string", example="Handles incoming sales queries"),
 *             @OA\Property(
 *                 property="extension",
 *                 type="array",
 *                 @OA\Items(type="string", example="SIP/31001")
 *             ),
 *             @OA\Property(
 *                 property="emails",
 *                 type="array",
 *                 @OA\Items(type="string", format="email", example="john@example.com")
 *             ),
 *             @OA\Property(property="ring_type", type="integer", example=1, description="1 for '&' concatenation, else '-'"),
 *             @OA\Property(property="receive_on", type="string", example="mobile", description="Where to receive calls, e.g., 'mobile', 'extension'")
 *         )
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Success response",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="string", example="true"),
 *             @OA\Property(property="message", type="string", example="Ring Group added successfully.")
 *         )
 *     ),
 *     @OA\Response(
 *         response=400,
 *         description="Bad request or missing required fields",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="string", example="false"),
 *             @OA\Property(property="message", type="string", example="Ring Group not added successfully.")
 *         )
 *     ),
 *     @OA\Response(
 *         response=500,
 *         description="Internal server error",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="string", example="false"),
 *             @OA\Property(property="message", type="string", example="Ring Group are not added successfully.")
 *         )
 *     )
 * )
 */

    public function addRingGroup()
    {
        /*$this->validate($this->request, [
            'title'    => 'string',
            'description' => 'string',
            'extension'   => 'array',
             ]);*/
        $response = $this->model->addRingGroup($this->request);
        return response()->json($response);
    }
    /*
     *Delete Dnc
     *@return json
     */
/**
 * @OA\Post(
 *     path="/delete-ring-group",
 *     summary="Delete a Ring Group",
 *     description="Deletes a ring group based on the provided ring ID.",
 *     tags={"Ring Group"},
 *     security={{"Bearer":{}}},
 *     @OA\Parameter(
 *         name="ring_id",
 *         in="query",
 *         required=true,
 *         description="ID of the ring group to delete",
 *         @OA\Schema(type="integer", example=12)
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Ring Group deleted successfully",
 *         @OA\JsonContent(
 *             type="object",
 *             @OA\Property(property="success", type="string", example="true"),
 *             @OA\Property(property="message", type="string", example="Ring Group deleted successfully.")
 *         )
 *     ),
 *     @OA\Response(
 *         response=400,
 *         description="Invalid request or failed deletion",
 *         @OA\JsonContent(
 *             type="object",
 *             @OA\Property(property="success", type="string", example="false"),
 *             @OA\Property(property="message", type="string", example="Ring Group not deleted successfully.")
 *         )
 *     ),
 *     @OA\Response(
 *         response=404,
 *         description="Ring Group not found",
 *         @OA\JsonContent(
 *             type="object",
 *             @OA\Property(property="success", type="string", example="false"),
 *             @OA\Property(property="message", type="string", example="Ring Group doesn't exist.")
 *         )
 *     ),
 *     @OA\Response(
 *         response=500,
 *         description="Internal server error",
 *         @OA\JsonContent(
 *             type="object",
 *             @OA\Property(property="success", type="string", example="false"),
 *             @OA\Property(property="message", type="string", example="An error occurred while deleting the Ring Group.")
 *         )
 *     )
 * )
 */
  
    public function deleteRingGroup()
    {
        $this->validate($this->request, [
           // 'number'    => 'required|numeric|regex:/^([0-9\s\-\+\(\)]*)$/|min:10',
           // 'id'        => 'required|numeric'
        ]);
        $response = $this->model->ringDelete($this->request);
        return response()->json($response);
    }



 
}
