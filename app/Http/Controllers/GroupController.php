<?php

namespace App\Http\Controllers;

use App\Model\Client\ExtensionGroup;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Model\User;
use Illuminate\Support\Facades\Log;



class GroupController extends Controller
{
    /**
     * @OA\Get(
     *      path="/extension-group",
     *      summary="List Extension Groups",
     *      tags={"Extension Group"},
     *      security={{"Bearer":{}}},
     *        @OA\Parameter(
     *          name="start",
     *          in="query",
     *          description="Start index for pagination",
     *          required=false,
     *          @OA\Schema(type="integer", default=0)
     *      ),
     *      @OA\Parameter(
     *          name="limit",
     *          in="query",
     *          description="Limit number of records returned",
     *          required=false,
     *          @OA\Schema(type="integer", default=10)
     *      ),
     *      @OA\Response(
     *          response="200",
     *          description="Group list"
     *      ),
     *      @OA\Response(
     *          response="401",
     *          description="Access denied"
     *      ),
     *      @OA\Response(
     *          response="403",
     *          description="Forbidden"
     *      )
     * )
     */
    // public function list(Request $request)
    // {
    //     try {
    //         $clientId = $request->auth->parent_id;
    //         $extGroups = [];
    //         if ($request->auth->level < 7) {
    //             if (!empty($request->auth->groups)) {
    //                 $extGroups = ExtensionGroup::on("mysql_$clientId")->whereIn("id", $request->auth->groups)->get()->all();
    //             }
    //         } else {
    //             $extGroups = ExtensionGroup::on("mysql_$clientId")->where(["is_deleted" => 0])->get()->all();
    //         }

