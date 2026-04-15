<?php

namespace App\Http\Controllers;

use App\Model\Client\LeadSource;
use App\Model\Master\LeadSourceWebhookToken;

use Illuminate\Http\Request;
use App\Model\Role;
use App\Model\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class LeadSourceController extends Controller
{

    /**
     * @OA\get(
     *     path="/lead-source",
     *     summary="get lead source list ",
     *     description="get lead source list",
     *     tags={"LeadSource"},
     *     security={{"Bearer":{}}},
     *       @OA\Parameter(
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
     *         description="lead source list retrived successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="status", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="lead source list retrived successfully")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Failed to get lead source list ",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="status", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Failed to get lead source list")
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized access",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="status", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Unauthorized")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="lead source not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="status", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Allowed IP not found")
     *         )
     *     )
     * )
     */

    public function list(Request $request)
    {
        try {
            $clientId = $request->auth->parent_id;
            $LeadSource = [];
            $LeadSource = LeadSource::on("mysql_$clientId")->orderBy('id', 'DESC')->get()->all();
            if ($request->has('start') && $request->has('limit')) {
                $total_row = count($LeadSource);
                $start = (int)$request->input('start'); // Start index (0-based)
                $limit = (int)$request->input('limit'); // Limit number of records to fetch
                $LeadSource = array_slice($LeadSource, $start, $limit, false);
                return $this->successResponse("LeadSource Status", [
                    'start' => $start,
                    'limit' => $limit,
                    'total' => $total_row,
                    'data' => $LeadSource
                ]);
            }
            return $this->successResponse("LeadSource Status", $LeadSource);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to list extension groups", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }
    public function list_old(Request $request)
    {
        try {
            $clientId = $request->auth->parent_id;
            $LeadSource = [];
            $LeadSource = LeadSource::on("mysql_$clientId")->orderBy('id', 'DESC')->get()->all();
            return $this->successResponse("LeadSource Status", $LeadSource);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to list extension groups", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }

    // public function addLogForLeadSource(Request $request)
    // {
    //     $clientId = $request->auth->parent_id;
    //     $this->validate($request, ['lead_source_url' => 'required']);

    //     try
    //     {
    //         $LeadSourceLog = new LeadSourceLog();
    //         $LeadSourceLog->setConnection("mysql_$clientId");
    //         $LeadSourceLog->lead_source_url = $request->lead_source_url;
    //         $LeadSourceLog->lead_id = $request->lead_id;

    //         $LeadSourceLog->saveOrFail();
    //         return $this->successResponse("Added Successfully", $LeadSourceLog->toArray());
    //     }
    //     catch (\Exception $exception)
    //     {
    //         return $this->failResponse("Failed to create Lead Source ", [
    //             $exception->getMessage()
    //         ], $exception, 500);
    //     }
    // }


    /**
     * @OA\Put(
     *     path="/add-lead-source",
     *     summary="Create a new lead source",
     *     description="Creates a new lead source with the provided URL and source title",
     *     tags={"LeadSource"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Data for creating the lead source",
     *         @OA\JsonContent(
     *             required={"url", "source_title"},
     *             @OA\Property(property="url", type="string", example="https://example.com"),
     *             @OA\Property(property="source_title", type="string", example="Referral")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lead source created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Added Successfully"),
     *             @OA\Property(property="data", type="object", @OA\AdditionalProperties(type="string"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Validation error or bad request",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Validation error: source_title is already taken.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to create lead source",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to create Lead Source")
     *         )
     *     )
     * )
     */

    public function create(Request $request)
    {
        $clientId = $request->auth->parent_id;
        $this->validate($request, [
            'url' => 'required|string|max:255',
            'source_title' => 'unique:mysql_' . $clientId . '.crm_lead_source,source_title'
        ]);

        try {
            $webhookSecret = (string) Str::uuid();
            $uniqueId = mt_rand(100000, 999999);

            $LeadSource = new LeadSource();
            $LeadSource->setConnection("mysql_$clientId");
            $LeadSource->url = $request->url;
            $LeadSource->source_title = $request->source_title;
            $LeadSource->unique_id = $uniqueId;
            $LeadSource->webhook_secret = $webhookSecret;
            $LeadSource->saveOrFail();

            // Register secret in master lookup table
            LeadSourceWebhookToken::create([
                'client_id' => $clientId,
                'source_id' => $LeadSource->id,
                'token'     => $webhookSecret,
            ]);

            return $this->successResponse("Added Successfully", $LeadSource->toArray());
        } catch (\Exception $exception) {
            return $this->failResponse("Failed to create Lead Source ", [
                $exception->getMessage()
            ], $exception, 500);
        }
    }

    public function delete(Request $request, $id)
    {
        $clientId = $request->auth->parent_id;
        try {
            $source = LeadSource::on("mysql_$clientId")->findOrFail($id);
            $source->delete();
            return $this->successResponse("Lead Source Deleted Successfully", []);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->failResponse("Lead Source Not Found", ["Invalid Lead Source id $id"], $e, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to delete Lead Source", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/update-lead-sources/{id}",
     *     summary="Update an existing lead source",
     *     description="Updates a lead source with the provided URL and source title by its ID",
     *     tags={"LeadSource"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the lead source to update",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         description="Data to update the lead source",
     *         @OA\JsonContent(
     *             required={"url", "source_title"},
     *             @OA\Property(property="url", type="string", example="https://new-example.com"),
     *             @OA\Property(property="source_title", type="string", example="Updated Referral")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lead source updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Lead Source Update"),
     *             @OA\Property(property="data", type="object", @OA\AdditionalProperties(type="string"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Lead source not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Lead Source Not Found"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string", example="Invalid Lead Source id 1"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Validation error or bad request",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Validation error: source_title is already taken.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to update lead source",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to update Lead Source")
     *         )
     *     )
     * )
     */

    public function update(Request $request, $id)
    {
        $clientId = $request->auth->parent_id;

        // Define the validation rules with the unique rule for source_title
        $validationRules = [
            'url' => 'required|string|max:255',
            'source_title' => [
                'required',
                'string',
                'max:255',
                Rule::unique("mysql_$clientId.crm_lead_source")->ignore($id),
            ],
        ];

        $this->validate($request, $validationRules);

        try {
            $LeadSource = LeadSource::on("mysql_$clientId")->findOrFail($id);

            if ($request->has("url")) {
                $LeadSource->url = $request->input("url");
            }
            if ($request->has("source_title")) {
                $LeadSource->source_title = $request->input("source_title");
            }

            $LeadSource->saveOrFail();
            return $this->successResponse("Lead Source Update", $LeadSource->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Lead Source Not Found", [
                "Invalid Lead Source id $id"
            ], $exception, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update Lead Source", [
                $exception->getMessage()
            ], $exception, 404);
        }
    }





    /** POST /lead-source/{id}/rotate-secret — generate a new webhook secret */
    public function rotateSecret(Request $request, $id)
    {
        $clientId = $request->auth->parent_id;
        try {
            $source = LeadSource::on("mysql_$clientId")->findOrFail($id);
            $newSecret = (string) Str::uuid();

            // Update client DB
            $source->webhook_secret = $newSecret;
            $source->saveOrFail();

            // Update master lookup (upsert)
            LeadSourceWebhookToken::updateOrCreate(
                ['client_id' => $clientId, 'source_id' => $id],
                ['token' => $newSecret]
            );

            return $this->successResponse("Webhook secret rotated", ['webhook_secret' => $newSecret]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->failResponse("Lead Source Not Found", [], $e, 404);
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to rotate secret", [$e->getMessage()], $e, 500);
        }
    }

    //  public function changeStatus(Request $request){

    //         $clientId = $request->auth->parent_id;   
    //     try {
    //         $LeadStatus = LeadStatus::on("mysql_$clientId")->findOrFail($request->lead_status_id);
    //         $LeadStatus->status =$request->status;
    //         $LeadStatus->saveOrFail();
    //         return $this->successResponse("Lead Status Updated", $LeadStatus->toArray());
    //     } catch (ModelNotFoundException $exception) {
    //         return $this->failResponse("Lead Status Not Found", [
    //             "Invalid Lead Status id $id"
    //         ], $exception, 404);
    //     } catch (\Throwable $exception) {
    //         return $this->failResponse("Failed to update Lead Status", [
    //             $exception->getMessage()
    //         ], $exception, 404);
    //     }

    // }
}
