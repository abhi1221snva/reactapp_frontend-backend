<?php


namespace App\Services;


use App\Model\Campaign;
use App\Model\Master\Client;
use App\Model\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;


class ReportService
{
    private $clientId;

    public function __construct(int $clientId)
    {
        $this->clientId = $clientId;
    }


     public function dialerAllCountCRM(Request $request)
    {
        $client = Client::where('api_key',$request->api_key)->get()->first();

        $parent_id = $client->id;
        $connection = 'mysql_'.$client->id;

        $result_arr = array();
        $previous_day = $request->start_date. ' 00:00:00';
        $current_day  = $request->end_date. ' 23:59:59';

        $result_arr['start_time'] = $previous_day;
        $result_arr['end_time'] = $current_day;

        $result_arr['company_name'] = $client->company_name;

        $extensions = DB::select("select extension,alt_extension from users where base_parent_id=".$parent_id);

        foreach($extensions as $list)
        {
            $extensionsList[] = $list->extension;
            $extensionsList[] = $list->alt_extension; 
        }

        $extCondition = " AND extension IN (".implode(",", $extensionsList).")";
        $cliCondition = " AND cli IN (".implode(",", $extensionsList).")";

        $agent_list = array();

        $sql = "select * from users WHERE parent_id=".$parent_id." AND is_deleted=0 $extCondition order by first_name";
        $agent_list = DB::connection("master")->select($sql);

        $j = 0;

        if(!empty($agent_list))
        {
            foreach ( $agent_list as $agent )
            {
                $agent_list_calls = (array)$agent;
                $alt_extension = $agent_list_calls['alt_extension'];
                $extension = $agent_list_calls['extension'];
                //$result_arr['agent'][$j]['extension'] = $extension;
                $result_arr['agent'][$j]['extension'] = $alt_extension; //is alternate ext

                $result_arr['agent'][$j]['agentName'] = $agent_list_calls['first_name'] . ' ' . $agent_list_calls['last_name'];

                $totalCall = 0;

               //dialer_call
                $agent_call_list_out_dialer_cdr = DB::connection($connection)->select("select count(*) as totalOutCalls from cdr WHERE extension IN('" . $extension . "','" . $alt_extension . "') and route = :route and (type = :type or type = :type1) and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'", array('route' => 'OUT','type'=>'dialer','type1'=>'manual'));
                $dialer_call_cdr = $agent_call_list_out_dialer_cdr[0]->totalOutCalls;
                $totalCall += $dialer_call_cdr;


              


                $agent_call_list_out_archive_dialer = DB::connection($connection)->select("select count(*) as totalOutCalls from cdr_archive WHERE extension IN('" . $extension . "','" . $alt_extension . "') and route = :route and (type = :type or type = :type1) and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'", array('route' => 'OUT','type'=>'dialer','type1' => 'manual'));
                $dialer_call_archive = $agent_call_list_out_archive_dialer[0]->totalOutCalls;
                $totalCall += $dialer_call_archive;


                $result_arr['agent'][$j]['dialer_call'] = $dialer_call_cdr + $dialer_call_archive;

                //close


                //c2c calls

          

                $agent_call_list_cdr_c2c = DB::connection($connection)->select("select count(*) as totalOutCalls from cdr WHERE extension IN('" . $extension . "','" . $alt_extension . "') and route = :route and type = :type and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'", array('route' => 'OUT','type'=>'c2c'));
                $c2c_call_cdr = $agent_call_list_cdr_c2c[0]->totalOutCalls;
                $totalCall += $c2c_call_cdr;

                

                    $agent_call_list_out_archive_c2c = DB::connection($connection)->select("select count(*) as totalOutCalls from cdr_archive WHERE extension IN('" . $extension . "','" . $alt_extension . "') and route = :route and type = :type and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'", array('route' => 'OUT','type'=>'c2c'));
                $c2c_call_cdr_archive = $agent_call_list_out_archive_c2c[0]->totalOutCalls;
                $totalCall += $c2c_call_cdr_archive;


                $result_arr['agent'][$j]['c2c_call'] = $c2c_call_cdr + $c2c_call_cdr_archive;
               
                //close c2c

                //desktop calls


                $agent_call_list_in_cdr = DB::connection($connection)->select("select count(*) as totalInCalls from cdr WHERE extension IN('" . $extension . "','" . $alt_extension . "') and route = :route and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'", array('route' => 'IN'));
                $desktop_call_cdr = $agent_call_list_in_cdr[0]->totalInCalls;
                $totalCall += $desktop_call_cdr;


                


                $agent_call_list_in_cdr_archive = DB::connection($connection)->select("select count(*) as totalInCalls from cdr_archive WHERE extension IN('" . $extension . "','" . $alt_extension . "') and route = :route and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'", array('route' => 'IN'));
                $desktop_call_cdr_archive = $agent_call_list_in_cdr_archive[0]->totalInCalls;
                $totalCall += $desktop_call_cdr_archive;

                $result_arr['agent'][$j]['desktop_call'] = $desktop_call_cdr + $desktop_call_cdr_archive;

            //desktop close


                //dialer calls duration

                $agent_call_list_cdr = DB::connection($connection)->select("select sum(duration) as totalDuration from cdr WHERE extension IN('" . $extension . "','" . $alt_extension . "') and route = :route and (type = :type or type = :type1) and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'",array('route' => 'OUT','type'=>'dialer','type1'=>'manual'));

                $duration_call_list_cdr_dialer = $agent_call_list_cdr[0]->totalDuration;

                $agent_call_list_cdr_archive = DB::connection($connection)->select("select sum(duration) as totalDuration from cdr_archive WHERE extension IN('" . $extension . "','" . $alt_extension . "') and route = :route and (type = :type or type = :type1) and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'",array('route' => 'OUT','type'=>'dialer','type1'=>'manual'));

                $duration_call_list_cdr_archive_dialer = $agent_call_list_cdr_archive[0]->totalDuration;

                $result_arr['agent'][$j]['dialer_call_time_spent_in_second'] = $duration_call_list_cdr_dialer + $duration_call_list_cdr_archive_dialer;

                //close dialer duration


                //c2c calls duration

                $agent_call_list_cdr_C2c = DB::connection($connection)->select("select sum(duration) as totalDuration from cdr WHERE extension IN('" . $extension . "','" . $alt_extension . "') and route = :route and type = :type and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'",array('route' => 'OUT','type'=>'c2c'));

                $duration_call_list_cdr_c2c = $agent_call_list_cdr_C2c[0]->totalDuration;


                $agent_call_list_c2c_archive = DB::connection($connection)->select("select sum(duration) as totalDuration from cdr_archive WHERE extension IN('" . $extension . "','" . $alt_extension . "') and route = :route and type = :type and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'",array('route' => 'OUT','type'=>'c2c'));

                $duration_call_list_cdr_archive_c2c = $agent_call_list_c2c_archive[0]->totalDuration;

                $result_arr['agent'][$j]['c2c_call_time_spent_in_second'] =  $duration_call_list_cdr_c2c + $duration_call_list_cdr_archive_c2c;

                //close c2c

                //desktop call




                $agent_call_list_desktop = DB::connection($connection)->select("select sum(duration) as totalDuration from cdr WHERE extension IN('" . $extension . "','" . $alt_extension . "') and route = :route and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'",array('route' => 'IN'));

                $desktop_call_cdr = $agent_call_list_desktop[0]->totalDuration;

                $agent_call_list_desktop_archive = DB::connection($connection)->select("select sum(duration) as totalDuration from cdr_archive WHERE extension IN('" . $extension . "','" . $alt_extension . "') and route = :route and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'",array('route' => 'IN'));
                $desktop_call_cdr_archive =  $agent_call_list_desktop_archive[0]->totalDuration;


                $result_arr['agent'][$j]['desktop_call_time_spent_in_second'] = $desktop_call_cdr + $desktop_call_cdr_archive;

                
                $result_arr['agent'][$j]['totalcalls'] = $totalCall;



                
                $j++;

            }
        }

        Log::info("ReportService.callreport_through_crm", ["startTime" => $previous_day,"endTime" => $current_day,"response" => $result_arr]);


        return $result_arr;
    }


