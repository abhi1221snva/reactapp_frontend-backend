<?php

namespace App\Http\Controllers\SmsAi;

use App\Http\Controllers\Controller;




use App\Model\Client\CrmLabel;
use App\Model\Client\Lead;
use App\Model\Client\Lists;

use App\Model\Master\DomainList;
use App\Model\Client\SmsAiReport;



use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use App\Model\Role;
use App\Model\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Log;

class SmsAiReportController extends Controller
{
    public function listo(Request $request)
    {
        Log::info('reached', [$request->lead_status]);

        ini_set('max_execution_time', 1800);
        try {
            $search = array();
            $searchString = array();
            $searchString1 = array();
            $limitString = '';

            $clientId = $request->auth->parent_id;
            $leads = [];
            $level = $request->auth->user_level;
            if ($request->has('start_date') && $request->has('end_date') && !empty($request->input('start_date')) && !empty($request->input('end_date'))) {
                $start = date('Y-m-d', strtotime($request->input('start_date'))) . " 00:00:00";
                $end = date('Y-m-d', strtotime($request->input('end_date'))) . " 23:59:59";
                $search['start_time'] = $start;
                $search['end_time'] = $end;
                array_push($searchString, 'created_at BETWEEN :start_time AND :end_time');
            }

            if ($request->has('lower_limit') && $request->has('upper_limit') && is_numeric($request->input('lower_limit')) && is_numeric($request->input('upper_limit'))) {
                $search['lower_limit'] = $request->input('lower_limit');
                $search['upper_limit'] = $request->input('upper_limit');
                $limitString = "LIMIT :lower_limit , :upper_limit";
            }


            if ($level > 1) {

                $filter = (!empty($searchString)) ? " WHERE " . implode(" AND ", $searchString) : '';
                //$query_string = "Select SQL_CALC_FOUND_ROWS * from sms_ai as crm $filter group by number order by created_at desc ";

                //$query_string = "SELECT * FROM sms_ai WHERE ID IN (Select SQL_CALC_FOUND_ROWS * from sms_ai as crm $filter group by number ) order by created_at desc";

                $query_string = "SELECT * FROM sms_ai WHERE id IN (Select  MAX(id) from sms_ai $filter group by number ) order by created_at desc ";



                $sql = $query_string . $limitString;

                // $sql = $query_string ;



                $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql, $search);
                $recordCount = DB::connection('mysql_' . $request->auth->parent_id)->selectOne("SELECT COUNT(*) as count FROM sms_ai WHERE id IN (Select MAX(id) from sms_ai $filter group by number)", $search);
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
            } else {


                //  $leads = Lead::on("mysql_$clientId")->where('assigned_to',$request->auth->id)->orderBy('id','desc')->get()->all();

                if ($request->auth->id) {
                    $search['assigned_to'] = $request->auth->id;
                    array_push($searchString, 'assigned_to = :assigned_to');
                }

                $filter = (!empty($searchString)) ? " WHERE " . implode(" AND ", $searchString) : '';

                $query_string = "Select * from crm_lead_data as crm $filter order by created_at desc ";
                $sql = $query_string . $limitString;

                $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql, $search);
                $recordCount = DB::connection('mysql_' . $request->auth->parent_id)->selectOne("SELECT COUNT(*) as count FROM crm_lead_data $filter", $search);
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
            }

            return $this->successResponse("List of Lead data", $leads);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to Lead Data ", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }


    /**
     * @OA\Post(
     *     path="/sms-ai-email-report",
     *     summary="Get SMS AI Email Report Data",
     *     description="Fetches SMS AI email report data for a specific date based on the time_period_from field.",
     *     tags={"SmsAiReport"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"time_period_from"},
     *             @OA\Property(property="time_period_from", type="string", format="date", example="2025-04-23")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="SMS AI Data",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="SMS AI Data"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(type="object")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad Request",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Invalid input or missing parameters")
     *         )
     *     )
     * )
     */


    public function smsAiEmailReportData(Request $request)
    {
        $from = $request->time_period_from . ' 00:00:00';
        $to = $request->time_period_from . ' 23:59:59';

        //$report = SmsAiReport::on('mysql_'.$request->auth->parent_id)->where('id','1')->get();
        $report = SmsAiReport::on('mysql_' . $request->auth->parent_id)->where('time_period_from', $from)->where('time_period_to', $to)->get();

        return response()->json([
            'message' => 'SMS AI Data ',
            'data' => $report
        ], 201);
    }



