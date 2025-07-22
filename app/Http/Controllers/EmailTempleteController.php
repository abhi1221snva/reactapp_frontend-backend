<?php

namespace App\Http\Controllers;

use App\Model\User;
use App\Model\Client\EmailTemplete;
use App\Model\Client\ListData;
use App\Model\Client\ListHeader;
use App\Model\Client\Label;
use App\Model\Client\CustomFieldLabelsValues;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;


class EmailTempleteController extends Controller
{

    /**
     * @OA\Get(
     *     path="/email-templates",
     *     summary="Get All Email Templates",
     *     description="Fetches all email templates .",
     *     operationId="getEmailTemplates",
     *     tags={"EmailTemplate"},
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
     *         description="List of email templates",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Template List"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="title", type="string", example="Welcome Email"),
     *                     @OA\Property(property="subject", type="string", example="Welcome to our service"),
     *                     @OA\Property(property="body", type="string", example="<p>Hello, welcome!</p>"),
     *                     @OA\Property(property="created_at", type="string", format="date-time", example="2024-01-10T14:48:00.000Z"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time", example="2024-04-21T14:48:00.000Z")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Failed to fetch templates")
     *         )
     *     )
     * )
     */

    public function index(Request $request)
    {
        $templates = EmailTemplete::on("mysql_" . $request->auth->parent_id)->get()->all();
        if ($request->has('start') && $request->has('limit')) {
            $total_row = count($templates);
            $start = (int)$request->input('start'); // Start index (0-based)
            $limit = (int)$request->input('limit'); // Limit number of records to fetch
            $templates = array_slice($templates, $start, $limit, false);
            return $this->successResponse("Custom Templates", [
                'start' => $start,
                'limit' => $limit,
                'total' => $total_row,
                'data' => $templates
            ]);
        }
        return $this->successResponse("Template List", $templates);
    }


    public function index_old(Request $request)
    {
        $templates = EmailTemplete::on("mysql_" . $request->auth->parent_id)->get()->all();

        return $this->successResponse("Template List", $templates);
    }


    /**
     * @OA\Put(
     *     path="/email-template",
     *     tags={"EmailTemplate"},
     *     summary="Create a new Email Template",
     *     description="Creates a new email template for the authenticated client.",
     *     operationId="createEmailTemplate",
     *     security={{"Bearer":{}}},
     **     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"template_name", "template_html", "subject"},
     *             @OA\Property(property="template_name", type="string", example="Welcome Email", description="Unique name for the template"),
     *             @OA\Property(property="template_html", type="string", example="<p>Hello [[name]]</p>", description="HTML content of the template"),
     *             @OA\Property(property="subject", type="string", example="Welcome to our service", description="Subject of the email"),
     *             @OA\Property(property="status", type="string", example="1", description="Status of the template")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Template created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Added Successfully"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation failed",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="The template_name field is required.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Failed to create template")
     *         )
     *     )
     * )
     */

    public function create(Request $request)
    {
        $clientid = $request->auth->parent_id;
        $this->validate($request, [
            "template_name" => "required|string|unique:mysql_$clientid.email_templates"
        ]);
        try {
            $template = new EmailTemplete($request->all());
            $template->setConnection("mysql_$clientid");
            $template->saveOrFail();
            return $this->successResponse("Added Successfully", $template->toArray());
        } catch (\Exception $exception) {
            return $this->failResponse("Failed to create template ", [
                $exception->getMessage()
            ], $exception, 500);
        }
    }

    /*
     * function get_string_between($string, $start, $end){
     * $string = ' ' . $string;
     * $ini = strpos($string, $start);
     * if ($ini == 0) return '';
     * $ini += strlen($start);
     * $len = strpos($string, $end, $ini) - $ini;
     * return substr($string, $ini, $len);
     * }
     */
    /**
     * @OA\Get(
     *     path="/label-data/{id}/{list_id}/{lead_id}",
     *     summary="Get the value of a label based on label ID, list ID, and lead ID",
     *     tags={"EmailTemplate"},
     *     operationId="getLabelValue",
     *     security={{"Bearer":{}}},
     *
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Label ID",
     *         @OA\Schema(type="integer", example=10)
     *     ),
     *     @OA\Parameter(
     *         name="list_id",
     *         in="path",
     *         required=true,
     *         description="List ID where the label is used",
     *         @OA\Schema(type="string", example="22")
     *     ),
     *     @OA\Parameter(
     *         name="lead_id",
     *         in="path",
     *         required=true,
     *         description="Lead ID whose label value needs to be fetched",
     *         @OA\Schema(type="string", example="1001")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Label value fetched successfully",
     *         @OA\JsonContent(
     *             type="string",
     *             example="John Smith"
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Template Not Found",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Template Not Found")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error while fetching label value",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Failed to fetch the template")
     *         )
     *     )
     * )
     */

