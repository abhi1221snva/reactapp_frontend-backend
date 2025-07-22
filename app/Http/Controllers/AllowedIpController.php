<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Model\Client\AllowedIp;

class AllowedIpController extends Controller
{

    /**
     * @OA\Get(
     *     path="/allowed-ips",
     *     summary="List all allowed IPs",
     *     description="Fetches the list of allowed IPs for the authenticated client's account.",
     *     tags={"Allowed IP"},
     *     security={{"Bearer":{}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Allowed IPs List",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Allowed IPs List"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="ip_address", type="string", example="192.168.1.1"),
     *                     @OA\Property(property="created_at", type="string", format="date-time", example="2025-04-22T12:34:56Z"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time", example="2025-04-22T12:34:56Z")
     *                 )
     *             )
     *         )
     *     )
     * )
     */

    public function index(Request $request)
    {
        $allowed_ips = AllowedIp::on("mysql_" . $request->auth->parent_id)->get()->all();
        return $this->successResponse("Allowed IPs List", $allowed_ips);
    }

    /**
     * @OA\Put(
     *     path="/allowed-ip",
     *     summary="Create a new allowed IP",
     *     description="Stores a new allowed IP address for the authenticated client",
     *     tags={"Allowed IP"},
     *     security={{"Bearer":{}}},
     *     
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"ip_address"},
     *             @OA\Property(property="ip_address", type="string", example="192.168.1.100"),
     *             @OA\Property(property="label", type="string", example="label"),
     *             @OA\Property(property="is_primary", type="string", example="0"),
     *             @OA\Property(property="status", type="string", example="0")
     *         )
     *     ),
     *     
     *     @OA\Response(
     *         response=200,
     *         description="Allowed IP created",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Allowed IP created"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="ip_address", type="string", example="192.168.1.100"),
     *                 @OA\Property(property="created_at", type="string", format="date-time", example="2025-04-22T12:34:56Z"),
     *                 @OA\Property(property="updated_at", type="string", format="date-time", example="2025-04-22T12:34:56Z")
     *             )
     *         )
     *     ),
     *     
     *     @OA\Response(
     *         response=422,
     *         description="Validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="The given data was invalid."),
     *             @OA\Property(property="errors", type="object")
     *         )
     *     )
     * )
     */

    public function create(Request $request)
    {
        $this->validate($request, [
            'ip_address' => 'required|string|max:255|unique:' . 'mysql_' . $request->auth->parent_id . '.allowed_ip',
        ]);
        $attributes = $request->all();
        $allowed_ip = AllowedIp::on("mysql_" . $request->auth->parent_id)->create($attributes);
        $allowed_ip->saveOrFail();
        return $this->successResponse("Allowed IP created", $allowed_ip->toArray());
    }

    /**
     * @OA\Get(
     *     path="/allowed-ip/{id}",
     *     summary="Get a specific allowed IP",
     *     description="Returns the details of a specific allowed IP by ID for the authenticated user",
     *     tags={"Allowed IP"},
     *     security={{"Bearer":{}}},
     *     
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Allowed IP ID",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     
     *     @OA\Response(
     *         response=200,
     *         description="Allowed IP info",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Allowed IP info"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="ip_address", type="string", example="192.168.1.100"),
     *                 @OA\Property(property="created_at", type="string", format="date-time", example="2025-04-22T10:00:00Z"),
     *                 @OA\Property(property="updated_at", type="string", format="date-time", example="2025-04-22T11:00:00Z")
     *             )
     *         )
     *     ),
     *     
     *     @OA\Response(
     *         response=404,
     *         description="Allowed IP not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="No Tariff Label with id 1"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string"))
     *         )
     *     )
     * )
     */

    public function show(Request $request, int $id)
    {
        try {
            $allowed_ip = AllowedIp::on("mysql_" . $request->auth->parent_id)->findOrFail($id);
            $data = $allowed_ip->toArray();
            return $this->successResponse("Allowed IP info", $data);
        } catch (ModelNotFoundException $exception) {
            throw new NotFoundHttpException("No Tariff Label with id $id");
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch Tariff Label info", [], $exception);
        }
    }


