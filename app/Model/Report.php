<?php

namespace App\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Model\Client\ExtensionGroupMap;
use App\Model\Campaign;
use App\Services\TimezoneService;
use Illuminate\Http\Request;
use App\Model\Master\Client;
use Illuminate\Support\Facades\Schema;



class Report extends Model
{

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];

    /*
     * Fetch CDR from user id
     * @param integer $id
     * @return array
     */


    public function getIvrLogs(Request $request)
    {
        try {
            $searchTerm = $request->input('search');
            $limitString = '';
            $parameters = [];

            $query = "SELECT SQL_CALC_FOUND_ROWS * FROM ivr_log";

            if (!empty($searchTerm)) {
                $query .= " WHERE (number LIKE CONCAT(?, '%') OR extension LIKE CONCAT(?, '%'))";
                $parameters[] = $searchTerm;
                $parameters[] = $searchTerm;
            }

            if ($request->has('lower_limit') && $request->has('upper_limit') && is_numeric($request->input('lower_limit')) && is_numeric($request->input('upper_limit'))) {
                $query .= " LIMIT ?, ?";
                $parameters[] = $request->input('lower_limit');
                $parameters[] = $request->input('upper_limit');
            }

            $record = DB::connection('mysql_' . $request->auth->parent_id)->select($query, $parameters);

            $recordCount = DB::connection('mysql_' . $request->auth->parent_id)->selectOne("SELECT FOUND_ROWS() as count");
            $recordCount = (array)$recordCount;

            $data = (array)$record;

            if (!empty($data)) {
                return [
                    'success' => true,
                    'message' => 'DNC Detail.',
                    'data' => $data,
                    'record_count' => $recordCount['count'],
                    'searchTerm' => $searchTerm
                ];
            }

            return [
                'success' => false,
                'message' => 'DNC not found.',
                'data' => [],
                'record_count' => 0,
                'errors' => [],
                'searchTerm' => $searchTerm
            ];
        } catch (Exception $e) {
            Log::error($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::error($e->getMessage());
        }
    }

    public function getReportByLeadId($request)
    {
        try {
            $id = $request->input('id');
            if (!empty($id) && is_numeric($id)) {
                $search = array();
                $searchString = array();
                $limitString = '';
                if ($request->auth->role == 2) {
                    $search['extension'] = $request->auth->extension;
                    array_push($searchString, 'extension = :extension');
                } elseif ($request->has('extension') && !empty($request->input('extension'))) {
                    $search['extension'] = $request->input('extension');
                    array_push($searchString, 'extension = :extension');
                }
                if ($request->has('lead_id') && !empty($request->input('lead_id'))) {
                    $search['lead_id'] = $request->input('lead_id');
                    array_push($searchString, 'lead_id = :lead_id');
                }
                if ($request->has('campaign') && !empty($request->input('campaign'))) {
                    $search['campaign_id'] = $request->input('campaign');
                    array_push($searchString, 'campaign_id = :campaign_id');
                }


                if ($request->has('route') && !empty($request->input('route'))) {
                    $search['route'] = $request->input('route');
                    array_push($searchString, 'route = :route');
                }


                if ($request->has('disposition') && !empty($request->input('disposition'))) {
                    $search['disposition_id'] = $request->input('disposition');
                    array_push($searchString, 'disposition_id = :disposition_id');
                }
                if ($request->has('type') && !empty($request->input('type'))) {
                    $search['type'] = $request->input('type');
                    array_push($searchString, 'type = :type');
                }
                if ($request->has('start_date') && $request->has('end_date') && !empty($request->input('start_date')) && !empty($request->input('end_date'))) {
                    $start = date('Y-m-d', strtotime($request->input('start_date'))) . " 00:00:00";
                    $end = date('Y-m-d', strtotime($request->input('end_date'))) . " 23:59:59";
                    $search['start_time'] = $start;
                    $search['end_time'] = $end;
                    array_push($searchString, 'start_time BETWEEN :start_time AND :end_time');
                }

                if ($request->has('lower_limit') && $request->has('upper_limit') && is_numeric($request->input('lower_limit')) && is_numeric($request->input('upper_limit'))) {
                    $search['lower_limit'] = $request->input('lower_limit');
                    $search['upper_limit'] = $request->input('upper_limit');
                    $limitString = "LIMIT :lower_limit , :upper_limit";
                }
                $filter = (!empty($searchString)) ? " WHERE " . implode(" AND ", $searchString) : '';
                $sql = "SELECT
                          SQL_CALC_FOUND_ROWS c.id,  c.extension, c.number, c.start_time, c.end_time, c.duration, c.route, c.call_recording,c.campaign_id, c.lead_id,c.type, d.title as disposition
                        FROM
                          cdr as c
                        LEFT JOIN disposition as d ON c.disposition_id = d.id"
                    . $filter . " ORDER BY start_time DESC " . $limitString;
                $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql, $search);
                $recordCount = DB::connection('mysql_' . $request->auth->parent_id)->selectOne("SELECT FOUND_ROWS() as count");
                $recordCount = (array) $recordCount;
                if (!empty($record)) {
                    $data = (array) $record;
                    return array(
                        'success' => 'true',
                        'message' => 'Call Data Report.',
                        'record_count' => $recordCount['count'],
                        'data' => $data
                    );
                } else {
                    return array(
                        'success' => 'false',
                        'message' => 'No Call Data Report found.',
                        'record_count' => 0,
                        'data' => array()
                    );
                }
            }
            return array(
                'success' => 'false',
                'message' => 'Call Data Report doesn\'t exist.'
            );
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }

    public function getReport($request)
    {

        if ($request->has('api_key')) {
            $client = Client::where('api_key', $request->api_key)->get()->first();
            $parent_id = $client->id;
            $connection = 'mysql_' . $client->id;
        } else {
            $parent_id = $request->auth->parent_id;
            $connection = 'mysql_' . $parent_id;
        }
        $user_data_did = array();
        try {

            $search = array();
            $searchString = array();
            $searchString1 = array();

            $limitString = '';
            if ($request->has('number') && !empty($request->input('number'))) {
                $search['number'] = $request->input('number');
                $search['number1'] = $request->input('number');

                //array_push($searchString, 'number = :number');
                //array_push($searchString1, 'number = :number1');
                array_push($searchString, "number like CONCAT(:number, '%')");
                array_push($searchString1, "number like CONCAT(:number1, '%')");
            }

            if (!empty($request->input('numbers'))) {
                $numbers = $request->input('numbers');
                $numbers1 = implode(',', $numbers);
                $numbers2 = implode(',', $numbers);
                array_push($searchString, "number IN ($numbers1)");
                array_push($searchString1, "number IN ($numbers2)");
            }


            if (!empty($request->input('area_code')) && !empty($request->input('timezone_value'))) {
                $timezone_sql = "SELECT areacode FROM master.timezone WHERE timezone = '" . $request->input('timezone_value') . "' ";
                $timezone_sql_result = DB::connection('mysql_' . $parent_id)->select($timezone_sql);
                foreach ($timezone_sql_result as $key => $val) {
                    $user_data_timezone[] = $val->areacode;
                }
                $areacode = $request->input('area_code');

                $merge = array_merge($user_data_timezone, $areacode);

                $area_code = implode(',', $merge);
                $area_code1 = implode(',', $merge);
                array_push($searchString, "area_code IN ($area_code)");
                array_push($searchString1, "area_code IN ($area_code1)");
            } else {

                if ($request->has('timezone_value') && !empty($request->input('timezone_value'))) {
                    $timezone_sql = "SELECT areacode FROM master.timezone WHERE timezone = '" . $request->input('timezone_value') . "' ";
                    $timezone_sql_result = DB::connection('mysql_' . $parent_id)->select($timezone_sql);
                    foreach ($timezone_sql_result as $key => $val) {
                        $user_data_timezone[] = $val->areacode;
                    }

                    $srch_input_1 = implode(',', $user_data_timezone);
                    $srch_input = implode(',', $user_data_timezone);
                    array_push($searchString, " area_code IN ($srch_input)");
                    array_push($searchString1, " area_code IN ($srch_input_1)");
                }

                if (!empty($request->input('area_code'))) {
                    $area_code = implode(',', $request->input('area_code'));
                    $area_code1 = implode(',', $request->input('area_code'));
                    array_push($searchString, "area_code IN ($area_code)");
                    array_push($searchString1, "area_code IN ($area_code1)");
                }
            }

            if ($request->has('campaign') && !empty($request->input('campaign'))) {
                $search['campaign_id'] = $request->input('campaign');
                $search['campaign_id1'] = $request->input('campaign');

                array_push($searchString, 'campaign_id = :campaign_id');
                array_push($searchString1, 'campaign_id = :campaign_id1');
            }

            if ($request->has('route') && !empty($request->input('route'))) {
                $search['route'] = $request->input('route');
                $search['route1'] = $request->input('route');

                array_push($searchString, 'route = :route');
                array_push($searchString1, 'route = :route1');
            }

            if ($request->has('disposition') && !empty($request->input('disposition'))) {
                $disposition = $request->input('disposition');
                $disposition_id = implode(',', $disposition);
                $disposition_id1 = implode(',', $disposition);

                array_push($searchString, "disposition_id IN ($disposition_id)");
                array_push($searchString1, "disposition_id IN ($disposition_id1)");
            }

            if ($request->has('type') && !empty($request->input('type'))) {
                $search['type'] = $request->input('type');
                $search['type1'] = $request->input('type');

                array_push($searchString, 'type = :type');
                array_push($searchString1, 'type = :type1');
            }

            if ($request->has('start_date') && $request->has('end_date') && !empty($request->input('start_date')) && !empty($request->input('end_date'))) {
                $start = date('Y-m-d', strtotime($request->input('start_date'))) . " 00:00:00";
                $end = date('Y-m-d', strtotime($request->input('end_date'))) . " 23:59:59";
                $search['start_time'] = $start;
                $search['end_time'] = $end;

                $search['start_time1'] = $start;
                $search['end_time1'] = $end;



                if (!empty($request->auth->timezone)) {
                    $timeZoneService = new TimezoneService();
                    $timezoneValue = $timeZoneService->findTimezoneValue($request->auth->timezone);
                    array_push($searchString, 'CONVERT_TZ(start_time,"+00:00", "' . $timezoneValue . '") BETWEEN :start_time AND :end_time');
                    array_push($searchString1, 'CONVERT_TZ(start_time,"+00:00", "' . $timezoneValue . '") BETWEEN :start_time1 AND :end_time1');
                } else {
                    array_push($searchString, 'start_time BETWEEN :start_time AND :end_time');
                    array_push($searchString1, 'start_time BETWEEN :start_time1 AND :end_time1');
                }
            }

            if ($request->has('extension') && !empty($request->input('extension'))) {
                $alt_extension_sql = "SELECT alt_extension,app_extension FROM master.users WHERE extension = '" . $request->input('extension') . "'";

                $alt_extension_result = DB::connection('mysql_' . $parent_id)->select($alt_extension_sql);
                $alt_extension = $alt_extension_result[0]->alt_extension;
                $app_extension = $alt_extension_result[0]->app_extension;

                $extensionArray = array();
                Log::info('reached extension', ['extensionArray' => $extensionArray]);
                array_push($extensionArray, $request->input('extension'));
                array_push($extensionArray, $alt_extension);
                array_push($extensionArray, $app_extension);

                foreach ($extensionArray as $key => $val) {
                    $user_data_extension[] = $val;
                }

                //echo "<pre>";print_r($user_data_extension);die;

                $srch_input_1 = implode(',', $user_data_extension);
                $srch_input = implode(',', $user_data_extension);
                array_push($searchString, " extension IN ($srch_input)");
                array_push($searchString1, " extension IN ($srch_input_1)");

                if (!empty($request->input('did_numbers'))) {
                    $did_implode = implode(',', $request->input('did_numbers'));
                    $did_implode1 = implode(',', $request->input('did_numbers'));

                    array_push($searchString, " extension IN ($srch_input) OR cli IN ($did_implode)");
                    array_push($searchString1, " extension IN ($srch_input_1) OR cli IN ($did_implode1)");
                }
            } elseif ($request->auth->level < 5) {          #below manager
                $alt_extension_sql = "SELECT alt_extension FROM master.users WHERE extension = '" . $request->auth->extension . "' ";
                $alt_extension_result = DB::connection('mysql_' . $parent_id)->select($alt_extension_sql);
                $alt_extension = $alt_extension_result[0]->alt_extension;
                $extensionArray = array();
                array_push($extensionArray, $request->auth->extension);
                array_push($extensionArray, $alt_extension);
                foreach ($extensionArray as $key => $val) {
                    $user_data_extension[] = $val;
                }

                $srch_input_1 = implode(',', $user_data_extension);
                $srch_input = implode(',', $user_data_extension);
                array_push($searchString, " extension IN ($srch_input)");
                array_push($searchString1, " extension IN ($srch_input_1)");
            } else {

                #admin and above show cdr for all extensions
                if ($request->auth->level >= 7) {
                    $sql_extension = "SELECT extension,alt_extension FROM master.users WHERE parent_id = '" . $parent_id . "' order by  first_name asc";
                    //Log::info('reached extension',['sql_extension' => $sql_extension]);

                    if (empty($request->input('did_numbers'))) {
                        $sql_did = "SELECT cli FROM master.did WHERE parent_id = '" . $parent_id . "'";

                        $extensionDid = DB::connection('master')->select($sql_did);

                        foreach ($extensionDid as $key => $val) {
                            $user_data_did[] = $val->cli;
                        }

                        $did_implode = implode(',', $user_data_did);
                        $did_implode1 = implode(',', $user_data_did);

                        $extensionArray = DB::connection('master')->select($sql_extension);





                        $user_data_extension = ["'" . $request->auth->extension . "'"]; // Quote the auth extension
                        foreach ($extensionArray as $val) {
                            $user_data_extension[] = "'" . $val->extension . "'"; // Quote each extension value
                            $user_data_extension[] = "'" . $val->alt_extension . "'"; // Quote each alt_extension value
                        }
                        $srch_input_1 = implode(',', $user_data_extension);
                        $srch_input = implode(',', $user_data_extension);
                        if (empty($user_data_did)) {
                            /*array_push($searchString, " (extension IN ($srch_input))");
                    array_push($searchString1, " (extension IN ($srch_input_1))");*/
                        } else {

                            array_push($searchString, " (extension IN ($srch_input) OR cli IN ($did_implode))");
                            array_push($searchString1, " (extension IN ($srch_input_1) OR cli IN ($did_implode1))");
                        }
                    } else {
                        $did_implode = implode(',', $request->input('did_numbers'));
                        $did_implode1 = implode(',', $request->input('did_numbers'));


                        array_push($searchString, "  cli IN ($did_implode)");
                        array_push($searchString1, "  cli IN ($did_implode1)");
                    }
                } else {
                    $database = 'client_' . $parent_id;
                    $sql_extension = "SELECT extension,alt_extension FROM master.users WHERE extension IN (
                        SELECT extension FROM " . $database . ".extension_group_map WHERE is_deleted =0 and group_id IN (SELECT group_id FROM " . $database . ".extension_group_map WHERE is_deleted =0 and extension = " . $request->auth->extension . ")
                    ) AND user_level <= '" . $request->auth->level . "' ";

                    $extensionArray = DB::connection('master')->select($sql_extension);

                    $user_data_extension = ["'" . $request->auth->extension . "'"]; // Quote the auth extension
                    foreach ($extensionArray as $val) {
                        $user_data_extension[] = "'" . $val->extension . "'"; // Quote each extension value
                        $user_data_extension[] = "'" . $val->alt_extension . "'"; // Quote each alt_extension value
                    }

                    $srch_input_1 = implode(',', $user_data_extension);
                    $srch_input = implode(',', $user_data_extension);

                    array_push($searchString, " extension IN ($srch_input)");
                    array_push($searchString1, " extension IN ($srch_input_1)");
                }
            }

            if ($request->has('lower_limit') && $request->has('upper_limit') && is_numeric($request->input('lower_limit')) && is_numeric($request->input('upper_limit'))) {
                $search['lower_limit'] = $request->input('lower_limit');
                $search['upper_limit'] = $request->input('upper_limit');
                $limitString = "LIMIT :lower_limit , :upper_limit";
            }
            $filter = (!empty($searchString)) ? " WHERE " . implode(" AND ", $searchString) : '';
            $filter1 = (!empty($searchString1)) ? " WHERE " . implode(" AND ", $searchString1) : '';

            // $query_string = "SELECT SQL_CALC_FOUND_ROWS c.id,c.area_code, c.extension,c.cli, c.number, c.start_time, c.end_time, c.duration, c.route, c.call_recording,c.campaign_id, c.lead_id,c.type, c.disposition_id FROM
            //                 (
            //                     (SELECT id, extension,cli,area_code,number, start_time, end_time, duration, route, call_recording, campaign_id, lead_id, type, disposition_id FROM cdr $filter )
            //                     UNION
            //                     (SELECT id, extension,cli,area_code,number, start_time, end_time, duration, route, call_recording, campaign_id, lead_id, type, disposition_id FROM cdr_archive $filter1 )
            //                 ) as c ORDER BY start_time DESC ";

$query_string = "
    SELECT 
        SQL_CALC_FOUND_ROWS 
        c.id,
        c.extension,
        c.cli,
        c.number,
        c.area_code,
        acc.city_name As city,
        acc.state_name As state,
        c.start_time,
        c.end_time,
        c.duration,
        c.route,
        c.call_recording,
        c.campaign_id,
        camp.title As campaign_name,
        c.lead_id,
        c.type,
        c.disposition_id,
        disp.title AS dispostion_name
    FROM (
        (SELECT 
            id, extension, cli, area_code, number, start_time, end_time, duration, 
            route, call_recording, campaign_id, lead_id, type, disposition_id 
         FROM cdr $filter)
        UNION
        (SELECT 
            id, extension, cli, area_code, number, start_time, end_time, duration, 
            route, call_recording, campaign_id, lead_id, type, disposition_id 
         FROM cdr_archive $filter1)
    ) AS c
    LEFT JOIN master.areacode_city AS acc 
        ON acc.areacode = c.area_code
    LEFT JOIN client_{$parent_id}.campaign AS camp 
        ON camp.id = c.campaign_id
    LEFT JOIN client_{$parent_id}.disposition AS disp 
        ON disp.id = c.disposition_id
    ORDER BY c.start_time DESC
";


            $sql = $query_string . $limitString;

            $record = DB::connection('mysql_' . $parent_id)->select($sql, $search);
            $recordCount = DB::connection('mysql_' . $parent_id)->selectOne("SELECT FOUND_ROWS() as count");
            $recordCount = (array) $recordCount;

            if (!empty($record)) {
                $data = (array) $record;

                return array(
                    'success' => 'true',
                    'message' => 'Call Data Report.',
                    'record_count' => $recordCount['count'],
                    'data' => $data
                );
            } else {
                return array(
                    'success' => 'true',
                    'message' => 'No Call Data Report found.',
                    'record_count' => 0,
                    'data' => array()
                );
            }

            return array(
                'success' => 'false',
                'message' => 'Call Data Report doesn\'t exist.'
            );
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }

    public function loginHistoryold($request)
    {

        $user_data_did = array();
        try {

            $search = array();
            $searchString = array();
            $searchString1 = array();

            $limitString = '';
            if ($request->has('number') && !empty($request->input('number'))) {
                $search['number'] = $request->input('number');
                $search['number1'] = $request->input('number');

                //array_push($searchString, 'number = :number');
                //array_push($searchString1, 'number = :number1');
                array_push($searchString, "number like CONCAT(:number, '%')");
                array_push($searchString1, "number like CONCAT(:number1, '%')");
            }


            if (!empty($request->input('area_code')) && !empty($request->input('timezone_value'))) {
                $timezone_sql = "SELECT areacode FROM master.timezone WHERE timezone = '" . $request->input('timezone_value') . "' ";
                $timezone_sql_result = DB::connection('mysql_' . $request->auth->parent_id)->select($timezone_sql);
                foreach ($timezone_sql_result as $key => $val) {
                    $user_data_timezone[] = $val->areacode;
                }
                $areacode = $request->input('area_code');

                $merge = array_merge($user_data_timezone, $areacode);

                $area_code = implode(',', $merge);
                $area_code1 = implode(',', $merge);
                array_push($searchString, "area_code IN ($area_code)");
                array_push($searchString1, "area_code IN ($area_code1)");
            } else {


                if ($request->has('timezone_value') && !empty($request->input('timezone_value'))) {
                    $timezone_sql = "SELECT areacode FROM master.timezone WHERE timezone = '" . $request->input('timezone_value') . "' ";
                    $timezone_sql_result = DB::connection('mysql_' . $request->auth->parent_id)->select($timezone_sql);
                    foreach ($timezone_sql_result as $key => $val) {
                        $user_data_timezone[] = $val->areacode;
                    }

                    $srch_input_1 = implode(',', $user_data_timezone);
                    $srch_input = implode(',', $user_data_timezone);
                    array_push($searchString, " area_code IN ($srch_input)");
                    array_push($searchString1, " area_code IN ($srch_input_1)");
                }

                if (!empty($request->input('area_code'))) {
                    $area_code = implode(',', $request->input('area_code'));
                    $area_code1 = implode(',', $request->input('area_code'));
                    array_push($searchString, "area_code IN ($area_code)");
                    array_push($searchString1, "area_code IN ($area_code1)");
                }
            }









            if ($request->has('campaign') && !empty($request->input('campaign'))) {
                $search['campaign_id'] = $request->input('campaign');
                $search['campaign_id1'] = $request->input('campaign');

                array_push($searchString, 'campaign_id = :campaign_id');
                array_push($searchString1, 'campaign_id = :campaign_id1');
            }

            if ($request->has('route') && !empty($request->input('route'))) {
                $search['route'] = $request->input('route');
                $search['route1'] = $request->input('route');

                array_push($searchString, 'route = :route');
                array_push($searchString1, 'route = :route1');
            }

            if ($request->has('disposition') && !empty($request->input('disposition'))) {
                $search['disposition_id'] = $request->input('disposition');
                $search['disposition_id1'] = $request->input('disposition');

                array_push($searchString, 'disposition_id = :disposition_id');
                array_push($searchString1, 'disposition_id = :disposition_id1');
            }

            if ($request->has('type') && !empty($request->input('type'))) {
                $search['type'] = $request->input('type');
                $search['type1'] = $request->input('type');

                array_push($searchString, 'type = :type');
                array_push($searchString1, 'type = :type1');
            }

            if ($request->has('start_date') && $request->has('end_date') && !empty($request->input('start_date')) && !empty($request->input('end_date'))) {
                $start = date('Y-m-d', strtotime($request->input('start_date'))) . " 00:00:00";
                $end = date('Y-m-d', strtotime($request->input('end_date'))) . " 23:59:59";
                $search['start_time'] = $start;
                $search['end_time'] = $end;

                // $search['start_time1'] = $start;
                // $search['end_time1'] = $end;



                if (!empty($request->auth->timezone)) {
                    $timeZoneService = new TimezoneService();
                    $timezoneValue = $timeZoneService->findTimezoneValue($request->auth->timezone);
                    array_push($searchString, 'CONVERT_TZ(login_logs.created_at,"+00:00", "' . $timezoneValue . '") BETWEEN :start_time AND :end_time');
                    array_push($searchString1, 'CONVERT_TZ(login_logs.created_at,"+00:00", "' . $timezoneValue . '") BETWEEN :start_time1 AND :end_time1');
                } else {
                    array_push($searchString, 'start_time BETWEEN :start_time AND :end_time');
                    array_push($searchString1, 'start_time BETWEEN :start_time1 AND :end_time1');
                }
            }

            if ($request->has('extension') && !empty($request->input('extension'))) {
                $alt_extension_sql = "SELECT alt_extension FROM master.users WHERE extension = '" . $request->input('extension') . "' ";
                $alt_extension_result = DB::connection('mysql_' . $request->auth->parent_id)->select($alt_extension_sql);
                $alt_extension = $alt_extension_result[0]->alt_extension;
                $extensionArray = array();
                array_push($extensionArray, $request->input('extension'));
                array_push($extensionArray, $alt_extension);
                foreach ($extensionArray as $key => $val) {
                    $user_data_extension[] = $val;
                }

                $srch_input_1 = implode(',', $user_data_extension);
                $srch_input = implode(',', $user_data_extension);
                array_push($searchString, " extension IN ($srch_input)");
                array_push($searchString1, " extension IN ($srch_input_1)");

                if (!empty($request->input('did_numbers'))) {
                    $did_implode = implode(',', $request->input('did_numbers'));
                    $did_implode1 = implode(',', $request->input('did_numbers'));

                    array_push($searchString, " extension IN ($srch_input) OR cli IN ($did_implode)");
                    array_push($searchString1, " extension IN ($srch_input_1) OR cli IN ($did_implode1)");
                }
            } elseif ($request->auth->level < 5) {          #below manager
                $alt_extension_sql = "SELECT alt_extension FROM master.users WHERE extension = '" . $request->auth->extension . "' ";
                $alt_extension_result = DB::connection('mysql_' . $request->auth->parent_id)->select($alt_extension_sql);
                $alt_extension = $alt_extension_result[0]->alt_extension;
                $extensionArray = array();
                array_push($extensionArray, $request->auth->extension);
                array_push($extensionArray, $alt_extension);
                foreach ($extensionArray as $key => $val) {
                    $user_data_extension[] = $val;
                }

                $srch_input_1 = implode(',', $user_data_extension);
                $srch_input = implode(',', $user_data_extension);
                array_push($searchString, " extension IN ($srch_input)");
                array_push($searchString1, " extension IN ($srch_input_1)");
            }
            /*else
            {

            #admin and above show cdr for all extensions
                if ($request->level >= 7)
                {
                    $sql_extension = "SELECT extension,alt_extension FROM master.users WHERE parent_id = '" . $request->auth->parent_id . "' order by  first_name asc";

                    if(empty($request->input('did_numbers')))
                    {
                    $sql_did = "SELECT cli FROM master.did WHERE parent_id = '" . $request->auth->parent_id . "'";

                    $extensionDid = DB::connection('mysql_' . $request->auth->parent_id)->select($sql_did);

                    foreach ($extensionDid as $key => $val)
                    {
                        $user_data_did[] = $val->cli;
                    }

                    $did_implode = implode(',',$user_data_did);
                    $did_implode1 = implode(',',$user_data_did);

                    $extensionArray = DB::connection('mysql_' . $request->auth->parent_id)->select($sql_extension);

                    $user_data_extension = [$request->auth->extension];
                    foreach ($extensionArray as $key => $val)
                    {
                        $user_data_extension[] = $val->extension;
                        $user_data_extension[] = $val->alt_extension;
                    }
                    $srch_input_1 = implode(',', $user_data_extension);
                    $srch_input = implode(',', $user_data_extension);

                    if(empty($user_data_did))
                    {
                        //array_push($searchString, " (extension IN ($srch_input))");
                        //array_push($searchString1, " (extension IN ($srch_input_1))");
                    }

                    else
                    {


                    array_push($searchString, " (extension IN ($srch_input) OR cli IN ($did_implode))");
                    array_push($searchString1, " (extension IN ($srch_input_1) OR cli IN ($did_implode1))");
                    }
                }
                else
                {
                    $did_implode = implode(',', $request->input('did_numbers'));
                    $did_implode1 = implode(',', $request->input('did_numbers'));
                    

                     array_push($searchString, "  cli IN ($did_implode)");
                    array_push($searchString1, "  cli IN ($did_implode1)");
                }
                   
                }
                else
                {
                    $database = 'client_' . $request->auth->parent_id;
                    $sql_extension = "SELECT extension,alt_extension FROM master.users WHERE extension IN (
                        SELECT extension FROM " . $database . ".extension_group_map WHERE is_deleted =0 and group_id IN (SELECT group_id FROM " . $database . ".extension_group_map WHERE is_deleted =0 and extension = " . $request->auth->extension . ")
                    ) AND user_level <= '" . $request->auth->level . "' ";
                    
                    $extensionArray = DB::connection('mysql_' . $request->auth->parent_id)->select($sql_extension);

                    $user_data_extension = [$request->auth->extension];
                    foreach ($extensionArray as $key => $val)
                    {
                        $user_data_extension[] = $val->extension;
                        $user_data_extension[] = $val->alt_extension;
                    }

                    $srch_input_1 = implode(',', $user_data_extension);
                    $srch_input = implode(',', $user_data_extension);
                    
                    array_push($searchString, " extension IN ($srch_input)");
                    array_push($searchString1, " extension IN ($srch_input_1)");
                }
            }*/

            if ($request->has('lower_limit') && $request->has('upper_limit') && is_numeric($request->input('lower_limit')) && is_numeric($request->input('upper_limit'))) {
                $search['lower_limit'] = $request->input('lower_limit');
                $search['upper_limit'] = $request->input('upper_limit');
                $limitString = "LIMIT :lower_limit , :upper_limit";
            }
            $filter = (!empty($searchString)) ? " WHERE " . implode(" AND ", $searchString) : '';
            //$filter1 = (!empty($searchString1)) ? " WHERE " . implode(" AND ", $searchString1) : '';

            $query_string = "
                                SELECT SQL_CALC_FOUND_ROWS login_logs.created_at,login_logs.ip,login_logs.user_agent,users.first_name,users.last_name FROM login_logs inner join users on users.id=login_logs.user_id $filter and client_id='" . $request->auth->parent_id . "'
                                ORDER BY login_logs.created_at DESC ";

            $sql = $query_string . $limitString;

            //return $search;

            $record = DB::connection('master')->select($sql, $search);
            $recordCount = DB::connection('master')->selectOne("SELECT FOUND_ROWS() as count");
            $recordCount = (array) $recordCount;

            if (!empty($record)) {
                $data = (array) $record;

                return array(
                    'success' => 'true',
                    'message' => 'Call Data Report.',
                    'record_count' => $recordCount['count'],
                    'data' => $data
                );
            } else {
                return array(
                    'success' => 'true',
                    'message' => 'No Call Data Report found.',
                    'record_count' => 0,
                    'data' => array()
                );
            }

            return array(
                'success' => 'false',
                'message' => 'Call Data Report doesn\'t exist.'
            );
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }
public function loginHistory($request)
{
    try {
        $search = [];
        $searchString = [];
        $limitString = '';

        // Filter by IP
        if ($request->has('ip') && !empty($request->input('ip'))) {
            $search['ip'] = $request->input('ip');
            $searchString[] = 'login_logs.ip = :ip';
        }

        // Filter by Extension
        if ($request->has('extension') && !empty($request->input('extension'))) {
            $search['extension'] = $request->input('extension');
            $searchString[] = 'users.extension = :extension';
        }
              // ✅ Filter by User ID
        if ($request->has('user_id') && !empty($request->input('user_id'))) {
            $search['user_id'] = $request->input('user_id');
            $searchString[] = 'login_logs.user_id = :user_id';
        }

        // Filter by Date Range
        if ($request->has('start_date') && $request->has('end_date') &&
            !empty($request->input('start_date')) && !empty($request->input('end_date'))) {

            $start = date('Y-m-d', strtotime($request->input('start_date'))) . " 00:00:00";
            $end = date('Y-m-d', strtotime($request->input('end_date'))) . " 23:59:59";
            $search['start_time'] = $start;
            $search['end_time'] = $end;

            // If timezone is present, convert it
            if (!empty($request->auth->timezone)) {
                $timeZoneService = new TimezoneService();
                $timezoneValue = $timeZoneService->findTimezoneValue($request->auth->timezone);
                $searchString[] = 'CONVERT_TZ(login_logs.created_at,"+00:00", "' . $timezoneValue . '") BETWEEN :start_time AND :end_time';
            } else {
                $searchString[] = 'login_logs.created_at BETWEEN :start_time AND :end_time';
            }
        }

        // Pagination (optional)
        if ($request->has('lower_limit') && $request->has('upper_limit') &&
            is_numeric($request->input('lower_limit')) && is_numeric($request->input('upper_limit'))) {
            $search['lower_limit'] = $request->input('lower_limit');
            $search['upper_limit'] = $request->input('upper_limit');
            $limitString = "LIMIT :lower_limit , :upper_limit";
        }

        $filter = (!empty($searchString)) ? " WHERE " . implode(" AND ", $searchString) : '';

        $query_string = "
            SELECT SQL_CALC_FOUND_ROWS 
                login_logs.created_at,
                login_logs.ip,
                login_logs.user_agent,
                users.first_name,
                users.last_name,
                users.extension
            FROM login_logs
            INNER JOIN users ON users.id = login_logs.user_id
            $filter AND login_logs.client_id = :client_id
            ORDER BY login_logs.created_at DESC
        ";

        // Always add client_id to the query
        $search['client_id'] = $request->auth->parent_id;

        $sql = $query_string . ' ' . $limitString;

        $record = DB::connection('master')->select($sql, $search);
        $recordCount = DB::connection('master')->selectOne("SELECT FOUND_ROWS() as count");
        $recordCount = (array) $recordCount;

        if (!empty($record)) {
            return [
                'success' => 'true',
                'message' => 'Login history fetched successfully.',
                'record_count' => $recordCount['count'],
                'data' => $record
            ];
        } else {
            return [
                'success' => 'true',
                'message' => 'No login history found.',
                'record_count' => 0,
                'data' => []
            ];
        }
    } catch (\Exception $e) {
        \Log::error("Login history error: " . $e->getMessage());
        return [
            'success' => 'false',
            'message' => 'Something went wrong while fetching login history.'
        ];
    }
}

    /*
     * Fetch Live calls from user id
     * @param integer $id
     * @return array
     */


    public function getLiveCallActivity($request)
    {
        $serach = " where extension = " . $request->extension . "";
        //replace now() to UTC_TIMESTAMP()
        $record = DB::connection('mysql_' . $request->parent_id)->select("SELECT *,TIMEDIFF(start_time, UTC_TIMESTAMP()) as duration from line_detail" . $serach);
        $data = (array) $record;
        if (count($data) > 0) {
            return array(
                'success' => 'true',
                'message' => 'Live Calls.',
                'data' => $data
            );
        } else {
            return array(
                'success' => 'false',
                'message' => 'No Live Calls found.',
                'data' => array()
            );
        }
    }

  
public function getLiveCall($request)
{
    try {
        $search = '';
        $limitString = '';

        // 🔹 Handle user-level filtering
        if ($request->auth->level < 7) {
            $extensionGroup = ExtensionGroupMap::on('mysql_' . $request->auth->parent_id)
                ->select('group_id')
                ->where([
                    ["extension", "=", $request->auth->extension],
                    ["is_deleted", "=", 0]
                ])
                ->get()
                ->toArray();

            if (count($extensionGroup) > 0) {
                $group = array_column($extensionGroup, 'group_id');

                $extensionArray = ExtensionGroupMap::on('mysql_' . $request->auth->parent_id)
                    ->select('extension')
                    ->where([["is_deleted", "=", 0]])
                    ->whereIn('group_id', $group)
                    ->get()
                    ->toArray();

                if (count($extensionArray) > 0) {
                    $ext_array = array_column($extensionArray, 'extension');
                    $ext_data = implode(',', $ext_array);
                    $search = " WHERE extension IN (" . $ext_data . ") ";
                }
            }
        }

        // 🔹 Apply pagination
        if (
            $request->has('start') &&
            $request->has('limit') &&
            is_numeric($request->input('start')) &&
            is_numeric($request->input('limit'))
        ) {
            $limitString = " LIMIT " . intval($request->input('start')) . ", " . intval($request->input('limit'));
        }

        // 🔹 Main SQL with total count
        $sql = "SELECT SQL_CALC_FOUND_ROWS *,
                       TIMEDIFF(start_time, UTC_TIMESTAMP()) AS duration
                FROM line_detail
                " . $search . "
                ORDER BY start_time DESC
                " . $limitString;

        $connection = DB::connection('mysql_' . $request->auth->parent_id);

        // 🔹 Fetch paginated data
        $records = $connection->select($sql);

        // 🔹 Fetch total rows (ignores LIMIT)
        $totalRows = $connection->select("SELECT FOUND_ROWS() AS total_count");
        $totalCount = $totalRows[0]->total_count ?? 0;

        // 🔹 Prepare response
        if (count($records) > 0) {
            return [
                'success' => 'true',
                'message' => 'Live Calls.',
                'total_rows' => $totalCount,
                'data' => $records
            ];
        } else {
            return [
                'success' => 'true',
                'message' => 'No Live Calls found.',
                'total_rows' => 0,
                'data' => []
            ];
        }
    } catch (Exception $e) {
        Log::error('Live Call Error: ' . $e->getMessage());
        return [
            'success' => 'false',
            'message' => 'An error occurred while fetching live calls.'
        ];
    }
}



    /*
     * Fetch Call transfer Detail
     * @param integer $id
     * @return array
     */

    public function getTransferReportold($request)
    {
        try {
            //$id = $request->input('id');
            //if (!empty($id) && is_numeric($id)) {
                $search = array();
                $searchString = array();
                $limitString = '';
                if ($request->auth->role == 2) {
                    $search['extension'] = $request->auth->extension;
                    array_push($searchString, 'extension = :extension');
                } elseif ($request->has('extension') && !empty($request->input('extension'))) {
                    $search['extension'] = $request->input('extension');
                    array_push($searchString, 'extension = :extension');
                }
                if ($request->has('number') && !empty($request->input('number'))) {
                    $search['number'] = $request->input('number');
                    array_push($searchString, 'number = :number');
                }
                if ($request->has('campaign') && !empty($request->input('campaign'))) {
                    $search['campaign_id'] = $request->input('campaign');
                    array_push($searchString, 'campaign_id = :campaign_id');
                }
                if ($request->has('transfer_status_id') && !empty($request->input('transfer_status_id'))) {
                    $search['transfer_status_id'] = $request->input('transfer_status_id');
                    array_push($searchString, 'transfer_status_id = :transfer_status_id');
                }
                if ($request->has('start_date') && $request->has('end_date') && !empty($request->input('start_date')) && !empty($request->input('end_date'))) {
                    $start = date('Y-m-d', strtotime($request->input('start_date'))) . " 00:00:00";
                    $end = date('Y-m-d', strtotime($request->input('end_date'))) . " 23:59:59";
                    $search['start_time'] = $start;
                    $search['end_time'] = $end;
                    array_push($searchString, 'start_time BETWEEN :start_time AND :end_time');
                }

                if ($request->has('lower_limit') && $request->has('upper_limit') && is_numeric($request->input('lower_limit')) && is_numeric($request->input('upper_limit'))) {
                    $search['lower_limit'] = $request->input('lower_limit');
                    $search['upper_limit'] = $request->input('upper_limit');
                    $limitString = "LIMIT :lower_limit , :upper_limit";
                }
                $filter = (!empty($searchString)) ? " WHERE " . implode(" AND ", $searchString) : '';
                $sql = "SELECT
                          SQL_CALC_FOUND_ROWS t.id,  t.extension, t.number, t.start_time, t.transfer_extension,  t.call_recording, t.call_recording_transfer, c.title as campaign, ts.title as status
                        FROM
                          transfer_log as t
                        LEFT JOIN campaign as c ON t.campaign_id = c.id
                        LEFT JOIN transfer_status as ts ON t.transfer_status_id = ts.id"
                    . $filter . " ORDER BY start_time DESC " . $limitString;
                $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql, $search);
                $recordCount = DB::connection('mysql_' . $request->auth->parent_id)->selectOne("SELECT FOUND_ROWS() as count");
                $recordCount = (array) $recordCount;
                if (!empty($record)) {
                    $data = (array) $record;
                    return array(
                        'success' => 'true',
                        'message' => 'Transfer Report.',
                        'record_count' => $recordCount['count'],
                        'data' => $data
                    );
                } else {
                    return array(
                        'success' => 'true',
                        'message' => 'No record found.',
                        'record_count' => 0,
                        'data' => array()
                    );
                }
            //}
            return array(
                'success' => 'false',
                'message' => 'Transfer Report doesn\'t exist.'
            );
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }
public function getTransferReport($request)
{
    try {
        $search = [];
        $searchString = [];
        $limitString = '';

        // Extension filter
        // if ($request->auth->role == 2) {
        //     $search['extension'] = $request->auth->extension;
        //     $searchString[] = 't.extension = :extension';
        // }
        //  else
            if ($request->has('extension') && !empty($request->input('extension'))) {
            $search['extension'] = $request->input('extension');
            $searchString[] = 't.extension = :extension';
        }

        // Number filter
        if ($request->has('number') && !empty($request->input('number'))) {
            $search['number'] = $request->input('number');
            $searchString[] = 't.number = :number';
        }

        // Campaign filter
        if ($request->has('campaign') && !empty($request->input('campaign'))) {
            $search['campaign_id'] = $request->input('campaign');
            $searchString[] = 't.campaign_id = :campaign_id';
        }

        // Transfer status filter (corrected)
        if ($request->has('transfer_status_id') && !empty($request->input('transfer_status_id'))) {
            $search['transfer_status_id'] = $request->input('transfer_status_id');
            $searchString[] = 't.transfer_status_id = :transfer_status_id';
        }

        // Date range filter
        if ($request->has('start_date') && $request->has('end_date') &&
            !empty($request->input('start_date')) && !empty($request->input('end_date'))) {
            $start = date('Y-m-d', strtotime($request->input('start_date'))) . " 00:00:00";
            $end = date('Y-m-d', strtotime($request->input('end_date'))) . " 23:59:59";
            $search['start_time'] = $start;
            $search['end_time'] = $end;
            $searchString[] = 't.start_time BETWEEN :start_time AND :end_time';
        }

        // Pagination (fixed LIMIT)
        if ($request->has('lower_limit') && $request->has('upper_limit') &&
            is_numeric($request->input('lower_limit')) && is_numeric($request->input('upper_limit'))) {
            $lower = (int) $request->input('lower_limit');
            $upper = (int) $request->input('upper_limit');
            $limitString = "LIMIT $lower, $upper";
        }

        // Build WHERE clause
        $filter = !empty($searchString) ? ' WHERE ' . implode(' AND ', $searchString) : '';

        $sql = "SELECT
                    SQL_CALC_FOUND_ROWS
                    t.id,
                    t.extension,
                    t.number,
                    t.start_time,
                    t.transfer_extension,
                    t.call_recording,
                    t.call_recording_transfer,
                    c.title AS campaign,
                    ts.title AS status
                FROM transfer_log AS t
                LEFT JOIN campaign AS c ON t.campaign_id = c.id
                LEFT JOIN transfer_status AS ts ON t.transfer_status_id = ts.id
                $filter
                ORDER BY t.start_time DESC
                $limitString";
Log::info('Transfer Report SQL:', [
    'sql' => $sql,
    'params' => $search
]);

        $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql, $search);
        $recordCount = DB::connection('mysql_' . $request->auth->parent_id)->selectOne("SELECT FOUND_ROWS() AS count");
        $recordCount = (array) $recordCount;

        if (!empty($record)) {
            return [
                'success' => 'true',
                'message' => 'Transfer Report.',
                'record_count' => $recordCount['count'],
                'data' => $record
            ];
        }

        return [
            'success' => 'true',
            'message' => 'No record found.',
            'record_count' => 0,
            'data' => []
        ];

    } catch (Exception $e) {
        Log::error($e->getMessage());
        return ['success' => 'false', 'message' => 'Error: ' . $e->getMessage()];
    }
}

    public function getExtensionByGroup($request)
    {
        $database = 'client_' . $request->auth->parent_id;

        if ($request->auth->level >= 7) {
            $sql_extension = "SELECT * FROM master.users WHERE parent_id = '" . $request->auth->parent_id . "' order by  first_name asc";
        } else {
            $sql_extension = "SELECT * FROM master.users WHERE (extension='" . $request->auth->extension . "' OR extension IN (
                SELECT extension FROM " . $database . ".extension_group_map WHERE is_deleted=0 AND group_id IN (SELECT group_id FROM " . $database . ".extension_group_map WHERE is_deleted=0 AND extension = " . $request->auth->extension . ")
            )) AND user_level <= '" . $request->auth->level . "' order by  first_name asc";
        }
        $extensionListData = DB::connection('mysql_' . $request->auth->parent_id)->select($sql_extension);

        return $extensionListData;
    }
    /**
     * Get only active extension of cients
     * Used for CC 77
     * @param type $request
     * @return type
     */
    public function getActiveExtensionByGroupold($request)
    {
        $sql_extension = "SELECT * FROM master.users WHERE "
            . "parent_id = '" . $request->auth->parent_id . "' AND is_deleted = 0 order by  first_name asc";
        $extensionListData = DB::connection('mysql_' . $request->auth->parent_id)->select($sql_extension);

        return $extensionListData;
    }

     public function getActiveExtensionByGroup($request)
    {
        $sql_extension = "SELECT * FROM users WHERE "
            . "parent_id = '" . $request->auth->parent_id . "' AND is_deleted = 0 order by  first_name asc";
        // $extensionListData = DB::connection('mysql_' . $request->auth->parent_id)->select($sql_extension);
         $extensionListData = DB::connection('master')->select($sql_extension);
 
        return $extensionListData;
    }

    /**
     * Search CDR
     * @param type $request
     * @return type
     */

    // function getCDR_copy($request)
    // {
    //     try {



    //         $deleted = DB::connection("master")->statement("DELETE FROM inbound_call_popup WHERE inbound_number='" . $request->input('phone_number') . "' and (extension='" . $request->extension . "' OR extension = '" . $request->alt_extension . "')");

    //         //DB::connection('master')->statement("UPDATE inbound_call_popup SET status='0' WHERE inbound_number='".$request->input('phone_number')."' and (extension='".$request->extension."' OR extension = '".$request->alt_extension."')"); 
    //         // for status change for inbound calls
    //         $lead_id = 0;
    //         $dataCDR = $dataCDRA = $uniqueExt = $uniqueUid = $userData = $smsData = $faxData = array();
    //         $numLen = strlen($request->input('phone_number'));
    //         $number1 = $request->input('phone_number');
    //         if ($numLen > 10) {
    //             $number2 = substr($request->input('phone_number'), ($numLen - 10));
    //         } else {
    //             $number2 = "1" . $request->input('phone_number');
    //         }

    //         $sql = "(SELECT *, SEC_TO_TIME(duration) AS duration_in_time_format, 'cdr' AS platform FROM cdr WHERE (number = " . $number1 . " || number = " . $number2 . ") ORDER BY start_time DESC) ";
    //         $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql);
    //         $dataCDR = (array) $record;
    //         if (!empty($record)) {
    //             foreach ($dataCDR as $d) {
    //                 if (!in_array($d->extension, $uniqueExt)) //get extension

    //                     if (!empty($d->extension))
    //                         $uniqueExt[] = $d->extension;

    //                 if ($d->lead_id != null && $lead_id == 0) { //get Lead Id
    //                     $lead_id = $d->lead_id;
    //                 }
    //             }
    //         }


    //         $sql = "(SELECT *, SEC_TO_TIME(duration) AS duration_in_time_format, 'cdr' AS platform FROM cdr_archive WHERE (number = " . $number1 . " || number = " . $number2 . ") ORDER BY start_time DESC) ";
    //         $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql);
    //         $dataCDRA = (array) $record;

    //         foreach ($dataCDRA as $d) {
    //             if (!in_array($d->extension, $uniqueExt)) //get extension
    //                 if (!empty($d->extension))
    //                     $uniqueExt[] = $d->extension;

    //             if ($d->lead_id != null && $lead_id == 0) { //get Lead Id
    //                 $lead_id = $d->lead_id;
    //             }
    //         }


    //         //$leadData = $this->getLeadData($lead_id, $request->auth->parent_id);
    //         $leadData = $this->getLeadData_copy($lead_id, $request->auth->parent_id);

    //         if (count($uniqueExt) > 0) { //get fax, sms and user info on extension
    //             $userData = $this->getUserInfoOnExt($uniqueExt);
    //             foreach ($userData as $u) {
    //                 if (!in_array($u->id, $uniqueUid))
    //                     $uniqueUid[] = $u->id;
    //             }

    //             $userData = $this->getUserInfoOnAltExt($uniqueExt);
    //             foreach ($userData as $u) {
    //                 if (!in_array($u->id, $uniqueUid))
    //                     $uniqueUid[] = $u->id;
    //             }

    //             //return $uniqueUid;
    //             $smsData = $this->getSmsLogOnExt($uniqueUid, $number1, $number2, $request->auth->parent_id);
    //             $faxData = $this->getFaxLogOnExt($uniqueExt, $number1, $number2, $request->auth->parent_id);
    //         }
    //         $comments = $this->getCommentsLogOnExt($uniqueExt, $lead_id, $request->auth->parent_id);

    //         //sort(on date time) and merge cdr, cdr archive, fax ,sms array
    //         $arr = array_merge($dataCDR, $dataCDRA);
    //         usort($arr, array($this, "sortResultOntimeDesc"));
    //         $arr = array_merge($arr, $faxData);
    //         usort($arr, array($this, "sortResultOntimeDesc"));
    //         $arr = array_merge($arr, $smsData);
    //         usort($arr, array($this, "sortResultOntimeDesc"));

    //         $arr = array_merge($arr, $comments);
    //         usort($arr, array($this, "sortResultOntimeDesc"));

    //         return ['leadData' => $leadData, 'updateData' => $arr, 'userData' => $userData];
    //     } catch (Exception $e) {
    //         Log::log($e->getMessage());
    //     } catch (InvalidArgumentException $e) {
    //         Log::log($e->getMessage());
    //     }
    // }

    function getCDR($request)
    {
                    Log::info('cdr Reached funtion',[$request->lead_id]);

        try {



            $deleted = DB::connection("master")->statement("DELETE FROM inbound_call_popup WHERE inbound_number='" . $request->input('phone_number') . "' and (extension='" . $request->extension . "' OR extension = '" . $request->alt_extension . "')");

            //DB::connection('master')->statement("UPDATE inbound_call_popup SET status='0' WHERE inbound_number='".$request->input('phone_number')."' and (extension='".$request->extension."' OR extension = '".$request->alt_extension."')"); 
            // for status change for inbound calls
            $lead_id = 0;
            $dataCDR = $dataCDRA = $uniqueExt = $uniqueUid = $userData = $smsData = $faxData = array();
            $numLen = strlen($request->input('phone_number'));
            $number1 = $request->input('phone_number');
            if ($numLen > 10) {
                $number2 = substr($request->input('phone_number'), ($numLen - 10));
            } else {
                $number2 = "1" . $request->input('phone_number');
            }

            $sql = "(SELECT *, SEC_TO_TIME(duration) AS duration_in_time_format, 'cdr' AS platform FROM cdr WHERE (number = " . $number1 . " || number = " . $number2 . ") ORDER BY start_time DESC) ";
            $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql);
            $dataCDR = (array) $record;
            Log::info('cdr Reached',['dataCDR'=>$dataCDR]);
            if (!empty($record)) {
                foreach ($dataCDR as $d) {
                    if (!in_array($d->extension, $uniqueExt)) //get extension

                        if (!empty($d->extension))
                            $uniqueExt[] = $d->extension;

                    if ($d->lead_id != null && $lead_id == 0) { //get Lead Id
                        $lead_id = $d->lead_id;
                    }
                }
            }
if ($lead_id == 0 && empty($dataCDR)) {

    // lead_id provided directly in request
    if ($request->input('lead_id')) {
        $lead_id = $request->input('lead_id');
    }

    // list_id also provided directly in request (optional but useful)
    if ($request->input('list_id')) {
        $list_id = $request->input('list_id');
    }
}

            $sql = "(SELECT *, SEC_TO_TIME(duration) AS duration_in_time_format, 'cdr' AS platform FROM cdr_archive WHERE (number = " . $number1 . " || number = " . $number2 . ") ORDER BY start_time DESC) ";
            $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql);
            $dataCDRA = (array) $record;

            foreach ($dataCDRA as $d) {
                if (!in_array($d->extension, $uniqueExt)) //get extension
                    if (!empty($d->extension))
                        $uniqueExt[] = $d->extension;

                if ($d->lead_id != null && $lead_id == 0) { //get Lead Id
                    $lead_id = $d->lead_id;
                }
            }


            $leadData = $this->getLeadData($lead_id, $request->auth->parent_id);
            if (count($uniqueExt) > 0) { //get fax, sms and user info on extension
                $userData = $this->getUserInfoOnExt($uniqueExt);
                foreach ($userData as $u) {
                    if (!in_array($u->id, $uniqueUid))
                        $uniqueUid[] = $u->id;
                }

                $userData = $this->getUserInfoOnAltExt($uniqueExt);
                foreach ($userData as $u) {
                    if (!in_array($u->id, $uniqueUid))
                        $uniqueUid[] = $u->id;
                }

                //return $uniqueUid;
                $smsData = $this->getSmsLogOnExt($uniqueUid, $number1, $number2, $request->auth->parent_id);
                $faxData = $this->getFaxLogOnExt($uniqueExt, $number1, $number2, $request->auth->parent_id);
            }
            $comments = $this->getCommentsLogOnExt($uniqueExt, $lead_id, $request->auth->parent_id);

            //sort(on date time) and merge cdr, cdr archive, fax ,sms array
            $arr = array_merge($dataCDR, $dataCDRA);
            usort($arr, array($this, "sortResultOntimeDesc"));
            $arr = array_merge($arr, $faxData);
            usort($arr, array($this, "sortResultOntimeDesc"));
            $arr = array_merge($arr, $smsData);
            usort($arr, array($this, "sortResultOntimeDesc"));

            $arr = array_merge($arr, $comments);
            usort($arr, array($this, "sortResultOntimeDesc"));
            return [
                'list' => array_values($leadData),
                'updateData' => $arr,
                'userData' => $userData
            ];
            
            // return ['list' => $leadData, 'updateData' => $arr, 'userData' => $userData];
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }
function getCDR_copy($request)
{
    try {
            Log::info('cdr Reached',['lead_id'=>$request->input('lead_id')]);

        $phone = $request->input('phone_number');
        $ext = $request->extension;
        $altExt = $request->alt_extension;

        // Delete popup rows
        DB::connection("master")->statement(
            "DELETE FROM inbound_call_popup 
             WHERE inbound_number = ? 
               AND (extension = ? OR extension = ?)",
            [$phone, $ext, $altExt]
        );

        $lead_id = 0;
        $uniqueExt = [];
        $uniqueUid = [];

        // Normalize number
        $numLen = strlen($phone);
        $number1 = $phone;
        $number2 = ($numLen > 10) ? substr($phone, ($numLen - 10)) : "1" . $phone;

        $parent = $request->auth->parent_id;
        $db = DB::connection('mysql_' . $parent);

        // --- CDR LIVE ---
        $sql = "SELECT *, SEC_TO_TIME(duration) AS duration_in_time_format,
                       'cdr' AS platform
                FROM cdr 
                WHERE number = ? OR number = ?
                ORDER BY start_time DESC";

        $dataCDR = $db->select($sql, [$number1, $number2]);
            Log::info('cdr Reached',['dataCDR'=>$dataCDR]);


        foreach ($dataCDR as $d) {
            if (!empty($d->extension) && !in_array($d->extension, $uniqueExt)) {
                $uniqueExt[] = $d->extension;
            }

            if (!empty($d->lead_id) && $lead_id == 0) {
                $lead_id = $d->lead_id;
            }
        }


        // --- CDR ARCHIVE ---
        $sql = "SELECT *, SEC_TO_TIME(duration) AS duration_in_time_format,
                       'cdr' AS platform
                FROM cdr_archive 
                WHERE number = ? OR number = ?
                ORDER BY start_time DESC";

        $dataCDRA = $db->select($sql, [$number1, $number2]);

        foreach ($dataCDRA as $d) {
            if (!empty($d->extension) && !in_array($d->extension, $uniqueExt)) {
                $uniqueExt[] = $d->extension;
            }

            if (!empty($d->lead_id) && $lead_id == 0) {
                $lead_id = $d->lead_id;
            }
        }
if (empty($dataCDR)) {

    // lead_id provided directly in request
    if ($request->lead_id) {
        $lead_id = $request->lead_id;
    }

    // If extension passed in request
    if ($request->extension) {
        $uniqueExt = [$request->extension];
    }
}
        // --- LEAD DATA ---
        $leadData = [];
        if ($lead_id > 0) {
            $leadData = $this->getLeadData_copy($lead_id, $parent);
        }

        // --- USER / SMS / FAX / COMMENTS ---
        $smsData = $faxData = $userData = [];

        if (count($uniqueExt) > 0) {

            $userData = $this->getUserInfoOnExt($uniqueExt);

            foreach ($userData as $u) {
                if (!in_array($u->id, $uniqueUid)) {
                    $uniqueUid[] = $u->id;
                }
            }

            // ALT EXT USER INFO
            $userDataAlt = $this->getUserInfoOnAltExt($uniqueExt);
            foreach ($userDataAlt as $u) {
                if (!in_array($u->id, $uniqueUid)) {
                    $uniqueUid[] = $u->id;
                }
            }

            // SMS, Fax
            $smsData = $this->getSmsLogOnExt($uniqueUid, $number1, $number2, $parent);
            $faxData = $this->getFaxLogOnExt($uniqueExt, $number1, $number2, $parent);
        }

        $comments = $this->getCommentsLogOnExt($uniqueExt, $lead_id, $parent);

        // --- MERGE ALL DATA ---
        $arr = array_merge($dataCDR, $dataCDRA);
        usort($arr, array($this, "sortResultOntimeDesc"));

        $arr = array_merge($arr, $faxData);
        usort($arr, array($this, "sortResultOntimeDesc"));

        $arr = array_merge($arr, $smsData);
        usort($arr, array($this, "sortResultOntimeDesc"));

        $arr = array_merge($arr, $comments);
        usort($arr, array($this, "sortResultOntimeDesc"));

        return [
            'leadData' => array_values($leadData),
            'updateData' => $arr,
            'userData' => $userData
        ];

    } catch (Exception $e) {
        Log::error("CDR ERROR: " . $e->getMessage());
    }
}

    /**
     * Get Lead data and header 
     * @param type $lead_id
     * @param type $parent_id
     * @return type
     */
    function getLeadData($lead_id, $parent_id)
    {
        $leadDataArr = $inLabelArr = $inLeadArr = $finalLeadArr = $temp = [];
        $list_id = 0;
        $sql = "(SELECT * FROM list_data WHERE id = $lead_id) UNION (SELECT * FROM list_data_archive WHERE id = $lead_id)";
        $record = DB::connection('mysql_' . $parent_id)->select($sql);
        $listData = (array) $record;

        if (!empty($listData)) {
            $list_id = $listData[0]->list_id;
            foreach ($listData[0] as $key => $val) {
                $inLeadArr[$key] = $val;
            }
        } else { //if no lead id found then get from list table having type = 2
            $sql = "SELECT id FROM list WHERE type = 2";
            $record = DB::connection('mysql_' . $parent_id)->select($sql);
            $list = (array) $record;
            if (!empty($list)) {
                $list_id = $list[0]->id;
            }
        }

        if ($list_id > 0) {
            //$sql = "SELECT id, title FROM label ORDER BY label.id ASC"; //get all labels
            $sql = "SELECT id, title FROM label where is_deleted='0' and status='1' ORDER BY label.display_order ASC"; //get all labels

            $labels = DB::connection('mysql_' . $parent_id)->select($sql);

            //get all list_header columun (option_1,option_2)
            $sql = $sql = "SELECT list_header.is_dialing, list_header.column_name, label.title, label.id "
                . "FROM list_header inner join label on label.id = list_header.label_id  "
                . "WHERE list_header.list_id IN(" . $list_id . ") group by label.title ORDER BY label.id ASC";
            $listHeaders = DB::connection('mysql_' . $parent_id)->select($sql);

            //intermidiate label array
            foreach ($labels as $lab) {
                $inLabelArr[$lab->id] = $lab->title;
            }
            //Create lead array from intermidiate List header array
            foreach ($listHeaders as $header) {
                $temp['id'] = $header->id;
                $temp['title'] = $header->title;
                $temp['is_dialing'] = $header->is_dialing;
                $temp['value'] = isset($inLeadArr[$header->column_name]) ? $inLeadArr[$header->column_name] : '';
                $leadDataArr[$header->id] = $temp;
                $temp = [];
            }
            //Create final lead array from  Lead array
            foreach ($inLabelArr as $key => $val) {
                if (isset($leadDataArr[$key])) {
                    $finalLeadArr[$key] = $leadDataArr[$key];
                } else {
                    $temp['id'] = $key;
                    $temp['title'] = $val;
                    $temp['value'] = '';
                    $temp['is_dialing'] = 0;
                    $finalLeadArr[$key] = $temp;
                }
                $temp = [];
            }
        }
        return (array) $finalLeadArr;
    }


    function getLeadData_copy($lead_id, $parent_id)
    {
        $leadDataArr = $inLabelArr = $inLeadArr = $finalLeadArr = $temp = [];
        $list_id = 0;


        $connectionName = 'mysql_' . $parent_id;
        $dbName = DB::connection($connectionName)->getDatabaseName();

        // Get column names dynamically using information_schema
        $listDataCols = DB::connection($connectionName)
            ->table('information_schema.columns')
            ->where('table_schema', $dbName)
            ->where('table_name', 'list_data')
            ->orderBy('ordinal_position')
            ->pluck('column_name')
            ->toArray();

        $archiveCols = DB::connection($connectionName)
            ->table('information_schema.columns')
            ->where('table_schema', $dbName)
            ->where('table_name', 'list_data_archive')
            ->orderBy('ordinal_position')
            ->pluck('column_name')
            ->toArray();

        // Build select statements
        $listDataSelect = implode(', ', $listDataCols);

        $archiveSelect = collect($listDataCols)->map(function ($col) use ($archiveCols) {
            return in_array($col, $archiveCols) ? $col : "NULL AS $col";
        })->implode(', ');

        $sql = "(SELECT $listDataSelect FROM list_data WHERE id = $lead_id) UNION (SELECT $archiveSelect FROM list_data_archive WHERE id = $lead_id)";
        $record = DB::connection('mysql_' . $parent_id)->select($sql);
        $listData = (array) $record;
                Log::info("reached listData",['listData'=>$listData]);

        if (!empty($listData)) {
            $list_id = $listData[0]->list_id;
            foreach ($listData[0] as $key => $val) {
                $inLeadArr[$key] = $val;
            }
        } else { //if no lead id found then get from list table having type = 2
            $sql = "SELECT id FROM list WHERE type = 2";
            $record = DB::connection('mysql_' . $parent_id)->select($sql);
            $list = (array) $record;
            if (!empty($list)) {
                $list_id = $list[0]->id;
            }
        }
                Log::info("reached listis",['list_id'=>$list_id]);

        if ($list_id > 0) {
            //$sql = "SELECT id, title FROM label ORDER BY label.id ASC"; //get all labels
            // $sql = "SELECT id, title FROM label where is_deleted='0' and status='1' ORDER BY label.display_order ASC"; //get all labels

            // $labels = DB::connection('mysql_' . $parent_id)->select($sql);
            $sql = "SELECT label.id, label.title 
        FROM list_header
        INNER JOIN label ON label.id = list_header.label_id
        WHERE list_header.list_id = $list_id
        AND list_header.is_visible = 1
        AND label.is_deleted = 0
        AND label.status = 1
        ORDER BY label.display_order ASC";
                Log::info("reached sql",['list_id'=>$list_id]);

        $labels = DB::connection('mysql_' . $parent_id)->select($sql);

                Log::info("labels",['labels'=>$labels]);
            $sql = "SELECT list_header.is_dialing, list_header.column_name, 
               label.title, label.id
        FROM list_header 
        INNER JOIN label ON label.id = list_header.label_id
        WHERE list_header.list_id = $list_id
        AND list_header.is_visible = 1
        ORDER BY label.display_order ASC";
            $listHeaders = DB::connection('mysql_' . $parent_id)->select($sql);
                Log::info("listHeaders",['listHeaders'=>$listHeaders]);



            //intermidiate label array
            foreach ($labels as $lab) {
                $inLabelArr[$lab->id] = $lab->title;
            }
            //Create lead array from intermidiate List header array
            foreach ($listHeaders as $header) {
                $temp['id'] = $header->id;
                $temp['title'] = $header->title;
                $temp['is_dialing'] = $header->is_dialing;
                $temp['value'] = isset($inLeadArr[$header->column_name]) ? $inLeadArr[$header->column_name] : '';
                $leadDataArr[$header->id] = $temp;
                $temp = [];
            }
                            Log::info("list array",['temp'=>$temp]);

            //Create final lead array from  Lead array
            foreach ($inLabelArr as $key => $val) {
                if (isset($leadDataArr[$key])) {
                    $finalLeadArr[$key] = $leadDataArr[$key];
                } else {
                    $temp['id'] = $key;
                    $temp['title'] = $val;
                    $temp['value'] = '';
                    $temp['is_dialing'] = 0;
                    $finalLeadArr[$key] = $temp;
                }
                $temp = [];
            }
        }
        return (array) $finalLeadArr;
    }

    /**
     * Get user info on extension
     * @param type $ext
     * @return type
     */
    function getUserInfoOnExt($ext)
    {
        $sql = "SELECT id, first_name, last_name, email, mobile, extension FROM users WHERE extension IN (" . implode(',', array_filter($ext)) . ")";
        $record = DB::connection('master')->select($sql);
        return (array) $record;
    }

    function getUserInfoOnAltExt($ext)
    {
        $sql = "SELECT id, first_name, last_name, email, mobile, alt_extension as extension FROM users WHERE alt_extension IN (" . implode(',', array_filter($ext)) . ")";
        $record = DB::connection('master')->select($sql);
        return (array) $record;
    }

    /**
     * Get Sms on Extension
     * @param type $ext
     * @param type $parent_id
     * @return type
     */
    function getSmsLogOnExt($uniqueUid, $number1, $number2, $parent_id)
    {
        $sql = "SELECT *, date AS start_time, 'sms' AS platform FROM sms WHERE number = $number1 OR number = $number2  ORDER BY start_time DESC";
        $record = DB::connection('mysql_' . $parent_id)->select($sql);
        return (array) $record;
    }

    /**
     * Get Fax on Extension
     * @param type $ext
     * @param type $parent_id
     * @return type
     */
    function getFaxLogOnExt($ext, $number1, $number2, $parent_id)
    {
        $sql = "SELECT *, 'fax' AS platform FROM fax WHERE dialednumber = '" . $number1 . "' OR dialednumber = '" . $number2 . "' ORDER BY start_time DESC";
        $records = DB::connection('mysql_' . $parent_id)->select($sql);
        return (array) $records;
    }

    function getCommentsLogOnExt($ext, $lead_id, $parent_id)
    {
        $sql = "SELECT *,created_at as start_time,'comment' AS platform FROM comment WHERE lead_id='" . $lead_id . "' ORDER BY created_at DESC";
        $records = DB::connection('mysql_' . $parent_id)->select($sql);
        return (array) $records;
    }

    /**
     * Sort result on time
     * @param type $a
     * @param type $b
     * @return type
     */
    // function sortResultOntimeDesc($a, $b)
    // {
    //     $ad = strtotime($a->start_time);
    //     $bd = strtotime($b->start_time);
    //     return ($bd - $ad);
    // }
