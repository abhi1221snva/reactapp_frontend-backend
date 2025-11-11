<?php

namespace App\Http\Controllers;

use App\Model\SmsTemplete;
use Illuminate\Http\Request;

class SmsTempleteController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    private $request;
    public function __construct(Request $request, SmsTemplete $smstemplete)
    {
        $this->request = $request;
        $this->model = $smstemplete;
    }


    /**
     * @OA\Get(
     *     path="/sms-templete",
     *     summary="List all SMS Templates",
     *     description="Fetches all SMS templates for the authenticated client's parent account.",
     *     tags={"SmsTemplete"},
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
     *         description="List of SMS templates",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="SMS Template List"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="template_name", type="string", example="Welcome Message"),
     *                     @OA\Property(property="message", type="string", example="Hello {{name}}, welcome to our service!"),
     *                     @OA\Property(property="status", type="string", example="active"),
     *                     @OA\Property(property="created_at", type="string", format="date-time", example="2025-04-01T10:00:00Z"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time", example="2025-04-01T10:00:00Z")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="An unexpected error occurred.")
     *         )
     *     )
     * )
     */
    public function index(Request $request)
    {
        $templates = SmsTemplete::on("mysql_" . $request->auth->parent_id)->get()->all();

        if ($request->has('start') && $request->has('limit')) {
            $total_row = count($templates);

            $start = (int) $request->input('start');  // Start index (0-based)
            $limit = (int) $request->input('limit');  // Number of records to fetch

            $templates = array_slice($templates, $start, $limit, false);

            return $this->successResponse("SMS Template List", [
                'start' => $start,
                'limit' => $limit,
                'total' => $total_row,
                'data' => $templates
            ]);
        }
        return $this->successResponse("SMS Template List", $templates);
    }

    public function index_old_code(Request $request)
    {
        $templates = SmsTemplete::on("mysql_" . $request->auth->parent_id)->get()->all();
        return $this->successResponse("SMS Template List", $templates);
    }
    /*
     *Fetch extension details
     *@return json
     */
    /**
     * @OA\post(
     *     path="/sms-templete",
     *     summary="Get SMS Template Detail",
     *     description="Fetches the details of a specific SMS template by its ID.",
     *     tags={"SmsTemplete"},
     *     security={{"Bearer":{}}},
     *      @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"mailbox_id"},
     *             @OA\Property(property="templete_id", type="integer", example=123)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successfully retrieved SMS template details",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Dnc detail."),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="templete_id", type="integer", example=1),
     *                 @OA\Property(property="template_name", type="string", example="Welcome Message"),
     *                 @OA\Property(property="message", type="string", example="Hello {{name}}, welcome to our service!"),
     *                 @OA\Property(property="status", type="string", example="active"),
     *                 @OA\Property(property="created_at", type="string", format="date-time", example="2025-04-01T10:00:00Z"),
     *                 @OA\Property(property="updated_at", type="string", format="date-time", example="2025-04-01T10:00:00Z")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid template ID",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Dnc not created.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="An unexpected error occurred.")
     *         )
     *     )
     * )
     */
    public function getSmsTemplete()
    {
        $response = $this->model->smsTempleteDetail($this->request);
        return response()->json($response);
    }
    /*
     *Add extension
     *@return json
     */
    /**
     * @OA\Post(
     *     path="/add-sms-templete",
     *     summary="Add a new SMS Template",
     *     description="Creates a new SMS template by providing the template name and description.",
     *     operationId="addSmsTemplate",
     *     tags={"SmsTemplete"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *        @OA\JsonContent(
     *                 type="object",
     *                 required={"templete_name", "templete_desc"},
     *                 @OA\Property(
     *                     property="templete_name",
     *                     type="string",
     *                     description="The name of the SMS template."
     *                 ),
     *                 @OA\Property(
     *                     property="templete_desc",
     *                     type="string",
     *                     description="A description of the SMS template."
     *                 )
     *             )
     *         ),
     *     @OA\Response(
     *         response=200,
     *         description="SMS Template successfully added.",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="success",
     *                 type="string",
     *                 example="true"
     *             ),
     *             @OA\Property(
     *                 property="message",
     *                 type="string",
     *                 example="Sms Templete added successfully."
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad Request - Missing required parameters.",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="success",
     *                 type="string",
     *                 example="false"
     *             ),
     *             @OA\Property(
     *                 property="message",
     *                 type="string",
     *                 example="Sms templete not created. Required Details are missing"
     *             ),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 additionalProperties=true
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error - Unexpected error during processing.",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="success",
     *                 type="string",
     *                 example="false"
     *             ),
     *             @OA\Property(
     *                 property="message",
     *                 type="string",
     *                 example="An error occurred while adding the template."
     *             ),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 additionalProperties=true
     *             )
     *         )
     *     )
     * )
     */

    public function addSmsTemplete()
    {

        $response = $this->model->addSmsTemplete($this->request);
        return response()->json($response);
    }
    /*
     *Edit Extension
     *@return json
     */

    /**
     * @OA\Post(
     *     path="/edit-sms-templete",
     *     summary="Edit SMS Template",
     *     description="Updates an SMS template's name and description based on provided template ID",
     *     tags={"SmsTemplete"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Fields to update for SMS Template",
     *         @OA\JsonContent(
     *             required={"templete_id"},
     *             @OA\Property(property="templete_id", type="integer", example=1),
     *             @OA\Property(property="templete_name", type="string", example="New Template Name"),
     *             @OA\Property(property="templete_desc", type="string", example="Updated Template Description")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="SMS Template updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Extension updated successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Failed to update SMS Template",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Nothing to update.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="SMS Template not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Unable to update Extension. Required Details are missing.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="An error occurred while updating SMS Template.")
     *         )
     *     )
     * )
     */

    public function editSmsTemplete()
    {

        $response = $this->model->editSmsTemplete($this->request);
        return response()->json($response);
    }


    /**
     * @OA\Post(
     *     path="/delete-sms-templete",
     *     summary="Delete SMS Template",
     *     description="Soft deletes an SMS template by setting the `is_deleted` field to true",
     *     tags={"SmsTemplete"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="SMS Template ID and status for deletion",
     *         @OA\JsonContent(
     *             required={"templete_id", "is_deleted"},
     *             @OA\Property(property="templete_id", type="integer", example=1),
     *             @OA\Property(property="is_deleted", type="boolean", example=true)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="SMS Template deleted successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Sms Template deleted successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Failed to delete SMS Template",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="SMS Template are not deleted successfully.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal Server Error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="An error occurred while deleting SMS Template.")
     *         )
     *     )
     * )
     */

    public function deleteSmsTemplete()
    {
        $response = $this->model->deleteSmsTemplete($this->request);
        return response()->json($response);
    }

    /**
     * @OA\Post(
     *     path="/get-sms-email-list",
     *     summary="Get Email and SMS Templates",
     *     description="Fetches SMS, Email, and CRM SMS templates based on the given lead ID and authenticated user.",
     *     tags={"SmsTemplete"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"lead_id"},
     *             @OA\Property(property="lead_id", type="integer", example=123)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Templates Retrieved",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="lead_id", type="integer", example=123),
     *             @OA\Property(
     *                 property="sms",
     *                 type="array",
     *                 @OA\Items(type="object", 
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="templete_name", type="string", example="Appointment Reminder"),
     *                     @OA\Property(property="message", type="string", example="Your appointment is scheduled for tomorrow.")
     *                 )
     *             ),
     *             @OA\Property(
     *                 property="email",
     *                 type="array",
     *                 @OA\Items(type="object", 
     *                     @OA\Property(property="id", type="integer", example=2),
     *                     @OA\Property(property="templete_name", type="string", example="Welcome Email"),
     *                     @OA\Property(property="message", type="string", example="Thank you for signing up.")
     *                 )
     *             ),
     *             @OA\Property(
     *                 property="crm_sms",
     *                 type="array",
     *                 @OA\Items(type="object", 
     *                     @OA\Property(property="id", type="integer", example=3),
     *                     @OA\Property(property="template_name", type="string", example="Follow-up Reminder"),
     *                     @OA\Property(property="message", type="string", example="Just checking in to follow up.")
     *                 )
     *             )
     *         )
     *     )
     * )
     */

    public function getEmailSmsList()
    {
        $response = $this->model->getEmailSmsList($this->request);
        return response()->json($response);
    }




    /**
     * @QA\Post(
     *     path="/sms-preview",
     *     summary="Get SMS Template Preview",
     *     tags={"SmsTempalete"},
     *   security={{"Bearer":{}}},
     *     @QA\Parameter(
     *         name="body",
     *         in="body",
     *         required=true,
     *         @QA\Schema(
     *             type="object",
     *             required={"auth", "lead_id", "sms_tpl_id"},
     *             @QA\Property(
     *                 property="auth",
     *                 type="object",
     *                 @QA\Property(property="id", type="integer", example=12),
     *                 @QA\Property(property="parent_id", type="integer", example=5)
     *             ),
     *             @QA\Property(
     *                 property="lead_id",
     *                 type="integer",
     *                 description="Lead ID to pull data from",
     *                 example=42
     *             ),
     *             @QA\Property(
     *                 property="sms_tpl_id",
     *                 type="integer",
     *                 description="SMS Template ID",
     *                 example=9
     *             )
     *         )
     *     ),
     * 
     *     @QA\Response(
     *         response=200,
     *         description="Generated SMS content preview",
     *         @QA\Schema(
     *             type="string",
     *             example="Hello John, your appointment is on 2025-05-01 with Dr. Smith."
     *         )
     *     ),
     * 
     *     @QA\Response(
     *         response=400,
     *         description="Invalid input or missing parameters"
     *     )
     * )
     */

    public function getSmsPreview()
    {
        $response = $this->model->getSmsPreview($this->request);
        return response()->json($response);
    }

    /**
     * @OA\Post(
     *     path="/sms-preview-crm",
     *     summary="Get SMS Template Preview",
     *     description="Fetches an SMS template preview by replacing placeholders with lead data and user details.",
     *     tags={"SmsTemplete"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"lead_id", "sms_tpl_id"},
     *             @OA\Property(property="lead_id", type="integer", example=123),
     *             @OA\Property(property="sms_tpl_id", type="integer", example=456)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="SMS Template Preview Retrieved",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="SMS Template Preview Details"),
     *             @OA\Property(property="data", type="string", example="Your custom SMS preview content")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Lead or Template Not Found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Lead or template not found"),
     *             @OA\Property(property="data", type="object", example={})
     *         )
     *     )
     * )
     */

    public function getSmsPreviewCRM()
    {
        $response = $this->model->getSmsPreviewCRM($this->request);
        return response()->json($response);
    }


    /**
     * @OA\delete(
     *     path="/sms-template/{id}",
     *     summary="delete SMS Template Preview",
     *     tags={"SmsTemplete"},
     *     security={{"Bearer":{}}},
     *       @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the SMS template to delete",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="SMS Template deleted",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="SMS Template Preview Details"),
     *             @OA\Property(property="data", type="string", example="Your custom SMS preview content")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Template Not Found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="template not found"),
     *             @OA\Property(property="data", type="object", example={})
     *         )
     *     )
     * )
     */
    public function delete(Request $request, int $id)
    {
        try {
            $template = SmsTemplete::on("mysql_" . $request->auth->parent_id)->findOrFail($id);
            $deleted = $template->delete();
            if ($deleted) {
                return $this->successResponse("Template List", $template->toArray());
            } else {
                return $this->failResponse("Failed to delete the template ", [
                    "Unkown"
                ]);
            }
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Template Not Found", [
                "Invalid template id $id"
            ], $exception, 404);
        } catch (\Exception $exception) {
            return $this->failResponse("Failed to fetch the template ", [
                $exception->getMessage()
            ], $exception, 500);
        }
    }
}
