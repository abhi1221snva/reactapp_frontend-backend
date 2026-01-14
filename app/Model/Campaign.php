<?php

namespace App\Model;

use App\Jobs\RecycleDeletedNotificationJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use App\Model\Client\CampaignList;
use Illuminate\Database\QueryException;

class Campaign extends Model
{

    

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];
    protected $table = 'campaign';
    public $timestamps = false;


    public static function allowedCampaigns(int $clientId, int $level, array $groups = [])
    {
        if (empty($groups)) $groups = [0];
        $sql = "SELECT * FROM campaign WHERE is_deleted=0";
        //$sql = "SELECT * FROM campaign WHERE status=1";

        if ($level < 7) {
            $sql .= " AND group_id IN (" . implode(",", $groups) . ")";
        }
        $records = DB::connection("mysql_$clientId")->select($sql);
        $data = [];
        foreach ($records as $record) {
            $data[$record->id] = $record;
        }
        return $data;
    }



public function campaignDetaillatest($request)
{
    try {
        $campaigns = self::allowedCampaigns(
            $request->auth->parent_id,
            $request->auth->level,
            $request->auth->groups
        );

        // ✅ Search by title (case-insensitive)
        if ($request->has('title') && !empty($request->input('title'))) {
            $searchTitle = strtolower($request->input('title'));
            $campaigns = array_filter($campaigns, function ($c) use ($searchTitle) {
                return strpos(strtolower($c->title), $searchTitle) !== false;
            });
        }

        // ✅ Reindex and sort by latest first (DESC) BEFORE pagination
        $campaigns = array_values($campaigns); // ensure 0..n keys
        usort($campaigns, function ($a, $b) {
            return ($b->id ?? 0) <=> ($a->id ?? 0);
        });

        // ✅ Pagination (slice AFTER sorting)
        $totalRows = count($campaigns);
        if ($request->has(['start', 'limit'])) {
            $start = (int) $request->input('start');
            $limit = (int) $request->input('limit');
            $campaigns = array_slice($campaigns, $start, $limit); // don't preserve keys
        }

        $data_count = [];

        foreach ($campaigns as $campaign) {
            $data1 = ['campaign_id' => $campaign->id];

            // 1) lead_report count
            $sql_count_lead_report = "SELECT count(1) as rowCountLeadReport
                                      FROM lead_report
                                      WHERE campaign_id = :campaign_id";
            $record_count_lead = DB::connection('mysql_' . $request->auth->parent_id)
                ->selectOne($sql_count_lead_report, $data1);
            $campaign->rowLeadReport = $record_count_lead->rowCountLeadReport ?? 0;

            // 2) campaign_list / hubspot_campaign_list
            $tableList = $campaign->crm_title_url == 'hubspot'
                ? 'hubspot_campaign_list'
                : 'campaign_list';

            $sql = "SELECT * FROM {$tableList}
                    WHERE campaign_id = :campaign_id AND status=1 AND is_deleted=0";

            $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql, $data1);
            $list = $record; // no cast needed
            $campaign->rowList = count($list);

            $id_list = array_map(fn($x) => $x->list_id, $list);
            $list_ids = $id_list ? "'" . implode("','", $id_list) . "'" : "''";

            // 3) list_data / hubspot_lists
            if ($campaign->crm_title_url == 'hubspot') {
                $sql_count_list = "SELECT sum(size) as rowCountList
                                   FROM hubspot_lists
                                   WHERE list_id IN ({$list_ids})";
            } else {
                $sql_count_list = "SELECT count(1) as rowCountList
                                   FROM list_data
                                   WHERE list_id IN ({$list_ids})";
            }

            $record_count_list = DB::connection('mysql_' . $request->auth->parent_id)->select($sql_count_list);
            $campaign->rowListData = $record_count_list[0]->rowCountList ?? 0;

            // 4) lead_temp count
            $sql_lead_temp = "SELECT count(1) as rowLeadTemp
                              FROM lead_temp
                              WHERE campaign_id = :campaign_id";
            $record_rowLeadTemp = DB::connection('mysql_' . $request->auth->parent_id)
                ->selectOne($sql_lead_temp, $data1);
            $campaign->rowLeadTemp = $record_rowLeadTemp->rowLeadTemp ?? 0;

            $data_count[] = (array) $campaign;
        }

        // ❌ Do NOT sort or reverse here; it's already sorted before pagination.

        return [
            'success' => 'true',
            'message' => 'Campaign detail.',
            'total_rows' => $totalRows,
            'data' => $data_count,
        ];
    } catch (\Throwable $e) {
        Log::error("Campaign.campaignDetail", [
            "message" => $e->getMessage(),
            "file" => $e->getFile(),
            "line" => $e->getLine()
        ]);

        return [
            'success' => 'false',
            'message' => 'Server error.',
            'data' => []
        ];
    }
}
   public function campaignDetail($request)
{
    try {
        $userTimezone = $request->auth->timezone ?? 'Asia/Kolkata';

        $campaigns = self::allowedCampaigns($request->auth->parent_id, $request->auth->level, $request->auth->groups);
        if ($request->has('title') && !empty($request->input('title'))) {
            $searchTitle = strtolower($request->input('title'));
            $campaigns = array_filter($campaigns, function ($c) use ($searchTitle) {
                return strpos(strtolower($c->title), $searchTitle) !== false;
            });
        }
        // Pagination
        if ($request->has(['start', 'limit'])) {
            $start = (int)$request->input('start');
            $limit = (int)$request->input('limit');
            $totalRows = count($campaigns); // total before slicing
            $campaigns = array_slice($campaigns, $start, $limit, true);
        } else {
            $totalRows = count($campaigns);
        }

        $data_count = [];

        foreach ($campaigns as $key => $id) {

            $data1['campaign_id'] = $id->id;

            // 1. lead_report count
            $sql_count_lead_report = "SELECT count(1) as rowCountLearReport FROM lead_report WHERE campaign_id = :campaign_id ";
            $record_count_lead = DB::connection('mysql_' . $request->auth->parent_id)->selectOne($sql_count_lead_report, $data1);
            $id->dialed_leads  = $record_count_lead->rowCountLearReport;

            $searchStr = [];
            if ($data1['campaign_id'] && is_numeric($data1['campaign_id'])) {
                $searchStr[] = 'campaign_id = :campaign_id';
            }

            // 2. campaign_list or hubspot_campaign_list
            if ($id->crm_title_url == 'hubspot') {
                $sql = "SELECT * FROM hubspot_campaign_list WHERE " . implode(" AND ", $searchStr) . " AND is_deleted=0 AND status=1";
            } else {
                $sql = "SELECT * FROM campaign_list WHERE " . implode(" AND ", $searchStr) . " AND is_deleted=0 AND status=1";
            }

            $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql, $data1);
            $list = (array) $record;
            $count = count($list);
            $id_list = [];

            foreach ($list as $listItem) {
                $id_list[] = $listItem->list_id;
            }

            $list_ids_str = implode(',', $id_list);

            $id->lists_associated = $count;

            // 3. rowListData: use lead_count from list table if exists, else fallback to existing logic
            if (!empty($id_list)) {
                // Sum lead_count from list table
                $sql_lead_count = "SELECT SUM(lead_count) as totalLeadCount FROM list WHERE id IN (" . $list_ids_str . ")";
                $lead_count_record = DB::connection('mysql_' . $request->auth->parent_id)->selectOne($sql_lead_count);

                if (!empty($lead_count_record) && $lead_count_record->totalLeadCount > 0) {
                    $id->total_leads = $lead_count_record->totalLeadCount;
                } else {
                    // fallback to existing logic
                    if ($id->crm_title_url == 'hubspot') {
                        $sql_count_list = "SELECT sum(size) as rowCountList FROM hubspot_lists WHERE list_id IN ('" . implode("','", $id_list) . "')";
                    } else {
                        $sql_count_list = "SELECT count(1) as rowCountList FROM list_data WHERE list_id IN ('" . implode("','", $id_list) . "')";
                    }

                    $record_count_list = DB::connection('mysql_' . $request->auth->parent_id)->select($sql_count_list);
                    $id->total_leads  = $record_count_list[0]->rowCountList ?? 0;
                }
            } else {
                $id->total_leads  = 0; // no lists
            }

            // 4. lead_temp count
            $sql_lead_temp = "SELECT count(1) as rowLeadTemp FROM lead_temp WHERE campaign_id = :campaign_id ";
            $record_rowLeadTemp = DB::connection('mysql_' . $request->auth->parent_id)->selectOne($sql_lead_temp, $data1);
            $id->hopper_count  = $record_rowLeadTemp->rowLeadTemp ?? 0;
            // ✅ Convert timestamps to user timezone (for response only)
            if (!empty($id->created_at)) {
                $id->created_at = convertToUserTimezone($id->created_at, $userTimezone);
            }

            if (!empty($id->updated)) {
                $id->updated = convertToUserTimezone($id->updated, $userTimezone);
            }

            $data_count[] = (array) $id;
            $data_count = array_reverse($data_count);
        }

        return [
            'success' => 'true',
            'message' => 'Campaign detail.',
            'total_rows' => $totalRows,
            'data' => $data_count,
        ];

    } catch (\Throwable $e) {
        Log::error("Campaign.campaignDetail", [
            "message" => $e->getMessage(),
            "file" => $e->getFile(),
            "line" => $e->getLine()
        ]);

        return [
            'success' => 'false',
            'message' => 'Server error.',
            'data' => []
        ];
    }
}

    /*
     * Update Campaign details
     * @param object $request
     * @return array
     */
