<?php

namespace App\Model;

use App\Http\Controllers\DialerController;
use App\Model\Client\ExtensionLive;
use App\Model\Client\ListHeader;
use App\Model\Client\LineDetail;
use App\Services\ExecutionProfiler;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Model\Client\ListData;
use Illuminate\Support\Facades\Cache;
use App\Services\TimezoneService;
use App\Model\Master\AsteriskServer;
use App\Exceptions\RenderableException;
use App\Model\Client\Schedule;
use App\Model\Master\Timezone;


use DateTime;
use DateTimeZone;

class Dialer extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];


    /**
     * Smart Lead Selection with Timezone & Schedule Check
     */
    function addLeadToExtensionLiveOutboundAI(int $campaignId, int $hopperMode, int $extension, int $asteriskServerId, int $clientId, $weekPlan = null, $adminTimezone = null)
    {
        $response = ["status" => false, "code" => "NO_LEADS", "message" => "No leads in hopper for campaign", "data" => []];
        $db = $clientId;

        // Loop to find a VALID lead (Limit 50 tries to prevent infinite loops)
        for ($i = 0; $i < 50; $i++) {
            $lead = null;

            // 1. Fetch Candidate Lead (Random/Linear)
            if ($hopperMode == '2') {
                // Random
                $lead = DB::connection('mysql_' . $db)->selectOne(
                    "SELECT * FROM lead_temp WHERE campaign_id = :campaign_id ORDER BY RAND()",
                    ['campaign_id' => $campaignId]
                );
            } else {
                // Linear - Use OFFSET to peek
                $lead = DB::connection('mysql_' . $db)->selectOne(
                    "SELECT * FROM lead_temp WHERE campaign_id = :campaign_id LIMIT 1 OFFSET :offset",
                    ['campaign_id' => $campaignId, 'offset' => $i]
                );
            }

            if (empty($lead)) {
                // End of Hopper
                return $response; 
            }
            $lead = (array)$lead;

            // 2. Determine Timezone
            $leadTimezone = $adminTimezone; // Default
            
            // Fetch Phone Number dynamically
            $phoneNumber = null;
            if (!empty($lead['list_id']) && !empty($lead['lead_id'])) {
                 // Get the column name for phone number
                 $listHeader = DB::connection('mysql_' . $db)->table('list_header')
                     ->where('list_id', $lead['list_id'])
                     ->where('is_dialing', 1)
                     ->select('column_name')
                     ->first();
                 
                 if ($listHeader && !empty($listHeader->column_name)) {
                     $colName = $listHeader->column_name;
                     // Get the actual data
                     $listData = DB::connection('mysql_' . $db)->table('list_data')
                         ->where('id', $lead['lead_id'])
                         ->select($colName)
                         ->first();
                     
                     if ($listData && isset($listData->$colName)) {
                         $phoneNumber = $listData->$colName;
                     }
                 }
            }

            // Fallback checking (if lead_temp has phone_number but valid? Original code checked lead['phone_number']?? no, it didn't.)
            // Assuming $phoneNumber is now found.

            if (!empty($phoneNumber)) {
                $phone = preg_replace('/[^0-9]/', '', $phoneNumber);
                if (strlen($phone) >= 10) {
                     $cleanPhone = substr($phone, -10); // Last 10 digits
                     $areaCode = substr($cleanPhone, 0, 3);
                     
                     $tzRow = Timezone::where('areacode', $areaCode)->first();
                     if ($tzRow && !empty($tzRow->timezone)) {
                         $leadTimezone = $tzRow->timezone;
                     }
                }
            }

            // 3. Check Schedule
            if ($this->isTimeAllowed($leadTimezone, $weekPlan)) {
                // ✅ Valid Lead Found!
                
                // DELETE specific lead from hopper
                DB::connection('mysql_' . $db)->delete(
                    "DELETE FROM lead_temp WHERE campaign_id = :campaign_id AND lead_id = :lead_id",
                    [
                        'campaign_id' => $campaignId,
                        'lead_id' => $lead['lead_id']
                    ]
                );
                
                // Return Logic
                $campaignType = DB::connection('mysql_' . $db)->selectOne(
                    "SELECT dial_mode FROM campaign WHERE id = :id",
                    ['id' => $campaignId]
                );

                return $lead;
            }
            
            // 4. If invalid, loop continues to next $i
        }
        
        return $response; // No valid leads found in batch
    }

    /**
     * Helper to check if current time in $timezone is allowed by $weekPlan
     */
    private function isTimeAllowed($timezone, $weekPlan)
    {
        if (empty($weekPlan)) return true; // No schedule = 24/7 allowed
        
        try {
            if (empty($timezone)) $timezone = 'America/New_York';
            $now = new DateTime("now", new DateTimeZone($timezone));
            $currentDay = strtolower($now->format('l')); // monday, tuesday...
            $currentTime = $now->format('H:i:s'); // 14:30:00

            foreach ($weekPlan as $dayKey => $times) {
                if (strtolower($dayKey) === $currentDay) {
                    if (empty($times['start_time']) || empty($times['end_time'])) return false; // Closed
                    
                    $start = $times['start_time'];
                    $end = $times['end_time'];
                    
                    if ($currentTime >= $start && $currentTime <= $end) {
                        return true;
                    }
                    return false; // Wrong time
                }
            }
            return false; // Day not found
            
        } catch (\Exception $e) {
            Log::error("Dialer Timezone Check Failed: " . $e->getMessage());
            return true; // Fail Safe
        }
    }

    /*
     *Fetch campaign for agent
     *@param object $request
     *@return array
     */

    function addLeadToExtensionLiveOutboundAI_bkp(int $campaignId, int $hopperMode, int $extension, int $asteriskServerId, int $clientId)
    {
        $response = ["status" => false, "code" => "NO_LEADS", "message" => "No leads in hopper for campaign", "data" => []];
        $db = $clientId;

        // hopper mode linear type
        if ($hopperMode == '1') {
            $lead = DB::connection('mysql_' . $db)->selectOne(
                "SELECT * FROM lead_temp WHERE campaign_id = :campaign_id",
                ['campaign_id' => $campaignId]
            );
        }

        //hopper mode random type
        else
        if ($hopperMode == '2') {
            $lead = DB::connection('mysql_' . $db)->selectOne(
                "SELECT * FROM lead_temp WHERE campaign_id = :campaign_id ORDER BY RAND()",
                ['campaign_id' => $campaignId]
            );
        }

        $lead = (array)$lead;
        if (!empty($lead)) {
            DB::connection('mysql_' . $db)->delete(
                "DELETE FROM lead_temp WHERE campaign_id = :campaign_id AND lead_id = :lead_id",
                [
                    'campaign_id' => $campaignId,
                    'lead_id' => $lead['lead_id']
                ]
            );

            $campaignType = DB::connection('mysql_' . $db)->selectOne(
                "SELECT dial_mode FROM campaign WHERE id = :id",
                [
                    'id' => $campaignId
                ]
            );

            return $outboundai_data['lead_id'] = $lead;
        }
        return $response;
    }

    public function outboundAIDialAsterisk($request)
    {
        $sql = "SELECT column_name FROM list_header  WHERE list_id = :list_id AND is_dialing = :is_dialing";
        $listHeader = DB::connection('mysql_' . $request['clientId'])->selectOne($sql, ['list_id' => $request['list_id'], 'is_dialing' => 1]);
        $listHeader = (array)$listHeader;

        $sql = "SELECT * FROM list_data  WHERE id = :id";
        $listData = DB::connection('mysql_' . $request['clientId'])->selectOne($sql, ['id' => $request['lead_id']]);
        $listData = (array)$listData;
        $number = $listData[$listHeader['column_name']];

        $request['number'] = $number;
        //echo "<pre>";print_r($request);die;
        if (!empty($number)) {
            $asterisk = $this->getAsterisk($request['asterisk_server_id'], $request['extension'], $request['clientId']);
            $response = $asterisk->outboundAIDial($request);

            $insertSql = "INSERT INTO lead_report (campaign_id, list_id, lead_id, disposition_id) VALUE (:campaign_id, :list_id, :lead_id, :disposition_id) ON DUPLICATE KEY UPDATE disposition_id = :disposition_id_1";
            return DB::connection('mysql_' . $request['clientId'])->insert(
                $insertSql,
                array(
                    'campaign_id' => $request['campaign_id'],
                    'list_id' => $request['list_id'],
                    'lead_id' => $request['lead_id'],
                    'disposition_id' => 110,
                    'disposition_id_1' => 110
                )
            );


            if ($response == true) {
                //$this->addToLeadReport('mysql_' . $clientId, $campaignId, $lead_id, $lead['lead_id'], 0);
                return array('status' => true, 'message' => "Call connected Outbound AI Dial Calls");
            } else {
                return array('status' => false, 'message' => "Unable to connect call Outbound AI Dial");
            }
        } else {
            return array('status' => false, 'message' => "Incorrect lead value Outbound AI dial");
        }
    }

    public function getAgentCampaignold($request)
    {
        try {
            $now_timezone_time = "";
            $current_time_utc = date('Y-m-d H:i:s');
            $timeZoneService = new TimezoneService();
            if (!empty($request->auth->timezone)) {
                $timezoneValue = $timeZoneService->findTimezoneValue($request->auth->timezone);


                $time = explode(':', $timezoneValue);
                $hour = $time[0];
                $minute = $time[1];

                $merge = $hour . ' hours' . ' ' . $minute . ' minute'; //-05 hours 00 minute
                //new code




                $tz = $request->auth->timezone;
                $timestamp = time();
                $dt = new DateTime("now", new DateTimeZone($tz)); //first argument "must" be a string
                $dt->setTimestamp($timestamp); //adjust the object to correct timestamp
                $now_timezone_time = $dt->format('H:i:s');
            }


            //close code



            // $now_timezone_time = date('H:i:s', strtotime($merge));


            if ($request->auth->role == '2' || $request->auth->role == '3' || $request->auth->role == '1' || $request->auth->role == '6' || $request->auth->role == '5') {

                $extension = $request->auth->extension;
                $intWebPhoneSetting = DialerController::getWebPhonestatus($request->auth->id, $request->auth->parent_id);
                if ($intWebPhoneSetting == 1) {
                    $extension = $request->auth->alt_extension;
                }

                /* new code implement*/

                $dataUser = User::where('id', $request->auth->id)->get()->first();

                $dialer_mode = $request->has('dialer_mode')
            ? intval($request->input('dialer_mode'))
            : $dataUser->dialer_mode;

                if ($dialer_mode == 3) {
                    $extension = $dataUser->app_extension;
                } else
            if ($dialer_mode == 2) {
                    $extension = $request->auth->alt_extension;
                } else
            if ($dialer_mode == 1) {
                    $extension =  $request->auth->extension;
                }

                //echo $extension;die;

                /*close new code implement*/

                $connection = "mysql_" . $request->auth->parent_id;

                $extensionGroup = DB::connection($connection)->select("SELECT * FROM extension_group_map WHERE extension = :extension AND is_deleted = :is_deleted", array('extension' => $extension, 'is_deleted' => 0));
                if (!empty($extensionGroup)) {
                    $inStr = array();
                    $data['is_deleted'] = 0;
                    $count = 1;
                    foreach ($extensionGroup as $item => $value) {
                        array_push($inStr, ":group_" . $count);
                        $data["group_" . $count] = $value->group_id;
                        $groupArray[] = $value->group_id;
                        $count++;
                    }


                    /*$campaign = DB::connection($connection)->select("SELECT * FROM campaign WHERE group_id in (" . implode(' , ', $inStr) . ") AND is_deleted = :is_deleted AND status=1 AND (time(now()) between call_time_start and call_time_end)", $data);*/

                    $campaign = DB::connection($connection)->select("SELECT * FROM campaign WHERE group_id in (" . implode(' , ', $inStr) . ") AND is_deleted = :is_deleted AND status=1 AND ('$now_timezone_time' between call_time_start and call_time_end)", $data);
                    $totalRows = count($campaign);
                    $extensionLive = ExtensionLive::on($connection)->find($extension);
                    $login = $extensionLive ? $extensionLive->toArray() : false;

                    if ($request->has('start') && $request->has('limit')) {
                        $start = (int)$request->input('start'); // Start index (0-based)
                        $limit = (int)$request->input('limit'); // Limit number of records to fetch

                        if ($start == 0 && $limit > 0) {
                            $campaign = array_slice($campaign, 0, $limit); // Fetch only the first 'limit' records
                        } else {
                            // For normal pagination, calculate length from start and limit
                            $length = $limit;
                            $$campaign = array_slice($campaign, $start, $length); // Fetch data from start to start+length
                        }
                        return array(
                            'utc_time' => $current_time_utc,
                            'timezone' => $request->auth->timezone,
                            'timezoneValue' => $timezoneValue,
                            'time' => $now_timezone_time . "(" . $timezoneValue . ")",

                            'success' => true,
                            'message' => 'List of campaign for extension.',
                            'total_rows' => $totalRows,
                            'data' => $campaign,
                            'login' => $login,
                        );
                    }


                    if (!empty($campaign)) {

                        return array(
                            'utc_time' => $current_time_utc,
                            'timezone' => $request->auth->timezone,
                            'timezoneValue' => $timezoneValue,
                            'time' => $now_timezone_time . "(" . $timezoneValue . ")",

                            'success' => true,
                            'message' => 'List of campaign for extension.',
                            'data' => $campaign,
                            'login' => $login,
                        );
                    } else {
                        return array(
                            'success' => true,
                            'message' => 'Extension is not belong to any campaign.',
                            'data' => array(),
                            'login' => $login
                        );
                    }
                } else {
                    return array(
                        'success' => false,
                        'message' => 'Extension not belong to any group',
                        'data' => [],
                        'login' => false
                    );
                }
            } else {
                return array(
                    'success' => false,
                    'message' => 'You do not have dialing permission',
                    'data' => array(),
                    'login' => false
                );
            }
        } catch (\Throwable $e) {
            Log::error("Dialer.getAgentCampaign", [
                "message" => $e->getMessage(),
                "line" => $e->getLine(),
                "file" => $e->getFile()
            ]);
            return array(
                'success' => false,
                'message' => $e->getMessage(),
                'data' => array(),
                'login' => false
            );
        }
    }

  public function getAgentCampaign($request)
    {
        try {
            $now_timezone_time = "";
            $current_time_utc = date('Y-m-d H:i:s');
            $timeZoneService = new TimezoneService();
            if (!empty($request->auth->timezone)) {
                $timezoneValue = $timeZoneService->findTimezoneValue($request->auth->timezone);
                $time = explode(':', $timezoneValue);
                $hour = $time[0];
                $minute = $time[1];

                $merge = $hour . ' hours' . ' ' . $minute . ' minute'; //-05 hours 00 minute
                $tz = $request->auth->timezone;
                $timestamp = time();
                $dt = new DateTime("now", new DateTimeZone($tz)); //first argument "must" be a string
                $dt->setTimestamp($timestamp); //adjust the object to correct timestamp
                $now_timezone_time = $dt->format('H:i:s');
                $dayKey = strtolower($dt->format('l')); // ✅ REQUIRED
            } else {
                // Fallback: use UTC when user has no timezone configured.
                // Without this, campaigns with call_schedule_id would always be excluded
                // (empty $dayKey never matches any week_plan key).
                $dt = new DateTime("now", new DateTimeZone('UTC'));
                $now_timezone_time = $dt->format('H:i:s');
                $dayKey = strtolower($dt->format('l'));
                $timezoneValue = '+00:00';
            }
             

            if ($request->auth->role == '2' || $request->auth->role == '3' || $request->auth->role == '1' || $request->auth->role == '6' || $request->auth->role == '5') {

                $extension = $request->auth->extension;
                $intWebPhoneSetting = DialerController::getWebPhonestatus($request->auth->id, $request->auth->parent_id);
                if ($intWebPhoneSetting == 1) {
                    $extension = $request->auth->alt_extension;
                }
                $dataUser = User::where('id', $request->auth->id)->get()->first();

                $dialer_mode = $request->has('dialer_mode')
            ? intval($request->input('dialer_mode'))
            : $dataUser->dialer_mode;

                if ($dialer_mode == 3) {
                    $extension = $dataUser->app_extension;
                } else
            if ($dialer_mode == 2) {
                    $extension = $request->auth->alt_extension;
                } else
            if ($dialer_mode == 1) {
                    $extension =  $request->auth->extension;
                }

                $connection = "mysql_" . $request->auth->parent_id;

                $extensionGroup = DB::connection($connection)->select("SELECT * FROM extension_group_map WHERE extension = :extension AND is_deleted = :is_deleted", array('extension' => $extension, 'is_deleted' => 0));
                 Log::info('extensionGroup', [
                    'extensionGroup' => $extensionGroup
                ]);
                if (!empty($extensionGroup)) {
                    $inStr = array();
                    $data['is_deleted'] = 0;
                    $count = 1;
                    foreach ($extensionGroup as $item => $value) {
                        array_push($inStr, ":group_" . $count);
                        $data["group_" . $count] = $value->group_id;
                        $groupArray[] = $value->group_id;
                        $count++;
                    }
                     Log::info('inStr', [
                    'inStr' => $inStr
                ]);
                //     $campaign = DB::connection($connection)->select("
                //     SELECT c.*, ct.week_plan
                //     FROM campaign c
                //     JOIN call_timers ct ON ct.id = c.call_schedule_id
                //     WHERE c.group_id IN (" . implode(' , ', $inStr) . ")
                //     AND c.is_deleted = :is_deleted
                //     AND c.status = 1
                // ", $data);
                $campaign = DB::connection($connection)->select("
                SELECT c.*,
                       c.title AS campaign_name,
                       'active' AS campaign_status,
                       ct.week_plan,
                       COALESCE((
                           SELECT SUM(l.lead_count)
                           FROM campaign_list cl
                           JOIN list l ON l.id = cl.list_id
                           WHERE cl.campaign_id = c.id AND cl.is_deleted = 0
                       ), 0) AS total_leads,
                       COALESCE((
                           SELECT COUNT(*)
                           FROM lead_report lr
                           WHERE lr.campaign_id = c.id
                       ), 0) AS called_leads
                FROM campaign c
                LEFT JOIN call_timers ct ON ct.id = c.call_schedule_id
                WHERE c.group_id IN (" . implode(' , ', $inStr) . ")
                AND c.is_deleted = :is_deleted
                AND c.status = 1
            ", $data);

                Log::info('Campaign  Debug', [
                    'campaign' => $campaign
                ]);
                $filteredCampaign = [];
                foreach ($campaign as $row) {
                    // ── SCHEDULE ENFORCEMENT ──────────────────────────────────────────
                    // Priority 1: Advanced week-plan schedule (call_schedule_id → call_timers)
                    if (!empty($row->call_schedule_id) && !empty($row->week_plan)) {
                        $weekPlan = json_decode($row->week_plan, true);
                        if (json_last_error() !== JSON_ERROR_NONE || !is_array($weekPlan)) {
                            Log::error('Invalid week_plan JSON', [
                                'campaign_id' => $row->id,
                                'error'       => json_last_error_msg(),
                            ]);
                            continue;
                        }
                        $plan = $weekPlan[$dayKey] ?? $weekPlan['default'] ?? null;
                        Log::info('Campaign week_plan debug', [
                            'campaign_id' => $row->id,
                            'day'         => $dayKey,
                            'now'         => $now_timezone_time,
                            'plan'        => $plan,
                        ]);
                        if (!$plan || empty($plan['start']) || empty($plan['end'])) {
                            continue; // no schedule for today → closed
                        }
                        $start = $plan['start'] . ':00';
                        $end   = $plan['end']   . ':00';
                        $isInWindow = ($start <= $end)
                            ? ($now_timezone_time >= $start && $now_timezone_time <= $end)
                            : ($now_timezone_time >= $start || $now_timezone_time <= $end);
                        if (!$isInWindow) {
                            continue;
                        }

                    // Priority 2: Simple time_based_calling window (uses campaign's own timezone)
                    } elseif (!empty($row->time_based_calling)) {
                        $campaignTz = !empty($row->timezone)
                            ? $row->timezone
                            : ($request->auth->timezone ?? 'America/New_York');
                        try {
                            $dtCampaign        = new \DateTime('now', new \DateTimeZone($campaignTz));
                            $nowInCampaignTz   = $dtCampaign->format('H:i:s');
                        } catch (\Exception $tzEx) {
                            Log::warning('Invalid campaign timezone — falling back to agent tz', [
                                'campaign_id' => $row->id,
                                'timezone'    => $campaignTz,
                            ]);
                            $nowInCampaignTz = $now_timezone_time;
                        }
                        $start = !empty($row->call_time_start) ? (string) $row->call_time_start : '00:00:00';
                        $end   = !empty($row->call_time_end)   ? (string) $row->call_time_end   : '23:59:59';
                        if (strlen($start) === 5) $start .= ':00';
                        if (strlen($end)   === 5) $end   .= ':00';
                        Log::info('Campaign simple time debug', [
                            'campaign_id'   => $row->id,
                            'campaign_tz'   => $campaignTz,
                            'now_in_tz'     => $nowInCampaignTz,
                            'window_start'  => $start,
                            'window_end'    => $end,
                        ]);
                        $isInWindow = ($start <= $end)
                            ? ($nowInCampaignTz >= $start && $nowInCampaignTz <= $end)
                            : ($nowInCampaignTz >= $start || $nowInCampaignTz <= $end);
                        if (!$isInWindow) {
                            continue;
                        }
                    }
                    // Priority 3: No restriction (time_based_calling=0, no call_schedule_id) → always available

                    // Check campaign has at least one active list
                    $hasList = DB::connection($connection)
                        ->table('campaign_list')
                        ->where('campaign_id', $row->id)
                        ->where('is_deleted', 0)
                        ->exists();
                    if (!$hasList) {
                        continue;
                    }

                    $filteredCampaign[] = $row;
                }

                $campaign  = $filteredCampaign;
                $totalRows = count($campaign);


                    $extensionLive = ExtensionLive::on($connection)->find($extension);
                    $login = $extensionLive ? $extensionLive->toArray() : false;

                    if ($request->has('start') && $request->has('limit')) {
                        $start = (int)$request->input('start'); // Start index (0-based)
                        $limit = (int)$request->input('limit'); // Limit number of records to fetch

                        if ($start == 0 && $limit > 0) {
                            $campaign = array_slice($campaign, 0, $limit); // Fetch only the first 'limit' records
                        } else {
                            // For normal pagination, calculate length from start and limit
                            $length = $limit;
                            //$$campaign = array_slice($campaign, $start, $length); // Fetch data from start to start+length
                            $campaign = array_slice($campaign, $start, $limit);

                        }

                        return array(
                            'utc_time' => $current_time_utc,
                            'timezone' => $request->auth->timezone,
                            'timezoneValue' => $timezoneValue,
                            'time' => $now_timezone_time . "(" . $timezoneValue . ")",

                            'success' => true,
                            'message' => 'List of campaign for extension.',
                            'total_rows' => $totalRows,
                            'data' => $campaign,
                            'login' => $login,
                        );
                    }


                    if (!empty($campaign)) {

                        return array(
                            'utc_time' => $current_time_utc,
                            'timezone' => $request->auth->timezone,
                            'timezoneValue' => $timezoneValue,
                            'time' => $now_timezone_time . "(" . $timezoneValue . ")",

                            'success' => true,
                            'message' => 'List of campaign for extension.',
                            'data' => $campaign,
                            'login' => $login,
                        );
                    } else {
                        return array(
                            'success' => true,
                            'message' => 'Extension is not belong to any campaign.',
                            'data' => array(),
                            'login' => $login
                        );
                    }
                } else {
                    return array(
                        'success' => false,
                        'message' => 'Extension not belong to any group',
                        'data' => [],
                        'login' => false
                    );
                }
            } else {
                return array(
                    'success' => false,
                    'message' => 'You do not have dialing permission',
                    'data' => array(),
                    'login' => false
                );
            }
        } catch (\Throwable $e) {
            Log::error("Dialer.getAgentCampaign", [
                "message" => $e->getMessage(),
                "line" => $e->getLine(),
                "file" => $e->getFile()
            ]);
            return array(
                'success' => false,
                'message' => $e->getMessage(),
                'data' => array(),
                'login' => false
            );
        }
    }
   
    /*
     *Fetch Lead Count In Temp table
     *@param object $request
     *@return array
     */
    
    public function getLeadCountInTemp(int $campaignId, int $parentId)
    {
        try {
            $leadCount = DB::connection('mysql_' . $parentId)->selectOne(
                "SELECT count(*) as cnt FROM lead_temp WHERE campaign_id = :campaign_id",
                array('campaign_id' => $campaignId)
            );
            $count = (array)$leadCount;

            //50 is random value. to make sure lead always in temp table
            if ($count['cnt'] < 50) {
                #Put this in Job
                $addLead = new Cron();
                $addLead->addLeadTemp($parentId, $campaignId);
            }
            return array(
                'success' => true,
                'message' => 'Lead Count',
                'count' => $count['cnt']
            );
        } catch (\Throwable $e) {
            Log::error("Dialer.getLeadCountInTemp.error", [
                "message" => $e->getMessage(),
                "line" => $e->getLine(),
                "file" => $e->getFile(),
                "code" => $e->getCode()
            ]);
            return array(
                'success' => false,
                'message' => 'Failed to fetch lead count. ' . $e->getMessage(),
                'count' => 0
            );
        }
    }

    /* CRM WEBPHONE EXAMPLE*/

    public function asteriskLoginCRM($request)
    {

        if (null !== $request->input('cli')) {
            $crm_cli =  $request->input('cli');
        } else {
            $crm_cli = '';
        }

        $extension = $request->input('extension');
        if (empty($extension)) {
            throw new RenderableException('Extension not found', [404], 404);
        }
        $data = User::where('extension', $extension)->orWhere('alt_extension', $extension)->orWhere('app_extension', $extension)->get()->first();

        $userData = $data;

        if (!empty($data)) {
            $server = AsteriskServer::find($data->asterisk_server_id);
            $asteriskServerId = $server->id;
            $parentId = $data->parent_id;
            $campaign = DB::connection('mysql_' . $parentId)->selectOne("SELECT * FROM campaign limit 1");

            if (!empty($campaign)) {
                $campaignId = $campaign->id;
            } else {
                throw new RenderableException('campaign not found', [404], 404);
            }
        } else {
            throw new RenderableException('Unauthorised Extension Found', [401], 401);
        }



        $asterisk = $this->getAsterisk($asteriskServerId, $extension, $parentId);
        $getExtensionLive = $this->getExtensionLive($extension, $parentId);

        if (!$request->has("number")) {
            if (!empty($getExtensionLive)) {
                $data_delete['extension'] = $extension;
                $response = $asterisk->confbridgeCRM($extension);

                /*$query = "DELETE FROM extension_live WHERE extension = :extension";
                $save = DB::connection('mysql_'.$parentId)->update($query, $data_delete);*/
            }
        }

        if (empty($getExtensionLive)) {
            try {
                $response = $asterisk->asteriskLoginCRM($extension, $campaignId);
                //echo "<pre>";print_r($response);die;
                if ($response == true) {
                    return  array(
                        'success' => 1,
                        'message' => 'Webphone In Call Status Active',
                        'data' => $response,
                        'code' => 301
                    );
                } else {
                    throw new RenderableException('Webphone In Call Status Inactive', [401], 401);
                }
            } catch (Exception $ex) {
                throw new RenderableException('Webphone In Call Status Inactive', [401], 401);
            }
        } else {
            try {
                $number = $request->input('number');
                if (empty($number)) {
                    throw new RenderableException('Number not found', [404], 404);
                }
                $leadId = $request->input('lead_id');
                if (empty($number)) {
                    throw new RenderableException('Lead Id not found', [404], 404);
                }
                $response = $asterisk->click2CallCRM($number, $campaignId, $leadId, $extension, $userData, $crm_cli);
                if ($response == true) {
                    return  array(
                        'success' => 1,
                        'message' => 'Call connected successfully for number =' . $number,
                        'data' => $response,
                        'code' => 302
                    );
                } else {
                    throw new RenderableException('Call not connected for number =' . $number, [401], 401);
                }
            } catch (Exception $ex) {
                throw new RenderableException('Call not connected for number =' . $number, [401], 401);
            }
        }
    }


    public function hangUpCRM($request)
    {
        $extension = $request->input('extension');
        if (empty($extension)) {
            throw new RenderableException('Extension not found', [404], 404);
        }

        $number = $request->input('number');
        if (empty($number)) {
            throw new RenderableException('Number not found', [404], 404);
        }

        $leadId = $request->input('lead_id');
        if (empty($number)) {
            throw new RenderableException('Lead Id not found', [404], 404);
        }

        $data = User::where('extension', $extension)->orWhere('alt_extension', $extension)->orWhere('app_extension', $extension)->get()->first();

        if (!empty($data)) {
            $server = AsteriskServer::find($data->asterisk_server_id);
            $asteriskServerId = $server->id;
            $parentId = $data->parent_id;
            $campaignId = 1;
        } else {
            throw new RenderableException('Unauthorised Extension Found', [401], 401);
        }

        try {
            $asterisk = $this->getAsterisk($asteriskServerId, $extension, $parentId);
            $response = $asterisk->hangUp();
            if ($response == true) {
                $sql = "UPDATE cdr set disposition_id = :disposition_id WHERE lead_id = :lead_id";
                DB::connection('mysql_' . $parentId)->update($sql, array('lead_id' => $leadId, 'disposition_id' => '108'));
                /*return array(
                    'success' => true,
                    'message' => 'Hang up successful'
                );*/
                return  array(
                    'success' => 1,
                    'message' => 'Hang up successful for number =' . $number,
                    'data' => $response,
                    'code' => 303
                );
            } else {
                /*return array(
                    'success' => false,
                    'message' => 'Unable to hang up the phone'
                );*/

                throw new RenderableException('Unable to hang up the number =' . $number,  401);
            }
        } catch (\Throwable $e) {
            Log::error("Dialer.hangUpCRM", [
                "message" => $e->getMessage(),
                "code" => $e->getCode(),
                "file" => $e->getFile(),
                "line" => $e->getLine()
            ]);
            throw new RenderableException('Unable to hang up the number =' . $number,  401);
        }
    }

    /*CLose*/

    /*
     *Extension Login
     *@param object $request
     *@return array
     */
    
    public function extensionLogin($request)
    {
        try {
            //check extension from extension live table
            $extension = $request->auth->extension;
            $intWebPhoneSetting = DialerController::getWebPhonestatus($request->auth->id, $request->auth->parent_id);
            if ($intWebPhoneSetting == 1) {
                $extension = $request->auth->alt_extension;
            }

            /* new code implement*/

            $dataUser = User::where('id', $request->auth->id)->get()->first();

            // Allow frontend to override dialer_mode (React WebPhone sends dialer_mode=2)
            $dialer_mode = $request->has('dialer_mode')
                ? intval($request->input('dialer_mode'))
                : $dataUser->dialer_mode;

            if ($dialer_mode == 3) {
                $extension = $dataUser->app_extension;
            } else
            if ($dialer_mode == 2) {
                $extension = $request->auth->alt_extension;
            } else
            if ($dialer_mode == 1) {
                $extension =  $request->auth->extension;
            }

            //echo $extension;die;

            /*close new code implement*/

            // Guard: extension must be non-empty and numeric before proceeding
            if (empty($extension) || !is_numeric($extension)) {
                return response()->json([
                    'success'    => false,
                    'message'    => $dialer_mode == 2
                        ? 'WebPhone extension (alt_extension) is not configured for your account. Contact your administrator.'
                        : 'Extension is not configured for your account. Contact your administrator.',
                    'error_code' => 'EXTENSION_NOT_CONFIGURED',
                ], 402);
            }

            error_log("extensionLogin: user_id={$request->auth->id} ext={$extension} alt_ext={$request->auth->alt_extension} hw_ext={$request->auth->extension} dialer_mode={$dialer_mode} campaign_id={$request->input('campaign_id')} parent_id={$request->auth->parent_id}");

            $getExtensionLive = $this->getExtensionLive($extension, $request->auth->parent_id);

            error_log("extensionLogin: getExtensionLive result=" . json_encode($getExtensionLive));

            if (empty($getExtensionLive)) {

                // ── WebRTC mode (dialer_mode=2): browser SIP stack is already registered
                // to Asterisk via WSS. We do NOT need the AMI originate handshake —
                // just INSERT the extension_live row directly and proceed.
                if ($dialer_mode == 2) {
                    error_log("extensionLogin: WebRTC mode - inserting ext={$extension} into extension_live for admin={$request->auth->parent_id}");
                    DB::connection('mysql_' . $request->auth->parent_id)->statement(
                        "INSERT INTO extension_live (extension, status, campaign_id, lead_id)
                         VALUES (?, ?, ?, NULL)
                         ON DUPLICATE KEY UPDATE status = ?, campaign_id = ?, lead_id = NULL",
                        [
                            $extension,
                            Asterisk::STATUS_READY,
                            $request->input('campaign_id'),
                            Asterisk::STATUS_READY,
                            $request->input('campaign_id'),
                        ]
                    );
                    Log::info("extensionLogin: WebRTC extension {$extension} inserted into extension_live directly", [
                        'user_id'     => $request->auth->id,
                        'campaign_id' => $request->input('campaign_id'),
                    ]);
                    $getExtensionLive = $this->getExtensionLive($extension, $request->auth->parent_id);
                } else {
                    // Hardware phone (mode 1) or mobile (mode 3): use legacy AMI originate
                    $asterisk = $this->getAsterisk($request->auth->asterisk_server_id, $extension, $request->auth->parent_id);
                    $asterisk->asteriskLogin($request->input('campaign_id'));
                }

                $count = 1;
                while (true) {
                    if (!empty($getExtensionLive)) {
                        $this->getLeadCountInTemp($request->input('campaign_id'), $request->auth->parent_id);
                        $modeType = $this->getHopperModeInCampaign($request->input('campaign_id'), $request->auth->parent_id);
                        $response = $this->addLeadToExtensionLive(
                            $request->input('campaign_id'),
                            $modeType['hopper_mode'],
                            $extension,
                            $request->auth->asterisk_server_id,
                            $request->auth->parent_id,
                            $request->auth->id,
                            $dialer_mode
                        );
                       if ($response["status"] === false) {
                        return response()->json([
                            'success' => false,
                            'message' => $response["message"]
                        ], 402);
                    }
                    return response()->json([
                        'success' => true,
                        'message' => 'You are logged in successfully. ' . $response["message"]
                    ], 200);

                    } elseif ($count == 5) {
                        return array(
                            'success' => false,
                            'message' => 'WebPhone is not connected. Please enable your WebPhone in the browser (click the phone icon → Enable WebPhone) and wait until it shows "Ready" before joining a campaign.',
                            'error_code' => 'WEBPHONE_NOT_CONNECTED',
                        );
                    } else {
                        sleep(5);
                    }
                    $count++;
                    $getExtensionLive = $this->getExtensionLive($extension, $request->auth->parent_id);
                }
            } else {
                // Status 0 (idle) or 3 (wrap-up) means not actively on a call,
                // even if a stale lead_id remains from a previous session.
                // Allow re-login to a new campaign in these states.
                if ($getExtensionLive['status'] == 0 || $getExtensionLive['status'] == 3) {
                    $sql = "UPDATE extension_live SET status = :status, lead_id = null, campaign_id = :campaign_id WHERE extension = :extension";
                    DB::connection('mysql_' . $request->auth->parent_id)->update($sql, array('extension' => $extension, 'status' => '0', 'campaign_id' => $request->input('campaign_id')));
                    $this->getLeadCountInTemp($request->input('campaign_id'), $request->auth->parent_id);
                    $modeType = $this->getHopperModeInCampaign($request->input('campaign_id'), $request->auth->parent_id);
                    $response = $this->addLeadToExtensionLive(
                        $request->input('campaign_id'),
                        $modeType['hopper_mode'],
                        $extension,
                        $request->auth->asterisk_server_id,
                        $request->auth->parent_id,
                        $request->auth->id,
                        $dialer_mode
                    );
                    if ($response["status"] === false) {
                        return response()->json([
                            'success' => false,
                            'message' => $response["message"]
                        ], 402);
                    }
                    return array(
                        'success' => $response["status"],
                        'message' => 'You are logged in successfully. ' . $response["message"]
                    );
                } else if ($getExtensionLive['lead_id']) {
                    return array(
                        'success' => true,
                        'message' => 'You are already in call. Lead Id: ' . $getExtensionLive['lead_id']
                    );
                } else {
                    return array(
                        'success' => false,
                        'message' => 'You are already in call'
                    );
                }
            }
        } catch (\Throwable $e) {
            Log::error("Dialer.extensionLogin", [
                "message" => $e->getMessage(),
                "code" => $e->getCode(),
                "file" => $e->getFile(),
                "line" => $e->getLine()
            ]);
                return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
            'data' => []
        ], 402);

        }
    }

    /*
     * Asterisk server detail
     * @return object
     */
    public function getAsterisk(int $serverId, int $extension, int $adminId): Asterisk
    {
        /** @var Asterisk $asterisk */
        $asterisk = Asterisk::findOrFail($serverId);
        $asterisk->setExtension($extension);
        $asterisk->setAdmin($adminId);
        return $asterisk;
    }

    /*
     * Extension live
     * @return array
     */
    public function getExtensionLive($extension, $admin)
    {
        if (!empty($extension) && is_numeric($extension) && !empty($admin) && is_numeric($admin)) {
            $extensionLive = DB::connection('mysql_' . $admin)->selectOne("SELECT * FROM extension_live WHERE extension = :extension", array('extension' => $extension));
            $extensionLive = (array)$extensionLive;
            return $extensionLive;
        }
    }

    /*
     *Call Number
     *@param object $request
     *@return array
     */
    public function callNumber($request)
    {
        try {
            if (
                !empty($request->input('campaign_id')) && is_numeric($request->input('campaign_id')) &&
                !empty($request->input('lead_id')) && is_numeric($request->input('lead_id')) &&
                !empty($request->input('number')) && is_numeric($request->input('number'))
            ) {
                $extension = $request->auth->extension;
                $intWebPhoneSetting = DialerController::getWebPhonestatus($request->auth->id, $request->auth->parent_id);
                if ($intWebPhoneSetting == 1) {
                    $extension = $request->auth->alt_extension;
                }

                /* new code implement*/

                $dataUser = User::where('id', $request->auth->id)->get()->first();

                // Allow frontend to override dialer_mode (React WebPhone sends dialer_mode=2)
                $dialer_mode = $request->has('dialer_mode')
                    ? intval($request->input('dialer_mode'))
                    : $dataUser->dialer_mode;

                if ($dialer_mode == 3) {
                    $extension = $dataUser->app_extension;
                } else
            if ($dialer_mode == 2) {
                    $extension = $request->auth->alt_extension;
                } else
            if ($dialer_mode == 1) {
                    $extension =  $request->auth->extension;
                }

                error_log("callNumber: user_id={$request->auth->id} hw_ext={$request->auth->extension} alt_ext={$request->auth->alt_extension} dialer_mode={$dialer_mode} req_mode=" . $request->input('dialer_mode') . " FINAL_EXT={$extension} number=" . $request->input('number'));

                /*close new code implement*/

                // WebRTC (dialer_mode=2): browser SIP stack places the call directly.
                // Just update extension_live with the lead and return success —
                // no AMI originate needed, the frontend dials via SIP.js/SIPml.
                if ($dialer_mode == 2) {
                    $number = preg_replace('/[^0-9]/', '', $request->input('number'));
                    $connection = 'mysql_' . $request->auth->parent_id;

                    // Update extension_live with lead_id
                    DB::connection($connection)->update(
                        "UPDATE extension_live SET lead_id = :lead_id, campaign_id = :campaign_id WHERE extension = :extension",
                        [
                            'lead_id' => $request->input('lead_id'),
                            'campaign_id' => $request->input('campaign_id'),
                            'extension' => $extension,
                        ]
                    );

                    // Add to lead_report
                    $this->addToLeadReport(
                        $connection,
                        $request->input('campaign_id'),
                        $request->input('id'), // list_id passed as 'id'
                        $request->input('lead_id'),
                        0
                    );

                    return [
                        'success' => 'true',
                        'message' => 'Number called',
                        'number' => $number,
                        'dial_mode' => 'webrtc',
                    ];
                }

                $asterisk = $this->getAsterisk($request->auth->asterisk_server_id, $extension, $request->auth->parent_id);
                $response = $asterisk->click2Call($request->input('number'), $request->input('campaign_id'), $request->input('lead_id'),$request->auth->id, $dialer_mode);
                  if (is_array($response) && isset($response['success']) && $response['success'] === false) {
                    return [
                        'success' => false,
                        'message' => $response['message'] ?? 'Call failed',
                        'status'    => $response['status'] ?? 400
                    ];
                }
                if ($response == true) {
                    return array(
                        'success' => 'true',
                        'message' => 'Number called'
                    );
                } else {
                    return array(
                        'success' => 'false',
                        'message' => 'Unable to call the number'
                    );
                }
            }
        } catch (Exception $e) {
            echo $e->getMessage();
        } catch (InvalidArgumentException $e) {
            echo $e->getMessage();
        }
    }

    /*
     *Hang Up
     *@param object $request
     *@return array
     */
    public function hangUp($request)
    {
        $extension = $request->auth->extension;
        $intWebPhoneSetting = DialerController::getWebPhonestatus($request->auth->id, $request->auth->parent_id);
        if ($intWebPhoneSetting == 1) {
            $extension = $request->auth->alt_extension;
        }

        /* new code implement*/

        $dataUser = User::where('id', $request->auth->id)->get()->first();

        // Allow frontend to override dialer_mode (React WebPhone sends dialer_mode=2)
        $dialer_mode = $request->has('dialer_mode')
            ? intval($request->input('dialer_mode'))
            : $dataUser->dialer_mode;

        if ($dialer_mode == 3) {
            $extension = $dataUser->app_extension;
        } else
            if ($dialer_mode == 2) {
            $extension = $request->auth->alt_extension;
        } else
            if ($dialer_mode == 1) {
            $extension =  $request->auth->extension;
        }

        //echo $extension;die;

        /*close new code implement*/

        // WebRTC mode (dialer_mode=2): the browser SIP stack already terminated the call.
        // No Asterisk AMI hangup is needed — return success immediately.
        if ($dialer_mode == 2) {
            return array(
                'success' => true,
                'message' => 'Hang up successful (WebRTC)',
            );
        }

        try {
            $asterisk = $this->getAsterisk($request->auth->asterisk_server_id, $extension, $request->auth->parent_id);
            $response = $asterisk->hangUp();
            if ($response == true) {
                return array(
                    'success' => true,
                    'message' => 'Hang up successful'
                );
            } else {
                return array(
                    'success' => false,
                    'message' => 'Unable to hang up the phone'
                );
            }
        } catch (\Throwable $e) {
            Log::error("Dialer.hangUp", [
                "message" => $e->getMessage(),
                "code" => $e->getCode(),
                "file" => $e->getFile(),
                "line" => $e->getLine()
            ]);
            return array(
                'success' => false,
                'message' => 'Unable to hang up the phone. ' . $e->getMessage(),
                'data' => []
            );
        }
    }

    /*
     * Assigned lead to extenstion
     * Fetches lead information
     * @return boolean
     */
    function addLeadToExtensionLive(int $campaignId, int $hopperMode, int $extension, int $asteriskServerId, int $clientId ,int $user_id, int $dialer_mode = 1)
    {

        $response = [
            "status" => false,
            "code" => "NO_LEADS",
            "message" => "No leads in hopper for campaign",
            "data" => []
        ];

        //check lead on temp table
        $db = $clientId;

        $lead = null; // initialise — prevents "Undefined variable $lead" when hopper_mode is 0/null/unset

        // hopper mode linear type
        if ($hopperMode == '1') {
            $lead = DB::connection('mysql_' . $db)->selectOne(
                "SELECT * FROM lead_temp WHERE campaign_id = :campaign_id",
                ['campaign_id' => $campaignId]
            );
        }

        // hopper mode random type
        elseif ($hopperMode == '2') {
            $lead = DB::connection('mysql_' . $db)->selectOne(
                "SELECT * FROM lead_temp WHERE campaign_id = :campaign_id and redial = '1'",
                ['campaign_id' => $campaignId]
            );

            if (empty($lead)) {
                $lead = DB::connection('mysql_' . $db)->selectOne(
                    "SELECT * FROM lead_temp WHERE campaign_id = :campaign_id ORDER BY RAND()",
                    ['campaign_id' => $campaignId]
                );
            }
        } else {
            // hopper_mode is 0 / null / any other value — fall back to linear fetch
            $lead = DB::connection('mysql_' . $db)->selectOne(
                "SELECT * FROM lead_temp WHERE campaign_id = :campaign_id",
                ['campaign_id' => $campaignId]
            );
        }

        $lead = (array)$lead;

        if (!empty($lead)) {
            DB::connection('mysql_' . $db)->delete(
                "DELETE FROM lead_temp WHERE campaign_id = :campaign_id AND lead_id = :lead_id",
                [
                    'campaign_id' => $campaignId,
                    'lead_id' => $lead['lead_id']
                ]
            );

            $campaignType = DB::connection('mysql_' . $db)->selectOne(
                "SELECT dial_mode FROM campaign WHERE id = :id",
                [
                    'id' => $campaignId
                ]
            );

            $dialMode = (array)$campaignType;
            if ($dialMode['dial_mode'] == 'super_power_dial') {
                $sql = "SELECT column_name FROM list_header  WHERE list_id = :list_id AND is_dialing = :is_dialing";
                $listHeader = DB::connection('mysql_' . $db)->selectOne($sql, ['list_id' => $lead['list_id'], 'is_dialing' => 1]);
                $listHeader = (array)$listHeader;

                $sql = "SELECT * FROM list_data  WHERE id = :id";
                $listData = DB::connection('mysql_' . $db)->selectOne($sql, ['id' => $lead['lead_id']]);
                $listData = (array)$listData;
                $number = $listData[$listHeader['column_name']];

                $numStr2 = (string)$number;

                $mobile = preg_replace('/[^0-9]/', '', $numStr2);
                $digitCount2 = strlen($mobile);
                // return array('status' => false, 'message' => $number.'  '.$mobile.' '.$digitCount2);
                // if (!empty($number)) {

                if ($digitCount2 == 10) {
                    $sql = "UPDATE extension_live SET lead_id = :lead_id, campaign_id = :campaign_id WHERE extension = :extension";
                    DB::connection('mysql_' . $db)->update(
                        $sql,
                        [
                            'extension' => $extension,
                            'lead_id' => $lead['lead_id'],
                            'campaign_id' => $campaignId
                        ]
                    );
                    // For WebRTC (dialer_mode=2): skip AMI originate, just assign
                    // the lead and let the browser SIP stack place the call.
                    if ($dialer_mode == 2) {
                        $this->addToLeadReport('mysql_' . $db, $campaignId, $lead['list_id'], $lead['lead_id'], 0);
                        return array('status' => true, 'message' => "Lead assigned — dial via WebPhone", 'number' => $number, 'lead_id' => $lead['lead_id']);
                    }

                    $asterisk = $this->getAsterisk($asteriskServerId, $extension, $clientId);
                    $response = $asterisk->click2Call($number, $campaignId, $lead['lead_id'],$user_id, $dialer_mode);
                    if (is_array($response) && isset($response['success']) && $response['success'] === false) {
                        return [
                        'status' => false,
                        'message' => $response['message'] ?? 'Call failed',
                        //'status'    => $response['status'] ?? 400
                    ];
                }


                    if ($response == true) {
                        $this->addToLeadReport('mysql_' . $db, $campaignId, $lead['list_id'], $lead['lead_id'], 0);
                        return array('status' => true, 'message' => "Call connected");
                    } else {
                        return array('status' => false, 'message' => "Unable to connect call");
                    }
                } else {

                    $addResponse = $this->addLeadToExtensionLive($campaignId, $hopperMode, $extension, $asteriskServerId, $clientId,$user_id);
                    $response["dail_next_lead"] = $addResponse;
                    //return array('status' => false, 'message' => "Incorrect lead value");
                }
            } elseif ($dialMode['dial_mode'] == 'predictive_dial') {

                $sql = "SELECT column_name FROM list_header  WHERE list_id = :list_id AND is_dialing = :is_dialing";
                $listHeader = DB::connection('mysql_' . $db)->selectOne($sql, ['list_id' => $lead['list_id'], 'is_dialing' => 1]);
                $listHeader = (array)$listHeader;

                $sql = "SELECT * FROM list_data  WHERE id = :id";
                $listData = DB::connection('mysql_' . $db)->selectOne($sql, ['id' => $lead['lead_id']]);
                $listData = (array)$listData;
                $number = $listData[$listHeader['column_name']];

                if (!empty($number)) {
                    /* $sql = "UPDATE extension_live SET lead_id = :lead_id, campaign_id = :campaign_id WHERE extension = :extension";
                    DB::connection('mysql_' . $db)->update($sql,
                        [
                            'extension' => $extension,
                            'lead_id' => $lead['lead_id'],
                            'campaign_id' => $campaignId
                        ]
                    );*/

                    $asterisk = $this->getAsterisk($asteriskServerId, $extension, $clientId);
                    $response = $asterisk->predictiveDial($number, $campaignId, $lead['lead_id'], $clientId);
                    if ($response == true) {
                        $this->addToLeadReport('mysql_' . $db, $campaignId, $lead['list_id'], $lead['lead_id'], 0);
                        return array('status' => true, 'message' => "Call connected Predictive Dial Calls");
                    } else {
                        return array('status' => false, 'message' => "Unable to connect call Predictive Dial");
                    }
                } else {
                    return array('status' => false, 'message' => "Incorrect lead value Predictive dial");
                }
            } elseif ($dialMode['dial_mode'] == 'outbound_ai') {

                $sql = "SELECT column_name FROM list_header  WHERE list_id = :list_id AND is_dialing = :is_dialing";
                $listHeader = DB::connection('mysql_' . $db)->selectOne($sql, ['list_id' => $lead['list_id'], 'is_dialing' => 1]);
                $listHeader = (array)$listHeader;

                $sql = "SELECT * FROM list_data  WHERE id = :id";
                $listData = DB::connection('mysql_' . $db)->selectOne($sql, ['id' => $lead['lead_id']]);
                $listData = (array)$listData;
                $number = $listData[$listHeader['column_name']];

                if (!empty($number)) {
                    /* $sql = "UPDATE extension_live SET lead_id = :lead_id, campaign_id = :campaign_id WHERE extension = :extension";
                    DB::connection('mysql_' . $db)->update($sql,
                        [
                            'extension' => $extension,
                            'lead_id' => $lead['lead_id'],
                            'campaign_id' => $campaignId
                        ]
                    );*/

                    $asterisk = $this->getAsterisk($asteriskServerId, $extension, $clientId);
                    $response = $asterisk->outboundAIDial($number, $campaignId, $lead['lead_id'], $clientId);
                    if ($response == true) {
                        $this->addToLeadReport('mysql_' . $db, $campaignId, $lead['list_id'], $lead['lead_id'], 0);
                        return array('status' => true, 'message' => "Call connected Outbound AI Dial Calls");
                    } else {
                        return array('status' => false, 'message' => "Unable to connect call Outbound AI Dial");
                    }
                } else {
                    return array('status' => false, 'message' => "Incorrect lead value Outbound AI dial");
                }
            } else {

                $insertSql = "INSERT INTO extension_live (extension, status, campaign_id, lead_id)
                          VALUES (:extension, :status, :campaign_id, :lead_id)
                          ON DUPLICATE KEY UPDATE  status = :status_1, campaign_id = :campaign_id_1, lead_id = :lead_id_1";
                DB::connection('mysql_' . $db)->insert(
                    $insertSql,
                    array(
                        'extension' => $extension,
                        'status' => 0,
                        'campaign_id' => $campaignId,
                        'lead_id' => $lead['lead_id'],
                        'status_1' => 0,
                        'campaign_id_1' => $campaignId,
                        'lead_id_1' => $lead['lead_id']
                    )
                );
                $this->addToLeadReport('mysql_' . $db, $campaignId, $lead['list_id'], $lead['lead_id'], 0);
                return array('status' => true, 'message' => "Call connected");
            }
        }
        $this->extensionLogout($user_id, $clientId, $asteriskServerId);
        return $response;
    }

    /*
     * disposition Campaign
     * @return array
     */
    public function dispositionCampaign($request)
    {
        $sql = "SELECT id, title FROM disposition WHERE is_deleted = 0 ";
        $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql);
        $dataClient = (array)$record;
        if (!empty($dataClient)) {
            return array(
                'success' => 'true',
                'message' => 'Dispositions detail.',
                'data' => $dataClient
            );
        }
        return array(
            'success' => 'false',
            'message' => 'Dispositions not created.',
            'data' => array()
        );
    }

 
