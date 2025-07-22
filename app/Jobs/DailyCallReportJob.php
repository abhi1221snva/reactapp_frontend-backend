<?php

namespace App\Jobs;

use App\Mail\GenericMail;
use App\Mail\SystemNotificationMail;
use App\Model\Client\ReportLog;
use App\Model\Client\SmtpSetting;
use App\Model\Client\SystemNotification;
use App\Model\User;
use App\Services\MailService;
use App\Services\ReportService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use App\Services\SmsService;
use App\Model\Master\Client;
use Plivo\RestClient;


class DailyCallReportJob extends Job
{
    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 1;
    public $timeout = 300;

    private $clientId;

    /**
     * DailyCallReportJob constructor.
     * @param $clientId
     */
    public function __construct($clientId)
    {
        $this->clientId = $clientId;
    }

    /**
     * Execute the job.
     *
     */
    public function handle()
    {
        Log::info("DailyCallReportJob.handle", [
            "clientId" => $this->clientId,
            "attempts" => $this->attempts()
        ]);

        $connection = 'mysql_' . $this->clientId;
        try {
            #prepare sender list
            $subscription = SystemNotification::on($connection)->findOrFail("daily_call_report");
            Log::info("DailyCallReportJob.handle", [
                "clientId" => $this->clientId,
                "subscription" => $subscription
            ]);
            if (empty($subscription->subscribers) or !$subscription->active) {
                return;
            }

            $emails = User::whereIn('id', $subscription->subscribers)->select('email','mobile','country_code','base_parent_id')->get()->all();
            Log::info("DailyCallReportJob.emails", [
                "clientId" => $this->clientId,
                "emails" => $emails
            ]);
            if(empty($emails))
            {
                return;
            }

            $system = SmtpSetting::on("mysql_" . $this->clientId)->where("sender_type", "=", 'system')->first();
            
            if(!empty($system))
            {
                $smtpSetting = SmtpSetting::getBySenderType("mysql_" . $this->clientId, "system");
                #determine from which email to send
                $from = [
                    "address" => empty($smtpSetting->from_email) ? env('DEFAULT_EMAIL') : $smtpSetting->from_email,
                    "name" => empty($smtpSetting->from_name) ? env('DEFAULT_NAME') : $smtpSetting->from_name,
                ];
            }
            else
            {
                $smtpSetting = new SmtpSetting;
                $smtpSetting->mail_driver = "SMTP";
                $smtpSetting->mail_host = env("PORTAL_MAIL_HOST");
                $smtpSetting->mail_port = env("PORTAL_MAIL_PORT");
                $smtpSetting->mail_username = env("PORTAL_MAIL_USERNAME");
                $smtpSetting->mail_password = env("PORTAL_MAIL_PASSWORD");
                $smtpSetting->from_name = env("PORTAL_MAIL_SENDER_NAME");
                $smtpSetting->from_email = env("PORTAL_MAIL_SENDER_EMAIL");
                $smtpSetting->mail_encryption = env("PORTAL_MAIL_ENCRYPTION");
                $from = [
                    "address" => empty($smtpSetting->from_email) ? env('DEFAULT_EMAIL') : $smtpSetting->from_email,
                    "name" => empty($smtpSetting->from_name) ? env('DEFAULT_NAME') : $smtpSetting->from_name,
                ];
            }
            $view = "emails.DailyCallReport.v1";

            $reportService = new ReportService($this->clientId);
            foreach ($emails as $email) {
                try {
                    /********** Start Preparing Data *******************/
                    $data = $reportService->dailyCallReport($email);
                    /********** End Preparing Data *******************/

                    Log::info("DailyCallReportJob.reportData", [
                        "clientId" => $this->clientId,
                        "email" => $email,
                        "data" => $data,
                        "smtpSetting" => $smtpSetting,
                        "view" => $view
                    ]);

                    $reportLog = new ReportLog([
                        'report_name' => "daily-call-report",
                        'report_date' => Carbon::now(),
                        'sent_to_email' => $email,
                        'data' => $data,
                        'view_file' => $view,
                        'source' => "DailyCallReportJob"
                    ]);
                    $reportLog->setConnection($connection);
                    $reportLog->saveOrFail();

                    //code for  send notification by sms.
                    if($subscription->active_sms == 1)
                    {
                        $setting = config("otp.sms");
                        #find sms and email service
                        $smsService = new SmsService($setting["url"], $setting["key"], $setting["token"]);
                        //$total_calls = $data['total_inbound_Calls'] + $data['total_inbound_Calls'] + $data['total_inbound_Calls']+ $data['total_outbound_Calls_dialer'];

                        $total_calls = $data['total_outbound_Calls'];


                        $message = "Total calls made :".$total_calls."  for ".$data['company_name']." on 
                        ".Carbon::now()->toFormattedDateString().". Login to ".env('PORTAL_NAME')." to 
                        see detailed call report.";
                        $to = $email->country_code.$email->mobile;

                        $client = Client::findOrFail($email->base_parent_id);

                Log::debug("SendNotificationForLowLeadClient.sendMessage.response", [$client]);

                if($client->sms_plateform == 'plivo')
                {
                    $data_array['from'] = env('PLIVO_SMS_NUMBER');
                    $data_array['to'] = $to;
                    $data_array['text'] = $message;

                    $plivo_user = env('PLIVO_USER');
                    $plivo_pass = env('PLIVO_PASS');

                    $client = new RestClient($plivo_user,$plivo_pass);
                    $response = $client->messages->create([ 
                        "src" => $data_array['from'],
                        "dst" => $data_array['to'],
                        "text"  =>$data_array['text'],
                        "url"=>""
                    ]);
                }

                else
                {
                        $response = $smsService->sendMessage($setting["from_number"],$to,$message);
                    }
                        Log::debug("SendNotificationForDailyCallReport.sendMessage.response",[$response]);
                    }
                    //close notification send by sms.

                    if($subscription->active == 1)
                    {

                        #create initiate mailable class
                        $mailable = new SystemNotificationMail($from, $view, "Daily Call Report", $data);

                        $mailService = new MailService($this->clientId, $mailable, $smtpSetting);
                        $mailService->sendEmail($email);

                        $subscription->last_sent = Carbon::now();
                        $subscription->save();
                    }
                    
                } catch (\Throwable $throwable) {
                    $context = buildContext($throwable, [
                        "clientId" => $this->clientId,
                        "email" => $email
                    ]);
                    Log::error("DailyCallReportJob.email.error($email)", $context);
                    $this->emailError("DailyCallReportJob.email.error($email)", $context);
                }
            }
        } catch (\Throwable $throwable) {
            $context = buildContext($throwable, [
                "clientId" => $this->clientId
            ]);
            Log::error("DailyCallReportJob.handle.error", $context);
            $this->emailError("DailyCallReportJob.handle.error", $context);
        }
    }

    private function emailError($subject, array $context)
    {
        $emailBody = view('emails.errorNotification', compact('context'))->render();
        $genericMail = new GenericMail(
            $subject,
            [
                "address" => env('DEFAULT_EMAIL'),
                "name" => "DailyCallReportJob"
            ],
            $emailBody
        );
        $errorEmailSetting = new SmtpSetting([
            "mail_driver" => "SMTP",
            "mail_host" => env("ERROR_MAIL_HOST"),
            "mail_port" => env("ERROR_MAIL_PORT"),
            "mail_username" => env("ERROR_MAIL_USERNAME"),
            "mail_password" => env("ERROR_MAIL_PASSWORD"),
            "mail_encryption" => env("ERROR_MAIL_ENCRYPTION"),
            "sender_type" => "system"
        ]);
        $mailService = new MailService($this->clientId, $genericMail, $errorEmailSetting);
        $mailService->sendEmail(["abhi2112mca@gmail.com", "mailme@rohitwanchoo.com"]);
    }
}
