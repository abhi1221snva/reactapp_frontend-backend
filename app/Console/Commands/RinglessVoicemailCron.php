<?php

namespace App\Console\Commands;
use Illuminate\Console\Command;
use App\Jobs\SendVoicemailRinglessJob;
use App\Model\Client\Ringless\RinglessCampaign;
use Illuminate\Support\Facades\DB;
use App\Model\Client\Ringless\RinglessCall;
use App\Model\Client\Ringless\RinglessCron;
use Illuminate\Support\Facades\Log;

class RinglessVoicemailCron extends Command
{
    protected $signature = 'app:send:ringless-voicemail-command  {--clientId=}';
    protected $description = 'Run this command for send the audio to all existing number in the ringless  campaigns';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $last_time_cron_run = date('Y-m-d H:i:s');
        $clients = \App\Model\Master\Client::where('is_deleted',0)->where('id','11')->get()->all();

        foreach ( $clients as $client ) 
        {
            $this->info("SendVoicemailRinglessJob({$client->id})");
            $ringless_campaign = RinglessCampaign::on('mysql_'.$client->id)->where('dialing_mode','ringless_voicemail')->where('status','1')->where('is_deleted',0)->get()->all();
            if(!empty($ringless_campaign)) 
            {
                foreach($ringless_campaign as $campaign)
                {
                    if(!empty($campaign->id))
                    {
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
                            if($start_date <= $input_date && $input_date <= $end_date)
                            {
                                $data['parent_'.$client->id]['campaign']['time_based_calling'] = "Time based Calling is matched";
                                $data['parent_'.$client->id]['campaign']['campaign_id'] = $campaign->id;
                                $data['parent_'.$client->id]['campaign']['campaign_title'] = $campaign->title;
                            }
                            else
                            {
                                $data['parent_'.$client->id]['campaign']['time_based_calling'] = "Time based Calling is not matched";
                                $data['parent_'.$client->id]['campaign']['campaign_id'] = $campaign->id;
                                $data['parent_'.$client->id]['campaign']['campaign_title'] = $campaign->title;
                            }
                        }
                        else
                        {
                            $data['parent_'.$client->id]['campaign']['time_based_calling'] = "Non Time based Calling is matched";
                            $data['parent_'.$client->id]['campaign']['campaign_id'] = $campaign->id;
                            $data['parent_'.$client->id]['campaign']['campaign_title'] = $campaign->title;
                        }

                        $duration = $campaign->call_duration;
                        $last_time_cron_run_db = $campaign->last_time_cron_run;
                        
                        if(is_null($last_time_cron_run_db))
                        {
                            $sql = "UPDATE ringless_campaign set last_time_cron_run = :last_time_cron_run WHERE id = :id";
                            DB::connection('mysql_'.$client->id)->update($sql, array('id' => $campaign->id,'last_time_cron_run'=>$last_time_cron_run));
                            $last_time_cron_run_db = $last_time_cron_run;
                        }


                        if($campaign->dialing_mode == 'ringless_voicemail')
                        {
                            $add_duration_date=strtotime($last_time_cron_run_db) + $duration;
                            $add_duration_last_time_cron_run_db= date('Y-m-d H:i:s',$add_duration_date);
                            $timestamp1 = strtotime($last_time_cron_run);
                            $timestamp111= date('Y-m-d H:i:s',$timestamp1);
                            $timestamp2 = strtotime($add_duration_last_time_cron_run_db);
                            $timestamp12= date('Y-m-d H:i:s',$timestamp2);
                            $array = array('add_duration_date' =>$add_duration_date,'add_duration_last_time_cron_run_db' => $add_duration_last_time_cron_run_db,'timestamp1'=>$timestamp111,'timestamp2'=>$timestamp12);

                            if($timestamp1>$timestamp2)
                            {
                                $data['parent_'.$client->id]['campaign']['last_time_cron_run'] = "Last time Cron Run time is ".$last_time_cron_run_db;
                                $ringless_call = new RinglessCall;
                                $cron = new RinglessCron();

                                for($i=0;$i< $campaign->call_ratio;$i++)
                                {
                                    $addResponse = $ringless_call->addLeadToRingless($campaign,$client->id );    
                                    $data['parent_'.$client->id]['campaign']['ringless_campaign'][$i] = $addResponse;

                                    if(!empty($addResponse['code']) == 'NO_LEADS')
                                    {
                                        $result = $cron->addLeadTemp($client->id,$campaign->id);
                                        $data['parent_'.$client->id]['campaign']['add_lead_result'] = $result;
                                        Log::info("RinglessVoicemailDropCampaign.handle", ["clientId" => $client->id,"Add Leads" => $result]);
                                        break;
                                    }
                                }

                                $data['parent_'.$client->id]['campaign']['cron_time'] = $last_time_cron_run;
                                $sql = "UPDATE ringless_campaign set last_time_cron_run = :last_time_cron_run WHERE id = :id";
                                DB::connection('mysql_'.$client->id)->update($sql, array('id' => $campaign->id,'last_time_cron_run'=>$last_time_cron_run));
                                $data['parent_'.$client->id]['campaign']['campaign_update']='campaign_update';
                            }
                            else
                            {
                                $data['parent_'.$client->id]['campaign']['time_else']="Last time Cron Run time is ".$last_time_cron_run_db." Please wait ";
                                $data['parent_'.$client->id]['campaign']['campaign_id'] = $campaign->id;
                                $data['parent_'.$client->id]['campaign']['campaign_name'] = $campaign->title;
                                $data['parent_'.$client->id]['campaign']['client_id'] = "client_".$client->id;
                            }
                        }

                        echo "<pre>";print_r($data); 
                    }
                }
            }
        }
    }
}
