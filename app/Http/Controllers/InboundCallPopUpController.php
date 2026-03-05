<?php

namespace App\Http\Controllers;
use App\Model\Client\ListHeader;
use App\Model\Client\ListData;
use App\Model\Master\InboundCallPopup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Model\Report;
use Illuminate\Support\Facades\DB;
use App\Model\Client\LocationGroup;
use App\Model\User;
use DateTime;
use Pusher\Pusher;

use App\Model\Cron;

class InboundCallPopUpController extends Controller
{
    private $request;
    protected $pusher;

    public function __construct(Request $request, Report $report)
    {
        $this->request = $request;
        $this->model = $report;

        // Initialize Pusher for real-time call notifications
        $this->pusher = new Pusher(
            env('PUSHER_APP_KEY'),
            env('PUSHER_APP_SECRET'),
            env('PUSHER_APP_ID'),
            [
                'cluster' => env('PUSHER_APP_CLUSTER'),
                'useTLS' => true
            ]
        );
    }

    public function index(Request $request)
    {
        //http://localhost:9000/inbound-call-popup-notification?parent_id=3&location_id=3&inbound_number=9024412385&token=asreDZBfwabsIEZDficbaewusEZDfcauwseZdcbwasEZDbfcyuwaseZudfycuwasEZdfuycawseZd


        $this->validate($request, ["location_id" => "required|int","parent_id" => "required|int","inbound_number" => "required|int","token" => "required",]);
        $tokenENV = env('PREDICTIVE_CALL_TOKEN');
        
        if($tokenENV == $_GET['token'])
        {
            $inbound_number =$this->request->inbound_number;
            $parent_id =$this->request->parent_id;
            $location_id =$this->request->location_id;

            try
            {

                $location_group = LocationGroup::on("mysql_".$parent_id)->where('id',$location_id)->first();

                if(empty($location_group))
                {
                    return $this->successResponse("Location group", [$location_group]);
                }

                $location_extension = $location_group->extensions;

                //echo "<pre>";print_r($user);die;

                $extension = str_replace("&","",$location_extension);
                $extension_list = array_values(array_filter(explode('SIP/',$extension)));
                //echo "<pre>";print_r($extension_list);die;

                $inbound_number = $this->request->inbound_number;
                $inbound_calls = [];
                $userIds = []; // Collect user IDs for Pusher notification
                foreach($extension_list as $key => $extension)
                {
                    $user = User::on("master")->where('extension',$extension)->orWhere('alt_extension',$extension)->first();
                    if(!empty($user))
                    {
                        $clientId = $user->parent_id;
                        $InboundCallPopup = new InboundCallPopup();
                        $InboundCallPopup->inbound_number = $inbound_number;
                        $InboundCallPopup->parent_id = $clientId;
                        $InboundCallPopup->extension = $extension;
                        $InboundCallPopup->save();
                        $inbound_calls[] =$InboundCallPopup;
                        $userIds[] = $user->id; // Collect user ID for notification
                    }
                }

                // ========== PUSHER: Real-time RINGING notification ==========
                if (!empty($userIds)) {
                    try {
                        $this->pusher->trigger('my-channel', 'my-event', [
                            'message' => [
                                'user_ids' => $userIds,
                                'platform' => 'call',
                                'event' => 'ringing',
                                'msg' => 'Incoming call from ' . $inbound_number,
                                'number' => $inbound_number,
                                'location_id' => $location_id,
                                'parent_id' => $parent_id
                            ]
                        ]);
                        Log::info("Pusher call RINGING notification sent", [
                            'user_ids' => $userIds,
                            'inbound_number' => $inbound_number,
                            'location_id' => $location_id
                        ]);
                    } catch (\Exception $e) {
                        Log::error("Pusher call RINGING notification failed: " . $e->getMessage());
                    }
                }
                // ========== END PUSHER ==========

                $ListHeader = ListHeader::on("mysql_" . $clientId)->where('is_dialing',1)->get()->all();
                $column = array();

                foreach($ListHeader as $header)
                {
                    $column[] = $header->column_name;
                }

                $unique_column = array_values(array_unique($column));
                $unique_column_count = count($unique_column);

                if(!empty($unique_column))
                {
                    $implode = implode(',',$unique_column);
                    $listData = DB::connection('mysql_'.$clientId)->selectOne("select * from  list_data where ".$inbound_number." IN(".$implode.")");

                    if(!empty($listData))
                    {
                        $lead_id = $listData->id;
                        $report = new Report;
                        $leadData = $report->getLeadData($lead_id, $clientId);

                        if(!empty($leadData))
                        {
                            foreach($leadData as $lead)
                            {
                                if($lead['title'] == 'First Name')
                                {
                                    $first_name = $lead['value'];
                                }
                                if($lead['title'] == 'Last Name')
                                {
                                    $last_name = $lead['value'];
                                }
                            }

                            $name = $first_name.' '.$last_name;
                            $data['name'] = $name;
                            $data['inbound_number'] = $inbound_number;
                            $updateInboundCalls = "UPDATE inbound_call_popup set name=:name WHERE inbound_number=:inbound_number";
                            $save = DB::connection('master')->update($updateInboundCalls, $data);
                        }
                    }


                   /* for($i=0; $i< $unique_column_count ; $i++)
                    {
                        $column_name = $unique_column[$i];
                        $listData = ListData::on("mysql_" . $clientId)->where($column_name,$inbound_number)->first();
                        if(!empty($listData))
                        {
                            $lead_id = $listData->id;
                            $report = new Report;
                            $leadData = $report->getLeadData($lead_id, $clientId);

                            if(!empty($leadData))
                            {
                                foreach($leadData as $lead)
                                {
                                    if($lead['title'] == 'First Name')
                                    {
                                        $first_name = $lead['value'];
                                    }
                                    if($lead['title'] == 'Last Name')
                                    {
                                        $last_name = $lead['value'];
                                    }
                                }

                                $name = $first_name.' '.$last_name;
                                $data['name'] = $name;
                                $data['inbound_number'] = $inbound_number;
                                $updateInboundCalls = "UPDATE inbound_call_popup set name=:name WHERE inbound_number=:inbound_number";
                                $save = DB::connection('master')->update($updateInboundCalls, $data);
                                break;
                            }
                        }
                    }
                   */
                }
                return $this->successResponse("Inbound Call Details", [$inbound_calls]);
            }
            catch(ModelNotFoundException $modelNotFoundException)
            {
                return $this->failResponse("Inbound Call Details", [$modelNotFoundException], null, 200);
            }
            catch (\Throwable $exception)
            {
                return $this->failResponse("Inbound Call Details", [$exception], null, 200);
            }
        }
        else
        {
            $message = 'Invalid Token or parameters';
            return $this->failResponse("Inbound Call Details", [$message], null, 200);
        }
    }

