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
use App\Model\Client\Did;


use Plivo\RestClient;
use Twilio\Rest\Client;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use App\Model\UserFcmToken;
use App\Services\FirebaseService;
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
// public function smsDetails(Request $request): array
// {
//     $data_row = [];
//     $clientId = $request->auth->parent_id;
//     $id = $request->auth->id;

//     // Pagination params
//     $start = (int) $request->get('start', 0);   // offset
//     $limit = (int) $request->get('limit', 20);  // number of records

//     // Search filters
//     // $searchNumber = $request->get('number');    // optional number filter
//     // $searchDid = $request->get('did');          // optional did filter
//     $search = $request->get('search'); // unified search for number or did


//     // Get DID records
//     $sql = "SELECT * FROM did WHERE sms_email = :sms_email";
//     $record = DB::connection("mysql_$clientId")->select($sql, ['sms_email' => $id]);
//     $response = (array) $record;

//     if (!empty($response)) {
//         foreach ($response as $res) {
//             $did = $res->cli;

//             // If a specific DID is provided and doesn't match this one, skip it
//             // if (!empty($searchDid) && $searchDid != $did) {
//             //     continue;
//             // }

//             // Subquery for latest message per number
//             // $sql = "SELECT max(id) as id FROM sms WHERE did = ?";

//             // // Add number filter if provided
//             // $params = [$did];
//             // if (!empty($searchNumber)) {
//             //     $sql .= " AND number = ?";
//             //     $params[] = $searchNumber;
//             // }

//             // $sql .= " GROUP BY number";
//             $sql = "SELECT max(id) as id FROM sms WHERE did = ?";
//             $params = [$did];

//             // Add search filter (matches both number and did)
//             if (!empty($search)) {
//                 $sql .= " AND (number LIKE ? OR did LIKE ?)";
//                 $params[] = "%$search%";
//                 $params[] = "%$search%";
//             }

//             $sql .= " GROUP BY number";


//             // Main query
//             $sql1 = "SELECT * FROM sms WHERE id IN ($sql) ORDER BY date DESC";
//             $record = DB::connection("mysql_$clientId")->select($sql1, $params);

//             if (!empty($record)) {
//                 foreach ($record as $k => $k_val) {
//                     if ($k_val->type == 'outgoing') {
//                         $record[$k]->message = $k_val->message;
//                     }
//                 }
//                 $data_row[] = $record;
//             }
//         }
//     }

//     // Flatten and sort
//     $array_result = array_reduce($data_row, 'array_merge', []);
//     $sorted = $this->array_sort($array_result, 'date', SORT_DESC);

//     // Total count
//     $total = count($sorted);

//     // Apply offset + limit
//     $pagedData = array_slice($sorted, $start, $limit);

//     // Return response
//     return [
//         'success' => true,
//         'message' => 'SMS fetched successfully',
//         'start' => $start,
//         'limit' => $limit,
//         'total' => $total,
//         'data' => array_values($pagedData),
//     ];
// }

