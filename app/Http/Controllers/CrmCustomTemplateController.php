<?php

namespace App\Http\Controllers;

use App\Model\Client\CustomTemplates;
use App\Model\Client\SystemSetting;


use Illuminate\Http\Request;
use App\Model\Role;
use App\Model\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Http\Helper\Log;
use App\Model\Client\CrmLabel;
use App\Model\Client\Lead;
use DateTime;



class CrmCustomTemplateController extends Controller
{


    /**
     * @OA\Get(
     *     path="/crm-custom-templates",
     *     summary="Get list of custom  templates",
     *     description="Retrieves a list of custom  templates for the authenticated client, ordered by descending ID",
     *     tags={"CrmCustomTemplate"},
     *     security={{"Bearer": {}}},
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
     *         description="List of custom templates retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Custom Templates"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="title", type="string", example="Welcome Template"),
     *                     @OA\Property(property="content", type="string", example="Welcome to our platform..."),
     *                     @OA\Property(property="created_at", type="string", format="date-time", example="2024-04-01T10:00:00Z"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time", example="2024-04-10T15:30:00Z")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to retrieve templates",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to list Email Templates")
     *         )
     *     )
     * )
     */

    public function list(Request $request)
    {
        try {
            $clientId = $request->auth->parent_id;
            $CustomTemplates = [];
            $CustomTemplates = CustomTemplates::on("mysql_$clientId")->orderBy('id', 'DESC')->get()->all();
            if ($request->has('start') && $request->has('limit')) {
                $total_row = count($CustomTemplates);
                $start = (int)$request->input('start'); // Start index (0-based)
                $limit = (int)$request->input('limit'); // Limit number of records to fetch
                $CustomTemplates = array_slice($CustomTemplates, $start, $limit, false);
                return $this->successResponse("Custom Templates", [
                    'start' => $start,
                    'limit' => $limit,
                    'total' => $total_row,
                    'data' => $CustomTemplates
                ]);
                //        return $this->successResponse("Custom Templates", $CustomTemplates);
            }

            return $this->successResponse("Custom Templates", $CustomTemplates);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to list Email Templates", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }


    /**
     * @OA\Put(
     *     path="/crm-add-custom-template",
     *     summary="Create a new custom email template",
     *     description="Adds a new custom email template with HTML content and optional custom type.",
     *     tags={"CrmCustomTemplate"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Custom email template data",
     *         @OA\JsonContent(
     *             required={"template_name", "template_html"},
     *             @OA\Property(property="template_name", type="string", example="Welcome Email"),
     *             @OA\Property(property="template_html", type="string", example="<p>Welcome to our platform!</p>"),
     *             @OA\Property(property="custom_type", type="string", example="onboarding")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Custom Template created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Added Successfully"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to create Custom Template",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to create Email Template")
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
            //'subject' => 'required|string|max:255',
        ]);

        try {
            $EmailTemplates = new CustomTemplates($request->all());
            $EmailTemplates->setConnection("mysql_$clientId");
            $EmailTemplates->saveOrFail();
            return $this->successResponse("Added Successfully", $EmailTemplates->toArray());
        } catch (\Exception $exception) {
            return $this->failResponse("Failed to create Email Template", [
                $exception->getMessage()
            ], $exception, 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/crm-custom-template/{id}",
     *     summary="Get a specific Custom Template",
     *     description="Retrieves the details of a single custom email template by ID for the authenticated client.",
     *     tags={"CrmCustomTemplate"},
     *     security={{"Bearer": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the Custom Template",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Custom Template info retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Email Template info"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="title", type="string", example="Welcome Template"),
     *                 @OA\Property(property="content", type="string", example="Hello, welcome to our service!"),
     *                 @OA\Property(property="created_at", type="string", format="date-time", example="2024-04-01T10:00:00Z"),
     *                 @OA\Property(property="updated_at", type="string", format="date-time", example="2024-04-10T15:30:00Z")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Custom Template not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="No Email Template with id 1")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Error while retrieving Custom template",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to fetch Custom Template info")
     *         )
     *     )
     * )
     */

    public function show(Request $request, int $id)
    {
        $email_template = [];
        $clientId = $request->auth->parent_id;
        try {
            $email_template = CustomTemplates::on("mysql_$clientId")->findOrFail($id);
            $data = $email_template->toArray();
            return $this->successResponse("Email Template info", $data);
        } catch (ModelNotFoundException $exception) {
            throw new NotFoundHttpException("No Email Template with id $id");
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch Email Template info", [], $exception);
        }
    }

    /**
     * @OA\get(
     *     path="/crm-delete-custom-template/{id}",
     *     summary="Delete a custom  template",
     *     description="Deletes a specific custom template by ID for the authenticated client.",
     *     tags={"CrmCustomTemplate"},
     *     security={{"Bearer": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the custom template to be deleted",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Custom Template deleted successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Custom Template deleted Successfully"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="boolean", example=true))
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Custom Template not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="No Custom Template with id 1")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Error while deleting the custom template",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to fetch Custom Template info")
     *         )
     *     )
     * )
     */

    public function delete(Request $request, $id)
    {
        $clientId = $request->auth->parent_id;
        try {
            $CustomTemplates = CustomTemplates::on("mysql_$clientId")->find($id)->delete();
            return $this->successResponse("Custom Template deleted Successfully", [$CustomTemplates]);
        } catch (ModelNotFoundException $exception) {
            throw new NotFoundHttpException("No Custom Template with id $id");
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch Custom Template info", [], $exception);
        }
    }

    /**
     * @OA\Post(
     *     path="/crm-custom-template/{id}",
     *     summary="Update a custom email template",
     *     description="Updates fields of a custom email template like name, HTML content, and custom type.",
     *     tags={"CrmCustomTemplate"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID of the custom email template",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         description="Data for updating custom email template",
     *         @OA\JsonContent(
     *             required={"template_name", "template_html"},
     *             @OA\Property(property="template_name", type="string", example="New Welcome Email"),
     *             @OA\Property(property="template_html", type="string", example="<p>Welcome to our platform!</p>"),
     *             @OA\Property(property="custom_type", type="string", example="onboarding")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Custom Template updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Custom Template Update"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Custom Template not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Custom Template Not Found")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to update Custom Template",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to Custom Template Type")
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
            //'subject' => 'required|string|max:255',
        ]);
        try {
            $EmailTemplates = CustomTemplates::on("mysql_$clientId")->findOrFail($id);

            if ($request->has("template_name"))
                $EmailTemplates->template_name = $request->input("template_name");
            if ($request->has("template_html"))
                $EmailTemplates->template_html = $request->input("template_html");
            /* if ($request->has("subject"))
                $EmailTemplates->subject = $request->input("subject");*/

            if ($request->has("custom_type"))
                $EmailTemplates->custom_type = $request->input("custom_type");
            /*if ($request->has("send_bcc"))
                $EmailTemplates->send_bcc = $request->input("send_bcc");*/
            $EmailTemplates->saveOrFail();
            return $this->successResponse("Custom Template Update", $EmailTemplates->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Custom Template Not Found", [
                "Invalid Template Type id $id"
            ], $exception, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to Custom Template Type", [
                $exception->getMessage()
            ], $exception, 404);
        }
    }


    /**
     * @OA\Post(
     *     path="/crm-change-custom-template-status",
     *     summary="Update the status of a custom  template",
     *     description="Change the status (e.g., active/inactive) of a specific custom email template by ID.",
     *     tags={"CrmCustomTemplate"},
     *     security={{"Bearer": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Status update details",
     *         @OA\JsonContent(
     *             required={"email_template_id", "status"},
     *             @OA\Property(property="email_template_id", type="integer", example=1),
     *             @OA\Property(property="status", type="integer", example=1)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Custom Template's status updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Custom Template's status Updated successfully"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Custom Template not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Custom Template Not Found")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to update Custom Template",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to update Custom Template")
     *         )
     *     )
     * )
     */


    public function changeCustomTemplateStatus(Request $request)
    {
        $clientId = $request->auth->parent_id;
        try {
            $CustomTemplates = CustomTemplates::on("mysql_$clientId")->findOrFail($request->email_template_id);
            $CustomTemplates->status = $request->status;
            $CustomTemplates->saveOrFail();
            return $this->successResponse(" Custom Template's status Updated succesfully", $CustomTemplates->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse(" Custom Template Not Found", [
                "Invalid Custom Template id $id"
            ], $exception, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update  Custom Template", [
                $exception->getMessage()
            ], $exception, 404);
        }
    }


    public function viewPDFPopupo(Request $request, int $id, $list_id = '', $lead_id = '', $file_type = '')
    {
        try {

            if (! empty($lead_id) && ! empty($list_id)) {
                $lead_record = Lead::on("mysql_" . $request->auth->parent_id)->where('id', "=", $lead_id)->first();
                //return $this->successResponse("Lead Info",  [$lead_record]);

                $lead_assigned_to = $lead_record->assigned_to;
                $lead_signature = $lead_record->signature_image;
                $lead_created_at = $lead_record->created_at;


                $label_header = CrmLabel::on("mysql_" . $request->auth->parent_id)->get();
                //return $this->successResponse("Label Info",  [$list_header]);

                foreach ($label_header as $key => $val) {
                    $new_array[$val['label_title_url']] = $lead_record[$val['column_name']];
                }

                //return $this->successResponse("Template Info",  $new_array);

                $tpl_record = CustomTemplates::on("mysql_" . $request->auth->parent_id)->where('custom_type', 'signature_application')->get()->first();
                //return $this->successResponse("Template Info",  [$tpl_record]);

                $email_content = $tpl_record->template_html;
                foreach ($new_array as $key1 => $val) {
                    $replace = "[[" . $key1 . "]]";
                    $email_content = str_replace($replace, $val, $email_content);
                }

                //return $this->successResponse("Template Info",  [$tpl_record]);
                //$user_detail = User::findOrFail($request->auth->id)->first()->toArray();

                $user_detail = User::findOrFail($lead_assigned_to)->toArray();
                //return $this->successResponse("Template Info",  [$user_detail]);

                // dd($user_details);
                foreach ($user_detail as $k1 => $vl1) {
                    $replace_key = "[" . $k1 . "]";

                    $email_content = str_replace($replace_key, $vl1, $email_content);
                }



                $subject_content = $tpl_record->subject;
                foreach ($new_array as $key1 => $val) {
                    $replace_subject = "[[" . $key1 . "]]";
                    $subject_content = str_replace($replace_subject, $val, $subject_content);
                }
                //$user_detail = User::findOrFail($request->auth->id)->first()->toArray();
                $user_detail = User::findOrFail($request->auth->id)->toArray();

                // dd($user_details);
                foreach ($user_detail as $k1 => $vl1) {
                    $replace_subject_key = "[[" . $k1 . "]]";
                    $subject_content = str_replace($replace_subject_key, $vl1, $subject_content);
                }

                //$user_detail = User::findOrFail($request->auth->id)->first()->toArray();
                $system_setting = SystemSetting::on("mysql_" . $request->auth->parent_id)->get()->first()->toArray();

                //  return $this->successResponse("Template Info",  [$system_setting]);


                // dd($user_details);
                foreach ($system_setting as $sys => $vl1) {
                    $replace_key = "_" . $sys . "_";

                    //return $this->successResponse("Template Info",  [$sys]);

                    if ($sys == 'logo') {
                        if ($file_type == 'pdf') {
                            $vl1 = '<img alt="" src="' . env('SIGNED_APPLICATION_PDF_LOGO') . $vl1 . '" style="width:30%">';
                        } else {
                            $vl1 = '<img alt="" src="/logo/' . $vl1 . '" style="width:30%">';
                        }
                    }


                    $email_content = str_replace($replace_key, $vl1, $email_content);
                }

                // return $this->successResponse("Template Info",  [$email_content]);


                //$subject_content = str_replace('[[', '', $subject_content);
                //$subject_content = str_replace(']]', '', $subject_content);

                preg_match_all("/\\[\\[(.*?)\\]\\]/", $email_content, $matches);
                //  return $this->successResponse("Template Info",  [$matches[1]]);

                if (!empty($matches[1])) {
                    $count = count($matches[1]);
                    if ($count > 0) {
                        for ($i = 0; $i < $count; $i++) {
                            $pending_key =  $matches[1][$i];
                            $label = CrmLabel::on("mysql_" . $request->auth->parent_id)->where("label_title_url", "=", $pending_key)->first();
                            //   return $this->successResponse("Template Info",  [$label]);

                            if (!empty($label)) {
                                $lebel_id = $label->id;

                                $column = $label->column_name;
                                $value = $lead_record[$column];
                                if (!empty($value)) {
                                    $replace = $matches[0][$i];
                                    $email_content = str_replace($replace, $value, $email_content);
                                } else {
                                    $value = '';
                                    $replace = $matches[0][$i];
                                    $email_content = str_replace($replace, $value, $email_content);
                                }

                                //return $this->successResponse("Template Info",  [$email_content]);

                            } else
                                    if ($pending_key == 'signature_image') {

                                if ($file_type == 'pdf') {
                                    $value = '<img alt="" src="' . env('SIGNED_APPLICATION_SIGNATURE_IMG') . $lead_signature . '" style="height:55px">';
                                } else {
                                    $value = '<img alt="" src="/uploads/signature/' . $lead_signature . '" style="height:55px">';
                                }

                                $replace = $matches[0][$i];
                                $email_content = str_replace($replace, $value, $email_content);
                            } else
                                        if ($pending_key == 'lead_created_at') {

                                $replace = $matches[0][$i];
                                $values = explode(' ', $lead_created_at);
                                $value = $values[0];
                                $email_content = str_replace($replace, $value, $email_content);
                            } else {
                                $value = '';
                                $replace = '[[' . $pending_key . ']]';
                                $email_content = str_replace($replace, $value, $email_content);
                            }
                        }
                    }
                }

                // return $this->successResponse("Template Info",  [$email_content]);


                //subject matches

                preg_match_all("/\\[\\[(.*?)\\]\\]/", $subject_content, $matches_subject);


                if (!empty($matches_subject[1])) {
                    $count = count($matches_subject[1]);
                    if ($count > 0) {
                        for ($i = 0; $i < $count; $i++) {
                            $pending_key =  $matches_subject[1][$i];
                            $label = CrmLabel::on("mysql_" . $request->auth->parent_id)->where("title", "=", $pending_key)->first();
                            if (!empty($label)) {
                                $lebel_id = $label->id;
                                $label = CrmLabel::on("mysql_" . $request->auth->parent_id)->where("label_title_url", "=", $pending_key)->first();
                                if (!empty($label)) {

                                    $lebel_id = $label->id;
                                    $column = $label->column_name;
                                    $value = $lead_record[$column];
                                    $replace = $matches_subject[0][$i];
                                    $subject_content = str_replace($replace, $value, $subject_content);
                                } else {
                                    $value = '';
                                    $replace = $matches_subject[0][$i];
                                    $subject_content = str_replace($replace, $value, $subject_content);
                                }
                            } else {
                                $value = '';
                                $replace = '[[' . $pending_key . ']]';
                                $subject_content = str_replace($replace, $value, $subject_content);
                            }
                        }
                    }
                }

                $templates = array();
                $templates['template_html'] = $email_content;
                $templates['subject'] = $subject_content;
                //  }

                return $this->successResponse("Template Info",  $templates);
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

    public function labelValue(Request $request, int $id, $list_id = '', $lead_id = '')
    {
        try {


            $label_id = $id;
            // echo "<pre>";print_r($Label);die;
            $listHeader = CrmLabel::on("mysql_" . $request->auth->parent_id)->where("id", "=", $label_id)->first();
            //return (array)$listHeader;

            // echo "<pre>";print_r($listHeader);die;

            $column_name = $listHeader->column_name;
            $listData = Lead::on("mysql_" . $request->auth->parent_id)->where('id', "=", $lead_id)->first();

            return (array)$listData[$column_name];
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


    public function signedApplication(Request $request, $lead_id = '')
    {

        try {
            if (! empty($lead_id)) {
                $lead_record = Lead::on("mysql_" . $request->auth->parent_id)->where('id', "=", $lead_id)->first();
                //return $this->successResponse("Template Info",  [$lead_record]);

                $list_header = CrmLabel::on("mysql_" . $request->auth->parent_id)->get();
                //return $this->successResponse("Template Info",  [$list_header]);

                foreach ($list_header as $key => $val) {
                    $new_array[$val['label_title_url']] = $lead_record[$val['column_name']];
                }

                //return $this->successResponse("Template Info",  $new_array);

                $tpl_record = PdfTemplates::on("mysql_" . $request->auth->parent_id)->where('document_type', 'signature_application')->get()->first();

                //return $this->successResponse("Template Info",  [$tpl_record]);

                $email_content = $tpl_record->template_html;
                foreach ($new_array as $key1 => $val) {
                    $replace = "[[" . $key1 . "]]";
                    $email_content = str_replace($replace, $val, $email_content);
                }

                //return $this->successResponse("Template Info",  [$email_content]);
                //$user_detail = User::findOrFail($request->auth->id)->first()->toArray();

                $user_detail = User::findOrFail($lead_record->assigned_to)->toArray();

                //return $this->successResponse("Template Info",  [$user_detail]);

                // dd($user_details);
                foreach ($user_detail as $k1 => $vl1) {
                    $replace_key = "_" . $k1 . "_";

                    $email_content = str_replace($replace_key, $vl1, $email_content);
                }



                /* $subject_content = $tpl_record->subject;
                    foreach ($new_array as $key1 => $val) {
                        $replace_subject = "[[". $key1."]]";
                        $subject_content = str_replace($replace_subject, $val, $subject_content);
                    }*/
                //$user_detail = User::findOrFail($request->auth->id)->first()->toArray();
                /* $user_detail = User::findOrFail($request->auth->id)->toArray();

                    // dd($user_details);
                    foreach ($user_detail as $k1 => $vl1) {
                        $replace_subject_key = "[[". $k1."]]";
                        $subject_content = str_replace($replace_subject_key, $vl1, $subject_content);
                    }*/

                //$subject_content = str_replace('[[', '', $subject_content);
                //$subject_content = str_replace(']]', '', $subject_content);

                preg_match_all("/\\[\\[(.*?)\\]\\]/", $email_content, $matches);
                //return $this->successResponse("Template Info",  [$matches[1]]);

                if (!empty($matches[1])) {
                    $count = count($matches[1]);
                    if ($count > 0) {
                        for ($i = 0; $i < $count; $i++) {
                            $pending_key =  $matches[1][$i];
                            $label = CrmLabel::on("mysql_" . $request->auth->parent_id)->where("label_title_url", "=", $pending_key)->first();
                            //   return $this->successResponse("Template Info",  [$label]);

                            if (!empty($label)) {
                                $lebel_id = $label->id;

                                $column = $label->column_name;
                                $value = $lead_record[$column];
                                if (!empty($value)) {
                                    $replace = $matches[0][$i];
                                    $email_content = str_replace($replace, $value, $email_content);
                                } else {
                                    $value = '';
                                    $replace = $matches[0][$i];
                                    $email_content = str_replace($replace, $value, $email_content);
                                }

                                //return $this->successResponse("Template Info",  [$email_content]);

                            } else {
                                $value = '';
                                $replace = '[[' . $pending_key . ']]';
                                $email_content = str_replace($replace, $value, $email_content);
                            }
                        }
                    }
                }

                // return $this->successResponse("Template Info",  [$email_content]);


                //subject matches

                /* preg_match_all("/\\[\\[(.*?)\\]\\]/", $subject_content, $matches_subject); 
                    

                    if(!empty($matches_subject[1]))
                    {
                        $count = count($matches_subject[1]);
                        if($count > 0)
                        {
                            for($i=0;$i< $count ; $i++)
                            {
                                $pending_key =  $matches_subject[1][$i];
                                $label = Label::on("mysql_" . $request->auth->parent_id)->where("title", "=", $pending_key)->first();
                                if(!empty($label))
                                {
                                    $lebel_id = $label->id;
                                     $label = Label::on("mysql_" . $request->auth->parent_id)->where("label_title_url", "=", $pending_key)->first();
                                    if(!empty($label))
                                    {

                                         $lebel_id = $label->id;
                                        $column = $label->column_name;
                                        $value = $lead_record[$column];
                                        $replace = $matches_subject[0][$i];
                                        $subject_content = str_replace($replace, $value, $subject_content);
                                    }

                                    else
                                    {
                                        $value ='';
                                        $replace = $matches_subject[0][$i];
                                        $subject_content = str_replace($replace, $value, $subject_content);
                                    }
                                }

                                else
                                {
                                    $value ='';
                                    $replace = '[['.$pending_key.']]';
                                    $subject_content = str_replace($replace, $value, $subject_content);
                                }
                            }
                        }
                    }*/

                $templates = array();
                $templates['template_html'] = $email_content;
                //$templates['subject'] = $subject_content;
                //  }

                return $this->successResponse("Template Info",  $templates);
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



    public function viewPDFPopupMerchanto(Request $request, int $id, $list_id = '', $lead_id = '', $parent_id, $file_type = '')
    {
        try {

            if (! empty($lead_id) && ! empty($list_id)) {
                $lead_record = Lead::on("mysql_" . $parent_id)->where('id', "=", $lead_id)->first();
                //return $this->successResponse("Lead Info",  [$lead_record]);

                $lead_assigned_to = $lead_record->assigned_to;
                $lead_signature = $lead_record->signature_image;
                $lead_created_at = $lead_record->created_at;


                $label_header = CrmLabel::on("mysql_" . $parent_id)->get();
                //return $this->successResponse("Label Info",  [$list_header]);

                foreach ($label_header as $key => $val) {
                    $new_array[$val['label_title_url']] = $lead_record[$val['column_name']];
                }

                //return $this->successResponse("Template Info",  $new_array);

                $tpl_record = CustomTemplates::on("mysql_" . $parent_id)->where('custom_type', 'signature_application')->get()->first();
                //return $this->successResponse("Template Info",  [$tpl_record]);

                $email_content = $tpl_record->template_html;
                foreach ($new_array as $key1 => $val) {
                    $replace = "[[" . $key1 . "]]";
                    $email_content = str_replace($replace, $val, $email_content);
                }

                //return $this->successResponse("Template Info",  [$tpl_record]);
                //$user_detail = User::findOrFail($request->auth->id)->first()->toArray();

                $user_detail = User::findOrFail($lead_assigned_to)->toArray();
                //return $this->successResponse("Template Info",  [$user_detail]);

                // dd($user_details);
                foreach ($user_detail as $k1 => $vl1) {
                    $replace_key = "[" . $k1 . "]";

                    $email_content = str_replace($replace_key, $vl1, $email_content);
                }



                $subject_content = $tpl_record->subject;
                foreach ($new_array as $key1 => $val) {
                    $replace_subject = "[[" . $key1 . "]]";
                    $subject_content = str_replace($replace_subject, $val, $subject_content);
                }
                //$user_detail = User::findOrFail($request->auth->id)->first()->toArray();
                $user_detail = User::findOrFail($lead_assigned_to)->toArray();

                // dd($user_details);
                foreach ($user_detail as $k1 => $vl1) {
                    $replace_subject_key = "[[" . $k1 . "]]";
                    $subject_content = str_replace($replace_subject_key, $vl1, $subject_content);
                }

                //$user_detail = User::findOrFail($request->auth->id)->first()->toArray();
                $system_setting = SystemSetting::on("mysql_" . $parent_id)->get()->first()->toArray();

                //  return $this->successResponse("Template Info",  [$system_setting]);


                // dd($user_details);
                foreach ($system_setting as $sys => $vl1) {
                    $replace_key = "_" . $sys . "_";

                    //return $this->successResponse("Template Info",  [$sys]);

                    if ($sys == 'logo') {
                        if ($file_type == 'pdf') {
                            $vl1 = '<img alt="" src="' . env('SIGNED_APPLICATION_PDF_LOGO') . $vl1 . '" style="width:30%">';
                        } else {
                            $vl1 = '<img alt="" src="/logo/' . $vl1 . '" style="width:30%">';
                        }
                    }


                    $email_content = str_replace($replace_key, $vl1, $email_content);
                }

                // return $this->successResponse("Template Info",  [$email_content]);


                //$subject_content = str_replace('[[', '', $subject_content);
                //$subject_content = str_replace(']]', '', $subject_content);

                preg_match_all("/\\[\\[(.*?)\\]\\]/", $email_content, $matches);
                //  return $this->successResponse("Template Info",  [$matches[1]]);

                if (!empty($matches[1])) {
                    $count = count($matches[1]);
                    if ($count > 0) {
                        for ($i = 0; $i < $count; $i++) {
                            $pending_key =  $matches[1][$i];
                            $label = CrmLabel::on("mysql_" . $parent_id)->where("label_title_url", "=", $pending_key)->first();
                            //   return $this->successResponse("Template Info",  [$label]);

                            if (!empty($label)) {
                                $lebel_id = $label->id;

                                $column = $label->column_name;
                                $value = $lead_record[$column];
                                if (!empty($value)) {
                                    $replace = $matches[0][$i];
                                    $email_content = str_replace($replace, $value, $email_content);
                                } else {
                                    $value = '';
                                    $replace = $matches[0][$i];
                                    $email_content = str_replace($replace, $value, $email_content);
                                }

                                //return $this->successResponse("Template Info",  [$email_content]);

                            } else
                                    if ($pending_key == 'signature_image') {

                                if ($file_type == 'pdf') {
                                    $value = '<img alt="" src="' . env('SIGNED_APPLICATION_SIGNATURE_IMG') . $lead_signature . '" style="height:55px">';
                                } else {
                                    $value = '<img alt="" src="/uploads/signature/' . $lead_signature . '" style="height:55px">';
                                }

                                $replace = $matches[0][$i];
                                $email_content = str_replace($replace, $value, $email_content);
                            } else
                                        if ($pending_key == 'lead_created_at') {

                                $replace = $matches[0][$i];
                                $values = explode(' ', $lead_created_at);
                                $value = $values[0];
                                $email_content = str_replace($replace, $value, $email_content);
                            } else {
                                $value = '';
                                $replace = '[[' . $pending_key . ']]';
                                $email_content = str_replace($replace, $value, $email_content);
                            }
                        }
                    }
                }

                // return $this->successResponse("Template Info",  [$email_content]);


                //subject matches

                preg_match_all("/\\[\\[(.*?)\\]\\]/", $subject_content, $matches_subject);


                if (!empty($matches_subject[1])) {
                    $count = count($matches_subject[1]);
                    if ($count > 0) {
                        for ($i = 0; $i < $count; $i++) {
                            $pending_key =  $matches_subject[1][$i];
                            $label = CrmLabel::on("mysql_" . $parent_id)->where("title", "=", $pending_key)->first();
                            if (!empty($label)) {
                                $lebel_id = $label->id;
                                $label = CrmLabel::on("mysql_" . $parent_id)->where("label_title_url", "=", $pending_key)->first();
                                if (!empty($label)) {

                                    $lebel_id = $label->id;
                                    $column = $label->column_name;
                                    $value = $lead_record[$column];
                                    $replace = $matches_subject[0][$i];
                                    $subject_content = str_replace($replace, $value, $subject_content);
                                } else {
                                    $value = '';
                                    $replace = $matches_subject[0][$i];
                                    $subject_content = str_replace($replace, $value, $subject_content);
                                }
                            } else {
                                $value = '';
                                $replace = '[[' . $pending_key . ']]';
                                $subject_content = str_replace($replace, $value, $subject_content);
                            }
                        }
                    }
                }

                $templates = array();
                $templates['template_html'] = $email_content;
                $templates['subject'] = $subject_content;
                //  }

                return $this->successResponse("Template Info",  $templates);
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



    public function viewPDFPopupAffiliate(Request $request, int $parent_id, int $id, $list_id = '', $lead_id = '', $file_type = '')
    {
        try {

            if (! empty($lead_id) && ! empty($list_id)) {
                $lead_record = Lead::on("mysql_" . $parent_id)->where('id', "=", $lead_id)->first();
                //return $this->successResponse("Lead Info",  [$lead_record]);

                $lead_assigned_to = $lead_record->assigned_to;
                $lead_signature = $lead_record->signature_image;
                $lead_signature2 = $lead_record->owner_2_signature_image;
                $owner_2_sign_date = $this->formatDateIfNecessary($lead_record->owner_2_sign_date);

                $lead_created_at = $this->formatDateIfNecessary(substr($lead_record->created_at, 0, 10));


                $label_header = CrmLabel::on("mysql_" . $parent_id)->get();
                //return $this->successResponse("Label Info",  [$list_header]);

                foreach ($label_header as $key => $val) {
                    $new_array[$val['label_title_url']] = $this->formatDateIfNecessary($lead_record[$val['column_name']]);
                }

                //return $this->successResponse("Template Info",  $new_array);

                $tpl_record = CustomTemplates::on("mysql_" . $parent_id)->where('custom_type', 'signature_application')->get()->first();
                //return $this->successResponse("Template Info",  [$tpl_record]);

                $email_content = $tpl_record->template_html;
                foreach ($new_array as $key1 => $val) {
                    $replace = "[[" . $key1 . "]]";
                    $email_content = str_replace($replace, $val, $email_content);
                }

                //return $this->successResponse("Template Info",  [$tpl_record]);
                //$user_detail = User::findOrFail($request->auth->id)->first()->toArray();

                $user_detail = User::findOrFail($lead_assigned_to)->toArray();
                //return $this->successResponse("Template Info",  [$user_detail]);

                // dd($user_details);
                foreach ($user_detail as $k1 => $vl1) {
                    $replace_key = "[" . $k1 . "]";

                    $email_content = str_replace($replace_key, $vl1, $email_content);
                }



                $subject_content = $tpl_record->subject;
                foreach ($new_array as $key1 => $val) {
                    $replace_subject = "[[" . $key1 . "]]";
                    $subject_content = str_replace($replace_subject, $val, $subject_content);
                }
                //$user_detail = User::findOrFail($request->auth->id)->first()->toArray();
                //$idd='358';
                //$user_detail = User::findOrFail($idd)->toArray();
                $user = User::where('extension', $list_id)->get()->first();

                // return $this->successResponse("Template Info",  [$user_detail]);


                // dd($user_details);
                foreach ($user_detail as $k1 => $vl1) {
                    $replace_subject_key = "[[" . $k1 . "]]";
                    $subject_content = str_replace($replace_subject_key, $this->formatDateIfNecessary($vl1), $subject_content);
                }

                //$user_detail = User::findOrFail($request->auth->id)->first()->toArray();
                $system_setting = SystemSetting::on("mysql_" . $parent_id)->get()->first()->toArray();

                //  return $this->successResponse("Template Info",  [$system_setting]);


                // dd($user_details);
                foreach ($system_setting as $sys => $vl1) {
                    $replace_key = "_" . $sys . "_";

                    //return $this->successResponse("Template Info",  [$sys]);

                    if ($sys == 'logo') {
                        if ($file_type == 'pdf') {
                            $vl1 = '<img alt="" src="' . env('SIGNED_APPLICATION_PDF_LOGO') . $vl1 . '" style="width:30%">';
                        } else {
                            $vl1 = '<img alt="" src="/logo/' . $vl1 . '" style="width:30%">';
                        }
                    }


                    $email_content = str_replace($replace_key, $this->formatDateIfNecessary($vl1), $email_content);
                }

                // return $this->successResponse("Template Info",  [$email_content]);


                //$subject_content = str_replace('[[', '', $subject_content);
                //$subject_content = str_replace(']]', '', $subject_content);

                preg_match_all("/\\[\\[(.*?)\\]\\]/", $email_content, $matches);
                //  return $this->successResponse("Template Info",  [$matches[1]]);

                if (!empty($matches[1])) {
                    $count = count($matches[1]);
                    if ($count > 0) {
                        for ($i = 0; $i < $count; $i++) {
                            $pending_key =  $matches[1][$i];
                            $label = CrmLabel::on("mysql_" . $parent_id)->where("label_title_url", "=", $pending_key)->first();
                            //   return $this->successResponse("Template Info",  [$label]);

                            if (!empty($label)) {
                                $lebel_id = $label->id;

                                $column = $label->column_name;
                                $value = $lead_record[$column];
                                if (!empty($value)) {
                                    $replace = $matches[0][$i];
                                    $email_content = str_replace($replace, $value, $email_content);
                                } else {
                                    $value = '';
                                    $replace = $matches[0][$i];
                                    $email_content = str_replace($replace, $value, $email_content);
                                }

                                //return $this->successResponse("Template Info",  [$email_content]);

                            } else
                                    if ($pending_key == 'signature_image') {

                                if ($file_type == 'pdf') {
                                    $value = '<img alt="" src="' . env('SIGNED_APPLICATION_SIGNATURE_IMG') . $lead_signature . '" style="height:55px">';
                                } else {
                                    $value = '<img alt="" src="/uploads/signature/' . $lead_signature . '" style="height:55px">';
                                }

                                $replace = $matches[0][$i];
                                $email_content = str_replace($replace, $value, $email_content);
                            } elseif ($pending_key == 'owner_2_signature_image') {
                                if (!empty($lead_signature2)) {
                                    if ($file_type == 'pdf') {
                                        $value = '<img alt="" src="' . env('SIGNED_APPLICATION_SIGNATURE_IMG') . $lead_signature2 . '" style="height:55px">';
                                    } else {
                                        $value = '<img alt="" src="/uploads/signature/' . $lead_signature2 . '" style="height:55px">';
                                    }
                                } else {
                                    $value = '';
                                }
                                $replace = $matches[0][$i];
                                $email_content = str_replace($replace, $value, $email_content);
                            } elseif ($pending_key == 'owner_2_signature_date' && !empty($lead_signature2)) {
                                if (!empty($owner_2_sign_date)) {
                                    $value = $this->formatDateIfNecessary($owner_2_sign_date);
                                } else {
                                    $value = '';
                                }
                                $replace = $matches[0][$i];
                                $email_content = str_replace($replace, $value, $email_content);
                            } else
                                        if ($pending_key == 'lead_created_at') {

                                $replace = $matches[0][$i];
                                $values = explode(' ', $lead_created_at);
                                $value = $values[0];
                                $email_content = str_replace($replace, $value, $email_content);
                            } else {
                                $value = '';
                                $replace = '[[' . $pending_key . ']]';
                                $email_content = str_replace($replace, $value, $email_content);
                            }
                        }
                    }
                }

                // return $this->successResponse("Template Info",  [$email_content]);


                //subject matches

                preg_match_all("/\\[\\[(.*?)\\]\\]/", $subject_content, $matches_subject);


                if (!empty($matches_subject[1])) {
                    $count = count($matches_subject[1]);
                    if ($count > 0) {
                        for ($i = 0; $i < $count; $i++) {
                            $pending_key =  $matches_subject[1][$i];
                            $label = CrmLabel::on("mysql_" . $parent_id)->where("title", "=", $pending_key)->first();
                            if (!empty($label)) {
                                $lebel_id = $label->id;
                                $label = CrmLabel::on("mysql_" . $parent_id)->where("label_title_url", "=", $pending_key)->first();
                                if (!empty($label)) {

                                    $lebel_id = $label->id;
                                    $column = $label->column_name;
                                    $value = $this->formatDateIfNecessary($lead_record[$column]);
                                    $replace = $matches_subject[0][$i];
                                    $subject_content = str_replace($replace, $value, $subject_content);
                                } else {
                                    $value = '';
                                    $replace = $matches_subject[0][$i];
                                    $subject_content = str_replace($replace, $value, $subject_content);
                                }
                            } else {
                                $value = '';
                                $replace = '[[' . $pending_key . ']]';
                                $subject_content = str_replace($replace, $value, $subject_content);
                            }
                        }
                    }
                }

                $templates = array();
                $templates['template_html'] = $email_content;
                $templates['subject'] = $subject_content;
                //  }

                return $this->successResponse("Template Info",  $templates);
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


    public function viewPDFPopup(Request $request, int $id, $list_id = '', $lead_id = '', $file_type = '')
    {
        try {
            if (!empty($lead_id) && !empty($list_id)) {
                $lead_record = Lead::on("mysql_" . $request->auth->parent_id)->where('id', "=", $lead_id)->first();
                $lead_assigned_to = $lead_record->assigned_to;
                $lead_signature = $lead_record->signature_image;
                $lead_signature2 = $lead_record->owner_2_signature_image;
                $owner_2_sign_date = $lead_record->owner_2_signature_date;

                $lead_created_at = $this->formatDateIfNecessary(substr($lead_record->created_at, 0, 10));

                $label_header = CrmLabel::on("mysql_" . $request->auth->parent_id)->get();

                foreach ($label_header as $key => $val) {
                    $new_array[$val['label_title_url']] = $this->formatDateIfNecessary($lead_record[$val['column_name']]);
                }

                $tpl_record = CustomTemplates::on("mysql_" . $request->auth->parent_id)->where('custom_type', 'signature_application')->first();
                $email_content = $tpl_record->template_html;

                foreach ($new_array as $key1 => $val) {
                    $replace = "[[" . $key1 . "]]";
                    $email_content = str_replace($replace, $val, $email_content);
                }

                $user_detail = User::findOrFail($lead_assigned_to)->toArray();

                foreach ($user_detail as $k1 => $vl1) {
                    $replace_key = "[" . $k1 . "]";
                    $email_content = str_replace($replace_key, $this->formatDateIfNecessary($vl1), $email_content);
                }

                $subject_content = $tpl_record->subject;
                foreach ($new_array as $key1 => $val) {
                    $replace_subject = "[[" . $key1 . "]]";
                    $subject_content = str_replace($replace_subject, $val, $subject_content);
                }

                $user_detail = User::findOrFail($request->auth->id)->toArray();
                foreach ($user_detail as $k1 => $vl1) {
                    $replace_subject_key = "[[" . $k1 . "]]";
                    $subject_content = str_replace($replace_subject_key, $this->formatDateIfNecessary($vl1), $subject_content);
                }

                $system_setting = SystemSetting::on("mysql_" . $request->auth->parent_id)->first()->toArray();
                foreach ($system_setting as $sys => $vl1) {
                    $replace_key = "_" . $sys . "_";
                    if ($sys == 'logo') {
                        if ($file_type == 'pdf') {
                            $vl1 = '<img alt="" src="' . env('SIGNED_APPLICATION_PDF_LOGO') . $vl1 . '" style="width:30%">';
                        } else {
                            $vl1 = '<img alt="" src="/logo/' . $vl1 . '" style="width:30%">';
                        }
                    }
                    $email_content = str_replace($replace_key, $this->formatDateIfNecessary($vl1), $email_content);
                }

                preg_match_all("/\\[\\[(.*?)\\]\\]/", $email_content, $matches);
                if (!empty($matches[1])) {
                    foreach ($matches[1] as $key) {
                        $label = CrmLabel::on("mysql_" . $request->auth->parent_id)->where("label_title_url", "=", $key)->first();
                        $value = '';
                        if ($label) {
                            $column = $label->column_name;
                            $value = $this->formatDateIfNecessary($lead_record[$column]);
                        } elseif ($key == 'signature_image') {
                            if ($file_type == 'pdf') {
                                if (isset($lead_signature)) {
                                    $value = '<img alt="" src="' . env('SIGNED_APPLICATION_SIGNATURE_IMG') . $lead_signature . '" style="height:55px">';
                                } else {
                                    $value = '';
                                }
                            } else {
                                $value = '<img alt="" src="/uploads/signature/' . $lead_signature . '" style="height:55px">';
                            }
                        } elseif ($key == 'owner_2_signature_image') {
                            if (!empty($lead_signature2)) {
                                if ($file_type == 'pdf') {
                                    if (isset($lead_signature)) {
                                        $value = '<img alt="" src="' . env('SIGNED_APPLICATION_SIGNATURE_IMG') . $lead_signature2 . '" style="height:55px">';
                                    } else {
                                        $value = '';
                                    }
                                } else {
                                    $value = '<img alt="" src="/uploads/signature/' . $lead_signature2 . '" style="height:55px">';
                                }
                            } else {
                                $value = '';
                            }
                        } elseif ($key == 'owner_2_signature_date') {
                            if (!empty($lead_signature2)) {
                                if (!empty($owner_2_sign_date)) {
                                    $value = $this->formatDateIfNecessary($owner_2_sign_date);
                                } else {
                                    $value = '';
                                }
                            }
                        } elseif ($key == 'lead_created_at') {
                            $value = $this->formatDateIfNecessary($lead_created_at);
                        }
                        $replace = '[[' . $key . ']]';
                        $email_content = str_replace($replace, $value, $email_content);
                    }
                }

                preg_match_all("/\\[\\[(.*?)\\]\\]/", $subject_content, $matches_subject);
                if (!empty($matches_subject[1])) {
                    foreach ($matches_subject[1] as $key) {
                        $label = CrmLabel::on("mysql_" . $request->auth->parent_id)->where("title", "=", $key)->first();
                        $value = '';
                        if ($label) {
                            $column = $label->column_name;
                            $value = $this->formatDateIfNecessary($lead_record[$column]);
                        }
                        $replace = '[[' . $key . ']]';
                        $subject_content = str_replace($replace, $value, $subject_content);
                    }
                }

                $templates = [
                    'template_html' => $email_content,
                    'subject' => $subject_content
                ];

                return $this->successResponse("Template Info", $templates);
            }
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Template Not Found", ["Invalid template id $id"], $exception, 404);
        } catch (\Exception $exception) {
            return $this->failResponse("Failed to fetch the template", [$exception->getMessage()], $exception, 500);
        }
    }
    public function viewPDFPopupMerchant(Request $request, int $id, $list_id = '', $lead_id = '', $parent_id, $file_type = '')
    {
        try {
            if (!empty($lead_id) && !empty($list_id)) {
                $lead_record = Lead::on("mysql_" . $parent_id)->where('id', "=", $lead_id)->first();
                $lead_assigned_to = $lead_record->assigned_to;
                $lead_signature = $lead_record->signature_image;
                $lead_signature2 = $lead_record->owner_2_signature_image;
                $owner_2_sign_date = $this->formatDateIfNecessary($lead_record->owner_2_signature_date);  // Apply date formatting

                $lead_created_at = $this->formatDateIfNecessary(substr($lead_record->created_at, 0, 10));  // Apply date formatting

                $label_header = CrmLabel::on("mysql_" . $parent_id)->get();

                foreach ($label_header as $key => $val) {
                    $new_array[$val['label_title_url']] = $this->formatDateIfNecessary($lead_record[$val['column_name']]);
                }

                $tpl_record = CustomTemplates::on("mysql_" . $parent_id)->where('custom_type', 'signature_application')->first();
                $email_content = $tpl_record->template_html;
                foreach ($new_array as $key1 => $val) {
                    $replace = "[[" . $key1 . "]]";
                    $email_content = str_replace($replace, $val, $email_content);
                }

                $user_detail = User::findOrFail($lead_assigned_to)->toArray();
                foreach ($user_detail as $k1 => $vl1) {
                    $replace_key = "[" . $k1 . "]";
                    $email_content = str_replace($replace_key, $vl1, $email_content);
                }

                $subject_content = $tpl_record->subject;
                foreach ($new_array as $key1 => $val) {
                    $replace_subject = "[[" . $key1 . "]]";
                    $subject_content = str_replace($replace_subject, $val, $subject_content);
                }
                foreach ($user_detail as $k1 => $vl1) {
                    $replace_subject_key = "[[" . $k1 . "]]";
                    $subject_content = str_replace($replace_subject_key, $vl1, $subject_content);
                }

                $system_setting = SystemSetting::on("mysql_" . $parent_id)->first()->toArray();
                foreach ($system_setting as $sys => $vl1) {
                    $replace_key = "_" . $sys . "_";
                    if ($sys == 'logo') {
                        if ($file_type == 'pdf') {
                            $vl1 = '<img alt="" src="' . env('SIGNED_APPLICATION_PDF_LOGO') . $vl1 . '" style="width:30%">';
                        } else {
                            $vl1 = '<img alt="" src="/logo/' . $vl1 . '" style="width:30%">';
                        }
                    }
                    $email_content = str_replace($replace_key, $vl1, $email_content);
                }

                preg_match_all("/\\[\\[(.*?)\\]\\]/", $email_content, $matches);
                if (!empty($matches[1])) {
                    $count = count($matches[1]);
                    if ($count > 0) {
                        for ($i = 0; $i < $count; $i++) {
                            $pending_key = $matches[1][$i];
                            $label = CrmLabel::on("mysql_" . $parent_id)->where("label_title_url", "=", $pending_key)->first();
                            if (!empty($label)) {
                                $column = $label->column_name;
                                $value = $this->formatDateIfNecessary($lead_record[$column]);  // Apply date formatting
                                $replace = $matches[0][$i];
                                $email_content = str_replace($replace, $value, $email_content);
                            } else if ($pending_key == 'signature_image') {
                                if ($file_type == 'pdf') {
                                    if (isset($lead_signature)) {
                                        $value = '<img alt="" src="' . env('SIGNED_APPLICATION_SIGNATURE_IMG') . $lead_signature . '" style="height:55px">';
                                    } else {
                                        $value = '';
                                    }
                                } else {
                                    $value = '<img alt="" src="/uploads/signature/' . $lead_signature . '" style="height:55px">';
                                }
                                $replace = $matches[0][$i];
                                $email_content = str_replace($replace, $value, $email_content);
                            } elseif ($pending_key == 'owner_2_signature_image') {
                                if (!empty($lead_signature2)) {
                                    if ($file_type == 'pdf') {
                                        if (isset($lead_signature2)) {
                                            $value = '<img alt="" src="' . env('SIGNED_APPLICATION_SIGNATURE_IMG') . $lead_signature2 . '" style="height:55px">';
                                        } else {
                                            $value = '';
                                        }
                                    } else {
                                        $value = '<img alt="" src="/uploads/signature/' . $lead_signature2 . '" style="height:55px">';
                                    }
                                } else {
                                    $value = '';
                                }
                                $replace = $matches[0][$i];
                                $email_content = str_replace($replace, $value, $email_content);
                            } elseif ($pending_key == 'owner_2_signature_date' && !empty($lead_signature2)) {
                                if (!empty($owner_2_sign_date)) {
                                    $value = $this->formatDateIfNecessary($owner_2_sign_date);
                                } else {
                                    $value = '';
                                }
                                $replace = $matches[0][$i];
                                $email_content = str_replace($replace, $value, $email_content);
                            } else if ($pending_key == 'lead_created_at') {
                                $replace = $matches[0][$i];
                                $values = explode(' ', $lead_created_at);
                                $value = $values[0];
                                $email_content = str_replace($replace, $value, $email_content);
                            } else {
                                $value = '';
                                $replace = '[[' . $pending_key . ']]';
                                $email_content = str_replace($replace, $value, $email_content);
                            }
                        }
                    }
                }

                preg_match_all("/\\[\\[(.*?)\\]\\]/", $subject_content, $matches_subject);
                if (!empty($matches_subject[1])) {
                    $count = count($matches_subject[1]);
                    if ($count > 0) {
                        for ($i = 0; $i < $count; $i++) {
                            $pending_key = $matches_subject[1][$i];
                            $label = CrmLabel::on("mysql_" . $parent_id)->where("title", "=", $pending_key)->first();
                            if (!empty($label)) {
                                $column = $label->column_name;
                                $value = $this->formatDateIfNecessary($lead_record[$column]);  // Apply date formatting
                                $replace = $matches_subject[0][$i];
                                $subject_content = str_replace($replace, $value, $subject_content);
                            } else {
                                $value = '';
                                $replace = '[[' . $pending_key . ']]';
                                $subject_content = str_replace($replace, $value, $subject_content);
                            }
                        }
                    }
                }

                $templates = [
                    'template_html' => $email_content,
                    'subject' => $subject_content
                ];

                return $this->successResponse("Template Info", $templates);
            }
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Template Not Found", [
                "Invalid template id $id"
            ], $exception, 404);
        } catch (\Exception $exception) {
            return $this->failResponse("Failed to fetch the template", [
                $exception->getMessage()
            ], $exception, 500);
        }
    }

    private function formatDateIfNecessary($value)
    {
        if ($this->isDateInYmdFormat($value)) {
            return DateTime::createFromFormat('Y-m-d', $value)->format('m/d/Y');
        }
        return $value;
    }

    private function isDateInYmdFormat($value)
    {
        $d = DateTime::createFromFormat('Y-m-d', $value);
        return $d && $d->format('Y-m-d') === $value;
    }
}
