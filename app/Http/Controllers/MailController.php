<?php

namespace App\Http\Controllers;

use App\Mail\GenericMail;
use App\Model\Client\emailLog;
use App\Model\Client\SmtpSetting;
use App\Model\Client\EmailSetting;
use App\Model\Client\Lender;
use App\Model\Client\CrmSendLeadToLender;
use App\Model\Client\LenderStatus;
use App\Model\Client\wallet;
use App\Model\Client\Lead;
use App\Model\Cron;
use App\Model\User;
use App\Services\MailService;
use App\Services\CrmMailService;
use App\Jobs\SendCrmNotificationEmail;
use App\Jobs\SendLeadByLenderApi;

use App\Http\Controllers\NotificationController;


use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class MailController extends Controller
{

    /**
     * @OA\Post(
     *     path="/send-email/generic",
     *     summary="Send a generic email using selected sender type",
     *     tags={"Mail"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"senderType", "subject", "body", "to"},
     *             @OA\Property(property="senderType", type="string", enum={"system", "campaign", "user"}, example="system"),
     *             @OA\Property(property="user_id", type="integer", example=12, description="Required if senderType is 'user'"),
     *             @OA\Property(property="campaign_id", type="integer", example=34, description="Required if senderType is 'campaign'"),
     *             @OA\Property(property="subject", type="string", example="Welcome Email"),
     *             @OA\Property(property="body", type="string", example="<p>Hello, this is a test email</p>"),
     *             @OA\Property(property="to", type="string", format="email", example="receiver@example.com")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Email sent successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true)
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="The given data was invalid.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Internal server error")
     *         )
     *     )
     * )
     */

    public function sendGenericEmail(Request $request)
    {
        $this->validate($request, [
            'senderType' => 'required|in:system,campaign,user',
            'user_id' => 'required_if:senderType,user|nullable|numeric',
            'campaign_id' => 'required_if:senderType,campaign|nullable|numeric',
            'subject' => 'required|string',
            'body' => 'required|string',
            'to' => 'required|email'
        ]);
        try {
            $currencyCode = $clientPackageId = 0;

            $senderType = $request->input("senderType");
            $senderTypeId = null;
            if ($senderType === "user") {
                $senderTypeId = $request->input("user_id");
                $from = [
                    "address" => $request->auth->email,
                    "name" => $request->auth->first_name . " " . $request->auth->last_name
                ];
            }
            if ($senderType === "campaign") $senderTypeId = $request->input("campaign_id");
            $smtpSetting = SmtpSetting::getBySenderType("mysql_" . $request->auth->parent_id, $senderType, $senderTypeId);
            if ($senderType === "campaign" || $senderType === "system") {
                $from = [
                    "address" => $smtpSetting->from_email,
                    "name" => $smtpSetting->from_name
                ];
            }

            $genericMail = new GenericMail(
                $request->input("subject"),
                $from,
                $request->input("body")
            );
            $cc  = $request->input('cc', []);
            $bcc = $request->input('bcc', []);
            //send email
            $mailService = new MailService($request->auth->parent_id, $genericMail, $smtpSetting);
            $mailService->sendEmail($request->input("to"),$cc,$bcc);

            //Billing part for Email from "Start Dialing"
            $isFree = $intCharge = NULL;

            $user = new User();
            $user->id = $request->auth->id;
            $user->parent_id = $request->auth->parent_id;
            $package = $user->getAssignedUserPackage(true);

            if (empty($package)) {
                //No charge for Admin
                $isFree = 1;
                $intCharge = 0;
            } else {
                //Calculate email charges
                if ($package->free_emails > 0) {
                    $isFree = 1;
                    $intCharge = 0;

                    //Deduct free balance
                    DB::connection('mysql_' . $request->auth->parent_id)->table('user_packages')->where('id', $package->user_package_id)->decrement('free_emails', 1);
                } else {
                    $intCharge = $package->rate_per_email;
                    $isFree = 0;

                    //Deduct amount from client_xxx.wallet
                    wallet::debitCharge($intCharge, $request->auth->parent_id, $package->currency_code);
                }

                $currencyCode = $package->currency_code;
                $clientPackageId = $package->id;
            }
            $draftId = $request->input('draft_id');

            if ($draftId) {
                EmailLog::on("mysql_" . $request->auth->parent_id)
                    ->where('id', $draftId)
                    ->where('folder', 'draft')
                    ->where('user_id', $request->auth->id)
                    ->delete();
            }


            //entry into client_xxx.email_logs
            $emailLog = new EmailLog();
            $emailLog->setConnection("mysql_" . $request->auth->parent_id);
            $emailLog->senderType = $senderType;
            $emailLog->user_id = $request->auth->id;
            $emailLog->campaign_id = $request->input("campaign_id");
            $emailLog->from = $smtpSetting->from_email;
            $emailLog->to = $request->input("to");
            $emailLog->subject = $request->input("subject");
            $emailLog->body = $request->input("body");
            $emailLog->charge = $intCharge;
            $emailLog->client_package_id = $clientPackageId;
            $emailLog->isFree = $isFree;
            $emailLog->currency_code = $currencyCode;
            $emailLog->cc  = json_encode($cc);
            $emailLog->bcc = json_encode($bcc);

            $emailLog->saveOrFail();
            $recipients = array_merge(
                [$request->input('to')],
                $cc
            );


            // $receiverUser = User::where('email', $request->input('to'))
            //     ->where('parent_id', $request->auth->parent_id)
            //     ->first();
            foreach ($recipients as $email) {
        $receiverUser = User::where('email', $email)
        ->where('parent_id', $request->auth->parent_id)
        ->first();

    if (!$receiverUser) {
        continue;
    }

    $emailLogInbox = new EmailLog();
    $emailLogInbox->setConnection("mysql_" . $request->auth->parent_id);
    $emailLogInbox->senderType = $senderType;
    $emailLogInbox->user_id = $receiverUser->id;
    $emailLogInbox->campaign_id = $request->input("campaign_id");
    $emailLogInbox->from = $smtpSetting->from_email;
    $emailLogInbox->to = $email;
    $emailLogInbox->subject = $request->input("subject");
    $emailLogInbox->body = $request->input("body");
    $emailLogInbox->cc = json_encode($cc);
    $emailLogInbox->bcc = null; // ❌ never show BCC
    $emailLogInbox->charge = 0;
    $emailLogInbox->isFree = 1;
    $emailLogInbox->folder = 'inbox';
    $emailLogInbox->saveOrFail();
}


            return response()->json(["success" => true]);
        } catch (\Throwable $exception) {
            Log::error("MailController.sendMail.error", [
                "message" => $exception->getMessage(),
                "file" => $exception->getFile(),
                "line" => $exception->getLine()
            ]);
            return response()->json([
                "success" => false,
                "message" => $exception->getMessage()
            ], 500); // <-- explicitly set status code

        }
    }

    /**
     * @OA\Post(
     *     path="/send-email/generics",
     *     summary="Send a generic CRM email",
     *     tags={"Mail"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="senderType", type="string", enum={"system", "campaign", "user"}, example="system"),
     *             @OA\Property(property="user_id", type="integer", example=2),
     *             @OA\Property(property="campaign_id", type="integer", example=1),
     *             @OA\Property(property="to", type="string", format="email", example="john@example.com"),
     *             @OA\Property(property="subject", type="string", example="Welcome Email"),
     *             @OA\Property(property="body", type="string", example="<p>Hi John, welcome!</p>")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Email Send Successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Email Send Successfully")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Email Sending Failed",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="SMTP error or billing issue")
     *         )
     *     )
     * )
     */

    public function sendGenericEmailCRM(Request $request)
    {
        $this->validate($request, [
            'senderType' => 'required|in:system,campaign,user',
            //'user_id' => 'required_if:senderType,user|nullable|numeric',
            'campaign_id' => 'required_if:senderType,campaign|nullable|numeric',
            'subject' => 'required|string',
            'body' => 'required|string',
            'to' => 'required|email'
        ]);
        try {
            $currencyCode = $clientPackageId = 0;

            $senderType = $request->input("senderType");
            $senderTypeId = null;
            if ($senderType === "user") {
                $senderTypeId = $request->input("user_id");
                $from = [
                    "address" => $request->auth->email,
                    "name" => $request->auth->first_name . " " . $request->auth->last_name
                ];
            }
            if ($senderType === "campaign") $senderTypeId = $request->input("campaign_id");
            $smtpSetting = SmtpSetting::getBySenderType("mysql_" . $request->auth->parent_id, $senderType, $senderTypeId);
            if ($senderType === "campaign" || $senderType === "system") {
                $from = [
                    "address" => $smtpSetting->from_email,
                    "name" => $smtpSetting->from_name
                ];
            }

            $genericMail = new GenericMail(
                $request->input("subject"),
                $from,
                $request->input("body")
            );

            //send email
            $mailService = new MailService($request->auth->parent_id, $genericMail, $smtpSetting);
            $mailService->sendEmail($request->input("to"));

            //Billing part for Email from "Start Dialing"
            $isFree = $intCharge = NULL;

            $user = new User();
            $user->id = $request->auth->id;
            $user->parent_id = $request->auth->parent_id;
            $package = $user->getAssignedUserPackage(true);

            if (empty($package)) {
                //No charge for Admin
                $isFree = 1;
                $intCharge = 0;
            } else {
                //Calculate email charges
                if ($package->free_emails > 0) {
                    $isFree = 1;
                    $intCharge = 0;

                    //Deduct free balance
                    DB::connection('mysql_' . $request->auth->parent_id)->table('user_packages')->where('id', $package->user_package_id)->decrement('free_emails', 1);
                } else {
                    $intCharge = $package->rate_per_email;
                    $isFree = 0;

                    //Deduct amount from client_xxx.wallet
                    wallet::debitCharge($intCharge, $request->auth->parent_id, $package->currency_code);
                }

                $currencyCode = $package->currency_code;
                $clientPackageId = $package->id;
            }

            //entry into client_xxx.email_logs
            $emailLog = new EmailLog();
            $emailLog->setConnection("mysql_" . $request->auth->parent_id);
            $emailLog->senderType = $senderType;
            $emailLog->user_id = $request->auth->id;
            $emailLog->campaign_id = $request->input("campaign_id");
            $emailLog->from = $smtpSetting->from_email;
            $emailLog->to = $request->input("to");
            $emailLog->subject = $request->input("subject");
            $emailLog->body = $request->input("body");
            $emailLog->charge = $intCharge;
            $emailLog->client_package_id = $clientPackageId;
            $emailLog->isFree = $isFree;
            $emailLog->currency_code = $currencyCode;
            $emailLog->saveOrFail();

            // return response()->json(["success" => true]);

            return response()->json([
                "success" => true,
                "message" => "Email Send Successfully"
            ]);
        } catch (\Throwable $exception) {
            Log::error("MailController.sendMail.error", [
                "message" => $exception->getMessage(),
                "file" => $exception->getFile(),
                "line" => $exception->getLine()
            ]);
            return response()->json([
                "success" => false,
                "message" => $exception->getMessage()
            ]);
        }
    }

    /**
     * @OA\Post(
     *     path="/send-email/test-low-lead",
     *     summary="Test sending low lead notification email",
     *     tags={"Mail"},
     *     security={{"Bearer":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Simulated low lead response",
     *         @OA\JsonContent(
     *             @OA\Property(property="min_lead_temp", type="integer", example=100),
     *             @OA\Property(property="max_lead_temp", type="integer", example=500),
     *             @OA\Property(property="hopperCount", type="object",
     *                 @OA\Property(property="valid", type="integer", example=96),
     *                 @OA\Property(property="invalid", type="integer", example=0)
     *             ),
     *             @OA\Property(property="limit", type="integer", example=100),
     *             @OA\Property(property="added", type="integer", example=10),
     *             @OA\Property(property="lists", type="object",
     *                 @OA\Property(property="67", type="object",
     *                     @OA\Property(property="records", type="integer", example=300),
     *                     @OA\Property(property="valid", type="integer", example=5),
     *                     @OA\Property(property="duplicates", type="integer", example=5)
     *                 )
     *             )
     *         )
     *     )
     * )
     */

    public function testLowLead()
    {
        $response = [
            "min_lead_temp" => 100,
            "max_lead_temp" => 500,
            "hopperCount" => [
                "valid" => 96,
                "invalid" => 0
            ],
            "limit" => 100,
            "added" => 10,
            "lists" => [
                "67" => [
                    "records" => 300,
                    "valid" => 5,
                    "duplicates" => 5
                ],
                "68" => [
                    "records" => 200,
                    "valid" => 5,
                    "duplicates" => 5
                ],
            ]
        ];

        $cron = new Cron();
        $sent = $cron->sendLowLeadEmail("testLowLead", 1, 4, $response);
        return response()->json([$response]);
    }

    public function sendEmailGenericCRM(Request $request) //online application send
    {

        $this->validate(
            $request,
            [
                'toEmailId' => 'required',
                'subject' => 'required|string|max:255',
                'editor1' => 'required',

            ]
        );

        try {
            $clientId = $request->auth->parent_id;
            //$smtp_setting = EmailSetting::on("mysql_$clientId")->where('mail_type','online application')->first();      
            //$mailable ="emails.send_email_generic_crm";
            //$to = $request->toEmailId;
            //$data = array('subject'=>$request->subject,'content'=>$request->editor1);

            //$mailService = new CrmMailService($request->auth->parent_id, $mailable, $smtp_setting, $data);
            // $mailService->sendEmail($to);


            $messageData = array(
                "subject" => $request->subject,
                "message" => $request->editor1,
                'to' => $request->toEmailId,
                'mailable' => "emails.send_email_generic_crm",
                'user_id' => $request->auth->id

            );

            //  $data = array('request' => $request);

            $notificationData = [
                "action" => "online_application",
                "user" => $messageData
            ];



            //dispatch(new SendCrmNotificationEmail($clientId, $notificationData, 'online application'))->onConnection("database");

            $notificationController = new NotificationController();
            $notificationController->sendCrmNotification($clientId, $notificationData, 'online application');


            return response()->json(["success" => true]);
        } catch (\Throwable $exception) {
            Log::error(
                "MailController.sendMail.error",
                [
                    "message" => $exception->getMessage(),
                    "file" => $exception->getFile(),
                    "line" => $exception->getLine()
                ]
            );
            return response()->json(
                [
                    "success" => false,
                    "message" => $exception->getMessage()
                ]
            );
        }
    }






    public function sendEmailGenericAttachment(Request $request)
    {
        $this->validate($request, [
            'toEmailId' => 'required|array',
            'subject' => 'required|string|max:255',
            'editor1' => 'required',
        ]);

        $clientId = $request->auth->parent_id;

        try {
            $file_paths = [];
            // Ensure $fileName is an array
            $fileNames = is_array($request->fileName) ? $request->fileName : [$request->fileName];
            foreach ($fileNames as $name) {
                if ($name) {
                    $file_paths[] = '/var/www/html/branch/frontend_beta/public/uploads/' . $name;
                    // $file_paths[] = "C:\\Users\\shikh\\Downloads\\signed_application_1720696123 (2) (1).pdf";
                    //  $filePath = base_path('public/upload/' . $name);
                    // $file_paths[] = $filePath;
                }
            }
            $editorContent = $request->input('editor1');
            Log::info("Base64 Image Data: ", ['data' => $request->input('editor1')]);

            // Regex to find base64 images
            $pattern = '/<img src="data:image\/(.*?);base64,(.*?)"/';
            preg_match_all($pattern, $editorContent, $matches);

            foreach ($matches[0] as $index => $imgTag) {
                $extension = $matches[1][$index];
                $base64Data = $matches[2][$index];
                $fileName = 'image_' . time() . '.' . $extension;
                //  $filePath = base_path('public/upload/' . $fileName);
                // $filePath  ="C:\Users\shikh\Downloads\signed_application_1732268450.pdf";            // Decode and save the file
                $file_paths = '/var/www/html/branch/frontend_beta/public/uploads/' . $fileName;

                file_put_contents($filePath, base64_decode($base64Data));

                // Add to file paths array
                $file_paths[] = $filePath;
            }
            Log::info('Base64 images processed', ['file_paths' => $file_paths]);

            Log::info('All files processed', ['file_paths' => $file_paths]);

            $toEmailId = $request->toEmailId;
            $emailLenders = Lender::on("mysql_$clientId")->whereIn('id', $toEmailId)->get()->all();
            $emails = [];
            $emailSet = [];
            $ccEmails = [];
            $lenderApiStatus = [];
            $lenderName = [];


            if (!empty($emailLenders)) {
                foreach ($emailLenders as $key => $email) {
                    // Initialize email set for the current lender
                    if (!empty($email->email)) {
                        $emailSet[$email->id]['to'] = $email->email;
                    }
                    if (!empty($email->secondary_email)) {
                        $emailSet[$email->id]['cc1'] = $email->secondary_email;
                    }
                    if (!empty($email->secondary_email2)) {
                        $emailSet[$email->id]['cc2'] = $email->secondary_email2;
                    }
                    if (!empty($email->secondary_email3)) {
                        $emailSet[$email->id]['cc3'] = $email->secondary_email3;
                    }
                    if (!empty($email->secondary_email4)) {
                        $emailSet[$email->id]['cc4'] = $email->secondary_email4;
                    }
                    if ($email->api_status == '1') {
                        $lenderApiStatus[$key]['lender_id'] = $email->id;
                    }

                    if ($email->api_status == '1') {
                        $lenderName[$key]['lender_name'] = $email->lender_name;
                    }
                }
            }
            /* else {
            $emailSet[] = $toEmailId;
        }*/

            /* return response()->json([
            "success" => false,
            "message" => $emailSet,
        ]);*/



            //check lender_api

            if (!empty($lenderApiStatus)) {
                $apiStatusData = array(
                    "lender_id" => $lenderApiStatus,
                    "lender_name" => $lenderName,
                    "lead_id" => $request->lead_id,
                    "user_id" => $request->auth->id
                );

                //echo "<pre>";print_r($apiStatusData);die;

                //dispatch(new SendLeadByLenderApi($clientId, $apiStatusData, 'lender_api'))->onConnection("database");
                dispatch(new SendLeadByLenderApi($clientId, $apiStatusData, 'lender_api'))->onConnection("lender_api_schedule_job");


                /*   return response()->json([
        "success" => true,
        "message" => "Application sent.Please check submission log",
    ]);*/
            }



            // Remove duplicate emails
            //$emails = array_unique($emailSet);
            //$ccEmails=array_unique($ccEmails);
            $file_name = $request->fileName;
            $sendAttachment = true;
            $messageData = [
                "subject" => $request->subject,
                "message" => $request->editor1,
                'file_path' => $file_name,
                'file_paths' => $file_paths,
                'emails' => $emailSet,
                // 'ccEmails'=>$ccEmails,
                'mailable' => "emails.send_email_generic_crm",
                'user_id' => $request->auth->id
            ];

            /*return response()->json([
            "success" => false,
            "message" => $messageData,
        ]);*/
            Log::info("Before notification: ", ['messageData' => $messageData]);
            $notificationData = [
                "action" => "lenders_submission",
                "user" => $messageData
            ];
            Log::info('Notification data prepared', ['notificationData' => $notificationData]);
            ///dispatch(new SendCrmNotificationEmail($clientId, $notificationData, 'submission'))->onConnection("database");

            $notificationController = new NotificationController();
            $notificationController->sendCrmNotification($clientId, $notificationData, 'submission');

            $leadId = $request->lead_id;
            $submittedDate = Carbon::now();
            foreach ($toEmailId as $lenderId) {
                CrmSendLeadToLender::on("mysql_$clientId")->create([
                    'lender_id' => $lenderId,
                    'lead_id' => $leadId,
                    'submitted_date' => $submittedDate,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
            }
        } catch (\Throwable $exception) {
            Log::error("MailController.sendMail.error", [
                "message" => $exception->getMessage(),
                "file" => $exception->getFile(),
                "line" => $exception->getLine()
            ]);

            return response()->json([
                "success" => false,
                "message" => $exception->getMessage(),
            ]);
        }

        return response()->json([
            "success" => true,
            "message" => "Emails sent successfully",
        ]);
    }
}
