<?php

namespace App\Http\Controllers;

use App\Model\RecycleRule;
use Illuminate\Http\Request;

class RecycleController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    private $request;
    public function __construct(Request $request, RecycleRule $recycleRule)
    {
        $this->request = $request;
        $this->model = $recycleRule;
    }

    /*
     * Fetch RecycleRule details
     * @return json
     */


    /**
     * @OA\Post(
     *     path="/recycle-rule",
     *     summary="Get recycle rule details",
     *     description="Retrieve recycle rule details with optional filters such as campaign ID, list ID, disposition ID, day, and call time.",
     *     tags={"Recycle Rule"},
     *     security={{"Bearer": {}}},
     *     @OA\Parameter(
     *         name="recycle_rule_id",
     *         in="query",
     *         description="ID of the recycle rule",
     *         required=false,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Parameter(
     *         name="campaign_id",
     *         in="query",
     *         description="Campaign ID to filter by",
     *         required=false,
     *         @OA\Schema(type="integer", example=10)
     *     ),
     *     @OA\Parameter(
     *         name="list_id",
     *         in="query",
     *         description="List ID to filter by",
     *         required=false,
     *         @OA\Schema(type="integer", example=5)
     *     ),
     *     @OA\Parameter(
     *         name="disposition_id",
     *         in="query",
     *         description="Disposition ID to filter by",
     *         required=false,
     *         @OA\Schema(type="integer", example=3)
     *     ),
     *     @OA\Parameter(
     *         name="day",
     *         in="query",
     *         description="Day of the week (e.g., monday, tuesday, ...)",
     *         required=false,
     *         @OA\Schema(type="string", example="monday")
     *     ),
     *     @OA\Parameter(
     *         name="call_time",
     *         in="query",
     *         description="Call time in numeric format",
     *         required=false,
     *         @OA\Schema(type="integer", example=900)
     *     ),
     * *      @OA\Parameter(
     *         name="start",
     *         in="query",
     *         required=false,
     *         description="Start index for pagination",
     *         @OA\Schema(type="integer", default=0)
     *     ),
     *     @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         required=false,
     *         description="Limit number of records returned",
     *         @OA\Schema(type="integer", default=10)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Recycle rule details retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="RecycleRules detail."),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="campaign", type="string", example="Sales Campaign"),
     *                     @OA\Property(property="list", type="string", example="Leads List"),
     *                     @OA\Property(property="disposition", type="string", example="Not Interested"),
     *                     @OA\Property(property="day", type="string", example="monday"),
     *                     @OA\Property(property="call_time", type="integer", example=900)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Recycle rules not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="RecycleRules not created."),
     *             @OA\Property(property="data", type="array", @OA\Items())
     *         )
     *     )
     * )
     */

    public function getRecycleRule()
    {
        $this->validate($this->request, [
            'recycle_rule_id'   => 'numeric',
            'campaign_id'       => 'numeric',
            'list_id'           => 'numeric',
            'disposition_id'    => 'numeric',
            'call_time'         => 'numeric',
            'day'               => 'string|max:255'
        ]);
        $response = $this->model->getRecycleRule($this->request);
        return response()->json($response);
    }

    public function getRecycleRule_old_code()
    {
        $this->validate($this->request, [
            'recycle_rule_id'   => 'numeric',
            'campaign_id'       => 'numeric',
            'list_id'           => 'numeric',
            'disposition_id'    => 'numeric',
            'call_time'         => 'numeric',
            'day'               => 'string|max:255'
        ]);
        $response = $this->model->getRecycleRule($this->request);
        return response()->json($response);
    }
    /*
     * Update Recycle Rules
     * @return json
     */
    /**
     * @OA\Post(
     *     path="/edit-recycle-rule",
     *     summary="Edit an existing recycle rule",
     *     description="Update fields of a recycle rule including campaign_id, list_id, disposition_id, day, time, call_time, or is_deleted.",
     *     tags={"Recycle Rule"},
     *     security={{"Bearer": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"recycle_rule_id", "id"},
     *             @OA\Property(property="recycle_rule_id", type="integer", example=1),
     *             @OA\Property(property="campaign_id", type="integer", example=101),
     *             @OA\Property(property="list_id", type="integer", example=202),
     *             @OA\Property(property="disposition_id", type="integer", example=5),
     *             @OA\Property(property="day", type="string", example="tuesday"),
     *             @OA\Property(property="time", type="string", format="HH:mm", example="09:30"),
     *             @OA\Property(property="call_time", type="integer", example=600),
     *             @OA\Property(property="is_deleted", type="integer", example=0),
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Recycle rule updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Recycle rules updated successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Update failed or missing required fields",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Recycle Rules not updated, Missing required fields")
     *         )
     *     )
     * )
     */

    public function editRecycleRule()
    {
        $this->validate($this->request, [
            'recycle_rule_id'   => 'required|numeric',
            'campaign_id'       => 'numeric',
            'list_id'           => 'numeric',
            'disposition_id'    => 'numeric',
            'call_time'         => 'numeric',
            'time'              => 'date_format:H:i',
        ]);
        $response = $this->model->editRecycleRule($this->request);
        return response()->json($response);
    }
    /*
     *Add RecycleRule details
     *@return json
     */

    /**
     * @OA\Post(
     *     path="/add-recycle-rule",
     *     summary="Add new recycle rule(s)",
     *     description="Create one or more recycle rules by providing campaign ID, list ID, an array of disposition IDs, an array of days, time, and call time.",
     *     tags={"Recycle Rule"},
     *     security={{"Bearer": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"campaign_id", "list_id", "disposition", "day", "time", "call_time"},
     *             @OA\Property(property="campaign_id", type="integer", example=1),
     *             @OA\Property(property="list_id", type="integer", example=2),
     *             @OA\Property(
     *                 property="disposition",
     *                 type="array",
     *                 @OA\Items(type="integer", example=3)
     *             ),
     *             @OA\Property(
     *                 property="day",
     *                 type="array",
     *                 @OA\Items(type="string", example="monday")
     *             ),
     *             @OA\Property(property="time", type="string", format="HH:mm", example="14:00"),
     *             @OA\Property(property="call_time", type="integer", example=1),
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Recycle rules added successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Recycle rules added successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid input or failed insertion",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Recycle rules are not added successfully.")
     *         )
     *     )
     * )
     */

    public function addRecycleRule()
    {
        $this->validate($this->request, [
            'campaign_id'   => 'required|numeric',
            'list_id'       => 'required|numeric',
            'disposition'   => 'required|array',
            'day'           => 'required|array',
            'time'          => 'required|date_format:H:i',
            'call_time'     => 'required|numeric',
            // 'id'            => 'required|numeric'
        ]);
        $response = $this->model->addRecycleRule($this->request);
        return response()->json($response);
    }
    /**
     * @OA\Post(
     *     path="/delete-leads-rule",
     *     summary="Delete lead rules with less than 15 calls",
     *     description="Deletes leads from lead_report where list_id and disposition_id match and the lead has fewer than 15 calls recorded in the cdr table.",
     *     tags={"Recycle Rule"},
     *     security={{"Bearer": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"list_id", "disposition_id"},
     *             @OA\Property(property="list_id", type="integer", example=124),
     *             @OA\Property(property="disposition_id", type="integer", example=0)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Leads deleted successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Recycle rule has been run successfully for the list."),
     *             @OA\Property(property="data", type="integer", example=5)
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="No matching leads found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Recycle rule Not Found."),
     *             @OA\Property(property="data", type="array", @OA\Items())
     *         )
     *     )
     * )
     */

    public function deleteLeadRule()
    {
        $response = $this->model->deleteLeadRule($this->request);
        return response()->json($response);
    }

    /**
     * @OA\post(
     *     path="/search-recycle-rule",
     *     tags={"Recycle Rule"},
     *     summary="Get Recycle Rule details",
     *     description="Retrieve recycle rule details with optional filters such as recycle_rule_id, campaign_id, list_id, disposition_id, day, and call_time.",
     *     operationId="getRecycleRule",
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="recycle_rule_id",
     *         in="query",
     *         description="Filter by Recycle Rule ID",
     *         required=false,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Parameter(
     *         name="campaign_id",
     *         in="query",
     *         description="Filter by Campaign ID",
     *         required=false,
     *         @OA\Schema(type="integer", example=101)
     *     ),
     *     @OA\Parameter(
     *         name="list_id",
     *         in="query",
     *         description="Filter by List ID",
     *         required=false,
     *         @OA\Schema(type="integer", example=202)
     *     ),
     *     @OA\Parameter(
     *         name="disposition_id",
     *         in="query",
     *         description="Filter by Disposition ID",
     *         required=false,
     *         @OA\Schema(type="integer", example=303)
     *     ),
     *     @OA\Parameter(
     *         name="day",
     *         in="query",
     *         description="Filter by Day of the Week (e.g., monday, tuesday, etc.)",
     *         required=false,
     *         @OA\Schema(type="string", example="monday")
     *     ),
     *     @OA\Parameter(
     *         name="call_time",
     *         in="query",
     *         description="Filter by Call Time",
     *         required=false,
     *         @OA\Schema(type="integer", example=1200)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Recycle Rules fetched successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="RecycleRules detail."),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="campaign", type="string", example="Summer Campaign"),
     *                     @OA\Property(property="list", type="string", example="Active Leads"),
     *                     @OA\Property(property="disposition", type="string", example="Not Interested"),
     *                     @OA\Property(property="day", type="string", example="monday"),
     *                     @OA\Property(property="call_time", type="integer", example=1500)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="No Recycle Rules found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="RecycleRules not created."),
     *             @OA\Property(property="data", type="array", @OA\Items(type="object"))
     *         )
     *     )
     * )
     */

    public function searchRecycleRule()
    {
        $response = $this->model->getRecycleRule($this->request);
        return response()->json($response);
    }
}
