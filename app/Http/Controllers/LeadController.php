<?php

namespace App\Http\Controllers;

use App\Model\Client\CrmLabel;
use App\Model\Client\Lead;
use App\Models\Client\CrmLeadRecord;
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
use App\Model\Client\CrmLeadStatusHistory;
use App\Model\Client\CrmLeadActivity;
use App\Jobs\SendReminderEmail;
use App\Jobs\SendLeadByLenderApi;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

use App\Services\LeadEavService;
use App\Services\LeadLenderService;
use App\Services\LeadQueryService;
use App\Services\LeadTaskService;


class LeadController extends Controller
{
    protected LeadEavService    $eavService;
    protected LeadLenderService $lenderService;
    protected LeadQueryService  $queryService;
    protected LeadTaskService   $taskService;

    public function __construct()
    {
        $this->eavService    = new LeadEavService();
        $this->lenderService = new LeadLenderService();
        $this->queryService  = new LeadQueryService();
        $this->taskService   = new LeadTaskService();
    }

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
            $clientId = $request->auth->parent_id;
            $conn     = $this->tenantDb($request);
            $level    = $request->auth->user_level;

            $filter     = $this->queryService->buildFilters($request, $clientId);
            $conditions = $filter['conditions'];
            $bindings   = $filter['bindings'];

            // Restrict level-1 users to their own leads
            if ($level <= 1 && $request->auth->id) {
                $conditions[] = 'assigned_to = ?';
                $bindings[]   = $request->auth->id;
            }

            $eavLeadIds = $this->queryService->buildEavFilter($request, $conn);
            $limit      = $this->queryService->buildLimitClause($request);
            $result     = $this->queryService->fetchLeads($conn, $conditions, $bindings, $limit, false, 'updated_at', $eavLeadIds);

            if (!empty($result['records'])) {
                $leadIds = array_column($result['records'], 'id');
                $eavMap  = $this->eavService->load($clientId, $leadIds);
                foreach ($result['records'] as $row) {
                    if (isset($eavMap[$row->id])) {
                        foreach ($eavMap[$row->id] as $col => $val) {
                            $row->$col = $val;
                        }
                    }
                }
                Log::info('reached', ['recordCount' => $result['count']]);

                return [
                    'success'      => 'true',
                    'message'      => 'Call Data Report.',
                    'record_count' => $result['count'],
                    'data'         => (array) $result['records'],
                ];
            }

