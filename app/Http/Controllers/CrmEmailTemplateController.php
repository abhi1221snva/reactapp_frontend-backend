<?php

namespace App\Http\Controllers;

use App\Model\Client\CrmEmailTemplate;

use Illuminate\Http\Request;
use App\Model\Role;
use App\Model\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Http\Helper\Log;
use App\Model\Client\CrmLabel;
use App\Model\Client\Lead;




class CrmEmailTemplateController extends Controller
{
    /**
     * @OA\Get(
     *     path="/crm-email-templates",
     *     summary="Get list of CRM email template details",
     *     tags={"Crm Email Templates"},
     *     security={{"Bearer":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="List of CRM email templates",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Templates fetched successfully."),
     *              description="extension data"
     *              
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized - Invalid Token"
     *     )
     * )
     */
    public function list(Request $request)
    {
        try {
            $clientId = $request->auth->parent_id;
            $EmailTemplates = [];
            $EmailTemplates = CrmEmailTemplate::on("mysql_$clientId")->orderBy('id', 'DESC')->get()->all();
            return $this->successResponse("Email Templates", $EmailTemplates);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to list Email Templates", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }

    /**
     * @OA\Put(
     *     path="/crm-add-email-template",
     *     summary="Create a new CRM email template",
     *     tags={"Crm Email Templates"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"template_name", "template_html", "subject"},
     *             @OA\Property(property="template_name", type="string", example="Welcome Email"),
     *             @OA\Property(property="template_html", type="string", example="<p>Hello {{name}}, welcome!</p>"),
     *             @OA\Property(property="subject", type="string", example="Welcome to our service")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Template added successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Added Successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=12),
     *                 @OA\Property(property="template_name", type="string", example="Welcome Email"),
     *                 @OA\Property(property="template_html", type="string", example="<p>Hello {{name}}, welcome!</p>"),
     *                 @OA\Property(property="subject", type="string", example="Welcome to our service")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid input"
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized - Invalid Token"
     *     )
     * )
     */
    public function create(Request $request)
    {
        $clientId = $request->auth->parent_id;
        $this->validate($request, [
            'template_name' => 'required|string|max:255',
            'template_html' => 'required|string',
            'subject' => 'required|string|max:255',
        ]);

        try {
            $EmailTemplates = new CrmEmailTemplate($request->all());
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
     *     path="/crm-email-template/{id}",
     *     summary="Get CRM email template details ",
     *     tags={"Crm Email Templates"},
     *     security={{"Bearer":{}}},
     *       @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the CRM email template",
     *         @OA\Schema(type="integer", example=1),
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="CRM email templates",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Templates fetched successfully."),
     *              description="extension data"
     *              
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized - Invalid Token"
     *     )
     * )
     */
    public function show(Request $request, int $id)
    {
        $email_template = [];
        $clientId = $request->auth->parent_id;
        try {
            $email_template = CrmEmailTemplate::on("mysql_$clientId")->findOrFail($id);
            $data = $email_template->toArray();
            return $this->successResponse("Email Template info", $data);
        } catch (ModelNotFoundException $exception) {
            throw new NotFoundHttpException("No Email Template with id $id");
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch Email Template info", [], $exception);
        }
    }

    public function delete(Request $request, $id)
    {
        $clientId = $request->auth->parent_id;
        try {
            $EmailTemplates = CrmEmailTemplate::on("mysql_$clientId")->find($id)->delete();
            return $this->successResponse("Email Template Successfully deleted", [$EmailTemplates]);
        } catch (ModelNotFoundException $exception) {
            throw new NotFoundHttpException("No Email Template with id $id");
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch Email Template info", [], $exception);
        }
    }
    /**
     * @OA\post(
     *     path="/crm-email-template/{id}",
     *     summary="Update CRM email template",
     *     tags={"Crm Email Templates"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the CRM email template to update",
     *         @OA\Schema(type="integer", example=12)
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"template_name", "template_html", "subject"},
     *             @OA\Property(property="template_name", type="string", example="Updated Template"),
     *             @OA\Property(property="template_html", type="string", example="<p>Updated HTML content</p>"),
     *             @OA\Property(property="subject", type="string", example="Updated Subject"),
     *             @OA\Property(property="lead_status", type="string", example="Hot"),
     *             @OA\Property(property="send_bcc", type="boolean", example=true)
     *         
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Template updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Email Template Update"),
     *             description="extension data"           
     *              )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Template not found"
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized - Invalid Token"
     *     )
     * )
     */
    public function update(Request $request, $id)
    {
        $clientId = $request->auth->parent_id;
        $this->validate($request, [
            'template_name' => 'required|string|max:255',
            'template_html' => 'required|string',
            'subject' => 'required|string|max:255',
        ]);
        try {
            $EmailTemplates = CrmEmailTemplate::on("mysql_$clientId")->findOrFail($id);

            if ($request->has("template_name"))
                $EmailTemplates->template_name = $request->input("template_name");
            if ($request->has("template_html"))
                $EmailTemplates->template_html = $request->input("template_html");
            if ($request->has("subject"))
                $EmailTemplates->subject = $request->input("subject");

            if ($request->has("lead_status"))
                $EmailTemplates->lead_status = $request->input("lead_status");
            if ($request->has("send_bcc"))
                $EmailTemplates->send_bcc = $request->input("send_bcc");
            $EmailTemplates->saveOrFail();
            return $this->successResponse("Email Template Update", $EmailTemplates->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Email Template Not Found", [
                "Invalid Template Type id $id"
            ], $exception, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to Email Template Type", [
                $exception->getMessage()
            ], $exception, 404);
        }
    }

