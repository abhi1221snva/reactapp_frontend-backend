<?php

namespace App\Jobs;

use App\Mail\SystemNotificationMail;
use App\Model\Client\Campaign;
use App\Model\Client\ExtensionGroup;
use App\Model\Client\ExtensionGroupMap;
use App\Model\Client\SmtpSetting;
use App\Model\Client\SystemNotification;
use App\Model\Master\AsteriskServer;
use App\Model\Master\Client;
use App\Model\User;
use App\Services\MailService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use App\Services\SmsService;
use App\Services\FirebaseService;
use App\Model\UserFcmToken;
use Plivo\RestClient;



class ExtensionNotificationJob extends Job
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
    public function __construct(int $clientId, array $data)
    {
        $this->clientId = $clientId;
        $this->data = $data;
        Log::info("ExtensionNotificationJob($clientId)", $data);
    }

    /**
     * Execute the job.
     *
     */
    public function handle()
    {
        #prepare sender list
        $subscription = SystemNotification::on("mysql_" . $this->clientId)->findOrFail("extension_add_delete");
        if (empty($subscription->subscribers)) {
            return;
        }

        #fetch user required data.
        $user = User::findOrFail($this->data["user"]["id"]);
        $this->data["userInfo"] = $user->toArray();
        $connection = "mysql_".$user->parent_id;

        $asteriskServer = AsteriskServer::on("master")->findOrFail($user->asterisk_server_id);

        if(empty($asteriskServer->location) && $asteriskServer->location == NULL )
        {
            $location='';
        }
        else
        {
            $location = '-'.$asteriskServer->location;
        }

        $this->data["userInfo"]["asteriskServer"] = $asteriskServer->domain.$location;
        $this->data["userInfo"]["followMe"] = $user->follow_me == 1?"Yes":"No";
        $this->data["userInfo"]["callForward"] = $user->call_forward == 1?"Yes":"No";
        $this->data["userInfo"]["voicemail"] = $user->voicemail == 1?"Yes":"No";
        $this->data["userInfo"]["voicemailSendToEmail"] = $user->voicemail_send_to_email == 1?"Yes":"No";
        $this->data["userInfo"]["twinning"] = $user->voicemail_send_to_email == 1?"Yes":"No";
        $this->data["userInfo"]["twinning"] = $user->voicemail_send_to_email == 1?"Yes":"No";

        $emails = User::whereIn('id', $subscription->subscribers)->select('email','mobile','country_code','base_parent_id')->get()->all();

        if(empty($emails))
        {
            return;
        }

        //code for  send notification by sms.
        if($subscription->active_sms == 1)
        {

            $setting = config("otp.sms");
            #find sms and email service
            $smsService = new SmsService($setting["url"], $setting["key"], $setting["token"]);
            foreach($emails as $key=> $value)
            {
                if($this->data['action'] == 'Extension added')
                {
                    $message = "Extension ".$user->extension."@".$asteriskServer->domain." has been created successfully on ".env('PORTAL_NAME').".";
                }
                else
                    if($this->data['action'] == 'Extension deleted')
                    {
                        $message = "Extension ".$user->extension."@".$asteriskServer->domain." has been deleted successfully on ".env('PORTAL_NAME').".";
                    }
                $to = $value->country_code.$value->mobile;

                $client = Client::findOrFail($value->base_parent_id);

                Log::debug("SendNotificationForAddDeleteExtensionClient.sendMessage.response", [$client]);

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

                Log::debug("SendNotificationForAddDeleteExtension.sendMessage.response", [$response]);
            }
        }
        //close notification send by sms.
        

        $groups = "";
        $groupMaps = ExtensionGroupMap::on($connection)->where("extension","=", $user->extension)->get()->all();
        foreach ($groupMaps as $map) {
            $extensionGroup = ExtensionGroup::on($connection)->find($map->group_id);
            if ($extensionGroup) {
                $groups .= $extensionGroup->title.", ";
            }
        }
        if (strlen($groups)) $groups = substr($groups, 0, -2);
        $this->data["userInfo"]["groups"] = $groups;
        $this->data["userInfo"]["cliSetting"] = $user->cli_setting == 0?"Default":"Custom";

        if($subscription->active == 1)
        {
            $system = SmtpSetting::on("mysql_" . $this->clientId)->where("sender_type", "=", 'system')->first();
            //echo "<pre>";print_r($system);die;
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

            //  echo "<pre>";print_r($smtpSetting);die;
            #create initiate mailable class
            $mailable = new SystemNotificationMail($from, "emails.extenstionActions", $this->data["action"], $this->data);

            $mailService = new MailService($this->clientId, $mailable, $smtpSetting);
            $mailService->sendEmail($emails);

            // Send FCM Push Notification
            try {
                $subscriberIds = array_column($emails, 'id');
                if (empty($subscriberIds)) {
                     $subscriberIds = $subscription->subscribers;
                }
                
                $fcmTokens = UserFcmToken::whereIn('user_id', $subscriberIds)
                    ->pluck('device_token')
                    ->toArray();
                
                if (!empty($fcmTokens)) {
                    $title = $this->data['action'] ?? 'Extension Notification';
                    $body = $message ?? "Update for extension " . $user->extension; // $message might be defined in SMS block
                    
                    if (!isset($message)) {
                         if($this->data['action'] == 'Extension added') {
                            $body = "Extension ".$user->extension."@".$asteriskServer->domain." has been created successfully.";
                        } else if($this->data['action'] == 'Extension deleted') {
                            $body = "Extension ".$user->extension."@".$asteriskServer->domain." has been deleted successfully.";
                        }
                    }

                    FirebaseService::sendNotification(
                        $fcmTokens,
                        $title,
                        strip_tags($body),
                        [
                            'type' => 'extension_notification',
                            'action' => $this->data['action'],
                            'extension' => $user->extension,
                            'clientId' => $this->clientId
                        ]
                    );
                }
            } catch (\Exception $e) {
                Log::error('FCM Extension Notification failed', [
                    'error' => $e->getMessage(),
                    'clientId' => $this->clientId
                ]);
            }

            $subscription->last_sent = Carbon::now();
            $subscription->save();
        }
    }

}