<?php

namespace App\Http\Controllers;

use App\Model\IvrMenu;
use Illuminate\Http\Request;

class IvrMenuController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    private $request;
    public function __construct(Request $request, IvrMenu $ivrmenu)
    {
        $this->request = $request;
        $this->model = $ivrmenu;
    }

    /*
     * Fetch Dnc details
     * @return json
     */
    /**
     * @OA\Post(
     *     path="/ivr-menu",
     *     summary="Get IVR Menu Details",
     *     description="Returns a list of IVR menu entries. If an ivr_id is passed, it filters based on that IVR.",
     *     tags={"IVR Menu"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *             @OA\Property(property="ivr_id", type="string", example="3_ivr_1635157351", description="IVR ID to filter menu details")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="IVR Menu detail retrieved",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="IVR Menu detail."),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="ivr_desc", type="string", example="Main Menu"),
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="ivr_id", type="string", example="ivr-001"),
     *                     @OA\Property(property="ivr_m_id", type="integer", example=10),
     *                     @OA\Property(property="dtmf", type="string", example="1"),
     *                     @OA\Property(property="dest_type", type="string", example="extension"),
     *                     @OA\Property(property="dest", type="string", example="101"),
     *                     @OA\Property(property="dtmf_title", type="string", example="Sales"),
     *                     @OA\Property(property="is_deleted", type="integer", example=0)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="IVR Menu not created.",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="IVR Menu not created."),
     *             @OA\Property(property="data", type="array", @OA\Items())
     *         )
     *     )
     * )
     */

    public function getIvrMenu()
    {
        $response = $this->model->ivrMenuDetail($this->request);
        return response()->json($response);
    }
    /*
     * Update IVR Menu detail
     * @return json
     */
    /**
     * @OA\Post(
     *     path="/edit-ivr-menu",
     *     summary="Create or Update IVR Menu",
     *     tags={"IVR Menu"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="parameter",
     *                 type="object",
     *                 @OA\Property(property="ivr", type="integer", example=6),
     *                 @OA\Property(property="dtmf", type="array", @OA\Items(type="string"), example={"1"}),
     *                 @OA\Property(property="dtmf_title", type="array", @OA\Items(type="string"), example={"Support"}),
     *                 @OA\Property(property="dest_type", type="array", @OA\Items(type="string"), example={"3"}),
     *                 @OA\Property(property="dest", type="array", @OA\Items(type="string"), example={"11"}),
     *                 @OA\Property(property="ivr_menu_id", type="array", @OA\Items(type="integer"), example={0})
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Success",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="IVR Menu updated successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Validation Error"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server Error"
     *     )
     * )
     */


    public function editIvrMenu()
    {
        $this->validate($this->request, [
            'parameter'   => 'required|array'
        ]);
        $response = $this->model->editIvrMenu($this->request);
        return response()->json($response);
    }
    /*
     *Add IVR Menu details
     *@return json
     */

    /**
     * @OA\Post(
     *     path="/add-ivr-menu",
     *     summary="Add new IVR Menu entries",
     *     tags={"IVR Menu"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="parameter",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="dtmf", type="string", example="1"),
     *                     @OA\Property(property="dest_type", type="string", example="0"),
     *                     @OA\Property(property="ivr_id", type="integer", example="3_ivr_1622222271"),
     *                     @OA\Property(property="dest", type="string", example="11")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Success",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="IVR Menu added successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Validation Error"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server Error"
     *     )
     * )
     */
    public function addIvrMenu()
    {
        $this->validate($this->request, [
            //'ivr_id'      => 'required|string|max:255',
            // 'dest'      => 'required|string|max:255',
            'parameter'   => 'required|array',
            // 'id'          => 'required|numeric'
        ]);
        $response = $this->model->addIvrMenu($this->request);
        return response()->json($response);
    }
    /*
     *Delete IVR Menu
     *@return json
     */
    /**
     * @OA\Post(
     *     path="/delete-ivr-menu",
     *     summary="Delete IVR Menu",
     *     description="Deletes an IVR menu entry using auto_id",
     *     operationId="deleteIVRMenu",
     *     security={{"Bearer":{}}},
     *     tags={"IVR Menu"},
     *     @OA\Parameter(
     *         name="auto_id",
     *         in="query",
     *         required=true,
     *         @OA\Schema(
     *             type="integer"
     *         ),
     *         description="The ID of the IVR menu to delete"
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful deletion",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="IvrMenu has been deleted successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid input"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error"
     *     )
     * )
     */

    public function deleteIvrMenu()
    {
        $this->validate($this->request, [
            'auto_id'        => 'required|numeric'
        ]);
        $response = $this->model->ivrMenuDelete($this->request);
        return response()->json($response);
    }

    
}
