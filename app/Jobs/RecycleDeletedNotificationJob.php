<?php

namespace App\Jobs;

use App\Mail\SystemNotificationMail;
use App\Model\Client\Campaign;
use App\Model\Client\Disposition;
use App\Model\Client\Lists;
use App\Model\Client\SmtpSetting;
use App\Model\Client\SystemNotification;
use App\Model\User;
use App\Services\MailService;
use Illuminate\Support\Carbon;
use App\Services\SmsService;
use Illuminate\Support\Facades\Log;
use App\Model\Master\Client;
use Plivo\RestClient;


class RecycleDeletedNotificationJob extends Job
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
        $subscription = SystemNotification::on("mysql_" . $this->clientId)->findOrFail("recycle_delete");
        if (empty($subscription->subscribers) or !$subscription->active) {
            return;
        }

        $emails = User::whereIn('id', $subscription->subscribers)->select('email','mobile','country_code','base_parent_id')->get()->all();
        if(empty($emails))
        {
            return;
        }

        #fetch and set campaign,list and disposition title
        $campaign = Campaign::on("mysql_" . $this->clientId)->findOrFail($this->campaignId);
        $this->data["campaignTitle"] = $campaign->title;

        $list = Lists::on("mysql_" . $this->clientId)->findOrFail($this->data["listId"]);
        $this->data["listName"] = $list->title;

        foreach($this->data["disposition_count"] as $key =>$value){
            if($key == 0){
                $this->data['disposition_zero_title'] = 'Not Dialed';
                $this->data['disposition_zero_value'] = $value;
            }
            else{
            $key_data[] =$key;
            $value_data[] = $value;
        }
        }

        //echo "<pre>";print_r($value_data);


        $disposition = Disposition::whereIn('id', $key_data)->select('title')->pluck('title')->all();
        //echo "<pre>";print_r($disposition);die;

        $this->data["disposition_title"] = $disposition;

        foreach($disposition as $key => $dispo){
            if(!empty($this->data['disposition_zero_title'])){
                 $key =$key+1;
            }
           
            $this->data['disposition_result'][$dispo] = $this->data['disposition_count'][$this->data['disposition'][$key]];

        }
        //echo "<pre>";print_r($this->data);die;

        //code for  send notification by sms.
        if($subscription->active_sms == 1)
        {
            
            $setting = config("otp.sms");
            #find sms and email service
            $smsService = new SmsService($setting["url"], $setting["key"], $setting["token"]);
            foreach($emails as $key=> $value)
            {
                if($this->data['action'] == 'Recycle Data')
                {
                    $message = $this->data['records']." Leads has been recycled in :".$this->data['listName'].", campaign : ".$list->title." successfully on ".env('PORTAL_NAME').".";
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
                Log::debug("SendNotificationForRecycleRuleForCampaign.sendMessage.response", [$response]);
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
            $mailable = new SystemNotificationMail($from, "emails.recycleDelete", $this->data["action"], $this->data);

            $mailService = new MailService($this->clientId, $mailable, $smtpSetting);
            $mailService->sendEmail($emails);

            $subscription->last_sent = Carbon::now();
            $subscription->save();
        }
    }

}