// public function dispositionByCampaignId(int $campaignId, int $clientId)
// {

// $sql = "SELECT d.id, d.title, d.enable_sms, d.d_type 
//         FROM campaign_disposition AS cd 
//         INNER JOIN disposition AS d 
//             ON cd.disposition_id = d.id  
//         WHERE cd.is_deleted = :cd_deleted 
//           AND d.is_deleted = :d_deleted 
//           AND cd.campaign_id = :campaign_id
//           AND d.title != ''";


// $record = DB::connection("mysql_$clientId")->select($sql, [
//     'cd_deleted' => 0,
//     'd_deleted' => 0,
//     'campaign_id' => $campaignId
// ]);

//     $data = (array) $record;

//     if (!empty($data)) {
//         return [
//             'success' => 'true',
//             'message' => 'Dispositions detail.',
//             'data' => $data
//         ];
//     }

//     return [
//         'success' => 'false',
//         'message' => 'Dispositions not created.',
//         'data' => []
//     ];
// }
public function dispositionByCampaignId(int $campaignId, int $clientId)
{
    $sql = "
        SELECT 
            d.id,
            d.title,
            d.enable_sms,
            d.d_type
        FROM campaign_disposition AS cd
        INNER JOIN disposition AS d
            ON cd.disposition_id = d.id
        WHERE cd.is_deleted = :cd_deleted
          AND d.is_deleted = :d_deleted
          AND d.status = 1
          AND cd.campaign_id = :campaign_id
          AND d.title != ''
    ";

    $record = DB::connection("mysql_$clientId")->select($sql, [
        'cd_deleted'  => 0,
        'd_deleted'   => 0,
        'campaign_id' => $campaignId
    ]);

    $data = (array) $record;

    if (!empty($data)) {
        return [
            'success' => 'true',
            'message' => 'Dispositions detail.',
            'data' => $data
        ];
    }

    return [
        'success' => 'false',
        'message' => 'Dispositions not created.',
        'data' => []
    ];
}


    // public function getLead(int $parentId, int $extension)
    // {
    //     $data = array();
    //     $number = null;
    //     $connection = "mysql_$parentId";

    //     //Fetch current lead
    //     $sql = "SELECT * FROM extension_live  WHERE extension = :extension";
    //     $extensionLive = DB::connection($connection)->selectOne($sql, array('extension' => $extension));
    //     $extensionLive = (array)$extensionLive;
    //     if (!empty($extensionLive) && !empty($extensionLive['lead_id'])) {
    //         $lead = $extensionLive['lead_id'];
    //         $sql = "SELECT * FROM list_data  WHERE id = :id";
    //         $listData = DB::connection($connection)->selectOne($sql, array('id' => $lead));
    //         $listData = (array)$listData;
    //         $listId = $listData['list_id'];

    //         $listHeaders = ListHeader::on($connection)->where([
    //             ["is_visible", "=", 1],
    //             ["is_deleted", "=", 0],
    //             ["list_id", "=", $listId]
    //         ])->get()->all();

    //         foreach ($listHeaders as $header) {
    //             if ($header->is_dialing == 1) $number = $listData[$header->column_name];
    //             $title = null;
    //             if (!empty($header->label_id)) {
    //                 $label = \App\Model\Client\Label::on($connection)->find($header->label_id);
    //                 if ($label) $title = $label->title;
    //             }
    //             $data[$header->column_name] = [
    //                 "label" => $title,
    //                 "value" => $listData[$header->column_name],
    //                 "is_dialing" => $header->is_dialing,
    //                 "is_visible" => $header->is_visible,
    //                 "is_editable" => $header->is_editable,
    //                 "alternate_phone" => $header->alternate_phone
    //             ];
    //         }

    //         $number = preg_replace('/[^0-9]/', '', $number);

    //         return [
    //             'success' => true,
    //             'message' => 'lead detail.',
    //             'number' => $number,
    //             'lead_id' => $lead,
    //             'list_id' => $listId,
    //             'data' => $data
    //         ];
    //     }

    //     return [
    //         'success' => false,
    //         'message' => "Not on a call",
    //         'number' => null,
    //         'lead_id' => null,
    //         'data' => $data
    //     ];
    // }
