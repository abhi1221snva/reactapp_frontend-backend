<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

use Session;
use App\Helper\Helper;
use Illuminate\Http\Request;
use App\Events\IncomingLead;
use Google\Cloud\TextToSpeech\V1\AudioConfig;
use Google\Cloud\TextToSpeech\V1\AudioEncoding;
use Google\Cloud\TextToSpeech\V1\SsmlVoiceGender;
use Google\Cloud\TextToSpeech\V1\SynthesisInput;
use Google\Cloud\TextToSpeech\V1\TextToSpeechClient;
use Google\Cloud\TextToSpeech\V1\VoiceSelectionParams;
use App\Model\VoiceTemplate;
use Illuminate\Support\Facades\DB;
use App\Model\Client\Campaign;
use App\Model\Client\CampaignList;
use App\Model\Client\ListData;
use App\Model\Client\LeadTemp;
use App\Model\Dialer;
use App\Model\Client\CustomFieldLabelsValues;
use App\Model\Client\Label;
use App\Model\SmsTemplete;

use App\Model\Client\ListHeader;
use App\Model\Cron;
use App\Model\User;
use App\Services\EasifyCreditService;
use Illuminate\Support\Facades\Log;

class OutboundUpdateCron extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'outbound:cron';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        try
        {
            $day = date('l');
            /*if($day =='Sunday' || $day == 'Saturday')
            {
                echo "Sorry Outbound AI not Run Saturday and Sunday";die;
            }*/

            //$clientIds = array('3');//array('3','47');
            $clientIds = array('3','9');

            foreach($clientIds as $clientId)
            {

                date_default_timezone_set('US/Eastern');
                $last_time_cron_run = date('Y-m-d H:i:s');

                // Find the billing admin for this client
                $adminUser = User::where('parent_id', $clientId)->where('role', 1)->first();



            //$clientId = 3;
            $outboundCampaign = Campaign::on('mysql_'.$clientId)->select('id','title','call_ratio','duration','hopper_mode','redirect_to','redirect_to_dropdown','amd_drop_action','voicedrop_option_user_id','amd','last_time_cron_run')->where('dial_mode','outbound_ai')->where('status',1)->get()->all();
           //echo "<pre>";print_r($outboundCampaign);die;


            if(!empty($outboundCampaign)) 
            {
                foreach($outboundCampaign as $details)
                {





                    $call_ratio  = $details->call_ratio;
                    $duration    = $details->duration;  //in sec
                    $campaign_id = $details->id;
                    $hopper_mode = $details->hopper_mode;
                    $extension   = '37873';
                    $asterisk_server_id = 7;
                    $redirect_to = $details->redirect_to;
                    $redirect_to_dropdown = $details->redirect_to_dropdown; 
                    $last_time_cron_run_db = $details->last_time_cron_run;




                       $add_duration_date=strtotime($last_time_cron_run_db) + $duration;
                        $add_duration_last_time_cron_run_db= date('Y-m-d H:i:s',$add_duration_date);

                        $timestamp1 = strtotime($last_time_cron_run);
                        $timestamp111= date('Y-m-d H:i:s',$timestamp1);

                        $timestamp2 = strtotime($add_duration_last_time_cron_run_db);
                        $timestamp12= date('Y-m-d H:i:s',$timestamp2);

                         $array = array('add_duration_date' =>$add_duration_date,'add_duration_last_time_cron_run_db' => $add_duration_last_time_cron_run_db,'timestamp1'=>$timestamp111,'timestamp2'=>$timestamp12,'last_time_cron_run' => $last_time_cron_run_db);
                        //echo "<pre>";print_r($array);die;

                      //  echo $timestamp1.'-------'.$timestamp2;die;


                       

                    if(is_null($last_time_cron_run_db))
                    {
                        $sql = "UPDATE campaign set last_time_cron_run = :last_time_cron_run WHERE id = :id";
                        DB::connection('mysql_'.$clientId)->update($sql, array('id' => $campaign_id,'last_time_cron_run'=>$last_time_cron_run));
                        $last_time_cron_run_db = $last_time_cron_run;
                    }
                   /* else
                    {
                        $sql = "UPDATE campaign set last_time_cron_run = :last_time_cron_run WHERE id = :id";
                        DB::connection('mysql_'.$clientId)->update($sql, array('id' => $campaign_id,'last_time_cron_run'=>$add_duration_last_time_cron_run_db));
                    }*/


                          $array = array('add_duration_date' =>$add_duration_date,'add_duration_last_time_cron_run_db' => $add_duration_last_time_cron_run_db,'timestamp1'=>$timestamp111,'timestamp2'=>$timestamp12,'last_time_cron_run' => $last_time_cron_run_db);
                       echo "<pre>";print_r($array);

                      //  echo $timestamp1.'-------'.$timestamp2;die;

                     if($timestamp1>$timestamp2)
                     {
                        //echo "time is ok";
                        $sql = "UPDATE campaign set last_time_cron_run = :last_time_cron_run WHERE id = :id";
                        DB::connection('mysql_'.$clientId)->update($sql, array('id' => $campaign_id,'last_time_cron_run'=>
                            $add_duration_last_time_cron_run_db));
                    }
                    else
                    {
                        echo "time is not ok";die;

                    }


                  /*  echo "hello";die;
 
                                     

                die;
*/


                    //for audio message https://api-test.domain.com/upload/ivr_file/3_audio_1678185520.wav
                    //for ivr https://api-test.domain.com/upload/ivr_file/3_ivr_1678186283.wav
                    $amd = $details->amd;
                    if($amd == 1)
                    {
                        $amd_drop_action = $details->amd_drop_action;
                        $amd_drop_message_output = $details->voicedrop_option_user_id;
                    }
                    else
                    {
                        $amd_drop_action = 0;
                        $amd_drop_message_output = 0;

                    }

                    /* =======================
                     * 🔹 EASIFY CREDIT CHECK
                     * ======================= */
                    if ($adminUser && !empty($adminUser->easify_user_uuid)) {
                        $creditService = new EasifyCreditService();
                        $creditCheck = $creditService->checkCredits(
                            $adminUser->id,
                            $adminUser->easify_user_uuid,
                            'outgoing_call',
                            'outbound_ai_batch', // placeholder resource
                            $call_ratio
                        );

                        //  Skip if credit check fails or insufficient balance
                        if (
                            empty($creditCheck) || 
                            ($creditCheck['status'] ?? false) === false ||
                            ($creditCheck['data']['has_sufficient_credits'] ?? false) === false
                        ) {
                            Log::warning("Easify: Skipping outbound batch for client $clientId campaign $campaign_id due to credit check failure or insufficient balance", [
                                'response' => $creditCheck
                            ]);
                            continue; // Skip this campaign for now
                        }
                    }

                    $requestData = array (
                        'hopper_mode'=>$hopper_mode,
                        'extension'=>$extension,
                        'asterisk_server_id'=>$asterisk_server_id,
                        'clientId'=>$clientId,
                        'campaign_id'=>$campaign_id,
                        'redirect_to'=>$redirect_to,
                        'redirect_to_dropdown'=>$redirect_to_dropdown,
                        
                        /*'amd_drop_action'=>$amd_drop_action,
                        'amd_drop_message'=>$amd_drop_message*/
                    );

                    // echo "<pre>";print_r($requestData);die;


                    $dialer = new Dialer;
                    $cron = new Cron();
                    $template = new SmsTemplete();


                    for($i=0;$i< $call_ratio;$i++)
                    {
                        $addResponse = $dialer->addLeadToExtensionLiveOutboundAI($campaign_id, $hopper_mode, $extension, $asterisk_server_id, $clientId );  
                        if(!empty($addResponse['code']) == 'NO_LEADS')
                        {
                            $result = $cron->addLeadTemp($clientId,$campaign_id);
                            break;
                        }
                     
                        
                        $dialer = new Dialer;

                        $requestData['list_id'] = $addResponse['list_id'];
                        $requestData['lead_id'] = $addResponse['lead_id'];

                        if($amd_drop_action == 1) //hang up
                        {
                            $amd_drop_message_output = $amd_drop_message_output;
                        }
                        else
                        if($amd_drop_action == 2) //audio message
                        {
                            $amd_drop_message_output = $amd_drop_message_output;
                        }

                        else
                        if($amd_drop_action == 3) //voice template
                        {
                            $file_name = $template->changeVoiceMessageText($addResponse,$redirect_to,$amd_drop_message_output,$clientId);
                            $amd_drop_message_output = $file_name;
                        }

                        if($redirect_to == 1) //audio message
                        {
                            $file_name = $redirect_to_dropdown.'.wav';
                        }
                        else
                        if($redirect_to == 2) //voice template
                        {
                             $file_name = $template->changeVoiceMessageText($addResponse,$redirect_to,$redirect_to_dropdown,$clientId);
                        }
                        else
                        if($redirect_to == 3) //extension
                        {
                            $file_name = $redirect_to_dropdown;
                        }
                        else
                        if($redirect_to == 4) //ring group
                        {
                            $file_name = $redirect_to_dropdown;
                        }
                        else
                        if($redirect_to == 5) //ivr
                        {
                            $file_name = $redirect_to_dropdown.'.wav';
                        }
                        // else
                        // if($redirect_to == 6) //voice ai
                        // {
                        //     $file_name = $redirect_to_dropdown;
                        //     clientCampaignLeadPromptRedisCacheSet($clientId, $campaign_id,  $requestData['lead_id'],  $requestData['list_id'], $redirect_to_dropdown,true);

                        //     //clientCampaignLeadPromptRedisCacheSet_2($clientId, $campaign_id,  $requestData['lead_id'],  $requestData['list_id'], $redirect_to_dropdown,true);

                        // }

                        $requestData['file_name'] = $file_name;
                        $requestData['amd_drop_action'] = $amd_drop_action;
                        $requestData['amd_drop_message_output'] = $amd_drop_message_output;

                        //echo "<pre>";print_r($requestData);die;
                        $call = $dialer->outboundAIDialAsterisk($requestData);  
                        //echo "<pre>";print_r($call);die;
                       
                    }

                        echo "call send ".$clientId;

                }
            }

        }
        }

        catch(\Exception $e)
        {
            echo $e->getMessage();
        }
    }


   
}