    //     // Apply pagination if present
    //     if ($request->has(['start', 'limit'])) {
    //         $start = (int)$request->input('start');
    //         $limit = (int)$request->input('limit');
    //         $extGroups = array_slice($extGroups, $start, $limit, true); // paginate array
    //     }
    //         return $this->successResponse("Extension Groups", $extGroups);
    //     } catch (\Throwable $exception) {
    //         return $this->failResponse("Failed to list extension groups", [$exception->getMessage()], $exception, $exception->getCode());
    //     }
    // }
    public function list(Request $request)
    {
        try {
            $clientId = $request->auth->parent_id;
            $extGroups = [];

            // Step 1: Fetch all extension groups based on user level
            if ($request->auth->level < 7) {
                if (!empty($request->auth->groups)) {
                    $extGroups = ExtensionGroup::on("mysql_$clientId")
                        ->whereIn("id", $request->auth->groups)
                        ->where("is_deleted", 0)
                        ->get()
                        ->toArray();
                }
            } else {
                $extGroups = ExtensionGroup::on("mysql_$clientId")
                    ->where("is_deleted", 0)
                    ->get()
                    ->toArray();
            }

            // Step 2: Apply search filter (case-insensitive)
            if ($request->filled('search')) {
                $search = strtolower($request->input('search'));

                $extGroups = array_filter($extGroups, function ($group) use ($search) {
                    return str_contains(strtolower($group['title'] ?? ''), $search);
                });
            }

            // Step 3: Save total before pagination
            $total = count($extGroups);

            // Step 4: Apply pagination if start and limit exist
            if ($request->has(['start', 'limit'])) {
                $start = (int) $request->input('start', 0);
                $limit = (int) $request->input('limit', 10);
                $extGroups = array_slice($extGroups, $start, $limit);
            }

            // Step 5: Return data with total
            return response()->json([
                'success' => true,
                'message' => 'Extension Groups',
                'data' => array_values($extGroups),
                'total' => $total,
            ]);
        } catch (\Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to list extension groups',
                'errors' => [$exception->getMessage()],
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *      path="/extension-group/{id}",
     *      summary="Show Extension Group",
     *      tags={"Extension Group"},
     *      security={{"Bearer":{}}},
     *      @OA\Parameter(
     *          name="id",
     *          description="Id",
     *          required=true,
     *          in="path",
     *          @OA\Schema(
     *              type="integer"
     *          )
     *      ),
     *      @OA\Response(
     *          response="200",
     *          description="Group data"
     *      ),
     *      @OA\Response(
     *          response="401",
     *          description="Access denied"
     *      ),
     *      @OA\Response(
     *          response="403",
     *          description="Forbidden"
     *      ),
     *      @OA\Response(
     *          response="404",
     *          description="No extension group with id xx"
     *      )
     * )
     */
    public function show(Request $request, $id)
    {
        try {
            $clientId = $request->auth->parent_id;
            $extGroup = ExtensionGroup::on("mysql_$clientId")->findOrFail($id);
            if (!$extGroup->is_deleted) {
                if ($request->auth->level < 7) {
                    if (!in_array($id, $request->auth->groups)) {
                        return $this->failResponse("Access denied", ["Unauthorized"], null, 403);
                    }
                }
                return $this->successResponse("Extension Group", $extGroup->toArray());
            } else {
                return $this->failResponse("Extension group not found", ["Invalid extension group id $id"], null, 404);
            }
        } catch (ModelNotFoundException $notFoundException) {
            return $this->failResponse("No extension group with id $id", [], $notFoundException, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch extension group $id", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }

    /**
     * @OA\Patch(
     *     path="/extension-group/{id}",
     *     summary="Update Extension Group",
     *     tags={"Extension Group"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Extension Group ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         description="Extension group update data",
     *         @OA\JsonContent(
     *             type="object",
     *             required={"title", "status", "extensions"},
     *             @OA\Property(
     *                 property="title",
     *                 type="string",
     *                 example="Sales Support Group"
     *             ),
     *             @OA\Property(
     *                 property="status",
     *                 type="boolean",
     *                 example=true
     *             ),
     *             @OA\Property(
     *                 property="extensions",
     *                 type="array",
     *                 @OA\Items(type="string"),
     *                 example={"31006,38187"}
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Updated extension group data",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Extension group updated"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="title", type="string", example="Sales Support Group"),
     *                 @OA\Property(property="status", type="boolean", example=true),
     * *                 @OA\Property(
*                     property="extensions",
*                     type="array",
*                     @OA\Items(type="string"),
*                     example={"31006", "38187"}
*                 )
     *                  
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Access denied"
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="Forbidden"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="No extension group with id XX"
     *     )
     * )
     */

    public function patch(Request $request, $id)
    {
        $this->validate($request, [
            'title'     => 'required|sometimes|string|max:255',
            'status'    => 'required|sometimes|boolean'
        ]);

        try {
            $extGroup = ExtensionGroup::on("mysql_" . $request->auth->parent_id)->findOrFail($id);
            if (!$extGroup->is_deleted) {
                if ($request->has("title")) $extGroup->title = $request->input("title");
                if ($request->has("status")) $extGroup->status = $request->input("status");
                $extGroup->saveOrFail();

                $extension = $request->extensions;
                $data['id'] = $id;
                $query = "DELETE FROM extension_group_map WHERE group_id = :id";
                $save = DB::connection('mysql_' . $request->auth->parent_id)->update($query, $data);
                //return $extension;

                foreach ($extension as $value) {

                    $allTypeExtension = User::where('extension', $value)->first();
                    $sql = "INSERT INTO extension_group_map (extension, group_id) VALUES (:extension, :group_id)";
                    $updateGroup = DB::connection('mysql_' . $request->auth->parent_id)->insert($sql, array('extension' => $value, 'group_id' => $id));

                    $sql = "INSERT INTO extension_group_map (extension, group_id) VALUES (:extension, :group_id)";
                    $updateGroup = DB::connection('mysql_' . $request->auth->parent_id)->insert($sql, array('extension' => $allTypeExtension->alt_extension, 'group_id' => $id));

                    $sql = "INSERT INTO extension_group_map (extension, group_id) VALUES (:extension, :group_id)";
                    $updateGroup = DB::connection('mysql_' . $request->auth->parent_id)->insert($sql, array('extension' => $allTypeExtension->app_extension, 'group_id' => $id));
                }

                return $this->successResponse("Extension group updated", $extGroup->toArray());
            } else {
                return $this->failResponse("Extension group not found", ["Invalid extension group id $id"], null, 404);
            }
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Extension group not found", ["Invalid extension group id $id"], $exception, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update extension group", [$exception->getMessage()], $exception, 404);
        }
    }
   public function patchNew(Request $request)
    {
        $this->validate($request, [
            'title'     => 'required|sometimes|string|max:255',
            'status'    => 'required|sometimes|boolean',
            'group_id'        =>'required'

        ]);

        try {
            
           $id = $request->input('group_id');  // ✅ get id from request
            $extGroup = ExtensionGroup::on("mysql_" . $request->auth->parent_id)->findOrFail($id);
            if (!$extGroup->is_deleted) {
                if ($request->has("title")) $extGroup->title = $request->input("title");
                if ($request->has("status")) $extGroup->status = $request->input("status");
                $extGroup->saveOrFail();

                $extension = $request->extensions;
                $data['id'] = $id;
                $query = "DELETE FROM extension_group_map WHERE group_id = :id";
                $save = DB::connection('mysql_' . $request->auth->parent_id)->update($query, $data);
                //return $extension;

                foreach ($extension as $value) {

                    $allTypeExtension = User::where('extension', $value)->first();
                    $sql = "INSERT INTO extension_group_map (extension, group_id) VALUES (:extension, :group_id)";
                    $updateGroup = DB::connection('mysql_' . $request->auth->parent_id)->insert($sql, array('extension' => $value, 'group_id' => $id));

                    $sql = "INSERT INTO extension_group_map (extension, group_id) VALUES (:extension, :group_id)";
                    $updateGroup = DB::connection('mysql_' . $request->auth->parent_id)->insert($sql, array('extension' => $allTypeExtension->alt_extension, 'group_id' => $id));

                    $sql = "INSERT INTO extension_group_map (extension, group_id) VALUES (:extension, :group_id)";
                    $updateGroup = DB::connection('mysql_' . $request->auth->parent_id)->insert($sql, array('extension' => $allTypeExtension->app_extension, 'group_id' => $id));
                }

                return $this->successResponse("Extension group updated", $extGroup->toArray());
            } else {
                return $this->failResponse("Extension group not found", ["Invalid extension group id $id"], null, 404);
            }
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Extension group not found", ["Invalid extension group id $id"], $exception, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update extension group", [$exception->getMessage()], $exception, 404);
        }
    }
    /**
     * @OA\Delete(
     *      path="/extension-group/{id}",
     *      summary="Delete Extension Group",
     *      tags={"Extension Group"},
     *      security={{"Bearer":{}}},
     *      @OA\Parameter(
     *          name="id",
     *          description="Id",
     *          required=true,
     *          in="path",
     *          @OA\Schema(
     *              type="integer"
     *          )
     *      ),
     *      @OA\Response(
     *          response="200",
     *          description="Extension group deleted"
     *      ),
     *      @OA\Response(
     *          response="401",
     *          description="Access denied"
     *      ),
     *      @OA\Response(
     *          response="403",
     *          description="Forbidden"
     *      ),
     *      @OA\Response(
     *          response="404",
     *          description="No extension group with id xx"
     *      )
     * )
     */
    public function delete(Request $request, $id)
    {
        try {
            $extGroup = ExtensionGroup::on("mysql_" . $request->auth->parent_id)->findOrFail($id);
            if (!$extGroup->is_deleted) {
                $extGroup->title = $extGroup->title . "| Deleted on " . date("Y-m-d H:i:s");
                $extGroup->is_deleted = 1;
                $extGroup->saveOrFail();
                return $this->successResponse("Extension group deleted", $extGroup->toArray());
            } else {
                return $this->failResponse("Extension group not found", ["Invalid extension group id $id"], null, 404);
            }
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Extension group not found", ["Invalid extension group id $id"], $exception, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to delete extension group", [$exception->getMessage()], $exception, 404);
        }
    }
    public function deleteNew(Request $request)
    {
           $this->validate($request, [
            'group_id'        =>'required'

        ]);
                $id= $request->input('group_id');

        //Log::info("delete extension group",['id'=>$id]);
        //die($id);
        try {
            $extGroup = ExtensionGroup::on("mysql_" . $request->auth->parent_id)->findOrFail($id);
            if (!$extGroup->is_deleted) {
                $extGroup->title = $extGroup->title . "| Deleted on " . date("Y-m-d H:i:s");
                $extGroup->is_deleted = 1;
                $extGroup->saveOrFail();
                return $this->successResponse("Extension group deleted", $extGroup->toArray());
            } else {
                return $this->failResponse("Extension group not found", ["Invalid extension group id $id"], null, 404);
            }
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Extension group not found", ["Invalid extension group id $id"], $exception, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to delete extension group", [$exception->getMessage()], $exception, 404);
        }
    }
    /**
     * @OA\Put(
     *     path="/extension-group",
     *     summary="Create Extension Group",
     *     tags={"Extension Group"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Create a new extension group with optional extensions",
     *         @OA\JsonContent(
     *             type="object",
     *             required={"title"},
     *             @OA\Property(
     *                 property="title",
     *                 type="string",
     *                 example="Support Group"
     *             ),
     *             @OA\Property(
     *                 property="extensions",
     *                 type="array",
     *                 @OA\Items(type="string"),
     *                 example={"1010", "2020", "3030"}
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Extension group added successfully",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Extension group added successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="title", type="string", example="Support Group"),
     *                 @OA\Property(property="status", type="boolean", example=true)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Validation error"
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to add new extension group"
     *     )
     * )
     */
    public function add(Request $request)
    {
        $this->validate($request, [
            'title' => 'required|string|max:255',
            'extensions' => 'nullable|array',
            'extensions.*' => 'nullable|string',
        ]);

        try {
            $extensionGroup = new ExtensionGroup();
            $extensionGroup->setConnection("mysql_" . $request->auth->parent_id);
            $extensionGroup->title = $request->get('title');

            $extensionGroup->saveOrFail();
            if (!empty($request->get('extensions'))) {
                $extensionIds = $request->get('extensions');
                $id = $extensionGroup->id; // Assign the generated id to $id

                foreach ($extensionIds as $extensionId) {

                    $allTypeExtension = User::where('extension', $extensionId)->first();

                    $sql = "INSERT INTO extension_group_map (extension, group_id) VALUES (:extension, :group_id)";
                    $updateGroup = DB::connection('mysql_' . $request->auth->parent_id)->insert($sql, ['extension' => $extensionId, 'group_id' => $id]);

                    $sql = "INSERT INTO extension_group_map (extension, group_id) VALUES (:extension, :group_id)";
                    $updateGroup = DB::connection('mysql_' . $request->auth->parent_id)->insert($sql, ['extension' => $allTypeExtension->alt_extension, 'group_id' => $id]);

                    $sql = "INSERT INTO extension_group_map (extension, group_id) VALUES (:extension, :group_id)";
                    $updateGroup = DB::connection('mysql_' . $request->auth->parent_id)->insert($sql, ['extension' => $allTypeExtension->app_extension, 'group_id' => $id]);
                }
            }
            return $this->successResponse("Extension group added successfully", $extensionGroup->toArray());
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to add new extension group", [$exception->getMessage()], $exception, 500);
        }
    }

