<?php

namespace App\Http\Controllers;

use App\Model\UserFcmToken;
use App\Services\FirebaseService;
use App\Model\Client\SystemNotification;
use App\Model\Master\SystemNotificationType;
use Carbon\Carbon;
use DateTime;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use App\Model\Master\Client;
use App\Model\Client\EmailSetting;
use App\Services\CrmMailService;
use App\Model\User;
use App\Model\Client\Lead;
use App\Model\Client\CrmLabel;
use Illuminate\Support\Facades\Log;
use App\Model\Client\CrmEmailTemplate;
use App\Model\Master\AreaCodeList;
use App\Model\Client\SystemSetting;





class NotificationController extends Controller
{
    /**
     * @OA\Get(
     *     path="/notifications",
     *     summary="Get notification list",
     *     description="Returns a list of all notifications for the authenticated user.",
     *     tags={"Notification"},
     *     security={{"Bearer": {}}},
     * 
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
     *         description="Notification list retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Notification list"),
     *             @OA\Property(property="data", type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="title", type="string", example="New Lead Assigned"),
     *                     @OA\Property(property="message", type="string", example="A new lead has been assigned to you."),
     *                     @OA\Property(property="read_at", type="string", format="date-time", example="2025-04-16T09:30:00Z"),
     *                     @OA\Property(property="created_at", type="string", format="date-time", example="2025-04-15T12:00:00Z")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthenticated"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to retrieve notifications"
     *     )
     * )
     */

    // public function index(Request $request)
    // {
    //     $clientId = $request->auth->parent_id;
    //     $notifications = SystemNotificationType::all()->sortBy("display_order");
    //     $types = $notifications->toArray();
    //     foreach ($types as $key => $type) {
    //         $subscriptions = SystemNotification::on("mysql_$clientId")->find($type["id"]);
    //         if ($subscriptions) {
    //             $types[$key]["active"] = $subscriptions->active;
    //             $types[$key]["active_sms"] = $subscriptions->active_sms;

    //             $types[$key]["subscribers"] = $subscriptions->subscribers;
    //         } else {
    //             $types[$key]["active"] = 0;
    //             $types[$key]["active_sms"] = 0;

    //             $types[$key]["subscribers"] = [];
    //         }
    //     }

    //     if ($request->has('start') && $request->has('limit')) {
    //         $total_row = count($types);

    //         $start = (int) $request->input('start');  // Start index (0-based)
    //         $limit = (int) $request->input('limit');  // Number of records to fetch

    //         $types = array_slice($types, $start, $limit, true);

    //         return $this->successResponse("notifications", [
    //             'start' => $start,
    //             'limit' => $limit,
    //             'total' => $total_row,
    //             'data' => $types
    //         ]);
    //     }
    //     return $this->successResponse("notifications", $types);
    // }
    public function index(Request $request)
{
    $clientId = $request->auth->parent_id;
    $notifications = SystemNotificationType::all()->sortBy("display_order");
    $types = $notifications->toArray();
    $userId=$request->auth->id;
    $result = [];

    foreach ($types as $key => $type) {
        $subscriptions = SystemNotification::on("mysql_$clientId")->find($type["id"]);

        $result[] = [
            // 'index'        => $key,  // <-- move key inside object
            'id'           => $type["id"],
            'name'         => $type["name"],
            'type'         => $type["type"],
            'display_order'=> $type["display_order"],
            'created_at'   => $type["created_at"],
            'updated_at'   => $type["updated_at"],
            'type_sms'     => $type["type_sms"],
            'active'       => $subscriptions ? $subscriptions->active : 0,
            'active_sms'   => $subscriptions ? $subscriptions->active_sms : 0,
            'subscribers'  => $subscriptions ? $subscriptions->subscribers : [],
        ];
    }
   /* =========================
       🔔 SEND PUSH NOTIFICATION
       ========================= */
    try {
        $fcmTokens = UserFcmToken::where('user_id', $userId)
            ->pluck('device_token')
            ->toArray();

        if (!empty($fcmTokens)) {
            FirebaseService::sendNotification(
                $fcmTokens,
                'Notification Settings',
                'Notification settings viewed',
                [
                    'type' => 'notification_settings',
                    'user_id' => $userId
                ]
            );
        }
    } catch (\Exception $e) {
        Log::error('FCM Notification Settings failed', [
            'error' => $e->getMessage(),
            'user_id' => $userId
        ]);
    }
    // pagination logic
    if ($request->has('start') && $request->has('limit')) {
        $total_row = count($result);
        $start = (int) $request->input('start');
        $limit = (int) $request->input('limit');
        $pagedResult = array_slice($result, $start, $limit);

        return $this->successResponse("notifications", [
            'start' => $start,
            'limit' => $limit,
            'total' => $total_row,
            'data'  => array_values($pagedResult)
        ]);
    }

    return $this->successResponse("notifications", array_values($result));
}


