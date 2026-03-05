<?php

namespace App\Http\Controllers;

use App\Model\Client\CrmLabel;
use App\Model\Client\Lead;
use App\Model\Client\Lists;
use App\Model\Master\DomainList;
use App\Model\Client\Lender;
use App\Model\Client\ExtensionGroupMap;
use App\Model\Client\CrmSendLeadToLender;
use App\Model\Client\LenderStatus;
use App\Model\Client\Documents;


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
use App\Model\Client\CrmScheduledTask;
use App\Jobs\SendReminderEmail;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;



class LeadController extends Controller
{

    /**
     * @OA\Post(
     *     path="/leads",
     *     summary="Retrieve the Lead List",
     *     tags={"Lead"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *             @OA\Property(property="lead_status", type="array", @OA\Items(type="string")),
     *             @OA\Property(property="lead_type", type="array", @OA\Items(type="string")),
     *             @OA\Property(property="assigned_to", type="array", @OA\Items(type="integer")),
     *             @OA\Property(property="first_name", type="string"),
     *             @OA\Property(property="last_name", type="string"),
     *             @OA\Property(property="crm_id", type="integer"),
     *             @OA\Property(property="phone_number", type="string"),
     *             @OA\Property(property="email", type="string"),
     *             @OA\Property(property="company_name", type="string"),
     *             @OA\Property(property="dba", type="string"),
     *             @OA\Property(property="start_date", type="string", format="date"),
     *             @OA\Property(property="end_date", type="string", format="date"),
     *             @OA\Property(property="lower_limit", type="integer"),
     *             @OA\Property(property="upper_limit", type="integer")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lead list retrieved successfully ",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string"),
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="record_count", type="integer"),
     *             @OA\Property(property="data", type="array", @OA\Items(type="object"))
     *         )
     *     )
     * )
     */

