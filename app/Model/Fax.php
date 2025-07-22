<?php

namespace App\Model;

use App\Model\Client\wallet;
use App\Model\User;
use App\Http\Helper\Log;
use App\Model\Master\Did;
use App\Model\Client\FaxDid;
use App\Model\Master\Client;
use App\Jobs\SendFaxEmailJob;
use App\Model\Client\UserToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;

//use Illuminate\Support\Facades\Log;

class Fax extends Model
{

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $table = 'fax';

    /*
     * Fetch SMS list
     * @param integer $id
     * @return array
     */

    public function faxDetails($request)
    {
        try {
            $data = array();
            $sql = "SELECT * FROM " . $this->table ." order by id desc";
            $record = DB::connection('mysql_' . $request->auth->parent_id)->select($sql, $data);
            $data = (array) $record;
            if (!empty($data)) {
                return array(
                    'success' => 'true',
                    'message' => 'Fax Number detail.',
                    'data' => $data
                );
            }
            return array(
                'success' => 'false',
                'message' => 'Fax Number not created.',
                'data' => array()
            );
        } catch (Exception $e) {
            Log::log($e->getMessage());
        } catch (InvalidArgumentException $e) {
            Log::log($e->getMessage());
        }
    }

    public function receiverFax($request)
    {
        /*
        $fax_data = [
            "action" => "Fax Received From ",
            "dialednumber" => 15963255,
            "did" =>  12365488,
            'receipt' => 1
        ];
        dispatch(new SendFaxEmailJob(1, 9586985632, $fax_data=array(),'google.com'))->onConnection("database");
        die();
        */
        try {
            $user_array = $user_fax = [];
            $isFree = $intCharge = $currencyCode = $clientPackageId = NULL;
            $cli = $request->dialednumber;
            $did_record = Did::where([["cli", "=", $cli]])->firstOrFail()->toArray();

            $parent_id = $did_record['parent_id'];
            $user_id = $did_record['user_id'];


            $userListObj = FaxDid::on('mysql_' . $parent_id)->where([["did", "=", $cli]])->get()->toArray();

            foreach ($userListObj as $key=>$val) {
                $user_data = User::find($val['userId']);
                $user_array[] = $val['userId'];
                $user_fax[] = array('extension'=>$user_data->extension,'received'=>$request->received,'numofpages'=>$request->numofpages,'dialednumber'=>$request->dialednumber,'callerid'=>$request->callerid,'faxurl'=>$request->faxurl,'faxstatus'=>$request->faxstatus);
            }

            Fax::on('mysql_' . $parent_id)->insert($user_fax);

            //Fax Billing
            //User will be selected for billing who have the highest level package is assigned.
            if($request->faxstatus == 'COMPLETE' && !empty($user_array)) {
                $userId = $this->getHighLevelPackageAssignedUser($user_array, $parent_id);

                $user = new User();
                $user->id = $userId;
                $user->parent_id = $parent_id;
                $package = $user->getAssignedUserPackage(true);

                if(empty($package)){
                    //No charge for Admin
                    $isFree = 1;
                    $intCharge = 0;
                } else {
                    //Calculate fax charges
                    if($package->free_fax > 0){
                        $isFree = 1;
                        $intCharge = 0;

                        //Deduct free balance
                        DB::connection('mysql_'.$parent_id)->table('user_packages')->where('id',$package->user_package_id)->decrement('free_fax',1);

                    } else {
                        $intCharge = $package->rate_per_fax;
                        $isFree = 0;

                        //Deduct amount from client_xxx.wallet
                        wallet::debitCharge($intCharge, $parent_id, $package->currency_code);
                    }

                    $currencyCode = $package->currency_code;
                    $clientPackageId = $package->id;
                }

                //Update Fax entry
                $objFax = Fax::on("mysql_" . $parent_id)->where('faxurl', '=', $request->faxurl)->first();
                $objFax->currency_code = $currencyCode;
                $objFax->client_package_id = $clientPackageId;
                $objFax->user_id = $userId;
                $objFax->charge = $intCharge;
                $objFax->isFree = $isFree;
                $objFax->saveOrFail();
            }




            //exit;

            // Get all map user list using cli
            // $searchFax = Fax::on('mysql_' . $parent_id)->where([["callerid", "=", $request->callerid]])->first();


            // $searchFax = Fax::on('mysql_' . $parent_id)->where([["callerid", "=", $request->callerid]])->first();
            /*
            +--------------+------------------+------+-----+-------------------+-------------------+
| Field        | Type             | Null | Key | Default           | Extra             |
+--------------+------------------+------+-----+-------------------+-------------------+
| id           | bigint unsigned  | NO   | PRI | NULL              | auto_increment    |
| faxurl       | varchar(255)     | YES  |     | NULL              |                   |
| dialednumber | varchar(255)     | YES  |     | NULL              |                   |
| callerid     | varchar(255)     | YES  |     | NULL              |                   |
| faxstatus    | varchar(255)     | YES  |     | NULL              |                   |
| numofpages   | varchar(255)     | YES  |     | NULL              |                   |
| received     | varchar(255)     | YES  |     | NULL              |                   |
| start_time   | timestamp        | NO   |     | CURRENT_TIMESTAMP | DEFAULT_GENERATED |
| ref_id       | varchar(255)     | YES  |     | NULL              |                   |
| fax_type     | tinyint unsigned | NO   |     | 1                 |                   |
| extension    | varchar(255)     | YES  |     | NULL              |                   |
+--------------+------------------+------+-----+-------------------+-------------------+

            */
            // if (!$searchFax) {
            // $faxObj = new Fax;
            // $faxObj->setConnection('mysql_' . $parent_id);
            // $faxObj->dialednumber = $request->dialednumber;
            // $faxObj->callerid = $request->callerid;
            // $faxObj->faxurl = $request->faxurl;
            // $faxObj->faxstatus = $request->faxstatus;
            // $faxObj->numofpages = $request->numofpages;
            // $faxObj->received = $request->received;
            // $faxObj->save();
            // }


            //send notification
            foreach ($user_array as $key) {
                $userToken = UserToken::on('mysql_'.$parent_id)->find(['userId'=>$key]);
                if (isset($userToken->deviceToken)) {
                    $deviceToken = $userToken->deviceToken;
                    $deviceType = $userToken->deviceType;
                    $title = "Fax Notification";
                    $body  = "You have received a fax from ".$request->callerid;
                    $type  = "FAX";
                    $this->sendNotification($deviceToken, $title, $body, $type, $deviceType);
                }
            }

            //end notification



            //send mail

            $fax_data = [
                "action" => "Fax Received From ".$request->callerid."",
                "dialednumber" => $request->dialednumber,
                "did" =>  $request->callerid,
                'receipt' => 1,
                "faxurl" => $request->faxurl,
                "user_array"=> $user_array
            ];

            dispatch(new SendFaxEmailJob($parent_id, $request->dialednumber, $fax_data))->onConnection("database");
            exit;
            //end send mail

            return array(
                'success' => true,
                'message' => 'New Fax detail saved'
            );
        } catch (\Throwable $e) {
            /*
              Log::error("Fax.receiverFax.error", [
              "message" => $e->getMessage(),
              "line" => $e->getLine(),
              "file" => $e->getFile(),
              "code" => $e->getCode()
              ]); */
            return array(
                'success' => false,
                'message' => 'Failed to save receive fax detail. ' . $e->getMessage(),
                'code' => $e->getCode(),
                'dialednumber' => $request->dialednumber,
                'line'=>$e->getLine(),
                'count' => 0
            );
        }
    }