    public function index_old_code(Request $request)
    {
        $clientId = $request->auth->parent_id;
        $notifications = SystemNotificationType::all()->sortBy("display_order");
        $types = $notifications->toArray();
        foreach ($types as $key => $type) {
            $subscriptions = SystemNotification::on("mysql_$clientId")->find($type["id"]);
            if ($subscriptions) {
                $types[$key]["active"] = $subscriptions->active;
                $types[$key]["active_sms"] = $subscriptions->active_sms;

                $types[$key]["subscribers"] = $subscriptions->subscribers;
            } else {
                $types[$key]["active"] = 0;
                $types[$key]["active_sms"] = 0;

                $types[$key]["subscribers"] = [];
            }
        }
        return $this->successResponse("notifications", $types);
    }



    /**
     * @OA\Post(
     *     path="/notifications",
     *     summary="Subscriptions updated",
     *     tags={"Notification"},
     *     security={{"Bearer": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="List of subscription settings",
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items(
     *                 type="object",
     *                 required={"notification_id", "active", "active_sms", "subscribers"},
     *                 @OA\Property(property="notification_id", type="string", example="send_fax_email"),
     *                 @OA\Property(property="active", type="integer", enum={0, 1}, example=1),
     *                 @OA\Property(property="active_sms", type="integer", enum={0, 1}, example=0),
     *                 @OA\Property(
     *                     property="subscribers",
     *                     type="array",
     *                     @OA\Items(type="integer", example=101)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description=" Subscriptions updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Subscriptions updated succeccfully"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="object"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation failed"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Subscription not found"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error"
     *     )
     * )
     */

    public function saveSubscriptions(Request $request)
    {
        $this->validate($request, [
            '*.notification_id' => 'required|string',
            '*.active' => 'required|in:0,1',
            '*.active_sms' => 'required|in:0,1',
            '*.subscribers' => 'array'
        ]);
        $subscriptions = $request->all();
        $clientId = $request->auth->parent_id;
        foreach ($subscriptions as $key => $val) {
            try {
                $subscription = SystemNotification::on("mysql_$clientId")->findOrFail($val["notification_id"]);
                $subscription->active = $val["active"];
                $subscription->active_sms = $val["active_sms"];

                if (isset($val["subscribers"])) {
                    $subscription->subscribers = array_map("intval", $val["subscribers"]);
                } else {
                    $subscription->subscribers = [];
                }
                $subscription->saveOrFail();
            } catch (ModelNotFoundException $exception) {
                throw new NotFoundHttpException("No subscription with id " . $val["notification_id"]);
            } catch (\Throwable $exception) {
                return $this->failResponse("Failed to update subscription", [$exception->getMessage()], $exception);
            }
        }
        return $this->successResponse("Subscriptions updated", []);
    }