public function sortResultOntimeDesc($a, $b)
{
    $timeA =
        $a->start_time ??
        $a->created_at ??
        $a->sms_time ??
        $a->fax_time ??
        null;

    $timeB =
        $b->start_time ??
        $b->created_at ??
        $b->sms_time ??
        $b->fax_time ??
        null;

    return strtotime($timeB) <=> strtotime($timeA);
}

public function getReportPress1Campaign($request)
{
    try {
        $parentConn = 'mysql_' . $request->auth->parent_id;
        $searchString = [];

        // 🔍 Individual Filters
        if ($request->filled('number')) {
            $number = trim($request->input('number'));
            $searchString[] = "ivl.number LIKE '%$number%'";
        }

        if ($request->filled('dtmf')) {
            $dtmf = trim($request->input('dtmf'));
            $searchString[] = "ivl.dtmf = '$dtmf'";
        }

        if ($request->filled('campaign')) {
            $campaignId = (int) $request->input('campaign');
            $searchString[] = "ivl.campaign_id = $campaignId";
        }

        if ($request->filled('route')) {
            $route = trim($request->input('route'));
            $searchString[] = "ivl.route = '$route'";
        }

        if ($request->filled('type')) {
            $type = trim($request->input('type'));
            $searchString[] = "ivl.type = '$type'";
        }

        // 🕒 Date filter
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $start = date('Y-m-d 00:00:00', strtotime($request->input('start_date')));
            $end = date('Y-m-d 23:59:59', strtotime($request->input('end_date')));

            if (!empty($request->auth->timezone)) {
                $timeZoneService = new TimezoneService();
                $timezoneValue = $timeZoneService->findTimezoneValue($request->auth->timezone);
                $searchString[] = "CONVERT_TZ(ivl.created_at,'+00:00','$timezoneValue') BETWEEN '$start' AND '$end'";
            } else {
                $searchString[] = "ivl.created_at BETWEEN '$start' AND '$end'";
            }
        }

        // 🔎 Apply global search (if any)
        if ($request->filled('search')) {
            $keyword = trim($request->input('search'));
            $keyword = addslashes($keyword); // prevent SQL error

            $globalSearch = [];
            $globalSearch[] = "ivl.number LIKE '%$keyword%'";
            $globalSearch[] = "ivl.cli LIKE '%$keyword%'";
            $globalSearch[] = "c.title LIKE '%$keyword%'";
            $globalSearch[] = "ivl.dtmf LIKE '%$keyword%'";
            $globalSearch[] = "ivl.route LIKE '%$keyword%'";

            $searchString[] = '(' . implode(' OR ', $globalSearch) . ')';
        }

        // 🧮 Pagination (default 0–10)
        $limit = $request->input('limit', 10);
        $offset = $request->input('start', 0);
        $limitString = "LIMIT $offset, $limit";

        // 🧩 Combine all filters
        $filter = (!empty($searchString)) ? 'WHERE ' . implode(' AND ', $searchString) : '';

        // ⚙️ Main query
        $sql = "
            SELECT SQL_CALC_FOUND_ROWS 
                MAX(ivl.id) as id,
                ivl.lead_id,
                ivl.number,
                ivl.cli,
                ivl.route,
                ivl.campaign_id,
                ivl.created_at,
                ivl.dtmf,
                c.title as campaign_name
            FROM ivr_log ivl
            LEFT JOIN campaign c ON ivl.campaign_id = c.id
            $filter
            GROUP BY ivl.lead_id
            ORDER BY id DESC
            $limitString
        ";

        $records = DB::connection($parentConn)->select($sql);

        // 🧮 Total count
        $recordCountObj = DB::connection($parentConn)->selectOne("SELECT FOUND_ROWS() AS count");
        $recordCount = $recordCountObj->count ?? 0;

        // 🧠 Resolve DTMF titles
        foreach ($records as &$r) {
            if (!empty($r->dtmf)) {
                $query = "SELECT dtmf_title FROM ivr_menu 
                          WHERE ivr_table_id = (SELECT ivr_id FROM ivr_log WHERE id = $r->id LIMIT 1)
                          AND dtmf = '$r->dtmf' LIMIT 1";

                $dtmfMenu = DB::connection($parentConn)->selectOne($query);
                $r->dtmf = $dtmfMenu->dtmf_title ?? '';
            } else {
                $r->dtmf = '';
            }
        }

        return [
            'success' => 'true',
            'message' => 'Call Data Report.',
            'total_rows' => $recordCount,
            'data' => $records
        ];

    } catch (Exception $e) {
        Log::error($e->getMessage());
        return [
            'success' => 'false',
            'message' => 'Error fetching Call Data Report.'
        ];
    } catch (InvalidArgumentException $e) {
        Log::error($e->getMessage());
        return [
            'success' => 'false',
            'message' => 'Invalid argument passed.'
        ];
    }
}


    public function getReportPress1Campaignold($request)
    {

        // return $request->all();

        $user_data_did = array();
        try {

            $search = array();
            $searchString = array();
            $searchString1 = array();

            $limitString = '';
            if ($request->has('number') && !empty($request->input('number'))) {
                $search['number'] = $request->input('number');
                // $search['number1'] = $request->input('number');

                //array_push($searchString, 'number = :number');
                //array_push($searchString1, 'number = :number1');
                array_push($searchString, "number like CONCAT('%','" . $search['number'] . "')");
                //array_push($searchString1, "number like CONCAT(, '%')");
            }


            if ($request->has('dtmf') && !empty($request->input('dtmf'))) {
                $search['dtmf'] = $request->input('dtmf');

                array_push($searchString, 'dtmf = "' . $search['dtmf'] . '"');
                //array_push($searchString1, 'campaign_id = :campaign_id1');
            }



            if ($request->has('campaign') && !empty($request->input('campaign'))) {
                $search['campaign_id'] = $request->input('campaign');

                array_push($searchString, 'campaign_id = "' . $search['campaign_id'] . '"');
                //array_push($searchString1, 'campaign_id = :campaign_id1');
            }

            if ($request->has('route') && !empty($request->input('route'))) {
                $search['route'] = $request->input('route');
                $search['route1'] = $request->input('route');

                array_push($searchString, 'route = :route');
                array_push($searchString1, 'route = :route1');
            }



            if ($request->has('type') && !empty($request->input('type'))) {
                $search['type'] = $request->input('type');
                $search['type1'] = $request->input('type');

                array_push($searchString, 'type = :type');
                array_push($searchString1, 'type = :type1');
            }


            if ($request->has('start_date') && $request->has('end_date') && !empty($request->input('start_date')) && !empty($request->input('end_date'))) {
                $start = date('Y-m-d', strtotime($request->input('start_date'))) . " 00:00:00";
                $end = date('Y-m-d', strtotime($request->input('end_date'))) . " 23:59:59";
                $search['start_time'] = $start;
                $search['end_time'] = $end;

                /*$search['start_time1'] = $start;
                $search['end_time1'] = $end;
*/


                if (!empty($request->auth->timezone)) {
                    $timeZoneService = new TimezoneService();
                    $timezoneValue = $timeZoneService->findTimezoneValue($request->auth->timezone);
                    array_push($searchString, 'CONVERT_TZ(created_at,"+00:00", "' . $timezoneValue . '") BETWEEN "' . $search['start_time'] . '" AND "' . $search['end_time'] . '"');
                    // array_push($searchString1, 'CONVERT_TZ(created_at,"+00:00", "'.$timezoneValue.'") BETWEEN :start_time1 AND :end_time1');
                } else {
                    array_push($searchString, 'created_at BETWEEN "' . $search['start_time'] . '" AND "' . $search['end_time'] . '"');
                    ///array_push($searchString1, 'created_at BETWEEN :start_time1 AND :end_time1');
                }
            }



            if ($request->has('lower_limit') && $request->has('upper_limit') && is_numeric($request->input('lower_limit')) && is_numeric($request->input('upper_limit'))) {
                $search['lower_limit'] = $request->input('lower_limit');
                $search['upper_limit'] = $request->input('upper_limit');
                //  $limitString = "LIMIT :lower_limit , :upper_limit";
                $limitString = "LIMIT " . $search['lower_limit'] . " , " . $search['upper_limit'] . "";
            }
            $filter = (!empty($searchString)) ? " WHERE " . implode(" AND ", $searchString) : '';
            // $filter1 = (!empty($searchString1)) ? " WHERE " . implode(" AND ", $searchString1) : '';


            $query_string = "SELECT SQL_CALC_FOUND_ROWS lead_id from ivr_log $filter  group by lead_id ";

            /// $query_string = "SELECT MAX(id) AS max_id,number,cli,dtmf,campaign_id,created_at,route,lead_id FROM ivr_log  $filter  GROUP BY lead_id ";



            $sql = $query_string . $limitString;

            $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql, $search);
            $data = array();

            //return $data;
            $recordCount = DB::connection('mysql_' . $request->auth->parent_id)->selectOne("SELECT FOUND_ROWS() as count");
            $recordCount = (array) $recordCount;

            foreach ($record as $key =>  $r) {
                $query_string1 = "SELECT max(id) as iddd,lead_id FROM ivr_log  where lead_id=" . $r->lead_id . "";

                $record11 = DB::connection('mysql_' . $request->auth->parent_id)->selectOne($query_string1);


                $query_string11 = "SELECT * from   ivr_log where id=" . $record11->iddd . "";

                $record111 = DB::connection('mysql_' . $request->auth->parent_id)->selectOne($query_string11);


                $data[$key]['cli'] = $record111->cli;
                $data[$key]['number'] = $record111->number;
                $data[$key]['lead_id'] = $record111->lead_id;
                $data[$key]['route'] = $record111->route;
                $data[$key]['campaign_id'] = $record111->campaign_id;

                // ✅ Fetch campaign name
                $campaignQuery = "SELECT title FROM campaign WHERE id = " . $record111->campaign_id . " LIMIT 1";
                $campaignRecord = DB::connection('mysql_' . $request->auth->parent_id)->selectOne($campaignQuery);

                $data[$key]['campaign_name'] = !empty($campaignRecord->title) ? $campaignRecord->title : '';


                $data[$key]['created_at'] = $record111->created_at;

                //  $data[$key]['dtmf'] = $record111->dtmf;


                if (!empty($record111->dtmf)) {

                    $query_string11111 = "SELECT * from   ivr_menu where ivr_table_id=" . $record111->ivr_id . " and dtmf='" . $record111->dtmf . "'";
                    $record111111 = DB::connection('mysql_' . $request->auth->parent_id)->selectOne($query_string11111);

                    if (!empty($record111111->dtmf_title)) {
                        $data[$key]['dtmf'] = $record111111->dtmf_title;
                    } else {
                        $data[$key]['dtmf'] = '';
                    }
                } else {
                    $data[$key]['dtmf'] = '';
                }
            }




            if (!empty($record)) {
                $data1 = (array) $data;

                return array(
                    'success' => 'true',
                    'message' => 'Call Data Report.',
                    'record_count' => $recordCount['count'],
                    'data' => $data1
                );
            } else {
                return array(
                    'success' => 'true',
                    'message' => 'No Call Data Report found.',
                    'record_count' => 0,
                    'data' => array()
                );
            }

            return array(
                'success' => 'false',
                'message' => 'Call Data Report doesn\'t exist.'
            );
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }
}