    public function labelValue(Request $request, int $id, $list_id = '', $lead_id = '')
    {
        try {

            if (! empty($lead_id) && ! empty($list_id)) {

                $label_id = $id;
                // echo "<pre>";print_r($Label);die;
                $listHeader = ListHeader::on("mysql_" . $request->auth->parent_id)->where("label_id", "=", $label_id)
                    ->where("is_deleted", "=", '0')
                    ->where("list_id", "=", $list_id)
                    ->first();
                //echo "<pre>";print_r($listHeader);die;

                $column_name = $listHeader->column_name;
                $listData = ListData::on("mysql_" . $request->auth->parent_id)->where('id', "=", $lead_id)->first();

                return (array)$listData[$column_name];
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

    /**
     * @OA\Get(
     *     path="/email-template/{id}",
     *     tags={"EmailTemplate"},
     *     summary="Get Email Template ",
     *     description="Returns the processed email template with subject and HTML content including lead and user-specific merged values.",
     *     operationId="getEmailTemplateById",
     *     security={{"Bearer":{}}},
     * 
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Email Template ID",
     *         required=true,
     *         @OA\Schema(type="integer", example=12)
     *     ),
     *     @OA\Parameter(
     *         name="list_id",
     *         in="query",
     *         description="List ID for fetching headers",
     *         required=false,
     *         @OA\Schema(type="integer", example=1001)
     *     ),
     *     @OA\Parameter(
     *         name="lead_id",
     *         in="query",
     *         description="Lead ID used for replacing fields in the template",
     *         required=false,
     *         @OA\Schema(type="integer", example=2025)
     *     ),
     * 
     *     @OA\Response(
     *         response=200,
     *         description="Success: Email template with replaced dynamic values",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Template Info"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="template_html", type="string", example="<p>Hello [[name]]</p>"),
     *                 @OA\Property(property="subject", type="string", example="Welcome [[name]]")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Template Not Found"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server Error"
     *     )
     * )
     */


    public function show(Request $request, int $id, $list_id = '', $lead_id = '')
    {
        $new_array_custom = array();
        try {

            if (! empty($lead_id) && ! empty($list_id)) {
                $lead_record = ListData::on("mysql_" . $request->auth->parent_id)->where('id', "=", $lead_id)->first();

                if (! empty($lead_record->list_id)) {
                    $list_header = ListHeader::on("mysql_" . $request->auth->parent_id)->where("list_id", "=", $list_id)->get();

                    foreach ($list_header as $key => $val) {
                        $new_array[$val['header']] = $lead_record[$val['column_name']];
                    }

                    $tpl_record = EmailTemplete::on("mysql_" . $request->auth->parent_id)->findOrFail($id);

                    $email_content = $tpl_record->template_html;
                    foreach ($new_array as $key1 => $val) {
                        $replace = "[[" . $key1 . "]]";
                        $email_content = str_replace($replace, $val, $email_content);
                    }
                    //$user_detail = User::findOrFail($request->auth->id)->first()->toArray();
                    $user_detail = User::findOrFail($request->auth->id)->toArray();

                    // dd($user_details);
                    foreach ($user_detail as $k1 => $vl1) {
                        $replace_key = "[[" . $k1 . "]]";

                        $email_content = str_replace($replace_key, $vl1, $email_content);
                    }

                    //custom filled labels
                    $custom_field_labels_values = CustomFieldLabelsValues::on("mysql_" . $request->auth->parent_id)->get();
                    foreach ($custom_field_labels_values as $key => $val) {
                        $new_array_custom[$val['title_match']] = $val->title_links;
                    }

                    Log::debug("customFieldLabels.Labels", [$new_array_custom]);
                    foreach ($new_array_custom as $key1 => $val) {
                        $replace_custom = "[[" . $key1 . "]]";
                        $email_content = str_replace($replace_custom, $val, $email_content);
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

                    //$subject_content = str_replace('[[', '', $subject_content);
                    //$subject_content = str_replace(']]', '', $subject_content);

                    preg_match_all("/\\[\\[(.*?)\\]\\]/", $email_content, $matches);
                    if (!empty($matches[1])) {
                        $count = count($matches[1]);
                        if ($count > 0) {
                            for ($i = 0; $i < $count; $i++) {
                                $pending_key =  $matches[1][$i];
                                $label = Label::on("mysql_" . $request->auth->parent_id)->where("title", "=", $pending_key)->first();
                                if (!empty($label)) {
                                    $lebel_id = $label->id;
                                    $listHeader = ListHeader::on("mysql_" . $request->auth->parent_id)->where("label_id", "=", $lebel_id)->where("list_id", "=", $lead_record['list_id'])->first();
                                    if (!empty($listHeader)) {
                                        $column = $listHeader->column_name;
                                        $value = $lead_record[$column];
                                        $replace = $matches[0][$i];
                                        $email_content = str_replace($replace, $value, $email_content);
                                    } else {
                                        $value = '';
                                        $replace = $matches[0][$i];
                                        $email_content = str_replace($replace, $value, $email_content);
                                    }
                                } else {
                                    $value = '';
                                    $replace = '[[' . $pending_key . ']]';
                                    $email_content = str_replace($replace, $value, $email_content);
                                }
                            }
                        }
                    }

                    //subject matches

                    preg_match_all("/\\[\\[(.*?)\\]\\]/", $subject_content, $matches_subject);


                    if (!empty($matches_subject[1])) {
                        $count = count($matches_subject[1]);
                        if ($count > 0) {
                            for ($i = 0; $i < $count; $i++) {
                                $pending_key =  $matches_subject[1][$i];
                                $label = Label::on("mysql_" . $request->auth->parent_id)->where("title", "=", $pending_key)->first();
                                if (!empty($label)) {
                                    $lebel_id = $label->id;
                                    $listHeader = ListHeader::on("mysql_" . $request->auth->parent_id)->where("label_id", "=", $lebel_id)->where("list_id", "=", $lead_record['list_id'])->first();
                                    if (!empty($listHeader)) {
                                        $column = $listHeader->column_name;
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
                } else {
                    $tpl_record = EmailTemplete::on("mysql_" . $request->auth->parent_id)->findOrFail($id);
                    $email_content = $tpl_record->template_html;
                    preg_match_all("/\\[\\[(.*?)\\]\\]/", $email_content, $matches);
                    //return $matches;
                    $count = count($matches[1]);
                    if ($count > 0) {
                        for ($i = 0; $i < $count; $i++) {
                            //return $matches[1][$i];
                            $email_content = str_replace('[[' . $matches[1][$i] . ']]', '', $email_content);
                        }
                    }

                    $subject_content = $tpl_record->subject;
                    preg_match_all("/\\[\\[(.*?)\\]\\]/", $subject_content, $matches);
                    //return $matches;
                    $count = count($matches[1]);
                    if ($count > 0) {
                        for ($i = 0; $i < $count; $i++) {
                            //return $matches[1][$i];
                            $subject_content = str_replace('[[' . $matches[1][$i] . ']]', '', $subject_content);
                        }
                    }

                    $templates = array();
                    $templates['template_html'] = $email_content;
                    $templates['subject'] = $subject_content;
                }

                return $this->successResponse("Template Info",  $templates);
            } else {
                $template = EmailTemplete::on("mysql_" . $request->auth->parent_id)->findOrFail($id);
                return $this->successResponse("Template Info", $template->toArray());
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

    public function showCRM(Request $request)
    {
        $id = $request->template_id;
        $list_id = $request->list_id;
        $lead_id = $request->lead_id;

        $new_array_custom = array();
        try {

            if (! empty($lead_id) && ! empty($list_id)) {
                $lead_record = ListData::on("mysql_" . $request->auth->parent_id)->where('id', "=", $lead_id)->first();

                if (! empty($lead_record->list_id)) {
                    $list_header = ListHeader::on("mysql_" . $request->auth->parent_id)->where("list_id", "=", $list_id)->get();

                    foreach ($list_header as $key => $val) {
                        $new_array[$val['header']] = $lead_record[$val['column_name']];
                    }

                    $tpl_record = EmailTemplete::on("mysql_" . $request->auth->parent_id)->findOrFail($id);

                    $email_content = $tpl_record->template_html;
                    foreach ($new_array as $key1 => $val) {
                        $replace = "[[" . $key1 . "]]";
                        $email_content = str_replace($replace, $val, $email_content);
                    }
                    //$user_detail = User::findOrFail($request->auth->id)->first()->toArray();
                    $user_detail = User::findOrFail($request->auth->id)->toArray();

                    // dd($user_details);
                    foreach ($user_detail as $k1 => $vl1) {
                        $replace_key = "[[" . $k1 . "]]";

                        $email_content = str_replace($replace_key, $vl1, $email_content);
                    }

                    //custom filled labels
                    $custom_field_labels_values = CustomFieldLabelsValues::on("mysql_" . $request->auth->parent_id)->get();
                    foreach ($custom_field_labels_values as $key => $val) {
                        $new_array_custom[$val['title_match']] = $val->title_links;
                    }

                    Log::debug("customFieldLabels.Labels", [$new_array_custom]);
                    foreach ($new_array_custom as $key1 => $val) {
                        $replace_custom = "[[" . $key1 . "]]";
                        $email_content = str_replace($replace_custom, $val, $email_content);
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

                    //$subject_content = str_replace('[[', '', $subject_content);
                    //$subject_content = str_replace(']]', '', $subject_content);

                    preg_match_all("/\\[\\[(.*?)\\]\\]/", $email_content, $matches);
                    if (!empty($matches[1])) {
                        $count = count($matches[1]);
                        if ($count > 0) {
                            for ($i = 0; $i < $count; $i++) {
                                $pending_key =  $matches[1][$i];
                                $label = Label::on("mysql_" . $request->auth->parent_id)->where("title", "=", $pending_key)->first();
                                if (!empty($label)) {
                                    $lebel_id = $label->id;
                                    $listHeader = ListHeader::on("mysql_" . $request->auth->parent_id)->where("label_id", "=", $lebel_id)->where("list_id", "=", $lead_record['list_id'])->first();
                                    if (!empty($listHeader)) {
                                        $column = $listHeader->column_name;
                                        $value = $lead_record[$column];
                                        $replace = $matches[0][$i];
                                        $email_content = str_replace($replace, $value, $email_content);
                                    } else {
                                        $value = '';
                                        $replace = $matches[0][$i];
                                        $email_content = str_replace($replace, $value, $email_content);
                                    }
                                } else {
                                    $value = '';
                                    $replace = '[[' . $pending_key . ']]';
                                    $email_content = str_replace($replace, $value, $email_content);
                                }
                            }
                        }
                    }

                    //subject matches

                    preg_match_all("/\\[\\[(.*?)\\]\\]/", $subject_content, $matches_subject);


                    if (!empty($matches_subject[1])) {
                        $count = count($matches_subject[1]);
                        if ($count > 0) {
                            for ($i = 0; $i < $count; $i++) {
                                $pending_key =  $matches_subject[1][$i];
                                $label = Label::on("mysql_" . $request->auth->parent_id)->where("title", "=", $pending_key)->first();
                                if (!empty($label)) {
                                    $lebel_id = $label->id;
                                    $listHeader = ListHeader::on("mysql_" . $request->auth->parent_id)->where("label_id", "=", $lebel_id)->where("list_id", "=", $lead_record['list_id'])->first();
                                    if (!empty($listHeader)) {
                                        $column = $listHeader->column_name;
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
                } else {
                    $tpl_record = EmailTemplete::on("mysql_" . $request->auth->parent_id)->findOrFail($id);
                    $email_content = $tpl_record->template_html;
                    preg_match_all("/\\[\\[(.*?)\\]\\]/", $email_content, $matches);
                    //return $matches;
                    $count = count($matches[1]);
                    if ($count > 0) {
                        for ($i = 0; $i < $count; $i++) {
                            //return $matches[1][$i];
                            $email_content = str_replace('[[' . $matches[1][$i] . ']]', '', $email_content);
                        }
                    }

                    $subject_content = $tpl_record->subject;
                    preg_match_all("/\\[\\[(.*?)\\]\\]/", $subject_content, $matches);
                    //return $matches;
                    $count = count($matches[1]);
                    if ($count > 0) {
                        for ($i = 0; $i < $count; $i++) {
                            //return $matches[1][$i];
                            $subject_content = str_replace('[[' . $matches[1][$i] . ']]', '', $subject_content);
                        }
                    }

                    $templates = array();
                    $templates['template_html'] = $email_content;
                    $templates['subject'] = $subject_content;
                }

                return $this->successResponse("Template Info",  $templates);
            } else {
                $template = EmailTemplete::on("mysql_" . $request->auth->parent_id)->findOrFail($id);
                return $this->successResponse("Template Info", $template->toArray());
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

    /**
     * @OA\Post(
     *     path="/email-template/{id}",
     *     summary="Update Email Template",
     *     description="Updates the specified email template fields if provided.",
     *     tags={"EmailTemplate"},
     *     security={{"Bearer": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the email template to update",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="template_name", type="string", example="Welcome Template"),
     *             @OA\Property(property="template_html", type="string", example="<h1>Hello User</h1>"),
     *             @OA\Property(property="subject", type="string", example="Welcome Email")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Template updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Template Update"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="template_name", type="string", example="Welcome Template"),
     *                 @OA\Property(property="template_html", type="string", example="<h1>Hello</h1>"),
     *                 @OA\Property(property="subject", type="string", example="Welcome Email"),
     *                 @OA\Property(property="updated_at", type="string", format="date-time"),
     *                 @OA\Property(property="created_at", type="string", format="date-time")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Template not found or failed to update",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Template Not Found"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="string"))
     *         )
     *     )
     * )
     */

    public function update(Request $request, int $id)
    {
        $this->validate($request, [
            'template_name' => 'string',
            'template_html' => 'string',
            'subject' => 'string'

        ]);
        try {
            $template = EmailTemplete::on("mysql_" . $request->auth->parent_id)->findOrFail($id);

            if ($request->has("template_name"))
                $template->template_name = $request->input("template_name");
            if ($request->has("template_html"))
                $template->template_html = $request->input("template_html");
            if ($request->has("subject"))
                $template->subject = $request->input("subject");
            $template->saveOrFail();
            return $this->successResponse("Template Update", $template->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Template Not Found", [
                "Invalid template id $id"
            ], $exception, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update template", [
                $exception->getMessage()
            ], $exception, 404);
        }
    }



    /**
     * @OA\Delete(
     *     path="/email-template/{id}",
     *     tags={"EmailTemplate"},
     *     summary="Delete Email Template",
     *     description="Deletes the specified email template by its ID.",
     *     operationId="deleteEmailTemplate",
     *     security={{"Bearer":{}}},
     *
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID of the email template to delete",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Email template deleted successfully",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Template List"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=15),
     *                 @OA\Property(property="template_html", type="string", example="<p>Hello</p>"),
     *                 @OA\Property(property="subject", type="string", example="Welcome Email")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Template Not Found"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server Error"
     *     )
     * )
     */

    public function delete(Request $request, int $id)
    {
        try {
            $template = EmailTemplete::on("mysql_" . $request->auth->parent_id)->findOrFail($id);
            $deleted = $template->delete();
            if ($deleted) {
                return $this->successResponse("Email template deleted successfully", $template->toArray());
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


    /**
     * @OA\Post(
     *     path="/delete-email-templete",
     *     tags={"EmailTemplate"},
     *     summary="Delete Email Template",
     *     description="Update the status of an email template to indicate it has been deleted .",
     *     operationId="deleteEmailTemplateStatus",
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"templete_id", "is_deleted"},
     *             @OA\Property(property="templete_id", type="integer", example=5, description="ID of the email template"),
     *             @OA\Property(property="is_deleted", type="string", example="0", description="New status like 'deleted'")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Template status updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Template List"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Template not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Template Not Found")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error while updating status",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Failed to fetch the template")
     *         )
     *     )
     * )
     */

    public function deleteStatus(Request $request)
    {
        try {
            $template = EmailTemplete::on("mysql_" . $request->auth->parent_id)->findOrFail($request->templete_id);

            $template->status = $request->input('is_deleted');
            $deleted = $template->save();
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

    /**
     * @OA\Post(
     *     path="/status-update-email-template",
     *     tags={"EmailTemplate"},
     *     summary="Update Email Template Status",
     *     description="Update the status of an email template by its ID.",
     *     operationId="updateEmailTemplateStatus",
     *     security={{"Bearer":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"listId", "status"},
     *             @OA\Property(property="listId", type="integer", example=10, description="ID of the email template"),
     *             @OA\Property(property="status", type="string", example="1", description="New status to set")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Status updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="status", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="Email Template Status updated successfully")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Update failed",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="false"),
     *             @OA\Property(property="status", type="string", example="false"),
     *             @OA\Property(property="message", type="string", example="Email Template Status update failed")
     *         )
     *     )
     * )
     */

    function updateEmailTemplateStatus(Request $request)
    {
        $listId = $request->input('listId');
        $status = $request->input('status');

        $saveRecord = EmailTemplete::on('mysql_' . $request->auth->parent_id)
            ->where('id', $listId) // Use the actual listId received from the request
            ->update(array('status' => $status));


        // Log::debug('Received listId: ', ['listId' => $listId]);
        // Log::debug('Received status: ', ['status' => $status]);
        // Log::debug('Number of updated rows: ', ['saveRecord' => $saveRecord]);
        if ($saveRecord > 0) {
            return response()->json([
                'success' => 'true',
                'status' => 'true',
                'message' => 'Email Template Status updated successfully'
            ]);
        } else {
            return response()->json([
                'success' => 'false',
                'status' => 'false',
                'message' => 'Email Template  Status  update failed'
            ]);
        }
    }
}
