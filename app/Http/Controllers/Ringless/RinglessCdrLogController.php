<?php

namespace App\Http\Controllers\Ringless;
use App\Http\Controllers\Controller;
use App\Model\Client\Ringless\RinglessCdrLog;
use App\Model\Master\RinglessVoiceMail;
use Illuminate\Support\Facades\Log;

use Illuminate\Http\Request;
use DB;
class RinglessCdrLogController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    private $request;
    // public function index(Request $request) {
    //     try {
    //         // Attempt to retrieve data from the RinglessCdrLog table
    //         $query = RinglessCdrLog::on("master")->get();
            
    //         // Check if data exists
    //         if ($query->isEmpty()) {
    //             return [
    //                 'success' => false,
    //                 'message' => 'Ringless Call Data Report does not exist.'
    //             ];
    //         }
    
    //         // Return success with data if found
    //         return [
    //             'success' => true,
    //             'message' => 'Ringless Call Data Report retrieved successfully.',
    //             'data' => $query
    //         ];
    
    //     } catch (Exception $e) {
    //         // Log the generic exception error
    //         Log::error("Error retrieving Ringless Call Data Report: " . $e->getMessage());
    //     } catch (InvalidArgumentException $e) {
    //         // Log the specific InvalidArgumentException error
    //         Log::error("Invalid Argument: " . $e->getMessage());
    //     }
    
    //     // Return error response if an exception was caught
    //     return [
    //         'success' => false,
    //         'message' => 'Failed to retrieve Ringless Call Data Report due to an error.'
    //     ];
    // }
    
    public function index(Request $request) {
        try {
            // Step 1: Get client ID from authenticated user
            $clientId = $request->auth->parent_id;
    
            // Step 2: Retrieve API token from clients table
            $client = DB::table('clients')->where('id', $clientId)->first();
            if (!$client || empty($client->api_key)) {
                return [
                    'success' => false,
                    'message' => 'Client API token not found.'
                ];
            }
    
            $apiToken = $client->api_key;
    
            // Step 3: Query RinglessCdrLog with api_token filter
            $query = RinglessCdrLog::on("master")
                ->where('api_token', $apiToken)
                ->get();
    
            // Step 4: Check if data exists
            if ($query->isEmpty()) {
                return [
                    'success' => false,
                    'message' => 'Ringless Cdr Log Report does not exist.'
                ];
            }
    
            // Step 5: Return success with data
            return [
                'success' => true,
                'message' => 'Ringless Cdr Log Report retrieved successfully.',
                'data' => $query
            ];
    
        } catch (Exception $e) {
            Log::error("Error retrieving Ringless Call Data Report: " . $e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::error("Invalid Argument: " . $e->getMessage());
        }
    
        // Step 6: Return error response if any exception occurred
        return [
            'success' => false,
            'message' => 'Failed to retrieve Ringless Call Data Report due to an error.'
        ];
    }
    

    public function rvm(Request $request) {
        
        $gateways = RinglessVoiceMail::on("master")->get()->all();
        return $this->successResponse("gateways List", $gateways);
    
       
   
}
// public function getLog(Request $request)
// {
//     Log::info('reached',[$request->all()]);

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
//         if ($request->has('status') && !empty($request->input('status'))) {
//             $Status = $request->input('status');
        
//             // Check if $lead_status is an array
//             if (is_array($Status)) {
//                 $result = "'" . implode("', '", $Status) . "'";
//                 array_push($searchString, " (status IN ($result))");
//             } else {
//                 // Handle the case where $searchString1 is not an array
//                 // You might want to log an error or handle it in some way
//                 // For now, I'm just adding a simple message to $searchString
//                 array_push($searchString, " (status = '$Status')");
//             }
//         }
 



 

         
//         if ($request->has('phone') && !empty($request->input('phone'))) {
//             // Remove all non-numeric characters from phone
//             $sanitizedPhone = preg_replace('/[^0-9]/', '', $request->input('phone'));
//             $search['phone'] = $sanitizedPhone;
//             array_push($searchString, "REPLACE(REPLACE(REPLACE(phone, '(', ''), ')', ''), '-', '') like CONCAT(:phone, '%')");
//         }
        
//         if ($request->has('cli') && !empty($request->input('cli'))) {
//             // Remove all non-numeric characters from cli
//             $sanitizedCli = preg_replace('/[^0-9]/', '', $request->input('cli'));
//             $search['cli'] = $sanitizedCli;
//             array_push($searchString, "REPLACE(REPLACE(REPLACE(cli, '(', ''), ')', ''), '-', '') like CONCAT(:cli, '%')");
//         }
        

//             if ($request->has('start_date') && $request->has('end_date') && !empty($request->input('start_date')) && !empty($request->input('end_date'))) {
//                 $start = date('Y-m-d', strtotime($request->input('start_date'))) . " 00:00:00";
//                 $end = date('Y-m-d', strtotime($request->input('end_date'))) . " 23:59:59";
//                 $search['start_time'] = $start;
//                 $search['end_time'] = $end;
//                 array_push($searchString, 'created_at BETWEEN :start_time AND :end_time');
            
//                 Log::info('Date Range', ['start_time' => $start, 'end_time' => $end]);
//             }
            
//             $filter = (!empty($searchString)) ? " WHERE " . implode(" AND ", $searchString) : '';
//             $query_string = "SELECT SQL_CALC_FOUND_ROWS * FROM rvm_cdr_log AS crm $filter ORDER BY created_at DESC";
            
//             Log::info('Final Query', ['query' => $query_string, 'bindings' => $search]);
            
//             $record = DB::connection('master')->select($query_string,$search);
//             Log::info('Final record', ['record' => $record]);

//             if (!empty($record)) {
//                 return [
//                     'success' => 'true',
//                     'message' => 'Call Data Report.',
//                     'data' => $record,
//                 ];
//             } else {
//                 return [
//                     'success' => 'false',
//                     'message' => 'No Call Data Report found.',
//                     'data' => [],
//                 ];
//             }
            
        

     
//         return $this->successResponse("List of Lead data", $leads);
//     }
//     catch (\Throwable $exception)
//     {
//         return $this->failResponse("Failed to Lead Data ", [$exception->getMessage()], $exception, $exception->getCode());
//     }
// }
public function getLog(Request $request)
{

    Log::info('Reached', [$request->all()]);
    ini_set('max_execution_time', 1800);
    try {
        $search = [];
        $searchString = [];

        $clientId = $request->auth->parent_id;
        $level = $request->auth->user_level;

        // 1. Get API token from clients table
        $client = DB::table('clients')->where('id', $clientId)->first();
        if (!$client || empty($client->api_key)) {
            return [
                'success' => 'false',
                'message' => 'Client API token not found.',
                'data' => [],
            ];
        }

        $apiToken = $client->api_key;
        Log::info('Client API Token', ['api_token' => $apiToken]);

        // 2. Filter by api_token
        $search['api_token'] = $apiToken;
        array_push($searchString, "api_token = :api_token");

        // 3. Optional filters (status, phone, cli, dates)
        if ($request->has('status') && !empty($request->input('status'))) {
            $Status = $request->input('status');
            if (is_array($Status)) {
                $result = "'" . implode("', '", $Status) . "'";
                array_push($searchString, " (status IN ($result))");
            } else {
                array_push($searchString, " (status = '$Status')");
            }
        }

        // if ($request->has('phone') && !empty($request->input('phone'))) {
        //     $sanitizedPhone = preg_replace('/[^0-9]/', '', $request->input('phone'));
        //     $search['phone'] = $sanitizedPhone;
        //     array_push($searchString, "REPLACE(REPLACE(REPLACE(phone, '(', ''), ')', ''), '-', '') like CONCAT(:phone, '%')");
        // }

        // if ($request->has('cli') && !empty($request->input('cli'))) {
        //     $sanitizedCli = preg_replace('/[^0-9]/', '', $request->input('cli'));
        //     $search['cli'] = $sanitizedCli;
        //     array_push($searchString, "REPLACE(REPLACE(REPLACE(cli, '(', ''), ')', ''), '-', '') like CONCAT(:cli, '%')");
        // }
        if ($request->has('phone') && !empty($request->input('phone'))) {
            // Strip user input to digits only
            $userPhone = preg_replace('/[^0-9]/', '', $request->input('phone'));
        
            // Add wildcard before for right-side match (matches end of the DB number)
            $search['phone'] = '%' . $userPhone;
        
            // Normalize DB field by stripping +, -, (), spaces
            array_push($searchString, "
                REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, '+', ''), '-', ''), '(', ''), ')', ''), ' ', '') 
                LIKE :phone
            ");
        }        
        if ($request->has('cli') && !empty($request->input('cli'))) {
            $userCli = preg_replace('/[^0-9]/', '', $request->input('cli')); // Keep only digits
        
            // Add % before user input for right-end matching
            $search['cli'] = '%' . $userCli;
        
            // In DB: remove all non-digits for consistent search
            array_push($searchString, "
                REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(cli, '+', ''), '-', ''), '(', ''), ')', ''), ' ', '') 
                LIKE :cli
            ");
        }
        
        if ($request->has('start_date') && $request->has('end_date') && !empty($request->input('start_date')) && !empty($request->input('end_date'))) {
            $start = date('Y-m-d', strtotime($request->input('start_date'))) . " 00:00:00";
            $end = date('Y-m-d', strtotime($request->input('end_date'))) . " 23:59:59";
            $search['start_time'] = $start;
            $search['end_time'] = $end;
            array_push($searchString, 'created_at BETWEEN :start_time AND :end_time');
            Log::info('Date Range', ['start_time' => $start, 'end_time' => $end]);
        }

        // 4. Final query
        // $filter = (!empty($searchString)) ? " WHERE " . implode(" AND ", $searchString) : '';
        // $query_string = "SELECT SQL_CALC_FOUND_ROWS * FROM rvm_cdr_log $filter ORDER BY created_at DESC";
