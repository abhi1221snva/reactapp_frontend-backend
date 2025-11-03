<?php

namespace App\Http\Controllers;

use App\Model\User;
use App\Model\Client\ExtensionGroupMap;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExtensionGroupMapController extends Controller
{
    /**
     * @OA\Get(
     *     path="/extension-group-map",
     *     summary="Get Extension Group Map List",
     *     description="Retrieves a list of extension-to-group mappings, including user details such as extension, first name, last name, and user ID.",
     *     tags={"Extension Group Map"},
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
     *         description="Extension Group Map List fetched successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Extension Group Map List"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=10),
     *                     @OA\Property(property="extension", type="string", example="1001"),
     *                     @OA\Property(property="group_id", type="integer", example=3),
     *                     @OA\Property(property="created_at", type="string", format="date-time", example="2025-06-26T10:00:00Z"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time", example="2025-06-26T10:05:00Z"),
     *                     @OA\Property(property="ext", type="string", example="1001"),
     *                     @OA\Property(property="first_name", type="string", example="Jane"),
     *                     @OA\Property(property="last_name", type="string", example="Doe"),
     *                     @OA\Property(property="user_id", type="integer", example=45)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to fetch Extension Group Map List")
     *         )
     *     )
     * )
     */


    public function indexold(Request $request)
{
    try {
        // Build base query
        $extensionGroupMap = "
            SELECT 
                egm.*,
                up.extension AS ext,
                up.first_name,
                up.last_name,
                up.id AS user_id
            FROM master.users AS up
            JOIN client_{$request->auth->parent_id}.extension_group_map AS egm 
                ON egm.extension = up.extension
            WHERE 1=1
        ";

        $params = [];

        // Optional filter by group_id
        if ($request->has('group_id') && !empty($request->group_id)) {
            $extensionGroupMap .= " AND egm.group_id = :group_id";
            $params['group_id'] = $request->group_id;
        }

        // Execute query
        $groupMap = DB::select($extensionGroupMap, $params);

        // Pagination logic
        if ($request->has('start') && $request->has('limit')) {
            $total_row = count($groupMap);

            $start = (int) $request->input('start', 0);  // default 0
            $limit = (int) $request->input('limit', 10); // default 10

            $groupMap = array_slice($groupMap, $start, $limit, false);

            return $this->successResponse("Extension Group Map List", [
                'start' => $start,
                'limit' => $limit,
                'total' => $total_row,
                'data' => $groupMap
            ]);
        }

        // Return without pagination
        return $this->successResponse("Extension Group Map List", $groupMap);

    } catch (\Exception $e) {
        return $this->errorResponse("Failed to fetch Extension Group Map", $e->getMessage());
    }
}
public function index(Request $request)
{
    try {
        // Build base query
        $extensionGroupMap = "
            SELECT 
                egm.group_id,
                egm.extension,
                up.extension AS ext,
                up.first_name,
                up.last_name,
                up.id AS user_id
            FROM master.users AS up
            JOIN client_{$request->auth->parent_id}.extension_group_map AS egm 
                ON egm.extension = up.extension
            WHERE 1=1
        ";

        $params = [];

        // Optional filter by group_id
        if ($request->filled('group_id')) {
            $extensionGroupMap .= " AND egm.group_id = :group_id";
            $params['group_id'] = $request->group_id;
        }

        // Execute query
        $groupMap = DB::select($extensionGroupMap, $params);

        // ✅ Remove "is_deleted" if it somehow exists in the record
        foreach ($groupMap as &$row) {
            if (isset($row->is_deleted)) {
                unset($row->is_deleted);
            }
        }

        // Pagination logic
        if ($request->has('start') && $request->has('limit')) {
            $total_row = count($groupMap);

            $start = (int) $request->input('start', 0);  // default 0
            $limit = (int) $request->input('limit', 10); // default 10

            $groupMap = array_slice($groupMap, $start, $limit, false);

            return $this->successResponse("Extension Group Map List", [
                'start' => $start,
                'limit' => $limit,
                'total' => $total_row,
                'data' => $groupMap
            ]);
        }

        // Return without pagination
        return $this->successResponse("Extension Group Map List", $groupMap);

    } catch (\Exception $e) {
        return $this->errorResponse("Failed to fetch Extension Group Map", $e->getMessage());
    }
}

}
