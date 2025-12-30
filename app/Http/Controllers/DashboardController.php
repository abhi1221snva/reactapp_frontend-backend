<?php

namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Model\Dids;
use App\Model\Lists;
use App\Services\ReportService;
use Carbon\Carbon;


class DashboardController extends Controller
{
    public function indexold(Request $request)
    {

        $dashboard = array();
        try
        {
            $clientId = $request->auth->parent_id;
            $leadstatus = [];
            $level = $request->auth->user_level;
          

            $dashboard['leadstatus'] = $leadstatus;
            // $dashboard['totalLeads'] = $totalLeads;
            $dashboard['totalDids']  = $totalDids;
            $dashboard['totalSMS']   = $totalSMS;







            return $this->successResponse("Label Status", $dashboard);
        }
        catch (\Throwable $exception)
        {
            return $this->failResponse("Failed to list extension groups", [$exception->getMessage()], $exception, $exception->getCode());
        }
    }
    /**
 * @OA\POST(
 *     path="/dashboard",
 *     operationId="getDashboardSummary",
 *     tags={"Dashboard"},
 *     summary="Get dashboard summary for the authenticated user",
 *     description="Returns counts for users, campaigns, DIDs, leads, callbacks, lists, SMS, and voicemails for the given time range.",
 *     security={{"Bearer":{}}},
 *     
 *     @OA\Parameter(
 *         name="startTime",
 *         in="query",
 *         required=false,
 *         description="Start time for the stats range (format: Y-m-d H:i:s)",
 *         @OA\Schema(type="string", format="date-time", example="2024-01-01 00:00:00")
 *     ),
 *     @OA\Parameter(
 *         name="endTime",
 *         in="query",
 *         required=false,
 *         description="End time for the stats range (format: Y-m-d H:i:s)",
 *         @OA\Schema(type="string", format="date-time", example="2024-12-31 23:59:59")
 *     ),
 *     
 *     @OA\Response(
 *         response=200,
 *         description="Dashboard Summary",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="boolean", example=true),
 *             @OA\Property(property="message", type="string", example="Dashboard Summary"),
 *             @OA\Property(
 *                 property="data",
 *                 type="object",
 *                 @OA\Property(property="totalCallbacks", type="integer", example=42),
 *                 @OA\Property(property="totalUsers", type="integer", example=10),
 *                 @OA\Property(property="totalCampaigns", type="integer", example=5),
 *                 @OA\Property(property="totalDids", type="integer", example=7),
 *                 @OA\Property(property="totalLeads", type="integer", example=2000),
 *                 @OA\Property(property="totalList", type="integer", example=15),
 *                 @OA\Property(property="incomingSms", type="integer", example=120),
 *                 @OA\Property(property="outgoingSms", type="integer", example=95),
 *                 @OA\Property(property="unreadVoicemail", type="integer", example=8),
 *                 @OA\Property(property="receivedVoicemail", type="integer", example=20)
 *             )
 *         )
 *     ),
 *     
 *     @OA\Response(
 *         response=500,
 *         description="Failed to load dashboard",
 *         @OA\JsonContent(
 *             @OA\Property(property="success", type="boolean", example=false),
 *             @OA\Property(property="message", type="string", example="Failed to load dashboard"),
 *             @OA\Property(property="errors", type="array", @OA\Items(type="string"))
 *         )
 *     )
 * )
 */

