<?php

namespace App\Http\Controllers;

use App\Model\Client\CrmSmsTemplate;

use Illuminate\Http\Request;
use App\Model\Role;
use App\Model\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Http\Helper\Log;
use App\Model\Client\CrmLabel;
use App\Model\Client\Lead;




class CrmSmsTemplateController extends Controller
{
    /**
     * @OA\Get(
     *     path="/crm-sms-template",
     *     summary="List all SMS templates",
     *     description="Fetches all SMS templates for the authenticated client's database connection.",
     *     tags={"CrmSmsTemplate"},
     *     security={{"Bearer":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="List of SMS templates fetched successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Sms Templates"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="object"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to list SMS Templates",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Failed to list SMS Templates"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string")),
     *             @OA\Property(property="trace", type="string"),
     *             @OA\Property(property="code", type="integer")
     *         )
     *     )
     * )
     */
    public function list(Request $request)
    {
        try {
            $clientId = $request->auth->parent_id;
            $SmsTemplates = [];
            $SmsTemplates = CrmSmsTemplate::on("mysql_$clientId")->orderBy('id', 'DESC')->get()->all();
            return $this->successResponse("Sms Templates", $SmsTemplates);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to list SMS Templates", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }


    /**
     * @OA\Put(
     *     path="/crm-add-sms-template",
     *     summary="Create a new SMS Template",
     *     description="Adds a new SMS template to the client's database.",
     *     tags={"CrmSmsTemplate"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"template_name", "template_html"},
     *             @OA\Property(property="template_name", type="string", example="Appointment Reminder"),
     *             @OA\Property(property="template_html", type="string", example="Dear customer, your appointment is scheduled for tomorrow at 10 AM."),
     *            @OA\Property(property="lead_status", type="string", example="New lead."),        
     * )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="SMS template created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Added Successfully"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation failed",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="The given data was invalid."),
     *             @OA\Property(
     *                 property="errors",
     *                 type="object",
     *                 example={"template_name": {"The template name field is required."}}
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Failed to create SMS Template"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string")),
     *             @OA\Property(property="trace", type="string"),
     *             @OA\Property(property="code", type="integer")
     *         )
     *     )
     * )
     */


    public function create(Request $request)
    {
        $clientId = $request->auth->parent_id;
        $this->validate($request, [
            'template_name' => 'required|string|max:255',
            'template_html' => 'required|string',
        ]);

        try {
            $SmsTemplates = new CrmSmsTemplate($request->all());
            $SmsTemplates->setConnection("mysql_$clientId");
            $SmsTemplates->saveOrFail();
            return $this->successResponse("Added Successfully", $SmsTemplates->toArray());
        } catch (\Exception $exception) {
            return $this->failResponse("Failed to create Email Template", [
                $exception->getMessage()
            ], $exception, 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/crm-sms-template/{id}",
     *     summary="Get  SMS templates",
     *     description="Get  SMS templates.",
     *     tags={"CrmSmsTemplate"},
     *     security={{"Bearer":{}}},
     *       @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the SMS template",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="SMS templates fetched successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Sms Templates"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="object"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to fetch list SMS Templates",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Failed to get SMS Templates"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string")),
     *             @OA\Property(property="trace", type="string"),
     *             @OA\Property(property="code", type="integer")
     *         )
     *     )
     * )
     */
    public function show(Request $request, int $id)
    {
        $sms_template = [];
        $clientId = $request->auth->parent_id;
        try {
            $sms_template = CrmSmsTemplate::on("mysql_$clientId")->findOrFail($id);
            $data = $sms_template->toArray();
            return $this->successResponse("Sms Template info", $data);
        } catch (ModelNotFoundException $exception) {
            throw new NotFoundHttpException("No Sms Template with id $id");
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch Sms Template info", [], $exception);
        }
    }


