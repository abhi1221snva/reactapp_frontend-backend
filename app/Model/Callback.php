<?php

namespace App\Model;

use App\Http\Helper\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;

class Callback extends Model
{

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $guarded = ['id'];

    protected $table = 'callback';

    /*
     * Fetch CDR from user id
     *@param integer $id
     * @return array
     */
    // public function getCallBack($request)
    // {
    //     try {

    //     date_default_timezone_set($request->auth->timezone); // your user's timezone
    //     $my_datetime=$request->start_date;//'2023-04-03 07:57:37';
    //     $my_datetime1=$request->end_date;//'2023-04-03 07:57:37';

    //     $request['start_date'] = date('Y-m-d H:i:s',strtotime("$my_datetime UTC"));
    //     $request['end_date'] = date('Y-m-d H:i:s',strtotime("$my_datetime1 UTC"));
        
    //     $id = $request->input('id');
    //     if (!empty($id) && is_numeric($id)) {
    //             $search = array();
    //             $searchString = array();

    //             // for Agent it will show his records only
    //             if ($request->auth->role == 2) {
    //                 $search['extension'] = $request->auth->extension;
    //                 $search['alt_extension'] = $request->auth->alt_extension;
    //                 array_push($searchString, '( c.extension = :extension OR c.extension = :alt_extension)');

    //             } else
    //             if ($request->has('extension') && !empty($request->input('extension'))) {
    //                 // filter option, consider alt_extension bacause call maybe made using webRTC.
    //                 $search['extension'] = $request->input('extension');
    //                 $objTempUser = User::where('extension', $request->input('extension'))->where('is_deleted', '=', 0)->first();
    //                 $search['alt_extension'] = $objTempUser->alt_extension;
    //                 array_push($searchString, '(extension = :extension OR c.extension = :alt_extension)');
    //             }

    //             if ($request->has('campaign') && !empty($request->input('campaign'))) {
    //                 $search['campaign_id'] = $request->input('campaign');
    //                 array_push($searchString, 'c.campaign_id = :campaign_id');
    //             }

    //             if ($request->has('start_date') && $request->has('end_date') && !empty($request->input('start_date')) && !empty($request->input('end_date'))) {
    //                 $start = date('Y-m-d', strtotime($request->input('start_date'))) . " 00:00:00";
    //                 $end = date('Y-m-d', strtotime($request->input('end_date'))) . " 23:59:59";
    //                 $search['start_time'] = $start;
    //                 $search['end_time'] = $end;
    //                 array_push($searchString, 'c.callback_time BETWEEN :start_time AND :end_time');
    //             }

    //             if ($request->has('reminder') && !empty($request->input('reminder'))) {
    //                 $sql_extension = "SELECT GROUP_CONCAT(extension) as extensions FROM master.users WHERE extension IN (
    //                     SELECT extension FROM " . 'client_' . $request->auth->parent_id . ".extension_group_map WHERE is_deleted =0 and group_id IN (SELECT group_id FROM " . 'client_' . $request->auth->parent_id . ".extension_group_map WHERE is_deleted =0 and extension = " . $request->auth->extension . ")
    //                 ) AND user_level <= '" . $request->auth->level . "' ";

    //                 $arrExtensions = DB::connection('mysql_' . $request->auth->parent_id)->select($sql_extension);

    //                 $sql_extension = "SELECT GROUP_CONCAT(alt_extension) as alt_extension FROM master.users WHERE alt_extension IN (
    //                     SELECT extension FROM " . 'client_' . $request->auth->parent_id . ".extension_group_map WHERE is_deleted =0 and group_id IN (SELECT group_id FROM " . 'client_' . $request->auth->parent_id . ".extension_group_map WHERE is_deleted =0 and extension = " . $request->auth->extension . ")
    //                 ) AND user_level <= '" . $request->auth->level . "' ";

    //                 $arrExtensions1 = DB::connection('mysql_' . $request->auth->parent_id)->select($sql_extension);


    //                 $strExtensions = $arrExtensions[0]->extensions;
    //                 $strExtensions1 = $arrExtensions1[0]->alt_extension;

    //                 $originateRequest = $strExtensions.','.$strExtensions1;

    //                 array_push($searchString, " c.extension IN ($originateRequest)");

