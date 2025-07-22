<?php

namespace App\Jobs;

use App\Mail\SystemNotificationMail;
use App\Model\Client\SmtpSetting;
use App\Model\Client\SystemNotification;
use App\Model\Master\IpWhiteList;
use App\Model\User;
use App\Services\MailService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use App\Services\SmsService;
use App\Model\Master\Client;
use Plivo\RestClient;


class SendIpWhitelistNotification extends Job
{
    private $clientId;

    private $serverIp;

    private $whitelistIp;

    private $subject;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(int $clientId, string $serverIp, string $whitelistIp, string $subject)
    {
        $this->clientId = $clientId;
        $this->serverIp = $serverIp;
        $this->whitelistIp = $whitelistIp;
        $this->subject = $subject;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        #prepare sender list
        $subscription = SystemNotification::on("mysql_" . $this->clientId)->findOrFail("ip_whitelist");
        if (empty($subscription->subscribers) or !$subscription->active) {
            return;
        }

        $emails = User::whereIn('id', $subscription->subscribers)->select('email','mobile','country_code','base_parent_id')->get()->all();
        if(empty($emails))
        {
            return;
        }


        #Event specific data
        $ipWhitelist = IpWhiteList::find(["server_ip" => $this->serverIp, "whitelist_ip"=>$this->whitelistIp]);
        $data = $ipWhitelist->toArray();

        #fetch user name
        $user = User::find($data["last_login_user"]);
        $data["user"] = $user ? $user->first_name . " " . $user->last_name: null;

        if ($data["updated_by"]) {
            $user = User::find($data["updated_by"]);
            $data["approvedBy"] = $user ? $user->first_name . " " . $user->last_name: "Auto Approved";
        }


        //code for  send notification by sms.
        if($subscription->active_sms == 1)
        {
            
            $setting = config("otp.sms");
            #find sms and email service
            $smsService = new SmsService($setting["url"], $setting["key"], $setting["token"]);
            foreach($emails as $key=> $value)
            {
                if($this->subject == 'IP whitelisting pending for approval')
                { 
                    $message = $data["user"]." (".$this->whitelistIp.") is pending for IP white listing. Please login on ".env('PORTAL_NAME')." to allow the user to register the phone from the IP address.";
                }
                else
                    {
                        $message = $data["user"]." (".$this->whitelistIp.") has been white listed on ".env('PORTAL_NAME')." successfully."
                    }
                $to = $value->country_code.$value->mobile;

                $client = Client::findOrFail($value->base_parent_id);

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
                Log::debug("SendNotificationForIpWhiteListing.sendMessage.response", [$response]);
            }
            
        }
        //close notification send by sms.

        if($subscription->active == 1)
        {

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
            #create initiate mailable class
            $mailable = new SystemNotificationMail($from, "emails.ipWhitelist", $this->subject, $data);

            $mailService = new MailService($this->clientId, $mailable, $smtpSetting);
            $mailService->sendEmail($emails);

            $subscription->last_sent = Carbon::now();
            $subscription->save();
        }
    }
}