    public function receivedInboundCallPopUp(Request $request)
    {
        $this->validate($request, ["extension" => "required|int","inbound_number" => "required|int","token" => "required",]);
        $tokenENV = env('PREDICTIVE_CALL_TOKEN');
        
        if($tokenENV == $_GET['token'])
        {
            $inbound_number =$this->request->inbound_number;
            $extension =$this->request->extension;

            try
            {
                $user = User::on("master")->where('extension',$extension)->orWhere('alt_extension',$extension)->first();
                if(!empty($user))
                {
                    // Get all users who were notified about this call BEFORE deleting
                    $notifiedPopups = DB::connection('master')
                        ->table('inbound_call_popup')
                        ->where('inbound_number', $inbound_number)
                        ->get();

                    $userIds = [];
                    foreach($notifiedPopups as $popup) {
                        $extUser = User::on("master")->where('extension', $popup->extension)
                            ->orWhere('alt_extension', $popup->extension)->first();
                        if ($extUser) {
                            $userIds[] = $extUser->id;
                        }
                    }

                    $data['extension'] = $extension;
                    $data['inbound_number'] = $inbound_number;

                    $receivedPopUp ="UPDATE inbound_call_popup set confirm=1 WHERE inbound_number=:inbound_number and extension=:extension";
                    $save = DB::connection('master')->update($receivedPopUp, $data);

                    $data_delete['inbound_number'] = $inbound_number;

                    $query = "DELETE FROM inbound_call_popup WHERE inbound_number = :inbound_number and confirm='0'";
                    $save = DB::connection('master')->update($query, $data_delete);

                    // ========== PUSHER: Real-time RECEIVED notification ==========
                    if (!empty($userIds)) {
                        try {
                            $answeredByName = $user->full_name ?? $user->name ?? $extension;
                            $this->pusher->trigger('my-channel', 'my-event', [
                                'message' => [
                                    'user_ids' => array_unique($userIds),
                                    'platform' => 'call',
                                    'event' => 'received',
                                    'msg' => 'Call answered by ' . $answeredByName,
                                    'number' => $inbound_number,
                                    'answered_by' => $user->id,
                                    'answered_by_name' => $answeredByName
                                ]
                            ]);
                            Log::info("Pusher call RECEIVED notification sent", [
                                'user_ids' => $userIds,
                                'inbound_number' => $inbound_number,
                                'answered_by' => $user->id
                            ]);
                        } catch (\Exception $e) {
                            Log::error("Pusher call RECEIVED notification failed: " . $e->getMessage());
                        }
                    }
                    // ========== END PUSHER ==========
                }

                //echo "<pre>";print_r($user);die;
                return $this->successResponse("Inbound Call Update Details", [$data]);
            }
            catch(ModelNotFoundException $modelNotFoundException)
            {
                return $this->failResponse("Inbound Call Details", [$modelNotFoundException], null, 200);
            }
            catch (\Throwable $exception)
            {
                return $this->failResponse("Inbound Call Details", [$exception], null, 200);
            }
        }
        else
        {
            $message = 'Invalid Token or parameters';
            return $this->failResponse("Inbound Call Details", [$message], null, 200);
        }

    }