    public function sendCrmNotification(int $clientId, array $data, $emailType)
    {
        $this->clientId = $clientId;
        $this->data = $data;
        $this->emailType = $emailType;

        $requestData = $this->data;
        $clientId = $this->clientId;
        $emailType = $this->emailType;
        $client = Client::findOrFail($clientId);

        $company_name = $client->company_name;

        $smtp_setting = EmailSetting::on("mysql_$clientId")->where('mail_type', $emailType)->first();
        if ($smtp_setting['send_email_via'] == 'user_email' && $smtp_setting['mail_type'] != 'notification') {
            $user = User::findOrFail($requestData['user']['user_id']);
            $smtp_setting['sender_email'] = $user->email;
            $smtp_setting['sender_name'] = $user->first_name . ' ' . $user->last_name;
        }

        //echo "<pre>";print_r($requestData);die;

        $mailable = $requestData['user']['mailable'];

        if ($requestData['action'] == 'notification') {
            $email_data = array();
            if ($requestData['user']['user_id'] == '0') {
                $name = Lead::on("mysql_$clientId")->findOrFail($requestData['user']['lead_id']);
            } else {
                $name = User::findOrFail($requestData['user']['user_id']);
                $email_data[] = $name->email;
            }

            $all_admin = User::where('base_parent_id', $clientId)->where('user_level', '7')->where('is_deleted', '0')->where('role', '1')->get()->all();
            $leadData = Lead::on("mysql_$clientId")->findOrFail($requestData['user']['lead_id']);
            $assignTo = $leadData->assigned_to;
            $createdBy = $leadData->created_by;

            $createdByUser = User::findOrFail($createdBy);
            $email_data[] = $createdByUser->email;

            // echo "<pre>";print_r($email_data);die;

            $assignToUser = User::findOrFail($assignTo);
            $email_data[] = $assignToUser->email;

            if (!empty($all_admin)) {
                foreach ($all_admin as $admin) {
                    $email_data[] = $admin['email'];
                    //$email_data[] = 'abhi2112mca@gmail.com';
                    //$email_data[] = 'abhi4mca@gmail.com';
                }
            }

            //echo "<pre>";print_r($email_data);
            $finalEmail = array_unique($email_data);

            //echo "<pre>";print_r($finalEmail);die;
            //$email_data[] = $name->email;

            // $subject = 'Status Update - '.$company_name.' Lead Id - '.$requestData['user']['lead_id'];
            // $subject = 'Status Update - ' . $company_name . ', Company Name - ' . $leadData->company_name;
            $subject = 'Status Update - '. $leadData->company_name;

            if ($requestData['user']['type'] == '1') {
                $message = $name->first_name . ' ' . $name->last_name . ' added notes <b>' . $requestData['user']['message'] . '</b>';
            } else {
                if ($requestData['user']['user_id'] == '0') {
                    $message = '<b>' . $requestData['user']['message'] . '</b>';
                } else {
                    $message = $name->first_name . ' ' . $name->last_name . ' - <b>' . $requestData['user']['message'] . '</b>';
                }
            }

            $data = array('subject' => $subject, 'content' => $message);
            $mailService = new CrmMailService($clientId, $mailable, $smtp_setting, $data);
            $to =  $finalEmail; //array('abhi4mca@gmail.com','mailme@rohitwanchoo.com');//env('SYSTEM_ADMIN_EMAIL'); //,'mailme@rohitwanchoo.com'

            $mailService->sendEmail($to);

            // Send Push Notification
            try {
                $fcmTokens = UserFcmToken::whereIn('user_id', User::whereIn('email', $finalEmail)->pluck('id'))->pluck('device_token')->toArray();
                if (!empty($fcmTokens)) {
                    FirebaseService::sendNotification($fcmTokens, $subject, strip_tags($message), [
                        'lead_id' => $requestData['user']['lead_id'],
                        'type' => 'crm_notification'
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('FCM CRM Notification failed', ['error' => $e->getMessage()]);
            }
        } else
        if ($requestData['action'] == 'lenders_submission') {
            $data = array('subject' => $requestData['user']['subject'], 'content' => $requestData['user']['message']);
            $mailService = new CrmMailService($clientId, $mailable, $smtp_setting, $data);
            $to = $requestData['user']['emails'];
            $path = $requestData['user']['file_paths'];
            $mailService->sendEmailAttachment($to, $path);
        } else {
            $data = array('subject' => $requestData['user']['subject'], 'content' => $requestData['user']['message']);
            $mailService = new CrmMailService($clientId, $mailable, $smtp_setting, $data);
            $to = array($requestData['user']['to']);
            $mailService->sendEmail($to);
        }
    }
    public function sendCrmNotificationMerchant(int $clientId, array $data, $emailType)
    {
        $this->clientId = $clientId;
        $this->data = $data;
        $this->emailType = $emailType;

        $requestData = $this->data;
        Log::info('request data', ["requestData" => $requestData]);
        $clientId = $this->clientId;
        $emailType = $this->emailType;
        $client = Client::findOrFail($clientId);
        try {
            //Step 1: Retrieve the column_name from CrmLabel
            $arrLabels = CrmLabel::on("mysql_$clientId")
                ->where('edit_mode', 1)
                ->where('label_title_url', '=', 'email')
                ->where('status', 1)
                ->pluck('column_name') // Get only the column_name(s)
                ->first();

            Log::info('Reached merchant email', ['arrLabels' => $arrLabels]);
            //             // Fetch the lead record matching the lead_id
            $lead = Lead::on("mysql_$clientId")
                ->where('id', $requestData['user']['lead_id']) // Match lead_id first
                ->first();

            if ($lead && $arrLabels) {
                // Retrieve the value of the dynamic column if it exists
                $columnValue = $lead->{$arrLabels};

                Log::info('Column value retrieved', [
                    'column_name' => $arrLabels,
                    'value' => $columnValue,
                ]);

                // You can now use $columnValue for further processing
            } else {
                Log::warning('Lead or column name not found', [
                    'lead_id' => $requestData['user']['lead_id'],
                    'column_name' => $arrLabels,
                ]);
            }
            $smtp_setting = EmailSetting::on("mysql_$clientId")->where('mail_type', $emailType)->first();

            if ($requestData['action'] == 'notification') {
                $email_data = array();
                if ($requestData['user']['user_id'] == '0') {
                    $name = Lead::on("mysql_$clientId")->findOrFail($requestData['user']['lead_id']);
                    $email_data[] = $columnValue;
                } else {
                    $name = User::findOrFail($requestData['user']['user_id']);
                    $email_data[] = $columnValue;
                }


                //echo "<pre>";print_r($email_data);
                $finalEmail = array_unique($email_data);
                Log::info('reached finalEmail', ['finalEmail' => $finalEmail]);
                //         //$email_data[] = $name->email;
                // Check if the message contains the phrase "has added the document"
                $message = $requestData['user']['message']; // Extract the message

                // Strip HTML tags
                $messagePlain = strip_tags($message);

                // Normalize spaces
                $messagePlain = preg_replace('/\s+/', ' ', trim($messagePlain));

                // Perform case-insensitive check
                $messagecheck = strpos(strtolower($messagePlain), 'has added the document') !== false;

                // Log the results
                Log::info('Full Message', ['message' => $message]);
                Log::info('Plain Message', ['messagePlain' => $messagePlain]);
                Log::info('Message Check Result', ['result' => $messagecheck]);


                if ($messagecheck == true) {
                    $CrmEmailTemplate = CrmEmailTemplate::on("mysql_$clientId")
                        ->where('lead_status', 'documents_acknowledgement')
                        ->first();
                } else {
                    $CrmEmailTemplate = CrmEmailTemplate::on("mysql_$clientId")
                        ->where('lead_status', 'application_acknowledgement')
                        ->first();
                }

                // Log the selected template for debugging
                Log::info('Selected Email Template', [
                    'template' => $CrmEmailTemplate,
                ]);
                Log::info('reached email template', ['CrmEmailTemplate' => $CrmEmailTemplate]);
                // Prepare the replacement data
                $email_content = $CrmEmailTemplate->template_html ?? '';
                $subject_content = $CrmEmailTemplate->subject ?? '';

                if (!empty($requestData['user']['lead_id'])) {
                    $lead_record = Lead::on("mysql_$clientId")->where('id', "=", $requestData['user']['lead_id'])->first();
                    $lead_assigned_to = $lead_record->created_by;
                    Log::info('reached', ['lead_assigned_to' => $lead_assigned_to]);
                    $lead_signature = $lead_record->signature_image;
                    $lead_signature2 = $lead_record->owner_2_signature_image;
                    $owner_2_sign_date = $lead_record->owner_2_signature_date;

                    $lead_created_at = $this->formatDateIfNecessary(substr($lead_record->created_at, 0, 10));

                    $label_header = CrmLabel::on("mysql_$clientId")->get();

                    foreach ($label_header as $key => $val) {
                        $new_array[$val['label_title_url']] = $this->formatDateIfNecessary($lead_record[$val['column_name']]);
                    }

                    // $tpl_record = CustomTemplates::on("mysql_" . $request->auth->parent_id)->where('custom_type', 'signature_application')->first();
                    // $email_content = $tpl_record->template_html;

                    foreach ($new_array as $key1 => $val) {
                        $replace = "[[" . $key1 . "]]";
                        $email_content = str_replace($replace, $val, $email_content);
                    }

                    $user_detail = User::findOrFail($lead_assigned_to)->toArray();
                    Log::info('reached user detail', ['user_detail' => $user_detail]);


                    foreach ($user_detail as $k1 => $vl1) {
                        $replace_key = "[" . $k1 . "]";
                        $email_content = str_replace($replace_key, $this->formatDateIfNecessary($vl1), $email_content);
                    }

                    foreach ($new_array as $key1 => $val) {
                        $replace_subject = "[[" . $key1 . "]]";
                        $subject_content = str_replace($replace_subject, $val, $subject_content);
                    }

                    $user_detail = User::findOrFail($lead_assigned_to)->toArray();
                    foreach ($user_detail as $k1 => $vl1) {
                        $replace_subject_key = "[[" . $k1 . "]]";
                        $subject_content = str_replace($replace_subject_key, $this->formatDateIfNecessary($vl1), $subject_content);
                    }

                    // $system_setting = SystemSetting::on("mysql_$clientId")->first()->toArray();
                    // foreach ($system_setting as $sys => $vl1) {
                    //     $replace_key = "_". $sys . "_";
                    // if ($sys == 'logo') {
                    //     if ($file_type == 'pdf') {
                    //         $vl1 = '<img alt="" src="'.env('SIGNED_APPLICATION_PDF_LOGO').$vl1.'" style="width:30%">';
                    //     } else {
                    //         $vl1 = '<img alt="" src="/logo/'.$vl1.'" style="width:30%">';
                    //     }
                    // }
                    // $email_content = str_replace($replace_key, $this->formatDateIfNecessary($vl1), $email_content);
                    //}

                    preg_match_all("/\\[\\[(.*?)\\]\\]/", $email_content, $matches);
                    if (!empty($matches[1])) {
                        foreach ($matches[1] as $key) {
                            $label = CrmLabel::on("mysql_$clientId")->where("label_title_url", "=", $key)->first();
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
                            $templateHtml = str_replace($replace, $value, $email_content);
                        }
                    }

                    preg_match_all("/\\[\\[(.*?)\\]\\]/", $subject_content, $matches_subject);
                    if (!empty($matches_subject[1])) {
                        foreach ($matches_subject[1] as $key) {
                            $label = CrmLabel::on("mysql_$clientId")->where("title", "=", $key)->first();
                            $value = '';
                            if ($label) {
                                $column = $label->column_name;
                                $value = $this->formatDateIfNecessary($lead_record[$column]);
                            }
                            $replace = '[[' . $key . ']]';
                            $subject_content = str_replace($replace, $value, $subject_content);
                        }
                    }

                    // $templates = [
                    //     'template_html' => $email_content,
                    //     'subject' => $subject_content
                    // ];
                    // Log::info('final Email Template', [
                    //     'template' => $templates,

                    // ]);
                    $data = array('subject' => $subject_content, 'content' => $email_content);
                    Log::info('reached email template data', ['data' => $data]);
                    $mailable = $requestData['user']['mailable'];

                    $mailService = new CrmMailService($clientId, $mailable, $smtp_setting, $data);
                    $to =  $finalEmail; //array('abhi4mca@gmail.com','mailme@rohitwanchoo.com');//env('SYSTEM_ADMIN_EMAIL'); //,'mailme@rohitwanchoo.com'
                    Log::info('reached final email', ['to' => $to]);
                    $mailService->sendEmail($to);
                }
            }
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Template Not Found", ["Invalid template id"], $exception, 404);
        } catch (\Exception $exception) {
            return $this->failResponse("Failed to fetch the template", [$exception->getMessage()], $exception, 500);
        }
    }

    // public function sendCrmNotificationMerchant(int $clientId, array $data, $emailType)
    // {
    //     $this->clientId = $clientId;
    //     $this->data = $data;
    //     $this->emailType = $emailType;

    //     $requestData = $this->data;
    //     Log::info('request data',["requestData"=>$requestData]);
    //     $clientId = $this->clientId;
    //     $emailType = $this->emailType;
    //     $client = Client::findOrFail($clientId);

    //     $company_name = $client->company_name;
    //     try {
    //         // Step 1: Retrieve the column_name from CrmLabel
    //         $arrLabels = CrmLabel::on("mysql_$clientId")
    //             ->where('edit_mode', 1)
    //             ->where('label_title_url', '=', 'email')
    //             ->where('status', 1)
    //             ->pluck('column_name') // Get only the column_name(s)
    //             ->first();

    //         Log::info('Reached merchant email', ['arrLabels' => $arrLabels]);


    //             // Fetch the lead record matching the lead_id
    //         $lead = Lead::on("mysql_$clientId")
    //         ->where('id', $requestData['user']['lead_id']) // Match lead_id first
    //         ->first();

    //         if ($lead && $arrLabels) {
    //             // Retrieve the value of the dynamic column if it exists
    //             $columnValue = $lead->{$arrLabels};

    //             Log::info('Column value retrieved', [
    //                 'column_name' => $arrLabels,
    //                 'value' => $columnValue,
    //             ]);

    //             // You can now use $columnValue for further processing
    //         } else {
    //             Log::warning('Lead or column name not found', [
    //                 'lead_id' => $requestData['user']['lead_id'],
    //                 'column_name' => $arrLabels,
    //             ]);
    //         }

    //     } catch (\Exception $exception) {
    //         Log::error('An error occurred while matching column_name', ['message' => $exception->getMessage()]);
    //         // Handle the exception appropriately
    //     }
    //     $crmLabels = CrmLabel::on("mysql_$clientId")
    //     ->where('edit_mode', 1)
    //     ->where('status', 1)
    //     ->get();

    //     $smtp_setting = EmailSetting::on("mysql_$clientId")->where('mail_type',$emailType)->first();    
    //     if($smtp_setting['send_email_via'] == 'user_email' && $smtp_setting['mail_type'] != 'notification') 
    //     {
    //         $user = User::findOrFail($requestData['user']['user_id']);
    //         $smtp_setting['sender_email'] = $columnValue;
    //         $smtp_setting['sender_name'] = $user->first_name.' '.$user->last_name;
    //     }  

    //     //echo "<pre>";print_r($requestData);die;

    //     $mailable = $requestData['user']['mailable'];

    //     if($requestData['action'] == 'notification')
    //     {
    //         $email_data = array();
    //         if($requestData['user']['user_id'] == '0')
    //         {
    //             $name = Lead::on("mysql_$clientId")->findOrFail($requestData['user']['lead_id']);
    //             $email_data[] = $columnValue;

    //         }

    //         else
    //         {
    //             $name = User::findOrFail($requestData['user']['user_id']);
    //             $email_data[] = $columnValue;
    //         }


    //         //echo "<pre>";print_r($email_data);
    //         $finalEmail = array_unique($email_data);
    //         Log::info('reached finalEmail',['finalEmail'=>$finalEmail]);

    //         //echo "<pre>";print_r($finalEmail);die;
    //         //$email_data[] = $name->email;
    //         // Check if the message contains the phrase "has added the document"
    //         $message = $requestData['user']['message']; // Extract the message

    //         // Strip HTML tags
    //         $messagePlain = strip_tags($message);

    //         // Normalize spaces
    //         $messagePlain = preg_replace('/\s+/', ' ', trim($messagePlain));

    //         // Perform case-insensitive check
    //         $messagecheck = strpos(strtolower($messagePlain), 'has added the document') !== false;

    //         // Log the results
    //         Log::info('Full Message', ['message' => $message]);
    //         Log::info('Plain Message', ['messagePlain' => $messagePlain]);
    //         Log::info('Message Check Result', ['result' => $messagecheck]);


    // if ($messagecheck== true) {
    //     $CrmEmailTemplate = CrmEmailTemplate::on("mysql_$clientId")
    //         ->where('lead_status', 'documents_acknowledgement')
    //         ->first();
    // }
    //          else {
    //             $CrmEmailTemplate = CrmEmailTemplate::on("mysql_$clientId")
    //                 ->where('lead_status', 'application_acknowledgement')
    //                 ->first();
    //         }

    //         // Log the selected template for debugging
    //         Log::info('Selected Email Template', [
    //             'template' => $CrmEmailTemplate,
    //         ]);
    //         Log::info('reached email template',['CrmEmailTemplate'=>$CrmEmailTemplate]);
    //         // Prepare the replacement data
    //         $templateHtml = $CrmEmailTemplate->template_html ?? '';
    //         $templateSubject = $CrmEmailTemplate->subject ?? '';

    //         $placeholders = [];

    //         // Check if the template contains square brackets (single placeholder format)


    //         $crmLabels = CrmLabel::on("mysql_$clientId")
    //         ->where('edit_mode', 1)
    //         ->where('status', 1)
    //         ->get();

    //         foreach ($crmLabels as $label) {
    //             $columnName = $label->column_name;
    //             $labelTitle = $label->label_title_url;

    //             if ($label->data_type === 'phone_number') {
    //                 $phoneNumber = str_replace(['(', ')', '_', '-', ' '], '', $lead->{$columnName} ?? '');
    //                 $placeholders["[[{$labelTitle}]]"] = $phoneNumber;
    //             } elseif ($label->data_type === 'date') {
    //                 $placeholders["[[{$labelTitle}]]"] = $this->formatDate($lead->{$columnName} ?? '', $columnName);
    //             } elseif ($label->data_type === 'select_state' || $label->column_name == 'state') {
    //                 $state_code = AreaCodeList::on("master")
    //                     ->where(function ($query) use ($lead, $columnName) {
    //                         $query->where('state_name', $lead->{$columnName} ?? '')
    //                               ->orWhere('state_code', $lead->{$columnName} ?? '');
    //                     })->value('state_code');
    //                 $placeholders["[[{$labelTitle}]]"] = $state_code ?? '';
    //             } else {
    //                 $placeholders["[[{$labelTitle}]]"] = $lead->{$columnName} ?? '';
    //             }
    //         }

    //         // Match placeholders in the template
    //         preg_match_all('/\[\[(.*?)\]\]|\[(.*?)\]/', $templateHtml, $matches);

    //         // Separate placeholders by type
    //         $doubleBracketPlaceholders = array_filter($matches[1]); // For [[last_name]]
    //         Log::info('Final Replaced doubleBracketPlaceholders', ['doubleBracketPlaceholders' => $doubleBracketPlaceholders]);

    //         $singleBracketPlaceholders = array_filter($matches[2]); // For [last_name]
    //         Log::info('Final Replaced singleBracketPlaceholders', ['singleBracketPlaceholders' => $singleBracketPlaceholders]);

    //         // Match double-bracket placeholders with labels
    //         foreach ($doubleBracketPlaceholders as $placeholder) {
    //             if (isset($placeholders["[[{$placeholder}]]"])) {
    //                 $templateHtml = str_replace("[[{$placeholder}]]", $placeholders["[[{$placeholder}]]"], $templateHtml);                    $templateHtml = str_replace("[[{$placeholder}]]", $placeholders["[[{$placeholder}]]"], $templateHtml);
    //                 $templateSubject = str_replace("[[{$placeholder}]]", $placeholders["[[{$placeholder}]]"], $templateSubject);

    //             }
    //             else {
    //                 // Replace unresolved placeholders with an empty string
    //                 $templateHtml = str_replace("[[{$placeholder}]]", '', $templateHtml);
    //                 $templateSubject = str_replace("[[{$placeholder}]]", '', $templateSubject);

    //             }
    //         }

    //         // Match single-bracket placeholders with the user table
    //         if (!empty($singleBracketPlaceholders)) {
    //             $userId = $lead->created_by ?? null;

    //             if ($userId) {
    //                 $user = User::find($userId);

    //                 if ($user) {
    //                     foreach ($singleBracketPlaceholders as $placeholder) {
    //                         $column = str_replace(['[', ']'], '', $placeholder);

    //                         if (isset($user->{$column})) {
    //                             $templateHtml = str_replace("[{$placeholder}]", $user->{$column}, $templateHtml);
    //                             $templateSubject = str_replace("[{$placeholder}]", $user->{$column}, $templateSubject);

    //                         } else {
    //                             $templateHtml = str_replace("[{$placeholder}]", '', $templateHtml);
    //                             $templateSubject = str_replace("[{$placeholder}]", '', $templateSubject);

    //                         }
    //                     }
    //                 } else {
    //                     foreach ($singleBracketPlaceholders as $placeholder) {
    //                         $templateHtml = str_replace("[{$placeholder}]", '', $templateHtml);
    //                         $templateSubject = str_replace("[{$placeholder}]", '', $templateSubject);

    //                     }
    //                     Log::error("User not found for user_id: $userId");
    //                 }
    //             } else {
    //                 Log::error("Invalid or missing user_id");
    //             }
    //         }

    //         // Log the final replaced template for debugging
    //         Log::info('Final Replaced Template', ['templateHtml' => $templateHtml]);


    //     // Log placeholders for debugging
    //     Log::info('Placeholders', ['placeholders' => $placeholders]);
    //     // If template placeholders use square brackets [key], adjust keys
    //     $adjustedPlaceholders = [];
    //     foreach ($placeholders as $key => $value) {
    //         $adjustedKey = str_replace(['{{', '}}'], ['[', ']'], $key); // Convert {{key}} to [key]
    //         $adjustedPlaceholders[$adjustedKey] = $value;
    //     }
    //     // Replace placeholders in the email subject and template
    //     $subject = $templateSubject ?? 'Default Subject';
    //     $finalSubject = str_replace(array_keys($adjustedPlaceholders), array_values($adjustedPlaceholders), $subject) 
    //                     . ' For Lead Id - ' . ($requestData['user']['lead_id'] ?? 'N/A');
    //                     // Remove square brackets from replaced values
    //     $finalSubject = preg_replace('/\[([^\]]+)\]/', '$1', $finalSubject);
    //     Log::info('Final Subject', ['finalSubject' => $finalSubject]);

    //     $templateHtml = $templateHtml ?? '';
    //     $finalTemplate = str_replace(array_keys($adjustedPlaceholders), array_values($adjustedPlaceholders), $templateHtml);
    //     // Remove square brackets from replaced values
    //     $finalTemplate = preg_replace('/\[([^\]]+)\]/', '$1', $finalTemplate);
    //     Log::info('Final Template', ['finalTemplate' => $finalTemplate]);

    //     // Construct the email message
    //     if ($requestData['user']['user_id'] == '0') {
    //         $message = "<b>{$finalTemplate}</b>";
    //     } else {
    //         $message = "{$name->first_name} {$name->last_name} - <b>{$finalTemplate}</b>";
    //     }
    //     Log::info('Final Email Message', ['message' => $message]);


    //         $data = array('subject'=>$finalSubject,'content'=>$message);
    //         Log::info('reached email template data',['data'=>$data]);

    //         $mailService = new CrmMailService($clientId, $mailable, $smtp_setting, $data);
    //         $to =  $finalEmail; //array('abhi4mca@gmail.com','mailme@rohitwanchoo.com');//env('SYSTEM_ADMIN_EMAIL'); //,'mailme@rohitwanchoo.com'
    //         Log::info('reached final email',['to'=>$to]);

    //         // $mailService->sendEmail($to);
    //     }


    // }
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

    /**
     * @OA\Post(
     *     path="/device/token",
     *     summary="Save or update device token for FCM",
     *     tags={"Notification"},
     *     security={{"Bearer": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="device_token", type="string", example="fcm_token_here"),
     *             @OA\Property(property="device_type", type="string", enum={"web", "android", "ios"}, example="web")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Token saved successfully")
     * )
     */
    public function saveDeviceToken(Request $request)
    {
        $this->validate($request, [
            'device_token' => 'required|string',
            'device_type' => 'nullable|in:web,android,ios'
        ]);

        try {
            $user = $request->user();
            $deviceType = $request->device_type ?? 'web';

            // We look up by (user_id, device_type). If found, we update that row's token.
            // If not found, we create a new row.
            // This allows User A and User B to have the same device_token if they use the same device.
            UserFcmToken::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'device_type' => $deviceType
                ],
                [
                    'device_token' => $request->device_token,
                    'last_used_at' => Carbon::now()
                ]
            );

            return $this->successResponse("Device token saved successfully", []);
        } catch (\Exception $e) {
            return $this->failResponse("Failed to save device token", [$e->getMessage()], $e);
        }
    }
}
