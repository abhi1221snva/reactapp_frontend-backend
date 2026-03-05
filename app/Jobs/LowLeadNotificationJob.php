<?php

namespace App\Jobs;

use App\Mail\SystemNotificationMail;
use App\Model\Client\Campaign;
use App\Model\Client\SmtpSetting;
use App\Model\Client\SystemNotification;
use App\Model\Lists;
use App\Model\User;
use App\Services\MailService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use App\Services\SmsService;
use App\Services\FirebaseService;
use App\Model\UserFcmToken;
use App\Model\Master\Client;
use Plivo\RestClient;




class LowLeadNotificationJob extends Job
{
    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    private $campaignId;

    private $clientId;

    private $data;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(int $clientId, int $campaignId, array $data)
    {
        $this->clientId = $clientId;
        $this->campaignId = $campaignId;
        $this->data = $data;
    }

    /**
     * Execute the job.
     *
     */
    public function handle()
    {
        #prepare sender list
        $subscription = SystemNotification::on("mysql_" . $this->clientId)->findOrFail("campaign_low_lead");
        if (empty($subscription->subscribers) or !$subscription->active) {
            return;
        }

        $emails = User::whereIn('id', $subscription->subscribers)->select('id', 'email','mobile','country_code','base_parent_id')->get()->all();
        if(empty($emails))
        {
            return;
        }

       

        #fetch and set campaign title
        $campaign = Campaign::on("mysql_" . $this->clientId)->where('is_deleted','0')->findOrFail($this->campaignId);
        $this->data["campaignTitle"] = $campaign->title;

        #get current hopper count for the campaign
        #$leadCount = LeadTemp::on("mysql_" . $this->clientId)->where("campaign_id","=", $this->campaignId)->count();
        #$this->data["hopperCount"] = $leadCount;

        #fetch and set list title
        foreach ($this->data["lists"] as $listId => $listData) {
            $list = Lists::on("mysql_" . $this->clientId)->find(intval($listId));
            if ($list) {
                $this->data["lists"][$listId]["title"] = $list->title;
            } else {
                $this->data["lists"][$listId]["title"] = "List Id $listId";
                Log::error("LowLeadNotificationJob.handle(): List $listId not found", $this->data["lists"]);
            }
        }

        //code for  send notification by sms.
        if($subscription->active_sms == 1)
        {
            $setting = config("otp.sms");
            #find sms and email service
            $smsService = new SmsService($setting["url"], $setting["key"], $setting["token"]);
            foreach($emails as $key=> $value)
            {
               
                $message = "You are running low leads on campaign: ".$campaign->title." on ".env('PORTAL_NAME').".";
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
                
                Log::debug("SendNotificationForLowLead.sendMessage.response", [$response]);
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
            $mailable = new SystemNotificationMail($from, "emails.lowleads", "Low leads in campaign", $this->data);

            $mailService = new MailService($this->clientId, $mailable, $smtpSetting);
            $mailService->sendEmail($emails);

            // Send FCM Push Notification
            try {
                $subscriberIds = array_column($emails, 'id');
                $fcmTokens = UserFcmToken::whereIn('user_id', $subscriberIds)
                    ->pluck('device_token')
                    ->toArray();
                
                if (!empty($fcmTokens)) {
                    $title = "Low Lead Alert";
                    $body = "You are running low leads on campaign: " . ($campaign->title ?? 'Unknown');
                    
                    FirebaseService::sendNotification(
                        $fcmTokens,
                        $title,
                        $body,
                        [
                            'type' => 'low_lead_notification',
                            'campaign_id' => $this->campaignId,
                            'clientId' => $this->clientId
                        ],
                        true // High priority for critical lead alerts
                    );
                }
            } catch (\Exception $e) {
                Log::error('FCM Low Lead Notification failed', [
                    'error' => $e->getMessage(),
                    'clientId' => $this->clientId
                ]);
            }

            $subscription->last_sent = Carbon::now();
            $subscription->save();
        }
    }

}