    /**
     * @OA\Post(
     *     path="/smsai/reports",
     *     summary="Fetch Call Data Report",
     *     description="Fetches call data report from sms_ai or crm_lead_data depending on user level with optional filters like search, date range, and pagination.",
     *     tags={"SmsAiReport"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="search", type="string", example="98765"),
     *             @OA\Property(property="start_date", type="string", format="date", example="2024-01-01"),
     *             @OA\Property(property="end_date", type="string", format="date", example="2024-01-31"),
     *             @OA\Property(property="lower_limit", type="integer", example=0),
     *             @OA\Property(property="upper_limit", type="integer", example=1000),
     *             @OA\Property(property="lead_status", type="string", example="open")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Call Data Report",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Call Data Report."),
     *             @OA\Property(property="record_count", type="integer", example=20),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(type="object")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to Load Data",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to Load Data")
     *         )
     *     )
     * )
     */


    // public function list(Request $request)
    // {
    //     Log::info('Reached function', ['lead_status' => $request->lead_status]);

    //     ini_set('max_execution_time', 1800);

    //     try {
    //         $search = [];
    //         $searchString = [];
    //         $limitString = '';

    //         $clientId = $request->auth->parent_id;
    //         $level = $request->auth->user_level;
    //         $userId = $request->auth->id;

    //         // Date Range Filter
    //         if ($request->has('start_date') && $request->has('end_date') && !empty($request->input('start_date')) && !empty($request->input('end_date'))) {
    //             $start = date('Y-m-d', strtotime($request->input('start_date'))) . " 00:00:00";
    //             $end = date('Y-m-d', strtotime($request->input('end_date'))) . " 23:59:59";
    //             $search['start_time'] = $start;
    //             $search['end_time'] = $end;
    //             $searchString[] = "created_at BETWEEN ? AND ?";
    //         }

    //         // Pagination Limits
    //         $lowerLimit = (int) $request->input('lower_limit', 0);
    //         $upperLimit = (int) $request->input('upper_limit', 10000);

    //         // Search Term Filter
    //         $searchTerm = null;
    //         if ($request->has('search') && !empty($request->input('search'))) {
    //             $searchTerm = $request->input('search') . '%';
    //             $searchString[] = "(number LIKE ? OR did LIKE ?)";
    //         }

    //         if ($level > 1) {
    //             // ✅ **Case when $level > 1 → Query sms_ai**
    //             $filter = !empty($searchString) ? " WHERE " . implode(" AND ", $searchString) : '';

    //             // ✅ **Fixed SQL Query with Correct Parameter Binding**
    //             $query_string = "SELECT SQL_CALC_FOUND_ROWS *  
    //                         FROM sms_ai  
    //                         WHERE id IN (
    //                             SELECT MAX(id) FROM sms_ai $filter GROUP BY number
    //                         )  
    //                         ORDER BY created_at DESC  
    //                         LIMIT ?, ?";

    //             Log::info('Generated SQL Query for sms_ai', ['query' => $query_string]);

    //             // ✅ **Building Query Parameters**
    //             $queryParams = [];
    //             if (!empty($search['start_time']) && !empty($search['end_time'])) {
    //                 $queryParams[] = $search['start_time'];
    //                 $queryParams[] = $search['end_time'];
    //             }
    //             if ($searchTerm) {
    //                 $queryParams[] = $searchTerm;
    //                 $queryParams[] = $searchTerm;
    //             }
    //             $queryParams[] = $lowerLimit;
    //             $queryParams[] = $upperLimit;

    //             // ✅ **Executing Query**
    //             $record = DB::connection('mysql_' . $clientId)->select($query_string, $queryParams);
    //             $recordCount = DB::connection('mysql_' . $clientId)->selectOne("SELECT FOUND_ROWS() as count");
    //         } else {
    //             // ✅ **Case when $level <= 1 → Query crm_lead_data**
    //             if ($userId) {
    //                 $search['assigned_to'] = $userId;
    //                 $searchString[] = 'assigned_to = ?';
    //             }

    //             $filter = (!empty($searchString)) ? " WHERE " . implode(" AND ", $searchString) : '';

    //             $query_string = "SELECT SQL_CALC_FOUND_ROWS * FROM crm_lead_data as crm $filter ORDER BY created_at DESC LIMIT ?, ?";

    //             Log::info('Generated SQL Query for crm_lead_data', ['query' => $query_string]);

    //             // ✅ **Building Query Parameters**
    //             $queryParams = [];
    //             if (isset($search['assigned_to'])) {
    //                 $queryParams[] = $search['assigned_to'];
    //             }
    //             if (!empty($search['start_time']) && !empty($search['end_time'])) {
    //                 $queryParams[] = $search['start_time'];
    //                 $queryParams[] = $search['end_time'];
    //             }
    //             $queryParams[] = $lowerLimit;
    //             $queryParams[] = $upperLimit;

    //             // ✅ **Executing Query**
    //             $record = DB::connection('mysql_' . $clientId)->select($query_string, $queryParams);
    //             $recordCount = DB::connection('mysql_' . $clientId)->selectOne("SELECT FOUND_ROWS() as count");
    //         }

    //         // ✅ **Processing Query Results**
    //         $recordCount = (array) $recordCount;

