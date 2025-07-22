<?php

namespace App\Model;

use App\Http\Helper\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use File;
use App\Model\Client\Label;
use App\Model\Client\CustomFieldLabelsValues;

use App\Model\Client\ListHeader;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Hash;

class VoiceTemplate  extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    //protected $guarded = ['id'];
    protected $primaryKey = 'templete_id';

    protected $table = 'voice_templete';
    /*
     *Fetch extension list
     *@param integer $id
     *@return array
     */
    public function voiceTempleteDetail($request)
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
                    'message' => 'Voice Template detail.',
                    'data'   => $data
                );
            }
            return array(
                'success' => 'false',
                'message' => 'Voice Template not created.',
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
    public function addVoiceTemplete($request)
    {
        try {


            if (
                $request->has('templete_name') && !empty($request->input('templete_name')) &&
                $request->has('templete_desc') && !empty($request->input('templete_desc'))

            ) {
                $data['templete_name'] = $request->input('templete_name');
                $data['templete_desc'] = $request->input('templete_desc');
                $data['language'] = $request->input('language');
                $data['voice_name'] = $request->input('voice_name');
                $data['speed'] = $request->input('speed');
                $data['pitch'] = $request->input('pitch');





                $query = "INSERT INTO " . $this->table . " 
                (templete_name,templete_desc,language,voice_name,pitch,speed) VALUE 
                (:templete_name, :templete_desc,:language,:voice_name,:pitch,:speed)";
                DB::connection('mysql_' . $request->auth->parent_id)->insert($query, $data);

                return array(
                    'success' => 'true',
                    'message' => 'Voice Templete added successfully.'
                );
            } else {
                return array(
                    'success' => 'false',
                    'message' => 'Voice templete not created. Required Details are missing',
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
    public function editVoiceTemplete($request)
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

                if ($request->has('language') && !empty($request->input('language'))) {
                    array_push($updateString, 'language = :language');
                    $data['language'] = $request->input('language');
                }

                if ($request->has('voice_name') && !empty($request->input('voice_name'))) {
                    array_push($updateString, 'voice_name = :voice_name');
                    $data['voice_name'] = $request->input('voice_name');
                }

                if ($request->has('pitch') && !empty($request->input('pitch'))) {
                    array_push($updateString, 'pitch = :pitch');
                    $data['pitch'] = $request->input('pitch');
                }

                if ($request->has('speed') && !empty($request->input('speed'))) {
                    array_push($updateString, 'speed = :speed');
                    $data['speed'] = $request->input('speed');
                }


                if (!empty($updateString)) {
                    $query = "UPDATE " . $this->table . " set " . implode(" , ", $updateString) . " WHERE templete_id = :templete_id";
                    //DB::connection('master')->update($query, $data);
                    DB::connection('mysql_' . $request->auth->parent_id)->update($query, $data);






                    return array(
                        'success' => 'true',
                        'message' => 'Voice Template updated successfully.'
                    );
                } else {
                    return array(
                        'success' => 'false',
                        'message' => 'Nothing to update.'
                    );
                }
                return array(
                    'success' => 'false',
                    'message' => 'VOice Template are not updated successfully.'
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

    public function deleteVoiceTemplete($request)
    {
        try {
            if ($request->has('templete_id') && is_numeric($request->input('templete_id'))) {
                $data['templete_id'] = $request->input('templete_id');
                $data['is_deleted'] = $request->input('is_deleted');



                $query = "UPDATE " . $this->table . " SET is_deleted = :is_deleted WHERE templete_id = :templete_id";

                $save =  DB::connection('mysql_' . $request->auth->parent_id)->update($query, $data);

                return array(
                    'success' => 'true',
                    'message' => 'Voice Templete deleted successfully.'
                );
            } else {
                return array(
                    'success' => 'false',
                    'message' => 'Voice Templete are not deleted successfully.'
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
        return $template;
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
}