    // public function add(Request $request)
    // {
    //     $this->validate($request, [
    //         'title'     => 'required|string|max:255',
    //     ]);
    //     try {
    //         $extensionGroup = new ExtensionGroup();
    //         $extensionGroup->setConnection("mysql_" . $request->auth->parent_id);
    //         $extensionGroup->title = $request->get("title");
    //         extension = $request->extensions;

    //             //return $extension;

    //             foreach ($extension as $value) {
    //                 $sql = "INSERT INTO extension_group_map (extension, group_id) VALUES (:extension, :group_id)";
    //                 $updateGroup = DB::connection('mysql_' . $request->auth->parent_id)->insert($sql, array('extension' => $value, 'group_id' => $id));

    //             }
    //         $extensionGroup->saveOrFail();
    //         return $this->successResponse("Added Successfully", $extensionGroup->toArray());
    //     } catch (\Throwable $exception) {
    //         return $this->failResponse("Failed to add new extension group", [$exception->getMessage()], $exception, 500);
    //     }
    // }

    /**
     * @OA\Post(
     *     path="/status-update-group",
     *     summary="Update Extension Group Status",
     *     tags={"Extension Group"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Provide group ID and new status",
     *         @OA\JsonContent(
     *             type="object",
     *             required={"listId", "status"},
     *             @OA\Property(
     *                 property="listId",
     *                 type="integer",
     *                 example=1,
     *                 description="ID of the extension group"
     *             ),
     *             @OA\Property(
     *                 property="status",
     *                 type="boolean",
     *                 example=true,
     *                 description="New status of the extension group (true for active, false for inactive)"
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Status update response",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="status", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Group status updated successfully")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Status update failed",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="status", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Status update failed")
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized"
     *     )
     * )
     */