    //                 $search['start_time'] = date('Y-m-d H:i:s', strtotime($request->input('start_date')));
    //                 $search['end_time'] = date('Y-m-d H:i:s', strtotime($request->input('end_date')));
    //             }

    //             $filter = (!empty($searchString)) ? " WHERE " . implode(" AND ", $searchString) : '';

    //             $sql = "SELECT c.*,
    //                             ld.*,
    //                             CONCAT_WS(', ', option_1, option_2, option_3, option_4, option_5, option_6, option_7, option_8, option_9, option_10, option_11, option_12, option_13, option_14, option_15, option_16, option_17, option_18, option_19, option_20, option_21, option_22, option_23, option_24, option_25, option_26, option_27, option_28, option_29, option_30 ) as list_values,
    //                             x.list_headers,
    //                             y.is_dialing_selected_column
    //                     from callback as c
    //                        JOIN list_data as ld ON ( c.lead_id = ld.id )
    //                         JOIN (SELECT lh.list_id, GROUP_CONCAT(lh.header ORDER BY lh.id SEPARATOR ', ') as list_headers FROM list_header as lh GROUP BY lh.list_id) x ON x.list_id = ld.list_id
    //                         JOIN (SELECT lhh.column_name as is_dialing_selected_column, lhh.list_id FROM list_header as lhh WHERE is_dialing = 1 GROUP BY lhh.list_id) y ON y.list_id = ld.list_id
    //                        " . $filter . " ORDER BY c.callback_time DESC";

    //             $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql, $search);

