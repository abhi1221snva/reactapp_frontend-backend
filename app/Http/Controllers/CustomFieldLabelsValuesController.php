<?php

namespace App\Http\Controllers;

use App\Model\Client\CustomFieldLabelsValues;
use Illuminate\Http\Request;


class CustomFieldLabelsValuesController extends Controller
{

    /**
     * @OA\Get(
     *     path="/custom-field-labels-values",
     *     summary="Get list of custom field label values",
     *     tags={"Custom Field Labels Value"},
     *     security={{"Bearer":{}}},
     *       @OA\Parameter(
     *         name="search",
     *         in="query",
     *         required=false,
     *         description="Search term to filter custom field label values",
     *         @OA\Schema(type="string")
     *     ),
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
     *         description="List of custom field label values",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Custom Field Labels Values List"),
     *             description="extension data",
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized - Invalid Token"
     *     )
     * )
     */

    public function index(Request $request)
    {
        $custom_field_labels_values = CustomFieldLabelsValues::on("mysql_" . $request->auth->parent_id)->where('user_id', $request->auth->id)->get()->all();


        if ($request->has('search')) {
            $query = CustomFieldLabelsValues::on("mysql_" . $request->auth->parent_id)
                ->where('user_id', $request->auth->id);

            $searchTerm = $request->input('search');
            $query->where(function ($q) use ($searchTerm) {
                $q->where('title_match', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('title_links', 'LIKE', "%{$searchTerm}%");
            });
            $allResults = $query->get()->all();
            $total_row = count($allResults);


            return $this->successResponse("Custom Field Labels Values List", [
                'total' => $total_row,
                'data' =>  $allResults
            ]);
        }
        if ($request->has('start') && $request->has('limit')) {
            $total_row = count($custom_field_labels_values);

            $start = (int) $request->input('start');  // Start index (0-based)
            $limit = (int) $request->input('limit');  // Number of records to fetch

            $custom_field_labels_values = array_slice($custom_field_labels_values, $start, $limit, false);

            return $this->successResponse("Custom Field Labels Values List", [
                'start' => $start,
                'limit' => $limit,
                'total' => $total_row,
                'data' => $custom_field_labels_values
            ]);
        }
        return $this->successResponse("Custom Field Labels Values List", $custom_field_labels_values);
    }

    public function index_old(Request $request)
    {
        $custom_field_labels_values = CustomFieldLabelsValues::on("mysql_" . $request->auth->parent_id)->where('user_id', $request->auth->id)->get()->all();
        return $this->successResponse("Custom Field Labels Values List", $custom_field_labels_values);
    }

    /**
     * @OA\Put(
     *     path="/custom-field-labels-value",
     *     summary="Create a new custom field label value",
     *     tags={"Custom Field Labels Value"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"title_match"},
     *             @OA\Property(property="custom_id", type="integer", example=1, description="ID of the related custom field"),
     *             @OA\Property(property="title_match", type="string", example="Facebook", description="Field label value"),
     *             @OA\Property(property="user_id", type="integer", example=101, description="User ID (automatically injected if applicable)"),
     *             @OA\Property(property="title_links", type="string", example="https://example.com", description="Optional link related to the title"),
     *             @OA\Property(property="is_deleted", type="boolean", example=false, description="Soft delete status")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Custom Field Value created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Custom Field Value created"),
     *             description="extension data"
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error"
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized - Invalid Token"
     *     )
     * )
     */
    public function create(Request $request)
    {
        $this->validate($request, [
            'title_match' => 'required|string',
        ]);
        $attributes = $request->all();
        $custom_values = CustomFieldLabelsValues::on("mysql_" . $request->auth->parent_id)->create($attributes);
        $custom_values->saveOrFail();
        return $this->successResponse("Custom Field Value created", $custom_values->toArray());
    }

    /**
     * @OA\Get(
     *     path="/custom-field-value/{id}",
     *     summary="Get Custom Field Label Value by ID",
     *     tags={"Custom Field Labels Value"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the Custom Field Label Value",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Custom Field value info retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Custom Field value info"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Custom Field value not found"
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized"
     *     )
     * )
     */
    public function show(Request $request, int $id)
    {
        try {
            $custom_field_values = CustomFieldLabelsValues::on("mysql_" . $request->auth->parent_id)->findOrFail($id);
            $data = $custom_field_values->toArray();
            return $this->successResponse("Custom Field value info", $data);
        } catch (ModelNotFoundException $exception) {
            throw new NotFoundHttpException("No Custom Field value with id $id");
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch Custom Field value info", [], $exception);
        }
    }

    /**
     * @OA\Post(
     *     path="/custom-field-value/{id}",
     *     summary="Update a Custom Field Labels Value",
     *     tags={"Custom Field Labels Value"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the custom field label value to update",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"title_links", "title_match"},
     *             @OA\Property(property="title_links", type="string", example="http://example.com", description="Link value"),
     *             @OA\Property(property="title_match", type="string", example="Match Title", description="Title match value")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Custom Field value updated",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Custom Field value updated"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Custom Field value not found"
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized"
     *     )
     * )
     */
    public function update(Request $request, int $id)
    {
        $this->validate($request, [
            'title_links' => 'required|string',
            'title_match' => 'required|string',

        ]);
        $input = $request->all();
        try {
            $custom_field_labels = CustomFieldLabelsValues::on("mysql_" . $request->auth->parent_id)->findOrFail($id);
            $custom_field_labels->update($input);
            $data = $custom_field_labels->toArray();
            return $this->successResponse("Custom Field value updated", $data);
        } catch (ModelNotFoundException $exception) {
            throw new NotFoundHttpException("No Custom Field value with id $id");
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update Custom Field value", [], $exception);
        }
    }

    /**
     * @OA\Get(
     *     path="/custom-label-value/{id}",
     *     summary="Get Custom Field Label Value by Custom ID",
     *     tags={"Custom Field Labels Value"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Custom ID for which to retrieve the label value",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Custom Field value info retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Custom Field value info"),
     *             description="extension data"
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="No Custom Field value found for the given custom ID"
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized - Invalid Token"
     *     )
     * )
     */
    public function showCustomLabelValue(Request $request, int $id)
    {
        try {
            $custom_field_values = CustomFieldLabelsValues::on("mysql_" . $request->auth->parent_id)->Where('custom_id', $id)->get()->first();
            $data = $custom_field_values->toArray();
            return $this->successResponse("Custom Field value info", $data);
        } catch (ModelNotFoundException $exception) {
            throw new NotFoundHttpException("No Custom Field value with id $id");
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch Custom Field value info", [], $exception);
        }
    }

    /**
     * @OA\Get(
     *     path="/delete-custom-field-value/{id}",
     *     summary="Delete Custom Field Label Value by ID",
     *     tags={"Custom Field Labels Value"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Delete Custom Field Value",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Custom Field value info deleted successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Custom Field value info"),
     *             description="extension data"
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="No Custom Field value found for the given ID"
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized - Invalid Token"
     *     )
     * )
     */
    public function delete(Request $request, int $id)
    {
        try {
            $custom_field_labels = CustomFieldLabelsValues::on("mysql_" . $request->auth->parent_id)->findOrFail($id);
            $data = $custom_field_labels->delete();
            return $this->successResponse("Custom Field value info", [$data]);
        } catch (ModelNotFoundException $exception) {
            throw new NotFoundHttpException("No Custom Field value with id $id");
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch Custom Field value info", [], $exception);
        }
    }
}