    public function list(Request $request)
    {
        Log::info('reached lead filter', [$request->all()]);

        ini_set('max_execution_time', 1800);
        try {
            $search = array();
            $searchString = array();
            $searchString1 = array();
            $limitString = '';

            $clientId = $request->auth->parent_id;
            $leads = [];
            $level = $request->auth->user_level;
            if ($request->has('lead_status') && !empty($request->input('lead_status'))) {
                $lead_Status = $request->input('lead_status');

                // Check if $lead_status is an array
                if (is_array($lead_Status)) {
                    $result = "'" . implode("', '", $lead_Status) . "'";
                    array_push($searchString, " (lead_status IN ($result))");
                } else {
                    // Handle the case where $searchString1 is not an array
                    // You might want to log an error or handle it in some way
                    // For now, I'm just adding a simple message to $searchString
                    array_push($searchString, " (lead_status = '$lead_Status')");
                }
            }

            if ($request->has('lead_type') && !empty($request->input('lead_type'))) {
                $lead_type = $request->input('lead_type');

                // Check if $lead_type is an array
                if (is_array($lead_type)) {
                    $result = "'" . implode("', '", $lead_type) . "'";
                    array_push($searchString, " (lead_type IN ($result))");
                } else {
                    // Handle the case where $searchString1 is not an array
                    // You might want to log an error or handle it in some way
                    // For now, I'm just adding a simple message to $searchString
                    array_push($searchString, " (lead_type = '$lead_type')");
                }
            }
            // if ($request->has('lead_status') && !empty($request->input('lead_status')))
            //     {
            //         $searchString1 = $request->input('lead_status');
            //         $result = "'" . implode ( "', '", $searchString1 ) . "'";
            //         array_push($searchString, " (lead_status IN ($result))");
            //     }

            if ($request->has('assigned_to') && !empty($request->input('assigned_to'))) {
                $searchString1 = $request->input('assigned_to');
                $result = "'" . implode("', '", $searchString1) . "'";
                array_push($searchString, " (assigned_to IN ($result))");
            }

            // if ($request->has('lead_type') && !empty($request->input('lead_type')))
            // {
            //     $searchString1 = $request->input('lead_type');
            //     $result = "'" . implode ( "', '", $searchString1 ) . "'";
            //     array_push($searchString, " (lead_type IN ($result))");
            // }


            // Global search across name, phone, email, company
            if ($request->has("search") && !empty($request->input("search"))) {
                $globalSearch = $request->input("search");
                $escaped = addslashes($globalSearch);
                array_push($searchString, "(first_name LIKE '%".$escaped."%' OR last_name LIKE '%".$escaped."%' OR phone_number LIKE '%".$escaped."%' OR email LIKE '%".$escaped."%' OR company_name LIKE '%".$escaped."%')");
            }

            if ($request->has('first_name') && !empty($request->input('first_name'))) {
                $search['first_name'] = $request->input('first_name');
                array_push($searchString, "first_name like CONCAT('%',:first_name)");
            }

            if ($request->has('last_name') && !empty($request->input('last_name'))) {
                $search['last_name'] = $request->input('last_name');
                array_push($searchString, "last_name like CONCAT('%',:last_name)");
            }

            if ($request->has('crm_id') && !empty($request->input('crm_id'))) {
                $search['id'] = $request->input('crm_id');
                array_push($searchString, 'id = :id');
            }

            if ($request->has('phone_number') && !empty($request->input('phone_number'))) {
                $search['phone_number'] = $request->input('phone_number');
                array_push($searchString, "phone_number like CONCAT(:phone_number, '%')");
            }

            if ($request->has('email') && !empty($request->input('email'))) {
                $search['email'] = $request->input('email');
                array_push($searchString, "email like CONCAT('%',:email, '%')");
            }

            if ($request->has('company_name') && !empty($request->input('company_name'))) {

                $search['company_name'] = $request->input('company_name');
                array_push($searchString, "company_name like CONCAT('%',:company_name, '%')");
            }
            if ($request->has('dba') && !empty($request->input('dba'))) {
                // Fetch the column name from CrmLabel model using dynamic DB connection
                $dbaColumn = DB::connection('mysql_' . $request->auth->parent_id)
                    ->table('crm_label')
                    ->whereRaw('LOWER(title) = ?', ['dba'])
                    ->value('column_name'); // Assuming 'column_name' holds 'option_40' or similar

                if (!empty($dbaColumn)) {
                    $search[$dbaColumn] = $request->input('dba');
                    array_push($searchString, "$dbaColumn LIKE CONCAT('%', :$dbaColumn, '%')");
                }
            }




            if ($request->has('start_date') && $request->has('end_date') && !empty($request->input('start_date')) && !empty($request->input('end_date'))) {
                $start = date('Y-m-d', strtotime($request->input('start_date'))) . " 00:00:00";
                $end = date('Y-m-d', strtotime($request->input('end_date'))) . " 23:59:59";
                $search['start_time'] = $start;
                $search['end_time'] = $end;
                array_push($searchString, 'updated_at BETWEEN :start_time AND :end_time');
            }

            if ($request->has('lower_limit') && $request->has('upper_limit') && is_numeric($request->input('lower_limit')) && is_numeric($request->input('upper_limit'))) {
                $search['lower_limit'] = $request->input('lower_limit');
                $search['upper_limit'] = $request->input('upper_limit');
                $limitString = "LIMIT :lower_limit , :upper_limit";
            }


            if ($level > 1) {

                // $filter = (!empty($searchString)) ? " WHERE " . implode(" AND ", $searchString) : '';
                $filter = (!empty($searchString)) ? " AND " . implode(" AND ", $searchString) : '';
                $query_string = "Select * from crm_lead_data as crm WHERE is_deleted = 0 $filter order by updated_at desc ";

                $sql = $query_string . $limitString;

                /*  return array(
                    'success' => 'true',
                    'message' => 'Call Data Report.',
                    'record_count' =>0,
                    'data' => $filter
                );*/

                $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql, $search);
                $recordCount = DB::connection('mysql_' . $request->auth->parent_id)->selectOne("SELECT COUNT(*) as count FROM crm_lead_data WHERE is_deleted = 0 $filter", $search);
                $recordCount = (array) $recordCount;
                Log::info('reached', ['recordCount' => $recordCount]);

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

                // $filter = (!empty($searchString)) ? " WHERE " . implode(" AND ", $searchString) : '';
                $filter = (!empty($searchString)) ? " AND " . implode(" AND ", $searchString) : '';
                $query_string = "Select * from crm_lead_data as crm WHERE is_deleted = 0 $filter order by updated_at desc ";
                $sql = $query_string . $limitString;

                $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql, $search);
                $recordCount = DB::connection('mysql_' . $request->auth->parent_id)->selectOne("SELECT COUNT(*) as count FROM crm_lead_data WHERE is_deleted = 0 $filter", $search);
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


    public function list_oldcopy(Request $request)
    {
        Log::info('reached lead filter', [$request->all()]);

        ini_set('max_execution_time', 1800);
        try {
            $search = array();
            $searchString = array();
            $searchString1 = array();
            $limitString = '';

            $clientId = $request->auth->parent_id;
            $leads = [];
            $level = $request->auth->user_level;
            if ($request->has('lead_status') && !empty($request->input('lead_status'))) {
                $lead_Status = $request->input('lead_status');

                // Check if $lead_status is an array
                if (is_array($lead_Status)) {
                    $result = "'" . implode("', '", $lead_Status) . "'";
                    array_push($searchString, " (lead_status IN ($result))");
                } else {
                    // Handle the case where $searchString1 is not an array
                    // You might want to log an error or handle it in some way
                    // For now, I'm just adding a simple message to $searchString
                    array_push($searchString, " (lead_status = '$lead_Status')");
                }
            }

            if ($request->has('lead_type') && !empty($request->input('lead_type'))) {
                $lead_type = $request->input('lead_type');

                // Check if $lead_type is an array
                if (is_array($lead_type)) {
                    $result = "'" . implode("', '", $lead_type) . "'";
                    array_push($searchString, " (lead_type IN ($result))");
                } else {
                    // Handle the case where $searchString1 is not an array
                    // You might want to log an error or handle it in some way
                    // For now, I'm just adding a simple message to $searchString
                    array_push($searchString, " (lead_type = '$lead_type')");
                }
            }
            // if ($request->has('lead_status') && !empty($request->input('lead_status')))
            //     {
            //         $searchString1 = $request->input('lead_status');
            //         $result = "'" . implode ( "', '", $searchString1 ) . "'";
            //         array_push($searchString, " (lead_status IN ($result))");
            //     }

            if ($request->has('assigned_to') && !empty($request->input('assigned_to'))) {
                $searchString1 = $request->input('assigned_to');
                $result = "'" . implode("', '", $searchString1) . "'";
                array_push($searchString, " (assigned_to IN ($result))");
            }

            // if ($request->has('lead_type') && !empty($request->input('lead_type')))
            // {
            //     $searchString1 = $request->input('lead_type');
            //     $result = "'" . implode ( "', '", $searchString1 ) . "'";
            //     array_push($searchString, " (lead_type IN ($result))");
            // }

            if ($request->has('first_name') && !empty($request->input('first_name'))) {
                $search['first_name'] = $request->input('first_name');
                array_push($searchString, "first_name like CONCAT('%',:first_name)");
            }

            if ($request->has('last_name') && !empty($request->input('last_name'))) {
                $search['last_name'] = $request->input('last_name');
                array_push($searchString, "last_name like CONCAT('%',:last_name)");
            }

            if ($request->has('crm_id') && !empty($request->input('crm_id'))) {
                $search['id'] = $request->input('crm_id');
                array_push($searchString, 'id = :id');
            }

            if ($request->has('phone_number') && !empty($request->input('phone_number'))) {
                $search['phone_number'] = $request->input('phone_number');
                array_push($searchString, "phone_number like CONCAT(:phone_number, '%')");
            }

            if ($request->has('email') && !empty($request->input('email'))) {
                $search['email'] = $request->input('email');
                array_push($searchString, "email like CONCAT('%',:email, '%')");
            }

            if ($request->has('company_name') && !empty($request->input('company_name'))) {

                $search['company_name'] = $request->input('company_name');
                array_push($searchString, "company_name like CONCAT('%',:company_name, '%')");
            }
            if ($request->has('dba') && !empty($request->input('dba'))) {
                // Fetch the column name from CrmLabel model using dynamic DB connection
                $dbaColumn = DB::connection('mysql_' . $request->auth->parent_id)
                    ->table('crm_label')
                    ->whereRaw('LOWER(title) = ?', ['dba'])
                    ->value('column_name'); // Assuming 'column_name' holds 'option_40' or similar

                if (!empty($dbaColumn)) {
                    $search[$dbaColumn] = $request->input('dba');
                    array_push($searchString, "$dbaColumn LIKE CONCAT('%', :$dbaColumn, '%')");
                }
            }




            if ($request->has('start_date') && $request->has('end_date') && !empty($request->input('start_date')) && !empty($request->input('end_date'))) {
                $start = date('Y-m-d', strtotime($request->input('start_date'))) . " 00:00:00";
                $end = date('Y-m-d', strtotime($request->input('end_date'))) . " 23:59:59";
                $search['start_time'] = $start;
                $search['end_time'] = $end;
                array_push($searchString, 'updated_at BETWEEN :start_time AND :end_time');
            }

            if ($request->has('lower_limit') && $request->has('upper_limit') && is_numeric($request->input('lower_limit')) && is_numeric($request->input('upper_limit'))) {
                $search['lower_limit'] = $request->input('lower_limit');
                $search['upper_limit'] = $request->input('upper_limit');
                $limitString = "LIMIT :lower_limit , :upper_limit";
            }


            if ($level > 1) {

                // $filter = (!empty($searchString)) ? " WHERE " . implode(" AND ", $searchString) : '';
                $filter = (!empty($searchString)) ? " AND " . implode(" AND ", $searchString) : '';
                $query_string = "Select * from crm_lead_data as crm WHERE is_deleted = 0 $filter order by updated_at desc ";

                $sql = $query_string . $limitString;

                /*  return array(
                    'success' => 'true',
                    'message' => 'Call Data Report.',
                    'record_count' =>0,
                    'data' => $filter
                );*/

                $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql, $search);
                $recordCount = DB::connection('mysql_' . $request->auth->parent_id)->selectOne("SELECT COUNT(*) as count FROM crm_lead_data WHERE is_deleted = 0 $filter", $search);
                $recordCount = (array) $recordCount;
                Log::info('reached', ['recordCount' => $recordCount]);

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

                // $filter = (!empty($searchString)) ? " WHERE " . implode(" AND ", $searchString) : '';
                $filter = (!empty($searchString)) ? " AND " . implode(" AND ", $searchString) : '';
                $query_string = "Select * from crm_lead_data as crm WHERE is_deleted = 0 $filter order by updated_at desc ";
                $sql = $query_string . $limitString;

                $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql, $search);
                $recordCount = DB::connection('mysql_' . $request->auth->parent_id)->selectOne("SELECT COUNT(*) as count FROM crm_lead_data WHERE is_deleted = 0 $filter", $search);
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
    public function listNew(Request $request)
    {
        Log::info('reached lead filter', [$request->all()]);
        ini_set('max_execution_time', 1800);

        try {
            $search = [];
            $searchString = [];
            $limitString = '';
            $clientId = $request->auth->parent_id;
            $leads = [];
            $level = $request->auth->user_level;

            // General search across multiple fields
            if ($request->has('search') && !empty($request->input('search'))) {
                $searchTerm = "%" . $request->input('search') . "%";
                array_push($searchString, "(
                first_name LIKE ? OR 
                last_name LIKE ? OR 
                email LIKE ? OR 
                phone_number LIKE ? OR 
                company_name LIKE ?
            )");

                array_push($search, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
            }

            // Additional filters
            if ($request->has('lead_status') && !empty($request->input('lead_status'))) {
                $leadStatus = $request->input('lead_status');
                if (is_array($leadStatus)) {
                    $result = "'" . implode("','", $leadStatus) . "'";
                    array_push($searchString, "lead_status IN ($result)");
                } else {
                    array_push($searchString, "lead_status = ?");
                    $search[] = $leadStatus;
                }
            }

            if ($request->has('lead_type') && !empty($request->input('lead_type'))) {
                $leadType = $request->input('lead_type');
                if (is_array($leadType)) {
                    $result = "'" . implode("','", $leadType) . "'";
                    array_push($searchString, "lead_type IN ($result)");
                } else {
                    array_push($searchString, "lead_type = ?");
                    $search[] = $leadType;
                }
            }

            if ($request->has('assigned_to') && !empty($request->input('assigned_to'))) {
                $assigned = $request->input('assigned_to');
                if (is_array($assigned)) {
                    $result = "'" . implode("','", $assigned) . "'";
                    array_push($searchString, "assigned_to IN ($result)");
                } else {
                    array_push($searchString, "assigned_to = ?");
                    $search[] = $assigned;
                }
            }

            if ($request->has('crm_id') && !empty($request->input('crm_id'))) {
                array_push($searchString, "id = ?");
                $search[] = $request->input('crm_id');
            }

            if ($request->has('first_name') && !empty($request->input('first_name'))) {
                array_push($searchString, "first_name LIKE ?");
                $search[] = "%" . $request->input('first_name') . "%";
            }

            if ($request->has('last_name') && !empty($request->input('last_name'))) {
                array_push($searchString, "last_name LIKE ?");
                $search[] = "%" . $request->input('last_name') . "%";
            }

            if ($request->has('phone_number') && !empty($request->input('phone_number'))) {
                array_push($searchString, "phone_number LIKE ?");
                $search[] = $request->input('phone_number') . "%";
            }

            if ($request->has('email') && !empty($request->input('email'))) {
                array_push($searchString, "email LIKE ?");
                $search[] = "%" . $request->input('email') . "%";
            }

            if ($request->has('company_name') && !empty($request->input('company_name'))) {
                array_push($searchString, "company_name LIKE ?");
                $search[] = "%" . $request->input('company_name') . "%";
            }

            if ($request->has('dba') && !empty($request->input('dba'))) {
                $dbaColumn = DB::connection('mysql_' . $clientId)
                    ->table('crm_label')
                    ->whereRaw('LOWER(title) = ?', ['dba'])
                    ->value('column_name');
                if (!empty($dbaColumn)) {
                    array_push($searchString, "$dbaColumn LIKE ?");
                    $search[] = "%" . $request->input('dba') . "%";
                }
            }

            if ($request->has('start_date') && $request->has('end_date')) {
                $start = date('Y-m-d 00:00:00', strtotime($request->input('start_date')));
                $end = date('Y-m-d 23:59:59', strtotime($request->input('end_date')));
                array_push($searchString, "updated_at BETWEEN ? AND ?");
                $search[] = $start;
                $search[] = $end;
            }

            if ($request->has('lower_limit') && $request->has('upper_limit')) {
                $lower = (int) $request->input('lower_limit');
                $upper = (int) $request->input('upper_limit');
                $limitString = " LIMIT $lower, $upper ";
            }

            // User restriction
            if ($level <= 1) {
                array_push($searchString, "assigned_to = ?");
                $search[] = $request->auth->id;
            }

            $filter = (!empty($searchString)) ? " AND " . implode(" AND ", $searchString) : '';

            $sql = "SELECT * FROM crm_lead_data WHERE is_deleted = 0 $filter ORDER BY created_at DESC $limitString";

            $records = DB::connection('mysql_' . $clientId)->select($sql, $search);
            $recordCount = DB::connection('mysql_' . $clientId)->selectOne("SELECT COUNT(*) as count FROM crm_lead_data WHERE is_deleted = 0 $filter", $search);
            $recordCount = (array) $recordCount;

            return [
                'success' => true,
                'message' => 'Lead Data Retrieved',
                'record_count' => $recordCount['count'],
                'data' => $records
            ];
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'message' => 'Failed to Lead Data',
                'errors' => [$exception->getMessage()]
            ];
        }
    }


    public function sublistNew(Request $request)
    {
        Log::info('reached lead filter', [$request->all()]);
        ini_set('max_execution_time', 1800);

        try {
            $search = [];
            $searchString = [];
            $limitString = '';
            $clientId = $request->auth->parent_id;
            $leads = [];
            $level = $request->auth->user_level;

            // General search across multiple fields
            if ($request->has('search') && !empty($request->input('search'))) {
                $searchTerm = "%" . $request->input('search') . "%";
                array_push($searchString, "(
                first_name LIKE ? OR 
                last_name LIKE ? OR 
                email LIKE ? OR 
                phone_number LIKE ? OR 
                company_name LIKE ?
            )");

                array_push($search, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
            }

            // Additional filters
            if ($request->has('lead_status') && !empty($request->input('lead_status'))) {
                $leadStatus = $request->input('lead_status');
                if (is_array($leadStatus)) {
                    $result = "'" . implode("','", $leadStatus) . "'";
                    array_push($searchString, "lead_status IN ($result)");
                } else {
                    array_push($searchString, "lead_status = ?");
                    $search[] = $leadStatus;
                }
            }

            if ($request->has('lead_type') && !empty($request->input('lead_type'))) {
                $leadType = $request->input('lead_type');
                if (is_array($leadType)) {
                    $result = "'" . implode("','", $leadType) . "'";
                    array_push($searchString, "lead_type IN ($result)");
                } else {
                    array_push($searchString, "lead_type = ?");
                    $search[] = $leadType;
                }
            }

            if ($request->has('assigned_to') && !empty($request->input('assigned_to'))) {
                $assigned = $request->input('assigned_to');
                if (is_array($assigned)) {
                    $result = "'" . implode("','", $assigned) . "'";
                    array_push($searchString, "assigned_to IN ($result)");
                } else {
                    array_push($searchString, "assigned_to = ?");
                    $search[] = $assigned;
                }
            }

            if ($request->has('crm_id') && !empty($request->input('crm_id'))) {
                array_push($searchString, "id = ?");
                $search[] = $request->input('crm_id');
            }

            if ($request->has('first_name') && !empty($request->input('first_name'))) {
                array_push($searchString, "first_name LIKE ?");
                $search[] = "%" . $request->input('first_name') . "%";
            }

            if ($request->has('last_name') && !empty($request->input('last_name'))) {
                array_push($searchString, "last_name LIKE ?");
                $search[] = "%" . $request->input('last_name') . "%";
            }

            if ($request->has('phone_number') && !empty($request->input('phone_number'))) {
                array_push($searchString, "phone_number LIKE ?");
                $search[] = $request->input('phone_number') . "%";
            }

            if ($request->has('email') && !empty($request->input('email'))) {
                array_push($searchString, "email LIKE ?");
                $search[] = "%" . $request->input('email') . "%";
            }

            if ($request->has('company_name') && !empty($request->input('company_name'))) {
                array_push($searchString, "company_name LIKE ?");
                $search[] = "%" . $request->input('company_name') . "%";
            }

            if ($request->has('dba') && !empty($request->input('dba'))) {
                $dbaColumn = DB::connection('mysql_' . $clientId)
                    ->table('crm_label')
                    ->whereRaw('LOWER(title) = ?', ['dba'])
                    ->value('column_name');
                if (!empty($dbaColumn)) {
                    array_push($searchString, "$dbaColumn LIKE ?");
                    $search[] = "%" . $request->input('dba') . "%";
                }
            }

            if ($request->has('start_date') && $request->has('end_date')) {
                $start = date('Y-m-d 00:00:00', strtotime($request->input('start_date')));
                $end = date('Y-m-d 23:59:59', strtotime($request->input('end_date')));
                array_push($searchString, "updated_at BETWEEN ? AND ?");
                $search[] = $start;
                $search[] = $end;
            }

            // if ($request->has('lower_limit') && $request->has('upper_limit')) {
            //     $lower = (int) $request->input('lower_limit');
            //     $upper = (int) $request->input('upper_limit');
            //     $limitString = " LIMIT $lower, $upper ";
            // }
            // Pagination handling
if ($request->has('start') && $request->has('limit')) {
    $start = (int) $request->input('start');
    $limit = (int) $request->input('limit');
    $limitString = " LIMIT $start, $limit ";
} else {
    // No pagination — fetch all data
    $limitString = '';
}


            // User restriction
            if ($level <= 1) {
                array_push($searchString, "assigned_to = ?");
                $search[] = $request->auth->id;
            }

            $filter = (!empty($searchString)) ? " AND " . implode(" AND ", $searchString) : '';

            $sql = "SELECT * FROM crm_lead_data WHERE is_deleted = 0 AND lead_parent_id != 0 $filter ORDER BY created_at DESC $limitString";

            $records = DB::connection('mysql_' . $clientId)->select($sql, $search);
            $recordCount = DB::connection('mysql_' . $clientId)->selectOne("SELECT COUNT(*) as count FROM crm_lead_data WHERE is_deleted = 0 AND lead_parent_id != 0 $filter", $search);
            $recordCount = (array) $recordCount;

            return [
                'success' => true,
                'message' => 'Lead Data Retrieved',
                'record_count' => $recordCount['count'],
                'data' => $records
            ];
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'message' => 'Failed to Lead Data',
                'errors' => [$exception->getMessage()]
            ];
        }
    }



    /**
     * @OA\Post(
     *     path="/lead/import",
     *     summary="Import lead data from an Excel file",
     *     tags={"Lead"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Excel file and list title",
     *         @OA\JsonContent(
     *             required={"title", "file"},
     *             @OA\Property(
     *                 property="title",
     *                 type="string",
     *                 description="Title of the lead list",
     *                 example="October Campaign Leads"
     *             ),
     *             @OA\Property(
     *                 property="file",
     *                 type="string",
     *                 description="File name of the uploaded Excel (already stored on server)",
     *                 example="lead_data.xlsx"
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="List imported successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="string", example="true"),
     *             @OA\Property(property="message", type="string", example="List added successfully."),
     *             @OA\Property(property="list_id", type="integer", example=12)
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to upload lead data",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to Upload Lead Data"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string"))
     *         )
     *     )
     * )
     */

    public function import(Request $request)
    {
        try {

            $DomainList = DomainList::where('client_id', $request->auth->parent_id)->get()->first();

            $domain_list = $DomainList->domain_name;

            $file_path = env('FILE_UPLOAD_PATH');
            $title = $request->title;
            $reader = Excel::toArray(new Excel(), $file_path . "" . $request->file . "");

            //add list title
            $list = new Lists();
            $list->setConnection("mysql_" . $request->auth->parent_id);
            $list->title = $title;
            $list->saveOrFail();

            $lastId = $list->id;

            if (!empty($reader)) {
                $date_array = array();
                $header_list = [];

                foreach ($reader as $row) {
                    if (is_array($row)) {
                        foreach ($row as $key => $value) {
                            if ($key == 0) {
                                $np = 100;
                                foreach ($value as $em => $ep) {
                                    $h_list['list_id'] = $lastId;

                                    $ncr = ++$np;
                                    $column_name = 'option_' . $ncr;
                                    if ($ncr > 131) {
                                        continue;
                                    }
                                    $h_list['column_name'] = $column_name;
                                    if (empty($ep)) {
                                        $ep = null;
                                    }

                                    $h_list['header'] = $ep;
                                    $check_date = strlen(strrchr(strtolower($ep), "date"));
                                    if (strpos(strtolower($ep), 'date')) {
                                        $date_array[$ncr] = $ncr;
                                    }
                                    if (!empty($h_list['header'])) {
                                        $header_list[] = $h_list;
                                    }
                                }
                            } else {

                                $k = 100;
                                foreach ($value as $emt => $ept) {
                                    $r = ++$k;
                                    if ($r > 131) {
                                        continue;
                                    }
                                    $list_data['list_id'] = $lastId;
                                    $list_data['assigned_to'] = $request->auth->id;
                                    $list_data['created_at'] = date('y-m-d h:i:s');
                                    $list_data['updated_at'] = date('y-m-d h:i:s');
                                    $list_data['unique_token'] = $this->generateCode();
                                    $url = $domain_list . $request->auth->parent_id . '/' . $r . '/' . $list_data['unique_token'];
                                    $list_data['unique_url'] = '<a href="' . $url . '">Click Here</a>';

                                    $list_data['lead_status'] = 'new_lead';
                                    $list_data['option_' . $r] = $ept;
                                    $var_element[] = 'option_' . $r;
                                    if (!empty($date_array[$r])) {
                                        if (is_int($ept)) {
                                            // +1 day difference added with date
                                            $ept = date("Y-m-d", (($ept - 25569) * 86400));
                                            $ept = date('Y-m-d', strtotime('+1 day', strtotime($ept)));
                                        }
                                    }

                                    $var_data[] = $ept;
                                }

                                if (count($list_data) > 0) {
                                    $query_1[] = $list_data;
                                }

                                unset($var_data);
                                unset($var_element);
                                unset($list_data);
                            }
                        }
                    }
                }
            }

            //return $query_1;
            //return $header_list;


            if (count($query_1) > 0) {
                $save_data = true;

                foreach (array_chunk($header_list, 1000) as $t) {
                    $save_data &= DB::connection("mysql_" . $request->auth->parent_id)->table('crm_list_header')->insert($t);
                }

                foreach (array_chunk($query_1, 1000) as $t1) {
                    $save_data &= DB::connection("mysql_" . $request->auth->parent_id)->table('crm_lead_data')->insert($t1);
                }

                $data = [
                    "action" => "List added",
                    "listId" => $lastId,
                    "listName" => $request->input('title'),
                    "records" => count($query_1),
                    "columns" => $header_list
                ];

                return array(
                    'success' => 'true',
                    'message' => 'List added successfully.',
                    'list_id' => $lastId,
                );
            }
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to Upload Lead Data ", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }
    public function copy(Request $request)
    {
        Log::info('lead copy reached', [$request->all()]);

        $phone_new = str_replace(array('(', ')', '_', '-', ' '), array(''), $request->phone_number);

        $request['phone_number'] = $phone_new;

        //return $request->all();
        $clientId = $request->auth->parent_id;
        /*$this->validate($request, ['phone_number' => 'required|string|max:255|unique:'.'mysql_'.$request->auth->parent_id.'.crm_lead_data','email' => 'required|string|unique:'.'mysql_'.$request->auth->parent_id.'.crm_lead_data']);
*/

        $this->validate($request, ['phone_number' => 'nullable|sometimes|max:255:' . 'mysql_' . $request->auth->parent_id . '.crm_lead_data', 'email' => 'nullable|sometimes:' . 'mysql_' . $request->auth->parent_id . '.crm_lead_data']);





        $DomainList = DomainList::where('client_id', $request->auth->parent_id)->get()->first();

        $domain_list = $DomainList->domain_name;

        $clientId = $request->auth->parent_id;

        //Validation
        $arrValidationRules = $this->validateLeadInfo($clientId);
        $this->validate($request, $arrValidationRules);

        try {
            $objLead = new Lead($request->all());
            if (isset($objLead->dob))
                $objLead->dob = \Carbon\Carbon::parse($objLead->dob)->format('Y-m-d');
            $objLead->setConnection("mysql_$clientId");
            $objLead->saveOrFail();

            $lastId = $objLead->id;
            $phone = $objLead->phone_number;
            $phone_new = str_replace(array('(', ')', '_', '-', ' '), array(''), $phone);
            $unique_token = $this->generateCode();
            $merchant_url = $domain_list . 'merchant/customer/app/index/' . $clientId . '/' . $lastId . '/' . $unique_token;
            $url = '<a href="' . $merchant_url . '">Click Here</a>';
            $lead = Lead::on("mysql_$clientId")->findorfail($lastId);
            $lead->unique_url = $url;
            $lead->unique_token = $unique_token;
            $lead->lead_status = 'new_lead';
            $lead->phone_number = $phone_new;
            $lead->created_by = $request->auth->id;
            $lead->signature_image = $request->signature_image;
            $lead->owner_2_signature_image = $request->owner_2_signature_image;
            $lead->owner_2_signature_date =  Carbon::now();
            $lead->created_at =  Carbon::now();
            $lead->updated_at =  Carbon::now();
            $lead->is_copied = "1";
            $lead->copy_lead_id = $request->copy_lead_id;

            $lead->save();
            Log::info('reached', ['lead' => $lead]);
            // Save documents
            if (!empty($request->documents)) {
                foreach ($request->documents as $document) {
                    $newDocument = new Documents();
                    $newDocument->setConnection("mysql_$clientId");
                    $newDocument->lead_id = $lead->id;
                    $newDocument->document_name = $document['document_name'];
                    $newDocument->document_type = $document['document_type'];
                    $newDocument->file_name = $document['file_name'];
                    $newDocument->file_size = $document['file_size'];
                    $newDocument->saveOrFail();
                }
            }


            return $this->successResponse("Lead Added Successfully", $objLead->toArray());
        } catch (\Exception $exception) {
            return $this->failResponse("Failed to create Lead ", [
                $exception->getMessage()
            ], $exception, 500);
        }
    }

    /**
     * @OA\Put(
     *     path="/lead/add",
     *     summary="Create a new lead",
     *     description="Creates a new lead after checking if phone number or email already exists",
     *     tags={"Lead"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Lead creation payload",
     *         @OA\JsonContent(
     *             required={"name", "phone_number", "email"},
     *             @OA\Property(property="name", type="string", example="John Doe"),
     *             @OA\Property(property="phone_number", type="string", example="9876543210"),
     *             @OA\Property(property="email", type="string", example="john@example.com"),
     *             @OA\Property(property="dob", type="string", format="date", example="1990-01-01"),
     *             @OA\Property(property="address", type="string", example="123 Street, City"),
     *             @OA\Property(property="city", type="string", example="New York"),
     *             @OA\Property(property="state", type="string", example="NY"),
     *             @OA\Property(property="zip_code", type="string", example="10001")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lead added successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Lead Added Successfully"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Lead already exists",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Lead already Added")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to create Lead"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string"))
     *         )
     *     )
     * )
     */

    public function create(Request $request)
    {
        Log::info('lead copy reached', [$request->all()]);

        $phone_new = str_replace(array('(', ')', '_', '-', ' '), array(''), $request->phone_number);

        $request['phone_number'] = $phone_new;

        //return $request->all();
        $clientId = $request->auth->parent_id;
        /*$this->validate($request, ['phone_number' => 'required|string|max:255|unique:'.'mysql_'.$request->auth->parent_id.'.crm_lead_data','email' => 'required|string|unique:'.'mysql_'.$request->auth->parent_id.'.crm_lead_data']);
*/

        $this->validate($request, ['phone_number' => 'nullable|sometimes|max:255|unique:' . 'mysql_' . $request->auth->parent_id . '.crm_lead_data', 'email' => 'nullable|sometimes|unique:' . 'mysql_' . $request->auth->parent_id . '.crm_lead_data']);





        $DomainList = DomainList::where('client_id', $request->auth->parent_id)->get()->first();

        $domain_list = $DomainList->domain_name;

        $clientId = $request->auth->parent_id;

        //Validation
        $arrValidationRules = $this->validateLeadInfo($clientId);
        $this->validate($request, $arrValidationRules);

        try {
            $objLead = new Lead($request->all());
            if (isset($objLead->dob))
                $objLead->dob = \Carbon\Carbon::parse($objLead->dob)->format('Y-m-d');
            // Fetch extension from the User class
            $user = User::findOrFail($request->auth->id); // Fetch user by ID
            $extension = $user->extension; // Assuming extension is a column in the User table

            if ($extension) {
                $extensionGroups = ExtensionGroupMap::on("mysql_$clientId")
                    ->where('extension', $extension)
                    ->where('is_deleted', 0)
                    ->pluck('group_id');

                Log::info('extension group checked', ['extensionGroups' => $extensionGroups]);

                if ($extensionGroups->isNotEmpty()) {
                    // Convert all group_ids to an array
                    $group_ids = $extensionGroups->map(function ($id) {
                        return (string)$id; // Ensure IDs are strings
                    })->toArray(); // Convert to array

                    Log::info('extension group id checked', ['group_ids' => $group_ids]);

                    // Add group_ids to lead as JSON string if necessary
                    $objLead->group_id = json_encode($group_ids); // Store as JSON string for database
                } else {
                    Log::warning("No group_id found for extension: {$extension}");
                }
            }

            $objLead->setConnection("mysql_$clientId");
            $objLead->saveOrFail();

            $lastId = $objLead->id;
            $phone = $objLead->phone_number;
            $phone_new = str_replace(array('(', ')', '_', '-', ' '), array(''), $phone);
            $unique_token = $this->generateCode();
            $merchant_url = $domain_list . 'merchant/customer/app/index/' . $clientId . '/' . $lastId . '/' . $unique_token;
            $url = '<a href="' . $merchant_url . '">Click Here</a>';
            $lead = Lead::on("mysql_$clientId")->findorfail($lastId);
            $lead->unique_url = $url;
            $lead->unique_token = $unique_token;
            $lead->phone_number = $phone_new;
            $lead->created_by = $request->auth->id;
            $closer_id_value = '["' . $request->auth->id . '"]';
            $lead->closer_id = $closer_id_value;

            $lead->save();
            return $this->successResponse("Lead Added Successfully", $objLead->toArray());
        } catch (\Exception $exception) {
            return $this->failResponse("Failed to create Lead ", [
                $exception->getMessage()
            ], $exception, 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/lead/{id}/edit",
     *     summary="Update a lead",
     *     tags={"Lead"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Lead ID to be updated",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         description="Updated lead information",
     *         @OA\JsonContent(
     *             example={
     *                 "first_name": "John",
     *                 "last_name": "Doe",
     *                 "email": "john.doe@example.com",
     *                 "phone_number": "(123) 456-7890",
     *                 "dob": "1990-01-01",
     *                 "group_id": 5
     *             }
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lead Updated Successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Lead Updated Successfully"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Lead Not Found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Lead Not Found"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to update Lead",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to update Lead"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string"))
     *         )
     *     )
     * )
     */


    public function update(Request $request, $id)
    {


        $clientId = $request->auth->parent_id;

        //Validation
        $arrValidationRules = $this->validateLeadInfo($clientId);
        $this->validate($request, $arrValidationRules);

        try {
            $objLead = Lead::on("mysql_$clientId")->findOrFail($id);


            $arrFormatLeadInfo = $this->formatLeadInfo($request->all(), $clientId);
            foreach ($arrFormatLeadInfo as $strLeadLabel => $strLeadValue) {
                //if ($objLead->$strLeadLabel != $strLeadValue)
                $objLead->$strLeadLabel = $strLeadValue;
            }

            $oldlead_status = $objLead->getOriginal('lead_status');
            Log::info('reached', ['objLead' => $objLead]);
            $objLead->group_id = $request->get('group_id');
            $objLead->saveOrFail();
            $objLead['old_lead_status'] =  $oldlead_status;
            return $this->successResponse("Lead Updated Successfully", $objLead->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Lead Not Found", [
                "Invalid Lead id: $id"
            ], $exception, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update Lead", [
                $exception->getMessage()
            ], $exception, 404);
        }
    }



    /**
     * @OA\Post(
     *     path="/lead-status/{id}/edit",
     *     summary="Update lead status, type, and assignment",
     *     tags={"Lead"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Lead ID to update",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"lead_status", "lead_type", "assigned_to"},
     *             @OA\Property(property="lead_status", type="string", example="new_lead"),
     *             @OA\Property(property="lead_type", type="string", example="Hot"),
     *             @OA\Property(property="assigned_to", type="integer", example=101)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lead Updated Successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Lead Updated Successfully"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Lead Not Found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Lead Not Found"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to update Lead",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to update Lead"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string"))
     *         )
     *     )
     * )
     */

    public function updateLeadStatus(Request $request, $id)
    {



        $clientId = $request->auth->parent_id;



        try {
            $objLead = Lead::on("mysql_$clientId")->findOrFail($id);
            $objLead->lead_status = $request->lead_status;
            $objLead->lead_type = $request->lead_type;
            $objLead->assigned_to = $request->assigned_to;

            $user = User::findOrFail($request->assigned_to);

            $user_new = $user->first_name . ' ' . $user->last_name;




            $oldlead_status = $objLead->getOriginal('lead_status');
            $oldlead_type = $objLead->getOriginal('lead_type');
            $oldassigned_to = $objLead->getOriginal('assigned_to');

            $user_old = User::findOrFail($oldassigned_to);

            $user_old = $user_old->first_name . ' ' . $user_old->last_name;


            $objLead->saveOrFail();
            $objLead->assigned_to = $user_new;

            $objLead['old_lead_status'] =  $oldlead_status;
            $objLead['old_lead_type']   =  $oldlead_type;
            $objLead['old_assigned_to']   =  $user_old;


            return $this->successResponse("Lead Updated Successfully", $objLead->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Lead Not Found", [
                "Invalid Lead id: $id"
            ], $exception, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update Lead", [
                $exception->getMessage()
            ], $exception, 404);
        }
    }

    /**
     * @OA\Get(
     *     path="/lead/{id}/delete",
     *     summary="delete a lead",
     *     tags={"Lead"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Lead ID to delete",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lead Deleted Successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Lead Deleted Successfully")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Lead Not Found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="No Lead with id {id}")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to delete Lead",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to fetch Lead info")
     *         )
     *     )
     * )
     */

    public function delete(Request $request, $id)
    {
        $clientId = $request->auth->parent_id;

        try {
            $sqlNotifications = "DELETE FROM crm_notifications WHERE lead_id = :lead_id";
            DB::connection("mysql_$clientId")->delete($sqlNotifications, ['lead_id' => $id]);
            // Delete from crm_documents table based on lead_id
            $sqlDocuments = "DELETE FROM crm_documents WHERE lead_id = :lead_id";
            DB::connection("mysql_$clientId")->delete($sqlDocuments, ['lead_id' => $id]);
            // Delete from crm_lead_source_log table based on lead_id
            // $sqlLeadSource = "DELETE FROM crm_lead_source_log WHERE lead_id = :lead_id";
            // DB::connection("mysql_$clientId")->delete($sqlLeadSource, ['lead_id' => $id]);
            // Delete from crm_log table based on lead_id
            // $sqlLog = "DELETE FROM crm_log WHERE lead_id = :lead_id";
            // DB::connection("mysql_$clientId")->delete($sqlLog, ['lead_id' => $id]);
            // // Delete from crm_lead_data table
            // $sql = "DELETE FROM crm_lead_data WHERE id = :id";
            // DB::connection("mysql_$clientId")->delete($sql, ['id' => $id]);
            // Delete from crm_notifications table based on lead_id
            // Soft delete from crm_lead_data table
            $sqlLeadData = "UPDATE crm_lead_data SET deleted_at = NOW(), is_deleted = 1 WHERE id = :id";
            DB::connection("mysql_$clientId")->update($sqlLeadData, ['id' => $id]);
            return $this->successResponse("Lead Deleted Successfully");
        } catch (ModelNotFoundException $exception) {
            throw new NotFoundHttpException("No Lead with id $id");
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch Lead info", [], $exception);
        }
    }

    /**
     * @OA\Get(
     *     path="/lead/{id}",
     *     summary="Get lead details by ID",
     *     description="Fetches lead information for a given ID, scoped by client parent ID",
     *     tags={"Lead"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Lead ID",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lead info fetched successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Lead info"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Lead not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="No Lead with id 1")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to fetch lead info",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to fetch lead info"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string"))
     *         )
     *     )
     * )
     */


    public function show(Request $request, int $id)
    {
        $clientId = $request->auth->parent_id;
        try {
            $arrLead = Lead::on("mysql_$clientId")->findorfail($id)->toArray();
            return $this->successResponse("Lead info", $arrLead);
        } catch (ModelNotFoundException $exception) {
            throw new NotFoundHttpException("No Lead with id $id");
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch lead info", [$exception->getMessage()], $exception);
        }
    }


    public function showByToken(Request $request, $id, $clientId)
    {
        return 1;
        return  $clientId = $clientId;
        try {
            $arrLead = Lead::on("mysql_$clientId")->where('unique_token', $id)->toArray();
            return $this->successResponse("Lead info", $arrLead);
        } catch (ModelNotFoundException $exception) {
            throw new NotFoundHttpException("No Lead with id $id");
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch lead info", [$exception->getMessage()], $exception);
        }
    }



    public function validateLeadInfo($clientId)
    {
        $arrLabels = CrmLabel::on("mysql_$clientId")->where('status', '1')->get()->toArray();

        return $arrLabels;

        foreach ($arrLabels as $key => $label) {
            $strRule = '';

            if ($label['required'])
                $strRule = $strRule . 'required';
            else
                $strRule = $strRule . 'sometimes';

            if ($label['data_type'] == 'date') {
                if (!empty($strRule)) $strRule = $strRule . '|';
                $strRule = $strRule . 'date';
            } elseif (($label['data_type'] == 'text' && $label['title'] == 'email') || ($label['data_type'] == 'text' && $label['title'] == 'Email')) {
                if (!empty($strRule)) $strRule = $strRule . '|';
                $strRule = $strRule . 'email';
            } elseif ($label['data_type'] == 'phone_number') {
                if (!empty($strRule)) $strRule = $strRule . '|';
                $strRule = $strRule . 'regex:/\([0-9]{3}\) [0-9]{3}-[0-9]{4}/';
            } elseif ($label['data_type'] == 'text' || $label['data_type'] == 'select_option') {
                if (!empty($strRule)) $strRule = $strRule . '|';
                $strRule = $strRule . 'string|max:255';
            } elseif ($label['data_type'] == 'date') {
                if (!empty($strRule)) $strRule = $strRule . '|';
                $strRule = $strRule . 'date';
            }
            $arrValidationRules[$label['column_name']] = $strRule;
        }
        return $arrValidationRules;
    }

    public function formatLeadInfo($arrInputLeadInfo, $clientId)
    {
        //$arrLabels = CrmLabel::on("mysql_$clientId")->where(["status" => 1])->get()->toArray();

        $arrLabels = CrmLabel::on("mysql_$clientId")->where('label_title_url', '!=', "unique_url")->where(["status" => 1])->get()->toArray();

        foreach ($arrLabels as $key => $arrLabel) {
            /* if ($arrLabel['data_type'] == 'phone_number')
                $arrInputLeadInfo[$arrLabel['column_name']] = str_replace(array('(',')', '_', '-',' '), array(''), $arrInputLeadInfo[$arrLabel['column_name']]);*/

            $arrInputLeadInfo[$arrLabel['column_name']] = (!empty($arrInputLeadInfo[$arrLabel['column_name']])) ? trim($arrInputLeadInfo[$arrLabel['column_name']]) : '';
        }
        return $arrInputLeadInfo;
    }

    /**
     * @OA\Post(
     *     path="/lead/{id}/view",
     *     summary="Update Lead Details (using view method)",
     *     description="This endpoint updates lead details based on provided input fields.",
     *     tags={"Lead"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Lead ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             example={
     *                 "first_name": "John",
     *                 "email": "john@example.com",
     *                 "group_id": 3
     *             }
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lead Updated Successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Lead Updated Successfully"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Lead Not Found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Invalid Lead id: {id}")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to update Lead",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to update Lead")
     *         )
     *     )
     * )
     */

    public function view(Request $request, $id)
    {
        $clientId = $request->auth->parent_id;

        //Validation
        $arrValidationRules = $this->validateLeadInfo($clientId);
        $this->validate($request, $arrValidationRules);

        try {
            $objLead = Lead::on("mysql_$clientId")->findOrFail($id);


            $arrFormatLeadInfo = $this->formatLeadInfo($request->all(), $clientId);
            foreach ($arrFormatLeadInfo as $strLeadLabel => $strLeadValue) {
                //if ($objLead->$strLeadLabel != $strLeadValue)
                $objLead->$strLeadLabel = $strLeadValue;
            }

            $objLead->saveOrFail();
            return $this->successResponse("Lead Updated Successfully", $objLead->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Lead Not Found", [
                "Invalid Lead id: $id"
            ], $exception, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update Lead", [
                $exception->getMessage()
            ], $exception, 404);
        }
    }