    /**
     * @OA\Post(
     *     path="/crm-change-email-template-status",
     *     summary="Change the status of a CRM email template",
     *     tags={"Crm Email Templates"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"id", "status"},
     *             @OA\Property(property="id", type="integer", example=10, description="ID of the CRM email template"),
     *             @OA\Property(property="status", type="boolean", example=true, description="New status of the template (true for 1, false for 0)")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Email Template status updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Email Template Updated"),
     *             description="extension data"
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Email Template not found"
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized - Invalid Token"
     *     )
     * )
     */

    public function changeEmailTemplateStatus(Request $request)
    {
        $clientId = $request->auth->parent_id;
        try {
            $EmailTemplates = CrmEmailTemplate::on("mysql_$clientId")->findOrFail($request->email_template_id);
            $EmailTemplates->status = $request->status;
            $EmailTemplates->saveOrFail();
            return $this->successResponse(" Email Template Updated", $EmailTemplates->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse(" Email Template Not Found", [
                "Invalid Email Template id $id"
            ], $exception, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update  Email Template", [
                $exception->getMessage()
            ], $exception, 404);
        }
    }


    public function viewEmailPopup(Request $request, int $id, $list_id = '', $lead_id = '')
    {
        try {

            if (! empty($lead_id) && ! empty($list_id)) {
                $lead_record = Lead::on("mysql_" . $request->auth->parent_id)->where('id', "=", $lead_id)->first();
                //return $this->successResponse("Template Info",  [$lead_record]);

                // Merge EAV dynamic field values into lead_record
                $eavRows = \Illuminate\Support\Facades\DB::connection("mysql_" . $request->auth->parent_id)
                    ->table('crm_lead_field_values')
                    ->where('lead_id', $lead_id)
                    ->get(['column_name', 'value_text']);
                foreach ($eavRows as $eavRow) {
                    $lead_record->{$eavRow->column_name} = $eavRow->value_text;
                }

                // if (! empty($lead_record->list_id)) {
                $list_header = CrmLabel::on("mysql_" . $request->auth->parent_id)->get();

                // return $this->successResponse("Template Info",  [$list_header]);


                foreach ($list_header as $key => $val) {
                    $new_array[$val['label_title_url']] = $lead_record[$val['column_name']] ?? $lead_record->{$val['column_name']} ?? '';
                }


                //return $this->successResponse("Template Info",  $new_array);


                $tpl_record = CrmEmailTemplate::on("mysql_" . $request->auth->parent_id)->findOrFail($id);

                // return $this->successResponse("Template Info",  [$tpl_record]);


                $email_content = $tpl_record->template_html;
                foreach ($new_array as $key1 => $val) {
                    $replace = "[[" . $key1 . "]]";
                    $email_content = str_replace($replace, $val, $email_content);
                }


                // return $this->successResponse("Template Info",  [$tpl_record]);

                //$user_detail = User::findOrFail($request->auth->id)->first()->toArray();
                $user_detail = User::findOrFail($request->auth->id)->toArray();

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
                    $replace_subject_key = "[" . $k1 . "]";
                    $subject_content = str_replace($replace_subject_key, $vl1, $subject_content);
                }

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

                // Merge EAV dynamic field values into lead_record
                $eavRows = \Illuminate\Support\Facades\DB::connection("mysql_" . $request->auth->parent_id)
                    ->table('crm_lead_field_values')
                    ->where('lead_id', $lead_id)
                    ->get(['column_name', 'value_text']);
                foreach ($eavRows as $eavRow) {
                    $lead_record->{$eavRow->column_name} = $eavRow->value_text;
                }

                $list_header = CrmLabel::on("mysql_" . $request->auth->parent_id)->get();
                //return $this->successResponse("Template Info",  [$list_header]);

                foreach ($list_header as $key => $val) {
                    $new_array[$val['label_title_url']] = $lead_record[$val['column_name']] ?? $lead_record->{$val['column_name']} ?? '';
                }

                //return $this->successResponse("Template Info",  $new_array);

                // PdfTemplates model not yet implemented — return early
                return $this->failResponse("Signed application feature not yet available", [], null, 501);
                /** @phpstan-ignore-next-line */
                $tpl_record = null; // placeholder — unreachable

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
}
