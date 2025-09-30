<?php

namespace App\Http\Controllers;

use App\Model\Client\CustomFieldLabels;
use Illuminate\Http\Request;
use App\Model\Client\CustomFieldLabelsValues;
use Illuminate\Support\Facades\DB;

class CustomFieldLabelController extends Controller
{
    /**
 * @OA\Get(
 *     path="/custom-field-labels",
 *     summary="Retrieve all custom field labels",
 *     tags={"CustomFieldLabels"},
 *     operationId="getCustomFieldLabels",
*     security={{"Bearer":{}}},
 *
 *     @OA\Response(
 *         response=200,
 *         description="Custom Field Labels List",
 *         @OA\JsonContent(
 *             type="array",
 *             @OA\Items(
 *                 type="object",
 *                 @OA\Property(property="id",          type="integer", example=1),
 *                 @OA\Property(property="label_key",   type="string",  example="phone_number"),
 *                 @OA\Property(property="label_name",  type="string",  example="Phone Number"),
 *                 @OA\Property(property="created_at",  type="string",  format="date-time", example="2025-06-01T12:34:56Z"),
 *                 @OA\Property(property="updated_at",  type="string",  format="date-time", example="2025-06-10T08:21:30Z")
 *             )
 *         )
 *     ),
 *     @OA\Response(
 *         response=401,
 *         description="Unauthenticated",
 *         @OA\JsonContent(
 *             @OA\Property(property="message", type="string", example="Unauthorized")
 *         )
 *     )
 * )
 */


    // public function index(Request $request)
    // {
    //     $custom_field_labels = CustomFieldLabels::on("mysql_" . $request->auth->parent_id)->get()->all();
    //     return $this->successResponse("Custom Field Labels List", $custom_field_labels);
    // }
public function index(Request $request)
{
    // Base query on dynamic DB connection
    $query = CustomFieldLabels::on("mysql_" . $request->auth->parent_id);

    // Apply search if present
    if ($request->has('search') && !empty($request->search)) {
        $search = $request->search;
        $query->where('title', 'like', "%{$search}%"); // adjust column name
    }

    // Clone query for counting
    $totalRows = $query->count();

    // Apply pagination only if both lower and upper limits are provided
    if ($request->has('start') && $request->has('limit')) {
        $lower = (int) $request->input('start');
        $upper = (int) $request->input('limit');
        $limit = max($upper - $lower, 0);
        $query->skip($lower)->take($limit);
    }

    $custom_field_labels = $query->get()->toArray();

    return $this->successResponse("Custom Field Labels List", [
        'total_rows' => $totalRows,
        'data'  => $custom_field_labels
    ]);
}

    public function create(Request $request)
    {
        $this->validate($request, [
            'title' => 'required|string|max:255|unique:'.'mysql_'.$request->auth->parent_id.'.custom_field_labels',
        ]);
        $attributes = $request->all();
        $create_custom = CustomFieldLabels::on("mysql_" . $request->auth->parent_id)->create($attributes);
        $create_custom->saveOrFail();
        return $this->successResponse("Custom Field Label created", $create_custom->toArray());
    }

    public function show(Request $request, int $id)
    {
        try
        {
            $custom_field_labels = CustomFieldLabels::on("mysql_" . $request->auth->parent_id)->findOrFail($id);
            $data = $custom_field_labels->toArray();
            return $this->successResponse("Custom Field Label info", $data);
        }
        catch (ModelNotFoundException $exception)
        {
            throw new NotFoundHttpException("No Custom Field Label with id $id");
        }
        catch (\Throwable $exception)
        {
            return $this->failResponse("Failed to fetch Custom Field Label info", [], $exception);
        }
    }

    public function update(Request $request, int $id)
    {
        $this->validate($request, ['title' => 'sometimes|required|string|max:255',]);
        $input = $request->all();
        try
        {
            $custom_field_labels = CustomFieldLabels::on("mysql_" . $request->auth->parent_id)->findOrFail($id);
            $custom_field_labels->update($input);
            $data = $custom_field_labels->toArray();

            $datas['title_match'] = $request->title;
            $datas['custom_id'] = $id;

            $sql_custom_fields_labels_values = "UPDATE custom_fields_labels_values SET title_match = :title_match where custom_id = :custom_id";
            DB::connection('mysql_'.$request->auth->parent_id)->update($sql_custom_fields_labels_values, $datas);
            return $this->successResponse("Custom Field Label updated", $data);
        }
        catch (ModelNotFoundException $exception)
        {
            throw new NotFoundHttpException("No Custom Field Label with id $id");
        }
        catch (\Throwable $exception)
        {
            return $this->failResponse("Failed to update Custom Field Label", [], $exception);
        }
    }

    public function delete(Request $request, int $id)
    {
        try
        {
            $custom_field_labels = CustomFieldLabels::on("mysql_" . $request->auth->parent_id)->findOrFail($id);
            $data = $custom_field_labels->delete();

            $custom_field_values = CustomFieldLabelsValues::on("mysql_" . $request->auth->parent_id)->where('custom_id',$id);
            $custom_field_values->delete();
            return $this->successResponse("Custom Field Label Deleted Sucessfully", [$data]);
        }
        catch (ModelNotFoundException $exception)
        {
            throw new NotFoundHttpException("No Custom Field Label with id $id");
        }
        catch (\Throwable $exception)
        {
            return $this->failResponse("Failed to fetch Custom Field Label info", [], $exception);
        }
    }

 
    
}
