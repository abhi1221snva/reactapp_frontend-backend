<?php

namespace App\Model;
use App\Jobs\RecycleDeletedNotificationJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use App\Model\Hubspot\HubspotLists;
use App\Model\Client\CrmLists;



class Hubspot extends Model {

    protected $guarded = ['id'];
    protected $table = 'campaign';
    public $timestamps = false;


    public function addCampaignHubspot($request) {

        try {
            if ($request->has('title') && !empty($request->input('title'))) {

                $validate = $this->validateCampaign($request);
                $insertString = implode(" , ", $validate['string']);
                $data = $validate['data'];

                //echo "<pre>";print_r($insertString);die;
                $query = "INSERT INTO " . $this->table . " SET " . $insertString;
                $add = DB::connection('mysql_' . $request->auth->parent_id)->insert($query, $data);
                if ($add == true) {
                    $lastInsertId = DB::connection('mysql_' . $request->auth->parent_id)->selectOne("SELECT * FROM " . $this->table . " ORDER BY id DESC");

                    $campaignId = $lastInsertId->id;
                    if ($request->has('disposition_id') && is_array($request->input('disposition_id'))) {
                        foreach ($request->input('disposition_id') as $value) {
                            $sql = "INSERT INTO campaign_disposition (campaign_id, disposition_id) VALUE (:campaign_id, :disposition_id) ON DUPLICATE KEY UPDATE is_deleted = :is_deleted";
                            DB::connection('mysql_' . $request->auth->parent_id)->insert($sql, array('is_deleted' => 0, 'campaign_id' => $campaignId, 'disposition_id' => $value));
                        }
                    }


                    $crmLists = CrmLists::on("mysql_" . $request->auth->parent_id)->where('title_url','hubspot')->get()->first();
                    $api_url = $crmLists->url;
                    $api_key = $crmLists->key;


                    foreach($request->hubspot_lists as $list)
                    {
                        $url = $api_url.'contacts/v1/lists/'.$list;
                        $hapikey = $api_key;
                        $ch = curl_init($url);

                        $headers = array('Content-Type: application/json','Authorization: Bearer '.$hapikey);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                        
                        $response = curl_exec($ch);
                        if(curl_errno($ch)) {
                            throw new Exception(curl_error($ch));
                        }

                        curl_close($ch);
                        $lists_data = json_decode($response, true, JSON_UNESCAPED_SLASHES);

                        $title = $lists_data['name'];
                        $size = $lists_data['metaData']['size'];
                        $list_id = $list;


                        $query = "INSERT INTO  hubspot_campaign_list (campaign_id,list_id) VALUE (:campaign_id,:list_id)";
                        $add_hubspot_campaign_list = DB::connection('mysql_' . $request->auth->parent_id)->insert($query, ['campaign_id' => $campaignId, 'list_id' => $list_id ]);


                        $query = "INSERT INTO hubspot_lists (list_id,title,size) VALUE (:list_id,:title,:size)";
                        $add_hubspot_lists = DB::connection('mysql_' . $request->auth->parent_id)->insert($query, ['list_id' => $list_id,'title' => $title,'size' => $size]);
                    }

                    // add for new api

                    $this->copyApiByNewCampaign($request,$campaignId);

                    return array(
                        'success' => 'true',
                        'message' => 'Hubspot Campaign added successfully.',
                        'data' => (array) $lastInsertId
                    );
                } else {
                    return array(
                        'success' => 'false',
                        'message' => 'Campaign are not added successfully, Due to some incorrect value.'
                    );
                }
            }

            return array(
                'success' => 'false',
                'message' => 'Campaign are not added successfully. Title is missing'
            );
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }


     protected function validateCampaign($request) {
        $string = array();
        $data = array();
        if ($request->has('title') && !empty($request->input('title'))) {
            array_push($string, 'title = :title');
            $data['title'] = $request->input('title');
        }
        if ($request->has('description') && !empty($request->input('description'))) {
            array_push($string, 'description = :description');
            $data['description'] = $request->input('description');
        }
        if ($request->has('status') && is_numeric($request->input('status'))) {
            array_push($string, 'status = :status');
            $data['status'] = $request->input('status');
        }
        if ($request->has('caller_id') && !empty($request->input('caller_id')) && ($request->input('caller_id') == 'area_code' || $request->input('caller_id') == 'custom' || $request->input('caller_id') == 'area_code_random' || $request->input('caller_id') == 'area_code_3' || $request->input('caller_id') == 'area_code_4' || $request->input('caller_id') == 'area_code_5')) {
            array_push($string, 'caller_id = :caller_id');
            $data['caller_id'] = $request->input('caller_id');
        }
        if ($request->has('custom_caller_id') && is_numeric($request->input('custom_caller_id'))) {
            array_push($string, 'custom_caller_id = :custom_caller_id');
            $data['custom_caller_id'] = $request->input('custom_caller_id');
        }
        if ($request->has('time_based_calling') && is_numeric($request->input('time_based_calling'))) {
            array_push($string, 'time_based_calling = :time_based_calling');
            $data['time_based_calling'] = $request->input('time_based_calling');
        }
        if ($request->has('call_time_start') && !empty($request->input('call_time_start'))) {
            array_push($string, 'call_time_start = :call_time_start');
            $data['call_time_start'] = $request->input('call_time_start');
        }
        if ($request->has('call_time_end') && !empty($request->input('call_time_end'))) {
            array_push($string, 'call_time_end = :call_time_end');
            $data['call_time_end'] = $request->input('call_time_end');
        }
        if ($request->has('dial_mode') && !empty($request->input('dial_mode')) && ($request->input('dial_mode') == 'preview_and_dial' || $request->input('dial_mode') == 'power_dial' || $request->input('dial_mode') == 'super_power_dial' || $request->input('dial_mode') == 'predictive_dial') || $request->input('dial_mode') == 'outbound_ai') {
            array_push($string, 'dial_mode = :dial_mode');
            $data['dial_mode'] = $request->input('dial_mode');
        }
        if ($request->has('group_id') && is_numeric($request->input('group_id'))) {
            array_push($string, 'group_id = :group_id');
            $data['group_id'] = $request->input('group_id');
        }
        if ($request->has('max_lead_temp') && is_numeric($request->input('max_lead_temp')) && $request->input('max_lead_temp') < 1000) {
            array_push($string, 'max_lead_temp = :max_lead_temp');
            $data['max_lead_temp'] = $request->input('max_lead_temp');
        }
        if ($request->has('min_lead_temp') && !empty($request->input('min_lead_temp')) && $request->input('max_lead_temp') < 500) {
            array_push($string, 'min_lead_temp = :min_lead_temp');
            $data['min_lead_temp'] = $request->input('min_lead_temp');
        }
        if ($request->has('api') && is_numeric($request->input('api'))) {
            array_push($string, 'api = :api');
            $data['api'] = $request->input('api');
        }
        if ($request->has('is_deleted') && is_numeric($request->input('is_deleted'))) {
            array_push($string, 'is_deleted = :is_deleted');
            $data['is_deleted'] = $request->input('is_deleted');
        }
        if ($request->has('send_report') && is_numeric($request->input('send_report'))) {
            array_push($string, 'send_report = :send_report');
            $data['send_report'] = $request->input('send_report');
        }
        if ($request->has('send_crm')) {
            array_push($string, 'send_crm = :send_crm');
            $data['send_crm'] = $request->input('send_crm');
        }
        if ($request->has('email')) {
            array_push($string, 'email = :email');
            $data['email'] = $request->input('email');
        }
        if ($request->has('sms')) {
            array_push($string, 'sms = :sms');
            $data['sms'] = $request->input('sms');
        }
        
        if ($request->has('hopper_mode')) {
            array_push($string, 'hopper_mode = :hopper_mode');
            $data['hopper_mode'] = $request->input('hopper_mode');
        }

        if ($request->has('call_ratio')) {
            array_push($string, 'call_ratio = :call_ratio');
            $data['call_ratio'] = $request->input('call_ratio');
        }

        if ($request->has('duration')) {
            array_push($string, 'duration = :duration');
            $data['duration'] = $request->input('duration');
        }

        if ($request->has('automated_duration')) {
            array_push($string, 'automated_duration = :automated_duration');
            $data['automated_duration'] = $request->input('automated_duration');
        }

        if ($request->has('amd')) {
            array_push($string, 'amd = :amd');
            $data['amd'] = $request->input('amd');
        }
        if ($request->has('amd_drop_action')) {
            array_push($string, 'amd_drop_action = :amd_drop_action');
            $data['amd_drop_action'] = $request->input('amd_drop_action');
        }
        if ($request->has('voicedrop_option_user_id')) {
            array_push($string, 'voicedrop_option_user_id = :voicedrop_option_user_id');
            $data['voicedrop_option_user_id'] = $request->input('voicedrop_option_user_id');
        }

        if ($request->has('no_agent_available_action')) {
            array_push($string, 'no_agent_available_action = :no_agent_available_action');
            $data['no_agent_available_action'] = $request->input('no_agent_available_action');
        }

        if ($request->has('no_agent_dropdown_action')) {
            array_push($string, 'no_agent_dropdown_action = :no_agent_dropdown_action');
            $data['no_agent_dropdown_action'] = $request->input('no_agent_dropdown_action');
        }

         if ($request->has('redirect_to')) {
            array_push($string, 'redirect_to = :redirect_to');
            $data['redirect_to'] = $request->input('redirect_to');
        }

         if ($request->has('redirect_to_dropdown')) {
            array_push($string, 'redirect_to_dropdown = :redirect_to_dropdown');
            $data['redirect_to_dropdown'] = $request->input('redirect_to_dropdown');
        }

         if ($request->has('country_code')) {
            array_push($string, 'country_code = :country_code');
            $data['country_code'] = $request->input('country_code');
        }

        if ($request->has('voip_configuration_id')) {
            array_push($string, 'voip_configuration_id = :voip_configuration_id');
            $data['voip_configuration_id'] = $request->input('voip_configuration_id');
        }

        if ($request->has('crm_title_url')) {
            array_push($string, 'crm_title_url = :crm_title_url');
            $data['crm_title_url'] = $request->input('crm_title_url');
        }



        return array('string' => $string, 'data' => $data);
    }


     public function copyApiByNewCampaign($request,$campaignId)
     {
        $api_id = $request->api_id;
        $sql = "SELECT * FROM api  WHERE id = :id";
        $record =  DB::connection('mysql_'.$request->auth->parent_id)->selectOne($sql, array('id'=>$api_id));
        $data = (array)$record;
        $dataBase = 'mysql_'.$request->auth->parent_id;
        $recordData = array(
            'title'     =>$data['title'] ,
            'url'       =>$data['url'],
            'campaign_id'=>$campaignId,
            'method'    =>$data['method']  ,
            'is_deleted'=>$data['is_deleted']
        );
        
        $insert_id =  DB::connection('mysql_'.$request->auth->parent_id)->table('api')->insertGetId($recordData);
        $save_data = true;
        $disposition = "SELECT * FROM api_disposition where api_id= :api_id ";
        $recordDisposition =  DB::connection('mysql_'.$request->auth->parent_id)->select($disposition, array('api_id'=>$api_id));
        $dataDisposition = (array)$recordDisposition;
        if(count($dataDisposition)>0){
            foreach($recordDisposition as $key=>$val){
                $h_list['disposition_id']   = $val->disposition_id;
                $h_list['api_id']           = $insert_id;
                $h_list['is_deleted']       = $val->is_deleted;
                $disposition_list[]         = $h_list;
            }
            $save_data &= DB::connection($dataBase)->table('api_disposition')->insert($disposition_list);
        }else{
            $save_data = false;
        }

        $apiParameter = "SELECT * FROM api_parameter where api_id= :api_id ";
        $recordApiParameter =  DB::connection('mysql_'.$request->auth->parent_id)->select($apiParameter, array('api_id'=>$api_id));
        $dataApiParameter = (array)$recordApiParameter;
        if(count($dataApiParameter)>0){
            foreach($dataApiParameter as $key1=>$val1){
                $ap_list['api_id']      = $insert_id;
                $ap_list['type']        = $val1->type;
                $ap_list['parameter']   = $val1->parameter;
                $ap_list['value']       = $val1->value;
                $ap_list['is_deleted']  = $val1->is_deleted;
                $parameter_list[]       = $ap_list;
            }
            $save_data &= DB::connection($dataBase)->table('api_parameter')->insert($parameter_list);
        }else{
            $save_data = false;
        }

        if($save_data){
            return array(
                'success'=> 'true',
                'message'=> 'New API added successfully.',
                'list_id' => $insert_id,
            );
        }else{
            return array(
                'success'=> 'false',
                'message'=> 'Api not added. Unable to add data in API table'
            );
        }
    }
    


    //  function getCampaignAndListHubspot($request) {

    //     try {
    //         $data = array();
    //         $searchStr = array();
    //         if ($request->has('campaign_id') && is_numeric($request->input('campaign_id'))) {
    //             $data['campaign_id'] = $request->input('campaign_id');
    //             $data['is_deleted'] = $request->input('is_deleted');
    //         }

    //         $sql = "SELECT campaign_list.campaign_id,campaign_list.status,campaign_list.list_id,campaign_list.is_deleted,list.title as l_title,list.id,campaign.title,campaign.crm_title_url,list.size as rowListData FROM hubspot_campaign_list as campaign_list inner join hubspot_lists as list on campaign_list.list_id = list.list_id  inner join campaign on campaign_list.campaign_id = campaign.id WHERE campaign_list.campaign_id = '" . $request->input('campaign_id') . "' and campaign_list.is_deleted ='" . $request->input('is_deleted') . "'";

    //         $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql, $data);
    //         $data = (array) $record;

    //         foreach ($data as $key => $id) {

    //             $data1['campaign_id'] = $id->campaign_id;
    //             $data1['list_id'] = $id->list_id;

    //             $sql_count_lead_report = "SELECT count(1) as rowCountLearReport FROM lead_report WHERE campaign_id = :campaign_id  and list_id = :list_id";
    //             $record_count_lead = DB::connection('mysql_' . $request->auth->parent_id)->selectOne($sql_count_lead_report, $data1);
    //             $id->rowLeadReport = $record_count_lead->rowCountLearReport;

    //             $list_data['list_id'] = $id->list_id;


    //             /*$sql_count_list = "SELECT count(1) as rowCountList FROM list_data WHERE list_id=:list_id ";
    //             $record_count_list = DB::connection('mysql_' . $request->auth->parent_id)->select($sql_count_list, $list_data);

    //             //return $data = (array)$record_count_list;
    //             //$id->rowList = $count;
    //             $id->rowListData = $record_count_list[0]->rowCountList;*/
    //         }

    //         //return $data;
    //         if (!empty($data)) {
    //             return array(
    //                 'success' => 'true',
    //                 'message' => 'Campaign List detail.',
    //                 'data' => $data
    //             );
    //         }
    //         return array(
    //             'success' => 'false',
    //             'message' => 'Campaign List Found.',
    //             'data' => array()
    //         );
    //     } catch (Exception $e) {
    //         echo $e->getMessage();
    //     } catch (InvalidArgumentException $e) {
    //         echo $e->getMessage();
    //     }
    // }
function getCampaignAndListHubspot($request)
{
    try {

        // -----------------------------
        // VALIDATION
        // -----------------------------
        if (!$request->has('campaign_id') || !is_numeric($request->input('campaign_id'))) {
            return [
                'success' => 'false',
                'message' => 'Invalid campaign_id',
                'data' => []
            ];
        }

        $campaignId = (int) $request->input('campaign_id');
        $isDeleted  = ($request->has('is_deleted') && $request->input('is_deleted') !== '')
            ? (int) $request->input('is_deleted')
            : 0;

        // -----------------------------
        // SQL WITH PLACEHOLDERS
        // -----------------------------
        $sql = "
            SELECT 
                cl.campaign_id,
                cl.status,
                cl.list_id,
                cl.is_deleted,
                l.title AS l_title,
                l.id,
                c.title,
                c.crm_title_url,
                l.size AS rowListData
            FROM hubspot_campaign_list cl
            INNER JOIN hubspot_lists l ON cl.list_id = l.list_id
            INNER JOIN campaign c ON cl.campaign_id = c.id
            WHERE cl.campaign_id = :campaign_id
              AND cl.is_deleted = :is_deleted
        ";

        // -----------------------------
        // EXECUTE
        // -----------------------------
        $records = DB::connection('mysql_' . $request->auth->parent_id)
            ->select($sql, [
                'campaign_id' => $campaignId,
                'is_deleted'  => $isDeleted
            ]);

        // -----------------------------
        // ADD COUNTS
        // -----------------------------
        foreach ($records as $row) {

            $leadCount = DB::connection('mysql_' . $request->auth->parent_id)
                ->selectOne(
                    "SELECT COUNT(1) AS total 
                     FROM lead_report 
                     WHERE campaign_id = :campaign_id 
                       AND list_id = :list_id",
                    [
                        'campaign_id' => $row->campaign_id,
                        'list_id'     => $row->list_id
                    ]
                );

            $row->rowLeadReport = $leadCount->total ?? 0;
        }

        return [
            'success' => 'true',
            'message' => 'Campaign List detail.',
            'data' => $records
        ];

    } catch (\Throwable $e) {
        return [
            'success' => 'false',
            'message' => $e->getMessage(),
            'data' => []
        ];
    }
}

    //close hubspot

}
