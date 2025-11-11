<?php

namespace App\Model;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use File;
use App\Model\Client\Label;
use App\Model\Client\CrmLabel;
use App\Model\Client\Lead;



use App\Model\Client\CustomFieldLabelsValues;

use App\Model\Client\ListHeader;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Hash;
use App\Events\IncomingLead;
use Google\Cloud\TextToSpeech\V1\AudioConfig;
use Google\Cloud\TextToSpeech\V1\AudioEncoding;
use Google\Cloud\TextToSpeech\V1\SsmlVoiceGender;
use Google\Cloud\TextToSpeech\V1\SynthesisInput;
use Google\Cloud\TextToSpeech\V1\TextToSpeechClient;
use Google\Cloud\TextToSpeech\V1\VoiceSelectionParams;
use App\Model\VoiceTemplate;

class SmsTemplete  extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    //protected $guarded = ['id'];
    protected $primaryKey = 'templete_id';

    protected $table = 'sms_templete';
    /*
     *Fetch extension list
     *@param integer $id
     *@return array
     */
    public function smsTempleteDetail($request)
    {
        try {
            $data = array();
            $searchStr = array();
            if ($request->has('templete_id') && is_numeric($request->input('templete_id'))) {
                array_push($searchStr, 'templete_id = :templete_id');
                $data['templete_id'] = $request->input('templete_id');
            }

            $str = !empty($searchStr) ? "  WHERE " . implode(" AND ", $searchStr) : '';
            $sql = "SELECT * FROM " . $this->table . $str;
            $record =  DB::connection('mysql_' . $request->auth->parent_id)->select($sql, $data);
            $data = (array)$record;
            if (!empty($data)) {
                return array(
                    'success' => 'true',
                    'message' => 'Sms detail.',
                    'data'   => $data
                );
            }
            return array(
                'success' => 'false',
                'message' => 'Sms not found.',
                'data'   => array()
            );
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }
    /*
     *Add Extension
     *@param object $request
     *@return array
     */
    public function addSmsTemplete($request)
    {
        try {


            if (
                $request->has('templete_name') && !empty($request->input('templete_name')) &&
                $request->has('templete_desc') && !empty($request->input('templete_desc'))

            ) {
                $data['templete_name'] = $request->input('templete_name');
                $data['templete_desc'] = $request->input('templete_desc');


                $query = "INSERT INTO " . $this->table . " 
                (templete_name,templete_desc) VALUE 
                (:templete_name, :templete_desc)";
                DB::connection('mysql_' . $request->auth->parent_id)->insert($query, $data);

                return array(
                    'success' => 'true',
                    'message' => 'Sms Templete added successfully.'
                );
            } else {
                return array(
                    'success' => 'false',
                    'message' => 'Sms templete not created. Required Details are missing',
                    'data'   => $data
                );
            }
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }
    /*
     *Edit Extension
     *@param object $request
     *@return array
     */
    public function editSmsTemplete($request)
    {
        try {
            $data = array();
            $updateString = array();
            if ($request->has('templete_id') && !empty($request->input('templete_id'))) {
                $data['templete_id'] = $request->input('templete_id');
                if ($request->has('templete_name') && !empty($request->input('templete_name'))) {
                    array_push($updateString, 'templete_name = :templete_name');
                    $data['templete_name'] = $request->input('templete_name');
                }
                if ($request->has('templete_desc') && !empty($request->input('templete_desc'))) {
                    array_push($updateString, 'templete_desc = :templete_desc');
                    $data['templete_desc'] = $request->input('templete_desc');
                }


                if (!empty($updateString)) {
                    $query = "UPDATE " . $this->table . " set " . implode(" , ", $updateString) . " WHERE templete_id = :templete_id";
                    //DB::connection('master')->update($query, $data);
                    DB::connection('mysql_' . $request->auth->parent_id)->update($query, $data);






                    return array(
                        'success' => 'true',
                        'message' => 'Extension updated successfully.'
                    );
                } else {
                    return array(
                        'success' => 'false',
                        'message' => 'Nothing to update.'
                    );
                }
                return array(
                    'success' => 'false',
                    'message' => 'Extension are not updated successfully.'
                );
            }
            return array(
                'success' => 'false',
                'message' => 'Unable to update Extension. Required Details are missing',
                'data'   => $data
            );
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }

    public function deleteSmsTemplete($request)
    {
        try {
            if ($request->has('templete_id') && is_numeric($request->input('templete_id'))) {
                $data['templete_id'] = $request->input('templete_id');
                $data['is_deleted'] = $request->input('is_deleted');



                $query = "UPDATE " . $this->table . " SET is_deleted = :is_deleted WHERE templete_id = :templete_id";

                $save =  DB::connection('mysql_' . $request->auth->parent_id)->update($query, $data);

                return array(
                    'success' => 'true',
                    'message' => 'Sms Templete deleted successfully.'
                );
            } else {
                return array(
                    'success' => 'false',
                    'message' => 'Sms Templete are not deleted successfully.'
                );
            }
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }

    public function getEmailSmsList($request)
    {
        $template['success'] = true;
        $template['lead_id'] = $request->lead_id;
        $template['sms'] = DB::connection('mysql_' . $request->auth->parent_id)->table('sms_templete')->where('is_deleted',1)->orderBy("templete_name")->get()->toArray();
        $template['email'] = DB::connection('mysql_' . $request->auth->parent_id)->table('sms_templete')->where('is_deleted',1)->orderBy("templete_name")->get()->toArray();
        $template['crm_sms'] = DB::connection('mysql_' . $request->auth->parent_id)->table('crm_sms_templates')->where('status',1)->orderBy("template_name")->get()->toArray();
        return $template;
    }


    public function getSmsPreviewCRM($request)
    {
        $new_array_custom = array();

       // $lead_record = DB::connection('mysql_' . $request->auth->parent_id)->table('crm_lead_data')->where("id", $request->lead_id)->first();
                $lead_record = Lead::on("mysql_" . $request->auth->parent_id)->where('id', "=", $request->lead_id)->first();


        $user_detail = DB::connection('master')->table('users')->where("id", $request->auth->id)->first();
       Log::info('reached user detail',['user_detail'=>$user_detail]);
        //if (!empty($lead_record)) {
            //echo $lead_record->list_ifir
             // if (! empty($lead_record->list_id)) {
                    $list_header = CrmLabel::on("mysql_" . $request->auth->parent_id)->get();

      // return $this->successResponse("Template Info",  [$list_header]);


                    foreach ($list_header as $key => $val) {
                        $new_array[$val['label_title_url']] = $lead_record[$val['column_name']];
                    }

      /* return array(
                'success' => 'true',
                'message' => 'SMS Template Preview Details',
                'data'   => $new_array
            );*/


            $user_detail = (array)($user_detail);

            $tpl_record = DB::connection('mysql_' . $request->auth->parent_id)->table('crm_sms_templates')->where("id", $request->sms_tpl_id)->first();

            $email_content = $tpl_record->template_html;
            Log::info('reached template html',['email_content'=>$email_content]);


             $email_content = $tpl_record->template_html;
                    foreach ($new_array as $key1 => $val) {
                        $replace = "[[". $key1."]]";
                        $email_content = str_replace($replace, $val, $email_content);
                    }

                    

            foreach ($new_array as $key1 => $val) {
                $replace = "{". $key1."}";
                $email_content = str_replace($replace, $val, $email_content);
            }
            // foreach ($user_detail as $k1 => $vl1) {
            //     $replace_key = "[[". $k1."]]";
            //     $email_content = str_replace($replace_key, $vl1, $email_content);
            // }
            foreach ($user_detail as $k1 => $vl1) {
                // Handle [[key]] format
                $replace_key_brackets = "[[" . $k1 . "]]";
                $email_content = str_replace($replace_key_brackets, $vl1, $email_content);
                
                // Handle [key] format
                $replace_key_single = "[" . $k1 . "]";
                $email_content = str_replace($replace_key_single, $vl1, $email_content);
            }
            
            Log::info('reached user email_content',['email_content'=>$email_content]);

     /* return array(
                'success' => 'true',
                'message' => 'SMS Template Preview Details',
                'data'   => $email_content
            );
*/
            //custom filled labels

            $custom_field_labels_values = CustomFieldLabelsValues::on("mysql_" . $request->auth->parent_id)->get();
            foreach ($custom_field_labels_values as $key => $val) {
                $new_array_custom[$val['title_match']] = $val->title_links;
            }

            foreach ($new_array_custom as $key1 => $val) {
                $replace_custom = "{".$key1."}";
                $email_content = str_replace($replace_custom, $val, $email_content);
            }

            preg_match_all("/\\{(.*?)\\}/", $email_content, $matches); 
            //return $matches;
            if(!empty($matches[1]))
            {
                $count = count($matches[1]);
                if($count > 0)
                {
                    for($i=0;$i< $count ; $i++)
                    {
                        $pending_key =  $matches[1][$i];
                        $label = Label::on("mysql_" . $request->auth->parent_id)->where("title", "=", $pending_key)->first();
                        if(!empty($label))
                        {
                            $lebel_id = $label->id;
                            $listHeader = ListHeader::on("mysql_" . $request->auth->parent_id)->where("label_id", "=", $lebel_id)->where("list_id", "=", $lead_record['list_id'])->first();
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
            return $email_content;

            $templates['sms_content'] =$email_content;


           /*

            return array(
                'success' => 'true',
                'message' => 'SMS Template Preview Details',
                'data'   => $templates
            );*/

            //print_r($new_array);
       // }
        /*else
        {
            $tpl_record = DB::connection('mysql_' . $request->auth->parent_id)->table('sms_templete')->where("templete_id", $request->sms_tpl_id)->first();
            $email_content = $tpl_record->templete_desc;

            preg_match_all("/\\{(.*?)\\}/", $email_content, $matches); 
            //return $matches;
            $count = count($matches[1]);
            if($count > 0)
            {
                for($i=0;$i< $count ; $i++)
                {
                    //return $matches[1][$i];
                    $email_content = str_replace('{'.$matches[1][$i].'}', '', $email_content);
                }
            }
            //return $email_content;

            $templates['sms_content'] =$email_content;
            

           

            return array(
                'success' => 'true',
                'message' => 'SMS Template Preview Details CRM',
                'data'   => $templates
            );
        }*/
    }

    public function getSmsPreview($request)
    {
        $new_array_custom = array();

        $lead_record = DB::connection('mysql_' . $request->auth->parent_id)->table('list_data')->where("id", $request->lead_id)->first();

        $user_detail = DB::connection('master')->table('users')->where("id", $request->auth->id)->first();

        if (!empty($lead_record->list_id)) {
            //echo $lead_record->list_id;
            $list_header = DB::connection('mysql_' . $request->auth->parent_id)->table('list_header')->where("list_id", "=", $lead_record->list_id)->orderBy("column_name")->get()->toArray();
            $lead_record = (array)($lead_record);

            foreach ($list_header as $key => $val) {
                $new_array[$val->header] = $lead_record[$val->column_name];
            }
            $user_detail = (array)($user_detail);

            $tpl_record = DB::connection('mysql_' . $request->auth->parent_id)->table('sms_templete')->where("templete_id", $request->sms_tpl_id)->first();

            $email_content = $tpl_record->templete_desc;

            foreach ($new_array as $key1 => $val) {
                $replace = "{". $key1."}";
                $email_content = str_replace($replace, $val, $email_content);
            }
            foreach ($user_detail as $k1 => $vl1) {
                $replace_key = "{". $k1."}";

                $email_content = str_replace($replace_key, $vl1, $email_content);
            }

            //custom filled labels

            $custom_field_labels_values = CustomFieldLabelsValues::on("mysql_" . $request->auth->parent_id)->get();
            foreach ($custom_field_labels_values as $key => $val) {
                $new_array_custom[$val['title_match']] = $val->title_links;
            }

            foreach ($new_array_custom as $key1 => $val) {
                $replace_custom = "{".$key1."}";
                $email_content = str_replace($replace_custom, $val, $email_content);
            }

            preg_match_all("/\\{(.*?)\\}/", $email_content, $matches); 
            //return $matches;
            if(!empty($matches[1]))
            {
                $count = count($matches[1]);
                if($count > 0)
                {
                    for($i=0;$i< $count ; $i++)
                    {
                        $pending_key =  $matches[1][$i];
                        $label = Label::on("mysql_" . $request->auth->parent_id)->where("title", "=", $pending_key)->first();
                        if(!empty($label))
                        {
                            $lebel_id = $label->id;
                            $listHeader = ListHeader::on("mysql_" . $request->auth->parent_id)->where("label_id", "=", $lebel_id)->where("list_id", "=", $lead_record['list_id'])->first();
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
            return $email_content;
            //print_r($new_array);
        }
        else
        {
            $tpl_record = DB::connection('mysql_' . $request->auth->parent_id)->table('sms_templete')->where("templete_id", $request->sms_tpl_id)->first();
            $email_content = $tpl_record->templete_desc;

            preg_match_all("/\\{(.*?)\\}/", $email_content, $matches); 
            //return $matches;
            $count = count($matches[1]);
            if($count > 0)
            {
                for($i=0;$i< $count ; $i++)
                {
                    //return $matches[1][$i];
                    $email_content = str_replace('{'.$matches[1][$i].'}', '', $email_content);
                }
            }
            return $email_content;
        }
    }

     //for voice template text changes
    function changeVoiceMessageText($addResponse,$redirect_to,$redirect_to_dropdown,$clientId)
    {
        //echo $redirect_to_dropdown;die;
        $templates = VoiceTemplate::on("mysql_".$clientId)->where('templete_id',$redirect_to_dropdown)->get()->first();
        $email_content = $templates->templete_desc;

        $new_array_custom = array();
        $lead_record = DB::connection('mysql_'.$clientId)->table('list_data')->where("id",$addResponse['lead_id'])->first();

        $user_detail = DB::connection('master')->table('users')->where("id", '358')->first();

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
            $file = $addResponse['list_id'].'_'.$addResponse['lead_id'].'_'.$uniqueId."_output.mp3";

            $filePath = 'public/upload/voice_audio/'.$file;

            if(file_exists($filePath))
            {
                unlink($filePath);
            }
            file_put_contents($filePath, $audioContent);

            $filenameDb = $clientId.'_voice_temp_'. time();
            $rootPath = 'public/upload/voice_audio/';
            $tmpFilename = $file;
            $convertedFilename = $rootPath . $filenameDb . ".wav";

            $new_file = $filenameDb . ".wav";

            shell_exec("sox $rootPath$tmpFilename -r 8000 -c 1 $convertedFilename -q");
            if(file_exists($convertedFilename))
            {
                unlink($rootPath . $tmpFilename);
            }
            return $new_file;
        }
    }
}