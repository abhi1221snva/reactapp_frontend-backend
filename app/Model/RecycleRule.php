<?php

namespace App\Model;

use App\Http\Helper\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;

class RecycleRule extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];
    protected $table = "recycle_rule";

    /*
     *Add Recycle Rule details
     *@param object $request
     *@return array
     */
    public function addRecycleRule($request)
    {
        try {
            if (
                $request->has('campaign_id') && is_numeric($request->input('campaign_id')) &&
                $request->has('list_id') && is_numeric($request->input('list_id')) &&
                $request->has('disposition') && is_array($request->input('disposition')) &&
                $request->has('day') && is_array($request->input('day')) &&
                $request->has('time') && !empty($request->input('time')) &&
                $request->has('call_time') && is_numeric($request->input('call_time'))
            ) {
                $dayArray = array('sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday');
                $data['campaign_id'] = $request->input('campaign_id');
                $data['list_id'] = $request->input('list_id');
                $disposition = $request->input('disposition');
                $day = $request->input('day');
                $data['time'] = $request->input('time');
                $data['call_time'] = $request->input('call_time');
                foreach ($disposition as $key => $value) {
                    foreach ($day as $item => $itemValue) {
                        $itemValue = strtolower($itemValue);
                        if (in_array($itemValue, $dayArray)) {
                            $data['disposition_id'] = $value;
                            $data['day']            = $itemValue;
                            $query = "INSERT INTO " . $this->table . " (campaign_id, list_id, disposition_id, day, time, call_time)
                                            VALUE (:campaign_id, :list_id, :disposition_id, :day, :time, :call_time)";
                            $add =  DB::connection('mysql_' . $request->auth->parent_id)->insert($query, $data);
                        }
                    }
                }
                if ($add == 1) {
                    return array(
                        'success' => 'true',
                        'message' => 'Recycle rules added successfully.'
                    );
                } else {
                    return array(
                        'success' => 'false',
                        'message' => 'Recycle rules are not added successfully.'
                    );
                }
            }

            return array(
                'success' => 'false',
                'message' => 'Recycle rules are not added successfully.'
            );
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }
    /*
     *Fetch RecycleRules
     *@param integer $id
     *@return array
     */
    public function getRecycleRule($request)
    {
        $searchStr = array('rr.is_deleted = :is_deleted');
        $data['is_deleted'] = 0;
        $dayArray = array('sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday');
        if ($request->has('recycle_rule_id') && is_numeric($request->input('recycle_rule_id'))) {
            array_push($searchStr, 'rr.id = :id');
            $data['id'] = $request->input('recycle_rule_id');
        }
        if ($request->has('campaign_id') && is_numeric($request->input('campaign_id'))) {
            array_push($searchStr, 'rr.campaign_id = :campaign_id');
            $data['campaign_id'] = $request->input('campaign_id');
        }
        if ($request->has('list_id') && is_numeric($request->input('list_id'))) {
            array_push($searchStr, 'rr.list_id = :list_id');
            $data['list_id'] = $request->input('list_id');
        }
        if ($request->has('disposition_id') && is_numeric($request->input('disposition_id'))) {
            array_push($searchStr, 'rr.disposition_id = :disposition_id');
            $data['disposition_id'] = $request->input('disposition_id');
        }
        if ($request->has('day') && in_array($request->input('day'), $dayArray)) {
            array_push($searchStr, 'rr.day = :day');
            $data['day'] = $request->input('day');
        }
        if ($request->has('call_time') && is_numeric($request->input('call_time'))) {
            array_push($searchStr, 'rr.call_time = :call_time');
            $data['call_time'] = $request->input('call_time');
        }
        if ($request->has('search') && !empty($request->input('search'))) {
            $search = '%' . $request->input('search') . '%';
            $searchStr[] = '(c.title LIKE :search_campaign OR l.title LIKE :search_list OR d.title LIKE :search_disposition)';
            $data['search_campaign'] = $search;
            $data['search_list'] = $search;
            $data['search_disposition'] = $search;
        }

        $sql = "SELECT
                      rr.*, c.title as campaign, l.title as list, d.title as disposition
                    FROM recycle_rule  as rr
                    LEFT JOIN campaign as c ON c.id = rr.campaign_id
                    LEFT JOIN list as l ON l.id = rr.list_id
                    LEFT JOIN disposition as d ON d.id = rr.disposition_id
                    WHERE " . implode(" AND ", $searchStr);;
        $record =  DB::connection('mysql_' . $request->auth->parent_id)->select($sql, $data);
        $data = (array)$record;
        foreach ($data as $key => $value) {
            if (empty($value->disposition) && !empty($value->disposition_id) && is_numeric($value->disposition_id)) {
                //Fetch data from master
                $sql = "SELECT title FROM disposition  WHERE id = :id";
                $record =  DB::connection('master')->selectOne($sql, array('id' => $value->disposition_id));
                $dispositionMaster = (array)$record;
                if (!empty($dispositionMaster)) {
                    $data[$key]->disposition = $record->title;
                }
            }
        }

        if (!empty($data)) {

            if ($request->has('start') && $request->has('limit')) {

                $total_row = count($data);

                $start = (int) $request->input('start');  // Start index (0-based)
                $limit = (int) $request->input('limit');  // Number of records to fetch

                $data = array_slice($data, $start, $limit, false);
                return array(
                    'success' => 'true',
                    'message' => 'RecycleRules detail.',
                    'start' => $start,
                    'limit' => $limit,
                    'total' => $total_row,
                    'data'   => $data
                );
            }
            return array(
                'success' => 'true',
                'message' => 'RecycleRules detail.',
                'data'   => $data
            );
        }
        return array(
            'success' => 'false',
            'message' => 'RecycleRules not created.',
            'data'   => array()
        );
    }

    public function getRecycleRule_old_code($request)
    {
        $searchStr = array('rr.is_deleted = :is_deleted');
        $data['is_deleted'] = 0;
        $dayArray = array('sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday');
        if ($request->has('recycle_rule_id') && is_numeric($request->input('recycle_rule_id'))) {
            array_push($searchStr, 'rr.id = :id');
            $data['id'] = $request->input('recycle_rule_id');
        }
        if ($request->has('campaign_id') && is_numeric($request->input('campaign_id'))) {
            array_push($searchStr, 'rr.campaign_id = :campaign_id');
            $data['campaign_id'] = $request->input('campaign_id');
        }
        if ($request->has('list_id') && is_numeric($request->input('list_id'))) {
            array_push($searchStr, 'rr.list_id = :list_id');
            $data['list_id'] = $request->input('list_id');
        }
        if ($request->has('disposition_id') && is_numeric($request->input('disposition_id'))) {
            array_push($searchStr, 'rr.disposition_id = :disposition_id');
            $data['disposition_id'] = $request->input('disposition_id');
        }
        if ($request->has('day') && in_array($request->input('day'), $dayArray)) {
            array_push($searchStr, 'rr.day = :day');
            $data['day'] = $request->input('day');
        }
        if ($request->has('call_time') && is_numeric($request->input('call_time'))) {
            array_push($searchStr, 'rr.call_time = :call_time');
            $data['call_time'] = $request->input('call_time');
        }
        $sql = "SELECT
                      rr.*, c.title as campaign, l.title as list, d.title as disposition
                    FROM recycle_rule  as rr
                    LEFT JOIN campaign as c ON c.id = rr.campaign_id
                    LEFT JOIN list as l ON l.id = rr.list_id
                    LEFT JOIN disposition as d ON d.id = rr.disposition_id
                    WHERE " . implode(" AND ", $searchStr);;
        $record =  DB::connection('mysql_' . $request->auth->parent_id)->select($sql, $data);
        $data = (array)$record;
        foreach ($data as $key => $value) {
            if (empty($value->disposition) && !empty($value->disposition_id) && is_numeric($value->disposition_id)) {
                //Fetch data from master
                $sql = "SELECT title FROM disposition  WHERE id = :id";
                $record =  DB::connection('master')->selectOne($sql, array('id' => $value->disposition_id));
                $dispositionMaster = (array)$record;
                if (!empty($dispositionMaster)) {
                    $data[$key]->disposition = $record->title;
                }
            }
        }
        if (!empty($data)) {
            return array(
                'success' => 'true',
                'message' => 'RecycleRules detail.',
                'data'   => $data
            );
        }
        return array(
            'success' => 'false',
            'message' => 'RecycleRules not created.',
            'data'   => array()
        );
    }

    /*
     *Update RecycleRule details
     *@param object $request
     *@return array
     */
    public function editRecycleRule($request)
    {
        try {
            if ($request->has('recycle_rule_id') && is_numeric($request->input('recycle_rule_id'))) {
                $updateString = array();
                $dayArray = array('sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday');
                if ($request->has('is_deleted') && is_numeric($request->input('is_deleted'))) {
                    array_push($updateString, 'is_deleted = :is_deleted');
                    $data['is_deleted'] = $request->input('is_deleted');
                }
                if ($request->has('campaign_id') && is_numeric($request->input('campaign_id'))) {
                    array_push($updateString, 'campaign_id = :campaign_id');
                    $data['campaign_id'] = $request->input('campaign_id');
                }
                if ($request->has('list_id') && is_numeric($request->input('list_id'))) {
                    array_push($updateString, 'list_id = :list_id');
                    $data['list_id'] = $request->input('list_id');
                }
                if ($request->has('disposition_id') && is_numeric($request->input('disposition_id'))) {
                    array_push($updateString, 'disposition_id = :disposition_id');
                    $data['disposition_id'] = $request->input('disposition_id');
                }
                if ($request->has('day') && in_array($request->input('day'), $dayArray)) {
                    array_push($updateString, 'day = :day');
                    $data['day'] = $request->input('day');
                }
                if ($request->has('call_time') && is_numeric($request->input('call_time'))) {
                    array_push($updateString, 'call_time = :call_time');
                    $data['call_time'] = $request->input('call_time');
                }
                if ($request->has('time') && !empty($request->input('time'))) {
                    array_push($updateString, 'time = :time');
                    $data['time'] = $request->input('time');
                }
                $save = 0;
                if (!empty($updateString)) {
                    $data['id'] = $request->input('recycle_rule_id');
                    $query = "UPDATE recycle_rule set " . implode(" , ", $updateString) . " WHERE id = :id";
                    $save = DB::connection('mysql_' . $request->auth->parent_id)->update($query, $data);
                }
                if ($save == 1) {
                    return array(
                        'success' => 'true',
                        'message' => 'Recycle rules updated successfully.'
                    );
                } else {
                    return array(
                        'success' => 'false',
                        'message' => 'Recycle Rules are not updated successfully.'
                    );
                }
            }
            return array(
                'success' => 'false',
                'message' => 'Recycle Rules not updated, Missing required fields',
                'data'   => array()
            );
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }

    public function deleteLeadRule($request)
    {

        try {

            $data = array();
            $searchStr = array();

            if ($request->has('list_id') && is_numeric($request->input('list_id'))) {
                array_push($searchStr, 'list_id = :list_id');
                $data['list_id'] = $request->input('list_id');
            }

            if ($request->has('disposition_id') && is_numeric($request->input('disposition_id'))) {
                array_push($searchStr, 'disposition_id = :disposition_id');
                $data['disposition_id'] = $request->input('disposition_id');
            }


            $sql = "SELECT * FROM lead_report
                    WHERE " . implode(" AND ", $searchStr);
            $record =  DB::connection('mysql_' . $request->auth->parent_id)->select($sql, $data);
            $data = (array)$record;

            if (!empty($data)) {
                /* return array(
                    'success'=> 'true',
                    'message'=> 'RecycleRules detail.',
                    'data'   => $data
                );*/

                $lead_id_arr = array();

                foreach ($data as $lead) {
                    $lead_id_arr[] = $lead->lead_id;
                }

                $lead_id = "'" . implode("', '", $lead_id_arr) . "'";

                /*  SELECT count(*),lead_id from cdr where lead_id IN('1908','1907','1911','1912','1913','1915','1916','1917','1932','1933','1935', '1936') group by lead_id;*/

                $data_lead['lead_id'] = $lead_id;

                $sql = "SELECT count(*) as count_calls,lead_id FROM cdr  WHERE lead_id IN(" . $lead_id . ") group by lead_id";
                $record =  DB::connection('mysql_' . $request->auth->parent_id)->select($sql, $data_lead);
                $data_lead_id = (array)$record;

                $deleted_lead_id = 1;

                foreach ($data_lead_id as $deleteLead) {
                    if ($deleteLead->count_calls < 15) {
                        $query = "DELETE FROM lead_report WHERE lead_id = :lead_id";
                        DB::connection('mysql_' . $request->auth->parent_id)->delete($query, array('lead_id' => $deleteLead->lead_id));
                    }

                    $deleted_lead_id++;
                }


                return array(
                    'success' => 'true',
                    'message' => 'Recycle rule has been run successfully for the list.',
                    'data'   => $deleted_lead_id
                );
            }
            return array(
                'success' => 'false',
                'message' => 'Recycle rule Not Found.',
                'data'   => array()
            );
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }
}
