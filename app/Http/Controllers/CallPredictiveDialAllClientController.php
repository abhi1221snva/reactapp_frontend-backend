<?php

namespace App\Http\Controllers;
use App\Model\Client\Campaign;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Model\Client\ExtensionLive;
use App\Model\Dialer;
use Illuminate\Support\Facades\DB;
use App\Model\Master\Client;
use DateTime;
use App\Model\Cron;


class CallPredictiveDialAllClientController extends Controller
{
    private $request;
    public function __construct(Request $request, Dialer $dialer)
    {
        $this->request = $request;
        $this->model = $dialer;
    }

    public function index()
    {
        die;
        if(empty($_GET['token']))
        {
            echo "Invalid API URL";die;
        }
        else
        if(!empty($_GET['token']))
        {
            $tokenENV = env('PREDICTIVE_CALL_TOKEN');
            if($tokenENV == $_GET['token'])
            {

                $all_clients = Client::on("master")->where('id',31)->get()->all();
                $data['total_clients'] = count($all_clients);

                if(!empty($all_clients))
                {
                    foreach($all_clients as $client_key =>$client)
                    {
                        $clientId =$client->id;

                        //same as job

                        $connection = 'mysql_' . $clientId;
                        $data = array();

                        Log::info("PredictiveCallJobCron.handle", ["clientId" => $clientId ]);

                        $live_campaigns = ExtensionLive::on($connection)->join('campaign', 'extension_live.campaign_id', '=', 'campaign.id')->where('extension_live.status','=','0')->where('campaign.dial_mode','=','predictive_dial')->groupBy('extension_live.campaign_id')->get(['extension_live.extension','extension_live.status','extension_live.campaign_id','extension_live.lead_id', 'campaign.id','campaign.title','campaign.time_based_calling','campaign.call_time_start','campaign.call_time_end','campaign.last_time_cron_run','campaign.duration','campaign.call_ratio','campaign.dial_mode','campaign.hopper_mode']);

                        //echo "<pre>";print_r($live_campaigns);die;
                        Log::info("PredictiveCallJobCron.handle", ["clientId" => $clientId,"live_campaigns" => $live_campaigns]);

                        $count = count($live_campaigns);


                        if ($count == 0)
                        {
                            $data['parent_'.$clientId]['response']='No Campaign and Extension free for client_'.$clientId;
                        }

                        else
                        {
                            foreach($live_campaigns as $extension_key =>$campaign)
                            {
                                if(!empty($campaign->campaign_id))
                                {
                                    if($campaign->time_based_calling == 1)
                                    {
                                        $currenttime = \Carbon\Carbon::now('America/New_York')->format('H:i:s');

                                        $start_time = $campaign->call_time_start;
                                        $end_time = $campaign->call_time_end;
                                        $format = "H:i:s";

                                        $input_date = \DateTime::createFromFormat($format, $currenttime);
                                        $start_date = \DateTime::createFromFormat($format, $start_time);
                                        $end_date = \DateTIme::createFromFormat($format, $end_time);
                                        if($start_date <= $input_date && $input_date <= $end_date)
                                        {
                                            $data['parent_'.$clientId]['campaign'][$extension_key]['time_based_calling'] = "Time based Calling is matched";
                                            $data['parent_'.$clientId]['campaign'][$extension_key]['campaign_id'] = $campaign->id;
                                            $data['parent_'.$clientId]['campaign'][$extension_key]['campaign_title'] = $campaign->title;
                                        }
                                    }

                                    else
                                    {
                                        $data['parent_'.$clientId]['campaign'][$extension_key]['time_based_calling'] = "Non Time based Calling is matched";
                                        $data['parent_'.$clientId]['campaign'][$extension_key]['campaign_id'] = $campaign->id;
                                        $data['parent_'.$clientId]['campaign'][$extension_key]['campaign_title'] = $campaign->title;
                                    }

                                    $last_time_cron_run = \Carbon\Carbon::now('America/New_York')->format('Y-m-d H:i:s');
                                    $duration = $campaign->duration;
                                    $last_time_cron_run_db = $campaign->last_time_cron_run;

                                    if($campaign->dial_mode == 'predictive_dial')
                                    {
                                        $add_duration_date=strtotime($last_time_cron_run_db) + $duration;
                                        $add_duration_last_time_cron_run_db= date('Y-m-d H:i:s',$add_duration_date);
                                        $timestamp1 = strtotime($last_time_cron_run);
                                        $timestamp2 = strtotime($add_duration_last_time_cron_run_db);

                                        if($timestamp1>$timestamp2)
                                        {
                                            $data['parent_'.$clientId]['campaign'][$extension_key]['last_time_cron_run'] = "Last time Cron Run time is ".$last_time_cron_run_db;
                                            $live_extensions_status =ExtensionLive::on($connection)->where('status',0)->where('campaign_id',$campaign->id)->get()->all();

                                            $total_extension_live = count($live_extensions_status);
                                            $call_ratio = $campaign->call_ratio;
                                            $total_hits_predictive_calls = round($call_ratio * $total_extension_live);
                                            $data['parent_'.$clientId]['campaign'][$extension_key]['total_extension_live']=$total_extension_live;
                                            $data['parent_'.$clientId]['campaign'][$extension_key]['call_ratio']=$call_ratio;
                                            $data['parent_'.$clientId]['campaign'][$extension_key]['duration']=$duration;
                                            $data['parent_'.$clientId]['campaign'][$extension_key]['total_predictive_call']=$total_hits_predictive_calls;

                                            $serverSql = "SELECT asterisk_server.id,host as ip_address,detail,domain FROM client_server Left join asterisk_server on asterisk_server.id = client_server.server_id WHERE client_server.client_id = :client_id";

                                            $serverList = DB::connection('master')->select($serverSql, array('client_id' => $clientId));
                                            $serverListResponse = (array)$serverList;
                                            $responseList['serverList'] = $serverListResponse;
                                            $asterisk_server_id = $responseList['serverList'][0]->id;
                                            $data['parent_'.$clientId]['campaign'][$extension_key]['asterisk_server_id'] = $responseList['serverList'][0]->id;

                                            $extension = $live_extensions_status[0]->extension;

                                            $dialer = new Dialer;
                                            $cron = new Cron();
                                            for($i=0;$i< $total_hits_predictive_calls;$i++)
                                            {
                                                $addResponse = $dialer->addLeadToExtensionLive($campaign->id, $campaign->hopper_mode, $extension, $asterisk_server_id, $clientId );    
                                                $data['parent_'.$clientId]['campaign'][$extension_key]['predictive_call'][$i] = $addResponse;

                                                
                                                if(!empty($addResponse['code']) == 'NO_LEADS')
                                                {
                                                    $result = $cron->addLeadTemp($clientId,$campaign->id);
                                                    $data['parent_'.$clientId]['campaign'][$extension_key]['add_lead_result'] = $result;

                                                    Log::info("PredictiveCallJobCron.handle", ["clientId" => $clientId,"Add Leads" => $result]);
                                                    break;
                                                }
                                            }


                                            $data['parent_'.$clientId]['campaign'][$extension_key]['cron_time'] = $last_time_cron_run;
                                            $sql = "UPDATE campaign set last_time_cron_run = :last_time_cron_run WHERE id = :id";
                                            DB::connection($connection)->update($sql, array('id' => $campaign->id,'last_time_cron_run'=>$last_time_cron_run));
                                            $data['parent_'.$clientId]['campaign'][$extension_key]['campaign_update']='campaign_update';
                                        }

                                        else
                                        {
                                            $data['parent_'.$clientId]['campaign'][$extension_key]['time_else'] = "Last time Cron Run time is ".$last_time_cron_run_db." Please wait ";
                                            $data['parent_'.$clientId]['campaign'][$extension_key]['campaign_id'] = $campaign->id;
                                            $data['parent_'.$clientId]['campaign'][$extension_key]['campaign_name'] = $campaign->title;
                                            $data['parent_'.$clientId]['campaign'][$extension_key]['client_id'] = "client_".$clientId;
                                        }
                                    }
                                }
                            }

                        }
                            echo "<pre>";print_r($data);
                            Log::info("PredictiveCallJobCron.handle", ["clientId" => $clientId,"predictive calls" => $data]);

                        //end 
                    }
                }
            }
            else
            {
                echo "Invalid Url";die;
            }
        }
        
    }
}
