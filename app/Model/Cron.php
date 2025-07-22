<?php

namespace App\Model;

use App\Jobs\LowLeadNotificationJob;
use App\Model\Client\SystemNotification;
use App\Model\Master\Client;
use DateTime;
use DateTimeZone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Cron extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];
    protected $database = '';

    /*
     *Add records to temp table
     *@param object $request
     *@return array
     */
    public function addLeadTemp($id, $campaignId)
    {
        try {
            $this->database = 'mysql_' . $id;
            $campaign = DB::connection($this->database)->selectOne("SELECT id,min_lead_temp,max_lead_temp,call_time_start,call_time_end,time_based_calling,hopper_mode FROM campaign WHERE id = :campaign_id", array('campaign_id' => $campaignId));
            $campaign = (array)$campaign;

            //echo "<pre>";print_r($campaign);die;

            //get leads from lead_temp table
            $getLead = $this->getTempLead($campaignId);

           // echo "<pre>";print_r($getLead);die;


            //find how many new records need to added to temp table.
            $tempLeadCount = count($getLead);
            $hopperCount = [
                "valid" => $tempLeadCount,
                "invalid" => 0
            ];

            $minimumLead = $campaign['min_lead_temp'];
            $maximumLead = $campaign['max_lead_temp'];

            #if hopper is not maintaining count
            if ($tempLeadCount < $minimumLead) {
                $addRecord = $maximumLead - $tempLeadCount;
            } else {
                $addRecord = 0;
            }

            $startTime = $campaign['call_time_start'];
            $endTime = $campaign['call_time_end'];

            //check campaign time based calling
            if ($campaign['time_based_calling'] == '1' && $addRecord > 0) {
                //validate campaign start time
                if (!empty($startTime) && !empty($endTime) && strtotime($startTime) < strtotime($endTime)) {
                    #Loop through all the existing leads in hopper
                    #Check if they can still be dialed. Remove if cannot be dialed
                    foreach ( $getLead as $key => $value ) {
                        $validateLead = $this->validateLead($value, $startTime, $endTime);
                        if (!$validateLead["isValid"]) {
                            //remove lead from temp table if it is not belong to campaign time and reduce the count by 1 of total number of records
                            $removeLead = $this->removeLead($value->lead_id, $campaign['id']);
                            $hopperCount["valid"]--;
                            $hopperCount["invalid"]++;
                            $addRecord++;
                        }
                    }

                    if ($addRecord > 0) {
                        $response = $this->addLeadToTempTimeBasedCalling($campaign['id'], $addRecord, $startTime, $endTime, $campaign['hopper_mode']);
                        $response["min_lead_temp"] = $minimumLead;
                        $response["max_lead_temp"] = $maximumLead;
                        $hopperCount["valid"] = $hopperCount["valid"] + $response["added"];
                        $response["hopperCount"] = $hopperCount;
                        $this->sendLowLeadEmail("time_based_loading", $id, $campaign['id'], $response);
                        $response["success"] = true;
                        return $response;
                    }
                }
            } elseif ($campaign['time_based_calling'] == '0' && $addRecord > 0) {
                $response = $this->addLeadToTempNonTimeBasedCalling($campaign['id'], $addRecord, $campaign['hopper_mode']);
                $response["min_lead_temp"] = $minimumLead;
                $response["max_lead_temp"] = $maximumLead;
                $hopperCount["valid"] = $hopperCount["valid"] + $response["added"];
                $response["hopperCount"] = $hopperCount;
                $this->sendLowLeadEmail("normal_loading", $id, $campaign['id'], $response);
                $response["success"] = true;
                return $response;
            }

            return [
                "success" => true,
                "limit" => 0,
                "added" => 0,
                "lists" => []
            ];
        } catch (\Throwable $e) {
            Log::error("Cron.addLeadTemp.error", [
                "message" => $e->getMessage(),
                "line" => $e->getLine(),
                "file" => $e->getFile()
            ]);
            return [
                "success" => false,
                "message" => $e->getMessage(),
                "limit" => null,
                "added" => null,
                "lists" => []
            ];
        }
    }

    public function sendLowLeadEmail(string $source, int $clientId, int $campaignId, array $response)
    {
        $sendEmail = false;
        if ($response["hopperCount"]["valid"] < 5) {
            Log::info("sendLowLeadEmail($clientId, $campaignId)", $response);
            #send email
            $subscription = SystemNotification::on("mysql_$clientId")->findOrFail("campaign_low_lead");
            if (empty($subscription->last_sent)) {
                $sendEmail = true;
            } else {
                $last_sent = new DateTime($subscription->last_sent);
                $diff = $last_sent->diff(new DateTime());
                Log::info("sendLowLeadEmail last sent diff in mins: " . $diff->i);
                if ($diff->i > 15) {
                    $sendEmail = true;
                }
            }
            if ($sendEmail) {
                dispatch(new LowLeadNotificationJob($clientId, $campaignId, $response))->onConnection("database");
            }
        }
        return $sendEmail;
    }

    /*
     * get lead in temp
     */
    public function getTempLead($campaignId)
    {
        $leadTemp = DB::connection($this->database)->select("SELECT campaign_id,list_id,lead_id FROM lead_temp WHERE campaign_id = :campaign_id", array('campaign_id' => $campaignId));
        $leadTemp = (array)$leadTemp;
        return $leadTemp;
    }

    /*
     * add Lead to lead_temp table for non time based calling campaign
     */
    public function addLeadToTempTimeBasedCalling($campaignId, $limit, $startTime = null, $endTime = null, $hopper_mode= null)
    {
        $response = [
            "limit" => $limit,
            "added" => 0,
            "lists" => []
        ];
        $campaignList = $this->getList($campaignId);
        $countCampaignList = count($campaignList);
        if($hopper_mode == '1' || ($hopper_mode == '2' && $countCampaignList == '1'))
        {
            foreach ( $campaignList as $key => $list )
            {
                $lead = $this->getLead($list->list_id, $campaignId, $limit, $startTime, $endTime);
                $record = count($lead);
                $response["lists"][$list->list_id] = [
                    "records" => $record,
                    "valid" => 0,
                    "duplicates" => 0
                ];

               /* if ($record > 0)
                {
                    foreach ( $lead as $leadKey => $value )
                    {
                        $check_list = DB::connection($this->database)->selectOne("SELECT * FROM campaign_list WHERE campaign_id  = :campaign_id and list_id=:list_id and status=:status", array('campaign_id' => $campaignId,'list_id'=>$list->list_id,'status'=>'1'));

                        if(!empty($check_list))
                        {
                            $insertSql = "INSERT ignore INTO lead_temp (campaign_id, list_id, lead_id) VALUES (:campaign_id, :list_id, :lead_id)";
                            DB::connection($this->database)->insert($insertSql, array('campaign_id' => $campaignId, 'list_id' => $list->list_id, 'lead_id' => $value));
                            $response["lists"][$list->list_id]["valid"]++;
                            $response["added"]++;
                        } 
                    }

                    $duplicates = $this->removeDuplicateRecord();
                    $response["lists"][$list->list_id]["duplicates"] = $duplicates;
                    $response["added"] = $response["added"] - $duplicates;
                }*/


                if ($record > 0)
                {
                    $getLead = $this->getTempLead($campaignId);
                    $lead_tempcount = count($getLead);
                    $lead_data =array();
                    foreach ( $lead as $leadKey => $value )
                    {
                        if($lead_tempcount <= 299)
                        {
                            $check_list = DB::connection($this->database)->selectOne("SELECT * FROM campaign_list WHERE campaign_id  = :campaign_id and list_id=:list_id and status=:status", array('campaign_id' => $campaignId,'list_id'=>$list->list_id,'status'=>'1'));

                            //echo "<pre>";print_r($check_list);die;

                            if(!empty($check_list))
                            {
                                $lead_data[$leadKey]['lead_id'] = $value;
                                $lead_data[$leadKey]['list_id'] = $list->list_id;
                                $lead_data[$leadKey]['campaign_id'] = $campaignId;
                                $response["lists"][$list->list_id]["valid"]++;
                                $response["added"]++;
                                ++$lead_tempcount ;
                            }
                        }

                    }
                    DB::connection($this->database)->table('lead_temp')->insert($lead_data);
                    $duplicates = $this->removeDuplicateRecord();
                    $response["lists"][$list->list_id]["duplicates"] = $duplicates;
                    $response["added"] = $response["added"] - $duplicates;
                }
            }
        }

        else
        {
            for($j=0;$j < 5; $j++ )
            {
                foreach ( $campaignList as $key => $list ) 
                {
                    $lead = $this->getLeadRandom($list->list_id, $campaignId, $limit, $startTime, $endTime);
                    $record = count($lead);
                    $response["lists"][$list->list_id] = [
                        "records" => $record,
                        "valid" => 0,
                        "duplicates" => 0
                    ];

                    if ($record > 0)
                    {
                        foreach ( $lead as $leadKey => $value )
                        {
                            $check_list = DB::connection($this->database)->selectOne("SELECT * FROM campaign_list WHERE campaign_id  = :campaign_id and list_id=:list_id and status=:status", array('campaign_id' => $campaignId,'list_id'=>$list->list_id,'status'=>'1'));

                            if(!empty($check_list))
                            {
                                $insertSql = "INSERT ignore INTO lead_temp (campaign_id, list_id, lead_id) VALUES (:campaign_id, :list_id, :lead_id)";
                                DB::connection($this->database)->insert($insertSql, array('campaign_id' => $campaignId, 'list_id' => $list->list_id, 'lead_id' => $value));
                                $response["lists"][$list->list_id]["valid"]++;
                                $response["added"]++;
                            }
                        }

                        $duplicates = $this->removeDuplicateRecord();
                        $response["lists"][$list->list_id]["duplicates"] = $duplicates;
                        $response["added"] = $response["added"] - $duplicates;
                    }
                }
            }
        }

        return $response;
    }

    /*
 * Remove lead from temp table
 */
    public function removeLead($leadId, $campaignId)
    {
        DB::connection($this->database)->delete("DELETE FROM lead_temp WHERE lead_id = :lead_id AND campaign_id = :campaign_id", array('lead_id' => $leadId, 'campaign_id' => $campaignId));
    }

    /*
     * get lead detail
     */
    public function getLeadDetail($id)
    {
        $leadDetail = DB::connection($this->database)->selectOne("SELECT * FROM list_data WHERE id = :id", array('id' => $id));
        $leadDetail = (array)$leadDetail;
        return $leadDetail;
    }

    /*
     * validate time based calling lead
     */
    public function validateLead($lead, $startTime, $endTime)
    {
        $return = [
            "isValid" => false,
            "isDeleted" => false,
            "hasDialingColumn" => false,
            "leadDialable" => []
        ];
        if (is_array($lead)) {
            $detail = $lead;
        } elseif (is_numeric($lead)) {
            $detail = $this->getLeadDetail($lead);
        }
        if (!empty($detail)) {
            $return["isDeleted"] = true;
            $list = $detail['list_id'];
            $dialingColumn = $this->getDialingColumn($list);
            if (!empty($dialingColumn)) {
                $return["hasDialingColumn"] = true;
                $dialNumber = $detail[$dialingColumn];
                if (!empty($dialNumber)) {
                    $dialable = $this->isLeadDialable($dialNumber, $startTime, $endTime);
                    $return["leadDialable"] = $dialable;
                    $return["isValid"] = $dialable["dialable"];
                }
            }
        }
        return $return;
    }

    /*
     * get dialing column for list
     */
    public function isLeadDialable($number, $startTime, $endTime)
    {
        $return = [
            "dialable" => 0,
            "areacodeTimeZone" => 0,
            "dialingTime" => 0
        ];
        
        $number = preg_replace('/[^0-9]/', '', $number);

        $numberAreacode = substr(trim($number), 0, 3);
        $timeZone = $this->getTimezone($numberAreacode);
        if (empty($timeZone)) {
            $return["dialable"] = 1;
            $return["dialingTime"] = 1;
        } else {
            if (!empty($timeZone['timezone'])) {
                $return["areacodeTimeZone"] = 1;
                $time = new DateTime();
                $time->setTimeZone(new DateTimeZone(timezone_name_from_abbr($timeZone['timezone'])));
                $currentTime = $time->format('H:i:s');
                if (strtotime($startTime) < strtotime($currentTime) && strtotime($endTime) > strtotime($currentTime)) {
                    $return["dialingTime"] = 1;
                    $return["dialable"] = 1;
                }
            }
        }
        return $return;
    }

    /*
     * get timezone from number
     */
    public function getTimezone($numberAreacode)
    {
        $timeZone = DB::connection('master')->selectOne("SELECT timezone FROM timezone WHERE areacode = :areacode", array('areacode' => $numberAreacode));
        $timeZone = (array)$timeZone;
        return $timeZone;
    }

    /*
     * get list associated to campaign
     */
    public function getList($campaignId)
    {
        $campaignList = DB::connection($this->database)->select("SELECT campaign_id,list_id FROM campaign_list WHERE campaign_id = :campaign_id and status=1 and is_deleted=0 ORDER BY list_id ASC", array('campaign_id' => $campaignId));
        $campaignList = (array)$campaignList;
        return $campaignList;
    }

    /*
     * Fetch lead from laed data table
     * @return array
     */
    public function getLead($list_id, $campaign_id, $limit = 100, $startTime = null, $endTime = null)
    {
        $response = [];
        $dialingColumn = $this->getDialingColumn($list_id);
        if (!empty($dialingColumn)) {
            $listData = DB::connection($this->database)->select("SELECT id,list_id, option_1, option_2, option_3, option_4, option_5, option_6, option_7, option_8, option_9, option_10, option_11, option_12, option_13, option_14, option_15, option_16, option_17, option_18, option_19, option_20, option_21, option_22, option_23, option_24, option_25, option_26, option_27, option_28, option_29, option_30 FROM list_data WHERE list_id = :list_id", array('list_id' => $list_id));
            $data = (array)$listData;

           // echo "<pre>";print_r($data);die;  // 41-50  cpu
            
            foreach ( $data as $key => $value ) {
                
                $number = $value->$dialingColumn;
                #do the time check of start and end time passed
                if ($startTime && $endTime && strtotime($startTime) < strtotime($endTime)) {
                    $check = $this->isLeadDialable($number, $startTime, $endTime);

                    #if not in dialing time skip
                    if (!$check["dialable"]) continue;
                }

                #If lead already in hopper skip
                $checkTemp = DB::connection($this->database)->selectOne("SELECT lead_id FROM lead_temp WHERE list_id = :list_id AND campaign_id = :campaign_id AND lead_id = :lead_id", array('list_id' => $list_id, 'campaign_id' => $campaign_id, 'lead_id' => $value->id));
                $checkTemp = (array)$checkTemp;
                if (!empty($checkTemp)) continue;

                #skip is lead present in lead_report
                $checkRecord = DB::connection($this->database)->selectOne("SELECT lead_id FROM lead_report WHERE campaign_id = :campaign_id AND lead_id = :lead_id", array('campaign_id' => $campaign_id, 'lead_id' => $value->id));
                $checkRecord = (array)$checkRecord;
                if (!empty($checkRecord)) continue;

                #Skip if lead present in DNC
                $checkNumber = DB::connection($this->database)->selectOne("SELECT `number`,`extension`,`comment`,`updated_at` FROM dnc WHERE number = :number", array('number' => $number));
                $checkNumber = (array)$checkNumber;
                if (!empty($checkNumber)) continue;

                $checkExcludeNumber = DB::connection($this->database)->selectOne("SELECT `number`,`campaign_id`,`first_name`,`last_name`,`company_name`,`updated_at` FROM exclude_number WHERE number = :number AND campaign_id = :campaign_id", array('number' => $number, 'campaign_id' => $campaign_id));
                $checkExcludeNumber = (array)$checkExcludeNumber;
                if (empty($checkExcludeNumber)) {
                    array_push($response, $value->id);
                }

                #if found required number of leads return back
                if (count($response) >= $limit) return $response;

                
            }
        }
        return $response;
    }


    public function getLeadRandom($list_id, $campaign_id, $limit = 100, $startTime = null, $endTime = null)
    {
        $response = [];
        $dialingColumn = $this->getDialingColumn($list_id);
        if (!empty($dialingColumn)) {
            $listData = DB::connection($this->database)->select("SELECT * FROM list_data WHERE list_id = :list_id", array('list_id' => $list_id));
            $data = (array)$listData;
            
            $i=0;
            foreach ( $data as $key => $value ) {
                if($i == '20')
                    break;
                
                $number = $value->$dialingColumn;
                #do the time check of start and end time passed
                if ($startTime && $endTime && strtotime($startTime) < strtotime($endTime)) {
                    $check = $this->isLeadDialable($number, $startTime, $endTime);

                    #if not in dialing time skip
                    if (!$check["dialable"]) continue;
                }

                #If lead already in hopper skip
                $checkTemp = DB::connection($this->database)->selectOne("SELECT lead_id FROM lead_temp WHERE list_id = :list_id AND campaign_id = :campaign_id AND lead_id = :lead_id", array('list_id' => $list_id, 'campaign_id' => $campaign_id, 'lead_id' => $value->id));
                $checkTemp = (array)$checkTemp;
                if (!empty($checkTemp)) continue;

                #skip is lead present in lead_report
                $checkRecord = DB::connection($this->database)->selectOne("SELECT lead_id FROM lead_report WHERE campaign_id = :campaign_id AND lead_id = :lead_id", array('campaign_id' => $campaign_id, 'lead_id' => $value->id));
                $checkRecord = (array)$checkRecord;
                if (!empty($checkRecord)) continue;

                #Skip if lead present in DNC
                $checkNumber = DB::connection($this->database)->selectOne("SELECT * FROM dnc WHERE number = :number", array('number' => $number));
                $checkNumber = (array)$checkNumber;
                if (!empty($checkNumber)) continue;

                $checkExcludeNumber = DB::connection($this->database)->selectOne("SELECT * FROM exclude_number WHERE number = :number AND campaign_id = :campaign_id", array('number' => $number, 'campaign_id' => $campaign_id));
                $checkExcludeNumber = (array)$checkExcludeNumber;
                if (empty($checkExcludeNumber)) {
                    array_push($response, $value->id);
                }

                #if found required number of leads return back
                if (count($response) >= $limit) return $response;

                $i++;
            }
        }
        return $response;
    }

    /*
     * get dialing column for list
     */
    public function getDialingColumn($list_id)
    {
        $dialingColumn = DB::connection($this->database)->selectOne("SELECT column_name FROM list_header WHERE list_id = :list_id AND is_dialing = :is_dialing", array('list_id' => $list_id, 'is_dialing' => '1'));
        $dialingColumn = (array)$dialingColumn;
        return $dialingColumn['column_name'];
    }

    public function removeDuplicateRecord()
    {
        //commented because delete created record
        //$sql = "DELETE t1 FROM lead_temp t1 INNER JOIN lead_temp t2 WHERE t1.lead_id = t2.lead_id";
        //DB::connection($this->database)->delete($sql, array());
        return 0;
    }

    public function addLeadToTempNonTimeBasedCalling($campaignId, $limit, $hopper_mode)
    {
        $response = [
            "limit" => $limit,
            "added" => 0,
            "lists" => []
        ];
        $campaignList = $this->getList($campaignId);
        $countCampaignList = count($campaignList);
        //echo "<pre>";print_r($campaignList);die;

        if($hopper_mode == '1' || ($hopper_mode == '2' && $countCampaignList == '1'))
        {
            foreach ( $campaignList as $key => $list )
            {
                $lead = $this->getLead($list->list_id, $campaignId, $limit);
/*
                 $response = [
            "limit" => $limit,
            "campaignId" => $campaignId,
            "lists" => $list->list_id
        ];*/


               // echo "<pre>";print_r($response);die;

                //echo "<pre>";print_r($lead);die;
                $record = count($lead);
                $response["lists"][$list->list_id] = [
                    "records" => $record,
                    "valid" => 0,
                    "duplicates" => 0
                ];

                if ($record > 0)
                {
                    $getLead = $this->getTempLead($campaignId);
                    $lead_tempcount = count($getLead);
                    $lead_data =array();
                    foreach ( $lead as $leadKey => $value )
                    {
                        if($lead_tempcount < 300)
                        {
                            $check_list = DB::connection($this->database)->selectOne("SELECT campaign_id,list_id,status,is_deleted FROM campaign_list WHERE campaign_id  = :campaign_id and list_id=:list_id and status=:status", array('campaign_id' => $campaignId,'list_id'=>$list->list_id,'status'=>'1'));

                            //echo "<pre>";print_r($check_list);die;

                            if(!empty($check_list))
                            {
                                $lead_data[$leadKey]['lead_id'] = $value;
                                $lead_data[$leadKey]['list_id'] = $list->list_id;
                                $lead_data[$leadKey]['campaign_id'] = $campaignId;
                                $response["lists"][$list->list_id]["valid"]++;
                                $response["added"]++;
                                ++$lead_tempcount ;
                            }
                        }

                    }
                    DB::connection($this->database)->table('lead_temp')->insert($lead_data);

                    //echo "<pre>";print_r($lead_data//);die;
                    //$duplicates = $this->removeDuplicateRecord();
                    $duplicates = 0;//

                    $response["lists"][$list->list_id]["duplicates"] = $duplicates;
                    $response["added"] = $response["added"] - $duplicates;
                }

                /*if ($record > 0)
                {
                    foreach ( $lead as $leadKey => $value )
                    {
                        $check_list = DB::connection($this->database)->selectOne("SELECT * FROM campaign_list WHERE campaign_id  = :campaign_id and list_id=:list_id and status=:status", array('campaign_id' => $campaignId,'list_id'=>$list->list_id,'status'=>'1'));

                        if(!empty($check_list))
                        {
                            $insertSql = "INSERT ignore INTO lead_temp (campaign_id, list_id, lead_id) VALUES (:campaign_id, :list_id, :lead_id)";

                            DB::connection($this->database)->insert($insertSql, array('campaign_id' => $campaignId, 'list_id' => $list->list_id, 'lead_id' => $value));
                            $response["lists"][$list->list_id]["valid"]++;
                            $response["added"]++;
                        }
                    }

                    $duplicates = $this->removeDuplicateRecord();
                    $response["lists"][$list->list_id]["duplicates"] = $duplicates;
                    $response["added"] = $response["added"] - $duplicates;
                }*/
            }
        }
        else
        {
            for($j=0;$j < 5; $j++ )
            {
                foreach ( $campaignList as $key => $list )
                {
                    $lead = $this->getLeadRandom($list->list_id, $campaignId, $limit);
                    //echo "<pre>";print_r($lead);die;
                    $record = count($lead);
                    $response["lists"][$list->list_id] = [
                        "records" => $record,
                        "valid" => 0,
                        "duplicates" => 0
                    ];

                    if ($record > 0)
                    {
                        foreach ( $lead as $leadKey => $value )
                        {
                            $check_list = DB::connection($this->database)->selectOne("SELECT * FROM campaign_list WHERE campaign_id  = :campaign_id and list_id=:list_id and status=:status", array('campaign_id' => $campaignId,'list_id'=>$list->list_id,'status'=>'1'));
                            if(!empty($check_list))
                            {
                                $insertSql = "INSERT ignore INTO lead_temp (campaign_id, list_id, lead_id) VALUES (:campaign_id, :list_id, :lead_id)";
                                DB::connection($this->database)->insert($insertSql, array('campaign_id' => $campaignId, 'list_id' => $list->list_id, 'lead_id' => $value));
                                $response["lists"][$list->list_id]["valid"]++;
                                $response["added"]++;
                            }
                        }

                        $duplicates = $this->removeDuplicateRecord();
                        $response["lists"][$list->list_id]["duplicates"] = $duplicates;
                        $response["added"] = $response["added"] - $duplicates;
                    }
                }
            }
        }

        return $response;
    }


    public function cronEmail($id)
    {
        try {
            $result_arr = array();
            $previous_day = date("Y-m-d", strtotime(" -1 day")) . ' 08:00:00';
            $current_day = date("Y-m-d") . ' 08:00:00';

            $this->database = 'mysql_' . $id;
            $email_status = DB::connection($this->database)->selectOne("SELECT * FROM user_setting WHERE auto_id  = :auto_id", array('auto_id' => 5));
            $email_status = (array)$email_status;
            $emails = implode(',', json_decode($email_status['sender_list']));
            //$email_status['status'];
            if ($email_status['status'] == '1') {
                $user = DB::connection('master')->selectOne("SELECT * FROM users WHERE parent_id = :parent_id and role = :role", array('parent_id' => $id, 'role' => 1));
//return "SELECT * FROM users WHERE id IN(".$emails.")";die;
                $email_data = DB::connection('master')->select("SELECT * FROM users WHERE id IN(" . $emails . ")");
                foreach ( $email_data as $email ) {
                    $sender_list[] = $email->email;
                }
                //return $sender_list;
            }

            $role = $user->role;
            $parent_id = $user->parent_id;
            $client = Client::find($parent_id);

            $result_arr['logo'] = 'http://phone.performancemedia.cloud/logo/' . $client->logo;
            $result_arr['company_name'] = $client->company_name;

            //echo $company_name = $user->company_name;die;

            // start_time >= '".$previous_day."' start_time <= '".$current_day."'

            $result_arr['name'] = $user->first_name . ' ' . $user->last_name;

            $outbound_res = DB::connection($this->database)->select("select count(*) as totalOutBoundCalls from cdr WHERE route  = :route and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'", array('route' => 'OUT'));

            $result_arr['total_outbound_Calls'] = $outbound_res[0]->totalOutBoundCalls;

            $outbound_manually = DB::connection($this->database)->select("select count(*) as totalOutBoundCallsByManually from cdr WHERE route= :route and type= :type and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'", array('route' => 'OUT', 'type' => 'manual'));

            $result_arr['total_outbound_Calls_manually'] = $outbound_manually[0]->totalOutBoundCallsByManually;
            $outbound_dialer = DB::connection($this->database)->select("select count(*) as totalOutBoundCallsByDialer from cdr WHERE route= :route and type= :type and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'", array('route' => 'OUT', 'type' => 'dialer'));

            $result_arr['total_outbound_Calls_dialer'] = $outbound_dialer[0]->totalOutBoundCallsByDialer;

            $outbound_campaign = DB::connection($this->database)->select("select campaign_id,count(*) as calls,title  from cdr inner join campaign on cdr.campaign_id=campaign.id    group by campaign_id");

            $outbound_campaign = (array)$outbound_campaign;

            //echo "<pre>";print_r($outbound_campaign);die;

            $i = 0;
            foreach ( $outbound_campaign as $key => $campaign_calls ) {
                //echo "<pre>";print_r($campaign_calls);
                $result_arr['campaign'][$key]['title'] = $campaign_calls->title;
                $result_arr['campaign'][$key]['calls'] = $campaign_calls->calls;
                $i++;
            }

            $inbound_res = DB::connection($this->database)->select("select count(*) as totalInBoundCalls from cdr WHERE route  = :route and type = :type and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'", array('route' => 'IN', 'type' => 'manual'));

            $result_arr['total_inbound_Calls'] = $inbound_res[0]->totalInBoundCalls;

            //return "select count(*) as totalSMSSend from sms WHERE type  = :type and created_date >= '".$previous_day."' and created_date <= '".$current_day."'";
            $sms_send = DB::connection($this->database)->select("select count(*) as totalSMSSend from sms WHERE type  = :type and date >= '" . $previous_day . "' and date <= '" . $current_day . "'", array('type' => 'outgoing'));

            if (!empty($sms_send)) {
                $result_arr['total_sms_send'] = $sms_send[0]->totalSMSSend;
            } else {
                $result_arr['total_sms_send'] = 0;
            }

            //return $result_arr;

            //echo "<pre>";print_r($result_arr);die;

            $sms_received = DB::connection($this->database)->select("select count(*) as totalSMSReceive from sms WHERE type  = :type and date >= '" . $previous_day . "' and date <= '" . $current_day . "'", array('type' => 'incoming'));

            $result_arr['total_sms_receive'] = $sms_received[0]->totalSMSReceive;
            $agent_list = User::get()->where('parent_id', $parent_id)->where('is_deleted', 0);
            //echo "<pre>";print_r($agent_list);die;

            $j = 0;
            foreach ( $agent_list as $agent_list_calls ) {
                $alt_extension = $agent_list_calls['alt_extension'];
                $extension = $agent_list_calls['extension'];
                $result_arr['agent'][$j]['extension'] = $extension;
                $result_arr['agent'][$j]['alt_extension'] = $alt_extension;

                $result_arr['agent'][$j]['agentName'] = $agent_list_calls['first_name'] . ' ' . $agent_list_calls['last_name'];
                //echo "select count(*) as totalCalls from cdr where extension IN('".$extension."','".$alt_extension."')";

                $agent_call_list_out = DB::connection($this->database)->select("select count(*) as totalOutCalls from cdr WHERE extension IN('" . $extension . "','" . $alt_extension . "') and route = :route and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'", array('route' => 'OUT'));

                //echo "<pre>";print_r($agent_call_list);die;
                $result_arr['agent'][$j]['outbound'] = $agent_call_list_out[0]->totalOutCalls;

                $agent_call_list_in = DB::connection($this->database)->select("select count(*) as totalInCalls from cdr WHERE extension IN('" . $extension . "','" . $alt_extension . "') and route = :route and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'", array('route' => 'IN'));

                $result_arr['agent'][$j]['inbound'] = $agent_call_list_in[0]->totalInCalls;

                $result_arr['email_data'] = $sender_list;
                $j++;
            }


            return $result_arr;


        } catch (Illuminate\Database\QueryException $e) {
            return $e->errorInfo;
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }


    }
}
