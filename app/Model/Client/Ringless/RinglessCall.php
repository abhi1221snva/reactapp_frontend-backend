<?php

namespace App\Model\Client\Ringless;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use DateTime;
use DateTimeZone;
use App\Model\Master\RvmCdrLog;
use App\Jobs\SendRvmJob;
use Carbon\Carbon;

use App\Jobs\SendVoicemailRinglessJob;

class RinglessCall extends Model
{

    protected $guarded = ['id'];
    function addLeadToRingless( $campaign,int $clientId) 
    {
        $response = ["status" => false,"code" => "NO_LEADS","message"=>"No leads in ringless_lead_temp table for campaign","data" => []];
        $db = $clientId;
        $lead = DB::connection('mysql_'.$db)->selectOne("SELECT * FROM ringless_lead_temp WHERE campaign_id = :campaign_id",['campaign_id' => $campaign->id]);

        $lead = (array)$lead;

        if (!empty($lead)) 
        {
            DB::connection('mysql_' . $db)->delete("DELETE FROM ringless_lead_temp WHERE campaign_id = :campaign_id AND lead_id = :lead_id",['campaign_id' => $campaign->id,'lead_id' => $lead['lead_id']]);

            if ($campaign->dialing_mode == 'ringless_voicemail') 
            {
                $sql = "SELECT column_name FROM ringless_list_header  WHERE list_id = :list_id AND is_dialling = :is_dialling";
                $listHeader = DB::connection('mysql_' . $db)->selectOne($sql, ['list_id' => $lead['list_id'], 'is_dialling' => 1]);
                $listHeader = (array)$listHeader;

                $sql = "SELECT * FROM ringless_list_data  WHERE id = :id";
                $listData = DB::connection('mysql_' . $db)->selectOne($sql, ['id' => $lead['lead_id']]);
                $listData = (array)$listData;

                $number = $listData[$listHeader['column_name']];
                $numStr2 = (string)$number; 
                $mobile = preg_replace('/[^0-9]/', '', $numStr2);

                $digitCount2 = strlen($mobile);
                if ($digitCount2 == 10)
                {
                    $merchant_number = $campaign->country_code.$mobile;
                    //echo '<pre>';print_r($merchant_number);die;
                    $cli = $campaign->custom_caller_id;
                    $phone = $merchant_number;
                    $clients = \App\Model\Master\Client::where('is_deleted',0)->where('id',$db)->get()->first();
                    $api_key = $clients->api_key;
                    $api_key  = explode('-',$clients->api_key);
                    $apiToken = $api_key[0];
                    $sip_gateways = \App\Model\Master\SipGateway\SipGateways::where('id',$campaign->sip_gateway_id)->get()->first();
                    $ringlessRequest['asterisk_server_id'] = $sip_gateways->asterisk_server_id;
                    $ringless_voice_file =\App\Model\Client\Ringless\RinglessIvr::on('mysql_'.$db)->where('id',$campaign->voice_template_id)->get()->first();

                    $ivr_id = $ringless_voice_file->ivr_id;
                    $voicemail_url = "https://api.voiptella.com/upload/ringless_files/".$ivr_id.'.wav';

                    $ringlessRequest['phone'] = $phone;
                    $ringlessRequest['cli'] = $cli;
                    $ringlessRequest['voicemail_url'] = $voicemail_url;
                    $ringlessRequest['api_key'] = $apiToken;
                    $ringlessRequest['userID'] = $db; //parent_id
                    $ringlessRequest['voicemail_id'] = $campaign->voice_template_id;
                    $ringlessRequest['callback_url'] = '';
                    $ringlessRequest['sip_trunk_name'] = $sip_gateways->sip_trunk_name;
                    $ringlessRequest['sip_trunk_host'] = $sip_gateways->sip_trunk_host;
                    $ringlessRequest['sip_trunk_username'] = $sip_gateways->sip_trunk_username;
                    $ringlessRequest['sip_trunk_password'] = $sip_gateways->sip_trunk_password;
                    $ringlessRequest['client_name'] = $sip_gateways->client_name;
                    $ringlessRequest['sip_trunk_provider'] = $sip_gateways->sip_trunk_provider;
                    $ringlessRequest['start_time'] = $campaign->call_time_start;
                    $ringlessRequest['end_time'] = $campaign->call_time_end;
                    $ringlessRequest['rvm_domain_id'] = $sip_gateways->rvm_domain_id;
                    $ringlessRequest['sip_gateway_id'] = $campaign->sip_gateway_id;
                    $ringlessRequest['voicemail_file_name'] = $ivr_id.'.wav';
                    $ringlessRequest['apiToken'] = $apiToken;
                    $ringlessRequest['user_id'] = $db; //parent_id

                    $startTime = $campaign->call_time_start;
                    $endTime = $campaign->call_time_end;

                    $rvmCdrLog = new RvmCdrLog();
                    $rvmCdrLog->cli = $ringlessRequest['cli'];
                    $rvmCdrLog->phone = $ringlessRequest['phone'];
                    $rvmCdrLog->api_token = $ringlessRequest['api_key'];
                    $rvmCdrLog->api_client_name =$ringlessRequest['api_key'];
                    $rvmCdrLog->rvm_domain_id = 24;
                    $rvmCdrLog->voicemail_id = $campaign->voice_template_id;
                    $rvmCdrLog->user_id = $db;
                    $rvmCdrLog->api_type = $campaign->id." (" .$campaign->title." )"; //campaign name
                    $rvmCdrLog->json_data = json_encode($ringlessRequest);
                    $rvmCdrLog->sip_gateway_id = $campaign->sip_gateway_id;
                    $rvmCdrLog->campaign_id = $campaign->id;
                    
                    //echo "<pre>";print_r($rvmCdrLog);die;
                    $rvmCdrLog->save();
                    $rvmCdrLog_id =  $rvmCdrLog->id;
                    $ringlessRequest['rvm_cdr_log_id'] = $rvmCdrLog_id;

                    $rvm_queue_list = rvmCdrLog::where('id',$rvmCdrLog->id)->get()->first();
                    $rvm_data = json_decode($rvm_queue_list->json_data);
                    $rvm_data->id = $rvmCdrLog->id;
                    $rvm_data->status_code = 'rvm_schedule_job_campaign';
                    $rvm_data->timezone_queue_trigger = 0; // check as per queue timezone


                    $number = preg_replace('/[^0-9]/', '', $ringlessRequest['phone']);
                    $last10Digit = substr($number, -10);
                    $return = ["dialable" => 0,"areacodeTimeZone" => 0,"dialingTime" => 0];

                    $numberAreacode = substr(trim($last10Digit), 0, 3);
                    $timeZone = $this->getTimezone($numberAreacode);

                    if (empty($timeZone)) 
                    {
                        $return["dialable"] = 1;
                        $return["dialingTime"] = 1;
                    }
                    else 
                    {
                        if (!empty($timeZone['timezone']))
                        {
                            $return["areacodeTimeZone"] = 1;
                            $time = new DateTime();
                            $time->setTimeZone(new DateTimeZone(timezone_name_from_abbr($timeZone['timezone'])));
                            $currentTime = $time->format('H:i:s');
                            if (strtotime($startTime) < strtotime($currentTime) && strtotime($endTime) > strtotime($currentTime)) 
                            {
                                $return["dialingTime"] = 1;
                                $return["dialable"] = 1;
                            }
                        }
                    }

                    if($return["dialable"] == 1)
                    {
                        dispatch((new SendRvmJob($rvm_data))->delay(Carbon::now()->addSeconds(5))->onConnection("database"));
                        $this->addToRinglessLeadReport('mysql_' . $db, $campaign->id, $lead['list_id'], $lead['lead_id'], $merchant_number,$campaign->custom_caller_id);
                    }
                    else
                    {
                        $rvmCdrLog->timezone_status = '0';
                        $rvmCdrLog->save();
                        $this->addToRinglessLeadReport('mysql_' . $db, $campaign->id, $lead['list_id'], $lead['lead_id'], $merchant_number,$campaign->custom_caller_id);
                    }

                    return $rvmCdrLog;
                } 
            } 
        }
        return $response;
    }

    public function addToRinglessLeadReport($db, $campaignId, $listId, $leadId, $merchant_number,$cli)
    {
        Log::info('reached',['db'=>$db]);
        $insertSql = "INSERT INTO ringless_lead_report (campaign_id, list_id, lead_id, merchant_number,cli) VALUE (:campaign_id, :list_id, :lead_id, :merchant_number,:cli)";
        Log::info('report reached',['insertSql'=>$insertSql]);
        return DB::connection($db)->insert($insertSql,array('campaign_id' => $campaignId,'list_id' => $listId,'lead_id' => $leadId,'merchant_number' => $merchant_number,'cli' => $cli));
    }

    public function getTimezone($numberAreacode)
    {
        $timeZone = DB::connection('master')->selectOne("SELECT timezone FROM timezone WHERE areacode = :areacode", array('areacode' => $numberAreacode));
        $timeZone = (array)$timeZone;
        return $timeZone;
    }
}
