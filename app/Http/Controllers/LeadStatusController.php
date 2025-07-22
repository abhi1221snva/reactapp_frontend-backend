<?php

namespace App\Http\Controllers;

use App\Model\Client\LeadStatus;
use Illuminate\Http\Request;
use App\Model\Role;
use App\Model\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class LeadStatusController extends Controller
{
    private $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }
    /*
     * Fetch lead status details
     * @return json
     */

    /**
     * @OA\Get(
     *      path="/leadStatus",
     *      summary="Get list of lead status",
     *      tags={"Lead Status"},
     *      security={{"Bearer":{}}},
     *      @OA\Response(
     *          response="200",
     *          description="extension Lead Status"
     *      )
     * )
     */
    public function list(Request $request)
    {
        try {

            $clientId = $request->auth->parent_id;
            $leadstatus = [];
            $leadstatus = LeadStatus::on("mysql_$clientId")->orderBy('display_order', 'ASC')->get()->all();
            return $this->successResponse("Lead Status", $leadstatus);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to list ", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }

    /*
 * Create a new lead status
 * @return json
 */

    /**
     * @OA\Put(
     *     path="/add-lead-status",
     *     summary="Create a new lead status",
     *     description="Creates a new lead status entry in the client-specific database.",
     *     tags={"Lead Status"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"title"},
     *             @OA\Property(property="title", type="string", example="Interested", description="Lead status title"),
     *            @OA\Property(property="lead_title_url", type="string", example="new_lead", description="Lead status title"),
     *             @OA\Property(property="color_code", type="string", example="#00FF00", description="Color code for status"),
     *             @OA\Property(property="image", type="string", example="interested.png", description="Image filename or path")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lead status created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Added Successfully"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="title", type="string", example="Interested"),
     *                 @OA\Property(property="lead_title_url", type="string", example="interested"),
     *                 @OA\Property(property="color_code", type="string", example="#00FF00"),
     *                 @OA\Property(property="image", type="string", example="interested.png"),
     *                 @OA\Property(property="created_at", type="string", format="date-time", example="2025-04-07T10:20:00Z"),
     *                 @OA\Property(property="updated_at", type="string", format="date-time", example="2025-04-07T10:20:00Z")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation Error - Title already exists or invalid format"
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized - Invalid Token"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server Error - Failed to create Lead Status"
     *     )
     * )
     */

    public function create(Request $request)
    {
        $clientId = $request->auth->parent_id;
        $this->validate($request, ['title' => 'required|string|max:255|unique:mysql_' . $clientId . '.crm_lead_status,title']);

        try {
            $lead_title_url = str_replace(' ', '_', trim(strtolower($request->title)));
            $LeadStatus = new LeadStatus();
            $LeadStatus->setConnection("mysql_$clientId");
            $LeadStatus->title = $request->title;
            $LeadStatus->lead_title_url = $lead_title_url;
            $LeadStatus->color_code = $request->color_code;
            $LeadStatus->image = $request->image;

            if ($request->has("webhook_status"))
            $LeadStatus->webhook_status = $request->webhook_status;

            if ($request->has("webhook_url"))
            $LeadStatus->webhook_url = $request->webhook_url;

            if ($request->has("webhook_token"))
            $LeadStatus->webhook_token = $request->webhook_token;

            if ($request->has("webhook_method"))
            $LeadStatus->webhook_method = $request->webhook_method;


            $LeadStatus->saveOrFail();
            return $this->successResponse("Added Successfully", $LeadStatus->toArray());
        } catch (\Exception $exception) {
            return $this->failResponse("Failed to create Lead Status ", [
                $exception->getMessage()
            ], $exception, 500);
        }
    }

    /*
 * Delete a lead status by ID
 * @return json
 */

    /**
     * @OA\get(
     *     path="/delete-lead-status/{id}",
     *     summary="Delete a lead status",
     *     tags={"Lead Status"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the lead status to delete",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lead Status deleted successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Lead Status Name Deleted Successfully"),
     *            description="extension Lead Status" 
     *            )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Lead Status not found"
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized - Invalid Token"
     *     )
     * )
     */


    public function delete(Request $request, $id)
    {
        $clientId = $request->auth->parent_id;
        try {
            $lead_status = LeadStatus::on("mysql_$clientId")->find($id)->delete();
            return $this->successResponse("Lead Status Name Deleted Successfully", [$lead_status]);
        } catch (ModelNotFoundException $exception) {
            throw new NotFoundHttpException("No Lead Status Name with id $id");
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch Lead Status Name info", [], $exception);
        }
    }

    /*
 * Update an existing lead status
 * @return json
 */

    /**
     * @OA\Post(
     *     path="/update-lead-status/{id}",
     *     summary="Update an existing lead status",
     *     description="Updates the title or color of a lead status in the client-specific database.",
     *     tags={"Lead Status"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the lead status to update",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"title"},
     *             @OA\Property(property="title", type="string", example="Follow Up"),
     *             @OA\Property(property="color_code", type="string", example="#FFA500", description="Optional color code")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lead Status updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Lead Status Update"),
     *             description="extension Lead Status"
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Lead Status not found"
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation Error"
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized - Invalid Token"
     *     )
     * )
     */


    public function update(Request $request, $id)
    {
        $clientId = $request->auth->parent_id;
        $validationRules = [
            'title' => [
                'required',
                'string',
                'max:255',
                Rule::unique("mysql_$clientId.crm_lead_status")->ignore($id),
            ],
        ];

        $this->validate($request, $validationRules);
        try {
            $LeadStatus = LeadStatus::on("mysql_$clientId")->findOrFail($id);

            $lead_title_url = str_replace(' ', '_', trim(strtolower($request->input("title"))));
            if ($request->has("title"))
                $LeadStatus->title = $request->input("title");
            $LeadStatus->lead_title_url = $lead_title_url;
            if ($request->has("color_code"))
                $LeadStatus->color_code = $request->color_code;

            if ($request->has("webhook_status"))
                $LeadStatus->webhook_status = $request->webhook_status;

            if ($request->has("webhook_url"))
                $LeadStatus->webhook_url = $request->webhook_url;

            if ($request->has("webhook_token"))
                $LeadStatus->webhook_token = $request->webhook_token;

            if ($request->has("webhook_method"))
            $LeadStatus->webhook_method = $request->webhook_method;

            $LeadStatus->saveOrFail();
            return $this->successResponse("Lead Status Update", $LeadStatus->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Lead Status Not Found", [
                "Invalid Lead Status id $id"
            ], $exception, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update Lead Status", [
                $exception->getMessage()
            ], $exception, 404);
        }
    }

    /*
 * Change the status of an existing lead status
 * @return json
 */

    /**
     * @OA\Post(
     *     path="/change-lead-status",
     *     summary="Change status of a lead status record",
     *     description="Update the status (active/inactive) of a lead status by its ID.",
     *     tags={"Lead Status"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"lead_status_id", "status"},
     *             @OA\Property(property="lead_status_id", type="integer", example=1, description="ID of the lead status to update"),
     *             @OA\Property(property="status", type="integer", example=0, description="New status value (1 for active, 0 for inactive)")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Status updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Lead Status Updated"),
     *             description="extension data"
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Lead Status not found"
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized - Invalid Token"
     *     )
     * )
     */


    public function changeStatus(Request $request)
    {

        $clientId = $request->auth->parent_id;
        try {
            $LeadStatus = LeadStatus::on("mysql_$clientId")->findOrFail($request->lead_status_id);
            $LeadStatus->status = $request->status;
            $LeadStatus->saveOrFail();
            return $this->successResponse("Lead Status Updated", $LeadStatus->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Lead Status Not Found", [
                "Invalid Lead Status id $id"
            ], $exception, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update Lead Status", [
                $exception->getMessage()
            ], $exception, 404);
        }
    }

    /*
 * Update Display Order an existing lead status
 * @return json
 */
    /**
     * @OA\Post(
     *     path="/lead-status/updateDisplayOrder",
     *     summary="Update display order of lead statuses",
     *     tags={"Lead Status"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"display_order"},
     *             @OA\Property(
     *                 property="display_order",
     *                 type="array",
     *                 @OA\Items(type="integer", example=1),
     *                 description="Array of lead status IDs in new display order"
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="LeadStatus Updated Successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="LeadStatus Updated Successfully"),
     *             description="extension data"
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="LeadStatus Not Found"
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized - Invalid Token"
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
                $objLead = LeadStatus::on("mysql_$clientId")->findOrFail($v);
                $objLead->display_order = $i;
                $i++;
                $objLead->saveOrFail();
            }
            return $this->successResponse("LeadStatus Updated Successfully", $objLead->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("LeadStatus Not Found", [
                "Invalid LeadStatus id: $id"
            ], $exception, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update LeadStatus", [
                $exception->getMessage()
            ], $exception, 404);
        }
    }


    /*
 * change-view-on-dashboard-status an existing lead status
 * @return json
 */

   /**
 * @OA\Post(
 *     path="/change-view-on-dashboard-status",
 *     summary="Toggle view on dashboard for a lead status",
 *     tags={"Lead Status"},
 *     security={{"Bearer":{}}},
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             required={"lead_status_id", "view_on_dashboard"},
 *             @OA\Property(property="lead_status_id", type="integer", example=1, description="ID of the lead status"),
 *             @OA\Property(property="view_on_dashboard", type="boolean", example=true, description="Whether to show this status on dashboard")
 *         )
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Lead Status Updated",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="boolean", example=true),
 *             @OA\Property(property="message", type="string", example="Lead Status Updated"),
 *             @OA\Property(property="data", type="object",
 *                 @OA\Property(property="id", type="integer", example=5),
 *                 @OA\Property(property="title", type="string", example="New Lead"),
 *                 @OA\Property(property="view_on_dashboard", type="boolean", example=true)
 *             )
 *         )
 *     ),
 *     @OA\Response(
 *         response=404,
 *         description="Lead Status Not Found"
 *     ),
 *     @OA\Response(
 *         response=401,
 *         description="Unauthorized - Invalid Token"
 *     )
 * )
 */


    public function changeViewOnLead(Request $request)
    {

        $clientId = $request->auth->parent_id;
        try {
            $Label = LeadStatus::on("mysql_$clientId")->findOrFail($request->lead_status_id);
            $Label->view_on_dashboard = $request->view_on_dashboard;
            $Label->saveOrFail();
            return $this->successResponse("Lead Status Updated", $Label->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Lead Status Not Found", [
                "Invalid Lead Status id $id"
            ], $exception, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update Lead Status", [
                $exception->getMessage()
            ], $exception, 404);
        }
    }
}
