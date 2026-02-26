<?php

namespace App\Http\Controllers;
use App\Model\Client\ListHeader;
use App\Model\Client\ListData;
use App\Model\Client\Notification;
use App\Model\Master\InboundCallPopup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Model\Report;
use Illuminate\Support\Facades\DB;
use App\Model\Client\LocationGroup;
use App\Model\User;
use DateTime;
use Illuminate\Database\Eloquent\ModelNotFoundException;

use App\Model\Cron;
use App\Services\PusherService;

class InboundCallPopUpController extends Controller
{
    private $request;
    protected $model;
    public function __construct(Request $request, Report $report)
    {
        $this->request = $request;
        $this->model = $report;
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

                    }
                }

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
                    $data['extension'] = $extension;
                    $data['inbound_number'] = $inbound_number;
                    
                    $receivedPopUp ="UPDATE inbound_call_popup set confirm=1 WHERE inbound_number=:inbound_number and extension=:extension";
                    $save = DB::connection('master')->update($receivedPopUp, $data);

                    $data_delete['inbound_number'] = $inbound_number;

                    $query = "DELETE FROM inbound_call_popup WHERE inbound_number = :inbound_number and confirm='0'";
                    $save = DB::connection('master')->update($query, $data_delete);
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
}
