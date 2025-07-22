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

class SmsAiDailyReportController extends Controller
{


    public function list(Request $request)
    {
        Log::info('Reached function', ['lead_status' => $request->lead_status]);

        ini_set('max_execution_time', 1800);

        try {
            $search = [];
            $searchString = [];
            $limitString = '';

            $clientId = $request->auth->parent_id;
            $level = $request->auth->user_level;
            $userId = $request->auth->id;

            // Date Range Filter
            if ($request->has('start_date') && $request->has('end_date') && !empty($request->input('start_date')) && !empty($request->input('end_date'))) {
                $start = date('Y-m-d', strtotime($request->input('start_date'))) . " 00:00:00";
                $end = date('Y-m-d', strtotime($request->input('end_date'))) . " 23:59:59";
                $search['start_time'] = $start;
                $search['end_time'] = $end;
                $searchString[] = "time_period_from BETWEEN ? AND ?";
            }

            // Pagination Limits
            $lowerLimit = (int) $request->input('lower_limit', 0);
            $upperLimit = (int) $request->input('upper_limit', 10000);

            // Search Term Filter
            $searchTerm = null;
            if ($request->has('search') && !empty($request->input('search'))) {
                $searchTerm = $request->input('search') . '%';
                $searchString[] = "(number LIKE ? OR did LIKE ?)";
            }


            $filter = !empty($searchString) ? " WHERE " . implode(" AND ", $searchString) : '';

            // ✅ **Fixed SQL Query with Correct Parameter Binding**
            /*  $query_string = "SELECT SQL_CALC_FOUND_ROWS *  
                            FROM sms_ai_report  
                            WHERE id IN (
                                SELECT MAX(id) FROM sms_ai_report $filter
                            )  
                            ORDER BY created_at DESC  
                            LIMIT ?, ?";*/

            $query_string = "SELECT SQL_CALC_FOUND_ROWS *  
                            FROM sms_ai_report  
                            $filter
                             
                            ORDER BY  time_period_from  DESC  
                            LIMIT 0, 1";
            Log::info('Generated SQL Query for sms_ai', ['query' => $query_string]);

            // ✅ **Building Query Parameters**
            $queryParams = [];
            if (!empty($search['start_time']) && !empty($search['end_time'])) {
                $queryParams[] = $search['start_time'];
                $queryParams[] = $search['end_time'];
            }
            if ($searchTerm) {
                $queryParams[] = $searchTerm;
                $queryParams[] = $searchTerm;
            }
            $queryParams[] = $lowerLimit;
            $queryParams[] = $upperLimit;

            // ✅ **Executing Query**
            $record = DB::connection('mysql_' . $clientId)->select($query_string, $queryParams);




            if (!empty($record)) {
                $data = (array) $record;
                return [
                    'success' => true,
                    'message' => 'Daily Call Report.',
                    'data' => $data
                ];
            } else {
                return [
                    'success' => true,
                    'message' => 'No Daily Call Report found.',
                    'data' => []
                ];
            }
        } catch (\Throwable $exception) {
            Log::error('SQL Query Error', [
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine()
            ]);
            return $this->failResponse("Failed to Load Data", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }
}
