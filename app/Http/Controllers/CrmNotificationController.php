<?php

namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Model\Client\Notification;
use App\Model\Client\SmtpSetting;
use App\Mail\GenericMail;
use App\Model\Client\emailLog;
use App\Services\CrmMailService;
use App\Model\User;
use App\Model\Master\Client;
use App\Jobs\SendCrmNotificationEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\NotificationController;
use App\Services\FirebaseService;
use App\Model\UserFcmToken;


class CrmNotificationController extends Controller
{
    public function list(Request $request)
    {
        try
        {
            $clientId = $request->auth->parent_id;
            $group = [];
            $notesandupdate = Notification::on("mysql_$clientId")->join('master.users', 'crm_notifications.user_id', '=', 'users.id')->orderBy('crm_notifications.id','DESC')
                  ->get(['crm_notifications.*', 'users.first_name','users.last_name'])->all();
                  return $this->successResponse("Notifications", $notesandupdate);
        }
        catch (\Throwable $exception)
        {
            return $this->failResponse("Failed to list of Notifications", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }

    public function create(Request $request)
    {
        //return $request->all();
        try
        {
            //send email abhishek

            $clientId = $request->auth->parent_id;
            $Notification = new Notification();
            $Notification->setConnection("mysql_$clientId");
            $Notification->user_id = $request->auth->id;
            $Notification->lead_id = $request->lead_id;
            $Notification->message = $request->message;
            if ($request->has("type"))
                $Notification->type = $request->input("type");
            else
            $Notification->type = '0';
            $Notification->saveOrFail();

            $messageData = array(
                "lead_id" => $request->lead_id,
                "message" => $request->message,
                "user_id" => $request->auth->id,
                'type' => $request->input("type"),
                'mailable' =>"emails.crm-generic"

            );

          //  $data = array('request' => $request);


            $notificationData = [
                "action" => "notification",
                "user" => $messageData
            ];


            //dispatch(new SendCrmNotificationEmail($request->auth->parent_id, $notificationData, 'notification'))->onConnection("database");



           // $this->sendEmailNotification($request);


            // Send FCM Push Notification
            try {
                $fcmTokens = UserFcmToken::where('user_id', $request->auth->id)
                    ->pluck('device_token')
                    ->toArray();
                
                if (!empty($fcmTokens)) {
                    FirebaseService::sendNotification(
                        $fcmTokens,
                        "CRM Update for Lead #" . $request->lead_id,
                        $request->message,
                        [
                            'type' => 'crm_notification',
                            'lead_id' => $request->lead_id,
                            'user_id' => $request->auth->id
                        ]
                    );
                }
            } catch (\Exception $e) {
                \Log::error('FCM CRM Notification failed', [
                    'error' => $e->getMessage(),
                    'user_id' => $request->auth->id
                ]);
            }


            return $this->successResponse("Notification Added Successfully", $Notification->toArray());
        }
        catch (\Exception $exception)
        {
            return $this->failResponse("Failed to create Notification ", [
                $exception->getMessage()
            ], $exception, 500);
        }
    }


    public function sendEmailNotificationOLD($request)
    {
            $clientId = $request->auth->parent_id;
            $client = Client::findOrFail($clientId);

            $company_name = $client->company_name;

        
            $smtp_setting = SmtpSetting::on("mysql_$clientId")->where('status','1')->first();      
            $mailable ="emails.crm-generic";
            $name = User::findOrFail($request->auth->id);
            $subject = 'Status Update - '.$company_name.' Lead Id - '.$request->lead_id;
            if($request->input("type") == '1')
            {
                $message = $name->first_name.' '.$name->last_name.' added notes <b>'.$request->message.'</b>';
            }
            else
            {
                $message = $name->first_name.' '.$name->last_name.' - <b>'.$request->message.'</b>';
            }
            $data = array('subject'=>$subject,'content'=>$message);
            $mailService = new CrmMailService($request->auth->parent_id, $mailable, $smtp_setting, $data);

           // $to = array('abhi4mca@gmail.com');//env('SYSTEM_ADMIN_EMAIL'); //,'mailme@rohitwanchoo.com'

//            $send = $mailService->sendEmail($to);


           // $admins = User::where('role', '=', 6)->orWhere('role', '=', 1)->where('is_deleted','=','0')->get()->all();

            if(!empty($to))
            {
                foreach($to as $email)
                {
                    $send = $mailService->sendEmail($email);
                }
            }


            //return response()->json(["success" => true]);

        //close send email abhishek
    }

    public function listbyLeadId(Request $request,$lead_id,$type)
    {
                          //return $this->successResponse("Notifications", [$type]);

        try
        {
            $clientId = $request->auth->parent_id;
            /*$notesandupdate = Notification::on("mysql_$clientId")->join('master.users', 'crm_notifications.user_id', '=', 'users.id')->orderBy('crm_notifications.id','DESC')->where('crm_notifications.lead_id',$lead_id)
                  ->get(['crm_notifications.*', 'users.first_name','users.last_name'])->all();*/


                  if($type == 1)
                  {
            $notesandupdate = Notification::on("mysql_$clientId")->orderBy('crm_notifications.id','DESC')->where('crm_notifications.lead_id',$lead_id)->where('type',$type)
                  ->get(['crm_notifications.*'])->all();

                  }
                  else
                  {

                    $notesandupdate = Notification::on("mysql_$clientId")->orderBy('crm_notifications.id','DESC')->where('crm_notifications.lead_id',$lead_id)->where('type',$type) //both submission and updates
                  ->get(['crm_notifications.*'])->all();

                  }
                  return $this->successResponse("Notifications", $notesandupdate);

        }
        catch (\Throwable $exception)
        {
            return $this->failResponse("Failed to list of Notifications", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }
}