public function smsDetails(Request $request): array
{
    $data_row = [];
    $clientId = $request->auth->parent_id;
    $id = $request->auth->id;

    // Pagination params
    $start = (int) $request->get('start', 0);
    $limit = (int) $request->get('limit', 20);

    // Unified search filter (for did or number)
    $search = $request->get('search');

    // ✅ Get DID records for this user
    $sql = "SELECT cli, voip_provider FROM did WHERE sms_email = :sms_email";
    $dids = DB::connection("mysql_$clientId")->select($sql, ['sms_email' => $id]);

    if (!empty($dids)) {
        foreach ($dids as $didRow) {
            $did = $didRow->cli;
            $voipProvider = $didRow->voip_provider ?? '';

            // ✅ Prepare query for latest message per number for this DID
            $sql = "SELECT MAX(id) AS id FROM sms WHERE did = ?";
            $params = [$did];

            if (!empty($search)) {
                $sql .= " AND (number LIKE ? OR did LIKE ?)";
                $params[] = "%$search%";
                $params[] = "%$search%";
            }

            $sql .= " GROUP BY number";

            // ✅ Fetch latest messages
            $sql1 = "SELECT * FROM sms WHERE id IN ($sql) ORDER BY date DESC";
            $records = DB::connection("mysql_$clientId")->select($sql1, $params);

            if (!empty($records)) {
                foreach ($records as $k => $k_val) {
                    // Include voip_provider from DID table
                    $records[$k]->voip_provider = $voipProvider;

                    if ($k_val->type == 'outgoing') {
                        $records[$k]->message = $k_val->message;
                    }
                }
                $data_row[] = $records;
            }
        }
    }

    // ✅ Flatten and sort by date DESC
    $array_result = array_reduce($data_row, 'array_merge', []);
    $sorted = $this->array_sort($array_result, 'date', SORT_DESC);

    // ✅ Pagination
    $total = count($sorted);
    $pagedData = array_slice($sorted, $start, $limit);
$userIds = array_unique(array_column($pagedData, 'user_id'));

$users = DB::connection("master")
    ->table('users')
    ->selectRaw("id, CONCAT(first_name, ' ', last_name) AS full_name")
    ->whereIn('id', $userIds)
    ->pluck('full_name', 'id');   // [id => full_name]


// --- Attach user_name to each SMS record ---
foreach ($pagedData as &$row) {
    $row->user_name = $users[$row->user_id] ?? null;
}
    // ✅ Return response
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
public function smsDetailsByDid($request)
{
    $data = [];

    if ($request->has('number') && is_numeric($request->input('number'))) {
        $data['number'] = $request->input('number');
        $data['number1'] = $request->input('number');
    }

    if ($request->has('did') && is_numeric($request->input('did'))) {
        $data['did'] = $request->input('did');
        $data['did1'] = $request->input('did');
    }

    $clientId = $request->auth->parent_id;
    $table = $this->table;

    // ✅ Update records (mark as read)
    $updateQuery = "UPDATE $table 
                    SET status = 1 
                    WHERE (did = :did AND number = :number) 
                       OR (number = :did1 AND did = :number1)";
    DB::connection("mysql_$clientId")->update($updateQuery, $data);

    // ✅ Count total rows for pagination
    $countQuery = "SELECT COUNT(*) as total_rows 
                   FROM $table 
                   WHERE (did = :did AND number = :number) 
                      OR (number = :did1 AND did = :number1)";
    $countResult = DB::connection("mysql_$clientId")->select($countQuery, $data);
    $total_rows = $countResult[0]->total_rows ?? 0;

    // ✅ Apply pagination
    $start = $request->input('start', 0);
    $limit = $request->input('limit', 10);

    // ✅ Fetch records with voip_provider join
    $sql = "SELECT s.*, d.voip_provider
            FROM $table s
            LEFT JOIN did d ON d.cli = s.did
            WHERE (s.did = :did AND s.number = :number) 
               OR (s.number = :did1 AND s.did = :number1)
            ORDER BY s.id DESC
            LIMIT $start, $limit";

    $records = DB::connection("mysql_$clientId")->select($sql, $data);

    // ✅ Ensure message & voip_provider are always included
    // $records = collect($records)->map(function ($r) {
    //     $r->message = $r->message ?? '';
    //     $r->voip_provider = $r->voip_provider ?? '';
    //     return $r;
    // })->toArray();
    // ✅ conversation_id = latest SMS id (first record, since ORDER BY s.id DESC)
$conversationId = !empty($records) ? (string) $records[0]->id : null;

$records = collect($records)->map(function ($r) use ($conversationId) {

    $ordered = [
        'id' => $r->id,
        'conversation_id' => $conversationId, // ✅ SAME for all
    ];

    foreach ($r as $key => $value) {
        if ($key !== 'id') {
            $ordered[$key] = $value ?? '';
        }
    }

    return $ordered;
})->toArray();


    /* ============================
       🔔 SEND PUSH NOTIFICATION
       ============================ */

    try {
        // Example: notify the logged-in user
        $fcmTokens = UserFcmToken::where('user_id', $request->auth->id)
            ->pluck('device_token')
            ->toArray();

        if (!empty($fcmTokens)) {
            FirebaseService::sendNotification(
                $fcmTokens,
                'SMS Opened',
                'Conversation viewed',
                [
                    'conversation_id' => $conversationId,
                    'type' => 'sms_chat'
                ]
            );
        }
    } catch (\Exception $e) {
        Log::error('FCM SMS Notification failed', [
            'error' => $e->getMessage()
        ]);
    }

    // ✅ Return final response
    return [
        'success' => true,
        'message' => 'SMS detail.',
        'pagination' => [
            'start' => (int) $start,
            'limit' => (int) $limit,
            'total_rows' => $total_rows,
        ],
        'data' => $records,
    ];
}  

    // public function smsDetailsByDid($request) {
    //     $data = array();
    //     $searchStr = array();
    //     if ($request->has('number') && is_numeric($request->input('number'))) {
    //         array_push($searchStr, 'number = :number');
    //         $data['number'] = $request->input('number');
    //         $data['number1'] = $request->input('number');
    //     }

    //     if ($request->has('did') && is_numeric($request->input('did'))) {
    //         array_push($searchStr, 'did = :did');
    //         $data['did'] = $request->input('did');
    //         $data['did1'] = $request->input('did');
    //     }


    //     $str = !empty($searchStr) ? "  WHERE " . implode(" AND ", $searchStr) : '';
	// 	$query = "UPDATE " . $this->table . " set status=1 where  (did = :did and number= :number ) or (number = :did1 and did= :number1 )";
    //     $save_update = DB::connection('mysql_' . $request->auth->parent_id)->update($query, $data);

    //     $sql = "SELECT * FROM " . $this->table . " where  (did = :did and number= :number ) or (number = :did1 and did= :number1 )  order by id "; //." group by did";
    //     $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql, $data);
    //     $data = (array) $record;

	// 	if($data){
	// 		foreach($data as $key=>$val){
	// 			//if($val->type=='outgoing')
	// 			$data[$key]->message = $val->message;
	// 		}
	// 	}
	// 	//echo '<pre>'.count($data); print_R($data); exit;
    //     return array(
    //         'success' => 'true',
    //         'message' => 'SMS detail.',
    //         'data' => $data
    //     );
    // }
public function smsDetailsByDidold($request)
{
    $data = [];
    $searchStr = [];

    if ($request->has('number') && is_numeric($request->input('number'))) {
        $searchStr[] = 'number = :number';
        $data['number'] = $request->input('number');
        $data['number1'] = $request->input('number');
    }

    if ($request->has('did') && is_numeric($request->input('did'))) {
        $searchStr[] = 'did = :did';
        $data['did'] = $request->input('did');
        $data['did1'] = $request->input('did');
    }

    $str = !empty($searchStr) ? " WHERE " . implode(" AND ", $searchStr) : '';

    $clientId = $request->auth->parent_id;
    $table = $this->table;

    // ✅ Update records
    $updateQuery = "UPDATE $table SET status = 1 
                    WHERE (did = :did AND number = :number) 
                       OR (number = :did1 AND did = :number1)";
    DB::connection("mysql_$clientId")->update($updateQuery, $data);

    // ✅ Count total rows for pagination
    $countQuery = "SELECT COUNT(*) as total_rows FROM $table 
                   WHERE (did = :did AND number = :number) 
                      OR (number = :did1 AND did = :number1)";
    $countResult = DB::connection("mysql_$clientId")->select($countQuery, $data);
    $total_rows = $countResult[0]->total_rows ?? 0;

    // ✅ Apply pagination if provided
    $start = $request->has('start') ? (int) $request->input('start') : 0;
    $limit = $request->has('limit') ? (int) $request->input('limit') : 10; // default limit 10

    $sql = "SELECT * FROM $table 
            WHERE (did = :did AND number = :number) 
               OR (number = :did1 AND did = :number1) 
            ORDER BY id Desc
            LIMIT $start, $limit";

    $records = DB::connection("mysql_$clientId")->select($sql, $data);
    $records = (array) $records;

    if (!empty($records)) {
        foreach ($records as $key => $val) {
            $records[$key]->message = $val->message;
        }
    }

    return [
        'success' => true,
        'message' => 'SMS detail.',
            'pagination' => [
            'start' => $start,
            'limit' => $limit,
            'total_rows' => $total_rows
            ],
        'data' => $records,    
    ];
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
           $mms_url = null;


            if ($request->hasFile('mms_file')) {
                $file = $request->file('mms_file');

                // CLEAN the original name
                $original = $file->getClientOriginalName();

                // Remove spaces & unsafe characters
                $cleanName = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $original);

                // Final stored filename
                $fileName = time() . '_' . $cleanName;

                // Save to public folder
                $uploadPath = base_path('public/uploads/mms');
                $file->move($uploadPath, $fileName);

                // Build URL for Twilio
                $mms_url = env('APP_URL') . '/uploads/mms/' . $fileName;
                //    $mms_url="https://api.phonify.app/uploads/mms/1763546502_image%20 (5).png";
            }


            Log::info('mms url',['mms_url'=>$mms_url]);

            $data_array['mms_url'] = $mms_url ?? $request->mms_url;

            Log::info('reached backend from',['from'=>$request->from]);
            $get_provider = Dids::on("mysql_$clientId")->where("cli",$request->from)->get()->first();
            if (!$get_provider) {
                        return array(
                            'success' => 'false',
                            'message' => "From number not found in DID table. Please add the number first."                        );
                            }
                    if (empty($get_provider->voip_provider)) {
                        return array(
                            'success' => 'false',
                            'message' => "Please add voip provider in DID table."
                        );
                    } 

            //$voip_provider = $get_provider->voip_provider;
            $voip_provider = strtolower(trim($get_provider->voip_provider));

            Log::info('reached twilio',['voip_provider'=>$voip_provider]);


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
                //if ($request->has('mms_url')) {
                    // Check if MMS was uploaded or generated
                if (!empty($data_array['mms_url'])) {

                    // Send MMS if mms_url is provided
                    $result = $client->messages->create([
                        "src" => $data_array['from'],                 // Sender's phone number
                        "dst" => $data_array['to'],                   // Recipient's phone number
                        "text" => $data_array['text'],                // Text content
                        "type" => "mms",                              // Explicitly set to MMS
                        "media_urls" => [$data_array['mms_url']]      // Use provided media URL
                    ]);
                   // $response_id = $result->messageUuid[0] ?? true;

                } else {
                    // Send SMS if mms_url is not provided
                    $result = $client->messages->create([
                        "src" => $data_array['from'],                 // Sender's phone number
                        "dst" => $data_array['to'],                   // Recipient's phone number
                        "text" => $data_array['text']                 // Text content
                    ]);
                   // $response_id = $result->messageUuid[0] ?? true;

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
                            'message' => "SMS has been sent successfully on (".$request->to.")"
                        );
                    }
                }
  return [
    'success' => false,
    'message' => $result->error 
        ?? $result->_message 
        ?? 'Plivo SMS request failed'
];



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
                    $data = array('from' => '+'.$request->from, 'to' => '+'.$request->to, 'subject' => 'Picture' , 'text' => $request->message, 'media_urls' => [$data_array['mms_url']]);
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
                    Log::info('reached twilio');


                // if (app()->environment() == "local") 
                // {

                   

                //     $response_id = true;

                // }

                // else
                // {


              
                $sms_setting = SmsProviders::on("mysql_$clientId")->where("status",'1')->where('provider',$voip_provider)->get()->first();
                //$auth_id = $sms_setting->auth_id;
                $api_key = $sms_setting->api_key;

                $auth_id = $sms_setting->auth_id;

                $auth_token = $api_key;
                $account_sid = $auth_id;

                if($mms_url)
                {
                   // $test_image_url = 'https://demo.twilio.com/owl.png';
                    $data = array('from' => '+'.$request->from, 'to' => '+'.$request->to, 'body' => $request->message, 'mediaUrl' => $mms_url);
                }
                else
                {
                    $data = array('from' => '+'.$request->from, 'to' => '+'.$request->to, 'body' => $request->message);
                }

               // return $data;