    /**
     * @OA\Get(
     *     path="/crm-delete-sms-template/{id}",
     *     summary="Delete SMS Template",
     *     description="Deletes a specific SMS template by ID from the client's database.",
     *     tags={"CrmSmsTemplate"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the SMS template to delete",
     *         @OA\Schema(type="integer", example=5)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="SMS Template Successfully deleted",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Sms Template Successfully deleted"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="boolean"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="SMS Template Not Found",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="No Sms Template with id 5"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Failed to fetch Sms Template info"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string")),
     *             @OA\Property(property="trace", type="string"),
     *             @OA\Property(property="code", type="integer")
     *         )
     *     )
     * )
     */
    public function delete(Request $request, $id)
    {
        $clientId = $request->auth->parent_id;
        try {
            $SmsTemplates = CrmSmsTemplate::on("mysql_$clientId")->find($id)->delete();
            return $this->successResponse("Sms Template Successfully deleted", [$SmsTemplates]);
        } catch (ModelNotFoundException $exception) {
            throw new NotFoundHttpException("No Sms Template with id $id");
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch Sms Template info", [], $exception);
        }
    }

    /**
     * @OA\Post(
     *     path="/crm-sms-template/{id}",
     *     summary="Update an existing SMS Template",
     *     description="Updates the SMS template with the given ID in the client's database.",
     *     tags={"CrmSmsTemplate"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the SMS template to update",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"template_name", "template_html"},
     *             @OA\Property(property="template_name", type="string", example="Updated Appointment Reminder"),
     *             @OA\Property(property="template_html", type="string", example="Hi {{name}}, this is your updated reminder."),
     *             @OA\Property(property="lead_status", type="string", example="Follow-up")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="SMS template updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Sms Template Updated"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Template not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Sms Template Not Found"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Failed to Sms Template Type"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string")),
     *             @OA\Property(property="trace", type="string"),
     *             @OA\Property(property="code", type="integer")
     *         )
     *     )
     * )
     */


    public function update(Request $request, $id)
    {
        $clientId = $request->auth->parent_id;
        $this->validate($request, [
            'template_name' => 'required|string|max:255',
            'template_html' => 'required|string',
        ]);
        try {
            $SmsTemplates = CrmSmsTemplate::on("mysql_$clientId")->findOrFail($id);

            if ($request->has("template_name"))
                $SmsTemplates->template_name = $request->input("template_name");
            if ($request->has("template_html"))
                $SmsTemplates->template_html = $request->input("template_html");


            if ($request->has("lead_status"))
                $SmsTemplates->lead_status = $request->input("lead_status");

            $SmsTemplates->saveOrFail();
            return $this->successResponse("Sms Template Updated", $SmsTemplates->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Sms Template Not Found", [
                "Invalid Template Type id $id"
            ], $exception, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to Sms Template Type", [
                $exception->getMessage()
            ], $exception, 404);
        }
    }

    /**
     * @OA\Post(
     *     path="/crm-change-sms-template-status",
     *     summary="Change SMS Template Status",
     *     description="Updates the status  of a specific SMS template in the client's database.",
     *     tags={"CrmSmsTemplate"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"sms_template_id", "status"},
     *             @OA\Property(property="sms_template_id", type="integer", example=5),
     *             @OA\Property(property="status", type="string", example="1")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="SMS Template Status Updated",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Sms Template Status Updated"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="SMS Template Not Found",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Sms Template Not Found"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Failed to update Sms Template"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string")),
     *             @OA\Property(property="trace", type="string"),
     *             @OA\Property(property="code", type="integer")
     *         )
     *     )
     * )
     */

    public function changeSmsTemplateStatus(Request $request)
    {
        $clientId = $request->auth->parent_id;
        try {
            $SmsTemplates = CrmSmsTemplate::on("mysql_$clientId")->findOrFail($request->sms_template_id);
            $SmsTemplates->status = $request->status;
            $SmsTemplates->saveOrFail();
            return $this->successResponse("Sms Template Status Updated", $SmsTemplates->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse(" Sms Template Not Found", [
                "Invalid Sms Template id $id"
            ], $exception, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update  Sms Template", [
                $exception->getMessage()
            ], $exception, 404);
        }
    }
}