            return [
                'success'      => 'true',
                'message'      => 'No Call Data Report found.',
                'record_count' => 0,
                'data'         => [],
            ];
        } catch (\Throwable $exception) {
            return $this->failResponse('Failed to Lead Data', [$exception->getMessage()], $exception, $exception->getCode());
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
                    array_push($searchString, " (lead_status = '$lead_Status')");
                }
            }

            if ($request->has('lead_type') && !empty($request->input('lead_type'))) {
                $lead_type = $request->input('lead_type');

                if (is_array($lead_type)) {
                    $result = "'" . implode("', '", $lead_type) . "'";
                    array_push($searchString, " (lead_type IN ($result))");
                } else {
                    array_push($searchString, " (lead_type = '$lead_type')");
                }
            }

            if ($request->has('assigned_to') && !empty($request->input('assigned_to'))) {
                $searchString1 = $request->input('assigned_to');
                $result = "'" . implode("', '", $searchString1) . "'";
                array_push($searchString, " (assigned_to IN ($result))");
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
                $dbaColumn = DB::connection($this->tenantDb($request))
                    ->table('crm_label')
                    ->whereRaw('LOWER(title) = ?', ['dba'])
                    ->value('column_name');

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
                $filter = (!empty($searchString)) ? " AND " . implode(" AND ", $searchString) : '';
                $query_string = "Select * from crm_lead_data as crm WHERE is_deleted = 0 $filter order by updated_at desc ";
                $sql = $query_string . $limitString;

                $record = DB::connection($this->tenantDb($request))->select($sql, $search);
                $recordCount = DB::connection($this->tenantDb($request))->selectOne("SELECT COUNT(*) as count FROM crm_lead_data WHERE is_deleted = 0 $filter", $search);
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
                if ($request->auth->id) {
                    $search['assigned_to'] = $request->auth->id;
                    array_push($searchString, 'assigned_to = :assigned_to');
                }

                $filter = (!empty($searchString)) ? " AND " . implode(" AND ", $searchString) : '';
                $query_string = "Select * from crm_lead_data as crm WHERE is_deleted = 0 $filter order by updated_at desc ";
                $sql = $query_string . $limitString;

                $record = DB::connection($this->tenantDb($request))->select($sql, $search);
                $recordCount = DB::connection($this->tenantDb($request))->selectOne("SELECT COUNT(*) as count FROM crm_lead_data WHERE is_deleted = 0 $filter", $search);
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

            return $this->successResponse("List of Lead data", []);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to Lead Data ", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }

    public function listNew(Request $request)
    {
        Log::info('reached lead filter', [$request->all()]);
        ini_set('max_execution_time', 1800);

        try {
            $clientId = $request->auth->parent_id;
            $level    = $request->auth->user_level;

            $filter     = $this->queryService->buildFilters($request, $clientId);
            $conditions = $filter['conditions'];
            $bindings   = $filter['bindings'];

            if ($level <= 1) {
                $conditions[] = 'assigned_to = ?';
                $bindings[]   = $request->auth->id;
            }

            $eavLeadIds = $this->queryService->buildEavFilter($request, "mysql_{$clientId}");
            $limit      = $this->queryService->buildLimitClause($request);
            $result     = $this->queryService->fetchLeads("mysql_{$clientId}", $conditions, $bindings, $limit, false, 'created_at', $eavLeadIds);

            return [
                'success'      => true,
                'message'      => 'Lead Data Retrieved',
                'record_count' => $result['count'],
                'data'         => $result['records'],
            ];
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'message' => 'Failed to Lead Data',
                'errors'  => [$exception->getMessage()],
            ];
        }
    }


    public function sublistNew(Request $request)
    {
        Log::info('reached lead filter', [$request->all()]);
        ini_set('max_execution_time', 1800);

        try {
            $clientId = $request->auth->parent_id;
            $level    = $request->auth->user_level;

            $filter     = $this->queryService->buildFilters($request, $clientId);
            $conditions = $filter['conditions'];
            $bindings   = $filter['bindings'];

            if ($level <= 1) {
                $conditions[] = 'assigned_to = ?';
                $bindings[]   = $request->auth->id;
            }

            $eavLeadIds = $this->queryService->buildEavFilter($request, "mysql_{$clientId}");
            $limit      = $this->queryService->buildLimitClause($request);
            $result     = $this->queryService->fetchLeads("mysql_{$clientId}", $conditions, $bindings, $limit, true, 'created_at', $eavLeadIds);

            return [
                'success'      => true,
                'message'      => 'Lead Data Retrieved',
                'record_count' => $result['count'],
                'data'         => $result['records'],
            ];
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'message' => 'Failed to Lead Data',
                'errors'  => [$exception->getMessage()],
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
            $list->setConnection($this->tenantDb($request));
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

            if (count($query_1) > 0) {
                $save_data = true;

                foreach (array_chunk($header_list, 1000) as $t) {
                    $save_data &= DB::connection($this->tenantDb($request))->table('crm_list_header')->insert($t);
                }

                foreach (array_chunk($query_1, 1000) as $t1) {
                    $save_data &= DB::connection($this->tenantDb($request))->table('crm_lead_data')->insert($t1);
                }

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

        $clientId = $request->auth->parent_id;

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
        Log::info('lead create reached', [$request->all()]);

        $clientId = $request->auth->parent_id;

        $DomainList = DomainList::where('client_id', $clientId)->first();
        $domain_list = $DomainList ? $DomainList->domain_name : '';

        try {
            // Build system-column payload for crm_leads
            $systemData = $this->eavService->formatLeadFields($request->all(), $clientId);
            $systemData['lead_status']    = $systemData['lead_status']    ?? 'new_lead';
            $systemData['lead_parent_id'] = $systemData['lead_parent_id'] ?? 0;
            $systemData['created_by']     = $request->auth->id;
            $closer_id_value              = '["' . $request->auth->id . '"]';
            $systemData['closer_id']      = $closer_id_value;

            // Resolve extension → group_id
            try {
                $user      = User::findOrFail($request->auth->id);
                $extension = $user->extension;
                if ($extension) {
                    $extensionGroups = ExtensionGroupMap::on("mysql_$clientId")
                        ->where('extension', $extension)
                        ->where('is_deleted', 0)
                        ->pluck('group_id');
                    if ($extensionGroups->isNotEmpty()) {
                        $systemData['group_id'] = json_encode(
                            $extensionGroups->map(fn($id) => (string)$id)->toArray()
                        );
                    }
                }
            } catch (\Throwable $e) {}

            $objLead = new CrmLeadRecord($systemData);
            $objLead->setConnection("mysql_$clientId");
            $objLead->saveOrFail();

            $lastId       = $objLead->id;
            $unique_token = $this->generateCode();
            $merchant_url = $domain_list . 'merchant/customer/app/index/' . $clientId . '/' . $lastId . '/' . $unique_token;
            $objLead->unique_url   = '<a href="' . $merchant_url . '">Click Here</a>';
            $objLead->unique_token = $unique_token;
            $objLead->save();

            // Save all dynamic fields (EAV) into crm_lead_values
            $this->eavService->save($clientId, $lastId, $request->all());

            // Log creation activity
            try {
                $activity = new CrmLeadActivity();
                $activity->setConnection("mysql_$clientId");
                $activity->lead_id       = $lastId;
                $activity->user_id       = $request->auth->id;
                $activity->activity_type = 'lead_created';
                $activity->subject       = 'Lead created by ' . ($request->auth->first_name ?? 'user');
                $activity->source_type   = 'api';
                $activity->save();
            } catch (\Throwable $e) {}

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

        try {
            $objLead = CrmLeadRecord::on("mysql_$clientId")->findOrFail($id);

            // Extract system column changes
            $arrFormatLeadInfo = $this->formatLeadInfo($request->all(), $clientId);
            $changedFields     = [];
            foreach ($arrFormatLeadInfo as $strLeadLabel => $strLeadValue) {
                $oldVal = $objLead->getOriginal($strLeadLabel);
                if ((string)$oldVal !== (string)$strLeadValue) {
                    $changedFields[$strLeadLabel] = ['old' => $oldVal, 'new' => $strLeadValue];
                }
                $objLead->$strLeadLabel = $strLeadValue;
            }

            $oldlead_status      = $objLead->getOriginal('lead_status');
            $objLead->group_id   = $request->get('group_id');
            $objLead->updated_by = $request->auth->id;
            $objLead->saveOrFail();

            // Save all dynamic fields (EAV) into crm_lead_values
            $this->eavService->save($clientId, (int)$id, $request->all());

            // ── CRM Activity: log field updates (additive — never breaks existing response) ──
            try {
                $activity = new CrmLeadActivity();
                $activity->setConnection("mysql_$clientId");
                $activity->lead_id       = (int)$id;
                $activity->user_id       = $request->auth->id;
                $activity->activity_type = 'field_update';
                $activity->subject       = 'Lead updated by ' . ($request->auth->first_name ?? 'user');
                $activity->meta          = json_encode(['changed_fields' => $changedFields]);
                $activity->source_type   = 'api';
                $activity->save();
            } catch (\Throwable $e) {}

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

            // ── CRM History & Activity: additive — never breaks existing response ──
            try {
                $history = new CrmLeadStatusHistory();
                $history->setConnection("mysql_$clientId");
                $history->lead_id          = $id;
                $history->user_id          = $request->auth->id;
                $history->from_status      = $oldlead_status;
                $history->to_status        = $request->lead_status;
                $history->from_assigned_to = $oldassigned_to;
                $history->to_assigned_to   = $request->assigned_to;
                $history->from_lead_type   = $oldlead_type;
                $history->to_lead_type     = $request->lead_type;
                $history->triggered_by     = 'agent';
                $history->created_at       = \Carbon\Carbon::now();
                $history->save();
            } catch (\Throwable $e) {}

            try {
                $activity = new CrmLeadActivity();
                $activity->setConnection("mysql_$clientId");
                $activity->lead_id       = $id;
                $activity->user_id       = $request->auth->id;
                $activity->activity_type = 'status_change';
                $activity->subject       = "Status changed from {$oldlead_status} to {$request->lead_status}";
                $activity->meta          = json_encode([
                    'from_status'      => $oldlead_status,
                    'to_status'        => $request->lead_status,
                    'from_assigned_to' => $oldassigned_to,
                    'to_assigned_to'   => $request->assigned_to,
                ]);
                $activity->source_type = 'api';
                $activity->save();
            } catch (\Throwable $e) {}

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

            // ── CRM Activity: log lead deletion (additive — never breaks existing response) ──
            try {
                $activity = new CrmLeadActivity();
                $activity->setConnection("mysql_$clientId");
                $activity->lead_id       = (int)$id;
                $activity->user_id       = $request->auth->id;
                $activity->activity_type = 'system';
                $activity->subject       = 'Lead deleted by ' . ($request->auth->first_name ?? 'user');
                $activity->source_type   = 'api';
                $activity->save();
            } catch (\Throwable $e) {}

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
            $arrLead = CrmLeadRecord::on("mysql_$clientId")->findOrFail($id)->toArray();
            // Merge EAV dynamic field values from crm_lead_values
            $eavMap = $this->eavService->load($clientId, [$id]);
            if (isset($eavMap[$id])) {
                $arrLead = array_merge($arrLead, $eavMap[$id]);
            }
            // Resolve assigned_to / created_by / updated_by display names
            try {
                if (!empty($arrLead['assigned_to'])) {
                    $assignee = User::find($arrLead['assigned_to']);
                    $arrLead['assigned_name'] = $assignee ? trim($assignee->first_name . ' ' . $assignee->last_name) : null;
                }
                if (!empty($arrLead['created_by'])) {
                    $creator = User::find($arrLead['created_by']);
                    $arrLead['created_by_name'] = $creator ? trim($creator->first_name . ' ' . $creator->last_name) : null;
                }
                if (!empty($arrLead['updated_by'])) {
                    $updater = User::find($arrLead['updated_by']);
                    $arrLead['updated_by_name'] = $updater ? trim($updater->first_name . ' ' . $updater->last_name) : null;
                }
            } catch (\Throwable $e) {}
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


    // ─── EAV / field helpers (delegate to LeadEavService) ────────────────────

    public function validateLeadInfo($clientId)
    {
        return $this->eavService->getLabels($clientId);
    }

    public function formatLeadInfo($arrInputLeadInfo, $clientId)
    {
        return $this->eavService->formatLeadFields($arrInputLeadInfo, $clientId);
    }

    private function saveEavFields(string $clientId, int $leadId, array $input): void
    {
        $this->eavService->save($clientId, $leadId, $input);
    }

    private function loadEavForLeads(string $clientId, array $leadIds): array
    {
        return $this->eavService->load($clientId, $leadIds);
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
                $objLead->$strLeadLabel = $strLeadValue;
            }

            $objLead->saveOrFail();
            $this->eavService->save($clientId, (int)$id, $request->all());
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
        $DomainList = DomainList::where('client_id', $request->auth->parent_id)->get()->first();

        $domain_list = $DomainList->domain_name;

        if ($domain_list) {
            $selected_domain = $domain_list;
        } else {
            $selected_domain = env('DOMAIN_NAME');
        }

        $clientId = $request->auth->parent_id;
        //Validation
        $arrValidationRules = $this->validateLeadInfo($clientId);

        try {

            if (isset($request->phone_number) && isset($request->email)) {
                $checkObjLead = Lead::on("mysql_$clientId")->where('phone_number', $request->phone_number)->orWhere('email', $request->email)->get()->first();
            } else
            if (isset($request->phone_number)) {
                $checkObjLead = Lead::on("mysql_$clientId")->where('phone_number', $request->phone_number)->get()->first();
            } else
            if (isset($request->email)) {
                $checkObjLead = Lead::on("mysql_$clientId")->where('email', $request->email)->get()->first();
            }

            if (empty($checkObjLead)) {
                $objLead = new Lead($request->all());
                if (isset($objLead->dob))
                    $objLead->dob = \Carbon\Carbon::parse($objLead->dob)->format('Y-m-d');
                $objLead->setConnection("mysql_$clientId");
                $objLead->saveOrFail();
                $lastId = $objLead->id;
                $unique_token = $this->generateCode();
                $objLeadUpdate = Lead::on("mysql_$clientId")->findOrFail($lastId);

                $merchant_url = $selected_domain . 'merchant/customer/app/index/' . $clientId . '/' . $lastId . '/' . $unique_token;
                $url = '<a href="' . $merchant_url . '">Click Here</a>';

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
            $domain_list = DomainList::on("master")->where('client_id', $request->auth->parent_id)->get()->all();
            return $this->successResponse("Domain List", $domain_list);
        } catch (\Throwable $exception) {
            return $this->failResponse("Failed to domain_list ", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }

    // ─── Lender methods (delegate to LeadLenderService) ──────────────────────

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
     *     @OA\Response(response=200, description="View List of Lenders"),
     *     @OA\Response(response=500, description="Failed to View Lenders")
     * )
     */
    public function SendLeadToLenders(Request $request, $id)
    {
        try {
            $clientId = $request->auth->parent_id;
            return $this->successResponse("View List of Lenders", $this->lenderService->getLeadLenders($clientId, (int) $id));
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
     *     @OA\Response(response=200, description="Successful response: list of lenders"),
     *     @OA\Response(response=500, description="Failed to view lenders")
     * )
     */
    public function LenderStatus(Request $request)
    {
        try {
            $clientId = $request->auth->parent_id;
            return $this->successResponse("View List of Lenders", $this->lenderService->getLenderStatuses($clientId));
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
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"lead_id", "lender_status"},
     *             @OA\Property(property="lead_id", type="integer", example=101),
     *             @OA\Property(property="lender_status", type="integer", example=2)
     *         )
     *     ),
     *     @OA\Response(response=200, description="Status changed successfully"),
     *     @OA\Response(response=404, description="Lender not found"),
     *     @OA\Response(response=500, description="Failed to change status")
     * )
     */
    public function submitLenderStatus(Request $request, $id)
    {
        $clientId = $request->auth->parent_id;
        Log::info('Client ID:', ['clientId' => $clientId]);
        Log::info('Lender ID:', ['lender_id' => $id]);
        Log::info('Lender status:', ['lender_status' => $request->input('lender_status')]);

        try {
            $this->lenderService->updateLenderStatus(
                $clientId,
                (int) $id,
                (int) $request->input('lead_id'),
                (int) $request->input('lender_status'),
                $request->auth->id
            );
            return response()->json(['message' => 'Status Changed Successfully', 'success' => true], 200);
        } catch (ModelNotFoundException $e) {
            Log::error('Lender not found:', ['lender_id' => $id, 'clientId' => $clientId]);
            return response()->json(['message' => 'Lender not found', 'error' => $e->getMessage()], 404);
        } catch (\Exception $e) {
            Log::error('Failed to change status:', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to change status', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * @OA\Put(
     *     path="/lender/notes/add",
     *     summary="Add or Update Lender Notes",
     *     tags={"Lead"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"lender_id", "lead_id", "message"},
     *             @OA\Property(property="lender_id", type="integer", example=5),
     *             @OA\Property(property="lead_id", type="integer", example=101),
     *             @OA\Property(property="message", type="string", example="Follow-up scheduled for next week"),
     *             @OA\Property(property="lender_status", type="integer", example=2)
     *         )
     *     ),
     *     @OA\Response(response=200, description="Note successfully added or updated"),
     *     @OA\Response(response=500, description="Failed to create/update Lender Notes")
     * )
     */
    public function addNotes(Request $request)
    {
        Log::info($request->auth->id);
        try {
            $clientId = $request->auth->parent_id;
            $result   = $this->lenderService->addNote(
                $clientId,
                (int) $request->lender_id,
                (int) $request->lead_id,
                (string) $request->message,
                $request->auth->id,
                $request->lender_status ? (int) $request->lender_status : null
            );

            if ($result['created']) {
                return $this->successResponse("New Lender Note Added Successfully", $result['note']->toArray());
            }

            return $this->successResponse("Lender Notes Updated Successfully", $result['note']->toArray());
        } catch (\Exception $exception) {
            Log::error('Failed to create/update Lender Notes: ' . $exception->getMessage(), [
                'request'   => $request->all(),
                'exception' => $exception,
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
     *     tags={"Lead"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer", example=101)),
     *     @OA\Response(response=200, description="Notes retrieved successfully"),
     *     @OA\Response(response=500, description="Failed to get notes")
     * )
     */
    public function showNotes(Request $request, $id)
    {
        Log::info('reached', [$request->all()]);
        try {
            $clientId = $request->auth->parent_id;
            return $this->successResponse("notes get Successfully", $this->lenderService->getNotesForLead($clientId, (int) $id));
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
     *     tags={"Lead"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"lead_id", "lender_id", "notes"},
     *             @OA\Property(property="lead_id", type="integer", example=101),
     *             @OA\Property(property="lender_id", type="integer", example=5),
     *             @OA\Property(property="notes", type="string", example="Updated follow-up message")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Note updated successfully"),
     *     @OA\Response(response=404, description="Note not found"),
     *     @OA\Response(response=500, description="Failed to update notes")
     * )
     */
    public function editNotes(Request $request)
    {
        Log::info('reached', [$request->all()]);
        try {
            $clientId = $request->auth->parent_id;
            $note     = $this->lenderService->updateNote(
                $clientId,
                (int) $request->get('lead_id'),
                (int) $request->get('lender_id'),
                (string) $request->get('notes')
            );

            if (!$note) {
                return $this->failResponse("Note not found", [], null, 404);
            }

            return $this->successResponse("Notes updated successfully", ['note' => $note]);
        } catch (\Exception $exception) {
            return $this->failResponse("Failed to update notes", [$exception->getMessage()], $exception, 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/documents/lead/{id}",
     *     summary="Get documents by Lead ID",
     *     tags={"Lead"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer", example=123)),
     *     @OA\Response(response=200, description="List of documents"),
     *     @OA\Response(response=500, description="Failed to list of Document Types")
     * )
     */
    public function getLeadData(Request $request, $id)
    {
        try {
            $clientId  = $request->auth->parent_id;
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
     *     tags={"Lead"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"lead_id", "opener"},
     *             @OA\Property(property="lead_id", type="integer", example=101),
     *             @OA\Property(property="opener", type="integer", example=8)
     *         )
     *     ),
     *     @OA\Response(response=200, description="Opener added successfully"),
     *     @OA\Response(response=404, description="Lead Not Found"),
     *     @OA\Response(response=500, description="Failed to update Lead")
     * )
     */
    public function addOpener(Request $request)
    {
        Log::info('reached opener', $request->all());
        $id       = $request->lead_id;
        $clientId = $request->auth->parent_id;

        try {
            $objLead           = Lead::on("mysql_$clientId")->findOrFail($id);
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
     *     tags={"Lead"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"lead_id", "closer"},
     *             @OA\Property(property="lead_id", type="integer", example=101),
     *             @OA\Property(property="closer", type="integer", example=8)
     *         )
     *     ),
     *     @OA\Response(response=200, description="Closer added successfully"),
     *     @OA\Response(response=404, description="Lead Not Found"),
     *     @OA\Response(response=500, description="Failed to update Lead")
     * )
     */
    public function addCloser(Request $request)
    {
        $id       = $request->lead_id;
        $clientId = $request->auth->parent_id;

        try {
            $objLead            = Lead::on("mysql_$clientId")->findOrFail($id);
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

    // ─── Task methods (delegate to LeadTaskService) ───────────────────────────

    /**
     * @OA\Post(
     *     path="/leadTask/add",
     *     summary="Add New Lead Task",
     *     tags={"Lead"},
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"lead_id", "task_name", "date", "time", "notes"},
     *             @OA\Property(property="lead_id", type="integer", example=101),
     *             @OA\Property(property="task_name", type="string", example="Follow-up call"),
     *             @OA\Property(property="date", type="string", format="date", example="2025-05-01"),
     *             @OA\Property(property="time", type="string", format="time", example="14:00:00"),
     *             @OA\Property(property="notes", type="string", example="Call to discuss loan options.")
     *         )
     *     ),
     *     @OA\Response(response=200, description="New task added successfully"),
     *     @OA\Response(response=500, description="Failed to create task")
     * )
     */
    public function addLeadTask(Request $request)
    {
        Log::info($request->auth->id);
        try {
            $clientId = $request->auth->parent_id;
            $task     = $this->taskService->create($clientId, (int) $request->lead_id, $request->all(), $request->auth->id);
            Log::info('reached newNote', ['newNote' => $task]);

            return $this->successResponse("New task Added Successfully", $task->toArray());
        } catch (\Exception $exception) {
            Log::error('Failed to create task: ' . $exception->getMessage(), [
                'request'   => $request->all(),
                'exception' => $exception,
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
     *     tags={"Lead"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(name="lead_id", in="path", required=true, @OA\Schema(type="integer", example=101)),
     *     @OA\Response(response=200, description="Scheduled tasks fetched successfully"),
     *     @OA\Response(response=500, description="Failed to retrieve scheduled tasks")
     * )
     */
    public function CrmScheduledTask(Request $request, $lead_id)
    {
        $clientId = $request->auth->parent_id;
        $tasks    = $this->taskService->getForLead($clientId, (int) $lead_id);
        return response()->json($tasks);
    }

    /**
     * @OA\Get(
     *     path="/crm-scheduled-task/{lead_id}/{id}/delete",
     *     summary="Delete Scheduled Task",
     *     tags={"Lead"},
     *     security={{"Bearer":{}}},
     *     @OA\Parameter(name="lead_id", in="path", required=true, @OA\Schema(type="integer", example=101)),
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer", example=12)),
     *     @OA\Response(response=200, description="Reminder Deleted Successfully"),
     *     @OA\Response(response=404, description="No Task found for lead_id and id"),
     *     @OA\Response(response=500, description="Failed to fetch Lead info")
     * )
     */
    public function deleteTask(Request $request, $lead_id, $id)
    {
        $clientId = $request->auth->parent_id;

        try {
            $this->taskService->delete($clientId, (int) $lead_id, (int) $id);
            return $this->successResponse("Reminder Deleted Successfully");
        } catch (NotFoundHttpException $exception) {
            throw $exception;
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

    /**
     * GET /crm/lead/{id}/lender-submissions
     */
    public function lenderSubmissions(Request $request, $id)
    {
        try {
            $clientId = $request->auth->parent_id;
            return $this->successResponse("Lender Submissions", $this->lenderService->getSubmissions($clientId, (int) $id));
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to load lender submissions", [$e->getMessage()], $e, 500);
        }
    }

    /**
     * POST /crm/lead/{id}/send-to-lender
     */
    public function sendToLender(Request $request, $id)
    {
        $this->validate($request, ['lender_id' => 'required|integer']);

        try {
            $clientId = $request->auth->parent_id;
            $result   = $this->lenderService->submitToLender(
                $clientId,
                (int) $id,
                (int) $request->input('lender_id'),
                $request->input('notes'),
                $request->auth->id
            );
            return $this->successResponse("Lead sent to lender successfully", $result);
        } catch (\RuntimeException $e) {
            return $this->failResponse($e->getMessage(), [], null, 404);
        } catch (\Throwable $e) {
            return $this->failResponse("Failed to send lead to lender", [$e->getMessage()], $e, 500);
        }
    }
}