$twilio_number = '+'.$request->from;
$to_number = '+'.$request->to;

$client = new \Twilio\Rest\Client($account_sid, $auth_token);
try {

    $response_twilio = $client->messages->create($to_number, $data);
    $response_id = $response_twilio->sid;

} catch (\Twilio\Exceptions\RestException $e) {

    // TWILIO ERROR MESSAGE
    $twilioMessage = $e->getMessage();
    $status = $e->getStatusCode();   
    $errorCode = $e->getCode();     // Twilio-specific error code (ex: 20003)

    // Authentication errors → 401 or code 20003
    // if ($status == 401 || $errorCode == 20003) {
    //     return [
    //         'success' => false,
    //         'message' => "Authentication failed for Twilio! Please verify your Account SID and Auth Token."
    //     ];
    // }
return response()->json([
    'success' => false,
    'message' => "Authentication failed for Twilio! Please verify your Account SID and Auth Token."
], 402);

    // Invalid number
    if ($errorCode == 21608) {
        return [
            'success' => false,
            'message' => "The destination number is not verified in your Twilio account."
        ];
    }

    // Phone number formatting error
    if ($errorCode == 21211) {
        return [
            'success' => false,
            'message' => "Invalid phone number format. Please check the number and try again."
        ];
    }

    // Fallback for all other Twilio errors
    return [
        'success' => false,
        'message' => "Twilio Error: " . $twilioMessage
    ];
}
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
                $smsObj->type       = 'outgoing';
                $smsObj->operator   = $voip_provider;
                $mms_full_url= $data_array['mms_url'];
                       
                if($mms_full_url)
                {
                    $smsObj->sms_type       = 1;
                    $smsObj->mms_url       = $mms_full_url;

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

            //}


           
            //}
        } catch (Exception $e) {
            Log::log($e->getMessage());
              return response()->json([
        'success' => false,
        'message' => $e->getMessage()
    ], 500);
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
              return response()->json([
        'success' => false,
        'message' => $e->getMessage()
    ], 500);
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

