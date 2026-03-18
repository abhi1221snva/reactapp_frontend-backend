<?php

namespace App\Jobs;
//use App\Model\Client\SmtpSetting;
use App\Model\Client\EmailSetting;
use App\Model\Client\Lead;


use App\Model\Master\Client;
use App\Model\User;
use Illuminate\Support\Facades\Log;
use App\Model\Client\emailLog;
use App\Services\CrmMailService;
use App\Services\FirebaseService;
use App\Model\UserFcmToken;


class SendCrmNotificationEmail extends Job
{
    private $emailType;
    private $clientId;
    private $data;

    public function __construct(int $clientId, array $data, $emailType)
    {
        $this->clientId = $clientId;
        $this->data = $data;
        $this->emailType = $emailType;
        Log::info("CRMEmailNotificationJob($clientId)", $data);
    }

    public function handle()
    {
        $requestData = $this->data;
        $clientId = $this->clientId;
        $emailType = $this->emailType;
        $client = Client::findOrFail($clientId);

        //echo "<pre>";print_r($client);die;

        $company_name = $client->company_name;

        // Prefer active config; fall back to any config for the type (backward compat)
        $smtp_setting = EmailSetting::on("mysql_$clientId")
            ->where('mail_type', $emailType)
            ->where('status', 1)
            ->first()
            ?? EmailSetting::on("mysql_$clientId")->where('mail_type', $emailType)->first();

            //if($smtp_setting['send_email_via'] == 'user_email') 
            if($smtp_setting['send_email_via'] == 'user_email' && $smtp_setting['mail_type'] != 'notification') 

            {
                $user = User::findOrFail($requestData['user']['user_id']);
                $smtp_setting['sender_email'] = $user->email;
                $smtp_setting['sender_name'] = $user->first_name.' '.$user->last_name;

            }  

        //echo "<pre>";print_r($smtp_setting);die;

        $mailable = $requestData['user']['mailable'];//"emails.crm-generic";


        if($requestData['action'] == 'notification')
        {
            $email_data = array();
            if($requestData['user']['user_id'] == '0')
            {
                $name = Lead::on("mysql_$clientId")->findOrFail($requestData['user']['lead_id']);
            }
            else
            {
                $name = User::findOrFail($requestData['user']['user_id']);
                $email_data[] = $name->email;

            }


                $all_admin = User::where('base_parent_id',$clientId)->where('user_level','7')->where('is_deleted','0')->where('role','1')->get()->all();//findOrFail($requestData['user']['user_id']);

                $leadData = Lead::on("mysql_$clientId")->findOrFail($requestData['user']['lead_id']);
                $assignTo = $leadData->assigned_to;
                $createdBy = $leadData->created_by;

                $createdByUser = User::findOrFail($createdBy);
                $email_data[] = $createdByUser->email;
               // echo "<pre>";print_r($email_data);die;

                $assignToUser = User::findOrFail($assignTo);
                $email_data[] = $assignToUser->email;





                if(!empty($all_admin))
                {
                    foreach($all_admin as $admin)
                    {
                        $email_data[] = $admin['email'];
                        //$email_data[] = 'abhi2112mca@gmail.com';
                        //$email_data[] = 'abhi4mca@gmail.com';


                    }
                }

                //echo "<pre>";print_r($email_data);

                $finalEmail = array_unique($email_data);

                //echo "<pre>";print_r($finalEmail);die;


                //$email_data[] = $name->email;
           


            $subject = 'Status Update - '.$company_name.' Lead Id - '.$requestData['user']['lead_id'];
            if($requestData['user']['type'] == '1')
            {
                $message = $name->first_name.' '.$name->last_name.' added notes <b>'.$requestData['user']['message'].'</b>';
            }
            else
            {
                if($requestData['user']['user_id'] == '0')
                {
                    $message = '<b>'.$requestData['user']['message'].'</b>';
                }
                else
                {
                    $message = $name->first_name.' '.$name->last_name.' - <b>'.$requestData['user']['message'].'</b>';
                }
            }

            $data = array('subject'=>$subject,'content'=>$message);
            $mailService = new CrmMailService($clientId, $mailable, $smtp_setting, $data);
            $to = $finalEmail;
            $mailService->sendEmail($to);

            // Send FCM Push Notification
            try {
                $recipientUserIds = User::whereIn('email', $finalEmail)->pluck('id');
                $fcmTokens = UserFcmToken::whereIn('user_id', $recipientUserIds)
                    ->pluck('device_token')
                    ->toArray();
                
                if (!empty($fcmTokens)) {
                    FirebaseService::sendNotification(
                        $fcmTokens,
                        $subject,
                        strip_tags($message),
                        [
                            'type' => 'crm_notification',
                            'lead_id' => $requestData['user']['lead_id'] ?? null,
                            'clientId' => $this->clientId
                        ]
                    );
                }
            } catch (\Exception $e) {
                Log::error('FCM CRM Notification failed in Job', [
                    'error' => $e->getMessage(),
                    'clientId' => $this->clientId
                ]);
            }

            /*if(!empty($to))
            {
                foreach($to as $email)
                {
                    $send = $mailService->sendEmail($email);
                }
            }*/

        }
        else
        if($requestData['action'] == 'lenders_submission')
        {
            $data = array('subject'=>$requestData['user']['subject'],'content'=>$requestData['user']['message']);
            $mailService = new CrmMailService($clientId, $mailable, $smtp_setting, $data);
            $to = $requestData['user']['emails'];
           // $cc = $requestData['user']['ccEmails']; // Extract CC emails
            $path = $requestData['user']['file_paths'];
           // Log::info('cc checked',['cc'=>$cc]);
// Log::info('path checked',['path'=>$cc]);

            //$mailService->sendEmail($to);
            $mailService->sendEmailAttachment($to,$path);


        }
        else
        {
            $data = array('subject'=>$requestData['user']['subject'],'content'=>$requestData['user']['message']);
            $mailService = new CrmMailService($clientId, $mailable, $smtp_setting, $data);
            $to = array($requestData['user']['to']);
            $mailService->sendEmail($to);


        }
        
    }
}