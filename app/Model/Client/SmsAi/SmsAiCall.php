<?php

namespace App\Model\Client\SmsAi;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Model\Client\SmsAi\SmsAiTemplates;
use App\Model\Client\Label;


use DateTime;
use DateTimeZone;


class SmsAiCall extends Model
{
    protected $guarded = ['id'];

    function addLeadToSmsAi(int $campaignId,int $clientId) {
        $response = ["status" => false,"code" => "NO_LEADS","message" => "No leads in sms_ai_lead_temp table for campaign","data" => []];
        $db = $clientId;
        $lead = DB::connection('mysql_'.$db)->selectOne("SELECT * FROM sms_ai_lead_temp WHERE campaign_id = :campaign_id",['campaign_id' => $campaignId]);
        $lead = (array)$lead;

        if (!empty($lead)) {
            DB::connection('mysql_' . $db)->delete("DELETE FROM sms_ai_lead_temp WHERE campaign_id = :campaign_id AND lead_id = :lead_id",['campaign_id' => $campaignId,'lead_id' => $lead['lead_id']]);

            $campaignType = DB::connection('mysql_'.$db)->selectOne("SELECT dialing_mode,caller_id,custom_caller_id,country_code,sms_ai_template_id FROM sms_ai_campaign WHERE id = :id",['id' => $campaignId]);

            $dialMode = (array)$campaignType;
            
            //echo '<pre>';print_r($dialMode);die;

        $new_array_custom = array();


            if ($dialMode['dialing_mode'] == 'sms_ai') {

                $sql = "SELECT column_name FROM sms_ai_list_header  WHERE list_id = :list_id AND is_dialing = :is_dialing";
                $listHeader = DB::connection('mysql_' . $db)->selectOne($sql, ['list_id' => $lead['list_id'], 'is_dialing' => 1]);
                $listHeader = (array)$listHeader;

                //echo '<pre>';print_r($listHeader);die;

                $sql = "SELECT * FROM sms_ai_list_data  WHERE id = :id";
                $listData = DB::connection('mysql_' . $db)->selectOne($sql, ['id' => $lead['lead_id']]);
                $listData = (array)$listData;

                //echo '<pre>';print_r($listData);die;

                $number = $listData[$listHeader['column_name']];
                $numStr2 = (string)$number; 
                $mobile = preg_replace('/[^0-9]/', '', $numStr2);

                $digitCount2 = strlen($mobile);

                if ($digitCount2 == 10) {

                    $merchant_number = $dialMode['country_code'].$mobile;
                    $templates = SmsAiTemplates::on('mysql_'.$clientId)->findOrFail($dialMode['sms_ai_template_id']);
                    $cli = '+'.$dialMode['custom_caller_id'];
                    $merchant = '+'.$merchant_number;
                    $introduction = $templates->introduction;

                     $list_header = SmsAiListHeader::on("mysql_" . $clientId)->where("list_id", "=", $lead['list_id'])->get();

                    foreach ($list_header as $key => $val) {
                        $new_array[$val['header']] = $listData[$val['column_name']];
                    }

                     foreach ($new_array as $key1 => $val) {
                        $replace = "[[". $key1."]]";
                        $introduction = str_replace($replace, $val, $introduction);
                    }

                    preg_match_all("/\\[\\[(.*?)\\]\\]/", $introduction, $matches); 


               // echo '<pre>';print_r($matches[1]);die;

                    $description = $templates->description;







                    if(!empty($matches[1]))
                    {
                        $count = count($matches[1]);
                        if($count > 0)
                        {
                            for($i=0;$i< $count ; $i++)
                            {
                                $pending_key =  $matches[1][$i];
                                $label = Label::on("mysql_" . $clientId)->where("title", "=", $pending_key)->first();
                                if(!empty($label))
                                {
                                    $lebel_id = $label->id;
                                    $listHeader = SmsAiListHeader::on("mysql_" . $clientId)->where("label_id", "=", $lebel_id)->where("list_id", "=", $lead['list_id'])->first();


                                    if(!empty($listHeader))
                                    {
                                        $column = $listHeader->column_name;
                                        $value = $listData[$column];
                                        $replace = $matches[0][$i];
                                        $introduction = str_replace($replace, $value, $introduction);
                                    }
                                    else
                                    {
                                        $value ='';
                                        $replace = $matches[0][$i];
                                        $introduction = str_replace($replace, $value, $introduction);
                                    }
                                }
                                else
                                {
                                    $value ='';
                                    $replace = '[['.$pending_key.']]';
                                    $introduction = str_replace($replace, $value, $introduction);
                                }
                            }
                        }
                    }


            //  echo '<pre>';print_r($introduction);die;


                    $sql = "SELECT * FROM open_ai_chat_setting";
                    $setting = DB::connection('mysql_' . $db)->selectOne($sql);
                    $access_token = $setting->access_token;

                    $array = ['cli' => $cli,'number' => $merchant, 'introduction' => $introduction,'description' => $description];
                   // echo '<pre>';print_r($array);die;

                    if (app()->environment() == "local") {
                    }
                    else
                    {
                        $TELNYX_SMS_AI_URL   = env('TELNYX_SMS_AI_URL');

                        $sendSms = $TELNYX_SMS_AI_URL.'sms/send';
                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $sendSms);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            'accept: application/json',
                            'x-api-key: '.$access_token,
                            'Content-Type: application/json',
                        ]);

                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($array));
                        $response = curl_exec($ch);
                        curl_close($ch);
                    }

                    $response = true;

                    if ($response == true) {
                        $this->addToSmsAiLeadReport('mysql_' . $db, $campaignId, $lead['list_id'], $lead['lead_id'], $merchant_number,$dialMode['custom_caller_id']);
                        return array('status' => true, 'message' => "Message Sent successfully");
                    } else {
                        return array('status' => false, 'message' => "Unable to send message");
                    }
                } else {
                    $addResponse = $this->addLeadToSmsAi($campaignId,$clientId);
                    $response["dail_next_lead"] = $addResponse;
                }
            } 
        }
        return $response;
    }

    public function addToSmsAiLeadReport($db, $campaignId, $listId, $leadId, $merchant_number,$cli)
    {
        $insertSql = "INSERT INTO sms_ai_lead_report (campaign_id, list_id, lead_id, merchant_number,cli) VALUE (:campaign_id, :list_id, :lead_id, :merchant_number,:cli)";
        return DB::connection($db)->insert($insertSql,
            array(
                'campaign_id' => $campaignId,
                'list_id' => $listId,
                'lead_id' => $leadId,
                'merchant_number' => $merchant_number,
                'cli' => $cli
            )
        );
    }

  
   

   

   




  

   

  

  

   




  

}
