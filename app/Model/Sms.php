<?php

namespace App\Model;

use App\Model\Client\wallet;
use App\Model\Dids;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Database\Eloquent\Model;
use App\Model\Client\SmsSetting;
use App\Model\Client\SmsProviders;


use Plivo\RestClient;
use Twilio\Rest\Client;

class Sms extends Model {

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $table = 'sms';

    /*
     * Fetch SMS list
     * @param integer $id
     * @return array
     */
public function smsDetails(Request $request): array
{
    $data_row = [];
    $clientId = $request->auth->parent_id;
    $id = $request->auth->id;

    // Pagination params
    $start = (int) $request->get('start', 0);   // offset
    $limit = (int) $request->get('limit', 20);  // number of records

    // Search filters
    $searchNumber = $request->get('number');    // optional number filter
    $searchDid = $request->get('did');          // optional did filter

    // Get DID records
    $sql = "SELECT * FROM did WHERE sms_email = :sms_email";
    $record = DB::connection("mysql_$clientId")->select($sql, ['sms_email' => $id]);
    $response = (array) $record;

    if (!empty($response)) {
        foreach ($response as $res) {
            $did = $res->cli;

            // If a specific DID is provided and doesn't match this one, skip it
            if (!empty($searchDid) && $searchDid != $did) {
                continue;
            }

            // Subquery for latest message per number
            $sql = "SELECT max(id) as id FROM sms WHERE did = ?";

            // Add number filter if provided
            $params = [$did];
            if (!empty($searchNumber)) {
                $sql .= " AND number = ?";
                $params[] = $searchNumber;
            }

            $sql .= " GROUP BY number";

            // Main query
            $sql1 = "SELECT * FROM sms WHERE id IN ($sql) ORDER BY date DESC";
            $record = DB::connection("mysql_$clientId")->select($sql1, $params);

            if (!empty($record)) {
                foreach ($record as $k => $k_val) {
                    if ($k_val->type == 'outgoing') {
                        $record[$k]->message = $k_val->message;
                    }
                }
                $data_row[] = $record;
            }
        }
    }

    // Flatten and sort
    $array_result = array_reduce($data_row, 'array_merge', []);
    $sorted = $this->array_sort($array_result, 'date', SORT_DESC);

    // Total count
    $total = count($sorted);

    // Apply offset + limit
    $pagedData = array_slice($sorted, $start, $limit);

    // Return response
    return [
        'success' => true,
        'message' => 'SMS fetched successfully',
        'start' => $start,
        'limit' => $limit,
        'total' => $total,
        'data' => array_values($pagedData),
    ];
}





    function array_sort($array, $on, $order=SORT_ASC)
    {
        $new_array = array();
        $sortable_array = array();
        if(count($array) > 0)
        {
            foreach($array as $k => $v)
            {
                if (is_array($v))
                {
                    foreach ($v as $k2 => $v2)
                    {
                        if ($k2 == $on)
                        {
                            $sortable_array[$k] = $v2;
                        }
                    }
                }
                else
                {
                    $sortable_array[$k] = $v;
                }
            }
            switch ($order)
            {
                case SORT_ASC:
                asort($sortable_array);
                break;

                case SORT_DESC:
                arsort($sortable_array);
                break;
            }

            foreach ($sortable_array as $k => $v)
            {
                //$new_array[$k] = $array[$k];
                $new_array[] = $array[$k];

            }
        }
        return $new_array;
    }

    public function smsDetailsByDid($request) {
        $data = array();
        $searchStr = array();
        if ($request->has('number') && is_numeric($request->input('number'))) {
            array_push($searchStr, 'number = :number');
            $data['number'] = $request->input('number');
            $data['number1'] = $request->input('number');
        }

        if ($request->has('did') && is_numeric($request->input('did'))) {
            array_push($searchStr, 'did = :did');
            $data['did'] = $request->input('did');
            $data['did1'] = $request->input('did');
        }


        $str = !empty($searchStr) ? "  WHERE " . implode(" AND ", $searchStr) : '';
		$query = "UPDATE " . $this->table . " set status=1 where  (did = :did and number= :number ) or (number = :did1 and did= :number1 )";
        $save_update = DB::connection('mysql_' . $request->auth->parent_id)->update($query, $data);

        $sql = "SELECT * FROM " . $this->table . " where  (did = :did and number= :number ) or (number = :did1 and did= :number1 )  order by id "; //." group by did";
        $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql, $data);
        $data = (array) $record;

