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
use DateTime;
use DateTimeZone;
use App\Services\TimezoneService;

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
    public function handle_bkp()
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

                date_default_timezone_set('America/New_York');
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

    public function handle()
    {
        try {
            $this->customLog("--- Outbound Cron Started ---");
            $day = date('l');
            $clientIds = array('3', '9');
            $db = '3'; // Default or iterate? The original code hardcoded clientIds array but then accessed $this->argument('db') which might be null in cron context if not passed?
            // Wait, original code: $clientIds = array('3','9'); foreach($clientIds...
            // But strict cron signature says `outbound:cron`.
            // Inside loop: $outboundCampaign = Campaign::on('mysql_'.$clientId)...
            
            // Let's stick to the original logic structure but inside the new handle()
            
            foreach ($clientIds as $clientId) {
                $this->customLog("Processing Client", ['clientId' => $clientId]);
                date_default_timezone_set('America/New_York');
                $last_time_cron_run = date('Y-m-d H:i:s');
                $db = $clientId;

                // Find the billing admin for this client (Admin, Super Admin, or System Admin)
                $adminUser = User::where('parent_id', $clientId)
                    ->whereIn('role', [1, 5, 6])
                    ->orderBy('user_level', 'desc')
                    ->first();

                // NEW QUERY: Join call_timers to get week_plan
                $outboundCampaign = Campaign::on('mysql_' . $clientId)
                    ->select('campaign.id', 'campaign.title', 'campaign.call_ratio', 'campaign.duration', 'campaign.hopper_mode', 'campaign.redirect_to', 'campaign.redirect_to_dropdown', 'campaign.amd_drop_action', 'campaign.voicedrop_option_user_id', 'campaign.amd', 'campaign.last_time_cron_run', 'campaign.dial_mode', 'campaign.group_id', 'call_timers.week_plan')
                    ->leftJoin('call_timers', 'call_timers.id', '=', 'campaign.call_schedule_id')
                    ->where('campaign.dial_mode', 'outbound_ai')
                    ->where('campaign.status', 1)
                    ->get()
                    ->all();

                $this->customLog("Campaigns found", ['clientId' => $clientId, 'count' => count($outboundCampaign)]);


                if (!empty($outboundCampaign)) {
                    foreach ($outboundCampaign as $details) {

                        $call_ratio  = $details->call_ratio;
                        $duration    = $details->duration;  //in sec
                        $campaign_id = $details->id;
                        $hopper_mode = $details->hopper_mode;
                        // Use admin extension if available, otherwise default
                        $extension   = $adminUser->extension ?? '37873'; 
                        $asterisk_server_id = 7;
                        $redirect_to = $details->redirect_to;
                        $redirect_to_dropdown = $details->redirect_to_dropdown;
                        $last_time_cron_run_db = $details->last_time_cron_run;


                        $add_duration_date = strtotime($last_time_cron_run_db) + $duration;
                        $add_duration_last_time_cron_run_db = date('Y-m-d H:i:s', $add_duration_date);

                        $timestamp1 = strtotime($last_time_cron_run);
                        $timestamp111 = date('Y-m-d H:i:s', $timestamp1);

                        $timestamp2 = strtotime($add_duration_last_time_cron_run_db);
                        $timestamp12 = date('Y-m-d H:i:s', $timestamp2);

                        if (is_null($last_time_cron_run_db)) {
                            $sql = "UPDATE campaign set last_time_cron_run = :last_time_cron_run WHERE id = :id";
                            DB::connection('mysql_' . $clientId)->update($sql, array('id' => $campaign_id, 'last_time_cron_run' => $last_time_cron_run));
                            $last_time_cron_run_db = $last_time_cron_run;
                        }

                        if ($timestamp1 > $timestamp2) {
                            $sql = "UPDATE campaign set last_time_cron_run = :last_time_cron_run WHERE id = :id";
                            DB::connection('mysql_' . $clientId)->update($sql, array(
                                'id' => $campaign_id, 'last_time_cron_run' =>
                                $add_duration_last_time_cron_run_db
                            ));
                            $this->customLog("Interval passed, allowed to dial", ['campaign_id' => $campaign_id]);
                        } else {
                            $this->customLog("Interval not passed, skipping campaign", ['campaign_id' => $campaign_id, 'next_run' => $add_duration_last_time_cron_run_db]);
                            continue; // Skip if duration not passed
                        }


                        $amd = $details->amd;
                        if ($amd == 1) {
                            $amd_drop_action = $details->amd_drop_action;
                            $amd_drop_message_output = $details->voicedrop_option_user_id;
                        } else {
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
                                $this->customLog("Easify credit check failed, skipping campaign", ['clientId' => $clientId, 'campaign_id' => $campaign_id, 'response' => $creditCheck]);
                                continue; // Skip this campaign for now
                            }
                            $this->customLog("Easify credit check successful", ['clientId' => $clientId, 'campaign_id' => $campaign_id]);
                        }

                        $requestData = array(
                            'hopper_mode' => $hopper_mode,
                            'extension' => $extension,
                            'asterisk_server_id' => $asterisk_server_id,
                            'clientId' => $clientId,
                            'campaign_id' => $campaign_id,
                            'redirect_to' => $redirect_to,
                            'redirect_to_dropdown' => $redirect_to_dropdown,
                        );
                        if ($adminUser) {
                            $this->customLog("Admin user identified", ['userId' => $adminUser->id, 'timezone' => $adminUser->timezone]);
                        } else {
                            $this->customLog("Admin user NOT found, using defaults", ['clientId' => $clientId]);
                        }

                        $dialer = new Dialer;
                        $cron = new Cron();
                        $template = new SmsTemplete();

                        // Decode Week Plan
                        $weekPlan = !empty($details->week_plan) ? json_decode($details->week_plan, true) : null;
                        
                        // Fallback Admin Timezone
                        $adminTimezone = $adminUser->timezone ?? 'US/Eastern';

                        $this->customLog("Starting lead processing loop", [
                            'campaign_id' => $campaign_id,
                            'call_ratio' => $call_ratio,
                            'adminTimezone' => $adminTimezone,
                            'hasWeekPlan' => !empty($weekPlan)
                        ]);

                        for ($i = 0; $i < $call_ratio; $i++) {
                            // Pass weekPlan and adminTimezone to a specific method
                            $addResponse = $dialer->addLeadToExtensionLiveOutboundAI($campaign_id, $hopper_mode, $extension, $asterisk_server_id, $clientId, $weekPlan, $adminTimezone);
                            $this->customLog("Lead retrieval response", ['iteration' => $i, 'response' => $addResponse]);
                            
                            if (isset($addResponse['code']) && $addResponse['code'] == 'NO_LEADS') {
                                $this->customLog("No leads available, attempting to refill hopper", ['campaign_id' => $campaign_id]);
                                $result = $cron->addLeadTemp($clientId, $campaign_id);
                                $this->customLog("Hopper refill result", ['result' => $result]);

                                // Descriptive summary for refill failure
                                if (isset($result['added']) && $result['added'] == 0) {
                                    $emptyLists = [];
                                    if (isset($result['lists']) && !empty($result['lists'])) {
                                        foreach ($result['lists'] as $listId => $stats) {
                                            if (($stats['records'] ?? 0) == 0) {
                                                $emptyLists[] = $listId;
                                            }
                                        }
                                        if (!empty($emptyLists)) {
                                            $this->customLog("REFILL FAILED: Leads found were 0 in associated lists: " . implode(', ', $emptyLists), ['campaign_id' => $campaign_id]);
                                        } else {
                                            $this->customLog("REFILL FAILED: Refill logic processed lists but added 0 leads (possibly due to filters or already in hopper).", ['campaign_id' => $campaign_id]);
                                        }
                                    } else {
                                        $this->customLog("REFILL FAILED: No active lists associated with this campaign.", ['campaign_id' => $campaign_id]);
                                    }
                                }
                                break;
                            }
                            
                            if ($addResponse === false) {
                                // NO valid leads found in this batch after retries (logic inside dialer)
                                // Trigger refill just in case
                                $result = $cron->addLeadTemp($clientId, $campaign_id);
                                break; 
                            }


                            $dialer = new Dialer;

                            $requestData['list_id'] = $addResponse['list_id'];
                            $requestData['lead_id'] = $addResponse['lead_id'];

                            if ($amd_drop_action == 1) { //hang up
                                $amd_drop_message_output = $amd_drop_message_output;
                            } else if ($amd_drop_action == 2) { //audio message
                                $amd_drop_message_output = $amd_drop_message_output;
                            } else if ($amd_drop_action == 3) { //voice template
                                $file_name = $template->changeVoiceMessageText($addResponse, $redirect_to, $amd_drop_message_output, $clientId);
                                $amd_drop_message_output = $file_name;
                            }

                            if ($redirect_to == 1) { //audio message
                                $file_name = $redirect_to_dropdown . '.wav';
                            } else if ($redirect_to == 2) { //voice template
                                $file_name = $template->changeVoiceMessageText($addResponse, $redirect_to, $redirect_to_dropdown, $clientId);
                            } else if ($redirect_to == 3) { //extension
                                $file_name = $redirect_to_dropdown;
                            } else if ($redirect_to == 4) { //ring group
                                $file_name = $redirect_to_dropdown;
                            } else if ($redirect_to == 5) { //ivr
                                $file_name = $redirect_to_dropdown . '.wav';
                            }

                            $requestData['file_name'] = $file_name;
                            $requestData['amd_drop_action'] = $amd_drop_action;
                            $requestData['amd_drop_message_output'] = $amd_drop_message_output;

                            $this->customLog("Initiating call", ['campaign_id' => $campaign_id, 'lead_id' => $requestData['lead_id'], 'file_name' => $file_name]);
                            $call = $dialer->outboundAIDialAsterisk($requestData);
                            $this->customLog("Call initiation response", ['response' => $call]);
                        }
                    }
                }
            }
            $this->customLog("--- Outbound Cron Finished ---");
        } catch (\Exception $e) {
            $this->customLog("Cron Error", ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            echo $e->getMessage();
        }
    }

    /**
     * Custom logging to storage/app/outbound_cron.log
     */
    private function customLog($message, $data = [])
    {
        try {
            $logPath = storage_path('app/outbound_cron.log');
            $timestamp = date('Y-m-d H:i:s');
            $logEntry = "[$timestamp] $message";
            if (!empty($data)) {
                $logEntry .= " " . json_encode($data);
            }
            $logEntry .= PHP_EOL;
            file_put_contents($logPath, $logEntry, FILE_APPEND);
        } catch (\Exception $e) {
            // Fallback to standard Laravel log if custom log fails
            Log::error("Failed to write to custom log: " . $e->getMessage());
        }
    }
}