    public function inboundCallPopup(Request $request)
    {
        $extension = $request->extension;
        $alt_extension = $request->alt_extension;

        date_default_timezone_set('US/Eastern');
        //date_default_timezone_set("Asia/Calcutta");

        $InboundCallPopup = InboundCallPopup::where('status', 1)->where('extension', $extension)->orderBy('id','DESC')->first();
        if(empty($InboundCallPopup))
        {
            $InboundCallPopup = InboundCallPopup::where('status', 1)->where('extension', $alt_extension)->orderBy('id','DESC')->first();
        }

        $created_at =  date('Y-m-d H:i:s');
        if(!empty($InboundCallPopup->created_at))
        {
        $call_created_at = date('Y-m-d H:i:s', strtotime($InboundCallPopup->created_at));
        $duration = 15;
        $add_duration_date=strtotime($call_created_at) + $duration;
        $add_duration_last_time_cron_run_db = date('Y-m-d H:i:s',$add_duration_date);
        $timestamp1 = strtotime($created_at);
        $timestamp2 = strtotime($add_duration_last_time_cron_run_db);
        if($timestamp1 > $timestamp2)
        {
            return $this->successResponse("Inbound List", [$created_at,$call_created_at,$add_duration_last_time_cron_run_db]);
        }
            
        }
        return $this->successResponse("Inbound List", [$InboundCallPopup]);
    }

    /**
     * Handle call completed notification
     * Clears ringing notifications for all users
     */
    public function completedInboundCallPopUp(Request $request)
    {
        $this->validate($request, [
            "inbound_number" => "required",
            "token" => "required"
        ]);

        $tokenENV = env('PREDICTIVE_CALL_TOKEN');

        if($tokenENV == $request->token)
        {
            $inbound_number = $request->inbound_number;

            try
            {
                // Get all users who were notified about this call
                $notifiedPopups = DB::connection('master')
                    ->table('inbound_call_popup')
                    ->where('inbound_number', $inbound_number)
                    ->get();

                $userIds = [];
                foreach($notifiedPopups as $popup) {
                    $user = User::on("master")->where('extension', $popup->extension)
                        ->orWhere('alt_extension', $popup->extension)->first();
                    if ($user) {
                        $userIds[] = $user->id;
                    }
                }

                // ========== PUSHER: Real-time COMPLETED notification ==========
                if (!empty($userIds)) {
                    try {
                        $this->pusher->trigger('my-channel', 'my-event', [
                            'message' => [
                                'user_ids' => array_unique($userIds),
                                'platform' => 'call',
                                'event' => 'completed',
                                'msg' => 'Call ended',
                                'number' => $inbound_number
                            ]
                        ]);
                        Log::info("Pusher call COMPLETED notification sent", [
                            'user_ids' => $userIds,
                            'inbound_number' => $inbound_number
                        ]);
                    } catch (\Exception $e) {
                        Log::error("Pusher call COMPLETED notification failed: " . $e->getMessage());
                    }
                }
                // ========== END PUSHER ==========

                // Clean up all records for this inbound number
                DB::connection('master')
                    ->table('inbound_call_popup')
                    ->where('inbound_number', $inbound_number)
                    ->delete();

                return $this->successResponse("Call completed notification sent", [
                    'inbound_number' => $inbound_number,
                    'notified_users' => count($userIds)
                ]);
            }
            catch (\Throwable $exception)
            {
                return $this->failResponse("Call completed notification failed", [$exception->getMessage()], null, 200);
            }
        }
        else
        {
            $message = 'Invalid Token or parameters';
            return $this->failResponse("Invalid request", [$message], null, 200);
        }
    }
}