		if($data){
			foreach($data as $key=>$val){
				//if($val->type=='outgoing')
				$data[$key]->message = $val->message;
			}
		}
		//echo '<pre>'.count($data); print_R($data); exit;
        return array(
            'success' => 'true',
            'message' => 'SMS detail.',
            'data' => $data
        );
    }

    public function sendSms(Request $request) {
        Log::info('reached backend sms data',[$request->all()]);
        try {

            $clientId = $request->auth->parent_id;
            $intUserId = $request->auth->id;
            $data = array();
            $searchStr = array();

            $data_array['to'] = $request->to;
            $data_array['from'] =  $request->from;
            $data_array['text'] = $request->message;
           // $data_array['mms_url'] = $request->mms_url;
// Handle image file upload if exists
// Handle image file upload if exists
$mms_url = null;
if ($request->hasFile('mms_file')) {
    $file = $request->file('mms_file');
    $fileName = time() . '_' . $file->getClientOriginalName();
    $filePath = 'uploads/mms/' . $fileName;
    $file->move(\public_path('uploads/mms'), $fileName);
    $mms_url = url($filePath); // Full public URL
}



$data_array['mms_url'] = $mms_url ?? $request->mms_url;

            $get_provider = Dids::on("mysql_$clientId")->where("cli",$request->from)->get()->first();

            $voip_provider = $get_provider->voip_provider;

            if($voip_provider == 'didforsale')
            {
                $sms_setting = SmsProviders::on("mysql_$clientId")->where("status",'1')->where('provider',$voip_provider)->get()->first();
                
                $auth_id = $sms_setting->auth_id;
                $api_key = $sms_setting->api_key;

                $didforsale_sms_url = "https://api.didforsale.com/didforsaleapi/index.php/api/V4/SMS/SingleSend"; 

                $json_data_to_send = json_encode($data_array);
            
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $didforsale_sms_url);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
                curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data_to_send);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
                curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/json", "Authorization: Basic " . base64_encode("$auth_id:$api_key")));
                $result = curl_exec($ch);
                $res = json_decode($result);
Log::info('result reached',['res'=>$res]);
                 if ($res->status == 'true') {

                //Billing part
                $isFree = $intCharge = $currencyCode = $clientPackageId = NULL;

                $user = new User();
                $user->id = $intUserId;
                $user->parent_id = $clientId;
                $package = $user->getAssignedUserPackage(true);

                if(empty($package)){
                    //No charge for Admin
                    $isFree = 1;
                    $intCharge = 0;
                } else {
                    //Calculate SMS charges
                    if($package->free_sms > 0){
                        $isFree = 1;
                        $intCharge = 0;

                        //Deduct free balance
                        DB::connection('mysql_'.$clientId)->table('user_packages')->where('id',$package->user_package_id)->decrement('free_sms',1);

                    } else {
                        $intCharge = $package->rate_per_sms;
                        $isFree = 0;

                        //Deduct amount from client_xxx.wallet
                        wallet::debitCharge($intCharge, $clientId, $package->currency_code);
                    }
                    $currencyCode = $package->currency_code;
                    $clientPackageId = $package->id;
                }

                $smsObj = new Sms;
                $smsObj->setConnection('mysql_' . $clientId);
                $smsObj->number     = $request->to;
                $smsObj->did        = $request->from;
                $smsObj->message    = $request->message;
                $smsObj->operator   = $voip_provider;
                $smsObj->type       = 'outgoing';
                $smsObj->date       = $request->date;
                $smsObj->extension  = $request->auth->id;
                if(!empty($clientPackageId))
                $smsObj->currency_code = $clientPackageId;
                else
                $smsObj->currency_code = 'USD';
                $smsObj->client_package_id = $currencyCode;
                $smsObj->user_id = $intUserId;
                $smsObj->charge = $intCharge;
                $smsObj->isFree = $isFree;

                if($smsObj->save()){
                    return array(
                            'success' => 'true',
                            'message' => $res->message
                        );
                }
            }
            else if ($res->code == '101') {
                return array(
                    'success' => 'false',
                    'message' => $res->message
                );
            } else if ($res->code == '113') {
                return array(
                    'success' => 'false',
                    'message' => $res->message
                );
            }

            }

            else
            if($voip_provider == 'plivo')
            {
                $sms_setting = SmsProviders::on("mysql_$clientId")->where("status",'1')->where('provider',$voip_provider)->get()->first();

                $auth_id = $sms_setting->auth_id;
                $api_key = $sms_setting->api_key;

               // $request->from = '16313362181';//$sms_number;
                //$data_array['from'] =  $request->from;
                $client = new RestClient($auth_id,$api_key);

               // Check if mms_url is provided
                if ($request->has('mms_url')) {
                    // Send MMS if mms_url is provided
                    $result = $client->messages->create([
                        "src" => $data_array['from'],                 // Sender's phone number
                        "dst" => $data_array['to'],                   // Recipient's phone number
                        "text" => $data_array['text'],                // Text content
                        "type" => "mms",                              // Explicitly set to MMS
                        "media_urls" => [$data_array['mms_url']]      // Use provided media URL
                    ]);
                } else {
                    // Send SMS if mms_url is not provided
                    $result = $client->messages->create([
                        "src" => $data_array['from'],                 // Sender's phone number
                        "dst" => $data_array['to'],                   // Recipient's phone number
                        "text" => $data_array['text']                 // Text content
                    ]);
}
           
Log::info('result reached',['result'=>$result]);

                    if($result->statusCode == '202')
                    {

                        //Billing part
                $isFree = $intCharge = $currencyCode = $clientPackageId = NULL;

                $user = new User();
                $user->id = $intUserId;
                $user->parent_id = $clientId;
                $package = $user->getAssignedUserPackage(true);

                if(empty($package)){
                    //No charge for Admin
                    $isFree = 1;
                    $intCharge = 0;
                } else {
                    //Calculate SMS charges
                    if($package->free_sms > 0){
                        $isFree = 1;
                        $intCharge = 0;

                        //Deduct free balance
                        DB::connection('mysql_'.$clientId)->table('user_packages')->where('id',$package->user_package_id)->decrement('free_sms',1);

                    } else {
                        $intCharge = $package->rate_per_sms;
                        $isFree = 0;

                        //Deduct amount from client_xxx.wallet
                        wallet::debitCharge($intCharge, $clientId, $package->currency_code);
                    }
                    $currencyCode = $package->currency_code;
                    $clientPackageId = $package->id;
                }

                $smsObj = new Sms;
                $smsObj->setConnection('mysql_' . $clientId);
                $smsObj->number     = $request->to;
                $smsObj->did        = $request->from;
                $smsObj->message    = $request->message;
                $smsObj->operator   = $voip_provider;

                $smsObj->type       = 'outgoing';
                $smsObj->date       = $request->date;
                $smsObj->extension  = $request->auth->id;
                if(!empty($$clientPackageId))
                $smsObj->currency_code = $clientPackageId;
                else
                $smsObj->currency_code = 'USD';
                $smsObj->client_package_id = $currencyCode;
                $smsObj->user_id = $intUserId;
                $smsObj->charge = $intCharge;
                $smsObj->isFree = $isFree;

                if($smsObj->save()){
                    return array(
                            'success' => 'true',
                            'message' => "SMS has been sent successfully on (".$request->to.")"
                        );
                    }
                }

                 }


            else
            if($voip_provider == 'telnyx')
            {

                if (app()->environment() == "local") 
                {

                   

                    $response_id = true;

                }

                else
                {
                $sms_setting = SmsProviders::on("mysql_$clientId")->where("status",'1')->where('provider',$voip_provider)->get()->first();
                //$auth_id = $sms_setting->auth_id;
                $api_key = $sms_setting->api_key;


                //$telnyxApiKey = 'KEY018C5C0A3935A6E6A770627FDE9749FB_Wd0H3Ktw0S3uJ3xbIXhr51';
                //$api_key = $telnyxApiKey;

                $telnyxApiEndpoint = 'https://api.telnyx.com/v2/messages';

                if($request->has('mms_url'))
                {
                    $data = array('from' => '+'.$request->from, 'to' => '+'.$request->to, 'subject' => 'Picture' , 'text' => $request->message, 'media_urls' => [$request->mms_url]);
                }
                else
                {
                $data = array('from' => '+'.$request->from, 'to' => '+'.$request->to, 'text' => $request->message);

                }


                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $telnyxApiEndpoint);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'Authorization: Bearer '.$api_key,
                ]);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

                $response = curl_exec($ch);

                curl_close($ch);
                $json_decode = json_decode($response);
                $response_id = $json_decode->data->id;

            }



            if(!empty($response_id))
            {
                //Billing part
                $isFree = $intCharge = $currencyCode = $clientPackageId = NULL;

                $user = new User();
                $user->id = $intUserId;
                $user->parent_id = $clientId;
                $package = $user->getAssignedUserPackage(true);

                if(empty($package)){
                    //No charge for Admin
                    $isFree = 1;
                    $intCharge = 0;
                } else {
                    //Calculate SMS charges
                    if($package->free_sms > 0){
                        $isFree = 1;
                        $intCharge = 0;

                        //Deduct free balance
                        DB::connection('mysql_'.$clientId)->table('user_packages')->where('id',$package->user_package_id)->decrement('free_sms',1);

                    } else {
                        $intCharge = $package->rate_per_sms;
                        $isFree = 0;

                        //Deduct amount from client_xxx.wallet
                        wallet::debitCharge($intCharge, $clientId, $package->currency_code);
                    }
                    $currencyCode = $package->currency_code;
                    $clientPackageId = $package->id;
                }


                $smsObj = new Sms;
                $smsObj->setConnection('mysql_' . $clientId);
                $smsObj->number     = $request->to;
                $smsObj->did        = $request->from;
                $smsObj->message    = $request->message;
                $smsObj->operator   = $voip_provider;
                $smsObj->type       = 'outgoing';
                if($request->has('mms_url'))
                {
                    $smsObj->sms_type       = 1;
                    $smsObj->mms_url       = $request->mms_url;

                }

                $smsObj->date       = $request->date;
                $smsObj->extension  = $request->auth->id;
                if(!empty($$clientPackageId))
                $smsObj->currency_code = $clientPackageId;
                else
                $smsObj->currency_code = 'USD';
                $smsObj->client_package_id = $currencyCode;
                $smsObj->user_id = $intUserId;
                $smsObj->charge = $intCharge;
                $smsObj->isFree = $isFree;

                if($smsObj->save()){
                    return array(
                            'success' => 'true',
                            'message' => "SMS has been sent successfully on (".$request->to.")"
                        );
                    }
                }

            }

            else
            if($voip_provider == 'twilio')
            {

                if (app()->environment() == "local") 
                {

                   

                    $response_id = true;

                }

                else
                {


              
                $sms_setting = SmsProviders::on("mysql_$clientId")->where("status",'1')->where('provider',$voip_provider)->get()->first();
                //$auth_id = $sms_setting->auth_id;
                $api_key = $sms_setting->api_key;

                $auth_id = $sms_setting->auth_id;

                $auth_token = $api_key;
                $account_sid = $auth_id;

                if($request->has('mms_url'))
                {
                    $data = array('from' => '+'.$request->from, 'to' => '+'.$request->to, 'body' => $request->message, 'mediaUrl' => [$request->mms_url]);
                }
                else
                {
                    $data = array('from' => '+'.$request->from, 'to' => '+'.$request->to, 'body' => $request->message);
                }

               // return $data;


$twilio_number = '+'.$request->from;
$to_number = '+'.$request->to;

$client = new \Twilio\Rest\Client($account_sid, $auth_token);
$response_twilio = $client->messages->create(
    $to_number,$data
);


$response_id = $response_twilio->sid;

            }

           /// return $request->mms_url.'-'.$response_twilio->sid;



            if(!empty($response_id))
            {
                //Billing part
                $isFree = $intCharge = $currencyCode = $clientPackageId = NULL;

                $user = new User();
                $user->id = $intUserId;
                $user->parent_id = $clientId;
                $package = $user->getAssignedUserPackage(true);

                if(empty($package)){
                    //No charge for Admin
                    $isFree = 1;
                    $intCharge = 0;
                } else {
                    //Calculate SMS charges
                    if($package->free_sms > 0){
                        $isFree = 1;
                        $intCharge = 0;

                        //Deduct free balance
                        DB::connection('mysql_'.$clientId)->table('user_packages')->where('id',$package->user_package_id)->decrement('free_sms',1);

                    } else {
                        $intCharge = $package->rate_per_sms;
                        $isFree = 0;

                        //Deduct amount from client_xxx.wallet
                        wallet::debitCharge($intCharge, $clientId, $package->currency_code);
                    }
                    $currencyCode = $package->currency_code;
                    $clientPackageId = $package->id;
                }


                $smsObj = new Sms;
                $smsObj->setConnection('mysql_' . $clientId);
                $smsObj->number     = $request->to;
                $smsObj->did        = $request->from;
                $smsObj->message    = $request->message;
                $smsObj->operator   = $voip_provider;
                $smsObj->type       = 'outgoing';
                if($request->has('mms_url'))
                {
                    $smsObj->sms_type       = 1;
                    $smsObj->mms_url       = $request->mms_url;

                }

                $smsObj->date       = $request->date;
                $smsObj->extension  = $request->auth->id;
                if(!empty($$clientPackageId))
                $smsObj->currency_code = $clientPackageId;
                else
                $smsObj->currency_code = 'USD';
                $smsObj->client_package_id = $currencyCode;
                $smsObj->user_id = $intUserId;
                $smsObj->charge = $intCharge;
                $smsObj->isFree = $isFree;

                if($smsObj->save()){
                    return array(
                            'success' => 'true',
                            'message' => "SMS has been sent successfully on (".$request->to.")"
                        );
                    }
                }

            }


           
            //}
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }

    function nexmoAPI($from, $to, $message) {
        $url = 'https://rest.nexmo.com/sms/json?' . http_build_query([
                    'api_key' => 'f8540474',
                    'api_secret' => 'oCZAngCfCdjM9NNO',
                    'to' => $to,
                    'from' => $from,
                    'text' => $message
        ]);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
    }

    /*
    public function saveSms($to, $from, $message, $operator, $type, $parent_id, $request) {
        if (!empty($to)) {
            $data['extension'] = $request;
            $data['number'] = trim($to);
            $data['did'] = trim($from);
            $data['message'] = $message;
            $data['operator'] = $operator;
            $data['type'] = $type;
            $query = "INSERT INTO " . $this->table . " (extension, number, did, message, operator, type) VALUE (:extension, :number, :did, :message, :operator, :type)";
            $add = DB::connection('mysql_' . $parent_id)->insert($query, $data);
            if ($add == 1) {

                return array(
                    'success' => 'true',
                    'message' => 'Sms Send successfully.'
                );
            } else {
                return array(
                    'success' => 'false',
                    'message' => 'Sms are not Send successfully.'
                );
            }
        }
    }
    */

    function getSmsCountDetails(Request $request) {
        $data = [
            'incoming' => 0,
            'outgoing' => 0
        ];

        $sql = "SELECT count(1) as rowCount, type FROM " . $this->table . "  group by type ";
        $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql);
        $response = (array) $record;
        foreach ($response as $res) {

            $data[$res->type] = $res->rowCount;
        }
        return array(
            'success' => 'true',
            'message' => 'SMS count',
            'data' => $data
        );
    }


     function getUnreadSmsOpenAI($request) {
        try {
            $data = array();
            $parent_id = $request->auth->parent_id;


            if ($request->has('id') && $request->input('id')) {
                $data['sms_email'] = $request->input('id');
                $sql = "SELECT * FROM did  WHERE sms_email = :sms_email";
                $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql, $data);
                $response = (array) $record;
            }

            $did = array();
            if (!empty($response)) {

                foreach ($response as $res) {

                    // echo "<pre>";print_r($res);die;

                    $did[] = $res->cli;
                }
            }

            if(empty($did)){
                return array(
                    'success' => 'false',
                    'message' => 'No Sms Count',
                    'data' => $data
                );
            }

            $did_all = implode(',', $did);
            if (is_numeric($parent_id)) {
                $data = array();
                $sql = "SELECT count(1) as rowCount,type,number as num FROM " . $this->table . " where status='0' and type='incoming' and did IN (" . $did_all . ") group by number";
                $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql);
                $response = (array) $record;
                foreach ($response as $key => $res) {
                    if($res->num == '14168362235')
                    {
                    $data[$res->num]['countRow'] = $res->rowCount;
                    $data[$res->num]['number'] = $res->num;
                        
                    }


                }
                if (!empty($data)) {
                    return array(
                        'success' => 'true',
                        'message' => 'SMS count',
                        'data' => $data
                    );
                } else {

                    return array(
                        'success' => 'false',
                        'message' => 'No Sms Count',
                        'data' => $data
                    );
                }
            }
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }

    function getUnreadSms($request) {
        try {
            $data = array();
            $parent_id = $request->auth->parent_id;


            if ($request->has('id') && $request->input('id')) {
                $data['sms_email'] = $request->input('id');
                $sql = "SELECT * FROM did  WHERE sms_email = :sms_email";
                $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql, $data);
                $response = (array) $record;
            }

            $did = array();
            if (!empty($response)) {

                foreach ($response as $res) {

                    // echo "<pre>";print_r($res);die;

                    $did[] = $res->cli;
                }
            }

            if(empty($did)){
                return array(
                    'success' => 'false',
                    'message' => 'No Sms Count',
                    'data' => $data
                );
            }

            $did_all = implode(',', $did);
            if (is_numeric($parent_id)) {
                $data = array();
                $sql = "SELECT count(1) as rowCount,type FROM " . $this->table . " where status='0' and type='incoming' and did IN (" . $did_all . ")";
                $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql);
                $response = (array) $record;
                foreach ($response as $res) {
                    $data['countRow'] = $res->rowCount;
                }
                if (!empty($data)) {
                    return array(
                        'success' => 'true',
                        'message' => 'SMS count',
                        'data' => $data
                    );
                } else {

                    return array(
                        'success' => 'false',
                        'message' => 'No Sms Count',
                        'data' => $data
                    );
                }
            }
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }

    public function smsDetailsByDidRecent($request) {
        $data = array();
        $searchStr = array();
        if ($request->has('number') && is_numeric($request->input('number'))) {
            array_push($searchStr, 'number = :number');
            $data['number'] = $request->input('number');
            $data['number1'] = $request->input('number');
        }

        if ($request->has('did') && is_numeric($request->input('did'))) {
            array_push($searchStr, 'did = :did');
            $data['did'] = $request->input('did');
            $data['did1'] = $request->input('did');
        }

        $str = !empty($searchStr) ? "  WHERE " . implode(" AND ", $searchStr) : '';

        $query = "UPDATE " . $this->table . " set status=1 where  (did = :did and number= :number ) or (number = :did1 and did= :number1 )";
        $save_update = DB::connection('mysql_' . $request->auth->parent_id)->update($query, $data);
        $data['last_id'] = $request->input('last_id');
       $sql = "SELECT * FROM " . $this->table . " where id > :last_id and ((did = :did and number= :number ) or (number = :did1 and did= :number1 ) )  order by id "; //." group by did";
        //$sql = "SELECT * FROM ".$this->table;
        $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql, $data);
        $data = (array) $record;

        return array(
            'success' => 'true',
            'message' => 'SMS detail.',
            'data' => $data
        );
    }

    function smsDidList(Request $request)
{
    $clientId = $request->auth->parent_id;
    $id = $request->auth->id;

    $response = Dids::on('mysql_' . $clientId)
        ->where([
            ['sms_email', '=', $id],
            ['sms', '=', 1],
        ])
        ->get(['cli', 'voip_provider']); // ✅ fetch both columns

    return $response;
}

    function smsDidListCRM(Request $request){
        $clientId = $request->auth->parent_id;
        $id = $request->auth->id;
        $response = Dids::on('mysql_' . $clientId)->where([["sms_email",'=',$id],["sms",'=',1]])->get('cli')->all();

        return array(
            'success' => 'true',
            'message' => 'SMS Numbers.',
            'data' => $response
        );
    }

}