    //         if (!empty($record)) {
    //             $data = (array) $record;
    //             return [
    //                 'success' => true,
    //                 'message' => 'Call Data Report.',
    //                 'record_count' => $recordCount['count'] ?? 0,
    //                 'data' => $data
    //             ];
    //         } else {
    //             return [
    //                 'success' => true,
    //                 'message' => 'No Call Data Report found.',
    //                 'record_count' => 0,
    //                 'data' => []
    //             ];
    //         }
    //     } catch (\Throwable $exception) {
    //         Log::error('SQL Query Error', [
    //             'message' => $exception->getMessage(),
    //             'file' => $exception->getFile(),
    //             'line' => $exception->getLine()
    //         ]);
    //         return $this->failResponse("Failed to Load Data", [$exception->getMessage()], $exception, $exception->getCode());
    //     }
    // }
 public function list(Request $request)
{
    Log::info('Reached function', ['lead_status' => $request->lead_status]);

    ini_set('max_execution_time', 1800);

    try {
        $clientId = $request->auth->parent_id;
        $level = $request->auth->user_level;
        $userId = $request->auth->id;

        // Pagination params from DataTables
        $start = $request->input('start', null);       // offset
        $length = $request->input('length', null);     // limit

        // Search value
        $searchTerm = $request->input('search.value', null);

        // Date Range Filter
        $dateFilter = [];
        $searchString = [];
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $startDate = date('Y-m-d 00:00:00', strtotime($request->start_date));
            $endDate = date('Y-m-d 23:59:59', strtotime($request->end_date));
            $dateFilter = [$startDate, $endDate];
            $searchString[] = "created_at BETWEEN ? AND ?";
        }

        // Search filter
        if ($searchTerm) {
            $searchTermLike = $searchTerm . '%';
            $searchString[] = "(number LIKE ? OR did LIKE ?)";
        }

        // Determine if LIMIT should be applied or not
        $applyLimit = true;
        if (is_null($start) || is_null($length) || intval($length) == -1) {
            $applyLimit = false;  // Fetch all records
        } else {
            $start = (int) $start;
            $length = (int) $length;
        }

        if ($level > 1) {
            // Query sms_ai for level > 1
            $whereClause = !empty($searchString) ? " WHERE " . implode(" AND ", $searchString) : '';

            $sql = "SELECT * FROM sms_ai
                    WHERE id IN (
                        SELECT MAX(id) FROM sms_ai $whereClause GROUP BY number
                    )
                    ORDER BY created_at DESC";

            $countParams = [];
            if (!empty($dateFilter)) {
                $countParams = array_merge($countParams, $dateFilter);
            }
            if ($searchTerm) {
                $countParams[] = $searchTermLike;
                $countParams[] = $searchTermLike;
            }

            if ($applyLimit) {
                $sql .= " LIMIT ?, ?";
            }

            $params = $countParams;
            if ($applyLimit) {
                $params[] = $start;
                $params[] = $length;
            }

            $records = DB::connection('mysql_' . $clientId)->select($sql, $params);
            $countObj = DB::connection('mysql_' . $clientId)->selectOne(
                "SELECT COUNT(*) as count FROM sms_ai WHERE id IN (SELECT MAX(id) FROM sms_ai $whereClause GROUP BY number)",
                $countParams
            );
        } else {
            // Query crm_lead_data for level <= 1
            if ($userId) {
                $searchString[] = "assigned_to = ?";
            }

            $whereClause = !empty($searchString) ? " WHERE " . implode(" AND ", $searchString) : '';

            $sql = "SELECT * FROM crm_lead_data $whereClause ORDER BY created_at DESC";

            $countParams = [];
            if ($userId) {
                $countParams[] = $userId;
            }
            if (!empty($dateFilter)) {
                $countParams = array_merge($countParams, $dateFilter);
            }

            if ($applyLimit) {
                $sql .= " LIMIT ?, ?";
            }

            $params = $countParams;
            if ($applyLimit) {
                $params[] = $start;
                $params[] = $length;
            }

            $records = DB::connection('mysql_' . $clientId)->select($sql, $params);
            $countObj = DB::connection('mysql_' . $clientId)->selectOne(
                "SELECT COUNT(*) as count FROM crm_lead_data $whereClause",
                $countParams
            );
        }

        $totalRecords = $countObj->count ?? 0;

        return response()->json([
            'draw' => intval($request->input('draw')),
            'recordsTotal' => $totalRecords,
            'recordsFiltered' => $totalRecords,
            'data' => $records,
        ]);
    } catch (\Throwable $e) {
        Log::error('SQL Query Error', [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
        return response()->json([
            'draw' => intval($request->input('draw')),
            'recordsTotal' => 0,
            'recordsFiltered' => 0,
            'data' => [],
            'error' => 'Failed to load data: ' . $e->getMessage(),
        ]);
    }
}


}