    /**
     * @OA\Put(
     *     path="/lead/addLead",
     *     summary="Create lead from another domain",
     *     tags={"Lead"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"phone_number", "email"},
     *             @OA\Property(property="first_name", type="string", example="John"),
     *             @OA\Property(property="last_name", type="string", example="Doe"),
     *             @OA\Property(property="phone_number", type="string", example="1234567890"),
     *             @OA\Property(property="email", type="string", example="john@example.com"),
     *             @OA\Property(property="dob", type="string", format="date", example="1990-01-01")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lead added successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Lead Added Successfully"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Lead already exists",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Lead already Added")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to create lead",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to create Lead")
     *         )
     *     )
     * )
     */

    //create lead from another domain
    public function createLead(Request $request)
    {
        //return $request->all();
        $DomainList = DomainList::where('client_id', $request->auth->parent_id)->get()->first();

        $domain_list = $DomainList->domain_name;

        if ($domain_list) {
            // $domain_list exists, use it
            $selected_domain = $domain_list;
        } else {
            // $domain_list doesn't exist, get domain_name from env
            $selected_domain = env('DOMAIN_NAME');
        }

        // Now $selected_domain contains the desired value

        $clientId = $request->auth->parent_id;
        //Validation
        $arrValidationRules = $this->validateLeadInfo($clientId);
        //$this->validate($request, $arrValidationRules);

        try {

            if (isset($request->phone_number) && isset($request->email)) {
                $checkObjLead = Lead::on("mysql_$clientId")->where('phone_number', $request->phone_number)->orWhere('email', $request->email)->get()->first();
                //return $this->successResponse("Lead Added Successfully", [$checkObjLead]);
            } else
            if (isset($request->phone_number)) {
                $checkObjLead = Lead::on("mysql_$clientId")->where('phone_number', $request->phone_number)->get()->first();
                // return $this->successResponse("Leads Added Successfully", [$checkObjLead]);
            } else
            if (isset($request->email)) {
                $checkObjLead = Lead::on("mysql_$clientId")->where('email', $request->email)->get()->first();
                //return $this->successResponse("Leads Added Successfully", [$checkObjLead]);
            }

            // $checkObjLead = Lead::on("mysql_$clientId")->where('phone_number',$request->phone_number)->orWhere('email',$request->email)->get()->first();
            // return $this->successResponse("Lead Added Successfully", [$checkObjLead]);

            if (empty($checkObjLead)) {
                //return 1;
                $objLead = new Lead($request->all());
                if (isset($objLead->dob))
                    $objLead->dob = \Carbon\Carbon::parse($objLead->dob)->format('Y-m-d');
                // $objLead->phone = $request->phone_number;
                $objLead->setConnection("mysql_$clientId");
                $objLead->saveOrFail();
                $lastId = $objLead->id;
                $unique_token = $this->generateCode();
                $objLeadUpdate = Lead::on("mysql_$clientId")->findOrFail($lastId);

                $merchant_url = $selected_domain . 'merchant/customer/app/index/' . $clientId . '/' . $lastId . '/' . $unique_token;
                $url = '<a href="' . $merchant_url . '">Click Here</a>';

                // $url = $domain_list.$clientId.'/'.$lastId.'/'.$unique_token;
                $objLeadUpdate->unique_url = $url;
                $objLeadUpdate->unique_token = $unique_token;
                $objLeadUpdate->created_by = $request->auth->id;
                $objLeadUpdate->assigned_to = $request->auth->id;

                $objLeadUpdate->save();

                return $this->successResponse("Lead Added Successfully", $objLead->toArray());
            } else {
                return $this->failResponse("Lead already Added", $checkObjLead->toArray());
            }
        } catch (\Exception $exception) {
            return $this->failResponse("Failed to create Lead ", [
                $exception->getMessage()
            ], $exception, 500);
        }
    }

