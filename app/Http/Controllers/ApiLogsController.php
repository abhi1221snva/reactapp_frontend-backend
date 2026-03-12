<?php

namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Model\Master\ApiLogs;
use DB;

/**
 * @OA\Get(
 *   path="/api-logs",
 *   summary="List all API logs (superadmin only)",
 *   operationId="apiLogsIndex",
 *   tags={"Admin"},
 *   security={{"Bearer":{}}},
 *   @OA\Response(response=200, description="API logs list"),
 *   @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Post(
 *   path="/api-logs-data",
 *   summary="Get filtered API logs",
 *   operationId="apiLogsGetLogs",
 *   tags={"Admin"},
 *   security={{"Bearer":{}}},
 *   @OA\RequestBody(@OA\JsonContent(
 *     @OA\Property(property="lender_api_type", type="string"),
 *     @OA\Property(property="businessID", type="string"),
 *     @OA\Property(property="start_date", type="string", format="date"),
 *     @OA\Property(property="end_date", type="string", format="date"),
 *     @OA\Property(property="lower_limit", type="integer"),
 *     @OA\Property(property="upper_limit", type="integer")
 *   )),
 *   @OA\Response(response=200, description="Filtered API logs")
 * )
 */
class ApiLogsController extends Controller
{
    public function create(Request $request)
    {
        $api_logs = new ApiLogs();
        $api_logs->endpoint = $request->endpoint;
        $api_logs->method = $request->method;
        $api_logs->request_body = json_encode($request->all());
        $api_logs->response_body = json_encode($request->response_body);
        $api_logs->status_code = $request->status_code;
        $api_logs->client_ip = $request->client_ip;
        $api_logs->client_api_key = $request->client_api_key;
        $api_logs->user_agent = $request->user_agent;
        $api_logs->alt_extension = $request->alt_extension;

        $api_logs->save();

        return $this->successResponse("Log Created Successfully", $api_logs->toArray());




    }
    public function index(){
        try {
            $query = ApiLogs::on("master")->get();
            Log::info('reached query',['query'=>$query]);
            // Check if data exists
            if ($query->isEmpty()) {
                return [
                    'success' => false,
                    'message' => 'Api Log does not exist.',
                    'record_count'=>0,

                ];
            }
     // Count the total records
     $recordCount = $query->count();
            // Return success with data if found
            return [
                'success' => true,
                'message' => 'Api log retrieved successfully.',
                'data' => $query,
                'record_count'=>$recordCount,
            ];
    
        } catch (Exception $e) {
            // Log the generic exception error
            Log::error("Error retrieving Api Log Report: " . $e->getMessage());
        } catch (InvalidArgumentException $e) {
            // Log the specific InvalidArgumentException error
            Log::error("Invalid Argument: " . $e->getMessage());
        }
    
        // Return error response if an exception was caught
        return [
            'success' => false,
            'message' => 'Failed to retrieve Api logs due to an error.'
        ];
    }
    public function getLogs(Request $request)
{
    Log::info('reached', [$request->all()]);

    ini_set('max_execution_time', 1800);

    try {
        $search = [];
        $searchString = [];
        $clientId = $request->auth->parent_id;
        $level = $request->auth->user_level;

        // Filter by lender_api_type
        if ($request->has('lender_api_type') && !empty($request->input('lender_api_type'))) {
            $lender_api_type = $request->input('lender_api_type');
            if (is_array($lender_api_type)) {
                $result = "'" . implode("', '", $lender_api_type) . "'";
                array_push($searchString, " (lender_api_type IN ($result))");
            } else {
                array_push($searchString, " (lender_api_type = :lender_api_type)");
                $search['lender_api_type'] = $lender_api_type;
            }
        }

        // Filter by businessID
        if ($request->has('businessID') && !empty($request->input('businessID'))) {
            $businessID = $request->input('businessID');
            if (is_array($businessID)) {
                $result = "'" . implode("', '", $businessID) . "'";
                array_push($searchString, " (businessID IN ($result))");
            } else {
                array_push($searchString, " (businessID = :businessID)");
                $search['businessID'] = $businessID;
            }
        }

        // Filter by date range
        if ($request->has('start_date') && $request->has('end_date') &&
            !empty($request->input('start_date')) && !empty($request->input('end_date'))) {
            $start = date('Y-m-d', strtotime($request->input('start_date'))) . " 00:00:00";
            $end = date('Y-m-d', strtotime($request->input('end_date'))) . " 23:59:59";
            $search['start_time'] = $start;
            $search['end_time'] = $end;
            array_push($searchString, 'created_at BETWEEN :start_time AND :end_time');
            Log::info('Date Range', ['start_time' => $start, 'end_time' => $end]);
        }

        // Filter by limit
        $limitString = '';
        if ($request->has('lower_limit') && $request->has('upper_limit') &&
            is_numeric($request->input('lower_limit')) && is_numeric($request->input('upper_limit'))) {
            $lower_limit = (int)$request->input('lower_limit');
            $upper_limit = (int)$request->input('upper_limit');
            $limitString = " LIMIT $lower_limit, $upper_limit";
        }

        // Combine filters
        $filter = (!empty($searchString)) ? " WHERE " . implode(" AND ", $searchString) : '';
        $query_string = "SELECT * FROM api_logs AS crm $filter ORDER BY created_at DESC $limitString";

        Log::info('Final Query', ['query' => $query_string, 'bindings' => $search]);

        // Execute query
        $record = DB::connection('master')->select($query_string, $search);

        // Fetch total count without LIMIT
        $recordCount = DB::connection('master')->selectOne("SELECT COUNT(*) as count FROM api_logs $filter", $search);

        if (!empty($record)) {
            return [
                'success' => 'true',
                'message' => 'Api Log Report.',
                'data' => $record,
                'record_count' => $recordCount->count,
            ];
        } else {
            return [
                'success' => 'false',
                'message' => 'No Api Log Report found.',
                'data' => [],
                'record_count' => 0,
            ];
        }
    } catch (\Throwable $exception) {
        Log::error('Error retrieving logs', ['exception' => $exception->getMessage()]);
        return $this->failResponse("Failed to retrieve Lead Data", [$exception->getMessage()], $exception, $exception->getCode());
    }
}

//     public function getLogs(Request $request)
// {
//     Log::info('reached',[$request->auth->parent_id]);

//     ini_set('max_execution_time', 1800);
//     try
//     {
//         $search = array();
//         $searchString = array();
//         $searchString1 = array();
//         $limitString = '';

//         $clientId = $request->auth->parent_id;
//         $leads = [];
//         $level = $request->auth->user_level;
//         if ($request->has('lender_api_type') && !empty($request->input('lender_api_type'))) {
//             $lender_api_type = $request->input('lender_api_type');
        
//             // Check if $lead_status is an array
//             if (is_array($lender_api_type)) {
//                 $result = "'" . implode("', '", $lender_api_type) . "'";
//                 array_push($searchString, " (lender_api_type IN ($result))");
//             } else {
//                 array_push($searchString, " (lender_api_type = '$lender_api_type')");
//             }
//         }
//         if ($request->has('businessID') && !empty($request->input('businessID'))) {
//             $businessID = $request->input('businessID');
        
//             // Check if $lead_status is an array
//             if (is_array($businessID)) {
//                 $result = "'" . implode("', '", $businessID) . "'";
//                 array_push($searchString, " (businessID IN ($result))");
//             } else {
//                 array_push($searchString, " (businessID = '$businessID')");
//             }
//         }

//             if ($request->has('start_date') && $request->has('end_date') && !empty($request->input('start_date')) && !empty($request->input('end_date'))) {
//                 $start = date('Y-m-d', strtotime($request->input('start_date'))) . " 00:00:00";
//                 $end = date('Y-m-d', strtotime($request->input('end_date'))) . " 23:59:59";
//                 $search['start_time'] = $start;
//                 $search['end_time'] = $end;
//                 array_push($searchString, 'created_at BETWEEN :start_time AND :end_time');
            
//                 Log::info('Date Range', ['start_time' => $start, 'end_time' => $end]);
//             }
//             if ($request->has('lower_limit') && $request->has('upper_limit') && is_numeric($request->input('lower_limit')) && is_numeric($request->input('upper_limit')))
//             {
//                 $search['lower_limit'] = $request->input('lower_limit');
//                 $search['upper_limit'] = $request->input('upper_limit');
//                 $limitString = "LIMIT :lower_limit , :upper_limit";
//             }

//             $filter = (!empty($searchString)) ? " WHERE " . implode(" AND ", $searchString) : '';
//             $query_string = "SELECT SQL_CALC_FOUND_ROWS * FROM api_logs AS crm $filter ORDER BY created_at DESC";
            
//             Log::info('Final Query', ['query' => $query_string, 'bindings' => $search]);
            
//             $record = DB::connection('master')->select($query_string,$search,$limitString);
//             Log::info('Final record', ['record' => $record]);
//             // $recordCount = DB::connection('mysql_' . $request->auth->parent_id)->selectOne("SELECT FOUND_ROWS() as count");
//             // $recordCount = (array)$recordCount;

//             if (!empty($record)) {
//                 return [
//                     'success' => 'true',
//                     'message' => 'Call Data Report.',
//                     'data' => $record,
//                     // 'record_count' => $recordCount['count'],

//                 ];
//             } else {
//                 return [
//                     'success' => 'false',
//                     'message' => 'No Call Data Report found.',
//                     'data' => [],
//                     'record_count' => 0,

//                 ];
//             }
            
        

     
//         return $this->successResponse("List of Lead data", $leads);
//     }
//     catch (\Throwable $exception)
//     {
//         return $this->failResponse("Failed to Lead Data ", [$exception->getMessage()], $exception, $exception->getCode());
//     }
// }
}
