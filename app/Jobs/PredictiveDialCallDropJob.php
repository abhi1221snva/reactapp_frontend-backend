<?php

namespace App\Jobs;
use Illuminate\Database\Eloquent\Model;
use App\Model\Client\Campaign;
use App\Model\Client\Cdr;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Model\Client\ExtensionLive;
use App\Model\Dialer;
use Illuminate\Support\Facades\DB;
use DateTime;
use App\Model\Cron;
use App\Model\Client\SmtpSetting;
use App\Mail\SystemNotificationMail;
use App\Model\User;
use App\Services\SmsService;
use App\Services\MailServiceToBccCC;



use App\Services\MailService;

class PredictiveDialCallDropJob extends Job
{
    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    //public $tries = 5;
    //public $timeout = 300;

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
        $clientId = $this->clientId;
        $date = \Carbon\Carbon::now('America/New_York')->format('Y-m-d');
        $connection = 'mysql_' . $clientId;
        $data = array();
        Log::info("PredictiveDialCallDropJob.handle", ["clientId" => $clientId ]);
        $campaign_list = Campaign::on($connection)->where('dial_mode','predictive_dial')->where('automated_duration',1)->get()->all();
        if(!empty($campaign_list))
        {
            foreach($campaign_list as $key=> $camp)
            {   
                $check_extension_live_for_campaign = ExtensionLive::on($connection)->where('campaign_id',$camp->id)->get()->first();
                if(empty($check_extension_live_for_campaign))
                {
                     Log::info("PredictiveDialCallDropJob.handle", ["clientId" => $clientId, 'message'=>"No Extension or Campaign Live ".$camp->id ]);

                    continue;
                }
                $cdr_total_call = Cdr::on($connection)->Where('start_time', 'like', '%' .$date. '%')->where('campaign_id',$camp->id)->where('type','predictive_dial')->count();
                $data['campaign_id'] = $camp->id;
                $data['percentage_inc_dec'] = $camp->percentage_inc_dec;

                $data['campaignTitle'] = $camp->title;
                $data['cdr_total_call'] = $cdr_total_call;

                $cdr_total_drop_call = Cdr::on($connection)->where('type','predictive_dial')->Where('start_time', 'like', '%' .$date. '%')->where('campaign_id',$camp->id)->where(function($q)
                {
                    $q->where('disposition_id', 0)->orWhere('disposition_id', NULL);
                })->count();


                $data['cdr_total_drop_call'] = $cdr_total_drop_call;
                if($data['cdr_total_call'] !=0)
                {
                    $data['percentage'] = round(($data['cdr_total_drop_call']*100)/$data['cdr_total_call']);

                    if($data['percentage'] >=10 && $data['percentage'] <=19 )
                    {
                        $data['duration'] = 1;
                    }
                    else
                    if($data['percentage'] >=20 && $data['percentage'] <=29 )
                    {
                        $data['duration'] = 2;
                    }
                    else
                    if($data['percentage'] >=30 && $data['percentage'] <=39 )
                    {
                        $data['duration'] = 3;
                    }
                    else
                    if($data['percentage'] >=40 && $data['percentage'] <=49 )
                    {
                        $data['duration'] = 4;
                    }
                    else
                    if($data['percentage'] >=50 && $data['percentage'] <=59 )
                    {
                        $data['duration'] = 5;
                    }
                    else
                    if($data['percentage'] >=60 && $data['percentage'] <=69 )
                    {
                        $data['duration'] = 6;
                    }
                    else
                    if($data['percentage'] >=70 && $data['percentage'] <=79 )
                    {
                        $data['duration'] = 7;
                    }
                    else
                    if($data['percentage'] >=80 && $data['percentage'] <=90 )
                    {
                        $data['duration'] = 8;
                    }
                    else
                    {
                        $data['duration'] = $camp->duration;
                    }
                //echo "<pre>";print_r($data);die;



                    $data['update_duration']='no';

                    if($camp->duration != $data['duration'])
                    {
                        if($camp->duration > $data['duration'])
                        {
                            $percentage = '-down';
                            $data['percentage_label'] = 'a decrease';
                        }
                        else
                        {
                            $percentage ='-up';
                            $data['percentage_label'] = 'an increase';

                        }
                        $data['percentage_inc_dec'] =  $data['duration'].$percentage;

                        $data['update_duration']='yes';

               // echo "<pre>";print_r($data);die;

                        $sql = "UPDATE campaign set duration = :duration,percentage_inc_dec=:percentage_inc_dec WHERE id = :id";
                        DB::connection($connection)->update($sql, array('id' => $camp->id,'duration'=>$data['duration'],'percentage_inc_dec'=>$data['percentage_inc_dec']));

                        $role = array('1','3');
                        $emails = User::whereIn('role', $role)->where('parent_id',$clientId)->where('is_deleted','0')->select('email','mobile','country_code','role')->get()->all();

                        #send sms for predictive drop calls
                        $setting = config("otp.sms");
                        $smsService = new SmsService($setting["url"], $setting["key"], $setting["token"]);

                        foreach($emails as $key=> $value)
                        {
                            $to = str_replace('-','',$value->country_code.$value->mobile);
                            $message = "Predictive Drop Call Total Calls: ".$data['cdr_total_call'].", Drop Calls: ".$data['cdr_total_drop_call'].", Change Duration: ".$data['duration']."";

                            $response = $smsService->sendMessage($setting["from_number"],$to,$message);
                            Log::debug("PredictiveDialCallDropJobSMS.sendMessage.response", [$response]);

                            $email_send[] = $value->email;
                        }



                        Log::info("PredictiveDialCallDropJob.handle", ["clientId" => $clientId,"predictive calls" => $data]);

                        $smtpSetting = SmtpSetting::on("mysql_3")->where("sender_type", "=", 'system')->first();
                        if(!empty($smtpSetting))
                        {
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

                        $view = "emails.predictiveDropCall";
                        $email = $email_send;
                        //$email = array('mailme@rohitwanchoo.com','abhi2112mca@gmail.com');
                        
                        $super_admin = env('SYSTEM_ADMIN_EMAIL');
                        $admin = explode(',',$super_admin);
                        $sender_super_admin = $admin;

                        //echo "<pre>";print_r($sender_super_admin);die;

                        $bcc = array();
                        $cc = array();

                        Log::info("PredictiveDialCallDropJobSMTP.handle", ["clientId" => $clientId,"predictive calls" => $smtpSetting]);

                        try
                        {
                            $subject = "Drop Ratio Updated For Campaign: ".$camp->title;
                            $mailable = new SystemNotificationMail($from, $view,$subject, $data);
                            $MailServiceToBccCC = new MailServiceToBccCC($clientId, $mailable, $smtpSetting);
                            $MailServiceToBccCC->sendEmail($email,$bcc,$cc);

                            Log::info("PredictiveDialCallDropJobEmail.handle", ["clientId" => $clientId,"MailServiceToBccCC calls" => $mailable]);

                            //
                            $view = "emails.predictiveDropCallSuperAdmin";
                            $mailable_super_admin = new SystemNotificationMail($from, $view,$subject, $data);
                            $MailServiceToBccCC_super_admin = new MailServiceToBccCC($clientId, $mailable_super_admin, $smtpSetting);
                            $MailServiceToBccCC_super_admin->sendEmail($sender_super_admin,$bcc,$cc);
                            Log::info("PredictiveDialCallDropJobEmail.handle", ["clientId" => $clientId,"MailServiceToBccCCSUperAdmin calls" => $mailable]);

                        } 
                        catch (\Throwable $throwable)
                        {
                            $context = buildContext($throwable, [
                                "clientId" => $clientId,
                                "email" => $email
                            ]);

                            Log::error("PredictiveDialCallDropJobError.email.error($email)", $context);
                        }
                    }
                }
                else
                {
                    $data['percentage']=0;
                    $data['duration']=0;

                     Log::info("PredictiveDialCallDropJob.handle", ["clientId" => $clientId,'message'=>"No Cdr Data" ]);
                }
            }
        }
        else
        {
            Log::info("PredictiveDialCallDropJobCampaign.handle", ["clientId" => $clientId,'message'=>'no campaign are automated_duration or predictive dial' ]);
        }
    }
}