    public function sendFax($request)
    {
        $faxurl = $request->faxurl;
//        $faxurl = 'http://www.biogem.org/downloads/notes/PHP%20Variables.pdf';
        $callid = $request->callid;
        $dialednumber = $request->dialednumber;

        // Sending fax
        $api = 'dJhDL6wSTec20f12b42b93d8d20e6105510a9066d6';
        $access = '3HIaCcu9Pt8UUZ4nfc5zw095stRbThCujsQ';

        $data_array = array();
        $data_array['to'] = $dialednumber;
        $data_array['from'] = $callid;
        $data_array['pdf'] = base64_encode(file_get_contents($faxurl));     // fax pdf

        $json_data_to_send = json_encode($data_array);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://api.didforsale.com/didforsaleapi/index.php/api/V4/products/WebSendFax");
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data_to_send);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/json", "Authorization: Basic " . base64_encode("$api:$access")));
        $result = curl_exec($ch);
        $res = json_decode($result);

        $faxModel = new Fax();
        $faxModel->setConnection('mysql_' . $request->auth->parent_id);
        $faxModel->faxurl = $faxurl;
        $faxModel->callerid = $callid;
        $faxModel->fax_type = '1';
        $faxModel->extension = $request->auth->extension;
        $faxModel->dialednumber = $dialednumber; // where N is infinite # of attr
        $faxModel->start_time = \Carbon\Carbon::now();

