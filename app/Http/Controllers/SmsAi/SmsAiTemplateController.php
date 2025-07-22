<?php

namespace App\Http\Controllers\SmsAi;

use App\Http\Controllers\Controller;
use App\Model\Client\SmsAi\SmsAiTemplates;
use App\Model\Client\SmsAI;
use App\Model\Master\Did;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class SmsAiTemplateController extends Controller
{


    /**
     * @OA\Get(
     *     path="/smsai/templates",
     *     summary="Get list of SMS AI Templates for authenticated client",
     *     tags={"SmsAiTemplate"},
     *     security={{"Bearer":{}}},
     *       @OA\Parameter(
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
     *     @OA\Response(
     *         response=200,
     *         description="Templates retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="SMS AI Templates"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="title", type="string", example="Order Confirmation"),
     *                     @OA\Property(property="content", type="string", example="Your order has been confirmed."),
     *                     @OA\Property(property="category", type="string", example="Transactional"),
     *                     @OA\Property(property="is_deleted", type="boolean", example=false),
     *                     @OA\Property(property="created_at", type="string", format="date-time", example="2024-01-15T12:00:00Z"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time", example="2024-03-01T08:45:00Z")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to retrieve templates",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Failed to list of SMS AI Templates")
     *         )
     *     )
     * )
     */
    public function indexold(Request $request)
    {
        try {
            $clientId = $request->auth->parent_id;
            //$clientId = 3;
            $setting = [];
            $setting = SmsAiTemplates::on("mysql_$clientId")->where('is_deleted', '0')->get()->all();
            return $this->successResponse("SMS AI Templates", $setting);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to list of SMS AI Templates", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }

public function index(Request $request)
{
    try {
        $clientId = $request->auth->parent_id;
        $query = SmsAiTemplates::on("mysql_$clientId")
            ->where('is_deleted', '0')
            ->orderBy('id', 'DESC');

        // Apply pagination if start and limit are present
        if ($request->has('start') && $request->has('limit')) {
            $start = (int) $request->input('start');
            $limit = (int) $request->input('limit');
            $query->skip($start)->take($limit);
        }

        $setting = $query->get();

        return $this->successResponse("SMS AI Templates", $setting->toArray());
    } catch (\Throwable $exception) {
        return $this->failResponse(
            "Failed to list of SMS AI Templates",
            [$exception->getMessage()],
            $exception,
            $exception->getCode()
        );
    }
}

    /**
     * @OA\Put(
     *     path="/smsai/template/add",
     *     summary="Create a new SMS AI Template",
     *     tags={"SmsAiTemplate"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"template_name"},
     *             @OA\Property(property="template_name", type="string", example="Order Confirmation"),
     *             @OA\Property(property="introduction", type="string", example="Used for confirming online orders"),
     *             @OA\Property(property="description", type="string", example="Hi {{name}}, your order #{{order_id}} has been confirmed!")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Template created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Added Successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="template_name", type="string", example="Order Confirmation"),
     *                 @OA\Property(property="introduction", type="string", example="Used for confirming online orders"),
     *                 @OA\Property(property="description", type="string", example="Hi {{name}}, your order #{{order_id}} has been confirmed!"),
     *                 @OA\Property(property="created_at", type="string", format="date-time", example="2025-04-25T10:00:00Z"),
     *                 @OA\Property(property="updated_at", type="string", format="date-time", example="2025-04-25T10:00:00Z")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Validation error or missing fields"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to save SMS AI Template"
     *     )
     * )
     */
    public function create(Request $request)
    {


        try {
            $input = $request->all();
            $smtp = new SmsAiTemplates();
            $smtp->setConnection("mysql_" . $request->auth->parent_id);

            if (!empty($input["template_name"])) $smtp->template_name = $input["template_name"];
            if (!empty($input["introduction"])) $smtp->introduction = $input["introduction"];
            if (!empty($input["description"])) $smtp->description = $input["description"];

            $smtp->saveOrFail();



            return $this->successResponse("Added Successfully", $smtp->toArray());
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to save SMS AI Template", [$exception->getMessage()], $exception, 500);
        }
    }


    /**
     * @OA\Get(
     *     path="/smsai/template/view/{id}",
     *     summary="Get a specific SMS AI Template by ID",
     *     tags={"SmsAiTemplate"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the SMS AI Template to fetch",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="SMS AI Template retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="SMS AI Template info"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="template_name", type="string", example="Order Confirmation"),
     *                     @OA\Property(property="introduction", type="string", example="Template for order confirmation"),
     *                     @OA\Property(property="description", type="string", example="Your order #{{order_id}} has been confirmed."),
     *                     @OA\Property(property="created_at", type="string", format="date-time", example="2024-01-01T10:00:00Z"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time", example="2024-01-02T15:00:00Z")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Template not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="No SMS AI Template with id 1")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to retrieve SMS AI Template"
     *     )
     * )
     */
    public function show(Request $request, int $id)
    {
        try {
            $template = SmsAiTemplates::on("mysql_" . $request->auth->parent_id)->where('id', $id)->get()->all();
            return $this->successResponse("SMS AI Template info", $template);
        } catch (ModelNotFoundException $exception) {
            throw new NotFoundHttpException("No SMS AI Template with id $id");
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch SMS AI Template info", [], $exception);
        }
    }

    /**
     * @OA\Post(
     *     path="/smsai/template/update/{id}",
     *     summary="Update a specific SMS AI Template by ID",
     *     tags={"SmsAiTemplate"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the SMS AI Template to update",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="template_name", type="string", example="Updated Order Confirmation"),
     *             @OA\Property(property="introduction", type="string", example="Template for updated order confirmation"),
     *             @OA\Property(property="description", type="string", example="Your updated order #{{order_id}} has been confirmed!")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="SMS AI Template updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="SMS AI Template updated")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Template not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="No SMS AI Template with id 1")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to update SMS AI Template"
     *     )
     * )
     */
    public function update(Request $request, int $id)
    {

        try {
            $data = $request->all();

            SmsAiTemplates::on("mysql_" . $request->auth->parent_id)->where('id', $id)->update($data);


            return $this->successResponse("SMS AI Template updated");
        } catch (ModelNotFoundException $exception) {
            throw new NotFoundHttpException("No SMS AI Template with id $id");
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update SMS AI Template", [], $exception);
        }
    }


    /**
     * @OA\Get(
     *     path="/smsai/template/delete/{id}",
     *     summary="delete a specific SMS AI Template by ID",
     *     tags={"SmsAiTemplate"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the SMS AI Template to delete",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="SMS AI Template soft deleted successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="SMS AI Template info deleted"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="boolean", example=true))
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Template not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="No SMS AI Template with id 1")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to soft delete SMS AI Template"
     *     )
     * )
     */
    public function delete(Request $request, int $id)
    {
        try {



            $list = SmsAiTemplates::on("mysql_" . $request->auth->parent_id)->findOrFail($id);
            $list->is_deleted = '1';
            $deleted = $list->update();
            return $this->successResponse("SMS AI Template info deleted", [$deleted]);
        } catch (ModelNotFoundException $exception) {
            throw new NotFoundHttpException("No SMS AI Template with id $id");
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch SMS AI Template info", [], $exception);
        }
    }


    /**
     * @OA\Post(
     *     path="/smsai/template/update-status",
     *     summary="Update the status of a specific SMS AI Template",
     *     tags={"SmsAiTemplate"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"template_id", "status"},
     *             @OA\Property(property="template_id", type="integer", example=1),
     *             @OA\Property(property="status", type="string", example="active")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="SMS AI Template status updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="SMS AI Template status updated successfully")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid input, missing fields"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to update SMS AI Template status"
     *     )
     * )
     */
    function updateStatus(Request $request)
    {
        $template_id = $request->input('template_id');
        $status = $request->input('status');

        $saveRecord = SmsAiTemplates::on('mysql_' . $request->auth->parent_id)->where('id', $template_id)->update(array('status' => $status));
        if ($saveRecord > 0) {
            return array(
                'success' => 'true',
                'message' => 'SMS AI Template status updated successfully'
            );
        } else {
            return array(
                'success' => 'true',
                'message' => 'SMS Ai Template update failed'
            );
        }
    }
}
