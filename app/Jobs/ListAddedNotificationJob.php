<?php

namespace App\Jobs;

use App\Mail\SystemNotificationMail;
use App\Model\Client\Campaign;
use App\Model\Client\SmtpSetting;
use App\Model\Client\SystemNotification;
use App\Model\User;
use App\Services\MailService;
use Illuminate\Support\Carbon;
use App\Services\SmsService;
use App\Services\FirebaseService;
use App\Model\UserFcmToken;
use Illuminate\Support\Facades\Log;
use App\Model\Master\Client;
use Plivo\RestClient;



class ListAddedNotificationJob extends Job
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
        $subscription = SystemNotification::on("mysql_" . $this->clientId)->findOrFail("list_add_delete");
        if (empty($subscription->subscribers)) {
            return;
        }

        $emails = User::whereIn('id', $subscription->subscribers)->select('id', 'email','mobile','country_code','base_parent_id')->get()->all();
        if(empty($emails))
        {
            return;
        }

        

        #fetch and set campaign title
        $campaign = Campaign::on("mysql_" . $this->clientId)->findOrFail($this->campaignId);
        $this->data["campaignTitle"] = $campaign->title;

        //code for  send notification by sms.
        if($subscription->active_sms == 1)
        {
            $setting = config("otp.sms");
            #find sms and email service
            $smsService = new SmsService($setting["url"], $setting["key"], $setting["token"]);
            foreach($emails as $key=> $value)
            {
                if($this->data['action'] == 'List added')
                {
                    $message = "List :".$this->data['listName']." has been created for campaign : ".$campaign->title." successfully on ".env('PORTAL_NAME').".";
                }
                else
                    if($this->data['action'] == 'List deleted')
                    {
                        $message = "List :".$this->data['listName']." has been deleted from campaign : ".$campaign->title." successfully on ".env('PORTAL_NAME').".";
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
                Log::debug("SendNotificationForAddDeleteList.sendMessage.response", [$response]);
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
            $mailable = new SystemNotificationMail($from, "emails.listActions", $this->data["action"], $this->data);

            $mailService = new MailService($this->clientId, $mailable, $smtpSetting);
            $mailService->sendEmail($emails);

            // Send FCM Push Notification
            try {
                $subscriberIds = array_column($emails, 'id');
                $fcmTokens = UserFcmToken::whereIn('user_id', $subscriberIds)
                    ->pluck('device_token')
                    ->toArray();
                
                if (!empty($fcmTokens)) {
                    $title = $this->data['action'] ?? 'List Notification';
                    $body = $message ?? "Update for list " . ($this->data['listName'] ?? 'Unknown');
                    
                    if (!isset($message)) {
                        if($this->data['action'] == 'List added') {
                            $body = "List: ".($this->data['listName'] ?? 'Unknown')." has been created for campaign: ".($campaign->title ?? 'Unknown').".";
                        } else if($this->data['action'] == 'List deleted') {
                            $body = "List: ".($this->data['listName'] ?? 'Unknown')." has been deleted from campaign: ".($campaign->title ?? 'Unknown').".";
                        }
                    }

                    FirebaseService::sendNotification(
                        $fcmTokens,
                        $title,
                        strip_tags($body),
                        [
                            'type' => 'list_notification',
                            'action' => $this->data['action'],
                            'campaign_id' => $this->campaignId,
                            'clientId' => $this->clientId
                        ]
                    );
                }
            } catch (\Exception $e) {
                Log::error('FCM List Notification failed', [
                    'error' => $e->getMessage(),
                    'clientId' => $this->clientId
                ]);
            }

            $subscription->last_sent = Carbon::now();
            $subscription->save();
        }
    }

}