        if ($res->status == 1) {
            $faxModel->faxstatus = 1;
            $faxModel->delivery_status = "TRYING";
            $faxModel->ref_id = $res->uuid;
            $userData = array(
                'success' => true,
                'message' => $res->message
            );

            //Fax Billing - only for those Fax.ref_id is present
            $isFree = $intCharge = $currencyCode = $clientPackageId = NULL;
            $user = new User();
            $user->id = $request->auth->id;
            $user->parent_id = $request->auth->parent_id;
            $package = $user->getAssignedUserPackage(true);

            if(empty($package)){
                //No charge for Admin
                $isFree = 1;
                $intCharge = 0;
            } else {
                //Calculate fax charges
                if($package->free_fax > 0){
                    $isFree = 1;
                    $intCharge = 0;

                    //Deduct free balance
                    DB::connection('mysql_'.$request->auth->parent_id)->table('user_packages')->where('id',$package->user_package_id)->decrement('free_fax',1);

                } else {
                    $intCharge = $package->rate_per_fax;
                    $isFree = 0;

                    //Deduct amount from client_xxx.wallet
                    wallet::debitCharge($intCharge, $request->auth->parent_id, $package->currency_code);
                }

                $currencyCode = $package->currency_code;
                $clientPackageId = $package->id;
            }

            //Update Fax entry
            $faxModel->currency_code = $currencyCode;
            $faxModel->client_package_id = $clientPackageId;
            $faxModel->user_id = $request->auth->id;
            $faxModel->charge = $intCharge;
            $faxModel->isFree = $isFree;
            $faxModel->saveOrFail();

            // send fax mail code //

            $did =$callid;

            $fax_data = [
                "action" => "Fax Acknowledgment From ".$dialednumber."",
                "dialednumber" => $dialednumber,
                "did" =>  $did,
                "faxurl"=>'',
                "user_array"=> ''
            ];

            dispatch(new SendFaxEmailJob($request->auth->parent_id, $did, $fax_data))->onConnection("database");

        //closed send fax mail //
        } else {
            $faxModel->faxstatus = 0;
            $userData = array(
                'success' => false,
                'message' => $res->message
            );
        }
        $faxModel->save();
        return $userData;
    }

    public function receiveFaxList($request)
    {
        try {
            $searchFax = Fax::on('mysql_' . $request->auth->parent_id)->where([["extension", "=", $request->auth->extension], ["fax_type", "=", $request->fax_type], ["faxstatus", "=", $request->faxstatus]])->get();

            return array(
                'success' => 'true',
                'message' => 'Fax Number detail.',
                'data' => $searchFax
            );
        } catch (\Throwable $e) {
            return array(
                'success' => false,
                'message' => 'Failed to get receive fax detail. ' . $e->getMessage(),
                'code' => $e->getCode(),
                'data' => array()
            );
        }
    }

    public function sendNotification($deviceToken, $title, $body, $type, $deviceType)
    {
        $SERVER_API_KEY = env("SERVER_API_KEY");
        if ($deviceType == 'Android') {
            $data = [
                "to" => trim($deviceToken),
                "data" =>[
                        "title" => $title, //$request->title,
                        "body"  => $body,
                        "type"  => $type,
                    ]
                ];
        } elseif ($deviceType == 'ios') {
            $data = [
                    "notification" =>[
                        "body"  => $body,
                        "title" => $title, //$request->title,
                        "type"  => $type,
                        "sound" =>"Default",
                        "content-available"=>1
                ],
                "to"=>trim($deviceToken),
                ];
        }
        $dataString = json_encode($data);
        //echo "<pre>";print_r($dataString);die;

        $headers = [
                'Authorization: key=' . $SERVER_API_KEY,
                'Content-Type: application/json',
            ];
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, 'https://fcm.googleapis.com/fcm/send');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $dataString);
            $response = curl_exec($ch);
            return $this->successResponse("Notification Send Successfully", $data);
        } catch (\Throwable $e) {
            return array(
                'success' => false,
                'message' => 'Failed to get receive fax detail. ' . $e->getMessage(),
                'code' => $e->getCode(),
                'data' => array()
            );
        }
    }

    /**
     * @param $userArray
     * @param $clientId
     * @return int
     */
    public function getHighLevelPackageAssignedUser($userArray, $clientId): int
    {
        $arrRankings = [];
        $arrPackageLevels = [ 1 => 'Starter', 2 => 'Standard', 3 => 'Premium'];
        $Sql = "SELECT up.user_id,p.name from client_packages as cp
                        JOIN client_{$clientId}.user_packages as up ON ( cp.id = up.client_package_id )
                        JOIN packages as p ON ( p.key = cp.package_key )
                        WHERE up.user_id IN (".implode(" ,",$userArray).")";
        $results    = DB::select($Sql, array('user_id' => $this->id));

        foreach ($results as $key => $userPackage ){
            foreach ($arrPackageLevels as $intRank => $strPackageName ){
                if($userPackage->name == $strPackageName){
                    $arrRankings[$userPackage->user_id] = $intRank;
                }
            }
        }

        return array_search(max($arrRankings),$arrRankings);
    }
}