    //             if (!empty($record)) {
    //                 $data = (array)$record;
    //                 return array(
    //                     'success' => 'true',
    //                     'message' => 'Callback Data Report.',
    //                     'data' => $data
    //                 );
    //             } else {
    //                 return array(
    //                     'success' => 'true',
    //                     'message' => 'No Callback Data Report found.',
    //                     'record_count' => 0,
    //                     'data' => array()
    //                 );
    //             }
    //         }
    //         return array(
    //             'success' => 'false',
    //             'message' => 'Callback Data Report doesn\'t exist.'
    //         );
    //     } catch (Exception $e) {
    //         Log::log($e->getMessage());
    //         return array(
    //             'success' => 'false',
    //             'message' => $e->getMessage()
    //         );
    //     } catch (InvalidArgumentException $e) {
    //         Log::log($e->getMessage());
    //         return array(
    //             'success' => 'false',
    //             'message' => $e->getMessage()
    //         );
    //     }
    // }
    public function getCallBack($request)
{
    try {
        date_default_timezone_set($request->auth->timezone);

        // ✅ Convert timezone safely
       $my_datetime = $request->start_date ?? date('Y-m-d H:i:s');
$my_datetime1 = $request->end_date ?? date('Y-m-d H:i:s');


        $request['start_date'] = date('Y-m-d H:i:s', strtotime("$my_datetime UTC"));
        $request['end_date'] = date('Y-m-d H:i:s', strtotime("$my_datetime1 UTC"));

        $client_id = $request->auth->parent_id;
        $search = [];
        $searchString = [];
\Log::info('Callback Debug:', [
    'extension' => $request->extension,
    'alt_extension' => $request->alt_extension,
    'campaign_id' => $request->campaign_id,
    'start_date' => $request->start_date,
    'end_date' => $request->end_date,
]);

        // 🔹 Filter by agent role
        if ($request->auth->role == 2) {
            $search['extension'] = $request->auth->extension;
            $search['alt_extension'] = $request->auth->alt_extension;
            $searchString[] = '(c.extension = :extension OR c.extension = :alt_extension)';
        } elseif ($request->has('extension') && !empty($request->input('extension'))) {
            $search['extension'] = $request->input('extension');

            $objTempUser = User::where('extension', $request->input('extension'))
                ->where('is_deleted', 0)
                ->first();

            $search['alt_extension'] = $objTempUser->alt_extension ?? $request->input('extension');
            $searchString[] = '(c.extension = :extension OR c.extension = :alt_extension)';
        }

        // 🔹 Filter by campaign
        if ($request->has('campaign') && !empty($request->input('campaign'))) {
            $search['campaign_id'] = $request->input('campaign');
            $searchString[] = 'c.campaign_id = :campaign_id';
        }

        // 🔹 Filter by date
        if ($request->has('start_date') && $request->has('end_date')) {
            $start = date('Y-m-d', strtotime($request->input('start_date'))) . " 00:00:00";
            $end = date('Y-m-d', strtotime($request->input('end_date'))) . " 23:59:59";
            $search['start_time'] = $start;
            $search['end_time'] = $end;
            $searchString[] = 'c.callback_time BETWEEN :start_time AND :end_time';
        }

        // 🔹 Handle reminder filter (safe check)
        if ($request->has('reminder') && !empty($request->input('reminder'))) {
            $sql_extension = "
                SELECT GROUP_CONCAT(extension) as extensions 
                FROM master.users 
                WHERE extension IN (
                    SELECT extension 
                    FROM client_{$request->auth->parent_id}.extension_group_map 
                    WHERE is_deleted = 0 
                      AND group_id IN (
                          SELECT group_id 
                          FROM client_{$request->auth->parent_id}.extension_group_map 
                          WHERE is_deleted = 0 
                            AND extension = {$request->auth->extension}
                      )
                )
                AND user_level <= {$request->auth->level}
            ";

            $arrExtensions = DB::connection('mysql_' . $client_id)->select($sql_extension);

            $sql_alt = "
                SELECT GROUP_CONCAT(alt_extension) as alt_extension 
                FROM master.users 
                WHERE alt_extension IN (
                    SELECT extension 
                    FROM client_{$request->auth->parent_id}.extension_group_map 
                    WHERE is_deleted = 0 
                      AND group_id IN (
                          SELECT group_id 
                          FROM client_{$request->auth->parent_id}.extension_group_map 
                          WHERE is_deleted = 0 
                            AND extension = {$request->auth->extension}
                      )
                )
                AND user_level <= {$request->auth->level}
            ";

            $arrExtensions1 = DB::connection('mysql_' . $client_id)->select($sql_alt);

            $strExtensions = $arrExtensions[0]->extensions ?? '';
            $strExtensions1 = $arrExtensions1[0]->alt_extension ?? '';

            // ✅ Fix for empty extensions (avoid IN ())
            $originateRequest = trim($strExtensions . ',' . $strExtensions1, ',');
            if (!empty($originateRequest)) {
                $searchString[] = "c.extension IN ($originateRequest)";
            }

            $search['start_time'] = date('Y-m-d H:i:s', strtotime($request->input('start_date')));
            $search['end_time'] = date('Y-m-d H:i:s', strtotime($request->input('end_date')));
        }

        // Build final WHERE filter
        $filter = (!empty($searchString)) ? " WHERE " . implode(" AND ", $searchString) : '';

        // 🔹 Full SQL Query
        $sql = "
            SELECT 
                c.*,
                ld.*,
                CONCAT_WS(', ', option_1, option_2, option_3, option_4, option_5, option_6, 
                           option_7, option_8, option_9, option_10, option_11, option_12, 
                           option_13, option_14, option_15, option_16, option_17, option_18, 
                           option_19, option_20, option_21, option_22, option_23, option_24, 
                           option_25, option_26, option_27, option_28, option_29, option_30) as list_values,
                x.list_headers,
                y.is_dialing_selected_column
            FROM callback AS c
            JOIN list_data AS ld ON (c.lead_id = ld.id)
            JOIN (
                SELECT lh.list_id, GROUP_CONCAT(lh.header ORDER BY lh.id SEPARATOR ', ') AS list_headers
                FROM list_header AS lh GROUP BY lh.list_id
            ) x ON x.list_id = ld.list_id
            JOIN (
                SELECT lhh.column_name AS is_dialing_selected_column, lhh.list_id
                FROM list_header AS lhh
                WHERE is_dialing = 1
                GROUP BY lhh.list_id
            ) y ON y.list_id = ld.list_id
            $filter
            ORDER BY c.callback_time DESC
        ";

        $record = DB::connection('mysql_' . $client_id)->select($sql, $search);

        if (!empty($record)) {
            return [
                'success' => 'true',
                'message' => 'Callback Data Report.',
                'record_count' => count($record),
                'data' => $record
            ];
        }

        return [
            'success' => 'true',
            'message' => 'No Callback Data Report found.',
            'record_count' => 0,
            'data' => []
        ];
    } catch (\Exception $e) {
        return [
            'success' => 'false',
            'message' => $e->getMessage()
        ];
    }
}

}