// Final filter condition
$filter = (!empty($searchString)) ? " WHERE " . implode(" AND ", $searchString) : '';

// Base query string
$query_string = "SELECT * FROM rvm_cdr_log $filter ORDER BY created_at DESC";

// Append LIMIT clause if valid lower and upper limits are provided
$limitString = '';
if (
    $request->has('lower_limit') &&
    $request->has('upper_limit') &&
    is_numeric($request->input('lower_limit')) &&
    is_numeric($request->input('upper_limit'))
) {
    // Casting to int for safety
    $lower = (int) $request->input('lower_limit');
    $upper = (int) $request->input('upper_limit');

    // SQL LIMIT clause
    $limitString = " LIMIT $lower, $upper";
}

// Final query with optional LIMIT
$sql = $query_string . $limitString;

// Log full query for debugging
Log::info('Executing Final Query', ['query' => $sql, 'bindings' => $search]);

// Execute the query
$record = DB::connection('master')->select($sql, $search);
        Log::info('Final Query', ['query' => $query_string, 'bindings' => $search]);
       // Get total record count using FOUND_ROWS()
        $recordCount = DB::connection('master')
        ->selectOne("SELECT COUNT(*) as count FROM rvm_cdr_log $filter", $search);
        $recordCount = $recordCount ? $recordCount->count : 0;

        // $record = DB::connection('master')->select($query_string, $search);
        // Log::info('Final record', ['record' => $record]);

        if (!empty($record)) {
            return [
                'success' => 'true',
                'message' => 'Call Data Report.',
                'data' => $record,
                'recordCount'=>$recordCount,
            ];
        } else {
            return [
                'success' => 'false',
                'message' => 'No Call Data Report found.',
                'data' => [],
                'recordCount'=> 0,

            ];
        }

    } catch (\Throwable $exception) {
        return $this->failResponse("Failed to fetch Call Data Report.", [$exception->getMessage()], $exception, $exception->getCode());
    }
}

}