    public function dialerAllCountCRMOLD(Request $request)
    {
        $connection = 'mysql_'.$request->auth->parent_id;
        $result_arr = array();

        $previous_day = $request->start_date. ' 00:00:00';
        $current_day  = $request->end_date. ' 23:59:59';

        $client = Client::find($request->auth->parent_id);
        $result_arr['start_time'] = $previous_day;
        $result_arr['end_time'] = $current_day;

        $result_arr['company_name'] = $client->company_name;


        $extensions = DB::select("select extension,alt_extension from users where base_parent_id=".$request->auth->parent_id);

        foreach($extensions as $list)
        {
            $extensionsList[] = $list->extension;
            $extensionsList[] = $list->alt_extension; 
        }

        $extCondition = " AND extension IN (".implode(",", $extensionsList).")";

        $cliCondition = " AND cli IN (".implode(",", $extensionsList).")";



        

        $agent_list = array();

        $sql = "select * from users WHERE parent_id=".$request->auth->parent_id." AND is_deleted=0 $extCondition order by first_name";
        $agent_list = DB::connection("master")->select($sql);

        $j = 0;

        if(!empty($agent_list))
        {
            foreach ( $agent_list as $agent )
            {
                $agent_list_calls = (array)$agent;
                $alt_extension = $agent_list_calls['alt_extension'];
                $extension = $agent_list_calls['extension'];
                //$result_arr['agent'][$j]['extension'] = $extension;
                $result_arr['agent'][$j]['extension'] = $alt_extension; //is alternate ext

                $result_arr['agent'][$j]['agentName'] = $agent_list_calls['first_name'] . ' ' . $agent_list_calls['last_name'];

                $totalCall = 0;

                //cdr

                $agent_call_list_out = DB::connection($connection)->select("select count(*) as totalOutCalls from cdr WHERE extension IN('" . $extension . "','" . $alt_extension . "') and route = :route and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'", array('route' => 'OUT'));
                $result_arr['agent'][$j]['dialer_call'] = $agent_call_list_out[0]->totalOutCalls;
                $totalCall += $agent_call_list_out[0]->totalOutCalls;


                $agent_call_list_out_archive = DB::connection($connection)->select("select count(*) as totalOutCalls from cdr_archive WHERE extension IN('" . $extension . "','" . $alt_extension . "') and route = :route and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'", array('route' => 'OUT'));
                $result_arr['agent'][$j]['dialer_call'] = $agent_call_list_out_archive[0]->totalOutCalls;
                $totalCall += $agent_call_list_out_archive[0]->totalOutCalls;

                $agent_call_list_in = DB::connection($connection)->select("select count(*) as totalInCalls from cdr WHERE extension IN('" . $extension . "','" . $alt_extension . "') and route = :route and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'", array('route' => 'IN'));
                $result_arr['agent'][$j]['desktop_call'] = $agent_call_list_in[0]->totalInCalls;
                $totalCall += $agent_call_list_in[0]->totalInCalls;

                $agent_call_list_in = DB::connection($connection)->select("select count(*) as totalInCalls from cdr_archive WHERE extension IN('" . $extension . "','" . $alt_extension . "') and route = :route and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'", array('route' => 'IN'));
                $result_arr['agent'][$j]['desktop_call'] = $agent_call_list_in[0]->totalInCalls;
                $totalCall += $agent_call_list_in[0]->totalInCalls;


                $agent_call_list = DB::connection($connection)->select("select sum(duration) as totalDuration from cdr WHERE extension IN('" . $extension . "','" . $alt_extension . "') and route = :route and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'",array('route' => 'OUT'));

                $duration = $agent_call_list[0]->totalDuration;

                $agent_call_list = DB::connection($connection)->select("select sum(duration) as totalDuration from cdr_archive WHERE extension IN('" . $extension . "','" . $alt_extension . "') and route = :route and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'",array('route' => 'OUT'));
                $result_arr['agent'][$j]['dialer_call_time_spent_in_second'] = $duration + $agent_call_list[0]->totalDuration;

                //desktop call

                $agent_call_list_desktop = DB::connection($connection)->select("select sum(duration) as totalDuration from cdr WHERE extension IN('" . $extension . "','" . $alt_extension . "') and route = :route and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'",array('route' => 'IN'));

                $duration = $agent_call_list_desktop[0]->totalDuration;

                $agent_call_list_desktop = DB::connection($connection)->select("select sum(duration) as totalDuration from cdr_archive WHERE extension IN('" . $extension . "','" . $alt_extension . "') and route = :route and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'",array('route' => 'IN'));
                $result_arr['agent'][$j]['desktop_call_time_spent_in_second'] = $duration + $agent_call_list_desktop[0]->totalDuration;

                
                $result_arr['agent'][$j]['totalcalls'] = $totalCall;



                
                $j++;

            }
        }



       
          


           

        
            return $result_arr;
    }
//     public function dialerAllCount(Request $request)
// {
//     try {
//         $client_id = $request->auth->parent_id;
//         $connection = "mysql_" . $client_id;

//         $start_date = $request->start_date;
//         $end_date = $request->end_date;
//         $extensions = $request->extensions;

//         if (!is_array($extensions)) {
//             $extensions = [$extensions];
//         }

//         // 🧩 Log incoming request
//         \Log::info('Dialer Count Debug', [
//             'client_id' => $client_id,
//             'start_date' => $start_date,
//             'end_date' => $end_date,
//             'extensions' => $extensions
//         ]);

//         $extString = implode(',', $extensions);

//         // ✅ Outbound Calls (cdr + cdr_archive)
//         $outbound_res = DB::connection($connection)->select("
//             SELECT COUNT(*) AS totalOutBoundCalls FROM cdr 
//             WHERE route = 'OUT'
//             AND start_time BETWEEN '$start_date' AND '$end_date'
//             AND extension IN ($extString)
//             UNION ALL
//             SELECT COUNT(*) AS totalOutBoundCalls FROM cdr_archive 
//             WHERE route = 'OUT'
//             AND start_time BETWEEN '$start_date' AND '$end_date'
//             AND extension IN ($extString)
//         ");
//         $totalOutBoundCalls = ($outbound_res[0]->totalOutBoundCalls ?? 0)
//                             + ($outbound_res[1]->totalOutBoundCalls ?? 0);

//         // ✅ Inbound Calls (cdr + cdr_archive)
//         $inbound_res = DB::connection($connection)->select("
//             SELECT COUNT(*) AS totalInBoundCalls FROM cdr 
//             WHERE route = 'IN'
//             AND start_time BETWEEN '$start_date' AND '$end_date'
//             AND extension IN ($extString)
//             UNION ALL
//             SELECT COUNT(*) AS totalInBoundCalls FROM cdr_archive 
//             WHERE route = 'IN'
//             AND start_time BETWEEN '$start_date' AND '$end_date'
//             AND extension IN ($extString)
//         ");
//         $totalInBoundCalls = ($inbound_res[0]->totalInBoundCalls ?? 0)
//                            + ($inbound_res[1]->totalInBoundCalls ?? 0);

//         // ✅ Manual Calls (cdr + cdr_archive)
//         $manual_res = DB::connection($connection)->select("
//             SELECT COUNT(*) AS totalManualCalls FROM cdr 
//             WHERE type = 'manual'
//             AND start_time BETWEEN '$start_date' AND '$end_date'
//             AND extension IN ($extString)
//             UNION ALL
//             SELECT COUNT(*) AS totalManualCalls FROM cdr_archive 
//             WHERE type = 'manual'
//             AND start_time BETWEEN '$start_date' AND '$end_date'
//             AND extension IN ($extString)
//         ");
//         $totalManualCalls = ($manual_res[0]->totalManualCalls ?? 0)
//                           + ($manual_res[1]->totalManualCalls ?? 0);

//         // ✅ Dialer Calls (cdr + cdr_archive)
//         $dialer_res = DB::connection($connection)->select("
//             SELECT COUNT(*) AS totalDialerCalls FROM cdr 
//             WHERE type = 'outbound_ai'
//             AND start_time BETWEEN '$start_date' AND '$end_date'
//             AND extension IN ($extString)
//             UNION ALL
//             SELECT COUNT(*) AS totalDialerCalls FROM cdr_archive 
//             WHERE type = 'outbound_ai'
//             AND start_time BETWEEN '$start_date' AND '$end_date'
//             AND extension IN ($extString)
//         ");
//         $totalDialerCalls = ($dialer_res[0]->totalDialerCalls ?? 0)
//                           + ($dialer_res[1]->totalDialerCalls ?? 0);

//         // ✅ SMS (using sms table, not sms_logs)
//         $sms_send = DB::connection($connection)->select("
//             SELECT COUNT(*) AS totalSMSSend 
//             FROM sms 
//             WHERE type = 'outgoing' 
//             AND date BETWEEN '$start_date' AND '$end_date'
//         ");
//         $total_sms_send = $sms_send[0]->totalSMSSend ?? 0;

//         $sms_receive = DB::connection($connection)->select("
//             SELECT COUNT(*) AS totalSMSReceive 
//             FROM sms 
//             WHERE type = 'incoming' 
//             AND date BETWEEN '$start_date' AND '$end_date'
//         ");
//         $total_sms_receive = $sms_receive[0]->totalSMSReceive ?? 0;

//         // ✅ Combine all
//         $data = [
//             'totalOutBoundCalls' => $totalOutBoundCalls,
//             'totalInBoundCalls' => $totalInBoundCalls,
//             'totalManualCalls' => $totalManualCalls,
//             'totalDialerCalls' => $totalDialerCalls,
//             'total_sms_send' => $total_sms_send,
//             'total_sms_receive' => $total_sms_receive,
//         ];

//         \Log::info('Dialer Count Result', $data);

//         return response()->json([
//             'success' => true,
//             'message' => 'Dialer Count List',
//             'data' => $data
//         ]);

//     } catch (\Exception $e) {
//         \Log::error('DialerAllCount Error', ['error' => $e->getMessage()]);
//         return response()->json([
//             'success' => false,
//             'message' => 'Something went wrong',
//             'error' => $e->getMessage()
//         ], 500);
//     }
// }



public function dialerAllCount(Request $request)
{
    try {
        $client_id = $request->auth->parent_id;
        $connection = "mysql_" . $client_id;

        $start_date = $request->start_date . ' 00:00:00';
        $end_date = $request->end_date . ' 23:59:59';
        $extensions = $request->extensions ?? [];

        if (!is_array($extensions)) {
            $extensions = [$extensions];
        }

        \Log::info('Dialer Count Debug', [
            'client_id' => $client_id,
            'start_date' => $start_date,
            'end_date' => $end_date,
            'extensions' => $extensions
        ]);

        $extString = implode(',', $extensions);

        /** -------------------------------------------------
         *  TOTAL CALL COUNTS
         * ------------------------------------------------- */

        // ✅ Outbound
        $outbound_res = DB::connection($connection)->select("
            SELECT COUNT(*) AS totalOutBoundCalls FROM cdr 
            WHERE route = 'OUT' 
            AND start_time BETWEEN '$start_date' AND '$end_date'
            AND extension IN ($extString)
            UNION ALL
            SELECT COUNT(*) AS totalOutBoundCalls FROM cdr_archive 
            WHERE route = 'OUT'
            AND start_time BETWEEN '$start_date' AND '$end_date'
            AND extension IN ($extString)
        ");
        $totalOutBoundCalls = ($outbound_res[0]->totalOutBoundCalls ?? 0)
                            + ($outbound_res[1]->totalOutBoundCalls ?? 0);

        // ✅ Inbound
        $inbound_res = DB::connection($connection)->select("
            SELECT COUNT(*) AS totalInBoundCalls FROM cdr 
            WHERE route = 'IN'
            AND start_time BETWEEN '$start_date' AND '$end_date'
            AND extension IN ($extString)
            UNION ALL
            SELECT COUNT(*) AS totalInBoundCalls FROM cdr_archive 
            WHERE route = 'IN'
            AND start_time BETWEEN '$start_date' AND '$end_date'
            AND extension IN ($extString)
        ");
        $totalInBoundCalls = ($inbound_res[0]->totalInBoundCalls ?? 0)
                           + ($inbound_res[1]->totalInBoundCalls ?? 0);

        // ✅ Manual
        $manual_res = DB::connection($connection)->select("
            SELECT COUNT(*) AS totalManualCalls FROM cdr 
            WHERE type = 'manual'
            AND start_time BETWEEN '$start_date' AND '$end_date'
            AND extension IN ($extString)
            UNION ALL
            SELECT COUNT(*) AS totalManualCalls FROM cdr_archive 
            WHERE type = 'manual'
            AND start_time BETWEEN '$start_date' AND '$end_date'
            AND extension IN ($extString)
        ");
        $totalManualCalls = ($manual_res[0]->totalManualCalls ?? 0)
                          + ($manual_res[1]->totalManualCalls ?? 0);

        // ✅ Dialer
        $dialer_res = DB::connection($connection)->select("
            SELECT COUNT(*) AS totalDialerCalls FROM cdr 
            WHERE type = 'outbound_ai'
            AND start_time BETWEEN '$start_date' AND '$end_date'
            AND extension IN ($extString)
            UNION ALL
            SELECT COUNT(*) AS totalDialerCalls FROM cdr_archive 
            WHERE type = 'outbound_ai'
            AND start_time BETWEEN '$start_date' AND '$end_date'
            AND extension IN ($extString)
        ");
        $totalDialerCalls = ($dialer_res[0]->totalDialerCalls ?? 0)
                          + ($dialer_res[1]->totalDialerCalls ?? 0);

        // ✅ SMS Counts
        $sms_send = DB::connection($connection)->select("
            SELECT COUNT(*) AS totalSMSSend 
            FROM sms 
            WHERE type = 'outgoing' 
            AND date BETWEEN '$start_date' AND '$end_date'
        ");
        $total_sms_send = $sms_send[0]->totalSMSSend ?? 0;

        $sms_receive = DB::connection($connection)->select("
            SELECT COUNT(*) AS totalSMSReceive 
            FROM sms 
            WHERE type = 'incoming' 
            AND date BETWEEN '$start_date' AND '$end_date'
        ");
        $total_sms_receive = $sms_receive[0]->totalSMSReceive ?? 0;


        /** -------------------------------------------------
         *  AGENT-WISE CALL DATA
         * ------------------------------------------------- */
        $result_arr = [];
        $agent_list = DB::connection('master')->select("
            SELECT * FROM users 
            WHERE parent_id = $client_id AND is_deleted = 0 
            ORDER BY first_name
        ");

        $j = 0;
        foreach ($agent_list as $agent) {
            $agent_arr = (array) $agent;
            $extension = $agent_arr['extension'];
            $alt_extension = $agent_arr['alt_extension'];
            $result_arr['agent'][$j]['agentName'] = trim($agent_arr['first_name'] . ' ' . $agent_arr['last_name']);
            $result_arr['agent'][$j]['extension'] = $extension;
            $result_arr['agent'][$j]['alt_extension'] = $alt_extension;

            $totalCall = 0;

            // Outbound (cdr + archive)
            foreach (['cdr', 'cdr_archive'] as $table) {
                $out = DB::connection($connection)->select("
                    SELECT COUNT(*) AS total FROM $table 
                    WHERE extension IN('$extension', '$alt_extension') 
                    AND route='OUT' AND (type='dialer' OR type='manual')
                    AND start_time BETWEEN '$start_date' AND '$end_date'
                ");
                $totalCall += ($out[0]->total ?? 0);
                $result_arr['agent'][$j]['outbound'] = ($result_arr['agent'][$j]['outbound'] ?? 0) + ($out[0]->total ?? 0);

                $c2c = DB::connection($connection)->select("
                    SELECT COUNT(*) AS total FROM $table 
                    WHERE extension IN('$extension', '$alt_extension') 
                    AND route='OUT' AND type='c2c'
                    AND start_time BETWEEN '$start_date' AND '$end_date'
                ");
                $totalCall += ($c2c[0]->total ?? 0);
                $result_arr['agent'][$j]['c2c'] = ($result_arr['agent'][$j]['c2c'] ?? 0) + ($c2c[0]->total ?? 0);

                $in = DB::connection($connection)->select("
                    SELECT COUNT(*) AS total FROM $table 
                    WHERE extension IN('$extension', '$alt_extension') 
                    AND route='IN'
                    AND start_time BETWEEN '$start_date' AND '$end_date'
                ");
                $totalCall += ($in[0]->total ?? 0);
                $result_arr['agent'][$j]['inbound'] = ($result_arr['agent'][$j]['inbound'] ?? 0) + ($in[0]->total ?? 0);

                $duration = DB::connection($connection)->select("
                    SELECT SUM(duration) AS total FROM $table 
                    WHERE extension IN('$extension', '$alt_extension')
                    AND start_time BETWEEN '$start_date' AND '$end_date'
                ");
                $result_arr['agent'][$j]['duration'] = ($result_arr['agent'][$j]['duration'] ?? 0) + ($duration[0]->total ?? 0);
            }

            // SMS for this agent
            $sms = DB::connection($connection)->select("
                SELECT SUM(type='outgoing') AS outgoing, SUM(type='incoming') AS incoming 
                FROM sms 
                WHERE date BETWEEN '$start_date' AND '$end_date'
                AND extension='" . $agent_arr['id'] . "'
            ");

            $result_arr['agent'][$j]['incoming_sms'] = $sms[0]->incoming ?? 0;
            $result_arr['agent'][$j]['outgoing_sms'] = $sms[0]->outgoing ?? 0;
            $result_arr['agent'][$j]['totalcalls'] = $totalCall;
            $result_arr['agent'][$j]['aht'] = $totalCall ? ($result_arr['agent'][$j]['duration'] / $totalCall) : 0;

            $j++;
        }

        /** -------------------------------------------------
         *  CITY-WISE CALL DATA
         * ------------------------------------------------- */
        $city_wise = [];
        $cdr_city = DB::connection($connection)->select("
            SELECT COUNT(*) AS total, area_code FROM cdr 
            WHERE start_time BETWEEN '$start_date' AND '$end_date' 
            GROUP BY area_code ORDER BY total DESC
        ");
        $cdr_arch_city = DB::connection($connection)->select("
            SELECT COUNT(*) AS total, area_code FROM cdr_archive 
            WHERE start_time BETWEEN '$start_date' AND '$end_date' 
            GROUP BY area_code ORDER BY total DESC
        ");

        $areacode_list = array_merge($cdr_city, $cdr_arch_city);
        $k = 0;
        foreach ($areacode_list as $code) {
            $ac = (array) $code;
            $did_data = DB::connection($connection)->selectOne("SELECT * FROM did WHERE area_code='" . $ac['area_code'] . "'");
            $cname = $did_data->cli ?? '';
            $cnam = DB::connection($connection)->selectOne("SELECT cnam FROM cli_report WHERE cli='$cname' ORDER BY id DESC LIMIT 1");
            $city_data = DB::connection('master')->selectOne("SELECT * FROM areacode_city WHERE areacode='" . $ac['area_code'] . "'");

            $city_wise[$k] = [
                'total' => $ac['total'],
                'area_code' => $ac['area_code'],
                'city' => $city_data->city_name ?? '-',
                'state' => $city_data->state_name ?? '-',
                'did' => $cname ?: '-',
                'cnam' => $cnam->cnam ?? '-',
            ];
            $k++;
        }

        /** -------------------------------------------------
         *  DID CALL DATA
         * ------------------------------------------------- */
        $did_result = [];
        $did_list = DB::connection('master')->select("SELECT * FROM did WHERE parent_id = $client_id");
        $d = 0;

        foreach ($did_list as $did) {
            $did_number = $did->cli;
            $totalCall = 0;

            foreach (['cdr', 'cdr_archive'] as $table) {
                $in_calls = DB::connection($connection)->select("
                    SELECT COUNT(*) AS total FROM $table 
                    WHERE cli='$did_number' AND route='IN'
                    AND start_time BETWEEN '$start_date' AND '$end_date'
                ");
                if (!empty($in_calls) && $in_calls[0]->total != 0) {
                    $totalCall += $in_calls[0]->total;

                    $cnam = DB::connection($connection)->selectOne("
                        SELECT cnam FROM cli_report WHERE cli='$did_number' ORDER BY id DESC LIMIT 1
                    ");
                    $dur = DB::connection($connection)->select("
                        SELECT SUM(duration) AS total FROM $table 
                        WHERE cli='$did_number' AND route='IN'
                        AND start_time BETWEEN '$start_date' AND '$end_date'
                    ");
                    $sms = DB::connection($connection)->select("
                        SELECT SUM(type='outgoing') AS outgoing, SUM(type='incoming') AS incoming 
                        FROM sms 
                        WHERE date BETWEEN '$start_date' AND '$end_date' 
                        AND did='$did_number'
                    ");

                    $did_result[$d] = [
                        'cli' => $did_number,
                        'inbound' => $in_calls[0]->total,
                        'duration' => $dur[0]->total ?? 0,
                        'totalcalls' => $totalCall,
                        'aht' => $totalCall ? (($dur[0]->total ?? 0) / $totalCall) : 0,
                        'cnam' => $cnam->cnam ?? '-',
                        'incoming' => $sms[0]->incoming ?? 0,
                        'outgoing' => $sms[0]->outgoing ?? 0,
                    ];
                    $d++;
                }
            }
        }

        /** -------------------------------------------------
         *  FINAL RESPONSE
         * ------------------------------------------------- */
        $data = [
            'totalOutBoundCalls' => $totalOutBoundCalls,
            'totalInBoundCalls' => $totalInBoundCalls,
            'totalManualCalls' => $totalManualCalls,
            'totalDialerCalls' => $totalDialerCalls,
            'total_sms_send' => $total_sms_send,
            'total_sms_receive' => $total_sms_receive,
            'agent' => $result_arr['agent'] ?? [],
            'city_wise' => $city_wise,
            'did' => $did_result,
        ];

        \Log::info('Dialer Count Result', $data);

        return response()->json([
            'success' => true,
            'message' => 'Dialer Count List',
            'data' => $data
        ]);
    } catch (\Exception $e) {
        \Log::error('DialerAllCount Error', ['error' => $e->getMessage()]);
        return response()->json([
            'success' => false,
            'message' => 'Something went wrong',
            'error' => $e->getMessage()
        ], 500);
    }
}



    public function dialerAllCountold(Request $request)
    {
        $connection = 'mysql_'.$request->client_id;
        $result_arr = array();

        $result_arr['connection'] = $connection;
        $previous_day = $request->start_date. ' 00:00:00';
        $current_day  = $request->end_date. ' 23:59:59';

        $client = Client::find($request->client_id);
        $result_arr['start_time'] = $previous_day;
        $result_arr['end_time'] = $current_day;


        $result_arr['logo'] = env('PORTAL_NAME').'logo/' . $client->logo;
        $result_arr['company_name'] = $client->company_name;
        $extensions = DB::select("select extension,alt_extension from users where base_parent_id=".$request->client_id);

   $extensionsList = [];

foreach($extensions as $list)
{
    if (!empty($list->extension)) {
        $extensionsList[] = $list->extension;
    }
    if (!empty($list->alt_extension)) {
        $extensionsList[] = $list->alt_extension;
    }
}

if (!empty($extensionsList)) {
    $extCondition = " AND extension IN (" . implode(",", $extensionsList) . ")";
    $cliCondition = " AND cli IN (" . implode(",", $extensionsList) . ")";
} else {
    $extCondition = "";
    $cliCondition = "";
}
        $outbound_res = DB::connection($connection)->select("select count(*) as totalOutBoundCalls from cdr WHERE route  = 'OUT'  and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "' $extCondition union select count(*) as totalOutBoundCalls from cdr_archive  WHERE route  = 'OUT' and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "' $extCondition");

        $cdr_outbound = $outbound_res[0]->totalOutBoundCalls;

        if(!empty($outbound_res[1]->totalOutBoundCalls))
        {
            $cdr_archive_outbound = $outbound_res[1]->totalOutBoundCalls;
        }
        else
        {
            $cdr_archive_outbound =0;
        }

        $result_arr['total_outbound_Calls'] = $cdr_outbound + $cdr_archive_outbound;
$outbound_manually = DB::connection($connection)->select("select count(*) as totalOutBoundCallsByManually from cdr WHERE route= 'OUT' and type= 'manual' and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "' $extCondition union select count(*) as totalOutBoundCallsByManually from cdr_archive  WHERE route= 'OUT' and type= 'manual' and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "' $extCondition");

      
$cdr_outbound_manually = $outbound_manually[0]->totalOutBoundCallsByManually;


 if(!empty($outbound_manually[1]->totalOutBoundCallsByManually))
        {
            $cdr_archive_outbound_manually = $outbound_manually[1]->totalOutBoundCallsByManually;
        }
        else
        {
            $cdr_archive_outbound_manually =0;
        }

        $result_arr['total_outbound_Calls_manually'] = $cdr_outbound_manually + $cdr_archive_outbound_manually;        
        $outbound_dialer = DB::connection($connection)->select("select count(*) as totalOutBoundCallsByDialer from cdr WHERE route= 'OUT' and type= 'dialer' and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "' $extCondition union select count(*) as totalOutBoundCallsByDialer from cdr_archive WHERE route= 'OUT' and type= 'dialer' and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "' $extCondition");
        $cdr_outbound_dialer = $outbound_dialer[0]->totalOutBoundCallsByDialer;

        if(!empty($outbound_dialer[1]->totalOutBoundCallsByDialer))
        {
            $cdr_archive_outbound_dialer = $outbound_dialer[1]->totalOutBoundCallsByDialer;
        }
        else
        {
            $cdr_archive_outbound_dialer =0;
        }

        $result_arr['total_outbound_Calls_dialer'] = $cdr_outbound_dialer + $cdr_archive_outbound_dialer;


         $outbound_dialer_c2c = DB::connection($connection)->select("select count(*) as totalOutBoundCallsByDialer from cdr WHERE route= 'OUT' and type= 'c2c' and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "' $extCondition union select count(*) as totalOutBoundCallsByDialer from cdr_archive WHERE route= 'OUT' and type= 'c2c' and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "' $extCondition");



        $cdr_outbound_dialer = $outbound_dialer_c2c[0]->totalOutBoundCallsByDialer;

        if(!empty($outbound_dialer_c2c[1]->totalOutBoundCallsByDialer))
        {
            $cdr_archive_outbound_dialer = $outbound_dialer_c2c[1]->totalOutBoundCallsByDialer;
        }
        else
        {
            $cdr_archive_outbound_dialer =0;
        }

        $result_arr['total_outbound_Calls_c2c'] = $cdr_outbound_dialer + $cdr_archive_outbound_dialer;

        $sql = "select campaign_id, count(*) as calls, title from cdr as cdr left join campaign on cdr.campaign_id=campaign.id WHERE start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "' and cdr.campaign_id is not null and route='OUT' $extCondition group by campaign_id";
        $outbound_campaign = DB::connection($connection)->select($sql);
        $outbound_campaign = (array)$outbound_campaign;

    

        $i = 0;
        foreach ( $outbound_campaign as $key => $campaign_calls ) {
            //echo "<pre>";print_r($campaign_calls);
            $result_arr['campaign'][$key]['title'] = $campaign_calls->title?$campaign_calls->title:"Manual Calls";
            $result_arr['campaign'][$key]['calls'] = $campaign_calls->calls;

            $sql_disposition = "select count(*) as disposition,disposition_id,title from cdr_archive as cdr left join disposition on cdr.disposition_id=disposition.id WHERE campaign_id='".$campaign_calls->campaign_id."' and  start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "' group by disposition_id";
         $disposition_id = DB::connection($connection)->select($sql_disposition);

            $result_arr['campaign'][$key]['disposition'] = $disposition_id;
             foreach($disposition_id as $id)
                {
            $result_arr['campaign'][$key]['dispositions'][$i] = $id->disposition_id;

            $i++;
                }
        }


          $sql = "select campaign_id, count(*) as calls, title from cdr_archive as cdr left join campaign on cdr.campaign_id=campaign.id WHERE start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "' and cdr.campaign_id is not null and route='OUT' $extCondition group by campaign_id";
        $outbound_campaign = DB::connection($connection)->select($sql);
        $outbound_campaign = (array)$outbound_campaign;

       

        $i = 0;
        foreach ( $outbound_campaign as $key => $campaign_calls ) {
            //echo "<pre>";print_r($campaign_calls);
            $result_arr['campaign'][$key]['title'] = $campaign_calls->title?$campaign_calls->title:"Manual Calls";
            $result_arr['campaign'][$key]['calls'] = $campaign_calls->calls;


            $sql_extension = "select extension, count(*) as extension_total from cdr_archive as cdr WHERE campaign_id='".$campaign_calls->campaign_id."' and  start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "' group by extension";

         $user_id = DB::connection($connection)->select($sql_extension);
            $a=0;

            foreach($user_id as  $user)
            {
             $sql_user = "select * from users WHERE extension ='".$user->extension."' OR alt_extension='".$user->extension."' limit 1";
        $agent_list_user = DB::connection("master")->select($sql_user);

            $result_arr['campaign'][$key]['ext'][$a]['name'] = $agent_list_user[0]->first_name;
            $result_arr['campaign'][$key]['ext'][$a]['sql'] = $sql_user;
            $result_arr['campaign'][$key]['ext'][$a]['ss'] = $a;


            $result_arr['campaign'][$key]['ext'][$a]['alt_extension'] = $agent_list_user[0]->alt_extension;
            $result_arr['campaign'][$key]['ext'][$a]['extension'] = $agent_list_user[0]->extension;
            $result_arr['campaign'][$key]['ext'][$a]['extension_total'] = $user->extension_total;


            $a++;


            }
        $sql_disposition = "select count(*) as disposition,disposition_id,title from cdr_archive as cdr left join disposition on cdr.disposition_id=disposition.id WHERE campaign_id='".$campaign_calls->campaign_id."' and  start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "' group by disposition_id";
         $disposition_id = DB::connection($connection)->select($sql_disposition);

            $result_arr['campaign'][$key]['disposition'] = $disposition_id;

            foreach($disposition_id as $id)
                {
            $result_arr['campaign'][$key]['dispositions'][$i] = $id->disposition_id;

            $i++;
                }
            
        }




        $inbound_res = DB::connection($connection)->select("select count(*) as totalInBoundCalls from cdr WHERE route  = 'IN' and type = 'manual' and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "' $extCondition union select count(*) as totalInBoundCalls from cdr_archive WHERE route  = 'IN' and type = 'manual' and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "' $extCondition");

       
        $cdr_inbound_res =  $inbound_res[0]->totalInBoundCalls;
          if(!empty($inbound_res[1]->totalInBoundCalls))
        {
            $cdr_archive_inbound_res = $inbound_res[1]->totalInBoundCalls;
        }
        else
        {
            $cdr_archive_inbound_res =0;
        }
        $inbound_res = DB::connection($connection)->select("select count(*) as totalInBoundCalls from cdr WHERE route  = 'IN' and type = 'manual' and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "' $cliCondition union select count(*) as totalInBoundCalls from cdr_archive WHERE route  = 'IN' and type = 'manual' and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "' $cliCondition");

       
        $cdr_inbound_res_cli =  $inbound_res[0]->totalInBoundCalls;
          if(!empty($inbound_res[1]->totalInBoundCalls))
        {
            $cdr_archive_inbound_res_cli = $inbound_res[1]->totalInBoundCalls;
        }
        else
        {
            $cdr_archive_inbound_res_cli =0;
        }

        $result_arr['total_inbound_Calls'] = $cdr_inbound_res_cli + $cdr_archive_inbound_res_cli + $cdr_inbound_res + $cdr_archive_inbound_res;

        #todo: extension column in sms table not having proper value
        $sms_send = DB::connection($connection)->select("select count(*) as totalSMSSend from sms WHERE type  = :type and date >= '" . $previous_day . "' and date <= '" . $current_day . "'", array('type' => 'outgoing'));

        if (!empty($sms_send)) {
            $result_arr['total_sms_send'] = $sms_send[0]->totalSMSSend;
        } else {
            $result_arr['total_sms_send'] = 0;
        }

        #todo: extension column in sms table not having proper value
        $sms_received = DB::connection($connection)->select("select count(*) as totalSMSReceive from sms WHERE type  = :type and date >= '" . $previous_day . "' and date <= '" . $current_day . "'", array('type' => 'incoming'));

        $result_arr['total_sms_receive'] = $sms_received[0]->totalSMSReceive;

        $agent_list = array();

        $sql = "select * from users WHERE parent_id=".$request->client_id." AND is_deleted=0 $extCondition order by first_name";
        $agent_list = DB::connection("master")->select($sql);

        $j = 0;
        if(!empty($agent_list))
        {
            foreach ( $agent_list as $agent )
            {
                $agent_list_calls = (array)$agent;
                $alt_extension = $agent_list_calls['alt_extension'];
                $extension = $agent_list_calls['extension'];
                $result_arr['agent'][$j]['extension'] = $extension;
                $result_arr['agent'][$j]['alt_extension'] = $alt_extension;

                $result_arr['agent'][$j]['agentName'] = $agent_list_calls['first_name'] . ' ' . $agent_list_calls['last_name'];

                $totalCall = 0;

                //cdr

                $agent_call_list_out = DB::connection($connection)->select("select count(*) as totalOutCalls from cdr WHERE extension IN('" . $extension . "','" . $alt_extension . "') and route = :route and (type = :type or type = :type1) and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'", array('route' => 'OUT', 'type' => 'dialer', 'type1' => 'manual'));
                $result_arr['agent'][$j]['outbound'] = $agent_call_list_out[0]->totalOutCalls;
                $totalCall += $agent_call_list_out[0]->totalOutCalls;


                $agent_call_list_out = DB::connection($connection)->select("select count(*) as totalOutCalls from cdr_archive WHERE extension IN('" . $extension . "','" . $alt_extension . "') and route = :route and  (type = :type or type = :type1) and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'", array('route' => 'OUT' , 'type' => 'dialer', 'type1' => 'manual'));
                $result_arr['agent'][$j]['outbound'] = $agent_call_list_out[0]->totalOutCalls;
                $totalCall += $agent_call_list_out[0]->totalOutCalls;

                $agent_call_list_out_c2c = DB::connection($connection)->select("select count(*) as totalOutCalls from cdr WHERE extension IN('" . $extension . "','" . $alt_extension . "') and route = :route and type = :type and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'", array('route' => 'OUT', 'type' => 'c2c'));
                $result_arr['agent'][$j]['c2c'] = $agent_call_list_out_c2c[0]->totalOutCalls;
                $totalCall += $agent_call_list_out_c2c[0]->totalOutCalls;


                $agent_call_list_out_c2c = DB::connection($connection)->select("select count(*) as totalOutCalls from cdr_archive WHERE extension IN('" . $extension . "','" . $alt_extension . "') and route = :route and  type = :type and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'", array('route' => 'OUT' , 'type' => 'c2c'));
                $result_arr['agent'][$j]['c2c'] = $agent_call_list_out_c2c[0]->totalOutCalls;
                $totalCall += $agent_call_list_out_c2c[0]->totalOutCalls;

                $agent_call_list_in = DB::connection($connection)->select("select count(*) as totalInCalls from cdr WHERE extension IN('" . $extension . "','" . $alt_extension . "') and route = :route and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'", array('route' => 'IN'));
                $result_arr['agent'][$j]['inbound'] = $agent_call_list_in[0]->totalInCalls;
                $totalCall += $agent_call_list_in[0]->totalInCalls;

                $agent_call_list_in = DB::connection($connection)->select("select count(*) as totalInCalls from cdr_archive WHERE extension IN('" . $extension . "','" . $alt_extension . "') and route = :route and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'", array('route' => 'IN'));
                $result_arr['agent'][$j]['inbound'] = $agent_call_list_in[0]->totalInCalls;
                $totalCall += $agent_call_list_in[0]->totalInCalls;


                $agent_call_list = DB::connection($connection)->select("select sum(duration) as totalDuration from cdr WHERE extension IN('" . $extension . "','" . $alt_extension . "') and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'");

                $duration = $agent_call_list[0]->totalDuration;

                $agent_call_list = DB::connection($connection)->select("select sum(duration) as totalDuration from cdr_archive WHERE extension IN('" . $extension . "','" . $alt_extension . "') and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'");
                $result_arr['agent'][$j]['duration'] = $duration + $agent_call_list[0]->totalDuration;

                $result_arr['agent'][$j]['totalcalls'] = $totalCall;

                $result_arr['agent'][$j]['aht'] = $totalCall?($agent_call_list[0]->totalDuration / $totalCall):0;

                //sms incoming and outgoing record code

                $filter_sms = " WHERE date >= '".$previous_day."' AND date <= '".$current_day."' AND extension='".$agent_list_calls['id']."'";

                $sql_sms = DB::connection($connection)->select("SELECT  SUM(type = 'outgoing') AS outgoing, 
                SUM(type = 'incoming') AS incoming,extension FROM sms $filter_sms");

                $result_arr['agent'][$j]['incoming']  = $sql_sms[0]->incoming > 0 ? $sql_sms[0]->incoming : 0;
                $result_arr['agent'][$j]['outgoing']  = $sql_sms[0]->outgoing > 0 ? $sql_sms[0]->outgoing : 0;           
                $j++;

            }
        }
            $areacode_list =array();
            $sql_areacode = "select count(*) as total,area_code from cdr WHERE  start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "' group by area_code order by total desc";

            $areacode_list_cdr = DB::connection($connection)->select($sql_areacode);


            $sql_areacode_cdr_archive= "select count(*) as total,area_code from cdr_archive WHERE  start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "' group by area_code order by total desc";

            $areacode_list_cdr_archive = DB::connection($connection)->select($sql_areacode_cdr_archive);


            $areacode_list = array_merge($areacode_list_cdr,$areacode_list_cdr_archive);
            $k = 0;
            if(!empty($areacode_list))
            {
                foreach ( $areacode_list as $code )
                {
                    $areacode_calls = (array)$code;


                     $did = "SELECT * from did where area_code = '".$areacode_calls['area_code']."'";

                    $did_data =  DB::connection($connection)->selectOne($did);

                    if(!empty($did_data))
                    {
                        $cname = $did_data->cli;

                        $cnam = DB::connection($connection)->selectOne("select cnam from cli_report where cli='".$cname."' order by id desc limit 1");

                            if(!empty($cnam))
                            {
                                $result_arr['city_wise'][$k]['cnam'] = $cnam->cnam;
                            }
                            else
                            {
                                $result_arr['city_wise'][$k]['cnam'] = '-';
                            }
                    }
                    else
                    {
                        $cname ='';
                        $result_arr['city_wise'][$k]['cnam'] = '-';
                    }
                    $find_city_state = "SELECT * from areacode_city where areacode = '".$areacode_calls['area_code']."'";

                    $record_city =  DB::connection("master")->selectOne($find_city_state);
                    if(!empty($record_city))
                    {
                        $total = $areacode_calls['total'];
                        $area_code = $areacode_calls['area_code'];
                        $city = $record_city->city_name;
                        $state = $record_city->state_name;
                        $result_arr['city_wise'][$k]['total'] = $total;
                        $result_arr['city_wise'][$k]['area_code'] = $area_code;
                        $result_arr['city_wise'][$k]['city'] = $city;
                        $result_arr['city_wise'][$k]['state'] = $state;
                        $result_arr['city_wise'][$k]['did'] = $cname;

                    }
                    else
                    {

                        $total = $areacode_calls['total'];
                        $area_code = $areacode_calls['area_code'];
                        
                        $result_arr['city_wise'][$k]['total'] = $total;
                        $result_arr['city_wise'][$k]['area_code'] = $area_code;
                        $result_arr['city_wise'][$k]['city'] = '-';
                        $result_arr['city_wise'][$k]['state'] = '-';
                        $result_arr['city_wise'][$k]['did'] = '-';

                    }
                        $k++;
                }
            }

            $did_list =array();
            $sql_did = "select * from did WHERE parent_id=".$request->client_id."";
            $did_list = DB::connection("master")->select($sql_did);

            $d = 0;

            if(!empty($did_list))
            {
                foreach ($did_list as $did )
                {
                    $did_list_calls = (array)$did;
                    $did_number = $did->cli;

                    $totalCall = 0;
                    $did_call_list_in = DB::connection($connection)->select("select count(*) as totalInCalls from cdr WHERE cli = '" . $did_number. "' and route = :route and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'", array('route' => 'IN'));
                    if(!empty($did_call_list_in))
                    {
                        if($did_call_list_in[0]->totalInCalls !=0)
                        {
                            $result_arr['did'][$d]['inbound'] = $did_call_list_in[0]->totalInCalls;
                            $result_arr['did'][$d]['cli'] = $did_number;
                            $totalCall += $did_call_list_in[0]->totalInCalls;

                            $cnam = DB::connection($connection)->selectOne("select cnam from cli_report where cli='".$did_number."' order by id desc limit 1");

                            if(!empty($cnam))
                            {
                                $result_arr['did'][$d]['cnam'] = $cnam->cnam;
                            }
                            else
                            {
                                $result_arr['did'][$d]['cnam'] = '-';
                            }

                            $did_call_list = DB::connection($connection)->select("select sum(duration) as totalDuration from cdr WHERE cli = '" . $did_number. "' and route = :route and  start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'", array('route' => 'IN'));
                            $result_arr['did'][$d]['duration'] = $did_call_list[0]->totalDuration;

                            $result_arr['did'][$d]['totalcalls'] = $totalCall;

                            $result_arr['did'][$d]['aht'] = $totalCall?($did_call_list[0]->totalDuration / $totalCall):0;

                            //sms incoming and outgoing record code

                            $filter_sms = " WHERE date >= '".$previous_day."' AND date <= '".$current_day."' AND did='".$did_number."'";

                            $sql_sms = DB::connection($connection)->select("SELECT  SUM(type = 'outgoing') AS outgoing, 
                                SUM(type = 'incoming') AS incoming,extension FROM sms $filter_sms");

                            $result_arr['did'][$d]['incoming']  = $sql_sms[0]->incoming > 0 ? $sql_sms[0]->incoming : 0;
                            $result_arr['did'][$d]['outgoing']  = $sql_sms[0]->outgoing > 0 ? $sql_sms[0]->outgoing : 0;           
                            $d++;
                        }
                    }

                    //cdr_archive

                    $did_call_list_in = DB::connection($connection)->select("select count(*) as totalInCalls from cdr_archive WHERE cli = '" . $did_number. "' and route = :route and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'", array('route' => 'IN'));
                    if(!empty($did_call_list_in))
                    {
                        if($did_call_list_in[0]->totalInCalls !=0)
                        {
                            $result_arr['did'][$d]['inbound'] = $did_call_list_in[0]->totalInCalls;
                            $result_arr['did'][$d]['cli'] = $did_number;
                            $totalCall += $did_call_list_in[0]->totalInCalls;

                            $cnam = DB::connection($connection)->selectOne("select cnam from cli_report where cli='".$did_number."' order by id desc limit 1");

                            if(!empty($cnam))
                            {
                                $result_arr['did'][$d]['cnam'] = $cnam->cnam;
                            }
                            else
                            {
                                $result_arr['did'][$d]['cnam'] = '-';
                            }

                            $did_call_list = DB::connection($connection)->select("select sum(duration) as totalDuration from cdr_archive WHERE cli = '" . $did_number. "' and route = :route and  start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'", array('route' => 'IN'));
                            $result_arr['did'][$d]['duration'] = $did_call_list[0]->totalDuration;

                            $result_arr['did'][$d]['totalcalls'] = $totalCall;

                            $result_arr['did'][$d]['aht'] = $totalCall?($did_call_list[0]->totalDuration / $totalCall):0;

                            //sms incoming and outgoing record code

                            $filter_sms = " WHERE date >= '".$previous_day."' AND date <= '".$current_day."' AND did='".$did_number."'";

                            $sql_sms = DB::connection($connection)->select("SELECT  SUM(type = 'outgoing') AS outgoing, 
                                SUM(type = 'incoming') AS incoming,extension FROM sms $filter_sms");

                            $result_arr['did'][$d]['incoming']  = $sql_sms[0]->incoming > 0 ? $sql_sms[0]->incoming : 0;
                            $result_arr['did'][$d]['outgoing']  = $sql_sms[0]->outgoing > 0 ? $sql_sms[0]->outgoing : 0;           
                            $d++;
                        }
                    }
                }
            }       
            return $result_arr;
    }


    public function dailyCallReport($objUser): array
    {
        $connection = 'mysql_' . $this->clientId;
        $result_arr = array();
        //$previous_day = date("Y-m-d") . ' 00:00:00';
        //$current_day = date("Y-m-d") . ' 22:00:00';

       /* $previous_day = date("Y-m-d", strtotime(" -1 day")) . ' 22:00:00';
        $current_day = date("Y-m-d") . ' 22:00:00';*/

        $previous_day = date("Y-m-d", strtotime(" -1 day")) . ' 00:00:00';
        $current_day = date("Y-m-d") . ' 22:00:00';
        //$current_day = date("Y-m-d", strtotime(" -1 day")) . ' 23:59:59';


       /* $previous_day = '2023-04-19 00:00:00';
        $current_day = '2023-04-19 22:00:00';*/


        

        $client = Client::find($this->clientId);
        $result_arr['start_time'] = $previous_day;
        $result_arr['end_time'] = $current_day;

      //  return $result_arr;

        $result_arr['logo'] = env('PORTAL_NAME').'logo/' . $client->logo;
        $result_arr['company_name'] = $client->company_name;
        
        $user = User::where("email", "=", $objUser->email)->first();
        $groups = ExtensionGroupService::getExtensionGroups($user->parent_id, $user->extension);

        if ($user->user_level >= 5) {
            $extCondition = "";
        } elseif ($user->user_level >= 5) {
            $extensions = ExtensionGroupService::getExtensionsByGroups($user->parent_id, $groups);
            if (empty($extensions)) $extensions = [$user->extension];
            $extCondition = " AND extension IN (".implode(",", $extensions).")";
        } else {
            $extCondition = " AND extension = ".$user->extension;
        }

        $outbound_res = DB::connection($connection)->select("select count(*) as totalOutBoundCalls from cdr WHERE route  = 'OUT' and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "' $extCondition union select count(*) as totalOutBoundCalls from cdr_archive  WHERE route  = 'OUT' and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "' $extCondition");

        $cdr_outbound = $outbound_res[0]->totalOutBoundCalls;

        if(!empty($outbound_res[1]->totalOutBoundCalls))
        {
            $cdr_archive_outbound = $outbound_res[1]->totalOutBoundCalls;
        }
        else
        {
            $cdr_archive_outbound =0;
        }

        $result_arr['total_outbound_Calls'] = $cdr_outbound + $cdr_archive_outbound;

        $outbound_manually = DB::connection($connection)->select("select count(*) as totalOutBoundCallsByManually from cdr WHERE route= 'OUT' and type= 'manual' and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "' $extCondition union select count(*) as totalOutBoundCallsByManually from cdr_archive  WHERE route= 'OUT' and type= 'manual' and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "' $extCondition");

        $cdr_outbound_manually = $outbound_manually[0]->totalOutBoundCallsByManually;

        if(!empty($outbound_manually[1]->totalOutBoundCallsByManually))
        {
            $cdr_archive_outbound_manually = $outbound_manually[1]->totalOutBoundCallsByManually;
        }
        else
        {
            $cdr_archive_outbound_manually =0;
        }

        $result_arr['total_outbound_Calls_manually'] = $cdr_outbound_manually + $cdr_archive_outbound_manually;

        $outbound_dialer = DB::connection($connection)->select("select count(*) as totalOutBoundCallsByDialer from cdr WHERE route= 'OUT' and type= 'dialer' and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "' $extCondition union select count(*) as totalOutBoundCallsByDialer from cdr_archive WHERE route= 'OUT' and type= 'dialer' and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "' $extCondition");

        $cdr_outbound_dialer = $outbound_dialer[0]->totalOutBoundCallsByDialer;

        if(!empty($outbound_dialer[1]->totalOutBoundCallsByDialer))
        {
            $cdr_archive_outbound_dialer = $outbound_dialer[1]->totalOutBoundCallsByDialer;
        }
        else
        {
            $cdr_archive_outbound_dialer =0;
        }

        $result_arr['total_outbound_Calls_dialer'] = $cdr_outbound_dialer + $cdr_archive_outbound_dialer;


         $outbound_dialer_c2c = DB::connection($connection)->select("select count(*) as totalOutBoundCallsByDialer from cdr WHERE route= 'OUT' and type= 'c2c' and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "' $extCondition union select count(*) as totalOutBoundCallsByDialer from cdr_archive WHERE route= 'OUT' and type= 'c2c' and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "' $extCondition");

        $cdr_outbound_dialer = $outbound_dialer_c2c[0]->totalOutBoundCallsByDialer;

        if(!empty($outbound_dialer_c2c[1]->totalOutBoundCallsByDialer))
        {
            $cdr_archive_outbound_dialer = $outbound_dialer_c2c[1]->totalOutBoundCallsByDialer;
        }
        else
        {
            $cdr_archive_outbound_dialer =0;
        }

        $result_arr['total_outbound_Calls_c2c'] = $cdr_outbound_dialer + $cdr_archive_outbound_dialer;


         $inbound_res = DB::connection($connection)->select("select count(*) as totalInBoundCalls from cdr WHERE route  = 'IN' and type = 'manual' and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "' $extCondition union select count(*) as totalInBoundCalls from cdr_archive WHERE route  = 'IN' and type = 'manual' and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "' $extCondition");

        $cdr_inbound_res =  $inbound_res[0]->totalInBoundCalls;
        if(!empty($inbound_res[1]->totalInBoundCalls))
        {
            $cdr_archive_inbound_res = $inbound_res[1]->totalInBoundCalls;
        }
        else
        {
            $cdr_archive_inbound_res =0;
        }

        $result_arr['total_inbound_Calls'] = $cdr_inbound_res + $cdr_archive_inbound_res;


          $sms_ai_outgoing = DB::connection($connection)->select("select count(sms_type) as totalSend from sms_ai WHERE sms_type= :sms_type and created_at >= '" . $previous_day . "' and created_at <= '" . $current_day . "'", array('sms_type' => 'outgoing'));
                $sms_ai_outgoing_total = $sms_ai_outgoing[0]->totalSend;


                $sms_ai_incoming = DB::connection($connection)->select("select count(sms_type) as totalSend from sms_ai WHERE sms_type= :sms_type and created_at >= '" . $previous_day . "' and created_at <= '" . $current_day . "'", array('sms_type' => 'incoming'));
                $sms_ai_incoming_total = $sms_ai_incoming[0]->totalSend;


                $result_arr['outgoing'] = $sms_ai_outgoing_total > 0 ? $sms_ai_outgoing_total : 0;
                $result_arr['incoming'] = $sms_ai_incoming_total > 0 ? $sms_ai_incoming_total : 0;



        $sql = "select campaign_id, count(*) as calls, title from cdr as cdr left join campaign on cdr.campaign_id=campaign.id WHERE start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "' and cdr.campaign_id is not null and route='OUT' $extCondition group by campaign_id";
        $outbound_campaign = DB::connection($connection)->select($sql);
        $outbound_campaign = (array)$outbound_campaign;

        $i = 0;
        foreach ( $outbound_campaign as $key => $campaign_calls )
        {
            //echo "<pre>";print_r($campaign_calls);
            $result_arr['campaign'][$key]['title'] = $campaign_calls->title?$campaign_calls->title:"Manual Calls";
            $result_arr['campaign'][$key]['calls'] = $campaign_calls->calls;

            $sql_disposition = "select count(*) as disposition,disposition_id,title from cdr as cdr left join disposition on cdr.disposition_id=disposition.id WHERE campaign_id='".$campaign_calls->campaign_id."' and  start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "' group by disposition_id";
            $disposition_id = DB::connection($connection)->select($sql_disposition);

            $result_arr['campaign'][$key]['disposition'] = $disposition_id;
            $i++;
        }

        $sql = "select campaign_id, count(*) as calls, title from cdr_archive as cdr left join campaign on cdr.campaign_id=campaign.id WHERE start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "' and cdr.campaign_id is not null and route='OUT' $extCondition group by campaign_id";

        $outbound_campaign = DB::connection($connection)->select($sql);
        $outbound_campaign = (array)$outbound_campaign;

        if(!empty($outbound_campaign)){
            
        $i = 0;
        foreach ( $outbound_campaign as $key => $campaign_calls )
        {
            //echo "<pre>";print_r($campaign_calls);
            $result_arr['campaign'][$key]['title'] = $campaign_calls->title?$campaign_calls->title:"Manual Calls";
            $result_arr['campaign'][$key]['calls'] = $campaign_calls->calls;
            $sql_disposition = "select count(*) as disposition,disposition_id,title from cdr_archive as cdr left join disposition on cdr.disposition_id=disposition.id WHERE campaign_id='".$campaign_calls->campaign_id."' and  start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "' group by disposition_id";
            $disposition_id = DB::connection($connection)->select($sql_disposition);
            $result_arr['campaign'][$key]['disposition'] = $disposition_id;
            $i++;
        }
        }


        #todo: extension column in sms table not having proper value
        $sms_send = DB::connection($connection)->select("select count(*) as totalSMSSend from sms WHERE type  = :type and date >= '" . $previous_day . "' and date <= '" . $current_day . "'", array('type' => 'outgoing'));

        if (!empty($sms_send)) {
            $result_arr['total_sms_send'] = $sms_send[0]->totalSMSSend;
        } else {
            $result_arr['total_sms_send'] = 0;
        }

        #todo: extension column in sms table not having proper value
        $sms_received = DB::connection($connection)->select("select count(*) as totalSMSReceive from sms WHERE type  = :type and date >= '" . $previous_day . "' and date <= '" . $current_day . "'", array('type' => 'incoming'));

        $result_arr['total_sms_receive'] = $sms_received[0]->totalSMSReceive;

        $agent_list = array();

        $sql = "select * from users WHERE parent_id=".$this->clientId." AND is_deleted=0 $extCondition order by first_name";
        $agent_list = DB::connection("master")->select($sql);

        $j = 0;
        if(!empty($agent_list))
        {
            foreach ( $agent_list as $agent )
            {
                $agent_list_calls = (array)$agent;
                $alt_extension = $agent_list_calls['alt_extension'];
                $extension = $agent_list_calls['extension'];
                $result_arr['agent'][$j]['extension'] = $extension;
                $result_arr['agent'][$j]['alt_extension'] = $alt_extension;

                $result_arr['agent'][$j]['agentName'] = $agent_list_calls['first_name'] . ' ' . $agent_list_calls['last_name'];

                $totalCall = 0;

                //cdr

                $agent_call_list_out = DB::connection($connection)->select("select count(*) as totalOutCalls from cdr WHERE extension IN('" . $extension . "','" . $alt_extension . "') and route = :route and (type = :type or type = :type1)  and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'", array('route' => 'OUT', 'type' => 'dialer','type1' => 'manual'));
                $result_arr['agent'][$j]['outbound'] = $agent_call_list_out[0]->totalOutCalls;
                $totalCall += $agent_call_list_out[0]->totalOutCalls;


                $agent_call_list_out = DB::connection($connection)->select("select count(*) as totalOutCalls from cdr_archive WHERE extension IN('" . $extension . "','" . $alt_extension . "') and route = :route and (type = :type or type = :type1) and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'", array('route' => 'OUT', 'type' => 'dialer','type1' => 'manual'));
                $result_arr['agent'][$j]['outbound'] = $agent_call_list_out[0]->totalOutCalls;
                $totalCall += $agent_call_list_out[0]->totalOutCalls;


                $agent_call_list_out_c2c = DB::connection($connection)->select("select count(*) as totalOutCalls from cdr WHERE extension IN('" . $extension . "','" . $alt_extension . "') and route = :route and type = :type and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'", array('route' => 'OUT', 'type' => 'c2c'));
                $result_arr['agent'][$j]['c2c'] = $agent_call_list_out_c2c[0]->totalOutCalls;
                $totalCall += $agent_call_list_out_c2c[0]->totalOutCalls;


                $agent_call_list_out_c2c = DB::connection($connection)->select("select count(*) as totalOutCalls from cdr_archive WHERE extension IN('" . $extension . "','" . $alt_extension . "') and route = :route and type = :type and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'", array('route' => 'OUT', 'type' => 'c2c'));
                $result_arr['agent'][$j]['c2c'] = $agent_call_list_out_c2c[0]->totalOutCalls;
                $totalCall += $agent_call_list_out_c2c[0]->totalOutCalls;

                $agent_call_list_in = DB::connection($connection)->select("select count(*) as totalInCalls from cdr WHERE extension IN('" . $extension . "','" . $alt_extension . "') and route = :route and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'", array('route' => 'IN'));
                $result_arr['agent'][$j]['inbound'] = $agent_call_list_in[0]->totalInCalls;
                $totalCall += $agent_call_list_in[0]->totalInCalls;

                $agent_call_list_in = DB::connection($connection)->select("select count(*) as totalInCalls from cdr_archive WHERE extension IN('" . $extension . "','" . $alt_extension . "') and route = :route and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'", array('route' => 'IN'));
                $result_arr['agent'][$j]['inbound'] = $agent_call_list_in[0]->totalInCalls;
                $totalCall += $agent_call_list_in[0]->totalInCalls;


                $agent_call_list = DB::connection($connection)->select("select sum(duration) as totalDuration from cdr WHERE extension IN('" . $extension . "','" . $alt_extension . "') and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'");

                $duration = $agent_call_list[0]->totalDuration;

                $agent_call_list = DB::connection($connection)->select("select sum(duration) as totalDuration from cdr_archive WHERE extension IN('" . $extension . "','" . $alt_extension . "') and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'");
                $result_arr['agent'][$j]['duration'] = $duration + $agent_call_list[0]->totalDuration;

                $result_arr['agent'][$j]['totalcalls'] = $totalCall;

                $result_arr['agent'][$j]['aht'] = $totalCall?($agent_call_list[0]->totalDuration / $totalCall):0;

                //sms incoming and outgoing record code

                $filter_sms = " WHERE date >= '".$previous_day."' AND date <= '".$current_day."' AND extension='".$agent_list_calls['id']."'";

                $sql_sms = DB::connection($connection)->select("SELECT  SUM(type = 'outgoing') AS outgoing, 
                SUM(type = 'incoming') AS incoming,extension FROM sms $filter_sms");

                $result_arr['agent'][$j]['incoming']  = $sql_sms[0]->incoming > 0 ? $sql_sms[0]->incoming : 0;
                $result_arr['agent'][$j]['outgoing']  = $sql_sms[0]->outgoing > 0 ? $sql_sms[0]->outgoing : 0;           
                $j++;

            }
        }

        
        //city wise send daily report
        $areacode_list =array();
        $sql_areacode = "select count(*) as total,area_code from cdr WHERE  start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "' group by area_code order by total desc";

        $areacode_list_cdr = DB::connection($connection)->select($sql_areacode);


        $sql_areacode_cdr_archive= "select count(*) as total,area_code from cdr_archive WHERE  start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "' group by area_code order by total desc";

        $areacode_list_cdr_archive = DB::connection($connection)->select($sql_areacode_cdr_archive);


        $areacode_list = array_merge($areacode_list_cdr,$areacode_list_cdr_archive);



        $k = 0;
        if(!empty($areacode_list))
        {
            foreach ( $areacode_list as $code )
                {
                    $areacode_calls = (array)$code;


                     $did = "SELECT * from did where area_code = '".$areacode_calls['area_code']."'";

                    $did_data =  DB::connection($connection)->selectOne($did);

                    if(!empty($did_data))
                    {
                        $cname = $did_data->cli;

                        $cnam = DB::connection($connection)->selectOne("select cnam from cli_report where cli='".$cname."' order by id desc limit 1");

                            if(!empty($cnam))
                            {
                                $result_arr['city_wise'][$k]['cnam'] = $cnam->cnam;
                            }
                            else
                            {
                                $result_arr['city_wise'][$k]['cnam'] = '-';
                            }
                    }
                    else
                    {
                        $cname ='';
                        $result_arr['city_wise'][$k]['cnam'] = '-';
                    }

          


                    $find_city_state = "SELECT * from areacode_city where areacode = '".$areacode_calls['area_code']."'";

                    $record_city =  DB::connection("master")->selectOne($find_city_state);
                    if(!empty($record_city))
                    {
                        $total = $areacode_calls['total'];
                        $area_code = $areacode_calls['area_code'];
                        $city = $record_city->city_name;
                        $state = $record_city->state_name;
                        $result_arr['city_wise'][$k]['total'] = $total;
                        $result_arr['city_wise'][$k]['area_code'] = $area_code;
                        $result_arr['city_wise'][$k]['city'] = $city;
                        $result_arr['city_wise'][$k]['state'] = $state;
                        $result_arr['city_wise'][$k]['did'] = $cname;

                       
                    }

                    else
                    {

                        $total = $areacode_calls['total'];
                        $area_code = $areacode_calls['area_code'];
                        
                        $result_arr['city_wise'][$k]['total'] = $total;
                        $result_arr['city_wise'][$k]['area_code'] = $area_code;
                        $result_arr['city_wise'][$k]['city'] = '-';
                        $result_arr['city_wise'][$k]['state'] = '-';
                        $result_arr['city_wise'][$k]['did'] = '-';

                    }
                        $k++;
                }
            }

            ///did wise calls

            $did_list =array();
            $sql_did = "select * from did WHERE parent_id=".$this->clientId."";
            $did_list = DB::connection("master")->select($sql_did);

            $d = 0;

            if(!empty($did_list))
            {
                foreach ($did_list as $did )
                {
                    $did_list_calls = (array)$did;
                    $did_number = $did->cli;

                    $totalCall = 0;
                    $did_call_list_in = DB::connection($connection)->select("select count(*) as totalInCalls from cdr WHERE cli = '" . $did_number. "' and route = :route and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'", array('route' => 'IN'));
                    if(!empty($did_call_list_in))
                    {
                        if($did_call_list_in[0]->totalInCalls !=0)
                        {
                            $result_arr['did'][$d]['inbound'] = $did_call_list_in[0]->totalInCalls;
                            $result_arr['did'][$d]['cli'] = $did_number;
                            $totalCall += $did_call_list_in[0]->totalInCalls;

                            $cnam = DB::connection($connection)->selectOne("select cnam from cli_report where cli='".$did_number."' order by id desc limit 1");

                            if(!empty($cnam))
                            {
                                $result_arr['did'][$d]['cnam'] = $cnam->cnam;
                            }
                            else
                            {
                                $result_arr['did'][$d]['cnam'] = '-';
                            }

                            $did_call_list = DB::connection($connection)->select("select sum(duration) as totalDuration from cdr WHERE cli = '" . $did_number. "' and route = :route and  start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'", array('route' => 'IN'));
                            $result_arr['did'][$d]['duration'] = $did_call_list[0]->totalDuration;

                            $result_arr['did'][$d]['totalcalls'] = $totalCall;

                            $result_arr['did'][$d]['aht'] = $totalCall?($did_call_list[0]->totalDuration / $totalCall):0;

                            //sms incoming and outgoing record code

                            $filter_sms = " WHERE date >= '".$previous_day."' AND date <= '".$current_day."' AND did='".$did_number."'";

                            $sql_sms = DB::connection($connection)->select("SELECT  SUM(type = 'outgoing') AS outgoing, 
                                SUM(type = 'incoming') AS incoming,extension FROM sms $filter_sms");

                            $result_arr['did'][$d]['incoming']  = $sql_sms[0]->incoming > 0 ? $sql_sms[0]->incoming : 0;
                            $result_arr['did'][$d]['outgoing']  = $sql_sms[0]->outgoing > 0 ? $sql_sms[0]->outgoing : 0;           
                            $d++;
                        }
                    }

                    //cdr_archive

                    $did_call_list_in = DB::connection($connection)->select("select count(*) as totalInCalls from cdr_archive WHERE cli = '" . $did_number. "' and route = :route and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'", array('route' => 'IN'));
                    if(!empty($did_call_list_in))
                    {
                        if($did_call_list_in[0]->totalInCalls !=0)
                        {
                            $result_arr['did'][$d]['inbound'] = $did_call_list_in[0]->totalInCalls;
                            $result_arr['did'][$d]['cli'] = $did_number;
                            $totalCall += $did_call_list_in[0]->totalInCalls;

                            $cnam = DB::connection($connection)->selectOne("select cnam from cli_report where cli='".$did_number."' order by id desc limit 1");

                            if(!empty($cnam))
                            {
                                $result_arr['did'][$d]['cnam'] = $cnam->cnam;
                            }
                            else
                            {
                                $result_arr['did'][$d]['cnam'] = '-';
                            }

                            $did_call_list = DB::connection($connection)->select("select sum(duration) as totalDuration from cdr_archive WHERE cli = '" . $did_number. "' and route = :route and  start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'", array('route' => 'IN'));
                            $result_arr['did'][$d]['duration'] = $did_call_list[0]->totalDuration;

                            $result_arr['did'][$d]['totalcalls'] = $totalCall;

                            $result_arr['did'][$d]['aht'] = $totalCall?($did_call_list[0]->totalDuration / $totalCall):0;

                            //sms incoming and outgoing record code

                            $filter_sms = " WHERE date >= '".$previous_day."' AND date <= '".$current_day."' AND did='".$did_number."'";

                            $sql_sms = DB::connection($connection)->select("SELECT  SUM(type = 'outgoing') AS outgoing, 
                                SUM(type = 'incoming') AS incoming,extension FROM sms $filter_sms");

                            $result_arr['did'][$d]['incoming']  = $sql_sms[0]->incoming > 0 ? $sql_sms[0]->incoming : 0;
                            $result_arr['did'][$d]['outgoing']  = $sql_sms[0]->outgoing > 0 ? $sql_sms[0]->outgoing : 0;           
                            $d++;
                        }
                    }
                }
            }



          

       // echo "<pre>";print_r($result_arr);die;
        
            return $result_arr;
        }

    public function dailyCallReport1($objUser): array
    {
        $connection = 'mysql_' . $this->clientId;
        $result_arr = array();
        $previous_day = date("Y-m-d", strtotime(" -1 day")) . ' 22:00:00';
        $current_day = date("Y-m-d") . ' 22:00:00';

        $client = Client::find($this->clientId);
        $result_arr['start_time'] = $previous_day;
        $result_arr['end_time'] = $current_day;


        $result_arr['logo'] = env('PORTAL_NAME').'logo/' . $client->logo;
        $result_arr['company_name'] = $client->company_name;
        $user = User::where("email", "=", $objUser->email)->first();
        $groups = ExtensionGroupService::getExtensionGroups($user->parent_id, $user->extension);

        if ($user->user_level >= 7) {
            $extCondition = "";
        } elseif ($user->user_level >= 5) {
            $extensions = ExtensionGroupService::getExtensionsByGroups($user->parent_id, $groups);
            if (empty($extensions)) $extensions = [$user->extension];
            $extCondition = " AND extension IN (".implode(",", $extensions).")";
        } else {
            $extCondition = " AND extension = ".$user->extension;
        }

        $outbound_res = DB::connection($connection)->select("select count(*) as totalOutBoundCalls from cdr WHERE route  = :route and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "' $extCondition", ['route' => 'OUT']);

        $result_arr['total_outbound_Calls'] = $outbound_res[0]->totalOutBoundCalls;

        $outbound_manually = DB::connection($connection)->select("select count(*) as totalOutBoundCallsByManually from cdr WHERE route= :route and type= :type and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "' $extCondition", array('route' => 'OUT', 'type' => 'manual'));

        $result_arr['total_outbound_Calls_manually'] = $outbound_manually[0]->totalOutBoundCallsByManually;
        $outbound_dialer = DB::connection($connection)->select("select count(*) as totalOutBoundCallsByDialer from cdr WHERE route= :route and type= :type and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "' $extCondition", array('route' => 'OUT', 'type' => 'dialer'));

        $result_arr['total_outbound_Calls_dialer'] = $outbound_dialer[0]->totalOutBoundCallsByDialer;

        $sql = "select campaign_id, count(*) as calls, title from cdr left join campaign on cdr.campaign_id=campaign.id WHERE start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "' and cdr.campaign_id is not null and route='OUT' $extCondition group by campaign_id";
        $outbound_campaign = DB::connection($connection)->select($sql);
        $outbound_campaign = (array)$outbound_campaign;

        $i = 0;
        foreach ( $outbound_campaign as $key => $campaign_calls ) {
            //echo "<pre>";print_r($campaign_calls);
            $result_arr['campaign'][$key]['title'] = $campaign_calls->title?$campaign_calls->title:"Manual Calls";
            $result_arr['campaign'][$key]['calls'] = $campaign_calls->calls;
            $i++;
        }

        $inbound_res = DB::connection($connection)->select("select count(*) as totalInBoundCalls from cdr WHERE route  = :route and type = :type and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "' $extCondition", array('route' => 'IN', 'type' => 'manual'));

        $result_arr['total_inbound_Calls'] = $inbound_res[0]->totalInBoundCalls;

        #todo: extension column in sms table not having proper value
        $sms_send = DB::connection($connection)->select("select count(*) as totalSMSSend from sms WHERE type  = :type and date >= '" . $previous_day . "' and date <= '" . $current_day . "'", array('type' => 'outgoing'));

        if (!empty($sms_send)) {
            $result_arr['total_sms_send'] = $sms_send[0]->totalSMSSend;
        } else {
            $result_arr['total_sms_send'] = 0;
        }

        #todo: extension column in sms table not having proper value
        $sms_received = DB::connection($connection)->select("select count(*) as totalSMSReceive from sms WHERE type  = :type and date >= '" . $previous_day . "' and date <= '" . $current_day . "'", array('type' => 'incoming'));

        $result_arr['total_sms_receive'] = $sms_received[0]->totalSMSReceive;

        $agent_list = array();

        $sql = "select * from users WHERE parent_id=".$this->clientId." AND is_deleted=0 $extCondition order by first_name";
        $agent_list = DB::connection("master")->select($sql);

        $j = 0;
        if(!empty($agent_list))
        {
            foreach ( $agent_list as $agent )
            {
                $agent_list_calls = (array)$agent;
                $alt_extension = $agent_list_calls['alt_extension'];
                $extension = $agent_list_calls['extension'];
                $result_arr['agent'][$j]['extension'] = $extension;
                $result_arr['agent'][$j]['alt_extension'] = $alt_extension;

                $result_arr['agent'][$j]['agentName'] = $agent_list_calls['first_name'] . ' ' . $agent_list_calls['last_name'];

                $totalCall = 0;
                $agent_call_list_out = DB::connection($connection)->select("select count(*) as totalOutCalls from cdr WHERE extension IN('" . $extension . "','" . $alt_extension . "') and route = :route and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'", array('route' => 'OUT'));
                $result_arr['agent'][$j]['outbound'] = $agent_call_list_out[0]->totalOutCalls;
                $totalCall += $agent_call_list_out[0]->totalOutCalls;

                $agent_call_list_in = DB::connection($connection)->select("select count(*) as totalInCalls from cdr WHERE extension IN('" . $extension . "','" . $alt_extension . "') and route = :route and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'", array('route' => 'IN'));
                $result_arr['agent'][$j]['inbound'] = $agent_call_list_in[0]->totalInCalls;
                $totalCall += $agent_call_list_in[0]->totalInCalls;

                $agent_call_list = DB::connection($connection)->select("select sum(duration) as totalDuration from cdr WHERE extension IN('" . $extension . "','" . $alt_extension . "') and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'");
                $result_arr['agent'][$j]['duration'] = $agent_call_list[0]->totalDuration;

                $result_arr['agent'][$j]['totalcalls'] = $totalCall;

                $result_arr['agent'][$j]['aht'] = $totalCall?($agent_call_list[0]->totalDuration / $totalCall):0;

                //sms incoming and outgoing record code

                $filter_sms = " WHERE date >= '".$previous_day."' AND date <= '".$current_day."' AND extension='".$agent_list_calls['id']."'";

                $sql_sms = DB::connection($connection)->select("SELECT  SUM(type = 'outgoing') AS outgoing, 
                SUM(type = 'incoming') AS incoming,extension FROM sms $filter_sms");

                $result_arr['agent'][$j]['incoming']  = $sql_sms[0]->incoming > 0 ? $sql_sms[0]->incoming : 0;
                $result_arr['agent'][$j]['outgoing']  = $sql_sms[0]->outgoing > 0 ? $sql_sms[0]->outgoing : 0;           
                $j++;

            }
        }

         //city wise send daily report
            $areacode_list =array();
            $sql_areacode = "select count(*) as total,area_code from cdr WHERE  start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "' group by area_code order by total desc";

            $areacode_list = DB::connection($connection)->select($sql_areacode);
            $k = 0;
            if(!empty($areacode_list))
            {
                foreach ( $areacode_list as $code )
                {
                    $areacode_calls = (array)$code;
                    $find_city_state = "SELECT * from areacode_city where areacode = '".$areacode_calls['area_code']."'";

                    $record_city =  DB::connection("master")->selectOne($find_city_state);
                    if(!empty($record_city))
                    {
                        $total = $areacode_calls['total'];
                        $area_code = $areacode_calls['area_code'];
                        $city = $record_city->city_name;
                        $state = $record_city->state_name;
                        $result_arr['city_wise'][$k]['total'] = $total;
                        $result_arr['city_wise'][$k]['area_code'] = $area_code;
                        $result_arr['city_wise'][$k]['city'] = $city;
                        $result_arr['city_wise'][$k]['state'] = $state;
                        $k++;
                    }
                }
            }

            //did wise calls

            $did_list =array();
            $sql_did = "select * from did WHERE parent_id=".$this->clientId."";
            $did_list = DB::connection("master")->select($sql_did);

            $d = 0;

            if(!empty($did_list))
            {
                foreach ($did_list as $did )
                {
                    $did_list_calls = (array)$did;
                    $did_number = $did->cli;

                    $totalCall = 0;
                    $did_call_list_in = DB::connection($connection)->select("select count(*) as totalInCalls from cdr WHERE cli = '" . $did_number. "' and route = :route and start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'", array('route' => 'IN'));
                    if(!empty($did_call_list_in))
                    {
                        if($did_call_list_in[0]->totalInCalls !=0)
                        {
                            $result_arr['did'][$d]['inbound'] = $did_call_list_in[0]->totalInCalls;
                            $result_arr['did'][$d]['cli'] = $did_number;
                            $totalCall += $did_call_list_in[0]->totalInCalls;

                            $did_call_list = DB::connection($connection)->select("select sum(duration) as totalDuration from cdr WHERE cli = '" . $did_number. "' and route = :route and  start_time >= '" . $previous_day . "' and start_time <= '" . $current_day . "'", array('route' => 'IN'));
                            $result_arr['did'][$d]['duration'] = $did_call_list[0]->totalDuration;

                            $result_arr['did'][$d]['totalcalls'] = $totalCall;

                            $result_arr['did'][$d]['aht'] = $totalCall?($did_call_list[0]->totalDuration / $totalCall):0;

                            //sms incoming and outgoing record code

                            $filter_sms = " WHERE date >= '".$previous_day."' AND date <= '".$current_day."' AND did='".$did_number."'";

                            $sql_sms = DB::connection($connection)->select("SELECT  SUM(type = 'outgoing') AS outgoing, 
                                SUM(type = 'incoming') AS incoming,extension FROM sms $filter_sms");

                            $result_arr['did'][$d]['incoming']  = $sql_sms[0]->incoming > 0 ? $sql_sms[0]->incoming : 0;
                            $result_arr['did'][$d]['outgoing']  = $sql_sms[0]->outgoing > 0 ? $sql_sms[0]->outgoing : 0;           
                            $d++;
                        }
                    }
                }
            }

        //echo "<pre>";print_r($result_arr);die;

        
            return $result_arr;
        }

    public function dispositionSummary($request,string $startTime, string $endTime)
    {
        if(!empty($request->userId))
        {
            //$explode = "'" . implode ( "', '", $request->userId ) . "'";
            $user = User::whereIn("id", $request->userId)->get()->all();
            $extensionArray = array();
            foreach($user as $key=> $value)
            {
                array_push($extensionArray,$value->extension);
                array_push($extensionArray,$value->alt_extension);
            }
            $srch_input_1 = "'" . implode ( "', '", $extensionArray ) . "'";
            $sql = "SELECT count(*) as rowCount, disposition_id, title from ((SELECT disposition_id FROM cdr_archive WHERE extension IN(".$srch_input_1.") and start_time >= '$startTime' AND start_time <= '$endTime') UNION ALL (SELECT disposition_id FROM cdr WHERE extension IN(".$srch_input_1.") and start_time >= '$startTime' AND start_time <= '$endTime') ) as t LEFT JOIN disposition as d on d.id=t.disposition_id group by disposition_id order by rowCount desc";
        }
        else
        if($request->auth->level == 1) //show dashboard related to agent 
        {
            $extension = $request->auth->extension;
            $alt_extension = $request->auth->alt_extension;

            $sql = "SELECT count(*) as rowCount, disposition_id, title from ((SELECT disposition_id FROM cdr_archive WHERE (extension = '".$extension."' || extension='".$alt_extension."') and start_time >= '$startTime' AND start_time <= '$endTime') UNION ALL (SELECT disposition_id FROM cdr WHERE (extension = '".$extension."' || extension='".$alt_extension."') and start_time >= '$startTime' AND start_time <= '$endTime') ) as t LEFT JOIN disposition as d on d.id=t.disposition_id group by disposition_id order by rowCount desc";
        }
        else // show dashboard except for agent
        {
            $sql = "SELECT count(*) as rowCount, disposition_id, title from ((SELECT disposition_id FROM cdr_archive WHERE start_time >= '$startTime' AND start_time <= '$endTime') UNION ALL (SELECT disposition_id FROM cdr WHERE start_time >= '$startTime' AND start_time <= '$endTime') ) as t LEFT JOIN disposition as d on d.id=t.disposition_id group by disposition_id order by rowCount desc";
        }

        Log::info("ReportService.dispositionSummary", ["startTime" => $startTime,"endTime" => $endTime,"sql" => $sql]);
        $record = DB::connection("mysql_{$this->clientId}")->select($sql);
        return (array)$record;
    }

    
    // public function stateWiseSummary($request, string $startTime, string $endTime)
    // {
    //     if(!empty($request->userId))
    //     {
    //         //$explode = "'" . implode ( "', '", $request->userId ) . "'";
    //         $user = User::whereIn("id", $request->userId)->get()->all();
    //         $extensionArray = array();
    //         foreach($user as $key=> $value)
    //         {
    //             array_push($extensionArray,$value->extension);
    //             array_push($extensionArray,$value->alt_extension);
    //         }
    //         $srch_input_1 = "'" . implode ( "', '", $extensionArray ) . "'";
    //         $sql = "SELECT count(*) as rowCount,area_code from ((SELECT area_code FROM cdr_archive WHERE extension IN(".$srch_input_1.") and start_time >= '$startTime' AND start_time <= '$endTime') UNION ALL (SELECT area_code FROM cdr WHERE extension IN(".$srch_input_1.") and start_time >= '$startTime' AND start_time <= '$endTime') ) as t group by area_code order by rowCount desc";
    //     }

    //     /*if(!empty($request->userId))
    //     {
    //         $user = User::where("id", "=", $request->userId)->first();
    //         $extension = $user->extension;
    //         $alt_extension = $user->alt_extension;
    //         $sql = "SELECT count(*) as rowCount,area_code from ((SELECT area_code FROM cdr_archive WHERE extension IN(".$srch_input_1.") and start_time >= '$startTime' AND start_time <= '$endTime') UNION ALL (SELECT area_code FROM cdr WHERE extension IN(".$srch_input_1.") and start_time >= '$startTime' AND start_time <= '$endTime') ) as t group by area_code order by rowCount desc";
    //     }*/
    //     else
    //     if($request->auth->level == 1) //show dashboard related to agent 
    //     {
    //         $extension = $request->auth->extension;
    //         $alt_extension = $request->auth->alt_extension;
    //         $sql = "SELECT count(*) as rowCount,area_code from ((SELECT area_code FROM cdr_archive WHERE (extension = '".$extension."' || extension='".$alt_extension."') and start_time >= '$startTime' AND start_time <= '$endTime') UNION ALL (SELECT area_code FROM cdr WHERE (extension = '".$extension."' || extension='".$alt_extension."') and start_time >= '$startTime' AND start_time <= '$endTime') ) as t group by area_code order by rowCount desc";
    //     }
    //     else
    //     {
    //          $sql = "SELECT count(*) as rowCount,area_code from ((SELECT area_code FROM cdr_archive WHERE start_time >= '$startTime' AND start_time <= '$endTime') UNION ALL (SELECT area_code FROM cdr WHERE start_time >= '$startTime' AND start_time <= '$endTime') ) as t group by area_code order by rowCount desc";
    //     }

    //     Log::info("ReportService.stateWiseSummary", ["startTime" => $startTime,"endTime" => $endTime,"sql" => $sql]);
    //     $record = DB::connection("mysql_{$this->clientId}")->select($sql);
    //     if(!empty($record))
    //     {
    //         foreach($record as $key=>$cdr_list)
    //         {
    //             $sql_areacode = "select * from areacode_city where areacode='".$cdr_list->area_code."'";
    //             $areacode_list = DB::connection("master")->select($sql_areacode);
    //             if(!empty($areacode_list))
    //             {
    //                 if($cdr_list->area_code == $areacode_list[0]->areacode)
    //                 {
    //                     $record[$key]->state_code = $areacode_list[0]->state_code;
    //                     $record[$key]->country_code = $areacode_list[0]->country_code;
    //                 }
    //             }
    //         }
    //     }

    //     return (array)$record;
    // }
public function stateWiseSummary($request, string $startTime, string $endTime)
{
    try {
        $bindings = [$startTime, $endTime, $startTime, $endTime];
        $sql = '';

        // 🧩 Case 1: Specific user IDs
        if (!empty($request->userId)) {
            $user = User::whereIn("id", $request->userId)->get();
            $extensionArray = [];

            foreach ($user as $value) {
                if (!empty($value->extension)) {
                    $extensionArray[] = $value->extension;
                }
                if (!empty($value->alt_extension)) {
                    $extensionArray[] = $value->alt_extension;
                }
            }

            if (empty($extensionArray)) {
                return [];
            }

            $srch_input_1 = "'" . implode("','", $extensionArray) . "'";

            $sql = "
                SELECT COUNT(*) AS rowCount, area_code
                FROM (
                    (SELECT area_code FROM cdr_archive
                     WHERE extension IN($srch_input_1)
                     AND start_time >= ? AND start_time <= ?)
                    UNION ALL
                    (SELECT area_code FROM cdr
                     WHERE extension IN($srch_input_1)
                     AND start_time >= ? AND start_time <= ?)
                ) AS t
                GROUP BY area_code
                ORDER BY rowCount DESC
            ";
        }
        // 🧩 Case 2: Agent-level view
        elseif ($request->auth->level == 1) {
            $extension = $request->auth->extension;
            $alt_extension = $request->auth->alt_extension;

            $sql = "
                SELECT COUNT(*) AS rowCount, area_code
                FROM (
                    (SELECT area_code FROM cdr_archive
                     WHERE (extension = ? OR extension = ?)
                     AND start_time >= ? AND start_time <= ?)
                    UNION ALL
                    (SELECT area_code FROM cdr
                     WHERE (extension = ? OR extension = ?)
                     AND start_time >= ? AND start_time <= ?)
                ) AS t
                GROUP BY area_code
                ORDER BY rowCount DESC
            ";

            $bindings = [
                $extension, $alt_extension,
                $startTime, $endTime,
                $extension, $alt_extension,
                $startTime, $endTime
            ];
        }
        // 🧩 Case 3: Admin view
        else {
            $sql = "
                SELECT COUNT(*) AS rowCount, area_code
                FROM (
                    (SELECT area_code FROM cdr_archive
                     WHERE start_time >= ? AND start_time <= ?)
                    UNION ALL
                    (SELECT area_code FROM cdr
                     WHERE start_time >= ? AND start_time <= ?)
                ) AS t
                GROUP BY area_code
                ORDER BY rowCount DESC
            ";
        }

        Log::info("ReportService.stateWiseSummary", [
            "startTime" => $startTime,
            "endTime" => $endTime,
            "sql" => $sql
        ]);

        // Fetch main data
        $record = DB::connection("mysql_{$this->clientId}")->select($sql, $bindings);

        // Enrich with areacode details
        if (!empty($record)) {
            foreach ($record as $key => $cdr_list) {
                $sql_areacode = "SELECT * FROM areacode_city WHERE areacode = ?";
                $areacode_list = DB::connection("master")->select($sql_areacode, [$cdr_list->area_code]);

                if (!empty($areacode_list)) {
                    $record[$key]->state_code = $areacode_list[0]->state_code;
                    $record[$key]->country_code = $areacode_list[0]->country_code;
                }
            }

            // ✅ Apply filters after enrichment
            $record = array_filter($record, function ($r) use ($request) {
                if (!empty($request->country_code) && (!isset($r->country_code) || $r->country_code != $request->country_code)) {
                    return false;
                }
                if (!empty($request->state_code) && (!isset($r->state_code) || $r->state_code != $request->state_code)) {
                    return false;
                }
                if (!empty($request->area_code) && (!isset($r->area_code) || $r->area_code != $request->area_code)) {
                    return false;
                }
                return true;
            });

            $record = array_values($record); // Reindex
        }

        return (array)$record;

    } catch (\Exception $e) {
        \Log::error("Error in stateWiseSummary: " . $e->getMessage());
        return [];
    }
}



    public function cdrCallCount($request, string $route, string $type, string $startTime, string $endTime)
    {
        if(!empty($request->userId))
        {
            //$explode = "'" . implode ( "', '", $request->userId ) . "'";
            $user = User::whereIn("id", $request->userId)->get()->all();
            $extensionArray = array();
            foreach($user as $key=> $value)
            {
                array_push($extensionArray,$value->extension);
                array_push($extensionArray,$value->alt_extension);
            }
            $srch_input_1 = "'" . implode ( "', '", $extensionArray ) . "'";
            if($route == 'OUT' && $type == 'dialer')
            {
                $filter = " WHERE extension IN(".$srch_input_1.") and route = '$route' AND (type ='$type') AND start_time >= '$startTime' AND start_time <= '$endTime'";
            }
            else
            {
                $filter = " WHERE extension IN(".$srch_input_1.") and route = '$route' AND type ='$type' AND start_time >= '$startTime' AND start_time <= '$endTime'";
            }
        }
        else
        if($request->auth->level == 1) //show dashboard related to agent 
        {
            $extension = $request->auth->extension;
            $alt_extension = $request->auth->alt_extension;

            if($route == 'OUT' && $type == 'dialer')
            {
                $filter = " WHERE (extension = '".$extension."' || extension='".$alt_extension."') and route = '$route' AND (type ='$type') AND start_time >= '$startTime' AND start_time <= '$endTime'";
            }
            else
            {
                $filter = " WHERE (extension = '".$extension."' || extension='".$alt_extension."') and route = '$route' AND type ='$type' AND start_time >= '$startTime' AND start_time <= '$endTime'";
            }
        }

        else
        {
            if($route == 'OUT' && $type == 'dialer')
            {
                $filter = " WHERE route = '$route' AND (type ='$type') AND start_time >= '$startTime' AND start_time <= '$endTime'";
            }
            else
            {
                $filter = " WHERE route = '$route' AND type ='$type' AND start_time >= '$startTime' AND start_time <= '$endTime'";
            }
        }

        $sql = "select  count(id) as calls, sum(duration) as totalDuration, avg(duration) as avgDuration from ((SELECT id, duration FROM cdr_archive $filter) UNION ALL (SELECT id, duration FROM cdr $filter)) as t";

        Log::info("ReportService.cdrCallCount",["route"=>$route,"type"=>$type,"startTime" => $startTime,"endTime" => $endTime,"sql" => $sql]);
        $record = DB::connection("mysql_{$this->clientId}")->select($sql);
        return (array)$record[0];
    }


    public function cdrCallAgentCount($request, string $startTime, string $endTime)
    {
        if(!empty($request->userId))
        {
            //$explode = "'" . implode ( "', '", $request->userId ) . "'";
            $user = User::whereIn("id", $request->userId)->get()->all();
            $extensionArray = array();
            foreach($user as $key=> $value)
            {
                array_push($extensionArray,$value->extension);
                array_push($extensionArray,$value->alt_extension);
            }
            $srch_input_1 = "'" . implode ( "', '", $extensionArray ) . "'";
            
                $filter = " WHERE extension IN(".$srch_input_1.")  AND start_time >= '$startTime' AND start_time <= '$endTime'";
           
        }
        else
        if($request->auth->level == 1) //show dashboard related to agent 
        {
            $extension = $request->auth->extension;
            $alt_extension = $request->auth->alt_extension;

            
                $filter = " WHERE (extension = '".$extension."' || extension='".$alt_extension."')   AND start_time >= '$startTime' AND start_time <= '$endTime'";
        }

        else
        {
            $filter = "WHERE start_time >= '$startTime' AND start_time <= '$endTime'";
        }

        $sql = "select count(distinct(extension)) as totalAgent from cdr_archive $filter";
        Log::info("ReportService.cdrCallCount",["startTime" => $startTime,"endTime" => $endTime,"sql" => $sql]);
        $record = DB::connection("mysql_{$this->clientId}")->select($sql);

        /*if(empty($record))
        {
             $sql = "select count(distinct(extension)) as totalAgent from cdr_archive $filter";
        Log::info("ReportService.cdrCallCount",["startTime" => $startTime,"endTime" => $endTime,"sql" => $sql]);
        $record = DB::connection("mysql_{$this->clientId}")->select($sql);

        }*/
        return (array)$record[0];
    }

    function cdrExtensionSummary($request, string $startTime, string $endTime)
    {
        $userData = [];
        if(!empty($request->userId))
        {
            $user = User::whereIn("id", $request->userId)->get()->all();
            $extensionArray = array();
            foreach($user as $key=> $value)
            {
                array_push($extensionArray,$value->extension);
                array_push($extensionArray,$value->alt_extension);
            }
            $srch_input_1 = "'" . implode ( "', '", $extensionArray ) . "'";
            $filter = " WHERE extension IN(".$srch_input_1.") and start_time >= '$startTime' AND start_time <= '$endTime'";
        }
        else
        {
            $filter = " WHERE start_time >= '$startTime' AND start_time <= '$endTime'";
        }

        $sql = "select count(id) as calls, sum(duration) as totalDuration, avg(duration) as avgDuration, extension from ((select id,extension,duration from cdr_archive $filter) UNION ALL (select id,extension,duration from cdr $filter)) as t group by extension order by calls desc"; //limit 25

        Log::info("ReportService.cdrExtensionSummary", ["startTime" => $startTime,"endTime" => $endTime,"sql" => $sql]);
        $record = DB::connection("mysql_{$this->clientId}")->select($sql);
        $data = (array)$record;

        if (count($data) > 0) {
            foreach ( $data as $key => $val ) {
                $extension = substr($val->extension, -4, 4);
                $extension_user = $this->clientId . $extension;

                /*$sqlUser = "SELECT * FROM users where is_deleted = 0 AND parent_id={$this->clientId} and (extension={$extension} or alt_extension={$extension} or extension={$extension_user} or alt_extension={$extension_user})";*/   

                $sqlUser = "SELECT * FROM users where is_deleted = 0 AND parent_id={$this->clientId} and (extension={$extension_user} or alt_extension={$extension_user})";
             $record = DB::connection('master')->selectOne($sqlUser);

                if (!empty($record))
                {
                    $filter_sms = " WHERE date >= '$startTime' AND date <= '$endTime' AND extension='$record->id'";
                    $sql_sms = "SELECT  SUM(type = 'outgoing') AS outgoing, SUM(type = 'incoming') AS incoming,extension FROM sms $filter_sms";
                    $record_sms =  DB::connection("mysql_{$this->clientId}")->selectOne($sql_sms);
                    $userData[] = [
                        'id' => $record->id,
                        'first_name' => $record->first_name,
                        'last_name' => $record->last_name,
                        'extension' => $extension,
                        'calls' => $val->calls,
                        'totalDuration' => $val->totalDuration,
                        'avgDuration' => $val->avgDuration,
                        'outgoing'=>    $record_sms->outgoing > 0 ? $record_sms->outgoing : 0,
                        'incoming'=>    $record_sms->incoming > 0 ? $record_sms->incoming : 0
                    ];
                }
            }
        }
        return $userData;
    }

    public function voicemailCount($request, string $startTime, string $endTime)
    {
        $data = [
            'read' => 0,
            'unread' => 0
        ];

        if(!empty($request->userId))
        {
            //$explode = "'" . implode ( "', '", $request->userId ) . "'";
            $user = User::whereIn("id", $request->userId)->get()->all();
            $extensionArray = array();
            foreach($user as $key=> $value)
            {
                array_push($extensionArray,$value->extension);
                array_push($extensionArray,$value->alt_extension);
            }
            $srch_input_1 = "'" . implode ( "', '", $extensionArray ) . "'";

            $filter = " WHERE extension IN(".$srch_input_1.") and date_time >= '$startTime' AND date_time <= '$endTime'";
        }

        else
        {
        $filter = " WHERE date_time >= '$startTime' AND date_time <= '$endTime'";

        }
        $sql = "SELECT count(id) as voicemails, status FROM mailbox $filter group by status";
        Log::info("ReportService.voicemailCount", [
            "startTime" => $startTime,
            "endTime" => $endTime,
            "sql" => $sql
        ]);
        $record = DB::connection("mysql_{$this->clientId}")->select($sql);
        $response = (array)$record;
        foreach ( $response as $res ) {
            if ($res->status == 1)
                $data["unread"] = $res->voicemails;
            else
                $data["read"] = $res->voicemails;
        }
        return $data;
    }

    function smsCount($request, string $startTime, string $endTime)
    {
        $data = ['incoming' => 0,'outgoing' => 0];
        if(!empty($request->userId))
        {
            $user = User::whereIn("id", $request->userId)->get()->all();
            $extensionArray = array();
            foreach($user as $key=> $value)
            {
                array_push($extensionArray,$value->id);
               // array_push($extensionArray,$value->alt_extension);
            }

            $srch_input_1 = "'" . implode ( "', '", $extensionArray ) . "'";
            $filter = " WHERE extension IN(".$srch_input_1.") and date >= '$startTime' AND date <= '$endTime'";
        }
        else
        {
            $filter = " WHERE date >= '$startTime' AND date <= '$endTime'";
        }

        $sql = "SELECT count(id) as rowCount, type FROM sms $filter group by type";
        Log::info("ReportService.getSmsCounts", ["startTime" => $startTime,"endTime" => $endTime,"sql" => $sql]);
        $record = DB::connection("mysql_{$this->clientId}")->select($sql);
        $response = (array)$record;

        foreach ( $response as $res )
        {
            $data[$res->type] = $res->rowCount;
        }
        return $data;
    }

    function cdrCallsByRange($request, array $range)
    {
        $data = [];
        foreach ($range as $key => $times)
        {
            if(!empty($times["userId"]))
            {
                $user = User::where("id", "=", $times["userId"])->first();
                $extension = $user->extension;
                $alt_extension = $user->alt_extension;
                $sql = "SELECT count(*) as rowCount,area_code from ((SELECT area_code FROM cdr_archive WHERE (extension = '".$extension."' || extension='".$alt_extension."') and start_time >= '$startTime' AND start_time <= '$endTime') UNION ALL (SELECT area_code FROM cdr WHERE (extension = '".$extension."' || extension='".$alt_extension."') and start_time >= '$startTime' AND start_time <= '$endTime') ) as t group by area_code order by rowCount desc";
            }
            else
            if($request->auth->level == 1) //show dashboard related to agent 
            {
                $extension = $request->auth->extension;
                $alt_extension = $request->auth->alt_extension;
                $filter = " WHERE (extension = '".$extension."' || extension='".$alt_extension."') and  start_time >= '".$times["startTime"]."' AND start_time <= '".$times["endTime"]."'";
            }
            else
            {
                $filter = " WHERE start_time >= '".$times["startTime"]."' AND start_time <= '".$times["endTime"]."'";
            }

            $sql = "select count(id) as calls, route from ((SELECT id, route FROM cdr_archive $filter) UNION ALL (SELECT id, route FROM cdr $filter)) as t group by route";

            $record = DB::connection("mysql_{$this->clientId}")->select($sql);
            $data[$key] = ["OUT" => 0,"IN" => 0];
            foreach ($record as $row)
            {
                $data[$key][$row->route] = $row->calls;
            }

            Log::info("ReportService.cdrCallsByRange.$key", ["startTime" => $times["startTime"],"endTime" => $times["endTime"],"sql" => $sql,"data.$key" => $data[$key]]);
        }
        return $data;
    }
    public function cdrCallsByRangeNew($request, array $range)
{
    $data = [];

    foreach ($range as $key => $times) {
        $startTime = $times["startTime"];
        $endTime = $times["endTime"];

        if (!empty($times["userId"])) {
            $user = User::find($times["userId"]);

            if (!$user) {
                // If user not found, skip or add error entry
                Log::warning("User not found", ['userId' => $times["userId"]]);
                $data[$key] = ["IN" => 0, "OUT" => 0, "error" => "User not found"];
                continue;
            }

            $extension = $user->extension;
            $alt_extension = $user->alt_extension;

            $sql = "SELECT count(*) as rowCount, area_code FROM (
                        (SELECT area_code FROM cdr_archive 
                         WHERE (extension = '$extension' OR extension = '$alt_extension') 
                         AND start_time >= '$startTime' AND start_time <= '$endTime')
                        UNION ALL
                        (SELECT area_code FROM cdr 
                         WHERE (extension = '$extension' OR extension = '$alt_extension') 
                         AND start_time >= '$startTime' AND start_time <= '$endTime')
                    ) as t 
                    GROUP BY area_code 
                    ORDER BY rowCount DESC";
        } elseif ($request->auth->level == 1) {
            // Agent level
            $extension = $request->auth->extension;
            $alt_extension = $request->auth->alt_extension;

            $filter = "WHERE (extension = '$extension' OR extension = '$alt_extension') 
                       AND start_time >= '$startTime' AND start_time <= '$endTime'";
        } else {
            // Admin level
            $filter = "WHERE start_time >= '$startTime' AND start_time <= '$endTime'";
        }

        // Common SQL for IN/OUT calls
        if (empty($times["userId"])) {
            $sql = "SELECT count(id) as calls, route FROM (
                        (SELECT id, route FROM cdr_archive $filter)
                        UNION ALL
                        (SELECT id, route FROM cdr $filter)
                    ) as t 
                    GROUP BY route";
        }

        // Execute SQL
        $record = DB::connection("mysql_{$this->clientId}")->select($sql);
        $data[$key] = ["OUT" => 0, "IN" => 0];

        foreach ($record as $row) {
            $data[$key][$row->route] = $row->calls;
        }

        // Log info
        Log::info("ReportService.cdrCallsByRange.$key", [
            "startTime" => $startTime,
            "endTime" => $endTime,
            "sql" => $sql,
            "data.$key" => $data[$key]
        ]);
    }

    return $data;
}
public function dispositionSummaryNew($request, string $startTime, string $endTime)
{
    if (!empty($request->userId)) {
        $userIds = is_array($request->userId) ? $request->userId : [$request->userId];
        $users = User::whereIn("id", $userIds)->get();
        
        $extensionArray = [];
        foreach ($users as $user) {
            if ($user->extension) {
                $extensionArray[] = $user->extension;
            }
            if ($user->alt_extension) {
                $extensionArray[] = $user->alt_extension;
            }
        }

        $srch_input_1 = "'" . implode("','", $extensionArray) . "'";
        $sql = "SELECT count(*) as rowCount, disposition_id, title 
                FROM (
                    (SELECT disposition_id FROM cdr_archive WHERE extension IN ($srch_input_1) AND start_time >= '$startTime' AND start_time <= '$endTime') 
                    UNION ALL 
                    (SELECT disposition_id FROM cdr WHERE extension IN ($srch_input_1) AND start_time >= '$startTime' AND start_time <= '$endTime')
                ) AS t 
                LEFT JOIN disposition d ON d.id = t.disposition_id 
                GROUP BY disposition_id 
                ORDER BY rowCount DESC";
    } elseif ($request->auth->level == 1) {
        $extension = $request->auth->extension;
        $alt_extension = $request->auth->alt_extension;

        $sql = "SELECT count(*) as rowCount, disposition_id, title 
                FROM (
                    (SELECT disposition_id FROM cdr_archive WHERE (extension = '$extension' OR extension = '$alt_extension') AND start_time >= '$startTime' AND start_time <= '$endTime') 
                    UNION ALL 
                    (SELECT disposition_id FROM cdr WHERE (extension = '$extension' OR extension = '$alt_extension') AND start_time >= '$startTime' AND start_time <= '$endTime')
                ) AS t 
                LEFT JOIN disposition d ON d.id = t.disposition_id 
                GROUP BY disposition_id 
                ORDER BY rowCount DESC";
    } else {
        $sql = "SELECT count(*) as rowCount, disposition_id, title 
                FROM (
                    (SELECT disposition_id FROM cdr_archive WHERE start_time >= '$startTime' AND start_time <= '$endTime') 
                    UNION ALL 
                    (SELECT disposition_id FROM cdr WHERE start_time >= '$startTime' AND start_time <= '$endTime')
                ) AS t 
                LEFT JOIN disposition d ON d.id = t.disposition_id 
                GROUP BY disposition_id 
                ORDER BY rowCount DESC";
    }

    Log::info("ReportService.dispositionSummary", ["sql" => $sql]);
    $record = DB::connection("mysql_{$this->clientId}")->select($sql);
    return (array) $record;
}
public function cdrCallCountNew($request, string $route, string $type, string $startTime, string $endTime)
{
    if (!empty($request->userId)) {
        $userIds = is_array($request->userId) ? $request->userId : [$request->userId];
        $users = User::whereIn("id", $userIds)->get();

        $extensionArray = [];
        foreach ($users as $user) {
            if ($user->extension) {
                $extensionArray[] = $user->extension;
            }
            if ($user->alt_extension) {
                $extensionArray[] = $user->alt_extension;
            }
        }

        $srch_input_1 = "'" . implode("','", $extensionArray) . "'";
        $filter = " WHERE extension IN ($srch_input_1) AND route = '$route' AND type = '$type' AND start_time >= '$startTime' AND start_time <= '$endTime'";
    } elseif ($request->auth->level == 1) {
        $extension = $request->auth->extension;
        $alt_extension = $request->auth->alt_extension;

        $filter = " WHERE (extension = '$extension' OR extension = '$alt_extension') AND route = '$route' AND type = '$type' AND start_time >= '$startTime' AND start_time <= '$endTime'";
    } else {
        $filter = " WHERE route = '$route' AND type = '$type' AND start_time >= '$startTime' AND start_time <= '$endTime'";
    }

    $sql = "SELECT count(id) as calls, SUM(duration) as totalDuration, AVG(duration) as avgDuration 
            FROM (
                (SELECT id, duration FROM cdr_archive $filter)
                UNION ALL
                (SELECT id, duration FROM cdr $filter)
            ) AS t";

    Log::info("ReportService.cdrCallCount", ["route" => $route, "type" => $type, "sql" => $sql]);
    $record = DB::connection("mysql_{$this->clientId}")->select($sql);
    return (array) $record[0];
}
public function cdrCallAgentCountNew(string $startTime, string $endTime, array $userId)
{
    $userIds = $userId; // already an array
    $users = User::whereIn("id", $userIds)->get();

    $extensionArray = [];
    foreach ($users as $user) {
        if ($user->extension) {
            $extensionArray[] = $user->extension;
        }
        if ($user->alt_extension) {
            $extensionArray[] = $user->alt_extension;
        }
    }

    $srch_input_1 = "'" . implode("','", $extensionArray) . "'";
    $filter = " WHERE extension IN ($srch_input_1) AND start_time >= '$startTime' AND start_time <= '$endTime'";

    $sql = "SELECT COUNT(DISTINCT(extension)) AS totalAgent FROM cdr_archive $filter";

    Log::info("ReportService.cdrCallAgentCountNew", ["sql" => $sql]);

    $record = DB::connection("mysql_{$this->clientId}")->select($sql);
    return (array) $record[0];
}
 public function stateWiseSummaryNew(string $startTime, string $endTime,array $userId)
    {
        if(!empty($userId))
        {
            //$explode = "'" . implode ( "', '", $request->userId ) . "'";
             $userIds = $userId; // already an array
            $users = User::whereIn("id", $userIds)->get();
            $extensionArray = array();
            foreach($users as $key=> $value)
            {
                array_push($extensionArray,$value->extension);
                array_push($extensionArray,$value->alt_extension);
            }
            $srch_input_1 = "'" . implode ( "', '", $extensionArray ) . "'";
            $sql = "SELECT count(*) as rowCount,area_code from ((SELECT area_code FROM cdr_archive WHERE extension IN(".$srch_input_1.") and start_time >= '$startTime' AND start_time <= '$endTime') UNION ALL (SELECT area_code FROM cdr WHERE extension IN(".$srch_input_1.") and start_time >= '$startTime' AND start_time <= '$endTime') ) as t group by area_code order by rowCount desc";
        }

        /*if(!empty($request->userId))
        {
            $user = User::where("id", "=", $request->userId)->first();
            $extension = $user->extension;
            $alt_extension = $user->alt_extension;
            $sql = "SELECT count(*) as rowCount,area_code from ((SELECT area_code FROM cdr_archive WHERE extension IN(".$srch_input_1.") and start_time >= '$startTime' AND start_time <= '$endTime') UNION ALL (SELECT area_code FROM cdr WHERE extension IN(".$srch_input_1.") and start_time >= '$startTime' AND start_time <= '$endTime') ) as t group by area_code order by rowCount desc";
        }*/
        else
        if($request->auth->level == 1) //show dashboard related to agent 
        {
            $extension = $request->auth->extension;
            $alt_extension = $request->auth->alt_extension;
            $sql = "SELECT count(*) as rowCount,area_code from ((SELECT area_code FROM cdr_archive WHERE (extension = '".$extension."' || extension='".$alt_extension."') and start_time >= '$startTime' AND start_time <= '$endTime') UNION ALL (SELECT area_code FROM cdr WHERE (extension = '".$extension."' || extension='".$alt_extension."') and start_time >= '$startTime' AND start_time <= '$endTime') ) as t group by area_code order by rowCount desc";
        }
        else
        {
             $sql = "SELECT count(*) as rowCount,area_code from ((SELECT area_code FROM cdr_archive WHERE start_time >= '$startTime' AND start_time <= '$endTime') UNION ALL (SELECT area_code FROM cdr WHERE start_time >= '$startTime' AND start_time <= '$endTime') ) as t group by area_code order by rowCount desc";
        }

        Log::info("ReportService.stateWiseSummary", ["startTime" => $startTime,"endTime" => $endTime,"sql" => $sql]);
        $record = DB::connection("mysql_{$this->clientId}")->select($sql);
        if(!empty($record))
        {
            foreach($record as $key=>$cdr_list)
            {
                $sql_areacode = "select * from areacode_city where areacode='".$cdr_list->area_code."'";
                $areacode_list = DB::connection("master")->select($sql_areacode);
                if(!empty($areacode_list))
                {
                    if($cdr_list->area_code == $areacode_list[0]->areacode)
                    {
                        $record[$key]->state_code = $areacode_list[0]->state_code;
                        $record[$key]->country_code = $areacode_list[0]->country_code;
                    }
                }
            }
        }

        return (array)$record;
    }

}
