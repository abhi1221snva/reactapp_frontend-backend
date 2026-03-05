<?php
namespace App\Http\Controllers;
use App\Model\Master\RinglessVoiceMail;
use App\Model\Master\RvmDomainList;
use App\Model\Master\RvmCdrLog;

use App\Model\Master\Client;
use App\Jobs\RinglessVoicemailDrop;
use DateTime;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RinglessVMController extends Controller
{
    public function index(Request $request)
    {
        die;

        $startTime = '08:00:00';
        $endTime   = '21:00:00';
        
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

        $rvmCdrLog = new RvmCdrLog();
        $rvmCdrLog->cli = $requestData['cli'];
        $rvmCdrLog->phone = $requestData['phone'];
        $rvmCdrLog->api_token = $requestData['api_key'];
        $rvmCdrLog->api_client_name =$key = array_search ($requestData['api_key'], $apiKey);
        $rvmCdrLog->rvm_domain_id = $requestData['rvm_domain_id'];
        $rvmCdrLog->api_type = 'live';
        $rvmCdrLog->json_data = $client_data;
        //echo "<pre>";print_r($rvmCdrLog);die;
        $rvmCdrLog->save();

        $rvmCdrLog_id =  $rvmCdrLog->id;
        $requestData['rvm_cdr_log_id'] = $rvmCdrLog_id;

        die;

        sleep(2);
        if($return["dialable"] == 1)
        {
          //  dispatch(new RinglessVoicemailDrop($request->api_key, $requestData))->onConnection("database");
            return array('success' => 'true','message' => 'Ringless VoiceMail Drop API Success.','data' => $requestData);
        }
        else
        {
            return array('success' => 'false','message' => 'Timezone calling is not matched with number','data' => $requestData);
        }
    }


    public function getTimezone($numberAreacode)
    {
        $timeZone = DB::connection('master')->selectOne("SELECT timezone FROM timezone WHERE areacode = :areacode", array('areacode' => $numberAreacode));
        $timeZone = (array)$timeZone;
        return $timeZone;
    }

    
    public function report(Request $request)
    {
        $this->validate($request, ['start_date' => 'required','end_date' => 'required','api_key' => 'required']);


        $previous_day = $request->start_date;
        $current_day  = $request->end_date;
        $api_key  = explode('-',$request->api_key);

        $client = Client::all();

        foreach($client as $key)

        {
            if($key->api_key)
            $clientKey[] = $key->api_key;
        }



        $otherKey = array('bc6c9740upew6966sijedaheiduweada','bc6c9740upew6966qdbe323ea688d6fe','bc6c','bc6c97');

        $apiKey = array_merge($clientKey,$otherKey);

        //echo "<pre>";print_r($apiKey);die;

        if(!in_array($request->api_key,$apiKey))
        {
            return array(
                'success' => 'false',
                'message' => 'Invalid API Key',
            );
        }
        else
        {
         $apiToken = $api_key[0];

        }



        $search = array();
        $searchString = array();
        $limitString = "";

        if ($request->has('api_key') && !empty($request->input('api_key'))) {
            $search['api_key'] = $apiToken;
            array_push($searchString, 'api_token = :api_key');
        }

        if ($request->has('phone') && !empty($request->input('phone'))) {
            $search['phone'] = $request->input('phone');
           // array_push($searchString, 'phone = :phone');
             array_push($searchString, "phone like CONCAT('%',:phone, '%')");

        }

        if ($request->has('cli') && !empty($request->input('cli'))) {
            $search['cli'] = $request->input('cli');

             array_push($searchString, "cli like CONCAT('%',:cli, '%')");

        }

        if ($request->has('start_date') && $request->has('end_date') && !empty($request->input('start_date')) && !empty($request->input('end_date'))) {

             $start = date('Y-m-d', strtotime($request->input('start_date'))) . " 00:00:00";
             $end = date('Y-m-d', strtotime($request->input('end_date'))) . " 23:59:59";

            /*$start = date('Y-m-d', strtotime($request->input('start_date')));
            $end = date('Y-m-d', strtotime($request->input('end_date')));*/
            $search['start_date'] = $start;
            $search['end_date'] = $end;
            array_push($searchString, 'start_date BETWEEN :start_date AND :end_date ');
        }


         if ($request->has('lower_limit') && $request->has('upper_limit') && is_numeric($request->input('lower_limit')) && is_numeric($request->input('upper_limit'))) {
                $search['lower_limit'] = $request->input('lower_limit');
                $search['upper_limit'] = $request->input('upper_limit');
                $limitString = "LIMIT :lower_limit , :upper_limit";
            }

        $filter = (!empty($searchString)) ? " WHERE " . implode(" AND ", $searchString) : '';
        $query_string = "SELECT id,cli,phone,voicemail_url,start_date,end_date,duration,created_at,updated_at,user_id,voicemail_id,ringless_recording from voicemail_drop_log $filter";

        $sql = $query_string . $limitString;
        $record = DB::connection('master')->select($sql, $search);
        $recordCount = DB::connection('master')->selectOne("SELECT COUNT(*) as count FROM voicemail_drop_log $filter", $search);
        $recordCount = (array) $recordCount;

        if (!empty($record)) {
            $data = (array) $record;
            return array(
                'success' => 'true',
                'message' => 'Ringless VoiceMail Data Reports.',
                'record_count' => $recordCount['count'],
                'data' => $data
            );
        }
        else
        {
            return array(
                'success' => 'true',
                'message' => 'No Ringless VoiceMail Data Report found.',
                'record_count' => 0,
                'data' => array()
            );
        }
        return array(
            'success' => 'false',
            'message' => 'Ringless VoiceMail Data Reports doesn\'t exist.'
        );
    }


     public function reportToAdmin(Request $request)
    {
        //$this->validate($request, ['start_date' => 'required','end_date' => 'required','api_key' => 'required']);


      /*  $previous_day = $request->start_date;
        $current_day  = $request->end_date;
        $api_key  = explode('-',$request->api_key);

        $client = Client::all();

        foreach($client as $key)

        {
            if($key->api_key)
            $clientKey[] = $key->api_key;
        }



        $otherKey = array('bc6c9740upew6966sijedaheiduweada','bc6c9740upew6966qdbe323ea688d6fe','bc6c','bc6c97');

        $apiKey = array_merge($clientKey,$otherKey);

        //echo "<pre>";print_r($apiKey);die;

        if(!in_array($request->api_key,$apiKey))
        {
            return array(
                'success' => 'false',
                'message' => 'Invalid API Key',
            );
        }
        else
        {
         $apiToken = $api_key[0];

        }



        $search = array();
        $searchString = array();
        $limitString = "";

        if ($request->has('api_key') && !empty($request->input('api_key'))) {
            $search['api_key'] = $apiToken;
            array_push($searchString, 'api_token = :api_key');
        }

        if ($request->has('phone') && !empty($request->input('phone'))) {
            $search['phone'] = $request->input('phone');
           // array_push($searchString, 'phone = :phone');
             array_push($searchString, "phone like CONCAT('%',:phone, '%')");

        }

        if ($request->has('cli') && !empty($request->input('cli'))) {
            $search['cli'] = $request->input('cli');

             array_push($searchString, "cli like CONCAT('%',:cli, '%')");

        }

        if ($request->has('start_date') && $request->has('end_date') && !empty($request->input('start_date')) && !empty($request->input('end_date'))) {

             $start = date('Y-m-d', strtotime($request->input('start_date'))) . " 00:00:00";
             $end = date('Y-m-d', strtotime($request->input('end_date'))) . " 23:59:59";

            /*$start = date('Y-m-d', strtotime($request->input('start_date')));
            $search['start_date'] = $start;
            $search['end_date'] = $end;
            array_push($searchString, 'start_date BETWEEN :start_date AND :end_date ');
        }*/


         if ($request->has('lower_limit') && $request->has('upper_limit') && is_numeric($request->input('lower_limit')) && is_numeric($request->input('upper_limit'))) {
                $search['lower_limit'] = $request->input('lower_limit');
                $search['upper_limit'] = $request->input('upper_limit');
                $limitString = "LIMIT :lower_limit , :upper_limit";
            }

        $filter = (!empty($searchString)) ? " WHERE " . implode(" AND ", $searchString) : '';
        $query_string = "SELECT id,cli,phone,voicemail_url,start_date,end_date,duration,created_at,updated_at,user_id,voicemail_id,ringless_recording from voicemail_drop_log $filter";

        $sql = $query_string . $limitString;
        $record = DB::connection('master')->select($sql, $search);
        $recordCount = DB::connection('master')->selectOne("SELECT COUNT(*) as count FROM voicemail_drop_log $filter", $search);
        $recordCount = (array) $recordCount;

        if (!empty($record)) {
            $data = (array) $record;
            return array(
                'success' => 'true',
                'message' => 'Ringless VoiceMail Data Reports.',
                'record_count' => $recordCount['count'],
                'data' => $data
            );
        }
        else
        {
            return array(
                'success' => 'true',
                'message' => 'No Ringless VoiceMail Data Report found.',
                'record_count' => 0,
                'data' => array()
            );
        }
        return array(
            'success' => 'false',
            'message' => 'Ringless VoiceMail Data Reports doesn\'t exist.'
        );
    }
}
