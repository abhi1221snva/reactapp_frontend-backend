<?php

namespace App\Http\Controllers;

use App\Model\Client\MarketingCampaign;
use App\Model\Client\MarketingCampaignSchedule;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class MarketingCampaignController extends Controller
{

    /**
     * @OA\Get(
     *     path="/marketing-campaigns",
     *     summary="Get Received MarketingCampaign List",
     *     description="Fetches a list of received  MarketingCampaign List records filtered by the user's DIDs and extension.",
     *     tags={"MarketingCampaign"},
     *     security={{"Bearer":{}}},
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
     *         description=" received  MarketingCampaign List",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example=" MarketingCampaign List"),
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
     *                     @OA\Property(property="file_path", type="string", example="/storage/ MarketingCampaign/inbound_10.pdf"),
     *                     @OA\Property(property="start_time", type="string", example="2025-04-25 14:00:00"),
     *                     @OA\Property(property="created_at", type="string", example="2025-04-25 14:02:00"),
     *                     @OA\Property(property="updated_at", type="string", example="2025-04-25 14:02:00")
     *                 )
     *             )
     *         )
     *     )
     * )
     */

    public function index(Request $request)
    {
        $campaign = MarketingCampaign::on("mysql_" . $request->auth->parent_id)->get()->all();

        if ($request->has('start') && $request->has('limit')) {
            $total_row = count($campaign);

            $start = (int) $request->input('start');  // Start index (0-based)
            $limit = (int) $request->input('limit');  // Number of records to fetch

            $campaign = array_slice($campaign, $start, $limit, false);

            return $this->successResponse("Campaigns List", [
                'start' => $start,
                'limit' => $limit,
                'total' => $total_row,
                'data' => $campaign
            ]);
        }
        return $this->successResponse("Campaigns List", $campaign);
    }

    public function index_old_code(Request $request)
    {
        $campaign = MarketingCampaign::on("mysql_" . $request->auth->parent_id)->get()->all();

        return $this->successResponse("Campaigns List", $campaign);
    }


    /**
     * @OA\Put(
     *     path="/marketing-campaign",
     *     summary="create a Marketing Campaign",
     *     tags={"MarketingCampaign"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="title", type="string", example="Updated Spring Campaign"),
     *             @OA\Property(property="description", type="string", example="Updated campaign details for spring sales.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Campaign created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Campaign Update"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="title", type="string", example="Campaign created successfully"),
     *                 @OA\Property(property="description", type="string", example="Updated campaign details for spring sales."),
     *                 @OA\Property(property="created_at", type="string", format="date-time", example="2024-01-01T10:00:00Z"),
     *                 @OA\Property(property="updated_at", type="string", format="date-time", example="2024-04-25T12:00:00Z")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Campaign Not Found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Campaign Not Found"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string", example="Invalid Campaign id 1"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to update Campaign",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Failed to update Campaign"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string", example="Server error message"))
     *         )
     *     )
     * )
     */
    public function create(Request $request)
    {
        $clientid = $request->auth->parent_id;
        $this->validate($request, [
            "title" => "required|string|unique:mysql_$clientid.marketing_campaigns",
            "description" => "required|string"
        ]);
        try {
            $campaign = new MarketingCampaign($request->all());
            $campaign->setConnection("mysql_$clientid");
            $campaign->saveOrFail();
            return $this->successResponse("Added Successfully", $campaign->toArray());
        } catch (\Exception $exception) {
            return $this->failResponse("Failed to create Campaign ", [
                $exception->getMessage()
            ], $exception, 500);
        }
    }



    /**
     * @OA\Get(
     *     path="/marketing-campaign/{id}",
     *     summary="Get a Marketing Campaign by ID",
     *     description="Fetches a specific marketing campaign's details by its ID for the authenticated user's parent account.",
     *     tags={"MarketingCampaign"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the marketing campaign",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Campaign Info",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="campaign Info"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="name", type="string", example="Spring Sale Campaign"),
     *                 @OA\Property(property="status", type="string", example="active"),
     *                 @OA\Property(property="created_at", type="string", format="date-time", example="2024-01-01T10:00:00Z"),
     *                 @OA\Property(property="updated_at", type="string", format="date-time", example="2024-01-05T15:00:00Z")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Campaign Not Found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Campaign Not Found"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string", example="Invalid Campaign id 1"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to fetch the Campaign",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Failed to fetch the Campaign"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string", example="Server error message"))
     *         )
     *     )
     * )
     */

    public function show(Request $request, int $id)
    {
        try {
            $campaign = MarketingCampaign::on("mysql_" . $request->auth->parent_id)->findOrFail($id);
            return $this->successResponse("campaign Info", $campaign->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Campaign Not Found", [
                "Invalid Campaign id $id"
            ], $exception, 404);
        } catch (\Exception $exception) {
            return $this->failResponse("Failed to fetch the Campaign ", [
                $exception->getMessage()
            ], $exception, 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/marketing-campaign/{id}",
     *     summary="Update a Marketing Campaign",
     *     description="Updates the title and/or description of a specific marketing campaign for the authenticated user's parent account.",
     *     tags={"MarketingCampaign"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the marketing campaign to update",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="title", type="string", example="Updated Spring Campaign"),
     *             @OA\Property(property="description", type="string", example="Updated campaign details for spring sales.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Campaign Update",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Campaign Update"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="title", type="string", example="Updated Spring Campaign"),
     *                 @OA\Property(property="description", type="string", example="Updated campaign details for spring sales."),
     *                 @OA\Property(property="created_at", type="string", format="date-time", example="2024-01-01T10:00:00Z"),
     *                 @OA\Property(property="updated_at", type="string", format="date-time", example="2024-04-25T12:00:00Z")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Campaign Not Found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Campaign Not Found"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string", example="Invalid Campaign id 1"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to update Campaign",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Failed to update Campaign"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string", example="Server error message"))
     *         )
     *     )
     * )
     */

    public function update(Request $request, int $id)
    {
        $this->validate($request, [
            'title' => 'string',
            'description' => 'string'
        ]);

        try {
            $campaign = MarketingCampaign::on("mysql_" . $request->auth->parent_id)->findOrFail($id);
            if ($request->has("title"))
                $campaign->title = $request->input("title");
            if ($request->has("description"))
                $campaign->description = $request->input("description");
            $campaign->saveOrFail();
            return $this->successResponse("Campaign Update", $campaign->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Campaign Not Found", [
                "Invalid Campaign id $id"
            ], $exception, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update Campaign", [
                $exception->getMessage()
            ], $exception, 404);
        }
    }

    /**
     * @OA\Post(
     *     path="/status-update-marketing",
     *     summary="Update a Marketing Campaign status",
     *     description="Updates the status of a specific marketing campaign for the authenticated user's parent account.",
     *     tags={"MarketingCampaign"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="listId", type="integer", example="1"),
     *             @OA\Property(property="status", type="string", example="1")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Campaign status Update",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Campaign Update"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="title", type="string", example="Updated Spring Campaign"),
     *                 @OA\Property(property="description", type="string", example="Updated campaign details for spring sales."),
     *                 @OA\Property(property="created_at", type="string", format="date-time", example="2024-01-01T10:00:00Z"),
     *                 @OA\Property(property="updated_at", type="string", format="date-time", example="2024-04-25T12:00:00Z")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Campaign Not Found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Campaign Not Found"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string", example="Invalid Campaign id 1"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to update Campaign",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Failed to update Campaign"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string", example="Server error message"))
     *         )
     *     )
     * )
     */
    function updateGroupStatus(Request $request)
    {
        $listId = $request->input('listId');
        $status = $request->input('status');

        $saveRecord = MarketingCampaign::on('mysql_' . $request->auth->parent_id)
            ->where('id', $listId) // Use the actual listId received from the request
            ->update(array('status' => $status));


        if ($saveRecord > 0) {
            return response()->json([
                'success' => 'true',
                'status' => 'true',
                'message' => 'marketing status updated successfully'
            ]);
        } else {
            return response()->json([
                'success' => 'false',
                'status' => 'false',
                'message' => 'marketing status  update failed'
            ]);
        }
    }
}
