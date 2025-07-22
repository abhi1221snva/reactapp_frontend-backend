<?php

namespace App\Http\Controllers;

use App\Model\Label;
use App\Model\Dialer;

use Illuminate\Http\Request;

class LabelController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    private $request;
    public function __construct(Request $request, Label $label, Dialer $dialer)
    {
        $this->request = $request;
        $this->model = $label;
        $this->model1 = $dialer;
    }

    /*
     * Fetch label details
     * @return json
     */

    /**
     * @OA\Post(
     *     path="/label",
     *     tags={"Label"},
     *     summary="Fetch label details",
     *     description="Fetch label details using optional filters like label_id, is_deleted, and extension ,title internally).",
     *     operationId="getLabel",
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *             @OA\Property(property="label_id", type="integer", example=5, description="The ID of the label"),
     *             @OA\Property(property="is_deleted", type="integer", example=0, description="0 for active, 1 for deleted"),
     *             @OA\Property(property="extension", type="integer", example="1", description="Used internally as 'title' in the database"),
     *             @OA\Property(property="title", type="string", example="Work Phone", description="Used internally as 'title' in the database"),
     *         @OA\Property(
     *                 property="start",
     *                 type="integer",
     *                 default=0,
     *                 description="Start index for pagination"
     *             ),
     * @OA\Property(
     *                 property="limit",
     *                 type="integer",
     *                 default=10,
     *                 description="Limit number of records returned"
     *             )        
     * )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Label details retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="label detail."),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="title", type="string", example="Sales"),
     *                     @OA\Property(property="display_order", type="integer", example=1),
     *                     @OA\Property(property="is_deleted", type="integer", example=0)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Label not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="label not created."),
     *             @OA\Property(property="data", type="array", @OA\Items(type="object"))
     *         )
     *     )
     * )
     */


    public function getLabel()
    {
        $response = $this->model->labelDetail($this->request);
        return response()->json($response);
    }
    /*
     * Update label detail
     * @return json
     */
    /**
     * @OA\Post(
     *     path="/edit-label",
     *     summary="Update a label",
     *     tags={"Label"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"label_id", "id"},
     *             @OA\Property(property="label_id", type="integer", example=5, description="The ID of the label to update"),
     *             @OA\Property(property="title", type="string", example="Important Label", description="The new title of the label"),
     *             @OA\Property(property="is_deleted", type="string", example="0", description="Set to 1 to soft delete"),
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Label updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Label updated successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="The given data was invalid."),
     *             @OA\Property(
     *                 property="errors",
     *                 type="object",
     *                 example={"label_id": {"The label_id field is required."}}
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Label not found or update failed",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Label doesn't exist.")
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

    public function editLabel()
    {
        $this->validate($this->request, [
            'label_id'  => 'required|numeric',
            'title'     => 'string',
            'is_deleted' => 'string',
            // 'id'        => 'required|numeric'
        ]);
        $response = $this->model->labelUpdate($this->request);
        return response()->json($response);
    }
    /*
     *Add label details
     *@return json
     */
    /**
     * @OA\Post(
     *     path="/add-label",
     *     summary="Add a new label",
     *     description="Adds a new label to the authenticated user's label table based on their parent ID.",
     *     tags={"Label"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"title"},
     *             @OA\Property(
     *                 property="title",
     *                 type="string",
     *                 example="Important",
     *                 description="The title of the label to be added."
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Label added successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Label added successfully."),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=101),
     *                 @OA\Property(property="title", type="string", example="Important")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Missing or invalid title parameter",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Label are not added successfully."),
     *             @OA\Property(
     *                 property="errors",
     *                 type="object",
     *                 @OA\Property(
     *                     property="title",
     *                     type="array",
     *                     @OA\Items(type="string", example="The title field is required.")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Internal server error")
     *         )
     *     )
     * )
     */


    public function addLabel()
    {
        $this->validate($this->request, [
            'title' => 'required|string',
            // 'id'    => 'required|numeric'
        ]);
        $response = $this->model->addLabel($this->request);
        return response()->json($response);
    }

    /*
     * Fetch label details
     * @return json
     */
    /**
     * @OA\Post(
     * path="/extension_live",
     * summary="Get Live Extension Details",
     * tags={"Live Extensions"},
     * security={{"Bearer":{}}},
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
     * )
     * )
     * ),
     * @OA\Response(
     * response=200,
     * description="Live Extension details",
     * @OA\JsonContent(
     * @OA\Property(property="success", type="string", example="true"),
     * @OA\Property(property="message", type="string", example="label detail."),
     * @OA\Property(
     * property="data",
     * type="array",
     * @OA\Items(
     * @OA\Property(property="extension", type="string", example="1001", description="The extension number, possibly overwritten by user's primary extension."),
     * @OA\Property(property="status", type="string", example="Idle"),
     * @OA\Property(property="channel", type="string", example="SIP/1001-0000000a"),
     * @OA\Property(property="campaign_id", type="integer", example=5),
     * @OA\Property(property="lead_id", type="integer", example=12345),
     * @OA\Property(property="title", type="string", example="Sales Campaign"),
     * @OA\Property(property="full_name", type="string", example="Agent Smith", description="Full name of the user associated with the extension."),
     * )
     * )
     * )
     * ),
     * @OA\Response(
     * response=404,
     * description="No live extensions found (or 'label not created.')",
     * @OA\JsonContent(
     * @OA\Property(property="success", type="string", example="false"),
     * @OA\Property(property="message", type="string", example="label not created."),
     * @OA\Property(property="data", type="array", @OA\Items(type="object"))
     * )
     * ),
     * @OA\Response(
     * response=500,
     * description="Server error",
     * @OA\JsonContent(
     * @OA\Property(property="success", type="string", example="false"),
     * @OA\Property(property="message", type="string", example="An internal server error occurred."),
     * @OA\Property(property="data", type="array", @OA\Items(type="object"))
     * )
     * )
     * )
     */
    public function gextensionLive()
    {
        $response = $this->model->liveExtensionDetail($this->request);
        return response()->json($response);
    }
    /**
     * @OA\Post(
     *     path="/delete-ext-live",
     *     summary="Delete a live SIP extension",
     *     description="Deletes a live SIP extension based on the given extension (sip).",
     *     tags={"Live Extensions"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"sip"},
     *             @OA\Property(property="sip", type="string", example="1001", description="SIP extension to delete")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Delete operation result",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="live extension deleted."),
     *             @OA\Property(property="response", type="string", example="1")
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized Extension Found"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error"
     *     )
     * )
     */

    public function deleteExt()
    {
        $response = $this->model1->deleteExt($this->request);
        return response()->json($response);
    }

    /**
     * @OA\Post(
     *     path="/label/updateDisplayOrder",
     *     summary="Update display order of labels",
     *     tags={"Label"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"display_order"},
     *             @OA\Property(
     *                 property="display_order",
     *                 type="array",
     *                 @OA\Items(type="integer", example=3),
     *                 example={3, 1, 2},
     *                 description="Ordered array of label IDs to set new display order"
     *             )
     *         )
     *     ),
     *      @OA\Response(
     *         response=200,
     *         description="Label Updated Successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Label Updated Successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=3),
     *                 @OA\Property(property="display_order", type="integer", example=1),
     *                 @OA\Property(property="title", type="string", example="Urgent")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Invalid label ID",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Lead Not Found"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="string", example="Invalid Lead id: 99"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to update Lead",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to update Lead"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="string"))
     *         )
     *     )
     * )
     */

    public function updateDisplayOrder(Request $request)
    {



        $clientId = $request->auth->parent_id;

        $position = $request->display_order;



        try {
            $i = 1;
            foreach ($position as $k => $v) {
                $objLead = Label::on("mysql_$clientId")->findOrFail($v);
                $objLead->display_order = $i;
                $i++;
                $objLead->saveOrFail();
            }
            return $this->successResponse("Label Updated Successfully", $objLead->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Lead Not Found", [
                "Invalid Lead id: $id"
            ], $exception, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update Lead", [
                $exception->getMessage()
            ], $exception, 404);
        }
    }
    /**
     * @OA\Post(
     *     path="/status-update-label",
     *     summary="Update label status",
     *     tags={"Label"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"listId", "status"},
     *             @OA\Property(property="listId", type="integer", example=5, description="ID of the label to update"),
     *             @OA\Property(property="status", type="string", enum={"1", "0"},example="1",description="Label status: 1 (active), 0 (inactive)")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Label status updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Label status updated successfully")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Label not found or update failed",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="status", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Status update failed")
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

    function updateLabelStatus(Request $request)
    {
        $listId = $request->input('listId');
        $status = $request->input('status');

        $saveRecord = Label::on('mysql_' . $request->auth->parent_id)
            ->where('id', $listId) // Use the actual listId received from the request
            ->update(array('status' => $status));


        // Log::debug('Received listId: ', ['listId' => $listId]);
        // Log::debug('Received status: ', ['status' => $status]);
        // Log::debug('Number of updated rows: ', ['saveRecord' => $saveRecord]);
        if ($saveRecord > 0) {
            return response()->json([
                'success' => 'true',
                'status' => 'true',
                'message' => 'Label status updated successfully'
            ]);
        } else {
            return response()->json([
                'success' => 'false',
                'status' => 'false',
                'message' => 'Status  update failed'
            ]);
        }
    }
}