    public function index(Request $request)
{
    $dashboard = [];

    try {
        $clientId = $request->auth->parent_id;
        $leadstatus = [];
        $level = $request->auth->user_level;

        // Get extension count
        $extensionCountResult = $this->getExtensionCount($request);
        $extensionCount = ($extensionCountResult['success'] === 'true') ? $extensionCountResult['data'] : 0;

        // Get campaign count
        $campaignCountResult = $this->getCampaignCount($request);
        $campaignCount = ($campaignCountResult['success'] === 'true') ? $campaignCountResult['data'] : 0;
            // ✅ Get direct DID count without JsonResponse
        $didCount = $this->getDidCount($request);
        $ListCount = $this->countList($request);
        
        
        // Get campaign count
        $LeadCountResult = $this->getLeadCount($request);
        $LeadCount = ($LeadCountResult['success'] === 'true') ? $LeadCountResult['data'] : 0;
        $callbackResult = $this->getCallBack($request);
        $callbackCount = ($callbackResult['success'] === 'true') ? $callbackResult['record_count'] : 0;
        
        $dashboard['totalCallbacks'] = $callbackCount;
        $dashboard['totalUsers']      = $extensionCount;
        $dashboard['totalCampaigns']       = $campaignCount;
        $dashboard['totalDids']       = $didCount;
        $dashboard['totalLeads']       = $LeadCount;
        $dashboard['totalList']       = $ListCount;
  $startTime = $request->startTime ?? Carbon::now()->subYear()->format('Y-m-d H:i:s');
$endTime = $request->endTime ?? Carbon::now()->format('Y-m-d H:i:s');


$SMSCountResponse = $this->getSmsCount($request, $startTime, $endTime);

$responseData = $SMSCountResponse->getData();

$smsCounts = (array) $responseData->data;

$dashboard['incomingSms'] = $smsCounts['incoming'] ?? 0;
$dashboard['outgoingSms'] = $smsCounts['outgoing'] ?? 0;
$VoicemailCountResponse = $this->getVoicemailCount($request, $startTime, $endTime);

// Extract data from JsonResponse object
$responseData = $VoicemailCountResponse->getData();
$voicemailData = (array) $responseData->data;

$unread = $voicemailData['unread'] ?? 0;
$read   = $voicemailData['read'] ?? 0;

$dashboard['unreadVoicemail']   = $unread;
$dashboard['receivedVoicemail']     = $read + $unread; // received = read + unread


        return $this->successResponse("Dashboard Summary", $dashboard);

    } catch (\Throwable $exception) {
        return $this->failResponse("Failed to load dashboard", [$exception->getMessage()], $exception, $exception->getCode());
    }
}
 function getCampaignCount(Request $request) {
        try {
            $data['is_deleted'] = 0;
            $sql = "SELECT count(1) as rowCount FROM campaign WHERE is_deleted = :is_deleted ";
            $record = DB::connection('mysql_' . $request->auth->parent_id)->selectOne($sql, $data);
            $data = (array) $record;
            return array(
                'success' => 'true',
                'message' => 'Extension is not belong to any campaign.',
                'data' => $data['rowCount']
            );
        } catch (Exception $e) {
            echo $e->getMessage();
        } catch (InvalidArgumentException $e) {
            echo $e->getMessage();
        }
    }
function getExtensionCount(Request $request)
{
    try {
        $parent_id = $request->auth->parent_id;

        if (is_numeric($parent_id)) {
            $status = 0;
            $isDeleted = 0;
            $userLevel = 9;

            $sql = "SELECT COUNT(1) as rowCount
                    FROM users
                    LEFT JOIN user_extensions ON user_extensions.name = users.extension
                    WHERE users.id IN (
                        SELECT user_id FROM permissions WHERE client_id = :parent_id
                    )
                    AND users.is_deleted = :is_deleted
                    AND users.status = :status
                    AND users.base_parent_id = :base_parent_id
                    AND users.user_level < :user_level";

            $bindings = [
                'parent_id' => $parent_id,
                'is_deleted' => $isDeleted,
                'status' => $status,
                'base_parent_id' => $parent_id,
                'user_level' => $userLevel
            ];

            $record = DB::connection('master')->selectOne($sql, $bindings);
            $userCount = (array)$record;

            return [
                'success' => 'true',
                'message' => 'Extension count',
                'data' => $userCount['rowCount'] ?? 0
            ];
        } else {
            return [
                'success' => 'false',
                'message' => 'Invalid parent ID',
                'data' => 0
            ];
        }
    } catch (\Exception $e) {
        Log::error('getExtensionCount error: ' . $e->getMessage());
        return [
            'success' => 'false',
            'message' => 'Exception occurred',
            'data' => 0
        ];
    }
}
private function getDidCount(Request $request)
{
   
$dids = Dids::on("mysql_" . $request->auth->parent_id)->where('is_deleted', '=', '0')->get();
        $didsCount = $dids->count();
    return $didsCount;
}

function getLeadCount(Request $request) {
        try {
            $dataBase = 'mysql_' . $request->auth->parent_id;
            $sql = "SELECT count(1) as rowCount FROM list_data ";
            $record = DB::connection($dataBase)->selectOne($sql);
            $response = (array) $record;
            $leadCount = $response['rowCount'];
            if ($leadCount > 0) {
                return array(
                    'success' => 'true',
                    'message' => 'Lead count',
                    'data' => $leadCount
                );
            } else {
                return array(
                    'success' => 'true',
                    'message' => 'Lead count not found',
                    'data' => 0
                );
            }
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
}

    public function countList(Request $request)
    {
        $lists = Lists::on("mysql_" . $request->auth->parent_id)->where('is_active', '=', 1)->get();
        $listCount = $lists->count();
         return $listCount;
    }
 public function getSmsCount(Request $request, $startTime, $endTime)
{
    $reportService = new ReportService($request->auth->parent_id);
    return $this->successResponse("Sms", $reportService->smsCount($request, $startTime, $endTime));
}

      public function getVoicemailCount(Request $request,$startTime, $endTime)
    {
      
        $reportService = new ReportService($request->auth->parent_id);
        return $this->successResponse("Voicemails", $reportService->voicemailCount($request, $startTime, $endTime));
    }

 public function getCallBack($request)
    {
        try {
        $timezone = $request->auth->timezone 
            ?? env('APP_TIMEZONE') 
            ?? 'UTC';

        if (empty($timezone) || !in_array($timezone, timezone_identifiers_list())) {
            $timezone = env('APP_TIMEZONE') ?? 'Asia/Kolkata';
        }

        date_default_timezone_set($timezone);

        //date_default_timezone_set($request->auth->timezone); // your user's timezone
        $my_datetime=$request->start_date;//'2023-04-03 07:57:37';
        $my_datetime1=$request->end_date;//'2023-04-03 07:57:37';

        $request['start_date'] = date('Y-m-d H:i:s',strtotime("$my_datetime UTC"));
        $request['end_date'] = date('Y-m-d H:i:s',strtotime("$my_datetime1 UTC"));
        
        $id = $request->input('id');
        if (!empty($id) && is_numeric($id)) {
                $search = array();
                $searchString = array();

                // for Agent it will show his records only
                if ($request->auth->role == 2) {
                    $search['extension'] = $request->auth->extension;
                    $search['alt_extension'] = $request->auth->alt_extension;
                    array_push($searchString, '( c.extension = :extension OR c.extension = :alt_extension)');

                } elseif ($request->has('extension') && !empty($request->input('extension'))) {
                    // filter option, consider alt_extension bacause call maybe made using webRTC.
                    $search['extension'] = $request->input('extension');
                    $objTempUser = User::where('extension', $request->input('extension'))->where('is_deleted', '=', 0)->first();
                    $search['alt_extension'] = $objTempUser->alt_extension;
                    array_push($searchString, '(extension = :extension OR c.extension = :alt_extension)');
                }

                if ($request->has('campaign') && !empty($request->input('campaign'))) {
                    $search['campaign_id'] = $request->input('campaign');
                    array_push($searchString, 'c.campaign_id = :campaign_id');
                }

                if ($request->has('start_date') && $request->has('end_date') && !empty($request->input('start_date')) && !empty($request->input('end_date'))) {
                    $start = date('Y-m-d', strtotime($request->input('start_date'))) . " 00:00:00";
                    $end = date('Y-m-d', strtotime($request->input('end_date'))) . " 23:59:59";
                    $search['start_time'] = $start;
                    $search['end_time'] = $end;
                    array_push($searchString, 'c.callback_time BETWEEN :start_time AND :end_time');
                }

                if ($request->has('reminder') && !empty($request->input('reminder'))) {
                    $sql_extension = "SELECT GROUP_CONCAT(extension) as extensions FROM master.users WHERE extension IN (
                        SELECT extension FROM " . 'client_' . $request->auth->parent_id . ".extension_group_map WHERE is_deleted =0 and group_id IN (SELECT group_id FROM " . 'client_' . $request->auth->parent_id . ".extension_group_map WHERE is_deleted =0 and extension = " . $request->auth->extension . ")
                    ) AND user_level <= '" . $request->auth->level . "' ";

                    $arrExtensions = DB::connection('mysql_' . $request->auth->parent_id)->select($sql_extension);

                    $sql_extension = "SELECT GROUP_CONCAT(alt_extension) as alt_extension FROM master.users WHERE alt_extension IN (
                        SELECT extension FROM " . 'client_' . $request->auth->parent_id . ".extension_group_map WHERE is_deleted =0 and group_id IN (SELECT group_id FROM " . 'client_' . $request->auth->parent_id . ".extension_group_map WHERE is_deleted =0 and extension = " . $request->auth->extension . ")
                    ) AND user_level <= '" . $request->auth->level . "' ";

                    $arrExtensions1 = DB::connection('mysql_' . $request->auth->parent_id)->select($sql_extension);


                    $strExtensions = $arrExtensions[0]->extensions;
                    $strExtensions1 = $arrExtensions1[0]->alt_extension;

                    $originateRequest = $strExtensions.','.$strExtensions1;

                    array_push($searchString, " c.extension IN ($originateRequest)");

                    $search['start_time'] = date('Y-m-d H:i:s', strtotime($request->input('start_date')));
                    $search['end_time'] = date('Y-m-d H:i:s', strtotime($request->input('end_date')));
                }

                $filter = (!empty($searchString)) ? " WHERE " . implode(" AND ", $searchString) : '';

                $sql = "SELECT c.*,
                                ld.*,
                                CONCAT_WS(', ', option_1, option_2, option_3, option_4, option_5, option_6, option_7, option_8, option_9, option_10, option_11, option_12, option_13, option_14, option_15, option_16, option_17, option_18, option_19, option_20, option_21, option_22, option_23, option_24, option_25, option_26, option_27, option_28, option_29, option_30 ) as list_values,
                                x.list_headers,
                                y.is_dialing_selected_column
                        from callback as c
                           JOIN list_data as ld ON ( c.lead_id = ld.id )
                            JOIN (SELECT lh.list_id, GROUP_CONCAT(lh.header ORDER BY lh.id SEPARATOR ', ') as list_headers FROM list_header as lh GROUP BY lh.list_id) x ON x.list_id = ld.list_id
                            JOIN (SELECT lhh.column_name as is_dialing_selected_column, lhh.list_id FROM list_header as lhh WHERE is_dialing = 1 GROUP BY lhh.list_id) y ON y.list_id = ld.list_id
                           " . $filter . " ORDER BY c.callback_time DESC";

                $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql, $search);

                if (!empty($record)) {
                    $data = (array)$record;
                    return array(
                        'success' => 'true',
                        'message' => 'Callback Data Report.',
                        'data' => $data,
                        'record_count' => count($data),

                    );
                } else {
                    return array(
                        'success' => 'true',
                        'message' => 'No Callback Data Report found.',
                        'record_count' => 0,
                        'data' => array()
                    );
                }
            }
            return array(
                'success' => 'false',
                'message' => 'Callback Data Report doesn\'t exist.'
            );
        } catch (Exception $e) {
            Log::log($e->getMessage());
            return array(
                'success' => 'false',
                'message' => $e->getMessage()
            );
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
            return array(
                'success' => 'false',
                'message' => $e->getMessage()
            );
        }
    }

}