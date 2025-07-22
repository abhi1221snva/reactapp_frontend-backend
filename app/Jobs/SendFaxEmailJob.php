<?php

namespace App\Jobs;

use App\Mail\SystemNotificationMail;
use App\Model\Fax;
use App\Model\Dids;

use App\Model\Client\SmtpSetting;
use App\Model\Client\SystemNotification;
use App\Model\User;
use App\Model\Master\Client;
use App\Services\MailService;
use App\Services\ReportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use App\Services\SmsService;

class SendFaxEmailJob extends Job
{
    /**
        * The number of times the job may be attempted.
        *
        * @var int
        */
    private $dialednumber;

    private $clientId;

    private $data;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(int $clientId, int $dialednumber, array $data)
    {
        $this->clientId = $clientId;
        $this->dialednumber = $dialednumber;
        $this->data = $data;
    }

    /**
     * Execute the job.
     *
     */
    public function handle()
    {
        #prepare active for mail
        $subscription = SystemNotification::on("mysql_" . $this->clientId)->findOrFail("send_fax_email");
        //var_dump($subscription);die;
        if ($subscription->active == 0) {
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

        // Send mail to multiple users

        $userArray = $this->data["user_array"];

        #fetch and set email
        $dialednumber = $this->dialednumber;

        $client = Client::find($this->clientId);
        $this->data['logo'] = env('PORTAL_NAME').'logo/' . $client->logo;
        $this->data['company_name'] = $client->company_name;
        $sendAttachment = false;

        $file_path = '';

        if (isset($userArray)) {
            $sendAttachment = true;
            $file_path = base_path('/public/user_pdf/'.time().'.pdf');
            $local_storage_pdf = file_get_contents($this->data["faxurl"]);
            file_put_contents($file_path, $local_storage_pdf);

            foreach ($userArray as $key=>$val) {
                $userObj = User::find($val);
                $userEmail = $userObj->email;
                $userMobile = $userObj->country_code.$userObj->mobile;

                //$userEmail = 'panpod@gmail.com';
                $this->data['first_name'] = $userObj->first_name;
                $mailable = new SystemNotificationMail($from, "emails.receiptFaxMail", $this->data["action"], $this->data, $file_path);
                $mailService = new MailService($this->clientId, $mailable, $smtpSetting);
                $mailService->sendEmailWithAttachment($userEmail, $file_path);

                //sms
                $setting = config("otp.sms");
                #find sms and email service
                $smsService = new SmsService($setting["url"], $setting["key"], $setting["token"]);
                $message = "Hello ".$this->data['first_name']." You have received one Fax document on your Fax number ".$dialednumber." on ".env('PORTAL_NAME')."";

                $to = $userMobile;
                $response = $smsService->sendMessage($setting["from_number"],$to,$message);
                Log::debug("SendNotificationForReceieveFax.sendMessage.response", [$response]);
            }
        } else {
            $didObj = Dids::on("mysql_" . $this->clientId)->where("cli", "=", $dialednumber);
            $userObj = User::find($didObj->sms_email);
            $this->data['first_name'] = $userObj->first_name;
            $mailable = new SystemNotificationMail($from, "emails.sendFaxMail", $this->data["action"], $this->data);
            $mailService = new MailService($this->clientId, $mailable, $smtpSetting);
            $mailService->sendEmail($userObj->email);

            //sms
                $setting = config("otp.sms");
                #find sms and email service
                $smsService = new SmsService($setting["url"], $setting["key"], $setting["token"]);
                $message = "Hello ".$this->data['first_name']." Your Fax document has been successfully sent to ".$dialednumber." on ".env('PORTAL_NAME')."";
                $to = $userMobile;
                $response = $smsService->sendMessage($setting["from_number"],$to,$message);
                Log::debug("SendNotificationForSentFax.sendMessage.response", [$response]);

            }

        if (!empty($file_path)) {
            unlink($file_path);
        }
        $subscription->last_sent = Carbon::now();
        $subscription->save();
    }
}