    /**
     * @OA\Post(
     *     path="/allowed-ip/{id}",
     *     summary="Update a specific allowed IP",
     *     description="Updates the details of a specific allowed IP by ID for the authenticated user",
     *     tags={"Allowed IP"},
     *     security={{"Bearer":{}}},
     *     
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Allowed IP ID",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     
     *     @OA\RequestBody(
     *         required=true,
     *         description="Data to update the allowed IP",
     *         @OA\JsonContent(
     *             required={"ip_address"},
     *             @OA\Property(property="ip_address", type="string", example="192.168.1.100"),
     *             @OA\Property(property="label", type="string", example="label"),
     *             @OA\Property(property="is_primary", type="string", example="0"),
     *             @OA\Property(property="status", type="string", example="0")
     *         )
     *     ),
     *     
     *     @OA\Response(
     *         response=200,
     *         description="Allowed IP updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Allowed IP updated"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="ip_address", type="string", example="192.168.1.101"),
     *                 @OA\Property(property="created_at", type="string", format="date-time", example="2025-04-22T10:00:00Z"),
     *                 @OA\Property(property="updated_at", type="string", format="date-time", example="2025-04-22T11:00:00Z")
     *             )
     *         )
     *     ),
     *     
     *     @OA\Response(
     *         response=404,
     *         description="Allowed IP not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="No Allowed IP with id 1"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string"))
     *         )
     *     )
     * )
     */

    public function update(Request $request, int $id)
    {
        $this->validate($request, [
            'ip_address' => 'required|string|max:255',
        ]);
        $input = $request->all();
        try {
            $allowed_ip = AllowedIp::on("mysql_" . $request->auth->parent_id)->findOrFail($id);
            $allowed_ip->update($input);
            $data = $allowed_ip->toArray();

            return $this->successResponse("Allowed IP updated", $data);
        } catch (ModelNotFoundException $exception) {
            throw new NotFoundHttpException("No Allowed IP with id $id");
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update Allowed IP", [], $exception);
        }
    }

    /**
     * @OA\get(
     *     path="/delete-allowed-ip/{id}",
     *     summary="Delete a specific allowed IP",
     *     description="Deletes a specific allowed IP by ID for the authenticated user",
     *     tags={"Allowed IP"},
     *     security={{"Bearer":{}}},
     *     
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Allowed IP ID",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     
     *     @OA\Response(
     *         response=200,
     *         description="Allowed IP deleted successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Allowed IP deleted"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="boolean", example=true))
     *         )
     *     ),
     *     
     *     @OA\Response(
     *         response=404,
     *         description="Allowed IP not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="No Allowed IP with id 1"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string"))
     *         )
     *     )
     * )
     */

    public function delete(Request $request, int $id)
    {
        try {
            $allowed_ip = AllowedIp::on("mysql_" . $request->auth->parent_id)->findOrFail($id);
            $data = $allowed_ip->delete();

            return $this->successResponse("Allowed Ip info", [$data]);
        } catch (ModelNotFoundException $exception) {
            throw new NotFoundHttpException("No Allowed IP with id $id");
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch Allowed IP info", [], $exception);
        }
    }

/**
 * @OA\Post(
 *     path="/status-update-allowed-ip",
 *     summary="Update status of an allowed IP",
 *     description="Updates the status of an allowed IP record by its ID for the authenticated user's account.",
 *     tags={"Allowed IP"},
 *     security={{"Bearer":{}}},
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             required={"listId", "status"},
 *             @OA\Property(
 *                 property="listId",
 *                 type="integer",
 *                 example=1,
 *                 description="ID of the allowed IP record to update"
 *             ),
 *             @OA\Property(
 *                 property="status",
 *                 type="integer",
 *                 example=1,
 *                 description="New status value (1 for active, 0 for inactive)"
 *             )
 *         )
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Allowed IP status updated successfully",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="string", example="true"),
 *             @OA\Property(property="status", type="string", example="true"),
 *             @OA\Property(property="message", type="string", example="Allowed IP Status updated successfully")
 *         )
 *     ),
 *     @OA\Response(
 *         response=400,
 *         description="Update failed",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="string", example="false"),
 *             @OA\Property(property="status", type="string", example="false"),
 *             @OA\Property(property="message", type="string", example="Allowed IP  Status  update failed")
 *         )
 *     )
 * )
 */


    function updateAllowedIpStatus(Request $request)
    {
        $listId = $request->input('listId');
        $status = $request->input('status');

        $saveRecord = AllowedIp::on('mysql_' . $request->auth->parent_id)
            ->where('id', $listId) // Use the actual listId received from the request
            ->update(array('status' => $status));


        // Log::debug('Received listId: ', ['listId' => $listId]);
        // Log::debug('Received status: ', ['status' => $status]);
        // Log::debug('Number of updated rows: ', ['saveRecord' => $saveRecord]);
        if ($saveRecord > 0) {
            return response()->json([
                'success' => 'true',
                'status' => 'true',
                'message' => 'Allowed IP Status updated successfully'
            ]);
        } else {
            return response()->json([
                'success' => 'false',
                'status' => 'false',
                'message' => 'Allowed IP  Status  update failed'
            ]);
        }
    }
}
