<?php

namespace App\Model;

use App\Model\Dids;
use App\Model\Master\Did;
use App\Model\Client\FaxDid;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use App\Model\Client\SmsProviders;
use Illuminate\Support\Facades\Log;
use Plivo\RestClient;
use Plivo\Exceptions\PlivoRestException;

class Dids extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];
    protected $table = 'did';
    public $timestamps = false;


    /*
     *Fetch List by email
     *@param integer $id
     *@return array
     */

    public function getListByEmailId($request)
    {

        $emailId = $request->input("id");
        try {

            $sql = "SELECT * FROM " . $this->table . " where sms_email='" . $emailId . "'";
            $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql);
            $data = (array)$record;
            if (!empty($data)) {
                return array(
                    'success' => 'true',
                    'message' => 'Lists detail.',
                    'data' => $data
                );
            }

            return array(
                'success' => 'false',
                'message' => 'Lists not created.',
                'data' => array()
            );
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }

    /*
     *Fetch List
     *@param integer $id
     *@return array
     */


public function getList($request)
{
    try {
        $database = 'mysql_' . $request->auth->parent_id;

        $sql = "SELECT * FROM " . $this->table . " WHERE is_deleted = '0'";

        // Apply search
        if ($request->has('search') && !empty($request->input('search'))) {
            $search = $request->input('search');
            $sql .= " AND cli = '" . $search . "'";
        }

        // Apply pagination (start, limit)
        if ($request->has('start') && $request->has('limit')) {
            $start = (int) $request->input('start');
            $limit = (int) $request->input('limit');
            $sql .= " LIMIT $start, $limit";
        }

        $record = DB::connection($database)->select($sql);
        $data = (array) $record;
foreach ($data as &$row) {
    // SMS case
    if (isset($row->sms) && $row->sms == 1 && !empty($row->sms_email)) {
        $row->assigned_user = $row->sms_email;
        unset($row->sms_email);
    }

    // Destination mapping based on dest_type
    if (isset($row->dest_type)) {
        switch ($row->dest_type) {
            case 1:
                if (!empty($row->extension)) {
                    $row->destination = $row->extension;
                    unset($row->extension);
                }
                break;
            case 2:
                if (!empty($row->voicemail_id)) {
                    $row->destination = $row->voicemail_id;
                    unset($row->voicemail_id);
                }
                break;
            case 4:
                if (!empty($row->forward_number)) {
                    $row->destination = $row->forward_number;
                    unset($row->forward_number);
                }
                break;
            case 5:
                if (!empty($row->conf_id)) {
                    $row->destination = $row->conf_id;
                    unset($row->conf_id);
                }
                break;
            case 8:
                if (!empty($row->ingroup)) {
                    $row->destination = $row->ingroup;
                    unset($row->ingroup);
                }
                break;
        }
    }
}
        if (!empty($data)) {
            return [
                'success' => 'true',
                'message' => 'Did detail.',
                'data' => $data
            ];
        }

        return [
            'success' => 'false',
            'message' => 'DId not found',
            'data' => []
        ];
    } catch (Exception $e) {
        Log::error('Error in getList: ' . $e->getMessage());
        return [
            'success' => 'false',
            'message' => 'Something went wrong.',
            'data' => []
        ];
    }
}



    public function getList_old($request)
    {
        try {

            $sql = "SELECT * FROM " . $this->table . " where is_deleted='0' ";
            //$sql = "SELECT * FROM " . $this->table;

            $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql);
            $data = (array)$record;
            if (!empty($data)) {
                return array(
                    'success' => 'true',
                    'message' => 'Lists detail.',
                    'data' => $data
                );
            }

            return array(
                'success' => 'false',
                'message' => 'Lists not created.',
                'data' => array()
            );
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }


    /*
     *Edit List
     *@param object $request
     * @return array
     */
    public function editList($request) {}

    /*
     *Add List
     *@param object $request
     *@return array
     */
    public function addList($request)
    {
        if ($request->has('cli')) {
            //$user_data = DB::connection('mysql_'.$request->auth->parent_id)->where('cli', '19027063135')->first();
            $cli = $request->input("cli");
            $query = 'SELECT count(1) as row_count from did WHERE cli="' . $cli . '" ';
            $countObj = collect(DB::connection('mysql_' . $request->auth->parent_id)->select($query))->first();
            if ($countObj->row_count == 0) {

                $data['cli'] = $request->input('cli');
                $data['cnam'] = $request->input('cnam');
                $data['area_code'] = $request->input('area_code');
                $data['country_code'] = $request->input('country_code');
                $data['dest_type'] = $request->input('dest_type');
                $data['ivr_id'] = $data['dest_type'] == 0 ? $request->input('ivr_id') : '';
                $data['extension'] = $data['dest_type'] == 1 ? $request->input('extension') : '';
                $data['voicemail_id'] = $data['dest_type'] == 2 ? $request->input('voicemail_id') : '';
                $data['forward_number'] = $data['dest_type'] == 4 ? $request->input('forward_number') : '';
                $data['conf_id'] = $data['dest_type'] == 5 ? $request->input('conf_id') : '';
                $data['ingroup'] = $data['dest_type'] == 8 ? $request->input('ingroup') : '';
                $data['operator'] = $request->input('operator_check') != '' ? $request->input('operator') : '';
                $data['default_did'] = $request->input('default_did');
                $data['voice'] = $request->input('option_1') == 'v' ? '1' : '';
                $data['fax'] = $request->input('option_1') == 'f' ? '1' : '';
                $data['sms'] = $request->input('is_sms');
                $data['sms_phone'] = $data['sms'] == '1' ? $request->input('sms_phone') : '';
                $data['sms_email'] = $data['sms'] == '1' ? $request->input('sms_email') : '';
                $data['set_exclusive_for_user'] = $request->input('set_exclusive_for_user');

                //call screening audio file
                $data['call_screening_status'] = $request->input('call_screening_status');
                $data['call_screening_ivr_id'] = $request->input('call_screening_ivr_id');
                //$didObj->ann_id = $request->ann_id;
                $data['language'] = $request->input('language');
                $data['voice_name'] = $request->input('voice_name');
                $data['ivr_audio_option'] = $request->input('ivr_audio_option');
                $data['speech_text'] = $request->input('speech_text');
                $data['prompt_option'] = $request->input('prompt_option');
                $data['redirect_last_agent'] = $request->input('redirect_last_agent');
                $data['sms_type'] = $request->input('sms_type');
                $data['voip_provider'] = $request->input('voip_provider');

                if ($data['sms']) //Active and forward SMS for did
                {
                    $this->activateSMS($data['cli']);
                    $this->forwardSMS($data['cli']);
                } else {
                    $this->deactivateSMS($data['cli']);
                }

                //Out of Hours data
                $data['call_time_department_id']    =  $request->input('call_time_department_id');
                $data['call_time_holiday']          =  $request->input('call_time_holiday');
                $data['dest_type_ooh']          =  $request->input('dest_type_ooh');
                $data['ivr_id_ooh']             =  $request->input('dest_type_ooh') == 0 ? $request->input('ivr_id_ooh') : '';
                $data['extension_ooh']          =  $request->input('dest_type_ooh') == 1 ? $request->input('extension_ooh') : '';
                $data['voicemail_id_ooh']       =  $request->input('dest_type_ooh') == 2 ? $request->input('voicemail_id_ooh') : '';
                $data['forward_number_ooh']     =  $request->input('dest_type_ooh') == 4 ? $request->input('forward_number_ooh') : '';
                $data['conf_id_ooh']            =  $request->input('dest_type_ooh') == 5 ? $request->input('conf_id_ooh') : '';
                $data['ingroup_ooh']            =  $request->input('dest_type_ooh') == 8 ? $request->input('ingroup_ooh') : '';

                $query = "INSERT INTO did (cli,cnam,area_code,dest_type,ivr_id,extension,voicemail_id,"
                    . "forward_number,country_code,conf_id,ingroup,operator,default_did,voice,fax,voip_provider,sms,sms_phone,sms_email,"
                    . "call_time_department_id, call_time_holiday, dest_type_ooh, ivr_id_ooh, extension_ooh, "
                    . "voicemail_id_ooh, forward_number_ooh, conf_id_ooh, ingroup_ooh,set_exclusive_for_user,call_screening_status,call_screening_ivr_id,language,voice_name,ivr_audio_option,speech_text,prompt_option,redirect_last_agent,sms_type) "
                    . "VALUE "
                    . "(:cli,:cnam,:area_code,:dest_type,:ivr_id,:extension,:voicemail_id,:forward_number,:country_code,:conf_id,"
                    . ":ingroup,:operator,:default_did,:voice,:fax,:voip_provider,:sms,:sms_phone,:sms_email,"
                    . ":call_time_department_id, :call_time_holiday, :dest_type_ooh, :ivr_id_ooh, :extension_ooh, "
                    . ":voicemail_id_ooh, :forward_number_ooh, :conf_id_ooh, :ingroup_ooh ,:set_exclusive_for_user,:call_screening_status,:call_screening_ivr_id,:language,:voice_name,:ivr_audio_option,:speech_text,:prompt_option,:redirect_last_agent,:sms_type"
                    . ")";

                $add = DB::connection('mysql_' . $request->auth->parent_id)->update($query, $data);
                $data2['parent_id'] = $request->auth->parent_id;
                $data2['cli'] = $request->input('cli');
                $data2['user_id'] = $request->auth->id;
                $data2['area_code'] = $request->input('area_code');
                $data2['country_code'] = $request->input('country_code');
                $data2['provider'] = 1;
                $data2['voip_provider'] = $request->input('voip_provider');
                $query2 = "INSERT INTO did (parent_id,cli,user_id,area_code,"
                    . "country_code,provider,voip_provider)"
                    . "VALUE "
                    . "(:parent_id,:cli,:user_id,:area_code,:country_code,:provider,"
                    . ":voip_provider"
                    . ")";
                $addMaster = DB::connection('master')->update($query2, $data2);
                if ($request->option_1 == 'f' && !empty($request->fax_did)) {
                    foreach ($request->fax_did as $key => $value) {
                        if ($cli == '') {
                            continue;
                        }
                        FaxDid::on('mysql_' . $request->auth->parent_id)->insert(
                            array('userId' =>  $value, 'did' => $request->cli, 'created_at' => date('Y-m-d h:i:s'))
                        );
                    }
                }

                if ($add == true) {
                    $lastInsertId = DB::connection('mysql_' . $request->auth->parent_id)->selectOne("SELECT * FROM " . $this->table . " where cli='" . $data['cli'] . "' ");
                    $didInsertedId = $lastInsertId->id;

                    //update default did set
                    if ($request->input('default_did') == '1') {
                        $data_default['id'] = $didInsertedId;
                        $query_default = "UPDATE did set default_did='' WHERE id != :id";
                        DB::connection('mysql_' . $request->auth->parent_id)->update($query_default, $data_default);
                    }

                    return array(
                        'success' => 'true',
                        'message' => 'Did added successfully.',
                        'data' => (array)$lastInsertId
                    );
                }
            } else {
                return array(
                    'success' => 'false',
                    'message' => 'Cli already in list'
                );
            }
        }
    }

    function didDetail($request)
    {
        if ($request->has('did')) {
            $editId = $request->input('did');
            $query = 'SELECT count(1) as row_count from did WHERE id="' . $editId . '" ';
            $countObj = collect(DB::connection('mysql_' . $request->auth->parent_id)->select($query))->first();
            if ($countObj->row_count > 0) {
                $query = 'SELECT * from did WHERE id="' . $editId . '" ';
                $countObj = collect(DB::connection('mysql_' . $request->auth->parent_id)->select($query))->first();
                return array(
                    'success' => 'true',
                    'message' => 'Did detail available.',
                    'data' => (array)$countObj
                );
            } else {
                return array(
                    'success' => 'false',
                    'message' => 'Did not available in list'
                );
            }
        }
    }

    function saveEdit($request)
    {
        if ($request->has('did_id')) {
            $did_id = $request->input("did_id");
            $cli = $request->input("cli");
            $checkDid = Dids::on('mysql_' . $request->auth->parent_id)->where('id', '<>', $request->did_id)->where('cli', $request->cli)->get()->toarray();

            //$query = 'SELECT count(1) AS row_count FROM did WHERE id !="' . $did_id . '" AND cli ="' . $cli . '"   ';
            //$countObj = collect(DB::connection('mysql_' . $request->auth->parent_id)->select($query))->first();

            if (!$checkDid) {
                $didObj = Dids::on('mysql_' . $request->auth->parent_id)->find($request->did_id);
                $didObj->cli                =  $request->cli;
                $didObj->cnam               =  $request->cnam;
                $didObj->area_code          =  $request->area_code;
                $didObj->dest_type          =  $request->dest_type;
                $didObj->ivr_id             =  $request->dest_type == 0 ? $request->ivr_id : '';
                $didObj->extension          =  $request->dest_type == 1 ? $request->extension : '';
                $didObj->voicemail_id       =  $request->dest_type == 2 ? $request->voicemail_id : '';
                $didObj->forward_number     =  $request->dest_type == 4 ? $request->forward_number : '';
                $didObj->country_code       =  $request->dest_type == 4 ? $request->country_code : '';
                $didObj->conf_id            =  $request->dest_type == 5 ? $request->conf_id : '';
                $didObj->ingroup            =  $request->dest_type == 8 ? $request->ingroup : '';
                $didObj->operator           =  $request->operator_check != '' ? $request->operator : '';
                $didObj->default_did        =  $request->default_did;
                $didObj->voice              =  $request->option_1 != '' ? '1' : '';
                $didObj->fax                =  $request->option_1 == '' ? '1' : '';
                $didObj->sms                =  $request->sms != '' ? 1 : 0;
                $didObj->sms_phone          =  $request->sms != '' ? $request->sms_phone : '';
                $request_fax                = $didObj->fax;
                $didObj->sms_email          =   $request->sms != '' ? $request->sms_email : '';
                //$didObj->fax_did            =   $request->fax_did;
                $didObj->set_exclusive_for_user = $request->set_exclusive_for_user;

                //call screening audio file
                $didObj->call_screening_status = $request->call_screening_status;
                $didObj->call_screening_ivr_id = $request->call_screening_ivr_id;
                //$didObj->ann_id = $request->ann_id;
                $didObj->language = $request->language;
                $didObj->voice_name = $request->voice_name;
                $didObj->ivr_audio_option = $request->ivr_audio_option;
                $didObj->speech_text = $request->speech_text;
                $didObj->prompt_option = $request->prompt_option;
                $didObj->redirect_last_agent = $request->redirect_last_agent;
                $didObj->sms_type = $request->sms_type;
                $didObj->voip_provider = $request->voip_provider;





                if ($didObj->sms) //Active and forward SMS for did
                {
                    $this->activateSMS($didObj->cli);
                    $this->forwardSMS($didObj->cli);
                } else {
                    $this->deactivateSMS($didObj->cli);
                }

                if ($request->dest_type == 6) {
                    $this->forwardDidToFaxUrl($didObj->cli); //DId forward fax url api
                } else {
                    $this->configDidToIp($didObj->cli, $request); //set did to ip on voice
                }

                //Out of Hours data
                $didObj->call_time_department_id    =  $request->call_time_department_id;
                $didObj->call_time_holiday          =  $request->call_time_holiday;
                $didObj->dest_type_ooh          =  $request->dest_type_ooh;
                $didObj->ivr_id_ooh             =  $request->dest_type_ooh == 0 ? $request->ivr_id_ooh : '';
                $didObj->extension_ooh          =  $request->dest_type_ooh == 1 ? $request->extension_ooh : '';
                $didObj->voicemail_id_ooh       =  $request->dest_type_ooh == 2 ? $request->voicemail_id_ooh : '';
                $didObj->forward_number_ooh     =  $request->dest_type_ooh == 4 ? $request->forward_number_ooh : '';
                $didObj->country_code_ooh       =  $request->dest_type_ooh == 4 ? $request->country_code_ooh : '';

                $didObj->conf_id_ooh            =  $request->dest_type_ooh == 5 ? $request->conf_id_ooh : '';
                $didObj->ingroup_ooh            =  $request->dest_type_ooh == 8 ? $request->ingroup_ooh : '';

                $editRecord = $didObj->save();

                if ($request->sms_type == '1') {
                    if ($didObj->voip_provider == 'telnyx') {
                        $TELNYX_SMS_AI_TOKEN = env('TELNYX_SMS_AI_TOKEN');
                        $TELNYX_SMS_AI_URL   = env('TELNYX_SMS_AI_URL');
                        $TELNYX_SMS_AI_WEBHOOK   = env('TELNYX_SMS_AI_WEBHOOK');

                        $sms_setting = SmsProviders::on('mysql_' . $request->auth->parent_id)->where("status", '1')->where('provider', 'telnyx')->get()->first();
                        $telnyx_api_key = $sms_setting->api_key;

                        $addCli = $TELNYX_SMS_AI_URL . 'sms/user-cli';
                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $addCli);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                        curl_setopt($ch, CURLOPT_HTTPHEADER, ['accept:application/json', 'x-api-key: ' . $TELNYX_SMS_AI_TOKEN, 'Content-Type: application/json',]);

                        $array = ['cli' => '+' . $request->cli, 'webhook' => $TELNYX_SMS_AI_WEBHOOK, 'telnyx_key' => $telnyx_api_key, 'telnyx_public_key' => 'string'];
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($array));
                        $response = curl_exec($ch);
                        curl_close($ch);
                    } else
                    if ($didObj->voip_provider == 'twilio') {
                        $TELNYX_SMS_AI_TOKEN = env('TELNYX_SMS_AI_TOKEN');
                        $TELNYX_SMS_AI_URL   = env('TELNYX_SMS_AI_URL');
                        $TELNYX_SMS_AI_WEBHOOK   = env('TELNYX_SMS_AI_WEBHOOK') . '?provider=twilio';

                        $sms_setting = SmsProviders::on('mysql_' . $request->auth->parent_id)->where("status", '1')->where('provider', 'twilio')->get()->first();
                        $twilio_api_key = $sms_setting->api_key;
                        $twilio_auth_id = $sms_setting->auth_id;


                        $addCli = $TELNYX_SMS_AI_URL . 'sms/user-cli';
                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $addCli);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                        curl_setopt($ch, CURLOPT_HTTPHEADER, ['accept:application/json', 'x-api-key: ' . $TELNYX_SMS_AI_TOKEN, 'Content-Type: application/json',]);

                        $array = ['cli' => '+' . $request->cli, 'webhook' => $TELNYX_SMS_AI_WEBHOOK, 'twilio_account_sid' => $twilio_auth_id, 'twilio_auth_token' => $twilio_api_key];
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($array));
                        $response = curl_exec($ch);
                        curl_close($ch);
                    }
                }

                //update for set default did
                if ($request->default_did == '1') {
                    $data['id'] = $request->did_id;
                    $query = "UPDATE did set default_did='' WHERE id != :id";
                    DB::connection('mysql_' . $request->auth->parent_id)->update($query, $data);
                }
                //$data_fax['cli'] = $cli;
                //$query_did = "DELETE FROM fax_did WHERE did= :cli ";
                //$deleteRecord = DB::connection('mysql_' . $request->auth->parent_id)->update($query_did, $data_fax);

                if ($request->option_1 == '' && !empty($request->fax_did)) {
                    $faxDid = FaxDid::on('mysql_' . $request->auth->parent_id)->where('did', $request->cli)->delete();
                    foreach ($request->fax_did as $key => $value) {
                        if ($cli == '') {
                            continue;
                        }
                        FaxDid::on('mysql_' . $request->auth->parent_id)->insert(
                            array('userId' =>  $value, 'did' => $cli, 'created_at' => date('Y-m-d h:i:s'))
                        );
                    }
                }


                if (!empty($request->sms_email) && !empty($request->sms_email)) {
                    Did::where('cli', $request->cli)->update(['user_id' => $request->sms_email]);
                }

                if ($editRecord == true) {
                    $lastInsertId = DB::connection('mysql_' . $request->auth->parent_id)->selectOne("SELECT * FROM " . $this->table . " where id='" . $request->did_id . "' ");
                    //$didInsertedId =  $lastInsertId->id;
                    return array(
                        'success' => 'true',
                        'message' => 'Phone Number has been updated successfully.',
                        'data' => (array)$lastInsertId
                    );
                }
            } else {
                return array(
                    'success' => 'false',
                    'message' => 'Phone Number already in list'
                );
            }
        } else {
            return array(
                'success' => 'false',
                'message' => 'Phone Number already in list'
            );
        }
    }



    public function deleteDid($request)
    {
        if ($request->has('did_id')) {
            $deleteId = $request->input('did_id');
            $data['did_id'] = $deleteId;
            $query = "DELETE FROM did WHERE id= :did_id ";
            $deleteRecord = DB::connection('mysql_' . $request->auth->parent_id)->update($query, $data);
            if ($deleteRecord == true) {
                return array(
                    'success' => 'true',
                    'message' => 'Phone Number delete successfully.'
                );
            } else {
                return array(
                    'success' => 'false',
                    'message' => 'Phone Number not deleted in list'
                );
            }
        } else {
            return array(
                'success' => 'false',
                'message' => 'Phone Number id is missing in list'
            );
        }
    }

    public function getListCount($request)
    {
        try {

            $sql = "SELECT count(1) as rowCount FROM " . $this->table;
            $record = DB::connection('mysql_' . $request->auth->parent_id)->selectOne($sql);
            $data = (array)$record;
            if ($data > 0) {
                return array(
                    'success' => 'true',
                    'message' => 'User count',
                    'data' => $data
                );
            } else {
                return array(
                    'success' => 'true',
                    'message' => 'User count not found',
                    'data' => 0
                );
            }

            return array(
                'success' => 'false',
                'message' => 'Lists not created.',
                'data' => array()
            );
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }

    public function getEmployeeDirectory($request)
    {
        $userData = array();
        $counter = 0;
        $sql = "select * from users where parent_id ={$request->auth->parent_id} and is_deleted=0  order by created_at desc Limit 8 ";
        $record = DB::connection('master')->select($sql);
        $data = (array)$record;
        if (count($data) > 0) {
            foreach ($data as $key => $val) {
                $addNewRecord = strtotime(date('Y-m-d', strtotime($val->created_at)));
                $today = strtotime(date('Y-m-d'));
                if ($addNewRecord >= $today) {
                    ++$counter;
                    $show_date = 'Today';
                } else {
                    $show_date = Date('d M');
                }
                $userData[] = array('id' => $val->id, 'date_show' => $show_date, 'first_name' => $val->first_name, 'last_name' => $val->last_name, 'extension' => $val->extension);
            }
        }

        if (count($userData) > 0) {
            return array(
                'success' => 'true',
                'message' => 'Get extension count',
                'data' => array('user_data' => $userData, 'new_member' => $counter)
            );
        } else {
            return array(
                'success' => 'true',
                'message' => 'extension count not found',
                'data' => array('user_data' => 0, 'new_member' => 0)
            );
        }

        return array(
            'success' => 'false',
            'message' => 'extension count not created.',
            'data' => array()
        );
    }

    function getInboundCountAvg($request)
    {
        try {
            $start = date('Y-m-d', strtotime('-1 day', strtotime($request->start_date)));
            $end = date('Y-m-d', strtotime('+1 day', strtotime($request->end_date)));
            $search = array();
            $searchString = array();
            $searchString1 = array();

            $start_date = $request->start_date;
            $end_date = $request->end_date;
            $route = $request->route;
            $type = $request->type;

            $search['start_time'] = $start;
            $search['end_time'] = $end;
            $search['route'] = $route;
            $search['type'] = $type;

            array_push($searchString, 'route = :route');
            array_push($searchString, 'type = :type');

            $search['start_time1'] = $start;
            $search['end_time1'] = $end;
            $search['route1'] = $route;
            $search['type1'] = $type;

            array_push($searchString, 'start_time BETWEEN :start_time AND :end_time');
            array_push($searchString1, 'start_time BETWEEN :start_time1 AND :end_time1');

            array_push($searchString1, 'route = :route1');
            array_push($searchString1, 'type = :type1');

            $filter = (!empty($searchString)) ? " WHERE " . implode(" AND ", $searchString) : '';
            $filter1 = (!empty($searchString1)) ? " WHERE " . implode(" AND ", $searchString1) : '';

            $sql = "select  * from ((SELECT AVG(duration)  as rowCount FROM cdr_archive " . $filter . " ) UNION ALL (SELECT AVG(duration)  as rowCount FROM cdr " . $filter1 . ")) as t  ";

            $record = DB::connection('mysql_' . $request->auth->parent_id)->selectOne($sql, $search);
            $data = (array)$record;
            if ($data['rowCount'] > 0) {
                return array(
                    'success' => 'true',
                    'message' => 'Average for ' . $request->type,
                    'data' => $data['rowCount']
                );
            } else {
                return array(
                    'success' => 'true',
                    'message' => 'Average not found' . $request->type,
                    'data' => 0
                );
            }

            return array(
                'success' => 'false',
                'message' => 'Lists not created.',
                'data' => array()
            );
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }

    function faxDidList($request)
    {
        try {
            return FaxDid::on('mysql_' . $request->auth->parent_id)->where(['did' => $request->did])->get();
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }

    function faxDidUserList($request)
    {
        try {
            return FaxDid::on('mysql_' . $request->auth->parent_id)->where(['userId' => $request->auth->id])->get();
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }

    /**
     * Save Did
     * @param type $request
     * @return type
     */

    function buySaveDidPlivo($request)
    {
        foreach ($request->data['number'] as $objNumber) {
            $objNumberDecoded = json_decode($objNumber);
            $result = $this->buyDidFromPlivo($request->data['country_code'], $objNumberDecoded, $request);
            /*  if($result['status'])
            {
            }*/

            $this->saveDIdPLIVO($request, $objNumberDecoded->value);
        }

        return array(
            'success' => 'true',
            'message' => 'Phone Number has been added successfully.',
            'data' => []
        );
    }

    function buySaveDidTelnyx($request)
    {
        foreach ($request->data['number'] as $objNumber) {
            $objNumberDecoded = json_decode($objNumber);
            $sms_setting = SmsProviders::on('mysql_' . $request->auth->parent_id)->where("status", '1')->where('provider', 'telnyx')->get()->first();
            $phone_number = $objNumberDecoded->value;

            $number = [
                "phone_numbers" => [
                    ["phone_number" => $phone_number]
                ]
            ];

            $telnyxApiKey = $sms_setting->api_key;

            //check balance

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, 'https://api.telnyx.com/v2/balance');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $telnyxApiKey,
            ]);

            $result = curl_exec($ch);
            curl_close($ch);
            $res = json_decode($result);
            $balance =  $res->data->balance;
            //$balance =0.01;

            if ($balance >= 0.20) {
            } else {
                return array(
                    'success' => 'false',
                    'message' => 'Telnyx Balance is Low',
                    'data' => array()
                );
            }


            //echo $hell;die;


            $ch = curl_init();
            $send = json_encode($number);
            curl_setopt($ch, CURLOPT_URL, 'https://api.telnyx.com/v2/number_orders');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $send);

            $headers = array();
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Accept: application/json';
            $headers[] = 'Authorization: Bearer ' . $telnyxApiKey;

            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            $response = curl_exec($ch);

            $this->saveDIdTelnyx($request, $objNumberDecoded->value);
        }

        return array(
            'success' => 'true',
            'message' => 'Phone Number has been added successfully.',
            'data' => []
        );
    }


    function buySaveDidTwilio($request)
    {
        foreach ($request->data['number'] as $objNumber) {
            $objNumberDecoded = json_decode($objNumber);
            $sms_setting = SmsProviders::on('mysql_' . $request->auth->parent_id)->where("status", '1')->where('provider', 'twilio')->get()->first();
            $phone_number = $objNumberDecoded->value;

            $number = [
                "phone_numbers" => [
                    ["phone_number" => $phone_number]
                ]
            ];

            $telnyxApiKey = $sms_setting->api_key;

            /* $ch = curl_init();
        $send = json_encode($number);
        curl_setopt($ch, CURLOPT_URL, 'https://api.telnyx.com/v2/number_orders');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $send);

        $headers = array();
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';
        $headers[] = 'Authorization: Bearer '. $telnyxApiKey;

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($ch);*/

            $sid = $sms_setting->auth_id;
            $token = $sms_setting->api_key;

            $twilio = new \Twilio\Rest\Client($sid, $token);
            $incoming_phone_number = $twilio->incomingPhoneNumbers
                ->create(["phoneNumber" => $phone_number]);

            //print($incoming_phone_number->sid);

            $this->saveDIdTwilio($request, $objNumberDecoded->value);
        }

        return array(
            'success' => 'true',
            'message' => 'Phone Number has been added successfully.',
            'data' => []
        );
    }


    private function saveDIdTelnyx($request, $number, $provider = 1)
    {
        $country_code = $request->data['country_code'];
        $data['parent_id'] = $request->auth->parent_id;
        // $data['cli'] = str_replace('+','',$number);

        // $data['area_code'] = substr($number, 1, 3);
        $cleanNumber = str_replace('+', '', $number); // "13465918900"
        $data['cli'] = $cleanNumber;
        $data['area_code'] = substr($cleanNumber, 1, 3); // works if country code is 1 digit
        $data['country_code'] = "+" . $country_code;
        $data['provider'] = $request->data['provider'];
        $data['voip_provider'] = $request->data['voip_provider'];

        $query = "INSERT INTO did (parent_id,cli,area_code,country_code,provider,voip_provider) "
            . "VALUE "
            . "(:parent_id,:cli,:area_code,:country_code,:provider,:voip_provider)";
        DB::connection('master')->update($query, $data);

        $data = [];
        $data['cli'] = $cleanNumber;
        $data['area_code'] = substr($cleanNumber, 1, 3);
        $data['voip_provider'] = $request->data['voip_provider'];

        $query = "INSERT INTO did (cli,area_code,voip_provider) "
            . "VALUE "
            . "(:cli,:area_code,:voip_provider)";
        DB::connection('mysql_' . $request->auth->parent_id)->update($query, $data);
    }


    private function saveDIdTwilio($request, $number, $provider = 1)
    {
        $country_code = $request->data['country_code'];


        $data['parent_id'] = $request->auth->parent_id;
        // $data['cli'] = str_replace('+','',$number);
        // $data['area_code'] = substr($number, 1, 3);
        $cleanNumber = str_replace('+', '', $number); // "13465918900"
        $data['cli'] = $cleanNumber;
        $data['area_code'] = substr($cleanNumber, 1, 3); // works if country code is 1 digit

        $data['country_code'] = "+" . $country_code;
        $data['provider'] = $request->data['provider'];
        $data['voip_provider'] = $request->data['voip_provider'];

        $query = "INSERT INTO did (parent_id,cli,area_code,country_code,provider,voip_provider) "
            . "VALUE "
            . "(:parent_id,:cli,:area_code,:country_code,:provider,:voip_provider)";
        DB::connection('master')->update($query, $data);

        $data = [];
        $data['cli'] = $cleanNumber;
        $data['area_code'] = substr($cleanNumber, 1, 3);
        $data['voip_provider'] = $request->data['voip_provider'];

        $query = "INSERT INTO did (cli,area_code,voip_provider) "
            . "VALUE "
            . "(:cli,:area_code,:voip_provider)";
        DB::connection('mysql_' . $request->auth->parent_id)->update($query, $data);
    }


    function buySaveDid($request)
    {
        foreach ($request->data['number'] as $objNumber) {
            $objNumberDecoded = json_decode($objNumber);
            $result = $this->buyDidFromSale($request->data['country_code'], $objNumberDecoded, $request);
            if ($result['status']) {
                $this->saveDId($request, $objNumberDecoded->value);
            }
        }

        return array(
            'success' => 'true',
            'message' => 'Phone Number has been added successfully.',
            'data' => []
        );
    }

    /**
     * Buy numbers from didforsale.com
     * @param type $country_code
     * @param type $number
     * @param type $ip
     * @return type
     */

    private function buyDidFromPlivo($countryCode, $objNumber, $request)
    {

        $sms_setting = SmsProviders::on('mysql_' . $request->auth->parent_id)->where("status", '1')->where('provider', 'plivo')->get()->first();

        $auth_id = $sms_setting->auth_id;
        $api_key = $sms_setting->api_key;


        $auth_id = $sms_setting->auth_id;
        $api_key = $sms_setting->api_key;

        $client = new RestClient($auth_id, $api_key);

        $response = $client->phonenumbers->buy($objNumber->value);
        $this->configDidToIp($countryCode . $objNumber->value, $request);
        return $response;
    }

    private function buyDidFromTelnyx($countryCode, $objNumber, $request)
    {

        $sms_setting = SmsProviders::on('mysql_' . $request->auth->parent_id)->where("status", '1')->where('provider', 'telnyx')->get()->first();

        return array(
            'success' => 'true',
            'message' => 'Phone Number has been added successfully.',
            'data' => $sms_setting
        );

        $auth_id = $sms_setting->auth_id;
        $api_key = $sms_setting->api_key;


        $auth_id = $sms_setting->auth_id;
        $api_key = $sms_setting->api_key;

        $client = new RestClient($auth_id, $api_key);

        $response = $client->phonenumbers->buy($objNumber->value);
        $this->configDidToIp($countryCode . $objNumber->value, $request);
        return $response;
    }



    private function buyDidFromSale($countryCode, $objNumber, $request)
    {
        $url = env('DID_SALE_API_URL') . "products/BuyDID?ratecenter=$objNumber->ratecenter&state=$objNumber->state&did=$objNumber->value&reference_id=$objNumber->referenceid&didtype=metered";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array("Accept: application/json", "Authorization: Basic " . base64_encode(env('DID_SALE_SERVICE_KEY') . ':' . env('DID_SALE_SERVICE_TOKEN'))));
        $response = curl_exec($ch);
        $response = json_decode($response, 1);

        $this->configDidToIp($countryCode . $objNumber->value, $request);
        return $response;
    }

    /**
     * Save bought did data in DB
     * @param type $request
     * @param type $number
     * @param type $provider
     */
    private function saveDId($request, $number, $provider = 1)
    {
        $country_code = $request->data['country_code'];
        $data['parent_id'] = $request->auth->parent_id;
        $data['cli'] = $country_code . $number;
        $data['area_code'] = substr($number, 0, 3);
        $data['country_code'] = "+" . $country_code;
        $data['provider'] = $request->data['provider'];
        $data['voip_provider'] = $request->data['voip_provider'];

        $query = "INSERT INTO did (parent_id,cli,area_code,country_code,provider,voip_provider) "
            . "VALUE "
            . "(:parent_id,:cli,:area_code,:country_code,:provider,:voip_provider)";
        DB::connection('master')->update($query, $data);

        $data = [];
        $data['cli'] = $country_code . $number;
        $data['area_code'] = substr($number, 0, 3);
        $data['voip_provider'] = $request->data['voip_provider'];

        $query = "INSERT INTO did (cli,area_code,voip_provider) "
            . "VALUE "
            . "(:cli,:area_code,:voip_provider)";
        DB::connection('mysql_' . $request->auth->parent_id)->update($query, $data);
    }


    private function saveDIdPLIVO($request, $number, $provider = 1)
    {
        $country_code = $request->data['country_code'];
        $data['parent_id'] = $request->auth->parent_id;
        $data['cli'] = $number;
        $data['area_code'] = substr($number, 1, 3);
        $data['country_code'] = "+" . $country_code;
        $data['provider'] = $request->data['provider'];
        $data['voip_provider'] = $request->data['voip_provider'];

        $query = "INSERT INTO did (parent_id,cli,area_code,country_code,provider,voip_provider) "
            . "VALUE "
            . "(:parent_id,:cli,:area_code,:country_code,:provider,:voip_provider)";
        DB::connection('master')->update($query, $data);

        $data = [];
        $data['cli'] = $number;
        $data['area_code'] = substr($number, 1, 3);
        $data['voip_provider'] = $request->data['voip_provider'];

        $query = "INSERT INTO did (cli,area_code,voip_provider) "
            . "VALUE "
            . "(:cli,:area_code,:voip_provider)";
        DB::connection('mysql_' . $request->auth->parent_id)->update($query, $data);
    }

    /**
     * Get asterisk server detail
     * @param type $request
     * @return type
     */
    private function getAsteriskServerDetails($request)
    {
        $hostIp = '';
        $sql = "select ip_address from client_server where client_id ={$request->auth->parent_id}  Limit 1 ";
        $record = DB::connection('master')->select($sql);
        $data = (array)$record;
        if (isset($data[0]->ip_address)) {
            $sql = "select host from asterisk_server where id = " . $data[0]->ip_address . "  Limit 1 ";
            $record = DB::connection('master')->select($sql);
            $data = (array)$record;
            if (isset($data[0]->host)) {
                $hostIp = $data[0]->host;
            }
        }
        return $hostIp;
    }

    /**
     * Active SMS
     * @param type $request
     * @return string
     */
    private function activateSMS($did)
    {
        $result = [];
        $url = env('DID_SALE_API_URL') . "SMS/ActivateSMS";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, ['did' => $did]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array("Accept: application/json", "Authorization: Basic " . base64_encode(env('DID_SALE_SERVICE_KEY') . ':' . env('DID_SALE_SERVICE_TOKEN'))));
        $result = curl_exec($ch);
        $result = json_decode($result, 1);

        return $result;
    }

    /**
     * DeActive SMS
     * @param type $request
     * @return string
     */
    private function deactivateSMS($did)
    {
        $result = [];
        $url = env('DID_SALE_API_URL') . "SMS/DeactivateSMS";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, ['did' => $did]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array("Accept: application/json", "Authorization: Basic " . base64_encode(env('DID_SALE_SERVICE_KEY') . ':' . env('DID_SALE_SERVICE_TOKEN'))));
        $result = curl_exec($ch);
        $result = json_decode($result, 1);

        return $result;
    }

    /**
     * Forward SMS
     * @param type $request
     * @return string
     */
    private function forwardSMS($did)
    {
        $result = [];
        $url = env('DID_SALE_API_URL') . "SMS/Forward";
        $forwardTo = env('DID_FORWARD_SMS_URL');
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, ['from' => $did, 'action' => 'update', 'forward_to' => $forwardTo]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array("Accept: application/json", "Authorization: Basic " . base64_encode(env('DID_SALE_SERVICE_KEY') . ':' . env('DID_SALE_SERVICE_TOKEN'))));
        $result = curl_exec($ch);
        $result = json_decode($result, 1);

        return $result;
    }

    /**
     * Forward Fax to Url
     * @param type $request
     * @return string
     */
    private function forwardDidToFaxUrl($did)
    {
        $res = [];
        $url = env('DID_SALE_API_URL') . "products/ManageDID/ForwardToUrl";
        $forwardUrl = env('DID_FORWARD_FAX_URL');
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['did' => [$did], 'forward_url' => $forwardUrl, 'callerid' => "none"]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array("Accept: application/json", "Authorization: Basic " . base64_encode(env('DID_SALE_SERVICE_KEY') . ':' . env('DID_SALE_SERVICE_TOKEN'))));
        $result = curl_exec($ch);
        $result = json_decode($result, 1);
        return $result;
    }

    /**
     * Forward DID to Url
     * @param type $request
     * @return string
     */
    private function configDidToIp($did, $request)
    {
        $ip = $this->getAsteriskServerDetails($request);
        $result = [];
        $url = env('DID_SALE_API_URL') . "products/ManageDID/config1";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['did' => [$did], 'ip1' => $ip, 'ip1_port' => "5060"]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array("Accept: application/json", "Authorization: Basic " . base64_encode(env('DID_SALE_SERVICE_KEY') . ':' . env('DID_SALE_SERVICE_TOKEN'))));
        $result = curl_exec($ch);
        $result = json_decode($result, 1);

        return $result;
    }
       public function getDetailById($request, $id)
{
    try {
        $sql = "SELECT * FROM " . $this->table . " 
                WHERE is_deleted = '0' AND id = :id";

        $record = DB::connection('mysql_' . $request->auth->parent_id)
            ->select($sql, ['id' => $id]);

        $data = (array) $record;

        if (!empty($data)) {
            return [
                'success' => 'true',
                'message' => 'Did detail.',
                'data'    => $data[0] ?? $data, // since you are fetching single record
            ];
        }

        return [
            'success' => 'false',
            'message' => 'Record not found.',
            'data'    => [],
        ];
    } catch (Exception $e) {
        Log::error($e->getMessage());
    } catch (InvalidArgumentException $e) {
        Log::error($e->getMessage());
    }
}
}