public function updateCampaign($request)
{
    try {
        if (!$request->has('campaign_id') || !is_numeric($request->input('campaign_id'))) {
            return [
                'success' => false,
                'message' => 'Campaign does not exist.',
                'status'  => 422
            ];
        }

        $validate = $this->validateCampaign($request);
        $updateString = $validate['string'];
        $data = $validate['data'];

        if (empty($updateString) || empty($data)) {
            return [
                'success' => false,
                'message' => 'Invalid campaign data.',
                'status'  => 422
            ];
        }

        $cmpId = $request->input('campaign_id');
        $date_time = date('Y-m-d H:i:s');
        $data['id'] = $cmpId;

        DB::connection('mysql_' . $request->auth->parent_id)
            ->update(
                "UPDATE {$this->table} 
                 SET updated = '{$date_time}', " . implode(', ', $updateString) . " 
                 WHERE id = :id",
                $data
            );

        // 🔴 DELETE OLD DISPOSITIONS
        DB::connection('mysql_' . $request->auth->parent_id)
            ->delete("DELETE FROM campaign_disposition WHERE campaign_id = ?", [$cmpId]);

        $disposition_id = $request->input('disposition_id');

        // 🔴 DUPLICATE CHECK
        if (is_array($disposition_id) && count($disposition_id) !== count(array_unique($disposition_id))) {
            return [
                'success' => false,
                'message' => 'Duplicate disposition values are not allowed.',
                'status'  => 422
            ];
        }

        if (is_array($disposition_id)) {
            foreach ($disposition_id as $value) {
                DB::connection('mysql_' . $request->auth->parent_id)->insert(
                    "INSERT INTO campaign_disposition 
                     (campaign_id, disposition_id, updated_at, is_deleted)
                     VALUES (?, ?, ?, ?)",
                    [$cmpId, $value, $date_time, 0]
                );
            }
        }

        return [
            'success' => true,
            'message' => 'Campaign updated successfully.',
            'status'  => 200
        ];

    } catch (\Illuminate\Database\QueryException $e) {

        if ($e->getCode() == 23000) {
            return [
                'success' => false,
                'message' => 'Duplicate disposition selected.',
                'status'  => 422
            ];
        }

        return [
            'success' => false,
            'message' => $e->getMessage(),
            'status'  => 500
        ];
    }
}

    public function updateCampaignold($request)
    {
        try {
            if ($request->has('campaign_id') && is_numeric($request->input('campaign_id'))) {
                $validate = $this->validateCampaign($request);
                $updateString = $validate['string'];
                $data = $validate['data'];
                $save = TRUE;
                if (!empty($updateString) && !empty($data)) {
                    $cmpId = $request->input('campaign_id');
                    $date_time = date('Y-m-d h:i:s');
                    $data['id'] = $request->input('campaign_id');
                    $query = "UPDATE " . $this->table . " set updated= '{$date_time}' , " . implode(" , ", $updateString) . " WHERE id = :id";
                    $save &= DB::connection('mysql_' . $request->auth->parent_id)->update($query, $data);

                    $queryDel = "DELETE FROM campaign_disposition WHERE campaign_id= " . $cmpId;
                    $save &= DB::connection('mysql_' . $request->auth->parent_id)->update($queryDel);
                    $disposition_id = $request->input('disposition_id');
                    if (is_array($disposition_id) && count($disposition_id) !== count(array_unique($disposition_id))) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Duplicate disposition values are not allowed.',
                            'status'  => 422
                        ]);
                    }
                    if (count($disposition_id) > 0) {
                        foreach ($disposition_id as $key => $value) {
                            $insert = "INSERT INTO campaign_disposition SET campaign_id= :campaign_id , disposition_id= :disposition_id , updated_at= :updated_at , is_deleted= :is_deleted ";
                            $dataInsert = array('campaign_id' => $cmpId, 'disposition_id' => $value, 'updated_at' => date('Y-m-d h:i:s'), 'is_deleted' => 0);
                            $save &= DB::connection('mysql_' . $request->auth->parent_id)->update($insert, $dataInsert);
                        }
                    }
                    if ($save == 1) {
                        return array(
                            'success' => 'true',
                            'message' => 'Campaign updated successfully.'
                        );
                    } else {
                        return array(
                            'success' => 'false',
                            'message' => 'Campaign updated successfully.'
                        );
                    }
                }
            }
            return array(
                'success' => 'false',
                'message' => 'Campaign doesn\'t exist.'
            );
        } catch (Exception $e) {
            if ($e->getCode() == 23000) {
        return response()->json([
            'success' => false,
            'message' => 'Duplicate disposition selected. Same disposition cannot be assigned multiple times.',
            'status'  => 422
        ]);
    }

    return response()->json([
        'success' => false,
        'message' => $e->getMessage(),
        'status'  => 422
    ]);
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
              return response()->json([
        'success' => false,
        'message' => $e->getMessage(),
        'status'  => 500
    ]);
            Log::log($e->getMessage());
        }
    }

    /*
     * Add group details
     * @param object $request
     * @return array
     */

    public function copyApiByNewCampaign($request, $campaignId)
    {
        //  $api_id = $request->api_id;
        if ($request->has('api_id') && is_numeric($request->input('api_id'))) {
            $api_id = $request->api_id;
        } else {
            $api_id = 1; // Default value if 'api' is not in the request or invalid
        }

        $sql = "SELECT * FROM api  WHERE id = :id";
        $record =  DB::connection('mysql_' . $request->auth->parent_id)->selectOne($sql, array('id' => $api_id));
        $data = (array)$record;
        $dataBase = 'mysql_' . $request->auth->parent_id;
        $recordData = array(
            'title'     => $data['title'],
            'url'       => $data['url'],
            'campaign_id' => $campaignId,
            'method'    => $data['method'],
            'is_deleted' => $data['is_deleted']
        );

        $insert_id =  DB::connection('mysql_' . $request->auth->parent_id)->table('api')->insertGetId($recordData);
        $save_data = true;
        $disposition = "SELECT * FROM api_disposition where api_id= :api_id ";
        $recordDisposition =  DB::connection('mysql_' . $request->auth->parent_id)->select($disposition, array('api_id' => $api_id));
        $dataDisposition = (array)$recordDisposition;
        if (count($dataDisposition) > 0) {
            foreach ($recordDisposition as $key => $val) {
                $h_list['disposition_id']   = $val->disposition_id;
                $h_list['api_id']           = $insert_id;
                $h_list['is_deleted']       = $val->is_deleted;
                $disposition_list[]         = $h_list;
            }
            $save_data &= DB::connection($dataBase)->table('api_disposition')->insert($disposition_list);
        } else {
            $save_data = false;
        }

        $apiParameter = "SELECT * FROM api_parameter where api_id= :api_id ";
        $recordApiParameter =  DB::connection('mysql_' . $request->auth->parent_id)->select($apiParameter, array('api_id' => $api_id));
        $dataApiParameter = (array)$recordApiParameter;
        if (count($dataApiParameter) > 0) {
            foreach ($dataApiParameter as $key1 => $val1) {
                $ap_list['api_id']      = $insert_id;
                $ap_list['type']        = $val1->type;
                $ap_list['parameter']   = $val1->parameter;
                $ap_list['value']       = $val1->value;
                $ap_list['is_deleted']  = $val1->is_deleted;
                $parameter_list[]       = $ap_list;
            }
            $save_data &= DB::connection($dataBase)->table('api_parameter')->insert($parameter_list);
        } else {
            $save_data = false;
        }

        if ($save_data) {
            return array(
                'success' => 'true',
                'message' => 'New API added successfully.',
                'list_id' => $insert_id,
            );
        } else {
            return array(
                'success' => 'false',
                'message' => 'Api not added. Unable to add data in API table'
            );
        }
    }

    public function copyApiByNewCampaign_old_code($request, $campaignId)
    {
        $api_id = $request->api_id;

        $sql = "SELECT * FROM api  WHERE id = :id";
        $record =  DB::connection('mysql_' . $request->auth->parent_id)->selectOne($sql, array('id' => $api_id));
        $data = (array)$record;
        $dataBase = 'mysql_' . $request->auth->parent_id;
        $recordData = array(
            'title'     => $data['title'],
            'url'       => $data['url'],
            'campaign_id' => $campaignId,
            'method'    => $data['method'],
            'is_deleted' => $data['is_deleted']
        );

        $insert_id =  DB::connection('mysql_' . $request->auth->parent_id)->table('api')->insertGetId($recordData);
        $save_data = true;
        $disposition = "SELECT * FROM api_disposition where api_id= :api_id ";
        $recordDisposition =  DB::connection('mysql_' . $request->auth->parent_id)->select($disposition, array('api_id' => $api_id));
        $dataDisposition = (array)$recordDisposition;
        if (count($dataDisposition) > 0) {
            foreach ($recordDisposition as $key => $val) {
                $h_list['disposition_id']   = $val->disposition_id;
                $h_list['api_id']           = $insert_id;
                $h_list['is_deleted']       = $val->is_deleted;
                $disposition_list[]         = $h_list;
            }
            $save_data &= DB::connection($dataBase)->table('api_disposition')->insert($disposition_list);
        } else {
            $save_data = false;
        }

        $apiParameter = "SELECT * FROM api_parameter where api_id= :api_id ";
        $recordApiParameter =  DB::connection('mysql_' . $request->auth->parent_id)->select($apiParameter, array('api_id' => $api_id));
        $dataApiParameter = (array)$recordApiParameter;
        if (count($dataApiParameter) > 0) {
            foreach ($dataApiParameter as $key1 => $val1) {
                $ap_list['api_id']      = $insert_id;
                $ap_list['type']        = $val1->type;
                $ap_list['parameter']   = $val1->parameter;
                $ap_list['value']       = $val1->value;
                $ap_list['is_deleted']  = $val1->is_deleted;
                $parameter_list[]       = $ap_list;
            }
            $save_data &= DB::connection($dataBase)->table('api_parameter')->insert($parameter_list);
        } else {
            $save_data = false;
        }

        if ($save_data) {
            return array(
                'success' => 'true',
                'message' => 'New API added successfully.',
                'list_id' => $insert_id,
            );
        } else {
            return array(
                'success' => 'false',
                'message' => 'Api not added. Unable to add data in API table'
            );
        }
    }

   public function addCampaign($request)
    {
        try {
if (!$request->has('voicedrop_option_user_id') || empty($request->voicedrop_option_user_id)) {
    $request->merge(['voicedrop_option_user_id' => 0]);
}
if (!$request->has('no_agent_dropdown_action') || empty($request->no_agent_dropdown_action)) {
    $request->merge(['no_agent_dropdown_action' => 0]);
}

            if (!$request->has('api_id') || empty($request->api_id)) {
            $request->merge(['api_id' => 1]);
}
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

                    // add for new api

                    $this->copyApiByNewCampaign($request, $campaignId);

                    return array(
                        'success' => 'true',
                        'message' => 'Campaign added successfully.',
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


    /*
     * Validate Campaign
     *
     */

    protected function validateCampaign($request)
    {
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
        // if ($request->has('group_id') && is_numeric($request->input('group_id'))) {
        //     array_push($string, 'group_id = :group_id');
        //     $data['group_id'] = $request->input('group_id');
        // }
        // group_id is NOT required when dial_mode = outbound_ai
if ($request->input('dial_mode') === 'super_power_dial') {

    // use provided group_id (assuming validation already handled elsewhere)
    $string[] = 'group_id = :group_id';
    $data['group_id'] = (int) $request->input('group_id');

} else {

    // NOT super_power_dial → force group_id = 0
    $string[] = 'group_id = :group_id';
    $data['group_id'] = 0;
}


        if ($request->has('max_lead_temp') && is_numeric($request->input('max_lead_temp')) && $request->input('max_lead_temp') < 1000) {
            array_push($string, 'max_lead_temp = :max_lead_temp');
            $data['max_lead_temp'] = $request->input('max_lead_temp');
        }
        if ($request->has('min_lead_temp') && !empty($request->input('min_lead_temp')) && $request->input('max_lead_temp') < 500) {
            array_push($string, 'min_lead_temp = :min_lead_temp');
            $data['min_lead_temp'] = $request->input('min_lead_temp');
        }
        // if ($request->has('api') && is_numeric($request->input('api'))) {
        //     array_push($string, 'api = :api');
        //     $data['api'] = $request->input('api');
        // }
        if ($request->has('api') && is_numeric($request->input('api'))) {
            $data['api'] = $request->input('api');
        } else {
            $data['api'] = 1; // Default value if 'api' is not in the request or invalid
        }

        array_push($string, 'api = :api');
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

        // if ($request->has('amd')) {
        //     array_push($string, 'amd = :amd');
        //     $data['amd'] = $request->input('amd');
        // }
       if ($request->has('amd')) {
    array_push($string, 'amd = :amd');
    $data['amd'] = (string) $request->input('amd') === '1' ? '1' : '0';
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

        if ($request->has('call_transfer')) {
            array_push($string, 'call_transfer = :call_transfer');
            $data['call_transfer'] = $request->input('call_transfer');
        }
        if ($request->has('call_metric')) {
            array_push($string, 'call_metric = :call_metric');
            $data['call_metric'] = $request->input('call_metric');
        }

        if ($request->has('call_schedule_id')) {
            array_push($string, 'call_schedule_id = :call_schedule_id');
            $data['call_schedule_id'] = $request->input('call_schedule_id');
        }
        return array('string' => $string, 'data' => $data);
    }

    /*
     * Fetch campaign for agent
     * @param object $request
     * @return array
     */

    public function getAgentCampaign($request)
    {
        try {
            if (!empty($request->input('id')) && $request->auth->role == '2') {
                $db = $request->auth->parent_id;
                $extension = $request->auth->extension;
                $extensionGroup = DB::connection('mysql_' . $db)->select(
                    "SELECT * FROM extension_group_map WHERE extension = :extension AND is_deleted = :is_deleted",
                    array('extension' => $extension, 'is_deleted' => 0)
                );
                if (!empty($extensionGroup)) {
                    $inStr = array();
                    $data['is_deleted'] = 0;
                    $count = 1;
                    foreach ($extensionGroup as $item => $value) {
                        array_push($inStr, ":group_" . $count);
                        $data["group_" . $count] = $value->group_id;
                        $count++;
                    }
                    $campaign = DB::connection('mysql_' . $db)->select(
                        "SELECT * FROM campaign WHERE group_id in (" . implode(' , ', $inStr) . ") AND is_deleted = :is_deleted",
                        $data
                    );
                    if (!empty($campaign)) {
                        return array(
                            'success' => 'true',
                            'message' => 'List of campaign for extension.',
                            'data' => $campaign
                        );
                    } else {
                        return array(
                            'success' => 'true',
                            'message' => 'Extension is not belong to any campaign.',
                            'data' => array()
                        );
                    }
                } else {
                    return array(
                        'success' => 'false',
                        'message' => 'Extension not belong to any group'
                    );
                }
            } else {
                return array(
                    'success' => 'false',
                    'message' => 'User does not have agent permission'
                );
            }
        } catch (Exception $e) {
            echo $e->getMessage();
        } catch (InvalidArgumentException $e) {
            echo $e->getMessage();
        }
    }

    function getCampaignCount($request)
    {
        try {
            $data['is_deleted'] = 0;
            $sql = "SELECT count(1) as rowCount FROM " . $this->table . " WHERE is_deleted = :is_deleted ";
            $record = DB::connection('mysql_' . $request->auth->parent_id)->selectOne($sql, $data);
            $data = (array) $record;
            return array(
                'success' => 'true',
                'message' => 'Extension is not belong to any campaign.',
                'data' => $data['rowCount']
            );
        } catch (Exception $e) {
            echo $e->getMessage();
        } catch (InvalidArgumentException $e) {
            echo $e->getMessage();
        }
    }

    // public function getCampaignAndList($request)
    // {
    //     $campaignId = $request->input('campaign_id');
    //     $isDeleted = $request->input('is_deleted', 0); // default to 0 if not passed

    //     // Validate input
    //     if (!is_numeric($campaignId)) {
    //         return [
    //             'success' => 'false',
    //             'message' => 'Invalid campaign_id',
    //             'data' => []
    //         ];
    //     }

    //     try {
    //         // Main campaign + list fetch
    //         $sql = "
    //         SELECT 
    //             campaign_list.campaign_id,
    //             campaign_list.status,
    //             campaign_list.list_id,
    //             campaign_list.is_deleted,
    //             list.title AS l_title,
    //             campaign_list.updated_at,
    //             list.id,
    //             campaign.title,
    //             campaign.crm_title_url,
    //            campaign.updated 
    //         FROM campaign_list
    //         INNER JOIN list ON campaign_list.list_id = list.id
    //         INNER JOIN campaign ON campaign_list.campaign_id = campaign.id
    //         WHERE campaign_list.campaign_id = :campaign_id
    //         AND campaign_list.is_deleted = :is_deleted AND campaign_list.status = 1

    //     ";

    //         $bindings = [
    //             'campaign_id' => $campaignId,
    //             'is_deleted' => $isDeleted,
    //         ];

    //         $records = DB::connection('mysql_' . $request->auth->parent_id)->select($sql, $bindings);

    //         if (empty($records)) {
    //             return [
    //                 'success' => 'false',
    //                 'message' => 'No campaign list found.',
    //                 'data' => []
    //             ];
    //         }

    //         // Enrich records with counts
    //         foreach ($records as $record) {
    //             $data1 = [
    //                 'campaign_id' => $record->campaign_id,
    //                 'list_id' => $record->list_id,
    //             ];

    //             // Lead Report count
    //             $sqlLeadReport = "
    //             SELECT COUNT(1) as rowCountLeadReport 
    //             FROM lead_report 
    //             WHERE campaign_id = :campaign_id 
    //               AND list_id = :list_id
    //         ";
    //             $leadCount = DB::connection('mysql_' . $request->auth->parent_id)
    //                 ->selectOne($sqlLeadReport, $data1);
    //             $record->rowLeadReport = $leadCount->rowCountLeadReport ?? 0;

    //             // List Data count
    //             $sqlListData = "
    //             SELECT COUNT(1) as rowCountList 
    //             FROM list_data 
    //             WHERE list_id = :list_id
    //         ";
    //             $listCount = DB::connection('mysql_' . $request->auth->parent_id)
    //                 ->selectOne($sqlListData, ['list_id' => $record->list_id]);
    //             $record->rowListData = $listCount->rowCountList ?? 0;
    //             $record->happer_count = $listCount->rowCountList ?? 0;
    //             $record->created_date = $record->updated_at;
    //         }

    //         return [
    //             'success' => 'true',
    //             'message' => 'Campaign List detail.',
    //             'data' => $records,
    //         ];
    //     } catch (\Exception $e) {
    //         return [
    //             'success' => 'false',
    //             'message' => 'Error: ' . $e->getMessage(),
    //             'data' => []
    //         ];
    //     }
    // }

public function getCampaignAndList($request)
{
    try {
        $parentConn = 'mysql_' . $request->auth->parent_id;

        // -------------------------------------------------
        // INPUT & VALIDATION
        // -------------------------------------------------
        $campaignId = $request->input('campaign_id');

        if (!is_numeric($campaignId)) {
            return [
                'success' => 'false',
                'message' => 'Invalid campaign_id',
                'data'    => []
            ];
        }

        // Normalize is_deleted (NEVER empty)
        $isDeleted = ($request->has('is_deleted') && $request->input('is_deleted') !== '')
            ? (int) $request->input('is_deleted')
            : 0;

        // -------------------------------------------------
        // FETCH CAMPAIGN (to know CRM type)
        // -------------------------------------------------
        $campaign = DB::connection($parentConn)
            ->table('campaign')
            ->where('id', (int) $campaignId)
            ->first();

        if (!$campaign) {
            return [
                'success' => 'false',
                'message' => 'Campaign not found',
                'data'    => []
            ];
        }

        // -------------------------------------------------
        // BUILD QUERY (NORMAL vs HUBSPOT)
        // -------------------------------------------------
        if ($campaign->crm_title_url === 'hubspot') {

            $sql = "
                SELECT 
                    cl.campaign_id,
                    cl.status,
                    cl.list_id,
                    cl.is_deleted,
                    l.title AS l_title,
                    l.size AS lead_count,
                    cl.updated_at,
                    c.title,
                    c.crm_title_url,
                    c.updated
                FROM hubspot_campaign_list cl
                INNER JOIN hubspot_lists l ON cl.list_id = l.list_id
                INNER JOIN campaign c ON cl.campaign_id = c.id
                WHERE cl.campaign_id = :campaign_id
                  AND cl.is_deleted = :is_deleted
                  AND cl.status = 1
            ";

        } else {

            $sql = "
                SELECT 
                    cl.campaign_id,
                    cl.status,
                    cl.list_id,
                    cl.is_deleted,
                    l.title AS l_title,
                    l.lead_count,
                    cl.updated_at,
                    l.id,
                    c.title,
                    c.crm_title_url,
                    c.updated
                FROM campaign_list cl
                INNER JOIN list l ON cl.list_id = l.id
                INNER JOIN campaign c ON cl.campaign_id = c.id
                WHERE cl.campaign_id = :campaign_id
                  AND cl.is_deleted = :is_deleted
                  AND cl.status = 1
            ";
        }

        $bindings = [
            'campaign_id' => (int) $campaignId,
            'is_deleted'  => (int) $isDeleted,
        ];

        $records = DB::connection($parentConn)->select($sql, $bindings);

        // -------------------------------------------------
        // NO LIST ATTACHED (SAFE EXIT)
        // -------------------------------------------------
        if (empty($records)) {
            return [
                'success' => 'true',
                'message' => 'Campaign exists but no list attached',
                'data'    => []
            ];
        }

        // -------------------------------------------------
        // ADD COUNTS
        // -------------------------------------------------
        foreach ($records as $record) {

            // Lead report count
            $leadCount = DB::connection($parentConn)
                ->selectOne(
                    "SELECT COUNT(1) AS total FROM lead_report 
                     WHERE campaign_id = :campaign_id AND list_id = :list_id",
                    [
                        'campaign_id' => $record->campaign_id,
                        'list_id'     => $record->list_id
                    ]
                );

            $record->rowLeadReport = $leadCount->total ?? 0;
$record->dialed_leads = $record->rowLeadReport ?? 0;

            // List data count (non-hubspot only)
            if ($campaign->crm_title_url !== 'hubspot') {
                $listCount = DB::connection($parentConn)
                    ->selectOne(
                        "SELECT COUNT(1) AS total FROM list_data WHERE list_id = :list_id",
                        ['list_id' => $record->list_id]
                    );

                $record->rowListData = $listCount->total ?? 0;
            } else {
                $record->rowListData = $record->lead_count ?? 0;
            }

            // Hopper count
            $record->hopper_count = $record->rowListData;

            // Rename fields
            $record->created_date = $record->updated_at;
            $record->lead_count   = $record->lead_count ?? 0;
        }

        // -------------------------------------------------
        // RESPONSE
        // -------------------------------------------------
        return [
            'success' => 'true',
            'message' => 'Campaign list detail',
            'data'    => $records
        ];

    } catch (\Throwable $e) {
        return [
            'success' => 'false',
            'message' => 'Error: ' . $e->getMessage(),
            'data'    => []
        ];
    }
}


  

    function getDispositionAndList($request)
    {



        try {
            $data = array();
            $searchStr = array();
            if ($request->has('list_id') && is_numeric($request->input('list_id'))) {
                $data['list_id'] = $request->input('list_id');
            }

            $sql = "SELECT l.disposition_id as id, IF(l.disposition_id = 0 , 'Not Dialed', d.title) as name, COUNT(l.list_id) as record_count FROM lead_report as l
                LEFT JOIN disposition as d ON l.disposition_id = d.id WHERE l.list_id = :list_id group by l.disposition_id";
            $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql, $data);
            $data = (array) $record;







            //return $data;
            if (!empty($data)) {
                return array(
                    'success' => 'true',
                    'message' => 'Disposition List detail.',
                    'data' => $data
                );
            }
            return array(
                'success' => 'false',
                'message' => 'Disposition List Not Found.',
                'data' => array()
            );
        } catch (Exception $e) {
            echo $e->getMessage();
        } catch (InvalidArgumentException $e) {
            echo $e->getMessage();
        }
    }

    function deleteDispositionAndList($request)
    {



        try {
            $data = array();
            $searchStr = array();
            if ($request->has('list_id') && is_numeric($request->input('list_id'))) {
                $data['list_id'] = $request->input('list_id');
            }

            if ($request->has('campaign_id') && is_numeric($request->input('campaign_id'))) {
                $data['campaign_id'] = $request->input('campaign_id');
            }
              $parentId   = $request->auth->parent_id;
                $campaignId = (int) $request->input('campaign_id');
                $listId     = (int) $request->input('list_id');

        /**
         * --------------------------------------------------
         * ✅ STEP 1: Check campaign status before recycling
         * --------------------------------------------------
         */
        $campaign = DB::connection('mysql_' . $parentId)
            ->table('campaign')
            ->where('id', $campaignId)
            ->first();

        if ($campaign && (int)$campaign->status === 0) {
            // Activate campaign first
            DB::connection('mysql_' . $parentId)
                ->table('campaign')
                ->where('id', $campaignId)
                ->update(['status' => 1]);
        }

            $disposition = $request->input('disposition');
            $select_id = $request->input('select_id');

            $count_deletedLeads = 0;
            foreach ($disposition as $dis => $dispositionId) {
                $userCount = $select_id[$dis];
                $data['disposition_id'] = $dispositionId;
                $sql = "SELECT lr.list_id, lr.lead_id, if(p.total is null, '0', p.total) as total
                    FROM lead_report as lr
                    LEFT JOIN (
                    SELECT lead_id, count(id) as total, campaign_id FROM cdr WHERE campaign_id = " . $request->input('campaign_id') . " group by lead_id
                    UNION
                    SELECT lead_id, count(id) as total, campaign_id FROM cdr WHERE campaign_id = " . $request->input('campaign_id') . " group by lead_id
                    ) as p
                    ON p.lead_id = lr.lead_id AND lr.campaign_id = p.campaign_id
                    WHERE lr.campaign_id = " . $request->input('campaign_id') . " AND lr.list_id = " . $request->input('list_id') . " AND lr.disposition_id = " . $dispositionId . "";

                // $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql, $data);
                                $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql);

                //return $data = (array)$record;

                $deleteId = array();
                foreach ($record as $key => $value) {
                    if ($value->total <= $userCount) {
                        array_push($deleteId, $value->lead_id);
                    }
                }

                //return $deleteId;

                if (!empty($deleteId)) {
                    $deleteSql = "DELETE FROM lead_report WHERE lead_id in (" . implode(",", $deleteId) . ")";
                    DB::connection('mysql_' . $request->auth->parent_id)->update($deleteSql);
                }

                $count[$dispositionId] = count($deleteId);
                $count_deletedLeads = $count_deletedLeads + $count[$dispositionId];
                //Send Email
            }

            $recycle_data = [
                "action" => "Recycle Data",
                "listId" => $request->input('list_id'),
                "records" => $count_deletedLeads,
                //"deletedId" => $deleteId,
                "disposition_count" => $count,
                "disposition" => $disposition,
                "campaignId" => $request->input('campaign_id')
            ];
            // return $recycle_data;



           // $campaignId = $request->input('campaign_id');
            dispatch(new RecycleDeletedNotificationJob($request->auth->parent_id, $campaignId, $recycle_data))->onConnection("database");
            //return $data;
            if (!empty($data)) {
                return array(
                    'success' => 'true',
                    'message' => 'Disposition List deleted successfully.',
                    'data' => $data
                );
            }
            return array(
                'success' => 'false',
                'message' => 'Disposition List Not Deleted Successfully.',
                'data' => array()
            );
        } catch (Exception $e) {
            echo $e->getMessage();
        } catch (InvalidArgumentException $e) {
            echo $e->getMessage();
        }
    }

    public function copyCampaign($request)
    {
        $campignStatus = '';
        $campaignId = $request->input('c_id');
        $userArray = Campaign::on('mysql_' . $request->auth->parent_id)->where('id', $campaignId)->first();
        if ($userArray['id']) {
            $id = Campaign::on('mysql_' . $request->auth->parent_id)->insertGetId(
                [
                    'title' => 'Copy ' . $userArray['title'],
                    'description' => $userArray['description'],
                    'status' => $userArray['status'],
                    'is_deleted' => $userArray['is_deleted'],
                    'caller_id' => $userArray['caller_id'],
                    'custom_caller_id' => $userArray['custom_caller_id'],
                    'time_based_calling' => $userArray['time_based_calling'],
                    'call_time_start' => $userArray['call_time_start'],
                    'call_time_end' => $userArray['call_time_end'],
                    'dial_mode' => $userArray['dial_mode'],
                    'group_id' => $userArray['group_id'],
                    'max_lead_temp' => $userArray['max_lead_temp'],
                    'min_lead_temp' => $userArray['min_lead_temp'],
                    'api' => $userArray['api'],
                    'send_report' => $userArray['send_report'],
                    'campaign' => $userArray['campaign'],
                    'send_crm' => $userArray['send_crm'],
                    'email' => $userArray['email'],
                    'sms' => $userArray['sms'],
                ]
            );

            //copy for API

            $sql = "SELECT * FROM api  WHERE is_default = :is_default";
            $record =  DB::connection('mysql_' . $request->auth->parent_id)->selectOne($sql, array('is_default' => '1'));
            $data = (array)$record;
            $request['api_id'] = $data['id'];

            $campaignId_new = $id;

            $this->copyApiByNewCampaign($request, $campaignId_new);

            //end API


            $now = date('Y-m-d h:i:s');
            $campaignDispositionArray = CampaignDisposition::on('mysql_' . $request->auth->parent_id)->where('campaign_id', $campaignId)->get();
            foreach ($campaignDispositionArray as $key => $val) {
                $user_Record[] = array('disposition_id' => $val->disposition_id, 'campaign_id' => $id, 'is_deleted' => 0, 'updated_at' => $now);
            }
            if (!empty($user_Record)) {
                $campignStatus = CampaignDisposition::on('mysql_' . $request->auth->parent_id)->insert($user_Record);
            }

            if ($campignStatus) {
                return array(
                    'success' => 'true',
                    'message' => 'Copy campaign successfully.',
                    'data' => $id
                );
            } else {
                return array(
                    'success' => 'false',
                    'message' => 'Copy campaign failed.',
                    'data' => array()
                );
            }
        }
    }

function campaignById($request)
{
    $campaignId = $request->campaign_id;

    $campaign = Campaign::on('mysql_' . $request->auth->parent_id)
        ->where('id', $campaignId)
        ->first();

    Log::info('campaign by id', ['campaign' => $campaign]);

if (!$campaign) {
    return response()->json([
        'success' => false,
        'message' => 'Campaign not found.',
        'data' => []
    ], 402);
}

    // === Predictive Dial logic ===
    if ($campaign->dial_mode == 'predictive_dial') {
        $campaign->call_ratio = $campaign->call_ratio;
        $campaign->duration = $campaign->duration;

        if ($campaign->amd == 1) {
            if ($campaign->amd_drop_action == 2) {
                $campaign->setAttribute('audio_message_amd', $campaign->voicedrop_option_user_id ?? 0);
            } elseif ($campaign->amd_drop_action == 3) {
                $campaign->setAttribute('voice_message_amd', $campaign->voicedrop_option_user_id ?? 0);
            }
        } else {
            $campaign->amd_drop_action = 0;
            $campaign->voicedrop_option_user_id = 0;
        }

        // --- Corrected no_agent_available_action logic ---
        if ($campaign->no_agent_available_action == 1) {
            $campaign->no_agent_dropdown_action = 0;
        } elseif ($campaign->no_agent_available_action == 2) {
            $campaign->no_agent_dropdown_action = $campaign->no_agent_dropdown_action;
            $campaign->setAttribute('voicedrop_no_agent_available_action', $campaign->no_agent_dropdown_action ?? 0);
        } elseif ($campaign->no_agent_available_action == 3) {
            $campaign->no_agent_dropdown_action = $campaign->no_agent_dropdown_action;
            $campaign->setAttribute('inbound_ivr_no_agent_available_action', $campaign->no_agent_dropdown_action ?? 0);
        } elseif ($campaign->no_agent_available_action == 4) {
            $campaign->no_agent_dropdown_action = $campaign->no_agent_dropdown_action;
            $campaign->setAttribute('extension_no_agent_available_action', $campaign->no_agent_dropdown_action ?? 0);
        } elseif ($campaign->no_agent_available_action == 5) {
            $campaign->no_agent_dropdown_action = $campaign->no_agent_dropdown_action;
            $campaign->setAttribute('assistant_no_agent_available_action', $campaign->no_agent_dropdown_action ?? 0);
        }
        // --------------------------------------------------

        $campaign->redirect_to = 0;
        $campaign->redirect_to_dropdown = 0;
    } elseif ($campaign->dial_mode == 'outbound_ai') {
        $campaign->call_ratio = $campaign->call_ratio;
        $campaign->duration = $campaign->duration;

        if ($campaign->amd == 1) {
            if ($campaign->amd_drop_action == 2) {
                $campaign->setAttribute('audio_message_amd', $campaign->voicedrop_option_user_id ?? 0);
            } elseif ($campaign->amd_drop_action == 3) {
                $campaign->setAttribute('voice_message_amd', $campaign->voicedrop_option_user_id ?? 0);
            }
        } else {
            $campaign->amd_drop_action = 0;
            $campaign->voicedrop_option_user_id = 0;
        }

        $campaign->no_agent_available_action = 0;
        $campaign->no_agent_dropdown_action = 0;

        $campaign->redirect_to = $campaign->redirect_to;
        $campaign->redirect_to_dropdown = $campaign->redirect_to_dropdown ?? 0;
 if ($campaign->redirect_to == 1) {
    $campaign->setAttribute('outbound_ai_dropdown_audio_message', $campaign->redirect_to_dropdown);

} elseif ($campaign->redirect_to == 2) {
    $campaign->setAttribute('outbound_ai_dropdown_voice_message', $campaign->redirect_to_dropdown);

} elseif ($campaign->redirect_to == 3) {
    $campaign->setAttribute('outbound_ai_dropdown_extension', $campaign->redirect_to_dropdown);

} elseif ($campaign->redirect_to == 4) {
    $campaign->setAttribute('outbound_ai_dropdown_ring_group', $campaign->redirect_to_dropdown);

} elseif ($campaign->redirect_to == 5) {
    $campaign->setAttribute('outbound_ai_dropdown_ivr', $campaign->redirect_to_dropdown);
}
    } else {
        $campaign->call_ratio = 1;
        $campaign->duration = 0;
        $campaign->amd_drop_action = 0;
        $campaign->voicedrop_option_user_id = 0;
        $campaign->redirect_to = 0;
        $campaign->redirect_to_dropdown = 0;
        $campaign->no_agent_available_action = 0;
        $campaign->no_agent_dropdown_action = 0;
        $campaign->outbound_ai_dropdown_audio_message =0;
        $campaign->outbound_ai_dropdown_voice_message =0;
        $campaign->outbound_ai_dropdown_extension =0;
        $campaign->outbound_ai_dropdown_ring_group=0;
        $campaign->outbound_ai_dropdown_ivr=0;
    }

    // === Lead report count ===
    $data1['campaign_id'] = $campaign->id;
    $sql_count_lead_report = "SELECT count(1) as rowCountLeadReport FROM lead_report WHERE campaign_id = :campaign_id ";
    $record_count_lead = DB::connection('mysql_' . $request->auth->parent_id)->selectOne($sql_count_lead_report, $data1);
    $campaign->rowLeadReport = $record_count_lead->rowCountLeadReport ?? 0;

    // === Campaign list ===
    $searchStr = [];
    if ($data1['campaign_id'] && is_numeric($data1['campaign_id'])) {
        $searchStr[] = 'campaign_id = :campaign_id';
    }

    if ($campaign->crm_title_url === 'hubspot') {
        $sql = "SELECT * FROM hubspot_campaign_list WHERE " . implode(" AND ", $searchStr) . " AND status=1 AND is_deleted=0";
    } else {
        $sql = "SELECT * FROM campaign_list WHERE " . implode(" AND ", $searchStr) . " AND status=1 AND is_deleted=0";
    }

    $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql, $data1);
    $list = (array) $record;
    $count = count($list);
    $id_list = [];

    foreach ($list as $listItem) {
        $id_list[] = $listItem->list_id;
    }

    $campaign->rowList = $count;

// === Total leads (same logic as campaignDetail) ===
if (!empty($id_list)) {

    $sql_lead_count = "SELECT SUM(lead_count) as totalLeadCount FROM list WHERE id IN (" . implode(',', $id_list) . ")";
    $lead_count_record = DB::connection('mysql_' . $request->auth->parent_id)->selectOne($sql_lead_count);

    if (!empty($lead_count_record) && $lead_count_record->totalLeadCount > 0) {
        $campaign->total_leads = (int) $lead_count_record->totalLeadCount;
    } else {
        if ($campaign->crm_title_url == 'hubspot') {
            $sql = "SELECT SUM(size) as total FROM hubspot_lists WHERE list_id IN ('" . implode("','", $id_list) . "')";
        } else {
            $sql = "SELECT COUNT(*) as total FROM list_data WHERE list_id IN ('" . implode("','", $id_list) . "')";
        }

        $record = DB::connection('mysql_' . $request->auth->parent_id)->selectOne($sql);
        $campaign->total_leads = (int) ($record->total ?? 0);
    }
} else {
    $campaign->total_leads = 0;
}


    // === Lead temp count ===
    $sql_lead_temp = "SELECT count(1) as rowLeadTemp FROM lead_temp WHERE campaign_id = :campaign_id ";
    $record_rowLeadTemp = DB::connection('mysql_' . $request->auth->parent_id)->selectOne($sql_lead_temp, $data1);
    $campaign->rowLeadTemp = $record_rowLeadTemp->rowLeadTemp ?? 0;

    // === Fetch dispositions ===
    $dispositions = DB::connection('mysql_' . $request->auth->parent_id)
        ->table('campaign_disposition')
        ->where('campaign_id', $campaign->id)
        ->where('is_deleted', 0)
        ->pluck('disposition_id')
        ->toArray();

    // convert dispositions to string
    $dispositions = array_map('strval', $dispositions);

    // add dispositions & hopper count directly to current $campaign
    $campaign->setAttribute('dispositions', $dispositions);
    $campaign->setAttribute('hopper_count', $campaign->rowLeadTemp);

    // Always ensure keys exist
    if (!$campaign->getAttribute('voicedrop_no_agent_available_action')) {
        $campaign->setAttribute('voicedrop_no_agent_available_action', 0);
    }
    if (!$campaign->getAttribute('inbound_ivr_no_agent_available_action')) {
        $campaign->setAttribute('inbound_ivr_no_agent_available_action', 0);
    }
    if (!$campaign->getAttribute('extension_no_agent_available_action')) {
        $campaign->setAttribute('extension_no_agent_available_action', 0);
    }
    if (!$campaign->getAttribute('assistant_no_agent_available_action')) {
        $campaign->setAttribute('assistant_no_agent_available_action', 0);
    }
    if (!$campaign->getAttribute('voice_message_amd')) {
        $campaign->setAttribute('voice_message_amd', 0);
    }
    if (!$campaign->getAttribute('outbound_ai_dropdown_audio_message')) {
        $campaign->setAttribute('outbound_ai_dropdown_audio_message', 0);
    }
    if (!$campaign->getAttribute('outbound_ai_dropdown_voice_message')) {
        $campaign->setAttribute('outbound_ai_dropdown_voice_message', 0);
    }    if (!$campaign->getAttribute('outbound_ai_dropdown_extension')) {
        $campaign->setAttribute('outbound_ai_dropdown_extension', 0);
    }    if (!$campaign->getAttribute('outbound_ai_dropdown_ring_group')) {
        $campaign->setAttribute('outbound_ai_dropdown_ring_group', 0);
    }    if (!$campaign->getAttribute('outbound_ai_dropdown_ivr')) {
        $campaign->setAttribute('outbound_ai_dropdown_ivr', 0);
    }    
    if (!$campaign->getAttribute('audio_message_amd')) {
        $campaign->setAttribute('audio_message_amd', 0);
    }
    // === Rename / Map columns for API output ===
//$campaign->setAttribute('total_leads', $campaign->rowLeadReport ?? 0);
$campaign->setAttribute('dialed_leads', $campaign->rowLeadReport ?? 0);
//$campaign->setAttribute('created_date', $campaign->updated ?? null);

// Optionally remove old keys to clean response
unset($campaign->rowLeadReport, $campaign->rowListData, $campaign->updated);
if (!empty($campaign->call_schedule_id)) {
    $schedule = DB::connection('mysql_' . $request->auth->parent_id)
        ->table('call_timers')
        ->where('id', $campaign->call_schedule_id)
        ->value('title');

    $campaign->setAttribute('schedule_name', $schedule ?? null);
}
// ✅ Convert timestamps to user timezone (API response only)
$userTimezone = $request->auth->timezone ?? 'Asia/Kolkata';

if (!empty($campaign->created_at)) {
    $campaign->created_at = convertToUserTimezone($campaign->created_at, $userTimezone);
}

if (!empty($campaign->updated)) {
    $campaign->updated = convertToUserTimezone($campaign->updated, $userTimezone);
}

    // return as collection for consistency
    //return collect($campaign);
    return response()->json([
    'success' => true,
    'message' => 'Campaign details fetched successfully.',
    'data' => $campaign
], 200);


}
    function campaignByIdNew($request)
    {

        $campaignId = $request->campaign_id;

        $campaign = Campaign::on('mysql_' . $request->auth->parent_id)
            ->where('id', $campaignId)
            ->first();

        if (!$campaign) {
            return [
                'success' => 'false',
                'message' => 'Campaign not found.',
                'data' => []
            ];
        }

        // Convert for query bindings
        $data1['campaign_id'] = $campaign->id;

        // 1. lead_report count
        $sql_count_lead_report = "SELECT count(1) as rowCountLeadReport FROM lead_report WHERE campaign_id = :campaign_id ";
        $record_count_lead = DB::connection('mysql_' . $request->auth->parent_id)->selectOne($sql_count_lead_report, $data1);
        $campaign->rowLeadReport = $record_count_lead->rowCountLeadReport ?? 0;

        // 2. campaign_list or hubspot_campaign_list
        $searchStr = [];
        if ($data1['campaign_id'] && is_numeric($data1['campaign_id'])) {
            $searchStr[] = 'campaign_id = :campaign_id';
        }

        if ($campaign->crm_title_url === 'hubspot') {
            $sql = "SELECT * FROM hubspot_campaign_list WHERE " . implode(" AND ", $searchStr) . " AND status=1 AND is_deleted=0";
        } else {
            $sql = "SELECT * FROM campaign_list WHERE " . implode(" AND ", $searchStr) . " AND status=1 AND is_deleted=0";
        }

        $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql, $data1);
        $list = (array) $record;
        $count = count($list);
        $id_list = [];

        foreach ($list as $listItem) {
            $id_list[] = $listItem->list_id;
        }

        $campaign->rowList = $count;

        $list_ids = "'" . implode("','", $id_list) . "'";
        if ($campaign->crm_title_url === 'hubspot') {
            $sql_count_list = "SELECT sum(size) as rowCountList FROM hubspot_lists WHERE list_id IN ($list_ids)";
        } else {
            $sql_count_list = "SELECT count(1) as rowCountList FROM list_data WHERE list_id IN ($list_ids)";
        }

        $record_count_list = DB::connection('mysql_' . $request->auth->parent_id)->select($sql_count_list);
        $campaign->rowListData = $record_count_list[0]->rowCountList ?? 0;

        // 4. lead_temp count
        $sql_lead_temp = "SELECT count(1) as rowLeadTemp FROM lead_temp WHERE campaign_id = :campaign_id ";
        $record_rowLeadTemp = DB::connection('mysql_' . $request->auth->parent_id)->selectOne($sql_lead_temp, $data1);
        $campaign->rowLeadTemp = $record_rowLeadTemp->rowLeadTemp ?? 0;

        // Optional: add created_date or hopper_count
        $campaign->created_date = $campaign->created_at;
        $campaign->hopper_count = 1; // Replace with real count if needed

        //  $hopper_count=0;
        $hopper_count = $campaign->rowLeadTemp;
        $userArray = Campaign::on('mysql_' . $request->auth->parent_id)
            ->where('id', $request->campaign_id)
            ->get()
            ->map(function ($campaign) use ($hopper_count) {
                // Add created_date
                $campaign->setAttribute('created_date', $campaign->updated ?: null);

                // Add hopper_count
                $campaign->setAttribute('hopper_count', $hopper_count);

                return $campaign;
            });

        return $userArray;
    }
    function campaignById_old($request)
    {
        $userArray = Campaign::on('mysql_' . $request->auth->parent_id)->where('id', $request->campaign_id)->get();
        return $userArray;
    }

    //hubspot

    function getCampaignAndListHubspot($request)
    {

        try {
            $data = array();
            $searchStr = array();
            if ($request->has('campaign_id') && is_numeric($request->input('campaign_id'))) {
                $data['campaign_id'] = $request->input('campaign_id');
                $data['is_deleted'] = $request->input('is_deleted');
            }

            $sql = "SELECT campaign_list.campaign_id,campaign_list.status,campaign_list.list_id,campaign_list.is_deleted,list.title as l_title,list.id,campaign.title,campaign.crm_title_url,list.size as rowListData FROM hubspot_campaign_list as campaign_list inner join hubspot_lists as list on campaign_list.list_id = list.id inner join campaign on campaign_list.campaign_id = campaign.id WHERE campaign_list.campaign_id = '" . $request->input('campaign_id') . "' and campaign_list.is_deleted ='" . $request->input('is_deleted') . "'";

            $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql, $data);
            $data = (array) $record;

            foreach ($data as $key => $id) {

                $data1['campaign_id'] = $id->campaign_id;
                $data1['list_id'] = $id->list_id;

                $sql_count_lead_report = "SELECT count(1) as rowCountLearReport FROM lead_report WHERE campaign_id = :campaign_id  and list_id = :list_id";
                $record_count_lead = DB::connection('mysql_' . $request->auth->parent_id)->selectOne($sql_count_lead_report, $data1);
                $id->rowLeadReport = $record_count_lead->rowCountLearReport;

                $list_data['list_id'] = $id->list_id;


                /*$sql_count_list = "SELECT count(1) as rowCountList FROM list_data WHERE list_id=:list_id ";
                $record_count_list = DB::connection('mysql_' . $request->auth->parent_id)->select($sql_count_list, $list_data);

                //return $data = (array)$record_count_list;
                //$id->rowList = $count;
                $id->rowListData = $record_count_list[0]->rowCountList;*/
            }

            //return $data;
            if (!empty($data)) {
                return array(
                    'success' => 'true',
                    'message' => 'Campaign List detail.',
                    'data' => $data
                );
            }
            return array(
                'success' => 'false',
                'message' => 'Campaign List Found.',
                'data' => array()
            );
        } catch (Exception $e) {
            echo $e->getMessage();
        } catch (InvalidArgumentException $e) {
            echo $e->getMessage();
        }
    }

    //close hubspot
    function updateCampaignStatus($request)
    {
        
        $listId = $request->input('listId');
        $status = $request->input('status');
        Log::debug('Received status: ', ['status' => $status]);

        $saveRecord = Campaign::on('mysql_' . $request->auth->parent_id)
            ->where('id', $listId) // Use the actual listId received from the request
            ->update(array('status' => $status));
        $saveRecordCampaignList = CampaignList::on('mysql_' . $request->auth->parent_id)
            ->where('campaign_id', $listId)
            ->update(array('status' => $status));

        // Log::debug('Received listId: ', ['listId' => $listId]);
        // Log::debug('Number of updated rows: ', ['saveRecord' => $saveRecord]);
        if ($saveRecord >= 0 && $saveRecordCampaignList >= 0) {
            return response()->json([
                'success' => 'true',
                'status' => 'true',
                'message' => 'Campaign status updated successfully'
            ]);
        } else {
            return response()->json([
                'success' => 'false',
                'status' => 'false',
                'message' => 'Campaign update failed'
            ]);
        }
    }
    function updateCampaignHopper($request)
    {
        $listId = $request->input('listId');
        $hopper_mode = $request->input('status');

        $saveRecord = Campaign::on('mysql_' . $request->auth->parent_id)
            ->where('id', $listId) // Use the actual listId received from the request
            ->update(array('hopper_mode' => $hopper_mode));
        Log::debug('Update SQL query: ', ['sql' => Campaign::on('mysql_' . $request->auth->parent_id)
            ->where('id', $listId)
            ->toSql()]);

        // Log::debug('Received listId: ', ['listId' => $listId]);
        // Log::debug('Received status: ', ['status' => $hopper_mode]);
        // Log::debug('Number of updated rows: ', ['saveRecord' => $saveRecord]);
        if ($saveRecord > 0) {
            return response()->json([
                'success' => 'true',
                'status' => 'true',
                'message' => 'Hopper mode  updated successfully'
            ]);
        } else {
            return response()->json([
                'success' => 'false',
                'status' => 'false',
                'message' => 'Hopper mode  failed'
            ]);
        }
    }
}