    function updateGroupStatus(Request $request)
    {
        $listId = $request->input('listId');
        $status = $request->input('status');

        $saveRecord = ExtensionGroup::on('mysql_' . $request->auth->parent_id)
            ->where('id', $listId) // Use the actual listId received from the request
            ->update(array('status' => $status));


        // Log::debug('Received listId: ', ['listId' => $listId]);
        // Log::debug('Received status: ', ['status' => $status]);
        // Log::debug('Number of updated rows: ', ['saveRecord' => $saveRecord]);
        if ($saveRecord > 0) {
            return response()->json([
                'success' => 'true',
                'status' => 'true',
                'message' => 'Group status updated successfully'
            ]);
        } else {
            return response()->json([
                'success' => 'false',
                'status' => 'false',
                'message' => 'Status  update failed'
            ]);
        }
    }
    public function deleteExtensionFromGroup(Request $request)
{
    $this->validate($request, [
        'group_id'     => 'required|numeric',
        'extension_id' => 'required|numeric',
    ]);

    $groupId     = $request->input('group_id');
    $extensionId = $request->input('extension_id');

    try {
        // ✅ Use the tenant connection
        $connection = "mysql_" . $request->auth->parent_id;

        // ✅ Find mapping entry
        $mapRecord = DB::connection($connection)
            ->table('extension_group_map')
            ->where('extension', $extensionId)
            ->where('group_id', $groupId)
            ->first();

        if (!$mapRecord) {
            return $this->failResponse(
                "Mapping not found",
                ["No mapping for extension $extensionId under group $groupId"],
                null,
                404
            );
        }

        // ✅ Check if already deleted
        if ($mapRecord->is_deleted == 1) {
            return $this->failResponse(
                "Extension already deleted from this group",
                ["Mapping already marked deleted"],
                null,
                400
            );
        }

        // ✅ Soft delete (update is_deleted flag)
        DB::connection($connection)
            ->table('extension_group_map')
            ->where('extension', $extensionId)
            ->where('group_id', $groupId)
            ->update([
                'is_deleted' => 1
            ]);

        return $this->successResponse("Extension deleted from group successfully", [
            'extension' => $extensionId,
            'group_id'  => $groupId,
            'is_deleted' => 1
        ]);

    } catch (\Throwable $exception) {
        return $this->failResponse(
            "Failed to delete extension from group",
            [$exception->getMessage()],
            $exception,
            500
        );
    }
}

}