    public static function generateCode($length = 35)
    {
        $characters = 'abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    /**
     * @OA\Get(
     *     path="/domain-list",
     *     summary="Get Domain List",
     *     description="Fetch the list of domains .",
     *     tags={"Lead"},
     *     security={{"Bearer":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Successful retrieval of domain list",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Domain List"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="client_id", type="integer", example=101),
     *                     @OA\Property(property="domain_name", type="string", example="example.com"),
     *                     @OA\Property(property="created_at", type="string", format="date-time"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to fetch domain list",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to domain_list"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string"))
     *         )
     *     )
     * )
     */

    public function domainList(Request $request)
    {
        try {

            $clientId = $request->auth->parent_id;
            $domain_list = [];
            //$domain_list = DomainList::on("master")->get()->all();

            $domain_list = DomainList::on("master")->where('client_id', $request->auth->parent_id)->get()->all();

            return $this->successResponse("Domain List", $domain_list);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to domain_list ", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }
    /**
     * @OA\Get(
     *     path="/send-lead-to-lenders/{id}",
     *     summary="Send lead to lenders",
     *     description="Fetch the list of lenders who received the lead.",
     *     tags={"Lead"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the lead",
     *         @OA\Schema(type="integer", example=101)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="View List of Lenders",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="View List of Lenders"),
     *            description="extenstion data"
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to View Lenders",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to View Lenders"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string"))
     *         )
     *     )
     * )
     */

    public function SendLeadToLenders(Request $request, $id)
    {
        try {
            $clientId = $request->auth->parent_id;
            $lead_id = $id;
            // Fetch the lenders list
            $SendlendersList = Lender::on("mysql_$clientId")
                ->whereHas('crmSendLeadToLender', function ($query) use ($id) {
                    $query->where('lead_id', $id);
                })
                ->with('crmSendLeadToLender')
                ->get()
                ->toArray(); // Convert the result to an array

            return $this->successResponse("View List of Lenders", $SendlendersList);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to View Lenders", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }


    /**
     * @OA\Get(
     *     path="/lender-status",
     *     summary="Get list of lenders",
     *     description="Returns a list of lenders.",
     *     tags={"Lead"},
     *     security={{"Bearer":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Successful response: list of lenders",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="View List of Lenders"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="name", type="string", example="HDFC Bank"),
     *                     @OA\Property(property="status", type="string", example="active")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to view lenders",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to View Lenders"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string"))
     *         )
     *     )
     * )
     */

    public function LenderStatus(Request $request)
    {
        try {
            $clientId = $request->auth->parent_id;

            // Fetch the lenders list
            $lender_status = LenderStatus::on("mysql_$clientId")
                ->get()
                ->toArray(); // Convert the result to an array

            return $this->successResponse("View List of Lenders", $lender_status);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to View Lenders", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }

    /**
     * @OA\Post(
     *     path="/lender-status/{id}/edit",
     *     summary="Update Lender Status",
     *     description="Update the status of a lender for a specific lead.",
     *     tags={"Lead"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Lender ID",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"lead_id", "lender_status"},
     *             @OA\Property(property="lead_id", type="integer", example=101),
     *             @OA\Property(property="lender_status", type="integer", example=2)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Status changed successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Status Changed Successfully")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Lender not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Lender not found"),
     *             @OA\Property(property="error", type="string", example="No matching lender-lead pair found")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to change status",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to change status"),
     *             @OA\Property(property="error", type="string", example="Something went wrong")
     *         )
     *     )
     * )
     */

    public function submitLenderStatus(Request $request, $id)
    {
        $clientId = $request->auth->parent_id;
        Log::info('Client ID:', ['clientId' => $clientId]);
        Log::info('Lender ID:', ['lender_id' => $id]);
        Log::info('Lender status:', ['lender_status' => $request->input('lender_status')]);

        try {
            // Set up dynamic database connection
            $connectionName = "mysql_$clientId";
            Log::info('Database connection:', ['connection' => $connectionName]);

            // Find the lender by ID
            $lenders = CrmSendLeadToLender::on($connectionName)->where('lender_id', $id)->where('lead_id', $request->input('lead_id'))
                ->get();

            // Update the lender statuses
            foreach ($lenders as $lender) {
                $lender->lender_status_id = $request->input('lender_status');
                $lender->user_id = $request->auth->id;
                $lender->save();
            }


            // Return success response
            return response()->json(['message' => 'Status Changed Successfully', 'success' => true], 200);
        } catch (ModelNotFoundException $e) {
            // Specific catch for ModelNotFoundException
            Log::error('Lender not found:', ['lender_id' => $id, 'clientId' => $clientId]);
            return response()->json(['message' => 'Lender not found', 'error' => $e->getMessage()], 404);
        } catch (\Exception $e) {
            // General exception catch
            Log::error('Failed to change status:', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to change status', 'error' => $e->getMessage()], 500);
        }
    }
    // public function addNotes(Request $request)
    // {
    //     Log::info('reached', [$request->all()]);
    //     try
    //     {
    //         $clientId = $request->auth->parent_id;
    //         $connectionName = "mysql_$clientId";

    //         // Ensure $lenderNotes is always an array
    //         $lenderNotes = (array) $request->input('message');

    //         foreach ($lenderNotes as $lenderNote) {
    //             if (is_array($lenderNote) && isset($lenderNote['lender_id'])) {
    //                 // Check if a record already exists for the lender_id and lead_id
    //                 $existingNote = CrmSendLeadToLender::on($connectionName)
    //                     ->where('lender_id', $lenderNote['lender_id'])
    //                     ->where('lead_id', $request->lead_id)
    //                     ->first();

    //                 if ($existingNote) {
    //                     // Update existing note with new message
    //                     $existingNote->notes = $lenderNote['message'];
    //                     $existingNote->submitted_date = Carbon::now();
    //                     $existingNote->saveOrFail();
    //                 } else {
    //                     // Create new note if record doesn't exist
    //                     $newNote = new CrmSendLeadToLender();
    //                     $newNote->setConnection($connectionName);
    //                     $newNote->lender_id = $lenderNote['lender_id'];
    //                     $newNote->lead_id = $request->lead_id;
    //                     $newNote->notes = $lenderNote['message'];
    //                     $newNote->submitted_date = Carbon::now();            
    //                     $newNote->lender_status = $request->lender_status;

    //                     $newNote->saveOrFail();
    //                 }
    //             } else {
    //                 // Log an error for invalid lender note format and continue processing next note
    //                 Log::error("Invalid lender note format: " . json_encode($lenderNote));
    //             }
    //         }

    //         return $this->successResponse("Lender Notes Added Successfully");
    //     }
    //     catch (\Exception $exception)
    //     {
    //         return $this->failResponse("Failed to create/update Lender Notes", [
    //             $exception->getMessage()
    //         ], $exception, 500);
    //     }
    // }


    /**
     * @OA\Put(
     *     path="/lender/notes/add",
     *     summary="Add or Update Lender Notes",
     *     description="Adds a new note or updates an existing note for a lender-lead pair. If a note already exists and is empty, it will be updated; otherwise, a new note is created.",
     *     tags={"Lead"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"lender_id", "lead_id", "message"},
     *             @OA\Property(property="lender_id", type="integer", example=5, description="Lender ID"),
     *             @OA\Property(property="lead_id", type="integer", example=101, description="Lead ID"),
     *             @OA\Property(property="message", type="string", example="Follow-up scheduled for next week", description="Lender note message"),
     *             @OA\Property(property="lender_status", type="integer", example=2, description="Optional status, used when note does not already exist")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Note successfully added or updated",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="New Lender Note Added Successfully"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to create/update Lender Notes",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to create/update Lender Notes"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string"))
     *         )
     *     )
     * )
     */

    public function addNotes(Request $request)
    {
        Log::info($request->auth->id);
        try {
            $clientId = $request->auth->parent_id;
            $connectionName = "mysql_$clientId";

            // Check if a record already exists for the lender_id and lead_id
            $existingNote = CrmSendLeadToLender::on($connectionName)
                ->where('lender_id', $request->lender_id)
                ->where('lead_id', $request->lead_id)
                ->first();

            if ($existingNote) {
                // If an existing note is found, use its lender_status_id for the new note
                $lenderStatusId = $existingNote->lender_status_id;
            } else {
                // If no existing note is found, use the lender_status_id from the request
                $lenderStatusId = $request->lender_status;
            }

            if ($existingNote && empty($existingNote->notes)) {
                // Update existing note if the notes field is empty
                $existingNote->notes = $request->message;
                $existingNote->submitted_date = Carbon::now();
                $existingNote->lender_status_id = $lenderStatusId;
                $existingNote->created_at = Carbon::now();

                $existingNote->saveOrFail();

                return $this->successResponse("Lender Notes Updated Successfully", $existingNote->toArray());
            } else {
                // Create new note
                $newNote = new CrmSendLeadToLender();
                $newNote->setConnection($connectionName);
                $newNote->lender_id = $request->lender_id;
                $newNote->lead_id = $request->lead_id;
                $newNote->notes = $request->message;
                $newNote->submitted_date = Carbon::now();
                $newNote->lender_status_id = $lenderStatusId;
                $newNote->user_id = $request->auth->id;
                $newNote->created_at = Carbon::now();

                $newNote->saveOrFail();

                return $this->successResponse("New Lender Note Added Successfully", $newNote->toArray());
            }
        } catch (\Exception $exception) {
            Log::error('Failed to create/update Lender Notes: ' . $exception->getMessage(), [
                'request' => $request->all(),
                'exception' => $exception
            ]);
            return $this->failResponse("Failed to create/update Lender Notes ", [
                $exception->getMessage()
            ], $exception, 500);
        }
    }


    /**
     * @OA\Get(
     *     path="/showlenders/{id}",
     *     summary="Show Lender Notes by Lead ID",
     *     description="Retrieve lender notes for the specified lead ID.",
     *     tags={"Lead"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Lead ID",
     *         @OA\Schema(type="integer", example=101)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Notes retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="notes get Successfully"),
     *             description="extension data"    
     *      )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to get notes",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to get notes"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string"))
     *         )
     *     )
     * )
     */
    public function showNotes(Request $request, $id)
    {
        Log::info('reached', [$request->all()]);
        //return $request->all();
        try {
            $clientId = $request->auth->parent_id;
            $connectionName = "mysql_$clientId";

            $LenderNotes = CrmSendLeadToLender::on($connectionName)->where('lead_id', $id)->orderBy('id', 'desc')->get()->all();
            //Log::info('Lender Notes Result:', $LenderNotes);
            return $this->successResponse("notes get Successfully", $LenderNotes);
        } catch (\Exception $exception) {
            return $this->failResponse("Failed to get notes ", [
                $exception->getMessage()
            ], $exception, 500);
        }
    }
    /**
     * @OA\Post(
     *     path="/lender/notes/edit",
     *     summary="Edit Lender Note",
     *     description="Update a lender note using lead_id and lender_id.",
     *     tags={"Lead"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"lead_id", "lender_id", "notes"},
     *             @OA\Property(property="lead_id", type="integer", example=101, description="Lead ID"),
     *             @OA\Property(property="lender_id", type="integer", example=5, description="Lender ID"),
     *             @OA\Property(property="notes", type="string", example="Updated follow-up message", description="Updated note content")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Note updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Notes updated successfully"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Note not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Note not found"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to update notes",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to update notes"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string"))
     *         )
     *     )
     * )
     */

    public function editNotes(Request $request)
    {
        Log::info('reached', [$request->all()]);

        try {
            $clientId = $request->auth->parent_id;
            $connectionName = "mysql_$clientId";

            $LenderNotes = CrmSendLeadToLender::on($connectionName)
                ->where('lead_id', $request->get('lead_id'))
                ->where('lender_id', $request->get('lender_id'))
                ->first();

            if (!$LenderNotes) {
                return $this->failResponse("Note not found", [], null, 404);
            }

            // Update the note content
            $LenderNotes->notes = $request->get('notes');
            $LenderNotes->save();

            // Return a success response with the updated note
            return $this->successResponse("Notes updated successfully", ['note' => $LenderNotes]);
        } catch (\Exception $exception) {
            return $this->failResponse("Failed to update notes", [$exception->getMessage()], $exception, 500);
        }
    }


    /**
     * @OA\Get(
     *     path="/documents/lead/{id}",
     *     summary="Get documents by Lead ID",
     *     description="Returns a list of documents associated with a specific lead.",
     *     tags={"Lead"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Lead ID",
     *         @OA\Schema(type="integer", example=123)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="List of documents",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Documents"),
     *             @OA\Property(property="data", type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="lead_id", type="integer", example=123),
     *                     @OA\Property(property="document_name", type="string", example="PAN Card"),
     *                     @OA\Property(property="document_type", type="string", example="ID Proof"),
     *                     @OA\Property(property="file_name", type="string", example="pan.pdf"),
     *                     @OA\Property(property="file_size", type="integer", example=204800),
     *                     @OA\Property(property="created_at", type="string", format="date-time"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to list of Document Types",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to list of Document Types"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string"))
     *         )
     *     )
     * )
     */


    public function getLeadData(Request $request, $id)
    {
        try {
            $clientId = $request->auth->parent_id;
            $documents = [];
            $documents = Documents::on("mysql_$clientId")->where('lead_id', $id)->orderBy('id', 'ASC')->get()->all();
            return $this->successResponse("Documents", $documents);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to list of Document Types", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }

    /**
     * @OA\Post(
     *     path="/leads/add/opener",
     *     summary="Assign opener to lead",
     *     description="Updates a lead by assigning an opener.",
     *     tags={"Lead"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"lead_id", "opener"},
     *             @OA\Property(property="lead_id", type="integer", example=101, description="ID of the lead"),
     *             @OA\Property(property="opener", type="integer", example=8, description="User ID of the opener to assign")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Opener added successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="opener added Successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=101),
     *                 @OA\Property(property="opener_id", type="integer", example=8),
     *                 @OA\Property(property="lead_status", type="string", example="new_lead"),
     *                 @OA\Property(property="updated_at", type="string", format="date-time")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Lead Not Found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Lead Not Found"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to update Lead",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to update Lead"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string"))
     *         )
     *     )
     * )
     */

    public function addOpener(Request $request)
    {

        Log::info('reached opener', $request->all());
        $id = $request->lead_id;
        $clientId = $request->auth->parent_id;



        try {
            $objLead = Lead::on("mysql_$clientId")->findOrFail($id);
            $objLead->opener_id = $request->opener;
            $objLead->saveOrFail();

            return $this->successResponse("opener added Successfully", $objLead->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Lead Not Found", [
                "Invalid Lead id: $id"
            ], $exception, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update Lead", [
                $exception->getMessage()
            ], $exception, 404);
        }
    }

    /**
     * @OA\Post(
     *     path="/leads/add/closer",
     *     summary="Assign closer to lead",
     *     description="Updates a lead by assigning an closer.",
     *     tags={"Lead"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"lead_id", "closer"},
     *             @OA\Property(property="lead_id", type="integer", example=101, description="ID of the lead"),
     *             @OA\Property(property="closer", type="integer", example=8, description="User ID of the opener to assign")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Closer added successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="opener added Successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=101),
     *                 @OA\Property(property="opener_id", type="integer", example=8),
     *                 @OA\Property(property="lead_status", type="string", example="new_lead"),
     *                 @OA\Property(property="updated_at", type="string", format="date-time")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Lead Not Found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Lead Not Found"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to update Lead",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to update Lead"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string"))
     *         )
     *     )
     * )
     */
    public function addCloser(Request $request)
    {


        $id = $request->lead_id;
        $clientId = $request->auth->parent_id;



        try {
            $objLead = Lead::on("mysql_$clientId")->findOrFail($id);
            $objLead->closer_id = $request->closer;
            $objLead->saveOrFail();

            return $this->successResponse("closer added Successfully", $objLead->toArray());
        } catch (ModelNotFoundException $exception) {
            return $this->failResponse("Lead Not Found", [
                "Invalid Lead id: $id"
            ], $exception, 404);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to update Lead", [
                $exception->getMessage()
            ], $exception, 404);
        }
    }
    /**
     * @OA\Post(
     *     path="/leadTask/add",
     *     summary="Add New Lead Task",
     *     description="Add a new CRM scheduled task for the given lead.",
     *     tags={"Lead"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"lead_id", "task_name", "date", "time", "notes"},
     *             @OA\Property(property="lead_id", type="integer", example=101, description="Lead ID"),
     *             @OA\Property(property="task_name", type="string", example="Follow-up call", description="Name of the task"),
     *             @OA\Property(property="date", type="string", format="date", example="2025-05-01", description="Date for the task"),
     *             @OA\Property(property="time", type="string", format="time", example="14:00:00", description="Time for the task"),
     *             @OA\Property(property="notes", type="string", example="Call to discuss loan options.", description="Additional notes for the task")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="New task added successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="New task Added Successfully"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to create task",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to create/update Lender Notes"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string"))
     *         )
     *     )
     * )
     */

    public function addLeadTask(Request $request)
    {
        Log::info($request->auth->id);
        try {
            $clientId = $request->auth->parent_id;
            $connectionName = "mysql_$clientId";

            // Check if a record already exists for the lender_id and lead_id
            $existingNote = CrmScheduledTask::on($connectionName)
                ->where('lead_id', $request->lead_id)
                ->first();


            // Create new note
            $newNote = new CrmScheduledTask();
            $newNote->setConnection($connectionName);
            $newNote->lead_id = $request->lead_id;
            $newNote->task_name = $request->task_name;
            $newNote->date = $request->date;
            $newNote->time = $request->time;
            $newNote->notes = $request->notes;
            $newNote->user_id = $request->auth->id;
            $newNote->created_at = Carbon::now();
            $newNote->saveOrFail();
            Log::info('reached newNote', ['newNote' => $newNote]);



            return $this->successResponse("New task Added Successfully", $newNote->toArray());
        } catch (\Exception $exception) {
            Log::error('Failed to create/update Lender Notes: ' . $exception->getMessage(), [
                'request' => $request->all(),
                'exception' => $exception
            ]);
            return $this->failResponse("Failed to create/update Lender Notes ", [
                $exception->getMessage()
            ], $exception, 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/crm-scheduled-task/{lead_id}",
     *     summary="Get Scheduled Tasks for a Lead",
     *     description="Retrieve all scheduled CRM tasks associated with the given lead ID.",
     *     tags={"Lead"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="lead_id",
     *         in="path",
     *         required=true,
     *         description="Lead ID",
     *         @OA\Schema(type="integer", example=101)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Scheduled tasks fetched successfully",
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items(type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to retrieve scheduled tasks",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to retrieve scheduled tasks"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string"))
     *         )
     *     )
     * )
     */

    public function CrmScheduledTask(Request $request, $lead_id)
    {
        $clientId = $request->auth->parent_id;
        $connectionName = "mysql_$clientId";

        // Use `get()` instead of `all()`
        $tasks = CrmScheduledTask::on($connectionName)->where('lead_id', $lead_id)->get();

        return response()->json($tasks);
    }

    /**
     * @OA\Get(
     *     path="/crm-scheduled-task/{lead_id}/{id}/delete",
     *     summary="Delete Scheduled Task",
     *     description="Delete a specific CRM scheduled task by lead_id and task id.",
     *     tags={"Lead"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(
     *         name="lead_id",
     *         in="path",
     *         required=true,
     *         description="Lead ID",
     *         @OA\Schema(type="integer", example=101)
     *     ),
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Task ID",
     *         @OA\Schema(type="integer", example=12)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Reminder Deleted Successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="scheduled Task Deleted Successfully")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="No Task found for lead_id and id",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="No Task found for lead_id 101 and id 12")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to fetch Lead info",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to fetch Lead info"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string"))
     *         )
     *     )
     * )
     */

    public function deleteTask(Request $request, $lead_id, $id)
    {
        $clientId = $request->auth->parent_id;

        try {
            // Ensure that both lead_id and id match
            $task = CrmScheduledTask::on("mysql_$clientId")
                ->where('lead_id', $lead_id)  // Match the lead_id
                ->where('id', $id)            // Match the task id
                ->first();                    // Get the first matching record

            if (!$task) {
                throw new NotFoundHttpException("No Task found for lead_id $lead_id and id $id");
            }

            // Proceed to delete the task
            $task->delete();

            return $this->successResponse("Reminder Deleted Successfully");
        } catch (ModelNotFoundException $exception) {
            throw new NotFoundHttpException("No Lead with id $id");
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to fetch Lead info", [], $exception);
        }
    }



    public function sendDataOnWebhook(Request $request, int $id)
    {
        $clientId = $request->auth->parent_id;

        try {
            // Step 1: Fetch lead
            $arrLead = Lead::on("mysql_$clientId")->findOrFail($id)->toArray();

            // Step 2: Get label mapping
            $labels = DB::connection("mysql_$clientId")
                ->table('crm_label')
                ->pluck('label_title_url', 'column_name')
                ->toArray();

            // Step 3: Build display data
            $leadDisplay = [];

            foreach ($arrLead as $column => $value) {
                if (isset($labels[$column])) {
                    $leadDisplay[$labels[$column]] = $value;
                } else {
                    $leadDisplay[$column] = $value;
                }
            }

            // Step 4: Check lead status
            $leadStatusKey = $arrLead['lead_status'] ?? null;

            if (!$leadStatusKey) {
                return $this->failResponse("Lead status not found");
            }

            $status = DB::connection("mysql_$clientId")
                ->table('crm_lead_status')
                ->where('lead_title_url', $leadStatusKey)
                ->first();

            if (!$status || $status->webhook_status != 1 || empty($status->webhook_url)) {
                return $this->failResponse("Webhook URL not setup or status is disabled");
            }

            // Step 5: Send to webhook
            try {
                $httpRequest = Http::withHeaders([
                    'Content-Type' => 'application/json',
                ]);

                // Add token if present
                if (!empty($status->webhook_token)) {
                    $httpRequest = $httpRequest->withToken($status->webhook_token);
                }

                // Determine method (default to POST if not specified)
                $method = strtolower($status->method ?? 'post');

                $filteredData = array_filter(
                    $leadDisplay,
                    fn($key) => !str_starts_with($key, 'option_'),
                    ARRAY_FILTER_USE_KEY
                );

                if ($method === 'get') {
                    $response = $httpRequest->get($status->webhook_url, $filteredData);
                } else {
                    $response = $httpRequest->post($status->webhook_url, $filteredData);
                }

                return $this->successResponse("Webhook triggered successfully");
            } catch (\Exception $e) {
                return $this->failResponse("Webhook call failed", [$e->getMessage()]);
            }
        } catch (ModelNotFoundException $exception) {
            throw new NotFoundHttpException("No Lead found with ID $id");
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to process webhook", [$exception->getMessage()], $exception);
        }
    }
}
