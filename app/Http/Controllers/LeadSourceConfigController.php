<?php

namespace App\Http\Controllers;

use App\Model\User;
use App\Model\Client\LeadSourceConfig;
use App\Model\Master\LeadSourceApi;
use App\Model\Client\ListData;
use App\Model\Client\ListHeader;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class LeadSourceConfigController extends Controller
{

    /**
     * @OA\Post(
     *     path="/lead-source-configs",
     *     summary="Get list of lead source configurations",
     *     tags={"LeadSourceConfig"},
     *     security={{"Bearer":{}}},
     *      @OA\Parameter(
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
     *         description="List of lead source configurations",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Api List Url"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="name", type="string", example="Google Ads"),
     *                     @OA\Property(property="type", type="string", example="Digital"),
     *                     @OA\Property(property="is_active", type="string", example="1"),
     *                     @OA\Property(property="created_at", type="string", format="date-time", example="2025-06-17 12:00:00"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time", example="2025-06-17 12:10:00")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized - Invalid or missing token",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Unauthenticated.")
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

    public function index(Request $request)
    {
        $leadSource = LeadSourceConfig::on("mysql_" . $request->auth->parent_id)->get()->all();
        if ($request->has('start') && $request->has('limit')) {
            $total_row = count($leadSource);

            $start = (int) $request->input('start');  // Start index (0-based)
            $limit = (int) $request->input('limit');  // Number of records to fetch

            $leadSource = array_slice($leadSource, $start, $limit, false);

            return $this->successResponse("Api List Url", [
                'start' => $start,
                'limit' => $limit,
                'total' => $total_row,
                'data' =>    $leadSource
            ]);
        }
        return $this->successResponse("Api List Url", $leadSource);
    }

    /**
     * @OA\Put(
     *     path="/lead-source-config",
     *     summary="Create a new lead source configuration and API entry",
     *     tags={"LeadSourceConfig"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"api_key", "client_id", "title", "list_id"},
     *             @OA\Property(property="api_key", type="string", example="lbRY1J9QQvVfMIhUmPVwB5zfICKUt9"),
     *             @OA\Property(property="client_id", type="integer", example=3),
     *             @OA\Property(property="title", type="string", example="Google Ads"),
     *             @OA\Property(property="description", type="string", example="Leads from Google ad campaign"),
     *             @OA\Property(property="list_id", type="integer", example=44)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lead source and API config created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Added Successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=12),
     *                 @OA\Property(property="api_key", type="string", example="lbRY1J9QQvVfMIhUmPVwB5zfICKUt9"),
     *                 @OA\Property(property="title", type="string", example="Google Ads"),
     *                 @OA\Property(property="description", type="string", example="Ad campaign lead source"),
     *                 @OA\Property(property="list_id", type="integer", example=44),
     *                 @OA\Property(property="created_at", type="string", format="date-time", example="2025-06-17 15:45:00"),
     *                 @OA\Property(property="updated_at", type="string", format="date-time", example="2025-06-17 15:45:00")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to create Lead Source Config",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to create Lead Source Config"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="string", example="SQLSTATE[23000]: Integrity constraint violation..."))
     *         )
     *     )
     * )
     */


    public function create(Request $request)
    {
        $clientid = $request->auth->parent_id;
        try {
            $leadSourceApi = new LeadSourceApi($request->all());
            $leadSourceApi->setConnection("master");
            $leadSourceApi->saveOrFail();

            $leadSourceConfig = new LeadSourceConfig($request->all());
            $leadSourceConfig->setConnection("mysql_$clientid");
            $leadSourceConfig->saveOrFail();

            return $this->successResponse("Added Successfully", $leadSourceConfig->toArray());
        } catch (\Exception $exception) {
            return $this->failResponse("Failed to create Lead Source Config ", [
                $exception->getMessage()
            ], $exception, 500);
        }
    }

    /**
     * @OA\get(
     *     path="/delete-lead-source-config/{id}",
     *     summary="Delete a lead source configuration",
     *     tags={"LeadSourceConfig"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the Lead Source to delete",
     *         @OA\Schema(type="integer", example=12)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lead Source Deleted",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Lead Source Deleted"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=12),
     *                 @OA\Property(property="api_key", type="string", example="abc123xyz"),
     *                 @OA\Property(property="title", type="string", example="Google Ads"),
     *                 @OA\Property(property="description", type="string", example="Lead source for Google Ads"),
     *                 @OA\Property(property="list_id", type="integer", example=44),
     *                 @OA\Property(property="created_at", type="string", format="date-time", example="2025-06-17 15:00:00"),
     *                 @OA\Property(property="updated_at", type="string", format="date-time", example="2025-06-17 15:30:00")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Lead Source Not Found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Lead Source Not Found"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="string", example="Invalid Lead Source id 999"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to fetch the Lead Source",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to fetch the Lead Source"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="string", example="SQLSTATE error message..."))
     *         )
     *     )
     * )
     */

    public function delete($id, Request $request)
    {
        try {
            $leadSourceConfig = LeadSourceConfig::on("mysql_" . $request->auth->parent_id)->where('id', $id)->get()->first();
            $deleted = $leadSourceConfig->delete();
            if ($deleted) {
                return $this->successResponse("Lead Source Deleted", $leadSourceConfig->toArray());
            } else {
                return $this->failResponse("Failed to delete the Lead Source ", [
                    "Unkown"
                ]);
            }
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Lead Source Not Found", [
                "Invalid Lead Source id $id"
            ], $exception, 404);
        } catch (\Exception $exception) {
            return $this->failResponse("Failed to fetch the Lead Source ", [
                $exception->getMessage()
            ], $exception, 500);
        }
    }


    /**
     * @OA\Post(
     *     path="/update-lead-source-config/{id}",
     *     summary="Update an existing lead source configuration",
     *     tags={"LeadSourceConfig"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="title", type="string"),
     *             @OA\Property(property="description", type="string"),
     *             @OA\Property(property="list_id", type="integer")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Updated successfully"),
     *     @OA\Response(response=404, description="Not found"),
     *     @OA\Response(response=500, description="Server error")
     * )
     */
    public function update($id, Request $request)
    {
        try {
            $config = LeadSourceConfig::on("mysql_" . $request->auth->parent_id)->findOrFail($id);

            $fields = $request->only(['title', 'description', 'list_id']);
            $config->fill($fields);
            $config->save();

            return $this->successResponse("Updated Successfully", $config->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Lead Source Config Not Found", [
                "Invalid Lead Source Config id $id"
            ], $exception, 404);
        } catch (\Exception $exception) {
            return $this->failResponse("Failed to update Lead Source Config", [
                $exception->getMessage()
            ], $exception, 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/header-by-listid/{id}",
     *     summary="Get list header by List ID",
     *     tags={"LeadSourceConfig"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the list",
     *         @OA\Schema(type="integer", example=44)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="List header fetched successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="campaign Info"),
     *             @OA\Property(property="data", type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=446),
     *                     @OA\Property(property="list_id", type="integer", example=44),
     *                     @OA\Property(property="header", type="string", example="Postal Code"),
     *                     @OA\Property(property="column_name", type="string", example="option_9"),
     *                     @OA\Property(property="label_id", type="integer", example=19),
     *                     @OA\Property(property="is_search", type="integer", example=0),
     *                     @OA\Property(property="is_dialing", type="integer", example=0),
     *                     @OA\Property(property="is_visible", type="integer", example=1),
     *                     @OA\Property(property="is_editable", type="integer", example=0),
     *                     @OA\Property(property="is_deleted", type="integer", example=0),
     *                     @OA\Property(property="alternate_phone", type="string", example=null),
     *                     @OA\Property(property="updated_at", type="string", format="date-time", example="2021-09-20 18:08:19")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="List Header Not Found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="List Header Not Found"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="string", example="Invalid List id 99"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to fetch the Header"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="string", example="SQL error message..."))
     *         )
     *     )
     * )
     */

    public function headerByListId(Request $request, int $id)
    {
        try {
            $listHeader = ListHeader::on("mysql_" . $request->auth->parent_id)->where('list_id', $id)->get();
            return $this->successResponse("campaign Info", $listHeader->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("List Header Not Found", [
                "Invalid List id $id"
            ], $exception, 404);
        } catch (\Exception $exception) {
            return $this->failResponse("Failed to fetch the Header ", [
                $exception->getMessage()
            ], $exception, 500);
        }
    }

    /**
     * @OA\get(
     *     path="/insert-lead-source",
     *     summary="Insert a lead using API token",
     *     tags={"LeadSourceConfig"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(name="token", in="query", required=true, @OA\Schema(type="string")),
     *     @OA\Parameter(name="first_name", in="query", required=true, @OA\Schema(type="string")),
     *     @OA\Parameter(name="last_name", in="query", required=true, @OA\Schema(type="string")),
     *     @OA\Parameter(name="number", in="query", required=true, @OA\Schema(type="string")),
     *     @OA\Parameter(name="email", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="provider", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="city", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="state", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="postal_code", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="lead_source", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="funded_amount_", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="annual_income", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="business_type", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="date", in="query", required=false, @OA\Schema(type="string", format="date")),
     *     @OA\Response(
     *         response=200,
     *         description="Lead Added Successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Lead Added Successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=234),
     *                 @OA\Property(property="list_id", type="integer", example=44),
     *                 @OA\Property(property="option_1", type="string", example="John"),
     *                 @OA\Property(property="option_2", type="string", example="Doe"),
     *                 @OA\Property(property="option_3", type="string", example="9876543210")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Token or Lead Source Not Found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Lead Source Not Found"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="string", example="Invalid Lead Source Id"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to insert lead"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="string", example="Error details"))
     *         )
     *     )
     * )
     */

    function insertLeadSource(Request $request)
    {
        try {
            $lead_source_token = $request->token;
            $leadSource = LeadSourceApi::on("master")->where("api_key", "=", $lead_source_token)->first()->toArray();

            if (!empty($leadSource)) {
                try {
                    $sourceConfig = LeadSourceConfig::on("mysql_" . $leadSource['client_id'])->where("api_key", "=", $lead_source_token)->first()->toArray();

                    if (!empty($sourceConfig)) {
                        $listHeader = ListHeader::on("mysql_" . $request->auth->parent_id)->where('list_id', $sourceConfig['list_id'])->get()->toArray();

                        //echo "<pre>";print_r($campaign);die;
                        foreach ($listHeader as $header) {
                            $header_name = str_replace(' ', '_', strtolower($header['header']));
                            $column_name = $header['column_name'];
                            $leads[$column_name] = $request->$header_name;
                        }
                    }

                    $leads['list_id'] = $sourceConfig['list_id'];
                    $leads_data = $leads;

                    $listData = new ListData($leads_data);
                    $listData->setConnection("mysql_" . $leadSource['client_id']);
                    $listData->saveOrFail();

                    return $this->successResponse("Lead Added Successfully", $listData->toArray());
                } catch (ModelNotFoundException $exception) {
                    return $this->failResponse("Lead Source Not Found", [
                        "Invalid Lead Source Id $lead_source_token"
                    ], $exception, 404);
                } catch (\Throwable $exception) {
                    return $this->failResponse("Failed to find Invalid Lead Source Id", [
                        $exception->getMessage()
                    ], $exception, 404);
                }
            }
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("TokenId Not Found", [
                "Invalid token id $lead_source_token"
            ], $exception, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to find Invalid TokenId", [
                $exception->getMessage()
            ], $exception, 404);
        }
    }
}
