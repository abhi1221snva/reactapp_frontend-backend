<?php
namespace App\Http\Controllers;
use App\Model\Master\RinglessVoiceMail;
use App\Model\Master\RvmDomainList;
use App\Model\Master\RvmCdrLog;
use App\Model\Master\RvmQueueList;
use App\Model\Master\RvmCallbackLog;

use App\Model\Master\AsteriskServer;


use App\Model\Master\SipGateway\SipGateways;


use App\Model\Master\Client;
use App\Model\Master\UserExtension;
use App\Model\Master\RvmCallbackConfiguration;

use App\Jobs\SendRvmJob;
use App\Jobs\RinglessVoicemailDropBySipName;

use DateTime;
use DateTimeZone;
use Illuminate\Http\Request;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;




class RinglessVMBySipNameInstantQueueController extends Controller
{
    public function index(Request $request)
    {
        $startTime =  '09:00:00';
        $endTime   =  '18:00:00';

        $client_data = file_get_contents("php://input");
        //echo "<pre>";print_r($client_data);die;


        $this->validate($request, ['phone' => 'required|min:10','cli' => 'required|min:10','voicemail_url' => 'required','api_key' => 'required','userID' =>'required|max:10']);

        $api_key  = explode('-',$request->api_key);
        $client = Client::all();

        foreach($client as $key)
        {
            if($key->api_key)
            {
                $clientKey[$key->company_name] = $key->api_key;
            }
        }

        $otherKey = array('Easify' => 'bc6c');
        $apiKey = array_merge($clientKey,$otherKey);
        //echo "<pre>";print_r($apiKey);die;


        if(!in_array($request->api_key,$apiKey)) {
            return array(
                'success' => 'false',
                'message' => 'Invalid API Key',
            );
        } 
        else {
            $apiToken = $api_key[0];
        }

        $requestData = array();

        if(substr_count($request->phone, '-') > 0 ? substr_count($request->phone, '-') : 0)
            return array('success' => 'false','message' => 'phone number should be without dash(-)');
        $phone_number = preg_replace('/[^0-9]/', '', $request->phone);
        $last10Digit = substr($phone_number, -10);
        if(floor(log10($last10Digit)+1) < 10)
            return array('success' => 'false','message' => 'phone number should be 10 digit');
        if(floor(log10($phone_number)+1) == 10)
            return array('success' => 'false','message' => 'phone number should be with country code');
        $requestData['phone'] = trim($request->phone);


        if(substr_count($request->cli, '-') > 0 ? substr_count($request->cli, '-') : 0)
            return array('success' => 'false','message' => 'cli should be without dash(-)');
        $check_cli_number = str_replace('+','',$request->cli);
        $cli_number = preg_replace('/[^0-9]/', '', $check_cli_number);
        $last10Digit = substr($cli_number, -10);
        if(floor(log10($last10Digit)+1) < 10)
            return array('success' => 'false','message' => 'cli number should be 10 digit');
        if(floor(log10($check_cli_number)+1) == 10)
            return array('success' => 'false','message' => 'cli number should be with country code');
        $requestData['cli'] = trim($request->cli);


        /*if(substr_count($request->api_key, '-') > 0 ? substr_count($request->api_key, '-') : 0)
            return array('success' => 'false','message' => 'api_key should be without dash(-)');*/
        $requestData['api_key'] = trim($request->api_key);

        if(substr_count($request->userID, '-') > 0 ? substr_count($request->userID, '-') : 0)
            return array('success' => 'false','message' => 'userID should be without dash(-)');
        $requestData['userID'] = trim($request->userID);


        if(substr_count($request->voicemail_id, '-') > 0 ? substr_count($request->voicemail_id, '-') : 0)
            return array('success' => 'false','message' => 'voicemail_id should be without dash(-)');
        $requestData['voicemail_id'] = trim($request->voicemail_id);


        if(substr_count($request->voicemail_url, '-') > 0 ? substr_count($request->voicemail_url, '-') : 0)
            return array('success' => 'false','message' => 'voicemail_url should be without dash(-)');
        $requestData['voicemail_url'] = trim($request->voicemail_url);


        if(isset($request['callback_url']))
        {
            $requestData['callback_url'] = trim($request->callback_url);
        }

        if(isset($request['callback_number']))
        {


        if(substr_count($request->callback_number, '-') > 0 ? substr_count($request->callback_number, '-') : 0)
            return array('success' => 'false','message' => 'callback number should be without dash(-)');
        $callback_number = preg_replace('/[^0-9]/', '', $request->callback_number);
        $last10Digit = substr($callback_number, -10);
        if(floor(log10($last10Digit)+1) < 10)
            return array('success' => 'false','message' => 'callback number should be 10 digit');
        if(floor(log10($callback_number)+1) == 10)
            return array('success' => 'false','message' => 'callback number should be with country code');
        $requestData['callback_number'] = trim($request->callback_number);
    }

       


        //find domain id rvm_domain_log table
        $voicemail_url_string  = $request['voicemail_url'];
        $url = explode("/", $voicemail_url_string);
        $voicemail_file_name = end($url);
        array_pop($url);
        $folder_link =  implode('/', $url).'/'; 

        if(isset($request['callback_url']))
        {
            $callback_url  = $request['callback_url'];
            $findDomain = RvmDomainList::where('folder_link',$folder_link)->where('callback_url',$callback_url)->get()->first();
        }
        else
        {
            $callback_url='';
            $findDomain = RvmDomainList::where('folder_link',$folder_link)->get()->first();
        }

        //echo "<pre>";print_r($findDomain);die;

        if($findDomain)
        {
            $rvm_domain_id =  $findDomain->id;
        }
        else
        {
            $rvm_domain_list = new RvmDomainList();
            $rvm_domain_list->folder_link = $folder_link;
            $rvm_domain_list->callback_url = $callback_url;
            $rvm_domain_list->save();
            $rvm_domain_id =  $rvm_domain_list->id;
        }

        $requestData['apiToken'] = $apiToken;
        $requestData['rvm_domain_id'] = $rvm_domain_id;
        $requestData['start_time'] = $startTime;
        $requestData['end_time'] = $endTime;
        $requestData['voicemail_file_name'] = $voicemail_file_name;
        $requestData['user_id'] = $request->userID;
        $requestData['voicemail_id'] = $request->voicemail_id;
        

        //echo "<pre>";print_r($requestData);die;

        $number = preg_replace('/[^0-9]/', '', $requestData['phone']);
        $last10Digit = substr($number, -10);
        $return = ["dialable" => 0,"areacodeTimeZone" => 0,"dialingTime" => 0];

        $numberAreacode = substr(trim($last10Digit), 0, 3);
        $timeZone = $this->getTimezone($numberAreacode);
        //echo "<pre>";print_r($timeZone);die;

        if (empty($timeZone)) 
        {
            $return["dialable"] = 1;
            $return["dialingTime"] = 1;
        }
        else 
        {
            if (!empty($timeZone['timezone']))
            {
                $return["areacodeTimeZone"] = 1;
                $time = new DateTime();
                $time->setTimeZone(new DateTimeZone(timezone_name_from_abbr($timeZone['timezone'])));
                $currentTime = $time->format('H:i:s');
                if (strtotime($startTime) < strtotime($currentTime) && strtotime($endTime) > strtotime($currentTime)) 
                {
                    $return["dialingTime"] = 1;
                    $return["dialable"] = 1;
                }
            }
        }



        if(isset($request['sip_trunk_name']))
        {
            if($request['sip_trunk_name'] == 'pilivo')
            {
                $request['sip_trunk_name'] = 'sip1-voiptella-com-almo';
            }

            
            $sip_gateway = SipGateways::where('sip_trunk_name',$request['sip_trunk_name'])->get()->first();
            //echo "<pre>";print_r($sip_gateway);die;

            if($sip_gateway)
            {
                $sip_gateway_id = $sip_gateway->id;
                $requestData['sip_gateway_id'] = $sip_gateway_id;
                $requestData['asterisk_server_id'] = $sip_gateway->asterisk_server_id;



            }
            else
            {
                if($request['sip_trunk_provider'] == 'twilio')
                {
                    $dt['name'] = $request['sip_trunk_name'];
                    $dt['username'] = $request['sip_trunk_username'];
                    $dt['fullname'] = $request['sip_trunk_name'];
                    $dt['host'] = $request['sip_trunk_host'];
                    $dt['secret']   = $request['sip_trunk_password'];
                    $dt['context'] =  'from-easify-incoming';//'trunkinbound-'.$request['sip_trunk_provider'].'-from-easify-incoming';
                    $dt['nat'] = 'force_rport,comedia';
                    $dt['qualify'] = 'no';
                    $dt['type'] = 'friend';
                }

                $AsteriskServer = AsteriskServer::where('rvm_status', '1')->orderByRaw('RAND()')->first();

                if(empty($AsteriskServer))
                {
                    return array('success' => 'false',"code" => 401,'message' => 'AsteriskServer rvm status is not enabled','data' => $requestData);
                }

                $asterisk_server_id = $AsteriskServer->id;

                $attributes['client_name'] = $request['client_name'];
                $attributes['sip_trunk_provider'] = $request['sip_trunk_provider'];
                $attributes['sip_trunk_name'] = $request['sip_trunk_name'];
                $attributes['sip_trunk_host'] = $request['sip_trunk_host'];
                $attributes['sip_trunk_password'] = $request['sip_trunk_password'];
                $attributes['sip_trunk_context'] = 'from-easify-incoming';//'trunkinbound-'.$request['sip_trunk_provider'].'-from-easify-incoming';;
                $attributes['sip_trunk_username'] = $request['sip_trunk_username'];
                $attributes['asterisk_server_id'] = $asterisk_server_id;



                //echo "<pre>";print_r($attributes);die;


                $sipGateway = SipGateways::create($attributes);
                $addUserExtension = UserExtension::create($dt);

                $sip_gateway_id = $sipGateway->id;
                $requestData['sip_gateway_id'] = $sip_gateway_id;
                $requestData['asterisk_server_id'] = $asterisk_server_id;





            }

        }

        $requestData['sip_trunk_name'] = trim($request->sip_trunk_name);
        $requestData['sip_trunk_host'] = trim($request->sip_trunk_host);
        $requestData['sip_trunk_username'] = trim($request->sip_trunk_username);
        $requestData['sip_trunk_password'] = trim($request->sip_trunk_password);
        $requestData['client_name'] = trim($request->client_name);
        $requestData['sip_trunk_provider'] = trim($request->sip_trunk_provider);


        if(isset($request['callback_number']))
        {
            $callback_number = RvmCallbackConfiguration::where('phone',$request['phone'])->where('cli',$request['cli'])->where('callback_number',$request['callback_number'])->where('sip_gateway_id',$requestData['sip_gateway_id'])->get()->first();
            //echo "<pre>";print_r($callback_number);die;

            if($callback_number)
            {
                $callback_number_id = $callback_number->id;
                $requestData['callback_number_id'] = $callback_number->id;


            }
            else
            {
                $rvm_callback['cli'] = $requestData['cli'];
                $rvm_callback['phone'] = $requestData['phone'];
                $rvm_callback['callback_number'] = $requestData['callback_number'];
                $rvm_callback['sip_gateway_id'] = $requestData['sip_gateway_id'];



                

                //echo "<pre>";print_r($rvm_callback);die;


                $callback_number = RvmCallbackConfiguration::create($rvm_callback);

                $callback_number_id = $callback_number->id;

                $requestData['callback_number_id'] = $callback_number->id;





            }

        }



       // echo "<pre>";print_r($requestData);die;
        

        $rvmCdrLog = new RvmCdrLog();
        $rvmCdrLog->cli = $requestData['cli'];
        $rvmCdrLog->phone = $requestData['phone'];
        $rvmCdrLog->api_token = $requestData['api_key'];
        $rvmCdrLog->api_client_name =$key = array_search ($requestData['api_key'], $apiKey);
        $rvmCdrLog->rvm_domain_id = $requestData['rvm_domain_id'];
        $rvmCdrLog->api_type = 'live';
        $rvmCdrLog->json_data = json_encode($requestData);
        $rvmCdrLog->sip_gateway_id = $sip_gateway_id;
        $rvmCdrLog->voicemail_id = $requestData['voicemail_id'];
        $rvmCdrLog->user_id = $requestData['user_id'];
        //echo "<pre>";print_r($rvmCdrLog);die;
        $rvmCdrLog->save();
        $rvmCdrLog_id =  $rvmCdrLog->id;
        $requestData['rvm_cdr_log_id'] = $rvmCdrLog_id;

        //echo "<pre>";print_r(json_encode($requestData));die;

        //echo "<pre>";print_r($requestData);


        $rvm_queue_list = rvmCdrLog::where('id',$rvmCdrLog->id)->get()->first();

        $rvm_data = json_decode($rvm_queue_list->json_data);
            $rvm_data->id = $rvmCdrLog->id;
        $rvm_data->status_code = 'rvm_schedule_job_instant';

        $rvm_data->timezone_queue_trigger = 1; // instant rvm in queue

            


       // echo "<pre>";print_r($rvm_data);die;








        if($return["dialable"] == 1 || $rvm_data->timezone_queue_trigger == 1)
        {
            dispatch((new SendRvmJob($rvm_data))->delay(Carbon::now()->addSeconds(5))->onConnection("rvm_schedule_job"));
            return array('success' => 'true',"code" => 200,'message' => 'Request has been accepted and is queued up.','data' => $requestData);
        }
        else
        {
            $rvmCdrLog->timezone_status = '0';
            $rvmCdrLog->save();
            return array('success' => 'true',"code" => 401,'message' => 'Request has been accepted and is queued up as per the time zone conditions.','data' => $requestData);
        }
    }


    public function getTimezone($numberAreacode)
    {
        $timeZone = DB::connection('master')->selectOne("SELECT timezone FROM timezone WHERE areacode = :areacode", array('areacode' => $numberAreacode));
        $timeZone = (array)$timeZone;
        return $timeZone;
    }


    
 


}