public function getLead(int $parentId, int $extension)
{
    $data = [];
    $number = null;
    $connection = "mysql_$parentId";

    // Fetch current lead
    $sql = "SELECT * FROM extension_live WHERE extension = :extension";
    $extensionLive = DB::connection($connection)->selectOne($sql, ['extension' => $extension]);

    if ($extensionLive && !empty($extensionLive->lead_id)) {

        $lead = $extensionLive->lead_id;

        // Get list data
        $sql = "SELECT * FROM list_data WHERE id = :id";
        $listData = DB::connection($connection)->selectOne($sql, ['id' => $lead]);
        $listData = (array) $listData;

        $listId = $listData['list_id'];

        // Get visible headers
        $listHeaders = ListHeader::on($connection)
            ->where('is_visible', 1)
            ->where('is_deleted', 0)
            ->where('list_id', $listId)
            ->get();

        foreach ($listHeaders as $header) {

            // Detect dialing number
            if ($header->is_dialing == 1) {
                $number = $listData[$header->column_name] ?? null;
            }

            // Get label title
            $title = null;
            if (!empty($header->label_id)) {
                $label = \App\Model\Client\Label::on($connection)->find($header->label_id);
                $title = $label ? $label->title : null;
            }

            // Push instead of associative index
            $data[] = [
                "label" => $title,
                "value" => $listData[$header->column_name] ?? null,
                "is_dialing" => $header->is_dialing,
                "is_visible" => $header->is_visible,
                "is_editable" => $header->is_editable,
                "alternate_phone" => $header->alternate_phone
            ];
        }

        // Clean number
        $number = preg_replace('/[^0-9]/', '', $number);

        return [
            'success' => true,
            'message' => 'lead detail.',
            'number' => $number,
            'lead_id' => $lead,
            'list_id' => $listId,
            'data' => $data
        ];
    }

    return [
        'success' => false,
        'message' => "Not on a call",
        'number' => null,
        'lead_id' => null,
        'data' => []
    ];
}

    /*
     * save disposition
     * @return array
     */
    public function saveDisposition($request)
    {
        $response = [
            "extension_update" => false,
            "cdr_update" => false
        ];

        $extension = $request->auth->extension;
        $intWebPhoneSetting = DialerController::getWebPhonestatus($request->auth->id, $request->auth->parent_id);
        if ($intWebPhoneSetting == 1) {
            $extension = $request->auth->alt_extension;
        }

        /* new code implement*/

        $dataUser = User::where('id', $request->auth->id)->get()->first();

        $dialer_mode = $request->has('dialer_mode')
            ? intval($request->input('dialer_mode'))
            : $dataUser->dialer_mode;

        if ($dialer_mode == 3) {
            $extension = $dataUser->app_extension;
        } else
            if ($dialer_mode == 2) {
            $extension = $request->auth->alt_extension;
        } else
            if ($dialer_mode == 1) {
            $extension =  $request->auth->extension;
        }

        //echo $extension;die;

        /*close new code implement*/

        $db = "mysql_" . $request->auth->parent_id;
        $campaignId = $request->input('campaign_id');
        $leadId = $request->input('lead_id');
        $dispositionId = $request->input('disposition_id');
        // Default pause_calling=0 (continue dialing) and api_call=0 when not provided
        $pauseCalling = $request->input('pause_calling') ?? 0;
        $callBack = $request->input('call_back');
        $comment = $request->input('comment');
        $apiCall = $request->input('api_call') ?? 0;
        //Update extension_live table
        $status = ($pauseCalling == 1) ? 3 : 0;
        $sql = "";
        $profiler = new ExecutionProfiler();

        try {
            $extensionLive = ExtensionLive::on($db)->findOrFail($extension);
            $extensionLive->status = $status;
            $extensionLive->lead_id = null;
            $extensionLive->saveOrFail();
            $response["extension_update"] = true;
        } catch (\Exception $exception) {
            Log::error("Failed to update extension_live", [
                "message" => $exception->getMessage(),
                "file" => $exception->getFile(),
                "line" => $exception->getLine()
            ]);
        }
        $profiler->addInterval("extension_live_updated");

        //Cdr Detail
        try {
            $sql = "SELECT id, number FROM cdr WHERE lead_id = :lead_id AND campaign_id = :campaign_id AND extension = :extension ORDER BY id DESC LIMIT 1";
            $cdr = DB::connection($db)->selectOne($sql, array('lead_id' => $leadId, 'campaign_id' => $campaignId, 'extension' => $extension));
            $cdr = (array)$cdr;
            $response["cdr_update"] = $cdr;
        } catch (\Exception $exception) {
            Log::error("Failed to fetch cdr", [
                "sql" => $sql,
                "message" => $exception->getMessage()
            ]);
        }
        $profiler->addInterval("cdr_updated");


        // Disposition Detail
        try {
            $sql = "SELECT * FROM disposition WHERE id=:dispositionId";
            $dispositionDetail = DB::connection($db)->selectOne($sql, array('dispositionId' => $dispositionId));
            $dispositionDetail = (array)$dispositionDetail;
        } catch (\Exception $exception) {
            Log::error("Failed to fetch disposition", [
                "sql" => $sql,
                "message" => $exception->getMessage()
            ]);
        }
        $profiler->addInterval("disposition_updated");

        if (!empty($cdr)) {
            $number = $cdr['number'];
            $id = $cdr['id'];
        }

        //Update PowerDialCDR
        if (!empty($id)) {
            try {
                $sql = "UPDATE cdr set disposition_id = :disposition_id WHERE id = :id";
                DB::connection($db)->update($sql, array('id' => $id, 'disposition_id' => $dispositionId));
            } catch (\Exception $exception) {
                Log::error("Failed to update disposition", [
                    "sql" => $sql,
                    "message" => $exception->getMessage()
                ]);
            }
        } else {
            try {
                $sql = "UPDATE cdr set disposition_id = :disposition_id WHERE extension = :extension AND lead_id = :lead_id AND campaign_id = :campaign_id ORDER BY id DESC";
                DB::connection($db)->update($sql, array('lead_id' => $leadId, 'campaign_id' => $campaignId, 'extension' => $extension, 'disposition_id' => $dispositionId));
            } catch (\Exception $exception) {
                Log::error("Failed to update disposition", [
                    "sql" => $sql,
                    "message" => $exception->getMessage()
                ]);
            }
        }
        $profiler->addInterval("cdr_updated_2");

        //Update lead_report
        try {
            $sql = "UPDATE lead_report SET disposition_id = :disposition_id WHERE campaign_id = :campaign_id AND lead_id = :lead_id";
            DB::connection($db)->update($sql, array('campaign_id' => $campaignId, 'disposition_id' => $dispositionId, 'lead_id' => $leadId));
        } catch (\Exception $exception) {
            Log::error("Failed to update lead_report", [
                "sql" => $sql,
                "message" => $exception->getMessage()
            ]);
        }
        $profiler->addInterval("lead_report_updated");

        //Add comment
        if (!empty($comment) && !empty($id)) {
            try {
                $sql = "INSERT IGNORE INTO comment (extension, campaign_id, cdr_id, lead_id, comment) VALUE (:extension, :campaign_id, :cdr_id, :lead_id, :comment)";
                DB::connection($db)->insert($sql, array('extension' => $extension, 'campaign_id' => $campaignId, 'cdr_id' => $id, 'lead_id' => $leadId, 'comment' => $comment));
            } catch (\Exception $exception) {
                Log::error("Failed to add comment", [
                    "sql" => $sql,
                    "message" => $exception->getMessage()
                ]);
            }
            $profiler->addInterval("comment_added");
        }

        //Add callback time
        //$id=11;
        if (!empty($callBack) && !empty($id) && $dispositionDetail['d_type'] == 3) {
            try {
                $sql = "INSERT IGNORE INTO callback (extension, campaign_id, cdr_id, lead_id, callback_time) VALUE (:extension, :campaign_id, :cdr_id, :lead_id, :callback_time)";
                DB::connection($db)->insert($sql, array('extension' => $extension, 'campaign_id' => $campaignId, 'cdr_id' => $id, 'lead_id' => $leadId, 'callback_time' => $callBack));


                $schedule = new Schedule();
                $schedule->setConnection($db);
                $schedule->title = $request->input('full_name');
                $schedule->description = $request->input('callback_comment');
                $schedule->start_datetime = $callBack;
                $schedule->end_datetime = $callBack;
                $schedule->user_id = $request->auth->id;
                // $schedule->timezone = 'sssss';

                $schedule->save();
            } catch (\Exception $exception) {
                Log::error("Failed to add callback", [
                    "sql" => $sql,
                    "message" => $exception->getMessage()
                ]);
            }
            $profiler->addInterval("callback_added");
        }
        //update dnc
        $dncArray = array('1', '2', '5', '9', '3');
        //if(!empty($number) && in_array($dispositionId, $dncArray))


        if (!empty($number) && $dispositionDetail['d_type'] == 2) {
            $comment = "No Comments";
            // switch ($dispositionId){
            //     case '1':
            //         $comment = "Do not Call";
            //         break;
            //     case '2':
            //         $comment = "Wrong number";
            //         break;
            //     case '5':
            //         $comment = "Disconnected Number";
            //         break;
            //     case '9':
            //         $comment = "Disconnected or Wrong Number";
            //         break;
            //     default:
            //         $comment = "Sale Made";
            // }

            try {
                $sql = "INSERT INTO dnc (number, extension, comment) VALUE (:number, :extension, :comment)";
                DB::connection($db)->insert($sql, array('number' => $number, 'extension' => $extension, 'comment' => $comment));
            } catch (\Exception $exception) {
                Log::error("Failed to add dnc", [
                    "sql" => $sql,
                    "message" => $exception->getMessage()
                ]);
            }
            $profiler->addInterval("dnc_added");
        }

        //call Api
        if ($apiCall == '1') {
            try {
                $callApi = $this->apiData($extension, $request->auth->parent_id, $db, $campaignId, $leadId, $dispositionId, $number);
                if (!empty($callApi)) {
                    $res = $this->callAPI($callApi['method'], $callApi['url'], $callApi['data']);
                    //$this->database->setData('api_log', array('extension' => $extension, 'campaign_id' =>$campaignId, 'lead_id' => $leadId, 'type' => 'post', 'url' => addslashes(htmlentities($value['url'])) , 'data' => addslashes(htmlentities(json_encode($value['data']))), 'response' => addslashes(htmlentities($res))));
                }
            } catch (\Exception $exception) {
                Log::error("Failed to call API", [
                    "message" => $exception->getMessage()
                ]);
            }
            $profiler->addInterval("api_call");
        }
        if ($pauseCalling != 1) {
            try {
                $modeType = $this->getHopperModeInCampaign($campaignId, $request->auth->parent_id);
                $addResponse = $this->addLeadToExtensionLive(
                    $request->input('campaign_id'),
                    $modeType['hopper_mode'],
                    $extension,
                    $request->auth->asterisk_server_id,
                    $request->auth->parent_id,
                    $request->auth->id,
                    $dialer_mode  // pass WebRTC mode so next lead uses correct extension
                );
                $response["dail_next_lead"] = $addResponse;
            } catch (\Exception $exception) {
                Log::error("Failed to to dial next lead", [
                    "message" => $exception->getMessage()
                ]);
            }
            $profiler->addInterval("dail_next_lead");

            /*if($addResponse != true)
            {
                return array(
                    'success' => 'false',
                    'message' => 'Disposition saved successfully, but Unable to dial next lead'
                );
            }*/
        }
        $profile = $profiler->calculate();
        $response["execution_time"] = $profile;
        Log::info("Dailer.saveDisposition.response", $response);
        return $response;
    }


    public function redialCall($request)
    {
        $response = [
            "extension_update" => false,
            "cdr_update" => false
        ];

        $extension = $request->auth->extension;
        $intWebPhoneSetting = DialerController::getWebPhonestatus($request->auth->id, $request->auth->parent_id);
        if ($intWebPhoneSetting == 1) {
            $extension = $request->auth->alt_extension;
        }

        /* new code implement*/

        $dataUser = User::where('id', $request->auth->id)->get()->first();

        $dialer_mode = $request->has('dialer_mode')
            ? intval($request->input('dialer_mode'))
            : $dataUser->dialer_mode;

        if ($dialer_mode == 3) {
            $extension = $dataUser->app_extension;
        } else
            if ($dialer_mode == 2) {
            $extension = $request->auth->alt_extension;
        } else
            if ($dialer_mode == 1) {
            $extension =  $request->auth->extension;
        }

        //echo $extension;die;

        /*close new code implement*/

        $db = "mysql_" . $request->auth->parent_id;
        $campaignId = $request->input('campaign_id');
        $leadId = $request->input('lead_id');
        $dispositionId = $request->input('disposition_id');
        $pauseCalling = $request->input('pause_calling');
        $callBack = $request->input('call_back');
        $comment = $request->input('comment');
        $listId = $request->input('listId');

        $apiCall = $request->input('api_call');
        //Update extension_live table
        $status = ($pauseCalling == 1) ? 3 : 0;
        $sql = "";
        $profiler = new ExecutionProfiler();

        $redial = 1;


        if ($redial == 1) {

            $this->addToLeadTempTableRedialCall($db, $campaignId, $listId, $leadId, 0);
        }

        try {
            $extensionLive = ExtensionLive::on($db)->findOrFail($extension);
            $extensionLive->status = $status;
            $extensionLive->lead_id = null;
            $extensionLive->saveOrFail();
            $response["extension_update"] = true;
        } catch (\Exception $exception) {
            Log::error("Failed to update extension_live", [
                "message" => $exception->getMessage(),
                "file" => $exception->getFile(),
                "line" => $exception->getLine()
            ]);
        }
        $profiler->addInterval("extension_live_updated");

        //Cdr Detail
        try {
            $sql = "SELECT id, number FROM cdr WHERE lead_id = :lead_id AND campaign_id = :campaign_id AND extension = :extension ORDER BY id DESC LIMIT 1";
            $cdr = DB::connection($db)->selectOne($sql, array('lead_id' => $leadId, 'campaign_id' => $campaignId, 'extension' => $extension));
            $cdr = (array)$cdr;
            $response["cdr_update"] = $cdr;
        } catch (\Exception $exception) {
            Log::error("Failed to fetch cdr", [
                "sql" => $sql,
                "message" => $exception->getMessage()
            ]);
        }
        $profiler->addInterval("cdr_updated");


        // Disposition Detail
        try {
            $sql = "SELECT * FROM disposition WHERE id=:dispositionId";
            $dispositionDetail = DB::connection($db)->selectOne($sql, array('dispositionId' => $dispositionId));
            $dispositionDetail = (array)$dispositionDetail;
        } catch (\Exception $exception) {
            Log::error("Failed to fetch disposition", [
                "sql" => $sql,
                "message" => $exception->getMessage()
            ]);
        }
        $profiler->addInterval("disposition_updated");

        if (!empty($cdr)) {
            $number = $cdr['number'];
            $id = $cdr['id'];
        }

        //Update PowerDialCDR
        if (!empty($id)) {
            try {
                $sql = "UPDATE cdr set disposition_id = :disposition_id WHERE id = :id";
                DB::connection($db)->update($sql, array('id' => $id, 'disposition_id' => $dispositionId));
            } catch (\Exception $exception) {
                Log::error("Failed to update disposition", [
                    "sql" => $sql,
                    "message" => $exception->getMessage()
                ]);
            }
        } else {
            try {
                $sql = "UPDATE cdr set disposition_id = :disposition_id WHERE extension = :extension AND lead_id = :lead_id AND campaign_id = :campaign_id ORDER BY id DESC";
                DB::connection($db)->update($sql, array('lead_id' => $leadId, 'campaign_id' => $campaignId, 'extension' => $extension, 'disposition_id' => $dispositionId));
            } catch (\Exception $exception) {
                Log::error("Failed to update disposition", [
                    "sql" => $sql,
                    "message" => $exception->getMessage()
                ]);
            }
        }
        $profiler->addInterval("cdr_updated_2");

        //Update lead_report
        try {
            $sql = "UPDATE lead_report SET disposition_id = :disposition_id WHERE campaign_id = :campaign_id AND lead_id = :lead_id";
            DB::connection($db)->update($sql, array('campaign_id' => $campaignId, 'disposition_id' => $dispositionId, 'lead_id' => $leadId));
        } catch (\Exception $exception) {
            Log::error("Failed to update lead_report", [
                "sql" => $sql,
                "message" => $exception->getMessage()
            ]);
        }
        $profiler->addInterval("lead_report_updated");

        //Add comment
        if (!empty($comment) && !empty($id)) {
            try {
                $sql = "INSERT IGNORE INTO comment (extension, campaign_id, cdr_id, lead_id, comment) VALUE (:extension, :campaign_id, :cdr_id, :lead_id, :comment)";
                DB::connection($db)->insert($sql, array('extension' => $extension, 'campaign_id' => $campaignId, 'cdr_id' => $id, 'lead_id' => $leadId, 'comment' => $comment));
            } catch (\Exception $exception) {
                Log::error("Failed to add comment", [
                    "sql" => $sql,
                    "message" => $exception->getMessage()
                ]);
            }
            $profiler->addInterval("comment_added");
        }

        //Add callback time
        if (!empty($callBack) && !empty($id) && $dispositionDetail['d_type'] == 3) {
            try {
                $sql = "INSERT IGNORE INTO callback (extension, campaign_id, cdr_id, lead_id, callback_time) VALUE (:extension, :campaign_id, :cdr_id, :lead_id, :callback_time)";
                DB::connection($db)->insert($sql, array('extension' => $extension, 'campaign_id' => $campaignId, 'cdr_id' => $id, 'lead_id' => $leadId, 'callback_time' => $callBack));
            } catch (\Exception $exception) {
                Log::error("Failed to add callback", [
                    "sql" => $sql,
                    "message" => $exception->getMessage()
                ]);
            }
            $profiler->addInterval("callback_added");
        }
        //update dnc
        $dncArray = array('1', '2', '5', '9', '3');
        //if(!empty($number) && in_array($dispositionId, $dncArray))


        if (!empty($number) && $dispositionDetail['d_type'] == 2) {
            $comment = "No Comments";
            // switch ($dispositionId){
            //     case '1':
            //         $comment = "Do not Call";
            //         break;
            //     case '2':
            //         $comment = "Wrong number";
            //         break;
            //     case '5':
            //         $comment = "Disconnected Number";
            //         break;
            //     case '9':
            //         $comment = "Disconnected or Wrong Number";
            //         break;
            //     default:
            //         $comment = "Sale Made";
            // }

            try {
                $sql = "INSERT INTO dnc (number, extension, comment) VALUE (:number, :extension, :comment)";
                DB::connection($db)->insert($sql, array('number' => $number, 'extension' => $extension, 'comment' => $comment));
            } catch (\Exception $exception) {
                Log::error("Failed to add dnc", [
                    "sql" => $sql,
                    "message" => $exception->getMessage()
                ]);
            }
            $profiler->addInterval("dnc_added");
        }

        //call Api
        if ($apiCall == '1') {
            try {
                $callApi = $this->apiData($extension, $request->auth->parent_id, $db, $campaignId, $leadId, $dispositionId, $number);
                if (!empty($callApi)) {
                    $res = $this->callAPI($callApi['method'], $callApi['url'], $callApi['data']);
                    //$this->database->setData('api_log', array('extension' => $extension, 'campaign_id' =>$campaignId, 'lead_id' => $leadId, 'type' => 'post', 'url' => addslashes(htmlentities($value['url'])) , 'data' => addslashes(htmlentities(json_encode($value['data']))), 'response' => addslashes(htmlentities($res))));
                }
            } catch (\Exception $exception) {
                Log::error("Failed to call API", [
                    "message" => $exception->getMessage()
                ]);
            }
            $profiler->addInterval("api_call");
        }
        if ($pauseCalling != 1) {
            try {
                $modeType = $this->getHopperModeInCampaign($campaignId, $request->auth->parent_id);
                $addResponse = $this->addLeadToExtensionLive(
                    $request->input('campaign_id'),
                    $modeType['hopper_mode'],
                    $extension,
                    $request->auth->asterisk_server_id,
                    $request->auth->parent_id,
                    $request->auth->id,
                    $dialer_mode  // pass WebRTC mode so next lead uses correct extension
                );
                $response["dail_next_lead"] = $addResponse;
            } catch (\Exception $exception) {
                Log::error("Failed to to dial next lead", [
                    "message" => $exception->getMessage()
                ]);
            }
            $profiler->addInterval("dail_next_lead");

            /*if($addResponse != true)
            {
                return array(
                    'success' => 'false',
                    'message' => 'Disposition saved successfully, but Unable to dial next lead'
                );
            }*/
        }
        $profile = $profiler->calculate();
        $response["execution_time"] = $profile;
        Log::info("Dailer.saveDisposition.response", $response);
        return $response;
    }

    /*
     * Insert data in lead report
     * @return boolean
     */
    public function addToLeadReport($db, $campaignId, $listId, $leadId, $dispositionId)
    {
        $insertSql = "INSERT INTO lead_report (campaign_id, list_id, lead_id, disposition_id) VALUE (:campaign_id, :list_id, :lead_id, :disposition_id) ON DUPLICATE KEY UPDATE disposition_id = :disposition_id_1";
        return DB::connection($db)->insert(
            $insertSql,
            array(
                'campaign_id' => $campaignId,
                'list_id' => $listId,
                'lead_id' => $leadId,
                'disposition_id' => $dispositionId,
                'disposition_id_1' => $dispositionId
            )
        );
    }
    // public function addToLeadReport($db, $campaignId, $listId, $leadId, $dispositionId)
    // {
    //     // ✅ 1. Insert / update lead report
    //   DB::connection($db)->insert(
    //         "INSERT INTO lead_report (campaign_id, list_id, lead_id, disposition_id)
    //          VALUES (:campaign_id, :list_id, :lead_id, :disposition_id)
    //          ON DUPLICATE KEY UPDATE disposition_id = :disposition_id_1",
    //         [
    //             'campaign_id' => $campaignId,
    //             'list_id' => $listId,
    //             'lead_id' => $leadId,
    //             'disposition_id' => $dispositionId,
    //             'disposition_id_1' => $dispositionId
    //         ]
    //     );
    
    //     return true;
    // }
    
    public function addToLeadTempTable($db, $campaignId, $listId, $leadId, $dispositionId)
    {
        $insertSql = "INSERT INTO lead_temp (campaign_id, list_id, lead_id) VALUE (:campaign_id, :list_id, :lead_id) ON DUPLICATE KEY UPDATE lead_id = :lead_id_1";
        return DB::connection($db)->insert(
            $insertSql,
            array(
                'campaign_id' => $campaignId,
                'list_id' => $listId,
                'lead_id' => $leadId,
                'lead_id_1' => $leadId
            )
        );
    }


    public function addToLeadTempTableRedialCall($db, $campaignId, $listId, $leadId, $dispositionId)
    {
        $redial = '1';
        $insertSql = "INSERT INTO lead_temp (campaign_id, list_id, lead_id, redial) VALUE (:campaign_id, :list_id, :lead_id, :redial) ON DUPLICATE KEY UPDATE lead_id = :lead_id_1";
        return DB::connection($db)->insert(
            $insertSql,
            array(
                'campaign_id' => $campaignId,
                'list_id' => $listId,
                'lead_id' => $leadId,
                'lead_id_1' => $leadId,
                'redial' => $redial
            )
        );
    }

    /*
     *DTMF
     *@param object $request
     *@return array
     */
    public function dtmf($request)
    {
        try {
            $extension = $request->auth->extension;
            $intWebPhoneSetting = DialerController::getWebPhonestatus($request->auth->id, $request->auth->parent_id);
            if ($intWebPhoneSetting == 1) {
                $extension = $request->auth->alt_extension;
            }

            /* new code implement*/

            $dataUser = User::where('id', $request->auth->id)->get()->first();

            $dialer_mode = $request->has('dialer_mode')
            ? intval($request->input('dialer_mode'))
            : $dataUser->dialer_mode;

            if ($dialer_mode == 3) {
                $extension = $dataUser->app_extension;
            } else
            if ($dialer_mode == 2) {
                $extension = $request->auth->alt_extension;
            } else
            if ($dialer_mode == 1) {
                $extension =  $request->auth->extension;
            }

            //echo $extension;die;

            /*close new code implement*/

            $asterisk = $this->getAsterisk($request->auth->asterisk_server_id, $extension, $request->auth->parent_id);
            $response = $asterisk->dtmf($request->input('number'));
            if ($response['status'] == true) {
                return array(
                    'success' => 'true',
                    'message' => 'DTMF dialed successfully'
                );
            } else {
                return array(
                    'success' => 'false',
                    'message' => $response['message']
                );
            }
        } catch (Exception $e) {
            echo $e->getMessage();
        } catch (InvalidArgumentException $e) {
            echo $e->getMessage();
        }
    }

    /*
     *Voicemail Drop
     *@param object $request
     *@return array
     */
    public function voicemailDrop($request)
    {

        try {
            $extension = $request->auth->extension;
            $intWebPhoneSetting = DialerController::getWebPhonestatus($request->auth->id, $request->auth->parent_id);
            if ($intWebPhoneSetting == 1) {
                $extension = $request->auth->alt_extension;
            }

            /* new code implement*/

            $dataUser = User::where('id', $request->auth->id)->get()->first();

            $dialer_mode = $request->has('dialer_mode')
            ? intval($request->input('dialer_mode'))
            : $dataUser->dialer_mode;

            if ($dialer_mode == 3) {
                $extension = $dataUser->app_extension;
            } else
            if ($dialer_mode == 2) {
                $extension = $request->auth->alt_extension;
            } else
            if ($dialer_mode == 1) {
                $extension =  $request->auth->extension;
            }

            //echo $extension;die;

            /*close new code implement*/

            $asterisk = $this->getAsterisk($request->auth->asterisk_server_id, $extension, $request->auth->parent_id);
            $response = $asterisk->voicemailDrop();
            if ($response['status'] == true) {
                return array(
                    'success' => 'true',
                    'message' => 'Voice mail dropped'
                );
            } else {
                return array(
                    'success' => 'false',
                    'message' => $response['message']
                );
            }
        } catch (Exception $e) {
            echo $e->getMessage();
        } catch (InvalidArgumentException $e) {
            echo $e->getMessage();
        }
    }

    public function apiData($extension, $adminId, $db, $campaignId, $leadId, $dispositionId, $number = '')
    {
        if (empty($number)) {
            $sql = "SELECT number FROM cdr WHERE extension = :extension AND lead_id = :lead AND campaign_id = :campaign order by id desc limit 0,1";
            $cdr = DB::connection($db)->selectOne($sql, array('extension' => $extension, 'lead' => $leadId, 'campaign' => $campaignId));
            $cdr = (array)$cdr;
            if (!empty($cdr)) {
                $number = $cdr['number'];
            }
        }
        $param = array(
            'extension' => $extension,
            'campaignId' => $campaignId,
            'leadId' => $leadId,
            'dispositionId' => $dispositionId,
            'dialed_number' => $number,
            'adminAccountNum' => $adminId
        );
        return $this->getApiData($param, $db);
    }

    /*

    changes related to two or more api url added and send to crm show both urls.

    public function getApiData($param = array(), $db)
    {
        $sql = "SELECT id, url, method FROM api WHERE is_deleted = :is_deleted AND campaign_id = :campaign";
        $api = DB::connection($db)->selectOne($sql, array('is_deleted' => 0, 'campaign' => $param['campaignId']));
        $api = (array)$api;
        if (!empty($api)) {
            $returnArray = array();
            $url = $api['url'];
            $method = $api['method'];
            $apiId = $api['id'];
            if ($param['dispositionId'] != 0) {
                $sql = "SELECT disposition_id FROM api_disposition WHERE api_id = :api_id AND disposition_id = :disposition_id";
                $apiDisposition = DB::connection($db)->selectOne($sql, array('api_id' => $apiId, 'disposition_id' => $param['dispositionId']));
                $apiDisposition = (array)$apiDisposition;
                if (empty($apiDisposition)) {
                    return;
                }
            }
            if (!empty($url)) {
                $data = array();
                $sql = "SELECT parameter, value, type FROM api_parameter WHERE api_id = :api_id";
                $apiLabelParam = DB::connection($db)->select($sql, array('api_id' => $apiId));
                foreach ( $apiLabelParam as $key1 => $value1 ) {

                    $data[$value1->parameter] = ($value1->type == "label") ? $this->leadColumnData($param['leadId'], $value1->value, $db) : $value1->value;
                }
                $param['api_id'] = $apiId;
                $res = $this->getCustomApiData($param, $data, $db);
                if (!empty($res) && is_array($res)) {
                    $returnData = array_merge($data, $res);
                } else {
                    $returnData = $data;
                }

                if (!empty($returnData)) {
                    $returnArray = array('method' => $method, 'url' => $url, 'data' => $returnData);
                }
            }
            return $returnArray;
        }
        return;
    }*/

    public function getApiData($param = array(), $db)
    {
        $sql = "SELECT id, url, method FROM api WHERE is_deleted = :is_deleted AND campaign_id = :campaign";
        $api = DB::connection($db)->select($sql, array('is_deleted' => 0, 'campaign' => $param['campaignId']));
        $api = (array)$api;
        if (!empty($api)) {
            $returnArray = array();
            foreach ($api as $item => $value) {
                $url[$item] = $value->url;
                $method[$item] = $value->method;
                $apiId = $value->id;

                if ($param['dispositionId'] != 0) {
                    $sql = "SELECT disposition_id FROM api_disposition WHERE api_id = :api_id AND disposition_id = :disposition_id";
                    $apiDisposition = DB::connection($db)->selectOne($sql, array('api_id' => $apiId, 'disposition_id' => $param['dispositionId']));
                    $apiDisposition = (array)$apiDisposition;
                    if (empty($apiDisposition)) {
                        return;
                    }
                }

                if (!empty($url)) {
                    $data = array();
                    $sql = "SELECT parameter, value, type FROM api_parameter WHERE api_id = :api_id";
                    $apiLabelParam = DB::connection($db)->select($sql, array('api_id' => $apiId));
                    foreach ($apiLabelParam as $key1 => $value1) {
                        $data[$value1->parameter] = ($value1->type == "label") ? $this->leadColumnData($param['leadId'], $value1->value, $db) : $value1->value;
                    }

                    $param['api_id'] = $apiId;
                    $res = $this->getCustomApiData($param, $method[$item], $data, $db);
                    if (!empty($res) && is_array($res)) {
                        $returnData[$item] = array_merge($data, $res);
                    } else {
                        $returnData[$item] = $data;
                    }

                    if (!empty($returnData)) {
                        $returnArray = array('param' => $param['api_id'], 'method' => $method, 'url' => $url, 'data' => $returnData);
                    }
                }
            }
            return $returnArray;
        }
        return;
    }

    public function leadColumnData($lead_id, $label_id, $db)
    {
        if (!empty($lead_id)) {
            $sql = "select * from list_data where id = :id";
            $leadDetail = DB::connection($db)->selectOne($sql, array('id' => $lead_id));
            $leadDetail = (array)$leadDetail;
            if (!empty($leadDetail)) {
                $listId = $leadDetail['list_id'];
                $sql = "select column_name from list_header where list_id = :list_id and label_id = :label_id";
                $column_name = DB::connection($db)->selectOne($sql, array('list_id' => $listId, 'label_id' => $label_id));
                $column_name = (array)$column_name;
                if (!empty($column_name)) {
                    $column = $column_name['column_name'];
                    if (!empty($leadDetail[$column])) {
                        return stripslashes(html_entity_decode($leadDetail[$column]));
                    } else {
                        return null;
                    }
                } else {
                    return null;
                }
            } else {
                return null;
            }
        } else {
            return null;
        }
    }

    public function getCustomApiData($param = array(), $method, $data = array(), $db)
    {
        $addData['phone'] = $param['extension'];
        $addData['phone_number'] = $param['dialed_number'];
        if ($method == 'get') {
            $addData['lead_id'] = $param['leadId'];
        }
        //$addData['leadId'] = $param['leadId'];
        $addData['SQLdate'] = date('Y-m-d H:i:s');
        $addData['campaign'] = 'test';
        $addData['server_ip'] = 'test';
        $addData['vendor_id'] = '';
        $addData['user'] = $param['extension'];
        if ($method == 'post') {
            $addData['sip_extension'] = $param['extension'];
            $addData['leadId'] = $param['leadId'];
        }

        $campaign = DB::connection($db)->selectOne("SELECT title FROM campaign WHERE id = :id", array('id' => $param['campaignId']));
        $campaign = (array)$campaign;
        if (!empty($campaign)) {
            $addData['campaign'] = $campaign['title'];
        }
        if (!empty($param['dispositionId']) && $param['dispositionId'] > 0) {
            $sql = "SELECT id, duration, call_recording FROM cdr WHERE extension = :extension AND lead_id = :lead_id AND campaign_id = :campaign_id AND disposition_id = :disposition order by id desc";
            $res = DB::connection($db)->selectOne($sql, array('extension' => $param['extension'], 'lead_id' => $param['leadId'], 'campaign_id' => $param['campaignId'], 'disposition' => $param['dispositionId']));
            $res = (array)$res;
            if (!empty($res)) {
                $addData['talk_time'] = $res['duration'];
                $addData['recording_filename'] = $res['call_recording'];
                $addData['leadID'] = $param['leadId'];
                $addData['server_ip'] = 'test123';
            }
            if (!empty($param['dispositionId']) && $param['dispositionId'] != 0) {
                $res = DB::connection($db)->selectOne("SELECT title as name FROM disposition WHERE id = :id", array('id' => $param['dispositionId']));
                $res = (array)$res;
                if (empty($res)) {
                    $res = DB::connection('master')->selectOne("SELECT title as name FROM disposition WHERE id = :id", array('id' => $param['dispositionId']));
                    $res = (array)$res;
                }
                if (!empty($res)) {
                    $addData['dispo'] = $res['name'];
                }
            }
        }
        return $addData;
    }

    function callAPI($method, $url, $data = false)
    {
        $curl = curl_init();

        switch ($method) {
            case "POST":
                curl_setopt($curl, CURLOPT_POST, 1);
                if ($data)
                    curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
                break;
            case "PUT":
                curl_setopt($curl, CURLOPT_PUT, 1);
                break;
            default:
                if ($data)
                    $url = sprintf("%s?%s", $url, http_build_query($data));
        }

        // Optional Authentication:
        //curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        //curl_setopt($curl, CURLOPT_USERPWD, "username:password");
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $result = curl_exec($curl);
        curl_close($curl);
        return $result;
    }
        // public function extensionlogout($request)
        // {
        //     Log::info('reached extension logout');
        //   $extension = $request->auth->extension;
        // $intWebPhoneSetting = DialerController::getWebPhonestatus($request->auth->id, $request->auth->parent_id);
        // if ($intWebPhoneSetting == 1) {
        //     $extension = $request->auth->alt_extension;
        // }

        // /* new code implement*/

        // $dataUser = User::where('id', $request->auth->id)->get()->first();

        // $dialer_mode = $dataUser->dialer_mode;

        // if ($dialer_mode == 3) {
        //     $extension = $dataUser->app_extension;
        // } else
        //     if ($dialer_mode == 2) {
        //     $extension = $request->auth->alt_extension;
        // } else
        //     if ($dialer_mode == 1) {
        //     $extension =  $request->auth->extension;
        // }
        // $asterisk = $this->getAsterisk($request->auth->asterisk_server_id, $extension, $request->auth->parent_id);
        // return $asterisk->asteriskLogout($request->auth->parent_id, $extension);
        // }
        public function extensionlogout($userId, $clientId, $asteriskServerId)
{
   Log::info('reached extension logout');
   
    $dataUser = User::find($userId);

    if (!$dataUser) {
        return false;
    }
  $intWebPhoneSetting = DialerController::getWebPhonestatus($userId, $clientId);
        if ($intWebPhoneSetting == 1) {
            $extension = $dataUser->extension;
        }
    $extension = $dataUser->extension;

    // Resolve correct extension based on dialer mode
    if ($dataUser->dialer_mode == 3) {
        $extension = $dataUser->app_extension;
    } elseif ($dataUser->dialer_mode == 2) {
        $extension = $dataUser->alt_extension;
    }elseif ($dataUser->dialer_mode == 1) {
            $extension =  $dataUser->extension;
        }

    $asterisk = $this->getAsterisk($asteriskServerId, $extension, $clientId);

    return $asterisk->asteriskLogout($clientId, $extension);
}
    public function logout($request)
    {
        $extension = $request->auth->extension;
        $intWebPhoneSetting = DialerController::getWebPhonestatus($request->auth->id, $request->auth->parent_id);
        if ($intWebPhoneSetting == 1) {
            $extension = $request->auth->alt_extension;
        }

        /* new code implement*/

        $dataUser = User::where('id', $request->auth->id)->get()->first();

        $dialer_mode = $request->has('dialer_mode')
            ? intval($request->input('dialer_mode'))
            : $dataUser->dialer_mode;

        if ($dialer_mode == 3) {
            $extension = $dataUser->app_extension;
        } else
            if ($dialer_mode == 2) {
            $extension = $request->auth->alt_extension;
        } else
            if ($dialer_mode == 1) {
            $extension =  $request->auth->extension;
        }

        //echo $extension;die;

        /*close new code implement*/

        $asterisk = $this->getAsterisk($request->auth->asterisk_server_id, $extension, $request->auth->parent_id);
        if ($request->logout_all == 1) {
            User::where('id', $request->auth->id)->update(['webphone' => false]);
            Cache::put("user.webphone.{$request->auth->id}.{$request->auth->parent_id}", 0);
        }
        return $asterisk->asteriskLogout($request->auth->parent_id, $extension);
    }

    public function listenCall($request)
    {

        try {
            $extension_auth = $request->auth->extension;
            $extension = $request->extension;

            if ($extension == $extension_auth) {
            } else {
                $intWebPhoneSetting = DialerController::getWebPhonestatus($request->auth->id, $request->auth->parent_id);
                if ($intWebPhoneSetting == 1) {
                    $extension = $request->extension;
                } else {
                    return array(
                        'success' => false,
                        'message' => 'Please enable the webphone first'
                    );
                }
            }

            $lineDetailArray = LineDetail::on('mysql_' . $request->auth->parent_id)->findOrFail($request->listen_id)->toArray();
            $asterisk = $this->getAsterisk($request->auth->asterisk_server_id, $extension, $request->auth->parent_id);
            $response = $asterisk->listenCall($lineDetailArray, $extension);
            if ($response == true) {
                return array(
                    'success' => true,
                    'message' => 'Listen activity started'
                );
            } else {
                return array(
                    'success' => false,
                    'message' => 'Unable to listen activity'
                );
            }
        } catch (\Throwable $e) {
            Log::error("Dialer.hangUp", [
                "message" => $e->getMessage(),
                "code" => $e->getCode(),
                "file" => $e->getFile(),
                "line" => $e->getLine()
            ]);
            return array(
                'success' => false,
                'message' => 'Unable to hang up the phone. ' . $e->getMessage(),
                'data' => []
            );
        }
    }

    public function bargeCall($request)
    {

        try {
            $extension_auth = $request->auth->extension;
            $extension = $request->extension;

            if ($extension == $extension_auth) {
            } else {
                $intWebPhoneSetting = DialerController::getWebPhonestatus($request->auth->id, $request->auth->parent_id);
                if ($intWebPhoneSetting == 1) {
                    $extension = $request->extension;
                } else {
                    return array(
                        'success' => false,
                        'message' => 'Please enable the webphone first'
                    );
                }
            }

            $lineDetailArray = LineDetail::on('mysql_' . $request->auth->parent_id)->findOrFail($request->listen_id)->toArray();
            $asterisk = $this->getAsterisk($request->auth->asterisk_server_id, $extension, $request->auth->parent_id);
            $response = $asterisk->bargeCall($lineDetailArray, $extension);
            if ($response == true) {
                return array(
                    'success' => true,
                    'message' => 'Barge Call activity started'
                );
            } else {
                return array(
                    'success' => false,
                    'message' => 'Unable to Barge Call activity'
                );
            }
        } catch (\Throwable $e) {
            Log::error("Dialer.hangUp", [
                "message" => $e->getMessage(),
                "code" => $e->getCode(),
                "file" => $e->getFile(),
                "line" => $e->getLine()
            ]);
            return array(
                'success' => false,
                'message' => 'Unable to bargeCall the phone. ' . $e->getMessage(),
                'data' => []
            );
        }
    }

    public function addNewLeadPd($request)
    {
        try {

            $lineDetailObj              = ListHeader::on('mysql_' . $request->auth->parent_id)->where(['list_id' => $request->list_id], ['is_dialing' => 1])->firstOrFail();
            $lineDetailArray            = $lineDetailObj->toArray();
            $is_dial_column             = $lineDetailArray['column_name'];

            $lineDetailAlterObj         = ListHeader::on('mysql_' . $request->auth->parent_id)->where(['list_id' => $request->list_id], ['alternate_phone' => 1])->firstOrFail();
            $lineDetailAlterArray       = $lineDetailAlterObj->toArray();
            $alternate_phone_column     = $lineDetailAlterArray['column_name'];

            $tasks = ListData::on('mysql_' . $request->auth->parent_id)->find($request->lead_id);
            $newTask = $tasks->replicate();
            $newTask->save();
            $newId = $newTask->id;
            ListData::on('mysql_' . $request->auth->parent_id)->where('id', $newId)->update([$is_dial_column => $request->nxt_call], ['alternate_phone' => 0], [$alternate_phone_column => '']);

            if ($newId) {
                return array(
                    'success' => true,
                    'message' => 'Created new lead'
                );
            } else {
                return array(
                    'success' => false,
                    'message' => 'Unable to create new lead'
                );
            }
        } catch (\Throwable $e) {
            Log::error("Dialer.addNewLeadPd", [
                "message" => $e->getMessage(),
                "code" => $e->getCode(),
                "file" => $e->getFile(),
                "line" => $e->getLine()
            ]);
            return array(
                'success' => false,
                'message' => 'Unable to create new lead. ' . $e->getMessage(),
                'data' => []
            );
        }
    }


    public function getHopperModeInCampaign(int $campaignId, int $parentId)
    {
        $hopperMode = DB::connection('mysql_' . $parentId)->selectOne(
            "SELECT * FROM campaign WHERE id = :id",
            array('id' => $campaignId)
        );
        return $hopperModeType = (array)$hopperMode;
    }

    public function deleteExt($request)
    {
        Log::info('reached', $request->all());
        try {
            $extension = $request->input('extension');
            $dataUser = User::where('extension', $extension)->orWhere('alt_extension', $extension)->get()->first();

            if (!empty($dataUser)) {
                $server = AsteriskServer::find($dataUser->asterisk_server_id);
                $asteriskServerId = $server->id;
                $parentId = $dataUser->parent_id;
                $alt_extension = $dataUser->alt_extension;
            } else {
                throw new RenderableException('Unauthorised Extension Found', [401], 401);
            }

            //  return $alt_extension;

            $asterisk = $this->getAsterisk($asteriskServerId, $extension, $parentId);
            $response = $asterisk->confbridge($alt_extension, $extension);
            if ($response == true) {
                $data['extension'] = $extension;
                $sql = "DELETE FROM extension_live where extension=:extension";
                $record =  DB::connection('mysql_' . $request->auth->parent_id)->select($sql, $data);
                Log::info('reached dlete ext', ['sql' => $sql]);
                $data = (array)$record;
                //return 1;
                if (empty($data)) {
                    return array(
                        'success' => 'true',
                        'message' => 'live extension deleted.',
                        'response' => $response
                    );
                }

                return array(
                    'success' => 'false',
                    'message' => 'live extension not deleted.'
                );
            } else {
                return array(
                    'success' => 'Live Extension Not Found',
                    'data' => $response
                );
            }
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }


    public function directCallTransfer(int $campaignId, int $parentId)
    {
        return array(
            'success' => true,
            'message' => 'Direct Call Transfer run successfully'
        );
    }


    public function warmCallTransferOLD($request, int $parentId)
    {

        $callTransferData = array('lead_id' => $request->lead_id, 'forward_extension' => $request->forward_extension, 'user_extension' => $request->auth->alt_extension, 'number' => $request->customer_phone_number, 'campaign_id' => $request->campaign_id, 'parent_id' => $request->auth->parent_id);



        try {
            $asterisk = $this->getAsterisk($request->auth->asterisk_server_id, $request->auth->alt_extension, $request->auth->parent_id);

            $response = $asterisk->getWarmCallTransfer($callTransferData);
            // $response = $asterisk->hangUp();
            if ($response == true) {
                return array(
                    'success' => true,
                    'message' => 'Warm Call Transfer on Extension is successful'
                );
            } else {
                return array(
                    'success' => false,
                    'message' => 'Unable to Run Warm Call Transfer'
                );
            }
        } catch (\Throwable $e) {
            Log::error("Dialer.hangUp", [
                "message" => $e->getMessage(),
                "code" => $e->getCode(),
                "file" => $e->getFile(),
                "line" => $e->getLine()
            ]);
            return array(
                'success' => false,
                'message' => 'Unable to Run Warm Call Transfer. ' . $e->getMessage(),
                'data' => []
            );
        }
        /*return array(
                    'success' => true,
                    'message' => 'Wall Call Transfer run successfully'
                );*/
    }

    public function warmCallTransfer($request, int $parentId)
    {
        $dataUser = DB::selectOne("SELECT * FROM users WHERE (extension = :extension OR alt_extension = :alt_extension OR app_extension = :app_extension)", ['extension' => $request->forward_extension, 'alt_extension' => $request->forward_extension, 'app_extension' => $request->forward_extension]);

        $dialer_mode = $request->has('dialer_mode')
            ? intval($request->input('dialer_mode'))
            : $dataUser->dialer_mode;

        if ($dialer_mode == 3) {
            $forward_extension = $dataUser->app_extension;
        } else
            if ($dialer_mode == 2) {
            $forward_extension = $dataUser->alt_extension;
        } else
            if ($dialer_mode == 1) {
            $forward_extension =  $dataUser->extension;
        }


        $callTransferData = array('domain' => $request->domain, 'lead_id' => $request->lead_id, 'ring_group' => $request->ring_group, 'forward_extension' => $forward_extension, 'user_extension' => $request->auth->alt_extension, 'number' => $request->customer_phone_number, 'parent_id' => $request->auth->parent_id, $request);

        //echo "<pre>";print_r($callTransferData);die;




        try {
            $asterisk = $this->getAsterisk($request->auth->asterisk_server_id, $request->auth->alt_extension, $request->auth->parent_id);

            if ($request->warm_call_transfer_type == 'extension') {
                $response = $asterisk->getWarmCallTransfer($callTransferData, $request);
                if ($response == true) {
                    return array(
                        'success' => true,
                        'message' => 'Warm Call Transfer C2C initiating on Extension is successful'
                    );
                } else {
                    return array(
                        'success' => false,
                        'message' => 'Unable to Run Warm Call Transfer C2C'
                    );
                }
            } else
            if ($request->warm_call_transfer_type == 'ring_group') {
                $response = $asterisk->getWarmCallTransferRingGroup($callTransferData, $request);
                if ($response == true) {
                    return array(
                        'success' => true,
                        'message' => 'Warm Call Transfer C2C initiating on Ring group is successful'
                    );
                } else {
                    return array(
                        'success' => false,
                        'message' => 'Unable to Run Warm Call Transfer C2C'
                    );
                }
            } else
            if ($request->warm_call_transfer_type == 'did') {
                $response = $asterisk->warmCallTransferDid($callTransferData, $request);
                if ($response == true) {
                    return array(
                        'success' => true,
                        'message' => 'Warm Call Transfer C2C initiating on Phone number is successful'
                    );
                } else {
                    return array(
                        'success' => false,
                        'message' => 'Unable to Run Warm Call Transfer C2C'
                    );
                }
            }
        } catch (\Throwable $e) {
            Log::error("Dialer.hangUp", [
                "message" => $e->getMessage(),
                "code" => $e->getCode(),
                "file" => $e->getFile(),
                "line" => $e->getLine()
            ]);
            return array(
                'success' => false,
                'message' => 'Unable to Run Warm Call Transfer C2C. ' . $e->getMessage(),
                'data' => []
            );
        }
        /*return array(
                    'success' => true,
                    'message' => 'Wall Call Transfer run successfully'
                );*/
    }


    function channelRedirect($request, $channel, $forward_extension)
    {
        try {
            $asterisk = $this->getAsterisk($request->auth->asterisk_server_id, $request->auth->alt_extension, $request->auth->parent_id);

            $response = $asterisk->channelRedirectToAgentB($request, $channel, $forward_extension);


            if ($response['status'] == true) {
                return array(
                    'success' => true,
                    'message' => 'Redirect channel of A to Conference of B'
                );
            } else {
                return array(
                    'success' => false,
                    'message' => 'Unable to Redirect channel of A to Conference of B'
                );
            }
        } catch (Exception $ex) {
            throw new RenderableException('Redirect channel of A to Conference of B Failed', [401], 401);
        }
    }



    public function mergeCallWithTransfer($request, $channel)
    {
        try {
            $asterisk = $this->getAsterisk($request->auth->asterisk_server_id, $request->auth->alt_extension, $request->auth->parent_id);

            $response = $asterisk->channelMergeWithNumber($request, $request->forward_extension, $channel);

            if ($response['status'] == true) {
                return array(
                    'success' => true,
                    'message' => 'Call Merge with Customer Phone Number'
                );
            } else {
                return array(
                    'success' => false,
                    'message' => 'Unable to Call Merge with Customer Phone Number'
                );
            }
        } catch (Exception $ex) {
            throw new RenderableException('Redirect channel of A to Conference of B Failed', [401], 401);
        }
    }



    public function leaveConferenceTransfer($request, $channel)
    {
        try {
            $asterisk = $this->getAsterisk($request->auth->asterisk_server_id, $request->auth->alt_extension, $request->auth->parent_id);

            $response = $asterisk->leaveConferenceTransfer($request, $request->auth->alt_extension, $channel);

            if ($response['status'] == true) {
                return array(
                    'success' => true,
                    'message' => 'Conference Leave Successfully'
                );
            } else {
                return array(
                    'success' => false,
                    'message' => 'Unable to leave Conference '
                );
            }
        } catch (Exception $ex) {
            throw new RenderableException('Unable to leave Conference', [401], 401);
        }
    }



    //call trnasfer to did

    public function warmCallTransferDid($request, int $parentId)
    {



        $callTransferData = array('domain' => $request->domain, 'lead_id' => $request->lead_id, 'did_number' => $request->did_number, 'user_extension' => $request->auth->alt_extension, 'number' => $request->customer_phone_number, 'parent_id' => $request->auth->parent_id, $request);

        /* return array(
                        'success' => true,
                        'message' => 'Warm Call Transfer C2C initiating on Phone number is successful',
                        'data' => $callTransferData
                    );
*/



        try {
            $asterisk = $this->getAsterisk($request->auth->asterisk_server_id, $request->auth->alt_extension, $request->auth->parent_id);




            if ($request->warm_call_transfer_type == 'did') {
                $response = $asterisk->warmCallTransferDid($callTransferData, $request);
                if ($response == true) {
                    return array(
                        'success' => true,
                        'message' => 'Warm Call Transfer C2C initiating on Phone number is successful'
                    );
                } else {
                    return array(
                        'success' => false,
                        'message' => 'Unable to Run Warm Call Transfer C2C'
                    );
                }
            }
        } catch (\Throwable $e) {
            Log::error("Dialer.hangUp", [
                "message" => $e->getMessage(),
                "code" => $e->getCode(),
                "file" => $e->getFile(),
                "line" => $e->getLine()
            ]);
            return array(
                'success' => false,
                'message' => 'Unable to Run Warm Call Transfer C2C. ' . $e->getMessage(),
                'data' => []
            );
        }
        /*return array(
                    'success' => true,
                    'message' => 'Wall Call Transfer run successfully'
                );*/
    }
}
