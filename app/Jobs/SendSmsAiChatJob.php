<?php

namespace App\Jobs;
use Illuminate\Database\Eloquent\Model;
use App\Model\Client\SmsAi\SmsAiCampaign;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Model\Client\ExtensionLive;
use App\Model\Client\SmsAi\SmsAiCall;
use App\Model\Client\SmsAi\SmsAiCron;

use Illuminate\Support\Facades\DB;
use DateTime;

class SendSmsAiChatJob extends Job
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
        $last_time_cron_run = \Carbon\Carbon::now('America/New_York')->format('Y-m-d H:i:s');

       // echo date('Y-m-d H:i:s');die;
        
        $connection = 'mysql_' . $clientId;
        $data = array();

        Log::info("SendSmsAiChatJobCron.handle", ["clientId" => $clientId ]);

        $sms_ai_campaign = SmsAiCampaign::on('mysql_'.$clientId)->where('dialing_mode','sms_ai')->where('status',1)->get()->all();
        //echo "<pre>";print_r($sms_ai_campaign);die;

        if(!empty($sms_ai_campaign)) 
        {
            foreach($sms_ai_campaign as $campaign)
            {
                if(!empty($campaign->id))
                {

                    /*$date_update = date('Y-m-d H:i:s');
                    $sql = "UPDATE sms_ai_campaign set last_time_cron_run = :last_time_cron_run WHERE id = :id";
                    DB::connection($connection)->update($sql, array('id' => $campaign->id,'last_time_cron_run'=>$date_update));*/


                    if($campaign->time_based_calling == 1)
                    {
                        $time = date('H:i:s');
                        list($hrs,$mins,$secs) = explode(':',$time);
                        $currenttime = $hrs.":".$mins.":".$secs; 

                        $start_time = $campaign->call_time_start;
                        $end_time = $campaign->call_time_end;
                        $format = "H:i:s";

                        $input_date = \DateTime::createFromFormat($format, $currenttime);
                        $start_date = \DateTime::createFromFormat($format, $start_time);
                        $end_date = \DateTIme::createFromFormat($format, $end_time);

                        $array = array('input_date' =>$input_date,'start_date' => $start_date,'end_date'=>$end_date);
                        //echo "<pre>";print_r($array);die;

                        if($start_date <= $input_date && $input_date <= $end_date)
                        {
                            $data['parent_'.$clientId]['campaign']['time_based_calling'] = "Time based Calling is matched";
                            $data['parent_'.$clientId]['campaign']['campaign_id'] = $campaign->id;
                            $data['parent_'.$clientId]['campaign']['campaign_title'] = $campaign->title;
                        }
                        else
                        {
                            $data['parent_'.$clientId]['campaign']['time_based_calling'] = "Time based Calling is not matched";
                            $data['parent_'.$clientId]['campaign']['campaign_id'] = $campaign->id;
                            $data['parent_'.$clientId]['campaign']['campaign_title'] = $campaign->title;

                        }



                    }

                    else
                    {
                        

                        $data['parent_'.$clientId]['campaign']['time_based_calling'] = "Non Time based Calling is matched";
                        $data['parent_'.$clientId]['campaign']['campaign_id'] = $campaign->id;
                        $data['parent_'.$clientId]['campaign']['campaign_title'] = $campaign->title;
                    }

                    $duration = $campaign->call_duration;
                    $last_time_cron_run_db = $campaign->last_time_cron_run;
                    if(is_null($last_time_cron_run_db))
                    {
                    $sql = "UPDATE sms_ai_campaign set last_time_cron_run = :last_time_cron_run WHERE id = :id";
                                            DB::connection($connection)->update($sql, array('id' => $campaign->id,'last_time_cron_run'=>$last_time_cron_run));

                    $last_time_cron_run_db = $last_time_cron_run;

                    }

                   // echo $last_time_cron_run_db;die;

                    if($campaign->dialing_mode == 'sms_ai')
                    {
                        $add_duration_date=strtotime($last_time_cron_run_db) + $duration;
                        $add_duration_last_time_cron_run_db= date('Y-m-d H:i:s',$add_duration_date);

                        $timestamp1 = strtotime($last_time_cron_run);
                        $timestamp111= date('Y-m-d H:i:s',$timestamp1);

                        $timestamp2 = strtotime($add_duration_last_time_cron_run_db);
                        $timestamp12= date('Y-m-d H:i:s',$timestamp2);


                        $array = array('add_duration_date' =>$add_duration_date,'add_duration_last_time_cron_run_db' => $add_duration_last_time_cron_run_db,'timestamp1'=>$timestamp111,'timestamp2'=>$timestamp12);
                        //echo "<pre>";print_r($array);die;



                        if($timestamp1>$timestamp2)
                                        {
                                            $data['parent_'.$clientId]['campaign']['last_time_cron_run'] = "Last time Cron Run time is ".$last_time_cron_run_db;

                                            $sms_ai_call = new SmsAiCall;
                                            $cron = new SmsAiCron();
                                            for($i=0;$i< $campaign->call_ratio;$i++)
                                            {
                                                $addResponse = $sms_ai_call->addLeadToSmsAi($campaign->id,$clientId );    
                                                $data['parent_'.$clientId]['campaign']['sms_ai_campaign'][$i] = $addResponse;

                                                
                                                if(!empty($addResponse['code']) == 'NO_LEADS')
                                                {
                                                    $result = $cron->addLeadTemp($clientId,$campaign->id);

                   // echo "<pre>";print_r($result);die;

                                                    $data['parent_'.$clientId]['campaign']['add_lead_result'] = $result;

                                                    Log::info("PredictiveCallJobCron.handle", ["clientId" => $clientId,"Add Leads" => $result]);
                                                    break;
                                                }
                                            }


                                            $data['parent_'.$clientId]['campaign']['cron_time'] = $last_time_cron_run;
                                            $sql = "UPDATE sms_ai_campaign set last_time_cron_run = :last_time_cron_run WHERE id = :id";
                                            DB::connection($connection)->update($sql, array('id' => $campaign->id,'last_time_cron_run'=>$last_time_cron_run));
                                            $data['parent_'.$clientId]['campaign']['campaign_update']='campaign_update';
                                           
                    }

                    else
                                        {
                                            $data['parent_'.$clientId]['campaign']['time_else'] = "Last time Cron Run time is ".$last_time_cron_run_db." Please wait ";
                                            $data['parent_'.$clientId]['campaign']['campaign_id'] = $campaign->id;
                                            $data['parent_'.$clientId]['campaign']['campaign_name'] = $campaign->title;
                                            $data['parent_'.$clientId]['campaign']['client_id'] = "client_".$clientId;
                                        }
                }


                    echo "<pre>";print_r($data);die;

                }
            }
        }
    }
}
