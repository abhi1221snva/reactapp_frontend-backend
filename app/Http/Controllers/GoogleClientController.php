<?php

namespace App\Http\Controllers;
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
use App\Model\Client\ListHeader;
use App\Model\Cron;


class GoogleClientController extends Controller
{
    function voiceAudio(Request $request)
    {
        try
        {
            $clientId = 3;
            $outboundCampaign = Campaign::on('mysql_'.$clientId)->select('id','title','call_ratio','duration','hopper_mode','redirect_to','redirect_to_dropdown','amd_drop_action','voicedrop_option_user_id','amd')->where('dial_mode','outbound_ai')->where('status',1)->get()->all();
           // echo "<pre>";print_r($outboundCampaign);die;


            if(!empty($outboundCampaign)) 
            {
                foreach($outboundCampaign as $details)
                {
                    $call_ratio  = $details->call_ratio;
                    $duration    = $details->duration;  //in minute
                    $campaign_id = $details->id;
                    $hopper_mode = $details->hopper_mode;
                    $extension   = '37873';
                    $asterisk_server_id = 1;
                    $redirect_to = $details->redirect_to;
                    $redirect_to_dropdown = $details->redirect_to_dropdown; 

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

                    $requestData = array (
                        'hopper_mode'=>$hopper_mode,
                        'extension'=>$extension,
                        'asterisk_server_id'=>$asterisk_server_id,
                        'clientId'=>$clientId,
                        'campaign_id'=>$campaign_id,
                        'redirect_to'=>$redirect_to,
                        /*'amd_drop_action'=>$amd_drop_action,
                        'amd_drop_message'=>$amd_drop_message*/
                    );

                    // echo "<pre>";print_r($requestData);die;


                    $dialer = new Dialer;
                    $cron = new Cron();

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
                            $file_name = $this->changeVoiceMessageText($addResponse,$redirect_to,$amd_drop_message_output,$clientId);
                            $amd_drop_message_output = $file_name;
                        }

                        if($redirect_to == 1) //audio message
                        {
                            $file_name = $redirect_to_dropdown.'.wav';
                        }
                        else
                        if($redirect_to == 2) //voice template
                        {
                            $file_name = $this->changeVoiceMessageText($addResponse,$redirect_to,$redirect_to_dropdown,$clientId);
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

                        $requestData['file_name'] = $file_name;
                        $requestData['amd_drop_action'] = $amd_drop_action;
                        $requestData['amd_drop_message_output'] = $amd_drop_message_output;

                        //echo "<pre>";print_r($requestData);die;
                        $call = $dialer->outboundAIDialAsterisk($requestData);  
                        //echo "<pre>";print_r($call);die;
                        echo "call send successfully (IVR) for lead  =".$requestData['lead_id']."<br>";
                       
                    }
                }
            }
        }

        catch(Exception $e)
        {
            echo $e->getMessage();
        }
    }


    //for voice template text changes
    function changeVoiceMessageText($addResponse,$redirect_to,$redirect_to_dropdown,$clientId)
    {
        $templates = VoiceTemplate::on("mysql_".$clientId)->where('templete_id',$redirect_to_dropdown)->get()->first();
        $email_content = $templates->templete_desc;

        $new_array_custom = array();
        $lead_record = DB::connection('mysql_'.$clientId)->table('list_data')->where("id",$addResponse['lead_id'])->first();

        // Resolve a tenant user — scoped to the client, not hardcoded
        $user_detail = DB::connection('master')->table('users')
            ->where('parent_id', $clientId)
            ->where('is_deleted', 0)
            ->first();

        if(!empty($lead_record->list_id)) 
        {
            $list_header = DB::connection('mysql_'.$clientId)->table('list_header')->where("list_id","=",$lead_record->list_id)->orderBy("column_name")->get()->toArray();
            $lead_record = (array)($lead_record);

            foreach ($list_header as $key => $val) {
                $new_array[$val->header] = $lead_record[$val->column_name];
            }

            $user_detail = (array)($user_detail);

            foreach ($new_array as $key1 => $val) 
            {
                $replace = "{". $key1."}";
                $email_content = str_replace($replace, $val, $email_content);
            }

            foreach ($user_detail as $k1 => $vl1) 
            {
                $replace_key = "{". $k1."}";
                $email_content = str_replace($replace_key, $vl1, $email_content);
            }

            $custom_field_labels_values = CustomFieldLabelsValues::on("mysql_" . $clientId)->get();

            foreach ($custom_field_labels_values as $key => $val) 
            {
                $new_array_custom[$val['title_match']] = $val->title_links;
            }

            foreach ($new_array_custom as $key1 => $val) 
            {
                $replace_custom = "{".$key1."}";
                $email_content = str_replace($replace_custom, $val, $email_content);
            }

            preg_match_all("/\\{(.*?)\\}/", $email_content, $matches); 
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
                            $listHeader = ListHeader::on("mysql_" . $clientId)->where("label_id", "=", $lebel_id)->where("list_id", "=", $lead_record['list_id'])->first();

                            if(!empty($listHeader))
                            {
                                $column = $listHeader->column_name;
                                $value = $lead_record[$column];
                                $replace = $matches[0][$i];
                                $email_content = str_replace($replace, $value, $email_content);
                            }
                            else
                            {
                                $value ='';
                                $replace = $matches[0][$i];
                                $email_content = str_replace($replace, $value, $email_content);
                            }
                        }
                    }
                }
            }

            $email_content = str_replace('{', '', $email_content);
            $email_content = str_replace('}', '', $email_content);

            $new_str = preg_replace("/\s+/", " ", $email_content);
            //echo "<pre>";print_r($new_str);die;

            $uniqueId = rand(1000,9999);

            $text = $new_str;
            $voice = $templates->voice_name;//"en-US ## en-US-Standard-A ## MALE";//$request->voice_name_ddl;
            $voice_name_ddl = explode("##", $voice);
            $pitch = $templates->pitch;
            $speking_rate = $templates->speed;


            $langCode = trim($voice_name_ddl[0]);
            $stand_wave = trim($voice_name_ddl[1]);
            $gender = trim($voice_name_ddl[2]);

            $client = new TextToSpeechClient(['credentials' => env('GOOGLE_JSON_KEY')]);
            $input_text = (new SynthesisInput())
            //->setText($text);
            ->setSsml("<speak>".$text."</speak>");

            if($gender == 'FEMALE')
            {
                $voice = (new VoiceSelectionParams())
                ->setLanguageCode($langCode)
                ->setName($stand_wave)
                ->setSsmlGender(SsmlVoiceGender::FEMALE);
            }
            else
            {
                $voice = (new VoiceSelectionParams())
                ->setLanguageCode($langCode)
                ->setName($stand_wave)
                ->setSsmlGender(SsmlVoiceGender::MALE);
            }

            // Effects profile
            $effectsProfileId = "telephony-class-application";

            // select the type of audio file you want returned
            $audioConfig = (new AudioConfig())
            ->setAudioEncoding(AudioEncoding::MP3)
            ->setPitch($pitch)
            ->setSpeakingRate($speking_rate)
            ->setEffectsProfileId(array($effectsProfileId));

            $response = $client->synthesizeSpeech($input_text, $voice, $audioConfig);
            $audioContent = $response->getAudioContent();
            $file = $addResponse['list_id'].'_'.$addResponse['lead_id'].'_'.$uniqueId."_output.wav";

            $filePath = 'upload/voice_audio/'.$file;

            if(file_exists($filePath))
            {
                unlink($filePath);
            }
            file_put_contents($filePath, $audioContent);

            return $file;
        }
    }
    
}