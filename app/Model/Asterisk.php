<?php

namespace App\Model;

use App\Model\Client\ExtensionLive;
use App\Model\Master\AreaCodeList;
use App\Model\Master\Client;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use App\Exceptions\RenderableException;
use App\Services\EasifyCreditService;


class Asterisk extends Model
{
    protected $table = "asterisk_server";

    protected $connection = 'master';

    public $timestamps = false;

    private $extension = null;
    private $waitTime = "30000";
    private $port = 5038;
    private $admin;

    const STATUS_READY  = 0;
    const STATUS_QUEUE  = 1;
    const STATUS_INCALL = 2;
    const STATUS_PAUSE  = 3;

    public function setExtension(int $extension)
    {
        $this->extension = $extension;
    }

    public function setAdmin(int $adminId)
    {
        $this->admin = $adminId;
    }

    /**
     * @return mixed
     */
    public function amiCommand($request, $param = array())
    {
        $param['request'] = addslashes(htmlentities($request));
        if(!empty($request))
        {
            $socket = stream_socket_client("tcp://".$this->host.":$this->port");
            if($socket)
            {
                // Prepare authentication request
                $authenticationRequest = "Action: Login\r\n";
                $authenticationRequest .= "Username: $this->user\r\n";
                $authenticationRequest .= "Secret: $this->secret\r\n";
                $authenticationRequest .= "Events: Off\r\n\r\n";
                // Send authentication request
                $authenticate = stream_socket_sendto($socket, $authenticationRequest);
                if($authenticate > 0)
                {
                    if($param['action'] == 'predictive_dial')
                    {
                            $this->cdrLog($param);
                    }
                    else
                    if($param['action'] == 'outbound_ai')
                    {
                            $this->cdrLog($param);
                    }
                    usleep(200000);
                    $authenticateResponse = fread($socket, 4096);
                    if(strpos($authenticateResponse, 'Success') !== false)
                    {
                        // Send originate request
                        $originate = stream_socket_sendto($socket, $request);

                       

                        if($originate > 0)
                        {
                            // Wait for server response
                            usleep(200000);
                            // Read server response
                            $originateResponse = fread($socket, 4096);
                            // Check if originate was successful
                            if(strpos($originateResponse, 'Success') !== false)
                            {
                                $param['response'] = "Call initiated, dialing.";
                                $this->amiLog($param);
                                return "true";
                            } else {
                                $param['response'] = "Could not initiate call, reason unknown";
                                $this->amiLog($param);
                                return "false";
                            }
                        }else {
                            $param['response'] = "Could not write call initiation request to socket";
                            $this->amiLog($param);
                            return "false";
                        }
                    } else {
                        $param['response'] =  "Could not authenticate to Asterisk Manager Interface";
                        $this->amiLog($param);
                        return "false";
                    }
                } else {
                    $param['response'] =  "Could not write authentication request to socket";
                    $this->amiLog($param);
                    return "false";
                }
            } else {
                $param['response'] =  "Unable to connect to socket.";
                $this->amiLog($param);
                return "false";
            }
        } else{
            return "false";
        }
    }


    /*CRM Webphone Dailer Feature*/

    public function asteriskLoginCRM($extension,$campaignId)
    {
        

        $callerId = "5500"."$extension"."$campaignId";
        $extenStr = "5500-".$extension."-".$campaignId;
        $originateRequest = "Action: originate\r\n";
        $originateRequest .= "Channel: SIP/$extension\r\n";
        $originateRequest .= "Timeout: $this->waitTime\r\n";
        $originateRequest .= "Callerid: $callerId\r\n";
        $originateRequest .= "Exten: $extenStr\r\n";
        $originateRequest .= "Context: dialler-agent-login-web\r\n";
        $originateRequest .= "Priority: 1\r\n";
        $originateRequest .= "Async: yes\r\n";
        $originateRequest .= "Action: Logoff\r\n\r\n";
        // Send originate request
        $param['action'] = 'login';
        $param['campaign_id'] = $campaignId;
        return $this->amiCommand($originateRequest, $param);

        
    }

    /*CLose*/

    public function asteriskLogin($campaignId = '')
    {
        if(!empty($campaignId))
        {
            #on local, make entry in extension_live and return
            if (app()->environment() == "local") {
                $extensionLive = new ExtensionLive();
                $extensionLive->extension = $this->extension;
                $extensionLive->campaign_id = $campaignId;
                $extensionLive->status = Asterisk::STATUS_READY;
                $extensionLive->setConnection("mysql_".$this->admin);
                $extensionLive->saveOrFail();
                Log::debug("asteriskLogin", $extensionLive->toArray());
                return true;
            }

            $callerId = "5500"."$this->extension"."$campaignId";
            $extenStr = "5500-".$this->extension."-".$campaignId;
            $originateRequest = "Action: originate\r\n";
            $originateRequest .= "Channel: SIP/$this->extension\r\n";
            $originateRequest .= "Timeout: $this->waitTime\r\n";
            $originateRequest .= "Callerid: $callerId\r\n";
            $originateRequest .= "Exten: $extenStr\r\n";
            $originateRequest .= "Context: dialler-agent-login-web\r\n";
            $originateRequest .= "Priority: 1\r\n";
            $originateRequest .= "Async: yes\r\n";
            $originateRequest .= "Action: Logoff\r\n\r\n";
            // Send originate request
            $param['action'] = 'login';
            $param['campaign_id'] = $campaignId;
            return $this->amiCommand($originateRequest, $param);

        } else{
            return "false";
        }
    }
    public function asteriskLogout(int $parent_id, int $extension)
    {
        $return = [
            "success" => false,
            "message" => "Unknown"
        ];
        try {
            $this->hangUp();
            $extensionLive = ExtensionLive::on('mysql_'.$parent_id)->findOrFail($extension);
            $channel = $extensionLive->channel;
            if(!empty($channel))
            {
                $request = "Action: Hangup\r\n";
                $request .= "Channel: $channel\r\n";
                $request .= "Timeout: $this->waitTime\r\n";
                $request .= "Async: yes\r\n";
                $request .= "Action: Logoff\r\n\r\n";

                // Send originate request
                $param['action'] = 'logout';
                $this->amiCommand($request, $param);
            }
            $return["success"] = $extensionLive->delete();
            $return["message"] = "Asterisk logout sent and live entry deleted";
        } catch (ModelNotFoundException $exception) {
            $return["message"] = sprintf("No login found for extension %d", $extension);
        }
        return $return;
    }


     public function click2CallCRM($number, $campaignId, $id,$extension, $userData, $crm_cli)
    {

        $number = preg_replace('/[^0-9]/', '', $number);
        $area_code =  substr($number, 0, 3);
        $sql = "SELECT extension FROM extension_live WHERE extension = :extension and status = :status";
        $agentLoginStatus =DB::connection('mysql_'.$this->admin)->selectOne($sql, array('extension' => $extension, 'status' => 0));

        //campaign

        $sql_campaign = "SELECT country_code,caller_id,custom_caller_id FROM campaign WHERE id = :id";
        $campaign_details =DB::connection('mysql_'.$this->admin)->selectOne($sql_campaign, array('id' => $campaignId));

        $cli=0;
        $country_code = $campaign_details->country_code;


        $caller_id = $userData->cli_setting;

        if($crm_cli)
        {
            $cli = $crm_cli;
        }

        else
        if($caller_id == '1')
        {
            $cli = $userData->cli;
        }

        else
            if($caller_id == '0')
            {
                $sql_area_code = "SELECT cli from did where area_code = :area_code and set_exclusive_for_user= :set_exclusive_for_user and is_deleted='0' ORDER BY RAND() limit 0,1";
                $area_code_details =DB::connection('mysql_'.$this->admin)->selectOne($sql_area_code, array('area_code' => $area_code, 'set_exclusive_for_user' => '0'));

                if(!empty($area_code_details->cli))
                {
                    $cli = $area_code_details->cli;
                }
                else
                {
                    $sql_area_code_default_did = "SELECT cli from did where default_did=:default_did and set_exclusive_for_user=:set_exclusive_for_user and is_deleted='0' ORDER BY RAND() limit 0,1";
                    $area_code_default_did_details =DB::connection('mysql_'.$this->admin)->selectOne($sql_area_code_default_did, array('default_did' => 0, 'set_exclusive_for_user' => '0'));

                    if($area_code_default_did_details)
                    {
                    $cli = $area_code_default_did_details->cli;

                    }
                    else
                    {
                        $cli = 0;
                    }

                }
            }

            else
                if($caller_id == '2')
                {
                    $sql_area_code_random = "SELECT cli from did where area_code = :area_code and set_exclusive_for_user= :set_exclusive_for_user and is_deleted='0' ORDER BY RAND() limit 0,1";
                    $area_code_random_details =DB::connection('mysql_'.$this->admin)->selectOne($sql_area_code_random, array('area_code' => $area_code, 'set_exclusive_for_user' => '0'));

                    if(!empty($area_code_random_details->cli))
                    {
                        $cli = $area_code_random_details->cli;
                    }

                    else
                    {
                        $sql_random_area_code_default_did = "SELECT cli from did where set_exclusive_for_user=:set_exclusive_for_user and is_deleted='0' ORDER BY RAND() limit 0,1";
                        $random_area_code_default_did_details =DB::connection('mysql_'.$this->admin)->selectOne($sql_random_area_code_default_did, array('set_exclusive_for_user' => '0'));

                        if($random_area_code_default_did_details)
                        {
                        $cli = $random_area_code_default_did_details->cli;

                        }
                        else
                        {
                            $cli=0;
                        }

                    }
                }


                if($cli == 0)
                {
                    throw new RenderableException('CLI not found', [404], 404);
                }
                

              //  echo "<pre>";print_r($agentLoginStatus);die;

               
               // echo $this->extension;die;
        //if(!empty($agentLoginStatus)) {
            if ($number != '' && $this->extension != '') {

                $type = 'c2c';

                if (app()->environment() == "local") return true;

                $callerId = "<$number>";
                $extenStr = $this->extension.'-'.$number."-".$campaignId."-".$id."-".$this->admin."-".$cli."-".$country_code."-".$type; //caller_id,parent_id,cli,country_code
                $originateRequest = "Action: originate\r\n";
                $originateRequest .= "Channel: local/" . $this->extension . "-" . $number . "-" .$this->admin . "@dialler-room-caller\r\n";//ext-number-parent_id
                $originateRequest .= "Timeout: $this->waitTime\r\n";
                $originateRequest .= "Callerid: $callerId\r\n";
                $originateRequest .= "Exten: $extenStr\r\n";
                $originateRequest .= "Context: dialler-room-agent\r\n";
                $originateRequest .= "Priority: 1\r\n";
                $originateRequest .= "Async: yes\r\n";
                $originateRequest .= "Action: Logoff\r\n\r\n";
                // Send originate request
                $param['action'] = 'dial';
                $param['campaign_id'] = $campaignId;
                $param['mobile'] = $number;
                $response = $this->amiCommand($originateRequest, $param);

                //echo "<pre>";print_r($originateRequest);die;
                if($response == "true")
                {
                    /*include_once ("Class/ListClass.php");
                    $listObj = new ListClass();
                    $listObj->preApiCall($this->extension,$this->account_num, $campaignId, $id, $number);
                    echo json_encode(array('status' => 'success'));*/
                    return true;
                }
                return false;
            }
        /*}
        else{
            echo json_encode(array('status' => 'fail', 'msg' => "Error : Your are not logged in from extension123 : $this->extension"));
        }*/
    }


    public function click2CallCRM_OLD($number, $campaignId, $id,$extension, $userData)
    {
        $number = preg_replace('/[^0-9]/', '', $number);
        $area_code =  substr($number, 0, 3);
        $sql = "SELECT extension FROM extension_live WHERE extension = :extension and status = :status";
        $agentLoginStatus =DB::connection('mysql_'.$this->admin)->selectOne($sql, array('extension' => $extension, 'status' => 0));

        //campaign

        $sql_campaign = "SELECT country_code,caller_id,custom_caller_id FROM campaign WHERE id = :id";
        $campaign_details =DB::connection('mysql_'.$this->admin)->selectOne($sql_campaign, array('id' => $campaignId));

        $cli=0;
        $country_code = $campaign_details->country_code;
        $caller_id = $campaign_details->caller_id;

        if($caller_id == 'custom')
        {
            $cli = $campaign_details->custom_caller_id;
        }

        else
            if($caller_id == 'area_code')
            {
                $sql_area_code = "SELECT cli from did where area_code = :area_code and set_exclusive_for_user= :set_exclusive_for_user and is_deleted='0' ORDER BY RAND() limit 0,1";
                $area_code_details =DB::connection('mysql_'.$this->admin)->selectOne($sql_area_code, array('area_code' => $area_code, 'set_exclusive_for_user' => '0'));

                if(!empty($area_code_details->cli))
                {
                    $cli = $area_code_details->cli;
                }
                else
                {
                    $sql_area_code_default_did = "SELECT cli from did where default_did=:default_did and set_exclusive_for_user=:set_exclusive_for_user and is_deleted='0' ORDER BY RAND() limit 0,1";
                    $area_code_default_did_details =DB::connection('mysql_'.$this->admin)->selectOne($sql_area_code_default_did, array('default_did' => 0, 'set_exclusive_for_user' => '0'));

                    $cli = $area_code_default_did_details->cli;
                }
            }

            else
                if($caller_id == 'area_code_random')
                {
                    $sql_area_code_random = "SELECT cli from did where area_code = :area_code and set_exclusive_for_user= :set_exclusive_for_user and is_deleted='0' ORDER BY RAND() limit 0,1";
                    $area_code_random_details =DB::connection('mysql_'.$this->admin)->selectOne($sql_area_code_random, array('area_code' => $area_code, 'set_exclusive_for_user' => '0'));

                    if(!empty($area_code_random_details->cli))
                    {
                        $cli = $area_code_random_details->cli;
                    }

                    else
                    {
                        $sql_random_area_code_default_did = "SELECT cli from did where set_exclusive_for_user=:set_exclusive_for_user and is_deleted='0' ORDER BY RAND() limit 0,1";
                        $random_area_code_default_did_details =DB::connection('mysql_'.$this->admin)->selectOne($sql_random_area_code_default_did, array('set_exclusive_for_user' => '0'));

                        $cli = $random_area_code_default_did_details->cli;
                    }
                }


              //  echo "<pre>";print_r($agentLoginStatus);die;

               
               // echo $this->extension;die;
        //if(!empty($agentLoginStatus)) {
            if ($number != '' && $this->extension != '') {

                $type = 'c2c';

                if (app()->environment() == "local") return true;

                $callerId = "<$number>";
                $extenStr = $this->extension.'-'.$number."-".$campaignId."-".$id."-".$this->admin."-".$cli."-".$country_code."-".$type; //caller_id,parent_id,cli,country_code
                $originateRequest = "Action: originate\r\n";
                $originateRequest .= "Channel: local/" . $this->extension . "-" . $number . "-" .$this->admin . "@dialler-room-caller\r\n";//ext-number-parent_id
                $originateRequest .= "Timeout: $this->waitTime\r\n";
                $originateRequest .= "Callerid: $callerId\r\n";
                $originateRequest .= "Exten: $extenStr\r\n";
                $originateRequest .= "Context: dialler-room-agent\r\n";
                $originateRequest .= "Priority: 1\r\n";
                $originateRequest .= "Async: yes\r\n";
                $originateRequest .= "Action: Logoff\r\n\r\n";
                // Send originate request
                $param['action'] = 'dial';
                $param['campaign_id'] = $campaignId;
                $param['mobile'] = $number;
                $response = $this->amiCommand($originateRequest, $param);

                //echo "<pre>";print_r($originateRequest);die;
                if($response == "true")
                {
                    /*include_once ("Class/ListClass.php");
                    $listObj = new ListClass();
                    $listObj->preApiCall($this->extension,$this->account_num, $campaignId, $id, $number);
                    echo json_encode(array('status' => 'success'));*/
                    return true;
                }
                return false;
            }
        /*}
        else{
            echo json_encode(array('status' => 'fail', 'msg' => "Error : Your are not logged in from extension123 : $this->extension"));
        }*/
    }

    public function click2Call($number, $campaignId, $id, $user_id)
    {
        $number = preg_replace('/[^0-9]/', '', $number);
        $area_code =  substr($number, 0, 3);
        $sql = "SELECT extension FROM extension_live WHERE extension = :extension and status = :status";
        $agentLoginStatus =DB::connection('mysql_'.$this->admin)->selectOne($sql, array('extension' => $this->extension, 'status' => 0));

        //campaign

        $sql_campaign = "SELECT country_code,caller_id,custom_caller_id FROM campaign WHERE id = :id";
        $campaign_details =DB::connection('mysql_'.$this->admin)->selectOne($sql_campaign, array('id' => $campaignId));

        $cli=0;
        $country_code = $campaign_details->country_code;
        $caller_id = $campaign_details->caller_id;

        if($caller_id == 'custom')
        {
            $cli = $campaign_details->custom_caller_id;
        }

        else
            if($caller_id == 'area_code')
            {
                $sql_area_code = "SELECT cli from did where area_code = :area_code and set_exclusive_for_user= :set_exclusive_for_user and is_deleted='0' ORDER BY RAND() limit 0,1";
                $area_code_details =DB::connection('mysql_'.$this->admin)->selectOne($sql_area_code, array('area_code' => $area_code, 'set_exclusive_for_user' => '0'));

                if(!empty($area_code_details->cli))
                {
                    $cli = $area_code_details->cli;
                }
                else
                {
                    $sql_area_code_default_did = "SELECT cli from did where default_did=:default_did and set_exclusive_for_user=:set_exclusive_for_user  and is_deleted='0' ORDER BY RAND() limit 0,1";
                    $area_code_default_did_details =DB::connection('mysql_'.$this->admin)->selectOne($sql_area_code_default_did, array('default_did' => 0, 'set_exclusive_for_user' => '0'));

                    $cli = $area_code_default_did_details->cli;
                }
            }

        else
            if($caller_id == 'area_code_random')
            {
                $sql_area_code_random = "SELECT cli from did where area_code = :area_code and set_exclusive_for_user= :set_exclusive_for_user and is_deleted='0' ORDER BY RAND() limit 0,1";
                $area_code_random_details =DB::connection('mysql_'.$this->admin)->selectOne($sql_area_code_random, array('area_code' => $area_code, 'set_exclusive_for_user' => '0'));

                if(!empty($area_code_random_details->cli))
                {
                    $cli = $area_code_random_details->cli;
                }

                else
                {
                    $sql_random_area_code_default_did = "SELECT cli from did where set_exclusive_for_user=:set_exclusive_for_user and is_deleted='0' ORDER BY RAND() limit 0,1";
                    $random_area_code_default_did_details =DB::connection('mysql_'.$this->admin)->selectOne($sql_random_area_code_default_did, array('set_exclusive_for_user' => '0'));

                    $cli = $random_area_code_default_did_details->cli;
                }
            }

        else
            if($caller_id == 'area_code_3')
            {
                $area_code_3_data =  substr($number, 0, 6);
                $rand = rand(1111,9999);
                $combine = $area_code_3_data.$rand;
                $cli = $combine;
            }

        else
            if($caller_id == 'area_code_4')
            {
                $area_code_3_data =  substr($number, 0, 7);
                $rand = rand(111,999);
                $combine = $area_code_3_data.$rand;
                $cli = $combine;
            }

        else
            if($caller_id == 'area_code_5')
            {
                $area_code_3_data =  substr($number, 0, 8);
                $rand = rand(11,99);
                $combine = $area_code_3_data.$rand;
                $cli = $combine;
            }
            $user=User::where('id',$user_id)->first();
            $from   = $cli;
            $count = 1; // 1 call = 1 credit

//         $creditService = new EasifyCreditService();

//         /* =======================
//         * 🔹 STEP 1: CHECK CREDITS
//         * ======================= */
//         $creditCheck = $creditService->checkCredits(
//             $user_id,
//             $user->easify_user_uuid,
//             'outgoing_call',
//             (string) $from,
//             $count
//         );

//         // 🔴 Easify failure (API error, validation fail etc.)
//         if (
//             empty($creditCheck) ||
//             ($creditCheck['status'] ?? false) === false
//         ) {
//             Log::warning('Easify credit check failed (click2call)', [
//                 'user_id' => $user_id,
//                 'from'    => $from,
//                 'response'=> $creditCheck
//             ]);

//            return [
//     'success' => false,
//     'message' => $creditCheck['message'] ?? 'Credit check failed',
//     'status'    => 400
// ];
//         }

//         // 🟡 Insufficient credits
//         if (($creditCheck['data']['has_sufficient_credits'] ?? false) === false) {

//             Log::warning('Insufficient credits for click2call', [
//                 'user_id' => $user_id,
//                 'from'    => $from
//             ]);

//            return [
//     'success' => false,
//     'message' => 'Insufficient credits to make a call',
//     'status'    => 402
// ];
//         }
        if(!empty($agentLoginStatus)) {
            if ($number != '' && $this->extension != '') {

                $type = 'dialer';

                if (app()->environment() == "local") return true;

                $callerId = "<$number>";
                $extenStr = $this->extension.'-'.$number."-".$campaignId."-".$id."-".$this->admin."-".$cli."-".$country_code."-".$type; //caller_id,parent_id,cli,country_code
                $originateRequest = "Action: originate\r\n";
                $originateRequest .= "Channel: local/" . $this->extension . "-" . $number . "-" .$this->admin . "@dialler-room-caller\r\n";//ext-number-parent_id
                $originateRequest .= "Timeout: $this->waitTime\r\n";
                $originateRequest .= "Callerid: $callerId\r\n";
                $originateRequest .= "Exten: $extenStr\r\n";
                $originateRequest .= "Context: dialler-room-agent\r\n";
                $originateRequest .= "Priority: 1\r\n";
                $originateRequest .= "Async: yes\r\n";
                $originateRequest .= "Action: Logoff\r\n\r\n";
                // Send originate request
                $param['action'] = 'dial';
                $param['campaign_id'] = $campaignId;
                $param['mobile'] = $number;
                $response = $this->amiCommand($originateRequest, $param);
                
                if($response == "true")
                {
                    /*include_once ("Class/ListClass.php");
                    $listObj = new ListClass();
                    $listObj->preApiCall($this->extension,$this->account_num, $campaignId, $id, $number);
                    echo json_encode(array('status' => 'success'));*/
                    return true;
                }
                return false;
            }
        }
        else{
            echo json_encode(array('status' => 'fail', 'msg' => "Error : Your are not logged in from extension : $this->extension"));
        }
    }


    public function confbridge($alt_extension,$extension)
    {
        if (app()->environment() == "local") return true;


        $extensionLiveAlt = DB::connection('mysql_' . $this->admin)->selectOne("SELECT * FROM extension_live WHERE extension = :extension", array('extension' => $alt_extension));

        if(!empty($extensionLiveAlt))
        {
            $extensionLive = (array)$extensionLiveAlt;
            $channel1 =  $extensionLive['channel'];
        }

        /*$extensionLive = DB::connection('mysql_' . $this->admin)->selectOne("SELECT * FROM extension_live WHERE extension = :extension", array('extension' => $extension));
        if(!empty($extensionLive))
        {
            $extensionLive = (array)$extensionLive;
            $channel2 =  $extensionLive['channel'];
        }*/

        $lineDetailsAlt = DB::connection('mysql_' . $this->admin)->selectOne("SELECT * FROM line_detail WHERE extension = :extension", array('extension' => $alt_extension));

        if(!empty($lineDetailsAlt))
        {

            $lineDetails = (array)$lineDetailsAlt;
            $channel3 =  $lineDetails['channel'];

        }



        /*$lineDetail = DB::connection('mysql_' . $this->admin)->selectOne("SELECT * FROM line_detail WHERE extension = :extension", array('extension' => $extension));
        if(!empty($lineDetail))
        {
            $lineDetails = (array)$lineDetail;
            $channel4 =  $lineDetails['channel'];
        }*/


        $localChannelAlt = DB::connection('mysql_' . $this->admin)->selectOne("SELECT * FROM local_channel1 WHERE confno = :extension", array('extension' => $alt_extension));

       // echo "<pre>";print_r($localChannelAlt);die;

        if(!empty($localChannelAlt))
        {

            $localChannel = (array)$localChannelAlt;
            $channel5 =  $localChannel['local_channel'];
        }

//echo $channel5;die;
      

        /*$localChannel = DB::connection('mysql_' . $this->admin)->selectOne("SELECT * FROM local_channel1 WHERE confno = :extension", array('extension' => $extension));

        if(!empty($localChannel))
        {

            $localChannel = (array)$localChannel;
            $channel6 =  $localChannel['channel'];
        }*/

        if(!empty($channel1))
        {
        $request = "Action: Hangup\r\n";
        $request .= "Channel: $channel1\r\n";
        $request .= "Timeout: $this->waitTime\r\n";
        $request .= "Priority: 1\r\n";
        $request .= "Async: yes\r\n";
        $request .= "Action: Logoff\r\n\r\n";
        // Send originate request
        $param['action'] = 'hangup';
        $response = $this->amiCommand($request, $param);

        }



        

      /*  if(!empty($channel2))
        {
        $request = "Action: Hangup\r\n";
        $request .= "Channel: $channel2\r\n";
        $request .= "Timeout: $this->waitTime\r\n";
        $request .= "Priority: 1\r\n";
        $request .= "Async: yes\r\n";
        $request .= "Action: Logoff\r\n\r\n";
        // Send originate request
        $param['action'] = 'hangup';
        $response = $this->amiCommand($request, $param);
    }*/

        

        
        if(!empty($channel3))
        {
            $request = "Action: Hangup\r\n";
            $request .= "Channel: $channel3\r\n";
            $request .= "Timeout: $this->waitTime\r\n";
            $request .= "Priority: 1\r\n";
            $request .= "Async: yes\r\n";
            $request .= "Action: Logoff\r\n\r\n";
            // Send originate request
            $param['action'] = 'hangup';
            $response = $this->amiCommand($request, $param);
        }
       

        
/*if(!empty($channel4))
        {
            $request = "Action: Hangup\r\n";
            $request .= "Channel: $channel4\r\n";
            $request .= "Timeout: $this->waitTime\r\n";
            $request .= "Priority: 1\r\n";
            $request .= "Async: yes\r\n";
            $request .= "Action: Logoff\r\n\r\n";
            // Send originate request
            $param['action'] = 'hangup';
            $response = $this->amiCommand($request, $param);
        }*/

      

        if(!empty($channel5))
        {

            $request = "Action: Hangup\r\n";
            $request .= "Channel: $channel5\r\n";
            $request .= "Timeout: $this->waitTime\r\n";
            $request .= "Priority: 1\r\n";
            $request .= "Async: yes\r\n";
            $request .= "Action: Logoff\r\n\r\n";
            // Send originate request
            $param['action'] = 'hangup';
            $response = $this->amiCommand($request, $param);
        }


        $request = "Action: Command\r\n";
        $request .= "Command: confbridge kick $alt_extension all\r\n";
        $request .= "Action: Logoff\r\n\r\n";
        // Send originate request
        $param['action'] = 'kick';
        $response = $this->amiCommand($request, $param);

      

        
/*if(!empty($channel6))
        {
            $request = "Action: Hangup\r\n";
            $request .= "Channel: $channel6\r\n";
            $request .= "Timeout: $this->waitTime\r\n";
            $request .= "Priority: 1\r\n";
            $request .= "Async: yes\r\n";
            $request .= "Action: Logoff\r\n\r\n";
            // Send originate request
            $param['action'] = 'hangup';
            $response = $this->amiCommand($request, $param);
        }*/


       // return $response;
        return true;

    }


    public function confbridgeCRM($extension)
    {
        if (app()->environment() == "local") return true;


        $extensionLiveAlt = DB::connection('mysql_' . $this->admin)->selectOne("SELECT * FROM extension_live WHERE extension = :extension", array('extension' => $extension));

        if(!empty($extensionLiveAlt))
        {
            $extensionLive = (array)$extensionLiveAlt;
            $channel1 =  $extensionLive['channel'];
        }




        $lineDetailAlt = DB::connection('mysql_' . $this->admin)->selectOne("SELECT * FROM line_detail WHERE extension = :extension", array('extension' => $extension));
        if(!empty($lineDetailAlt))
        {

            $lineDetails = (array)$lineDetailAlt;
            $channel3 =  $lineDetails['channel'];

        }




        $localChannelAlt = DB::connection('mysql_' . $this->admin)->selectOne("SELECT * FROM local_channel1 WHERE confno = :extension", array('extension' => $extension));
       // echo "<pre>";print_r($localChannelAlt);die;

        if(!empty($localChannelAlt))
        {

            $localChannel = (array)$localChannelAlt;
            $channel5 =  $localChannel['local_channel'];
        }




        if(!empty($channel1))
        {
        $request = "Action: Hangup\r\n";
        $request .= "Channel: $channel1\r\n";
        $request .= "Timeout: $this->waitTime\r\n";
        $request .= "Priority: 1\r\n";
        $request .= "Async: yes\r\n";
        $request .= "Action: Logoff\r\n\r\n";
        // Send originate request
        $param['action'] = 'hangup';
        $response = $this->amiCommand($request, $param);

        }


        if(!empty($channel3))
        {
            $request = "Action: Hangup\r\n";
            $request .= "Channel: $channel3\r\n";
            $request .= "Timeout: $this->waitTime\r\n";
            $request .= "Priority: 1\r\n";
            $request .= "Async: yes\r\n";
            $request .= "Action: Logoff\r\n\r\n";
            // Send originate request
            $param['action'] = 'hangup';
            $response = $this->amiCommand($request, $param);
        }
       


      

        if(!empty($channel5))
        {

            $request = "Action: Hangup\r\n";
            $request .= "Channel: $channel5\r\n";
            $request .= "Timeout: $this->waitTime\r\n";
            $request .= "Priority: 1\r\n";
            $request .= "Async: yes\r\n";
            $request .= "Action: Logoff\r\n\r\n";
            // Send originate request
            $param['action'] = 'hangup';
            $response = $this->amiCommand($request, $param);
        }



        


       
        $request = "Action: Command\r\n";
        $request .= "Command: confbridge kick $extension all\r\n";
        $request .= "Action: Logoff\r\n\r\n";
        // Send originate request
        $param['action'] = 'kick';
        $response = $this->amiCommand($request, $param);

        return true;
    }


    public function hangUp()
    {
        if (app()->environment() == "local") return true;
        // line details

        $sql = "SELECT channel FROM line_detail WHERE extension = :extension ORDER by id desc";
        $lineDetail =DB::connection('mysql_'.$this->admin)->selectOne($sql, array('extension' => $this->extension));
        if(!empty($lineDetail))
        {
        $lineDetail = (array)$lineDetail;
        $channel = $lineDetail['channel'];

        }


        $sql = "SELECT local_channel as channel FROM local_channel1 WHERE confno = :extension ";
        $channelDetail =DB::connection('mysql_'.$this->admin)->selectOne($sql, array('extension' => $this->extension));
        if(!empty($channelDetail))
        {
        $channelDetail = (array)$channelDetail;
        $channel_local = $channelDetail['channel'];

        }

        if(empty($lineDetail) && empty($channelDetail))
        {
            return false;
        }

        if(isset($channel))
        {
        $request = "Action: Hangup\r\n";
        $request .= "Channel: $channel\r\n";
        $request .= "Timeout: $this->waitTime\r\n";
        $request .= "Priority: 1\r\n";
        $request .= "Async: yes\r\n";
        $request .= "Action: Logoff\r\n\r\n";
        // Send originate request
        $param['action'] = 'hangup';
        $response = $this->amiCommand($request, $param);

        }

        if(isset($channel_local))
        {
        $request = "Action: Hangup\r\n";
        $request .= "Channel: $channel_local\r\n";
        $request .= "Timeout: $this->waitTime\r\n";
        $request .= "Priority: 1\r\n";
        $request .= "Async: yes\r\n";
        $request .= "Action: Logoff\r\n\r\n";
        // Send originate request
        $param['action'] = 'hangup';
        $response = $this->amiCommand($request, $param);

        }

        return true;



               
        /*//$sql = "SELECT channel FROM line_detail WHERE extension = :extension ORDER by id desc";
        $sql = "SELECT local_channel as channel FROM local_channel1 WHERE confno = :extension ";

        $channelDetail =DB::connection('mysql_'.$this->admin)->selectOne($sql, array('extension' => $this->extension));
        $channelDetail = (array)$channelDetail;
        if(!empty($channelDetail))
        {
            $channel = $channelDetail['channel'];
            if(!empty($channel))
            {
                $request = "Action: Hangup\r\n";
                $request .= "Channel: $channel\r\n";
                $request .= "Timeout: $this->waitTime\r\n";
                $request .= "Priority: 1\r\n";
                $request .= "Async: yes\r\n";
                $request .= "Action: Logoff\r\n\r\n";
                // Send originate request
                $param['action'] = 'hangup';
                $response = $this->amiCommand($request, $param);
                return $response;
            }
        }
        else{
            return true;
        }*/

    }
    public function extensionStatus()
    {
        if($this->extension != '') {
            $sql = "SELECT e.channel, c.dial_mode FROM exten_live as e LEFT JOIN campaign as c ON c.id = e.campaign_id WHERE extension = :extension limit 0,1";
            return $this->database->select($sql, array('extension' => $this->extension));
        }
    }

    public function dialStatus($number)
    {
        if($this->extension != '') {
            $sql = "SELECT channel FROM powerdial_line_details WHERE exten = :extension AND mobile = :mobile AND account_num = :account_num limit 0,1";
            return $this->database->select($sql, array('extension' => $this->extension, 'mobile' => $number, 'account_num' => $this->account_num));
        }
    }

    public function deskPhoneExtensionStatus($number)
    {
        if($this->extension != '') {
            $sql = "SELECT channel FROM line_details WHERE exten = :extension AND mobile = :mobile AND account_num = :account_num limit 0,1";
            return $this->database->select($sql, array('extension' => $this->extension, 'mobile' => $number, 'account_num' => $this->account_num));
        }
    }

    public function monitorOrBarge($type, $dialedNumber, $tableType, $adminNumber){
        if($tableType == 'line'){
            $result = $this->deskPhoneExtensionStatus($dialedNumber);
            $type = $type."-deskphone-portal";
        }
        elseif ($tableType == 'power'){
            $result = $this->dialStatus($dialedNumber);
            $type = $type."-extension-portal";
        }
        if(count($result)  == '0'){
            echo json_encode(array('status' => 'fail', 'msg' => 'This call is ended'));
            return;
        }
        else{
            if($type == 'barge' && $tableType == 'line') {
                $extension = $result[0]['channel'];
            }
            else{
                $extension = $this->extension;
            }
            $request = "Action: Command\r\n";
            $request .= "Command: originate SIP/".$adminNumber." extension ".$extension."@".$type."\r\n\r\n";
            $request .= "Timeout: $this->waitTime\r\n";
            $request .= "Priority: 1\r\n";
            $request .= "Async: yes\r\n";
            $request .= "Action: Logoff\r\n\r\n";
            // Send originate request
            $param['action'] = 'monitorOrBarge';
            $param['mobile'] = $dialedNumber;
            $this->amiCommand($request, $param);
        }

    }
    public function updateExtensionCampaign($campaign, $callBack, $lead){
        $param['campaign_id'] = $campaign;
        if($callBack == 1)
        {
            $param['status'] = 0;
            //populate lead report
            $list = '';
            $listId = $this->database->getData('list_data', array('list_id'), array('id' => $lead));
            if(!empty($listId))
            {
                $list = $listId[0]['list_id'];
            }
            $this->database->setData('lead_report', array('campaign_id' => $campaign, 'list_id' => $list, 'lead_id' => $lead, 'disposition_id' => 0));
        }
        $this->database->updateData("exten_live", $param, array('extension' => $this->extension));
    }
    /*public function predectiveDial($campaignId, $mobile, $leadId)
    {
        $agentLoginStatus = $this->database->getData('exten_live', array('extension'),  array('campaign_id' => $campaignId, 'status' => '0'));
        if(!empty($agentLoginStatus))
        {
            $callerId = $this->getCallerId($campaignId, $mobile);
            $extenStr = $mobile."-".$campaignId."-".$leadId;
            $originateRequest = "Action: originate\r\n";
            $originateRequest .= "Channel: SIP/airespring/#13517131$mobile\r\n";
            $originateRequest .= "Timeout: $this->waitTime\r\n";
            $originateRequest .= "Callerid: $callerId\r\n";
            $originateRequest .= "Exten: $extenStr\r\n";
            $originateRequest .= "Context: dialler-room-customer-predictive\r\n";
            $originateRequest .= "Variable: var1=$extenStr\r\n";
            $originateRequest .= "Priority: 1\r\n";
            $originateRequest .= "Async: yes\r\n";
            $originateRequest .= "Action: Logoff\r\n\r\n";
            // Send originate request
            $param['action'] = 'predective_dial';
            $param['campaign_id'] = $campaignId;
            $param['mobile'] = $mobile;
            $response = $this->amiCommand($originateRequest, $param);
            if($response == "true")
            {
                return true;
            }
        }
        else
        {
            $this->database->deleteData('lead_report' , array('campaign_id' => $campaignId, 'lead_id' => $leadId));
            return false;
        }


    }*/

    public function predictiveDial($mobile,$campaignId, $leadId, $clientId)
    {
        $mobile = preg_replace('/[^0-9]/', '', $mobile);
        $numberAreacode = substr(trim($mobile), 0, 3);

        $sqlCli = "SELECT caller_id,custom_caller_id,amd FROM campaign WHERE id = :id";
        $sqlCliStatus =DB::connection('mysql_'.$this->admin)->selectOne($sqlCli, array('id' => $campaignId));

        if($sqlCliStatus->caller_id == 'custom')
        {
            $cli = $sqlCliStatus->custom_caller_id;
        }
        else
        if($sqlCliStatus->caller_id == 'area_code')
        {
            $sqlCliDid = "SELECT cli from did where area_code =:area_code and set_exclusive_for_user='0' and is_deleted='0' ORDER BY RAND() limit 0,1";
            $sqlCliDidStatus =DB::connection('mysql_'.$this->admin)->selectOne($sqlCliDid, array('area_code' => $numberAreacode));
            
            if(!empty($sqlCliDidStatus))
            {
                $cli = $sqlCliDidStatus->cli;
            }
            else
            {
                $sqlCliDid = "SELECT cli from did where default_did = :default_did and set_exclusive_for_user='0' and is_deleted='0' ORDER BY RAND() limit 0,1";
                $sqlCliDidStatus =DB::connection('mysql_'.$this->admin)->selectOne($sqlCliDid, array('default_did' => 0));
                if(!empty($sqlCliDidStatus))
                {
                    $cli = $sqlCliDidStatus->cli;
                }
            }
        }
        else
        if($sqlCliStatus->caller_id == 'area_code_random')
        {
            $sqlCliDid = "SELECT cli from did where area_code =:area_code and set_exclusive_for_user='0' and is_deleted='0' ORDER BY RAND() limit 0,1";
            $sqlCliDidStatus =DB::connection('mysql_'.$this->admin)->selectOne($sqlCliDid, array('area_code' => $numberAreacode));
            
            if(!empty($sqlCliDidStatus))
            {
                $cli = $sqlCliDidStatus->cli;
            }
            else
            {
                $sqlCliDid = "SELECT cli from did where  set_exclusive_for_user='0' and is_deleted='0' ORDER BY RAND() limit 0,1";
                $sqlCliDidStatus =DB::connection('mysql_'.$this->admin)->selectOne($sqlCliDid, array('default_did' => 1));
                if(!empty($sqlCliDidStatus))
                {
                    $cli = $sqlCliDidStatus->cli;
                }
            }
        }

        else
            if($caller_id == 'area_code_3')
            {
                $area_code_3_data =  substr($number, 0, 6);
                $rand = rand(1111,9999);
                $combine = $area_code_3_data.$rand;
                $cli = $combine;
            }

        else
            if($caller_id == 'area_code_4')
            {
                $area_code_3_data =  substr($number, 0, 7);
                $rand = rand(111,999);
                $combine = $area_code_3_data.$rand;
                $cli = $combine;
            }

        else
            if($caller_id == 'area_code_5')
            {
                $area_code_3_data =  substr($number, 0, 8);
                $rand = rand(11,99);
                $combine = $area_code_3_data.$rand;
                $cli = $combine;
            }

        if($sqlCliStatus->amd == 1)
        {
            $amd_on = $sqlCliStatus->amd;
        }
        else
        {
            $amd_on='';
        }

        $sql = "SELECT extension FROM extension_live WHERE extension = :extension and status = :status";
        $agentLoginStatus =DB::connection('mysql_'.$this->admin)->selectOne($sql, array('extension' => $this->extension, 'status' => 0));
        if(!empty($agentLoginStatus))
        {
            if($mobile != '' && $this->extension != '')
            {
                if (app()->environment() == "local") return true;

                //$callerId = "19499914823";
                $callerId = $cli;

                $extenStr = $mobile."-".$campaignId."-".$leadId."-".$clientId;
                $originateRequest = "Action: originate\r\n";
                //$originateRequest .= "Channel: SIP/Airespring2/#13519621$mobile\r\n"; //airespring/#13517131  // for v g  Channel: SIP/Airespring1/1$mobile\r\n

                //$originateRequest .= "Channel: SIP/pilivo/1$mobile\r\n"; //airespring/#13517131  // for v g  Channel: SIP/Airespring1/1$mobile\r\n

                $originateRequest .= "Channel: SIP/pilivo/+1$mobile\r\n"; 



                $originateRequest .= "Timeout: $this->waitTime\r\n";
                $originateRequest .= "Callerid: $callerId\r\n";
                $originateRequest .= "Exten: $extenStr\r\n";
                if($amd_on == 1)
                {
                    $originateRequest .= "Context: dialler-room-customer-predictive-amd\r\n";
                }
                else
                {
                    $originateRequest .= "Context: dialler-room-customer-predictive\r\n";
                }
                $originateRequest .= "Variable: var1=$extenStr\r\n";
                $originateRequest .= "Priority: 1\r\n";
                $originateRequest .= "Async: yes\r\n";
                $originateRequest .= "Action: Logoff\r\n\r\n";
                
                // Send originate request
                $param['action'] = 'predictive_dial';
                $param['campaign_id'] = $campaignId;
                $param['mobile'] = $mobile;
                $param['lead_id'] = $leadId;
                $param['cli'] = $cli;
                $param['amd_status'] = $sqlCliStatus->amd;
                $param['area_code'] = $numberAreacode;



                $response = $this->amiCommand($originateRequest, $param);
                if($response == "true")
                {
                    Log::error("Dialer.predictiveDial.success", [
                        "message" => $response,
                        "originateRequest" => $originateRequest,
                        "param" =>$param
                    ]);

                    return true;
                }
                else
                {
                    Log::error("Dialer.predictiveDial.error", [
                    "message" => $response,
                    "originateRequest" => $originateRequest,
                    "param" =>$param
                ]);
                }
            }
            /*else
            {
                // $this->database->deleteData('lead_report' , array('campaign_id' => $campaignId, 'lead_id' => $leadId));
                return false;
            }*/
        }

        else
        {
            echo json_encode(array('status' => 'fail', 'msg' => "Error : Your are not logged in from extension for predictive dial : $this->extension"));
        }
    }

    public function outboundAIDial($request) //mobile,$campaignId, $leadId, $clientId,$redirect_to,$file_name
    {
        //echo "<pre>";print_r($request);die;
        $mobile = preg_replace('/[^0-9]/', '', $request['number']);
        $numberAreacode = substr(trim($mobile), 0, 3);
        $area_code = $numberAreacode;

        $sqlCli = "SELECT caller_id,custom_caller_id,amd FROM campaign WHERE id = :id";
        $sqlCliStatus =DB::connection('mysql_'.$this->admin)->selectOne($sqlCli, array('id' => $request['campaign_id']));

        if($sqlCliStatus->caller_id == 'custom')
        {
            $cli = $sqlCliStatus->custom_caller_id;
        }
        else
        if($sqlCliStatus->caller_id == 'area_code')
        {
            $sqlCliDid = "SELECT cli from did where area_code =:area_code and set_exclusive_for_user='0' and is_deleted='0' ORDER BY RAND() limit 0,1";
            $sqlCliDidStatus =DB::connection('mysql_'.$this->admin)->selectOne($sqlCliDid, array('area_code' => $numberAreacode));

            if(!empty($sqlCliDidStatus))
            {
                $cli = $sqlCliDidStatus->cli;
            }
            else
            {
                $areacode = AreaCodeList::where('areacode',$numberAreacode)->get()->first();
                //echo "<pre>";print_r($areacode);die;
                if(!empty($areacode))
                {
                    $statecode = $areacode->state_code;
                    $all_areacode = AreaCodeList::where('state_code',$statecode)->get()->all();
                    //echo "<pre>";print_r($all_areacode);die;

                    foreach($all_areacode as $state)
                    {
                        $code_area[] = $state->areacode;
                    }

                    //echo "<pre>";print_r($code_area);die;

                    $array_to_remove = array($area_code);
                    $final_array = array_diff($code_area,$array_to_remove);
                    $area_codes = implode(',',$final_array);

                    //echo "<pre>";print_r($area_codes);die;

                    $sql_area_code_new = "SELECT cli from did where area_code IN ($area_codes) and set_exclusive_for_user= :set_exclusive_for_user and is_deleted='0' ORDER BY RAND() limit 0,1";

                    $area_code_details =DB::connection('mysql_'.$this->admin)->selectOne($sql_area_code_new, array( 'set_exclusive_for_user' => '0'));
                    //echo "<pre>";print_r($area_code_details);die;

                    if(!empty($area_code_details))
                    {
                        $cli = $area_code_details->cli;
                    }

                    else
                    {
                        $sql_area_code_default_did = "SELECT cli from did where  set_exclusive_for_user=:set_exclusive_for_user  and is_deleted='0' ORDER BY RAND() limit 0,1";
                        $area_code_default_did_details =DB::connection('mysql_'.$this->admin)->selectOne($sql_area_code_default_did, array('set_exclusive_for_user' => '0'));
                        $cli = $area_code_default_did_details->cli;
                    }
                }

                else
                {
                    $sql_area_code_default_did = "SELECT cli from did where  set_exclusive_for_user=:set_exclusive_for_user  and is_deleted='0' ORDER BY RAND() limit 0,1";
                    $area_code_default_did_details =DB::connection('mysql_'.$this->admin)->selectOne($sql_area_code_default_did, array('set_exclusive_for_user' => '0'));
                    $cli = $area_code_default_did_details->cli;
                }
                /*else
                {
                    $sql_area_code_default_did = "SELECT cli from did where  set_exclusive_for_user=:set_exclusive_for_user  and is_deleted='0' ORDER BY RAND() limit 0,1";
                    $area_code_default_did_details =DB::connection('mysql_'.$this->admin)->selectOne($sql_area_code_default_did, array('set_exclusive_for_user' => '0'));
                    $cli = $area_code_default_did_details->cli;
                }*/
            }
        }
        /*if($sqlCliStatus->caller_id == 'area_code')
        {
            $sqlCliDid = "SELECT cli from did where area_code =:area_code and set_exclusive_for_user='0' and is_deleted='0' ORDER BY RAND() limit 0,1";
            $sqlCliDidStatus =DB::connection('mysql_'.$this->admin)->selectOne($sqlCliDid, array('area_code' => $numberAreacode));
            
            if(!empty($sqlCliDidStatus))
            {
                $cli = $sqlCliDidStatus->cli;
            }
            else
            {
                $sqlCliDid = "SELECT cli from did where default_did = :default_did and set_exclusive_for_user='0' and is_deleted='0' ORDER BY RAND() limit 0,1";
                $sqlCliDidStatus =DB::connection('mysql_'.$this->admin)->selectOne($sqlCliDid, array('default_did' => 0));
                if(!empty($sqlCliDidStatus))
                {
                    $cli = $sqlCliDidStatus->cli;
                }
            }
        }*/
        else
        if($sqlCliStatus->caller_id == 'area_code_random')
        {
            $sqlCliDid = "SELECT cli from did where area_code =:area_code and set_exclusive_for_user='0' and is_deleted='0' ORDER BY RAND() limit 0,1";
            $sqlCliDidStatus =DB::connection('mysql_'.$this->admin)->selectOne($sqlCliDid, array('area_code' => $numberAreacode));
            
            if(!empty($sqlCliDidStatus))
            {
                $cli = $sqlCliDidStatus->cli;
            }
            else
            {
                $sqlCliDid = "SELECT cli from did where  set_exclusive_for_user='0' and is_deleted='0' ORDER BY RAND() limit 0,1";
                $sqlCliDidStatus =DB::connection('mysql_'.$this->admin)->selectOne($sqlCliDid, array('default_did' => 1));
                if(!empty($sqlCliDidStatus))
                {
                    $cli = $sqlCliDidStatus->cli;
                }
            }

        }

        //tech prefix

        $client_details = Client::findOrFail($this->admin);
        if(!empty($client_details))
        {
            if(!empty($client_details->tech_prefix))
            {
                $tech_prefix = $client_details->tech_prefix;
            }
            else
            {
                $tech_prefix = '';
            }
        }

        //closed tech prefix



        if($sqlCliStatus->amd == 1)
        {
            $amd_on = $sqlCliStatus->amd;
        }
        else
        {
            $amd_on='';
        }

        $sql = "SELECT extension FROM extension_live WHERE extension = :extension and status = :status";
       // $agentLoginStatus =DB::connection('mysql_'.$this->admin)->selectOne($sql, array('extension' => $this->extension, 'status' => 0));
       
        $agentLoginStatus = 1;//DB::connection('mysql_'.$this->admin)->selectOne($sql, array('extension' => $this->extension, 'status' => 0));

        if($agentLoginStatus == 1)
        {
            if($mobile != '' && $this->extension != '')
            {
                if (app()->environment() == "local") return true;

                //$callerId = "19499914823";
                $callerId   = $cli;
                $destType   = $request['redirect_to'];
                $destId     = $request['redirect_to_dropdown'];
                $dest       = $request['file_name'];
                $leadId     = $request['lead_id'];
                $campaignId = $request['campaign_id'];
                $clientId   = $request['clientId'];
                $amd_drop_action = $request['amd_drop_action'];
                $amd_drop_message_output = $request['amd_drop_message_output'];


                if($amd_on == 1)
                {
                    $extenStr = $mobile."-".$campaignId."-".$leadId."-".$clientId."-".$destType."-".$destId."-".$dest."-".$amd_drop_action."-".$callerId."-".$tech_prefix;
                }
                else
                {
                    $extenStr = $mobile."-".$campaignId."-".$leadId."-".$clientId."-".$destType."-".$destId."-".$callerId."-".$tech_prefix;
                }

                //echo $extenStr;die;
                $originateRequest = "Action: originate\r\n";
              //  $originateRequest .= "Channel: SIP/telnyx/#13519621$mobile\r\n"; //airespring/#13517131  // for v g  Channel: SIP/Airespring1/1$mobile\r\n

                //$originateRequest .= "Channel: SIP/telnyx/$mobile\r\n"; 
                //$originateRequest .= "Channel: SIP/telnyx/$tech_prefix$mobile\r\n"; 
                 //$originateRequest .= "Channel: SIP/telnyx/$mobile\r\n"; 
                $originateRequest .= "Channel: SIP/pilivo/+1$mobile\r\n"; 


                $originateRequest .= "Timeout: $this->waitTime\r\n";
                $originateRequest .= "Callerid: $callerId\r\n";
                $originateRequest .= "Exten: $extenStr\r\n";
                if($amd_on == 1)
                {
                    $originateRequest .= "Context: dialler-room-outbund-ai-amd\r\n";
                }
                else
                {
                    $originateRequest .= "Context: dialler-room-outbund-ai\r\n";
                }
                $originateRequest .= "Variable: var1=$extenStr\r\n";
                $originateRequest .= "Priority: 1\r\n";
                $originateRequest .= "Async: yes\r\n";
                $originateRequest .= "Action: Logoff\r\n\r\n";
                
                // Send originate request
                $param['action'] = 'outbound_ai';
                $param['campaign_id'] = $campaignId;
                $param['mobile'] = $mobile;
                $param['lead_id'] = $leadId;
                $param['cli'] = $cli;
                $param['amd_status'] = $sqlCliStatus->amd;
                $param['area_code'] = $numberAreacode;



                $response = $this->amiCommand($originateRequest, $param);
                if($response == "true")
                {
                    Log::error("Dialer.outboundAIDial.success", [
                        "message" => $response,
                        "originateRequest" => $originateRequest,
                        "param" =>$param
                    ]);

                    return true;
                }
                else
                {
                    Log::error("Dialer.outboundAIDial.error", [
                    "message" => $response,
                    "originateRequest" => $originateRequest,
                    "param" =>$param
                ]);
                }
            }
            /*else
            {
                // $this->database->deleteData('lead_report' , array('campaign_id' => $campaignId, 'lead_id' => $leadId));
                return false;
            }*/
        }

        else
        {
            echo json_encode(array('status' => 'fail', 'msg' => "Error : Your are not logged in from extension for Outbound AI dial : $this->extension"));
        }
    }

    public function campaignDetail($campaign){
        return $this->database->getData('campaign', array('*'), array('id' => $campaign, 'username' => $this->account_num));
    }

    /**
     * @return string
     */
    public function getCallerId($campaignId, $mobile = '')
    {
        $campaignDetail = $this->campaignDetail($campaignId);
        if(!empty($campaignDetail))
        {
            $campaignDetail = $campaignDetail[0];
            if($campaignDetail['caller_id'] == "custom")
            {
                if(!empty($campaignDetail['custom_caller_id']))
                {
                    return $campaignDetail['custom_caller_id'];
                }

            }
            elseif ($campaignDetail['caller_id'] == "area_code" && $mobile != '')
            {
                $numberAreaCode = substr($mobile, 0, 3);
                $areaCodeCallerId = $this->database->getData('voip_did_table', array('caller_id'), array('account_num' => $this->account_num, 'area_code' => $numberAreaCode));
                if(!empty($areaCodeCallerId))
                {
                    return $areaCodeCallerId[0]['caller_id'];
                }
            }
            return $this->getDefaultCallerId();
        }
    }

    /**
     * @return mixed
     */
    public function getDefaultCallerId()
    {
        $result = $this->database->getData('voip_did_default', array('default_did'), array('account_num' => $this->account_num));
        return (!empty($result)) ? $result[0]['default_did'] : '';
    }

    /**
     * @return mixed
     */
    public function getExtensionDetail()
    {
        return $this->database->getData('exten_live', array('*'), array('extension' => $this->extension), array(),'0,1');
    }

    public function dtmf($number)
    {
        $sql = "SELECT id, channel, number, campaign_id FROM cdr WHERE extension = :extension ORDER BY id desc";
        $callDetail =DB::connection('mysql_'.$this->admin)->selectOne($sql, array('extension' => $this->extension));
        if(!empty($callDetail) && $number != '' && $this->extension != '') {

            $originateRequest = "Action: originate\r\n";
            $originateRequest .= "Channel: local/s@initiate\r\n";
            $originateRequest .= "Timeout: $this->waitTime\r\n";
            $originateRequest .= "Exten: ".$callDetail->channel."-".$number."\r\n";
            $originateRequest .= "Context: send-dtmf\r\n";
            $originateRequest .= "Priority: 1\r\n";
            $originateRequest .= "Async: yes\r\n";
            $originateRequest .= "Action: Logoff\r\n\r\n";
            // Send originate request
            $param['action'] = 'dtmf';
            $param['campaign_id'] = $callDetail->campaign_id;
            $param['mobile'] = $callDetail->number;
            $this->amiCommand($originateRequest, $param);
            return array('status' => "true");
        }
        else{
            return array('status' => 'fail', 'message' => "Error : Your are not connected to any call.");
        }
    }
    public function voicemailDrop()
    {

        $sql = "SELECT id, channel, number, campaign_id, lead_id FROM cdr WHERE extension = :extension ORDER BY id desc";
        $callDetail =DB::connection('mysql_'.$this->admin)->selectOne($sql, array('extension' => $this->extension));

        if(!empty($callDetail) && $this->extension != '')
        {
            $callerId = "5500"."$this->extension"."$callDetail->campaign_id";
            $originateRequest = "Action: Redirect\r\n";
            $originateRequest .= "Channel: $callDetail->channel\r\n";
            $originateRequest .= "Timeout: $this->waitTime\r\n";
            $originateRequest .= "Callerid: $callerId\r\n";
            $originateRequest .= "Exten: $this->extension-".$callDetail->campaign_id."-".$callDetail->lead_id."\r\n";
            $originateRequest .= "Context: route-voicemail\r\n";
            $originateRequest .= "Priority: 1\r\n";
            $originateRequest .= "Async: yes\r\n";
            $originateRequest .= "Action: Logoff\r\n\r\n";
            // Send originate request
            $param['action'] = 'voice mail drop';
            $param['campaign_id'] = $callDetail->campaign_id;
            $param['mobile'] = $callDetail->number;
            $this->amiCommand($originateRequest, $param);
            return array('status' => "true");
        }
        else{
            return array('status' => 'fail', 'message' => "Error : Your are not connected to any call.");
        }
    }

    public function cdrLog($param)
    {
        //$data['extension'] = $this->extension;
        $data['route'] = 'OUT';
        $data['type'] = !empty($param['action']) ? $param['action'] : '';
        //$data['admin'] = $this->admin; //parentid
        $data['campaign_id'] = !empty($param['campaign_id']) ? $param['campaign_id'] : '';
        $data['mobile'] = !empty($param['mobile']) ? $param['mobile'] : '';
        //$data['action'] = !empty($param['action']) ? $param['action'] : '';
        //$data['request'] = !empty($param['request']) ? $param['request'] : '';
        //$data['response'] = !empty($param['response']) ? $param['response'] : '';
        $data['lead_id'] = !empty($param['lead_id']) ? $param['lead_id'] : '';
        $data['area_code'] = !empty($param['area_code']) ? $param['area_code'] : '';
        $data['cli'] = !empty($param['cli']) ? $param['cli'] : '';
        $data['amd_status'] = !empty($param['amd_status']) ? $param['amd_status'] : '0';
        





        $query = "INSERT IGNORE INTO cdr ( `route`, `type`, `campaign_id`, `number`,`lead_id`,`area_code`,`cli`,`amd_status`) VALUE (:route, :type, :campaign_id, :mobile,:lead_id,:area_code,:cli,:amd_status)";
        Log::error("Dialer.predictiveDialCdr.success", [
                        "message" => $query,
                        "param" =>$param
                    ]);
        DB::connection('mysql_'.$this->admin)->insert($query, $data);
    }


    public function amiLog($param)
    {
        $data['extension'] = $this->extension;
        $data['admin'] = $this->admin;
        $data['campaign_id'] = !empty($param['campaign_id']) ? $param['campaign_id'] : '';
        $data['mobile'] = !empty($param['mobile']) ? $param['mobile'] : '';
        $data['action'] = !empty($param['action']) ? $param['action'] : '';
        $data['request'] = !empty($param['request']) ? $param['request'] : '';
        $data['response'] = !empty($param['response']) ? $param['response'] : '';
        $query = "INSERT IGNORE INTO ami_log (extension, admin, campaign_id, mobile, action, request, response) VALUE (:extension, :admin, :campaign_id, :mobile, :action, :request, :response)";
        DB::connection('mysql_'.$this->admin)->insert($query, $data);
    }

    public function acceptInbondCall($val)
    {
        if($val == 1)
        {
            $sql = "INSERT INTO accept_inbond_call (extension, status) VALUES (:extension, :status) ON DUPLICATE KEY UPDATE status = :status";
            $this->database->insert($sql, array('extension' => $this->extension, 'status' => $val));
        }
        elseif ($val == 0)
        {
            $this->database->deleteData('accept_inbond_call', array('extension' => $this->extension));
        }
        return true;
    }
    public function getLeadIdCampaignId()
    {

        $getLead = $this->database->getData('exten_live', array('campaign_id', 'lead_id'), array('extension' => $this->extension));
        if(!empty($getLead))
        {
            $lead = (isset($getLead[0]['lead_id'])) ? $getLead[0]['lead_id'] : '';
            $campaign = (isset($getLead[0]['campaign_id'])) ? $getLead[0]['campaign_id'] : '';
        }
        if(empty($lead) || empty($campaign))
        {
            $getDetail = $this->database->getData('powerdial_line_details', array('mobile', 'campaign_id', 'lead_id'), array('exten' => $this->extension));
            $lead = (isset($getDetail[0]['lead_id'])) ? $getDetail[0]['lead_id'] : '';
            $campaign = (isset($getDetail[0]['campaign_id'])) ? $getDetail[0]['campaign_id'] : '';
        }
        return array('lead' => $lead, 'campaign' => $campaign);
    }
    public function getphoneNumber($lead)
    {
        $number = '';
        $getLeadDetail = $this->database->getData('list_data', array('*'), array('id' => $lead));
        if(!empty($getLeadDetail))
        {
            $listId = (isset($getLeadDetail[0]['list_id'])) ? $getLeadDetail[0]['list_id'] : '';
            if(!empty($listId))
            {
                $getDetail = $this->database->getData('list_header', array('column_name'), array('list_id' => $listId, 'is_dialing' => 1));
                $column = (isset($getDetail[0]['column_name'])) ? $getDetail[0]['column_name'] : '';
                if(!empty($column))
                {
                    $number =  (isset($getLeadDetail[0][$column])) ? $getLeadDetail[0][$column] : '';
                }
            }
        }
        return $number;
    }
    public function getTransferExtension($accessGroup, $lead, $campaign, $number)
    {
        if(!empty($accessGroup))
        {
            //Critical feature to make sure we have proper data
            if(empty($lead) || empty($campaign))
            {
                $getLeadIdCampaignId = $this->getLeadIdCampaignId();
                $lead = $getLeadIdCampaignId['lead'];
                $campaign = $getLeadIdCampaignId['campaign'];
            }
            if(empty($number))
            {
                $number = $this->getphoneNumber($lead);
            }
            //Add Data to reporting
            $checkReport = $this->database->getData('transfer_report', array('id'), array('admin' => $this->account_num, 'campaign_id' => $campaign, 'lead_id' => $lead));
            if(!empty($checkReport) && isset($checkReport[0]['id'])){
                $reportId = $checkReport[0]['id'];
                $sql = "UPDATE transfer_report set extension = :extension , number = :number , datetime = now(), forward_extension = null WHERE id = :id";
                $this->database->update($sql, array('extension' => $this->extension, 'number' => $number, 'id' => $reportId));
            }
            else
            {
                $this->database->setData('transfer_report', array(
                        'admin' => $this->account_num,
                        'extension' => $this->extension,
                        'campaign_id' => $campaign,
                        'lead_id' => $lead,
                        'number' => $number
                    )
                );
                $reportId =  $this->database->lastInsertId();
            }

            //Error Message:
            $response = array('status' => "false");
            //get List of extension for access group
            $extensionList = $this->database->getData('extension_group_map', array('extension'), array('account_num' =>$this->account_num, 'group_id' => $accessGroup));
            $allExtension = array();
            if(!empty($extensionList))
            {
                foreach ($extensionList as $itemKey=>$itemValue)
                {
                    array_push($allExtension, $itemValue['extension']);
                }
            }
            else
            {
                return $response;
            }

            //Check Extension Available to take call and fetch details
            $sql = "SELECT extension FROM accept_inbond_call WHERE status = :status AND extension in ('".implode("' , '", $allExtension)."')";
            $checkExtension = $this->database->select($sql, array('status' => '1'));
            $availableExtension = array();
            if(!empty($checkExtension))
            {
                foreach ($checkExtension as $itemKey=>$itemValue)
                {
                    array_push($availableExtension, $itemValue['extension']);
                }
            }
            else
            {
                return $response;
            }
            if(!empty($availableExtension)){
                $extension = array();
                foreach ($availableExtension as $key=>$currentExtension)
                {
                    $checkExtensionTransferStatus = $this->checkExtensionTransferStatus($currentExtension);
                    if($checkExtensionTransferStatus == "true")
                    {
                        $todayInbondCall = $this->todayInbondCall($currentExtension);
                        $count = (!empty($todayInbondCall) && isset($todayInbondCall[0]['cnt'])) ? $todayInbondCall[0]['cnt']: 0;
                        $extension[$currentExtension] = $count;
                    }
                }
                //remove current extension if exist
                if(isset($extension[$this->extension]))
                {
                    unset($extension[$this->extension]);
                }

                if(!empty($extension))
                {
                    //order extension asecnding to number of call recived
                    asort($extension);
                    $callAmi = true;
                    foreach ($extension as $ext=>$rec)
                    {
                        if($callAmi == true){
                            $callExtension = $this->extensionCallTransfer($ext, $lead, $campaign, $number, $reportId);
                            if($callExtension == "true")
                            {
                                $callAmi = false;
                                $this->database->updateData('transfer_report', array('status' => '0', 'forward_extension' => $ext), array('id' => $reportId));
                            }
                            /*else
                            {
                                //$this->transferLog($ext, $campaign, $lead, $number, "Command return response unsuccessful");
                            }*/
                        }
                        $this->database->setData('transfer_temp', array('unique_id' => $reportId, 'extension' => $ext));
                    }
                    $j =0;
                    while ($j < 100)
                    {
                        $checkTransferTemp =$this->database->getData('transfer_temp', array('extension'), array('unique_id' => $reportId));
                        if(!empty($checkTransferTemp))
                        {
                            $extensionTransfer = $this->checkTransferStatus($lead, $campaign);
                            if(!empty($extensionTransfer) && isset($extensionTransfer[0]['extension']))
                            {
                                $extensionDetail = $this->database->getData('extension_details', array('first_name', 'last_name', 'extension'), array('account_num' => $this->account_num, 'extension' => $extensionTransfer[0]['extension']));
                                return array('status' => "true", 'data' => $extensionDetail);
                                break;
                            }
                            $j++;
                            sleep(2);
                        }
                        else
                        {
                            return $response;
                            break;
                        }
                    }
                    return $response;
                }
                else
                {
                    return $response;
                }
            }
            else
            {
                return $response;
            }

        }
    }
    /*public function getTransferExtension($accessGroup, $lead, $campaign, $number)
    {
        //echo $accessGroup."==".$lead."==".$campaign."==".$number; exit;
        if(!empty($accessGroup))
        {
            //Critical feature to make sure we have proper data
            if(empty($lead) || empty($campaign))
            {
                $getLeadIdCampaignId = $this->getLeadIdCampaignId();
                $lead = $getLeadIdCampaignId['lead'];
                $campaign = $getLeadIdCampaignId['campaign'];
            }
            if(empty($number))
            {
                $number = $this->getphoneNumber($lead);
            }
            //Add Data to reporting
            $checkReport = $this->database->getData('transfer_report', array('id'), array('admin' => $this->account_num, 'campaign_id' => $campaign, 'lead_id' => $lead));
            if(!empty($checkReport) && isset($checkReport[0]['id'])){
                $reportId = $checkReport[0]['id'];
                $sql = "UPDATE transfer_report set extension = :extension , number = :number , datetime = now(), forward_extension = null WHERE id = :id";
                $this->database->update($sql, array('extension' => $this->extension, 'number' => $number, 'id' => $reportId));
            }
            else
            {
                $this->database->setData('transfer_report', array(
                        'admin' => $this->account_num,
                        'extension' => $this->extension,
                        'campaign_id' => $campaign,
                        'lead_id' => $lead,
                        'number' => $number
                    )
                );
                $reportId =  $this->database->lastInsertId();
            }

            //Error Message:
            $response = array('status' => "false");
            //get List of extension for access group
            $extensionList = $this->database->getData('extension_group_map', array('extension'), array('account_num' =>$this->account_num, 'group_id' => $accessGroup));
            $allExtension = array();
            if(!empty($extensionList))
            {
                foreach ($extensionList as $itemKey=>$itemValue)
                {
                    array_push($allExtension, $itemValue['extension']);
                }
            }
            else
            {
                return $response;
            }

            //Check Extension Available to take call and fetch details
            $sql = "SELECT extension FROM accept_inbond_call WHERE status = :status AND extension in ('".implode("' , '", $allExtension)."')";
            $checkExtension = $this->database->select($sql, array('status' => '1'));
            $availableExtension = array();
            if(!empty($checkExtension))
            {
                foreach ($checkExtension as $itemKey=>$itemValue)
                {
                    array_push($availableExtension, $itemValue['extension']);
                }
            }
            else
            {
                return $response;
            }
            if(!empty($availableExtension)){
                $extension = array();
                foreach ($availableExtension as $key=>$currentExtension)
                {
                    $checkExtensionTransferStatus = $this->checkExtensionTransferStatus($currentExtension);
                    if($checkExtensionTransferStatus == "true")
                    {
                        $todayInbondCall = $this->todayInbondCall($currentExtension);
                        $count = (!empty($todayInbondCall) && isset($todayInbondCall[0]['cnt'])) ? $todayInbondCall[0]['cnt']: 0;
                        $extension[$currentExtension] = $count;
                    }
                }
                //remove current extension if exist
                if(isset($extension[$this->extension]))
                {
                    unset($extension[$this->extension]);
                }

                if(!empty($extension))
                {
                    //order extension asecnding to number of call recived
                    asort($extension);
                    foreach ($extension as $ext=>$rec)
                    {
                        $callExtension = $this->extensionCallTransfer($ext, $lead, $campaign, $number);
                        if($callExtension == "true")
                        {
                            $this->database->updateData('transfer_report', array('status' => '0', 'forward_extension' => $ext), array('id' => $reportId));
                            $logId = $this->transferLog($ext, $campaign, $lead, $number, "Command return response successful and phone is ringing");
                            $j =0;
                            while ($j < 10)
                            {
                                $chkFirst = $this->checkTransferStatus($ext, $lead, $campaign);
                                if(!empty($chkFirst))
                                {
                                    $this->transferLog($ext, $campaign, $lead, $number, "Agent picked up the call", $logId);
                                    $callExtensionPatch = $this->callTransferPatch($ext, $lead, $campaign);
                                    if ($callExtensionPatch == "true") {
                                        $i = 0;
                                        while ($i < 10) {
                                            $chk = $this->checkTransferStatus($ext, $lead, $campaign);
                                            if (!empty($chk)) {
                                                $extensionDetail = $this->database->getData('extension_details', array('first_name', 'last_name', 'extension'), array('account_num' => $this->account_num, 'extension' => $ext));
                                                $this->database->updateData('transfer_report', array('status' => '1', 'forward_extension' => $ext), array('id' => $reportId));
                                                $this->transferLog($ext, $campaign, $lead, $number, "Call transfer successful", $logId);
                                                return array('status' => "true", 'data' => $extensionDetail);
                                                break;
                                            }
                                            $i++;
                                            sleep(3);
                                        }
                                    }
                                    break;
                                }
                                $j++;
                                sleep(2);
                            }
                        }
                        else
                        {
                            $this->transferLog($ext, $campaign, $lead, $number, "Command return response unsuccessful");
                        }
                    }
                    return $response;
                }
                else
                {
                    return $response;
                }
            }
            else
            {
                return $response;
            }

        }
    }*/
    public function checkExtensionTransferStatus($extension)
    {
        //check extension is in call or not:
        $sql = "select exten from line_details WHERE exten = :exten
                UNION
                select exten from powerdial_line_details WHERE exten = :exten";
        $checkOnCall = $this->database->select($sql, array('exten' => $extension));
        if(empty($checkOnCall))
        {
            //check extension status on exten-live table
            $checkExtenLive = $this->database->select("SELECT extension FROM exten_live WHERE extension = :extension AND status = :status", array('extension' => $extension,'status' => 1));
            if(empty($checkExtenLive))
            {
                return "true";
            }
            else
            {
                return "false";
            }
        }
        else{
            return "false";
        }
    }
    public function todayInbondCall($extension)
    {
        return $this->database->getData('powerdial_cdr_table', array('count(serial_num) as cnt'), array('exten' => $extension, 'route' => 'IN'));
    }
    public function extensionCallTransfer($transferExtension, $lead, $campaign, $number, $reportId)
    {
        //Critical feature to make sure we have proper data
        if(empty($lead) || empty($campaign))
        {
            $getLeadIdCampaignId = $this->getLeadIdCampaignId();
            $lead = $getLeadIdCampaignId['lead'];
            $campaign = $getLeadIdCampaignId['campaign'];
        }
        if(empty($number))
        {
            $number = $this->getphoneNumber($lead);
        }
        $extenStatus  = $this->getExtensonLoginStatus($transferExtension);
        if(isset($extenStatus['status']) && strpos($extenStatus['status'], 'OK') !== false )
        {
            $callerId = "5500"."$this->extension"."$campaign";
            $originateRequest = "Action: originate\r\n";
            //$originateRequest .= "Channel: SIP/$transferExtension\r\n";
            $originateRequest .= "Channel: local/$this->extension-$transferExtension-$campaign-$lead-$number-$reportId-$this->account_num@transfer-confbridge\r\n";
            $originateRequest .= "Timeout: $this->waitTime\r\n";
            $originateRequest .= "Callerid: $callerId\r\n";
            $originateRequest .= "Exten: $this->extension-$transferExtension-$campaign-$lead-$number-$reportId-$this->account_num\r\n";
            //$originateRequest .= "Exten: $this->extension-$transferExtension-".$campaign."-".$lead."-".$number."\r\n";
            $originateRequest .= "Context: transfer-local\r\n";
            $originateRequest .= "Priority: 1\r\n";
            $originateRequest .= "Async: yes\r\n";
            $originateRequest .= "Action: Logoff\r\n\r\n";
            // Send originate request
            return $this->amiCommand($originateRequest);
        }
        //$this->transferLog($transferExtension, $campaign, $lead, $number, "Extension is not logged in ");
        return "false";
    }
    public function getExtensonLoginStatus ($extension)
    {
        $ip = $_SERVER['SERVER_ADDR'];
        if($this->host == $ip) {
            $server = '';
        } else {
            $server = $this->host;
        }
        if(empty($server))
        {
            $publicIP = exec("sudo /usr/sbin/asterisk -rx 'sip show peer " . $extension . "' | grep Addr | cut -d ':' -f 2");
            $localIP = exec("sudo /usr/sbin/asterisk -rx 'sip show peer " . $extension . "' | grep Reg | cut -d '@' -f 2  | cut -d ':' -f 1");
            $status = exec("sudo /usr/sbin/asterisk -rx 'sip show peer " . $extension . "' | grep Status | cut -d ':' -f2 | cut -c 2-");
            return array('public' => $publicIP, 'local' => $localIP, 'status' => strtoupper($status));
        }
        else
        {
            $url = "http://" . $server . MANAGEEXTENSIONFILEPATH . "?action=extension_detail&extension=" . $extension;
            $res = json_decode(file_get_contents($url));
            return array('public' => $res->public_ip, 'local' => $res->local_ip, 'status' => strtoupper($res->status));
        }
    }
    public function transferLog($transferExtension, $campaign, $lead, $number, $status, $id = '')
    {
        //Critical feature to make sure we have proper data
        /*if(empty($lead) || empty($campaign))
        {
            $getLeadIdCampaignId = $this->getLeadIdCampaignId();
            $lead = $getLeadIdCampaignId['lead'];
            $campaign = $getLeadIdCampaignId['campaign'];
        }
        if(empty($number))
        {
            $number = $this->getphoneNumber($lead);
        }
        if(empty($id))
        {
            $this->database->setData('transfer_log', array('admin' => $this->account_num,
                    'extension' => $this->extension,
                    'transfer_extension' => $transferExtension,
                    'campaign_id' => $campaign,
                    'lead_id' => $lead,
                    'status' => $status,
                    'number' => $number
                )
            );
            return $this->database->lastInsertId();
        }
        else{
            $this->database->updateData('transfer_log', array('status' => $status), array('id' => $id));
            return $id;
        }*/


    }
    public function checkTransferStatus($lead, $campaign)
    {
        //Critical feature to make sure we have proper data
        if(empty($lead) || empty($campaign))
        {
            $getLeadIdCampaignId = $this->getLeadIdCampaignId();
            $lead = $getLeadIdCampaignId['lead'];
            $campaign = $getLeadIdCampaignId['campaign'];
        }
        $sql = "SELECT extension FROM exten_live WHERE lead_id = :lead_id AND campaign_id = :campaign_id AND extension != :extension";
        return $this->database->select($sql, array('extension' => $this->extension, 'lead_id' => $lead, 'campaign_id' => $campaign), array(),'0,1');
    }
    public function callTransferPatch($transferExtension, $lead, $campaign)
    {
        return "true";
        //Critical feature to make sure we have proper data
        /*if(empty($lead) || empty($campaign))
        {
            $getLeadIdCampaignId = $this->getLeadIdCampaignId();
            $lead = $getLeadIdCampaignId['lead'];
            $campaign = $getLeadIdCampaignId['campaign'];
        }
        $getChannel = $this->database->getData('exten_live', array('channel'), array('extension' => $this->extension), array(),'0,1');
        $channel = (isset($getChannel[0]['channel'])) ? $getChannel[0]['channel'] : '';
        if(!empty($channel)){
            $callerId = "5500"."$this->extension"."$campaign";
            $originateRequest = "Action: Redirect\r\n";
            $originateRequest .= "Channel: $channel\r\n";
            $originateRequest .= "Timeout: $this->waitTime\r\n";
            $originateRequest .= "Callerid: $callerId\r\n";
            $originateRequest .= "Exten: $this->extension-$transferExtension-".$campaign."-".$lead."\r\n";
            $originateRequest .= "Context: live-transfer\r\n";
            $originateRequest .= "Priority: 1\r\n";
            $originateRequest .= "Async: yes\r\n";
            $originateRequest .= "Action: Logoff\r\n\r\n";

            // Send originate request
            return $this->amiCommand($originateRequest);
        }
        else{
            return "false";
        }*/

    }

    public function leaveConference($transferExtension, $lead, $campaign)
    {
        //Critical feature to make sure we have proper data
        $getChannelCurrentExtension = $this->database->getData('powerdial_line_details', array('channel'), array('exten' => $this->extension), array(),'0,1');
        if(!empty($getChannelCurrentExtension))
        {
            $currentChannelExtension = (isset($getChannelCurrentExtension[0]['channel'])) ? $getChannelCurrentExtension[0]['channel'] : '';

        }
        if(empty($lead) || empty($campaign))
        {
            $getLeadIdCampaignId = $this->getLeadIdCampaignId();
            $lead = $getLeadIdCampaignId['lead'];
            $campaign = $getLeadIdCampaignId['campaign'];
        }
        $number = $this->getphoneNumber($lead);
        if(!empty($getChannelCurrentExtension)){
            $getChannel = $this->database->getData('local_channel1', array('local_channel'), array('confno' => $transferExtension), array(),'0,1');
            $channel = (isset($getChannel[0]['local_channel'])) ? $getChannel[0]['local_channel'] : '';

            $callerId = "5500"."$this->extension"."$campaign";
            $originateRequest = "Action: Redirect\r\n";
            $originateRequest .= "Channel: $channel\r\n";
            $originateRequest .= "Timeout: $this->waitTime\r\n";
            $originateRequest .= "Callerid: $callerId\r\n";
            $originateRequest .= "Exten: $this->extension-$transferExtension-".$campaign."-".$lead."-".$number."-".$this->adminAccountNum."\r\n";
            $originateRequest .= "Context: channel-redirect-transfers\r\n";
            $originateRequest .= "Priority: 1\r\n";
            $originateRequest .= "Async: yes\r\n";
            $originateRequest .= "Action: Logoff\r\n\r\n";


            // Send originate request
            $res = $this->amiCommand($originateRequest);
            if($res == "true")
            {
                if(!empty($currentChannelExtension))
                {
                    $this->database->updateData('exten_live', array('transfer_status' => 'transfer'), array('extension' => $this->extension));
                    $callerId = "5500"."$this->extension"."$campaign";
                    $originateRequest = "Action: Redirect\r\n";
                    $originateRequest .= "Channel: $currentChannelExtension\r\n";
                    $originateRequest .= "Timeout: $this->waitTime\r\n";
                    $originateRequest .= "Callerid: $callerId\r\n";
                    $originateRequest .= "Exten: $this->extension-$transferExtension-".$campaign."-".$lead."-".$number."-".$this->adminAccountNum."\r\n";
                    $originateRequest .= "Context: channel-redirect-transfers1\r\n";
                    $originateRequest .= "Priority: 1\r\n";
                    $originateRequest .= "Async: yes\r\n";
                    $originateRequest .= "Action: Logoff\r\n\r\n";
                    $res = $this->amiCommand($originateRequest);
                }

                echo json_encode(array('status' => "success", 'message' => 'Your are successfully leave the conference.'));
            }
            else{
                echo json_encode(array('status' => "false", 'message' => 'Your are not able to leave the conference, Kindly hang up the extension'));
            }
        }
        else{
            echo json_encode(array('status' => "false", 'message' => 'Your are not able to leave the conference, Kindly hang up the extension'));
        }

    }
    public function addCustomerToCall($transferExtension, $lead, $campaign, $number)
    {
        //Critical feature to make sure we have proper data
        if(empty($lead) || empty($campaign))
        {
            $getLeadIdCampaignId = $this->getLeadIdCampaignId();
            $lead = $getLeadIdCampaignId['lead'];
            $campaign = $getLeadIdCampaignId['campaign'];
        }
        if(empty($number))
        {
            $number = $this->getphoneNumber($lead);
        }
        $getChannel = $this->database->getData('powerdial_line_details', array('channel'), array('exten' => $this->extension), array(),'0,1');
        $channel = (isset($getChannel[0]['channel'])) ? $getChannel[0]['channel'] : '';
        if(!empty($channel)){
            $callerId = "5500"."$this->extension"."$campaign";
            $originateRequest = "Action: Redirect\r\n";
            $originateRequest .= "Channel: $channel\r\n";
            $originateRequest .= "Timeout: $this->waitTime\r\n";
            $originateRequest .= "Callerid: $callerId\r\n";
            $originateRequest .= "Exten: $this->extension-$transferExtension-".$campaign."-".$lead."-".$number."-".$this->account_num."\r\n";
            $originateRequest .= "Context: customer-live-transfer\r\n";
            $originateRequest .= "Priority: 1\r\n";
            $originateRequest .= "Async: yes\r\n";
            $originateRequest .= "Action: Logoff\r\n\r\n";

            // Send originate request
            $res = $this->amiCommand($originateRequest);
            if($res == "true")
            {
                echo json_encode(array('status' => "success", 'message' => 'Customer is added to call'));
            }
            else{
                echo json_encode(array('status' => "false", 'message' => 'Not able to add customer to call, please try again.'));
            }
        }
        else{
            echo json_encode(array('status' => "false", 'message' => 'Not able to add customer to call, please inform to administrator'));
        }

    }
    public function transferCloseBtn($lead, $campaign)
    {
        //Critical feature to make sure we have proper data
        if(empty($lead) || empty($campaign))
        {
            $getLeadIdCampaignId = $this->getLeadIdCampaignId();
            $lead = $getLeadIdCampaignId['lead'];
            $campaign = $getLeadIdCampaignId['campaign'];
        }
        $findExten =  $this->database->getData('exten_live', array('extension'),  array( 'campaign_id' => $campaign, 'lead_id' => $lead, 'transfer_status' => 'transfer_in'));
        if(!empty($findExten) && isset($findExten[0]['extension']))
        {
            $this->transferCancel($lead, $campaign);
            /*$sql = "UPDATE transfer_log SET status = :status WHERE admin = :admin AND extension = :extension AND transfer_extension = :transfer_extension AND campaign_id = :campaign_id AND lead_id = :lead_id AND datetime like '".date('Y-m-d')."%'";
            $this->database->update($sql,
                array(
                    'status' => 'Agent clicked on close button',
                    'admin' => $this->account_num,
                    'extension' => $this->extension,
                    'transfer_extension' => $findExten[0]['extension'],
                    'campaign_id' => $campaign,
                    'lead_id' => $lead
                )
            );*/
        }
        else{
            /*$sql = "UPDATE transfer_log SET status = :status WHERE admin = :admin AND extension = :extension AND transfer_extension = :transfer_extension AND campaign_id = :campaign_id AND lead_id = :lead_id AND datetime like '".date('Y-m-d')."%'";
            $this->database->update($sql,
                array(
                    'status' => 'Agent clicked on close button',
                    'admin' => $this->account_num,
                    'extension' => $this->extension,
                    'campaign_id' => $campaign,
                    'lead_id' => $lead
                )
            );*/
        }
        return;
    }
    public function transferCancel($lead, $campaign)
    {
        if($this->extension != '') {
            //Critical feature to make sure we have proper data
            if(empty($lead) || empty($campaign))
            {
                $getLeadIdCampaignId = $this->getLeadIdCampaignId();
                $lead = $getLeadIdCampaignId['lead'];
                $campaign = $getLeadIdCampaignId['campaign'];
            }
            $channelDetail = $this->database->getData('local_channel1', array('local_channel'), array('campaign_id' => $campaign, 'lead_id' => $lead));
            if(!empty($channelDetail))
            {
                foreach ($channelDetail as $key=>$value)
                {
                    $channel = $value['local_channel'];
                }
            }
            else{
                return false;
            }
            if(!empty($channel))
            {
                $request = "Action: Hangup\r\n";
                $request .= "Channel: $channel\r\n";
                $request .= "Timeout: $this->waitTime\r\n";
                $request .= "Async: yes\r\n";
                $request .= "Action: Logoff\r\n\r\n";
                // Send originate request
                $param['action'] = 'logout';
                $this->amiCommand($request, $param);
            }
            $this->database->deleteData('local_channel1' , array('confno' => $this->extension));
            echo json_encode(array('status' => 'success', 'msg' => "Transfer cancelled"));
        }
        else{
            echo json_encode(array('status' => 'fail', 'msg' => "Error : Your are not logged in from extension : $this->extension"));
        }
    }

    public function updateMailBox($extension)
    {
        if(!empty($extension)){
            $request = "Action: Command\r\n";
            $request .= "Command: sip notify clear-mwi $extension\r\n";
            $request .= "Action: Logoff\r\n\r\n";
            $param['action'] = 'logout';
            return $this->amiCommand($request, $param);
        }else {
            return false;
        }
    }
    
    public function listenCall($lineDetailArray,$ext)
    {
        if (app()->environment() == "local") return true;
        
        if(!empty($lineDetailArray))
        {
            if($lineDetailArray['type']=='manual'){
                $request = "Action: Command\r\n";
                $request .= "Command: originate SIP/".$ext." extension ".$lineDetailArray['extension']."@monitor-deskphone-portal\r\n\r\n";
                $request .= "Timeout: $this->waitTime\r\n";
                $request .= "Priority: 1\r\n";
                $request .= "Async: yes\r\n";
                $request .= "Action: Logoff\r\n\r\n";
            }else if($lineDetailArray['type']=='dialer'){
                    $request = "Action: Command\r\n";
                    $request .= "Command: originate SIP/".$ext." extension ".$lineDetailArray['extension']."@monitor-extension-portal\r\n\r\n";
                    $request .= "Timeout: $this->waitTime\r\n";
                    $request .= "Priority: 1\r\n";
                    $request .= "Async: yes\r\n";
                    $request .= "Action: Logoff\r\n\r\n";
            }
                // Send originate request
                $param['action'] = '';
                $response = $this->amiCommand($request, $param);
                return $response;
        }
        else{
            return false;
        }

    }
    
    public function bargeCall($lineDetailArray,$ext)
    {
        if (app()->environment() == "local") return true;
        
        if(!empty($lineDetailArray))
        {
            if($lineDetailArray['type']=='manual'){
                $request = "Action: Command\r\n";
                $request .= "Command: originate SIP/".$ext." extension ".$lineDetailArray['extension']."@barge-deskphone-portal\r\n\r\n";
                $request .= "Timeout: $this->waitTime\r\n";
                $request .= "Priority: 1\r\n";
                $request .= "Async: yes\r\n";
                $request .= "Action: Logoff\r\n\r\n";
            }else if($lineDetailArray['type']=='dialer'){
                $request = "Action: Command\r\n";
                $request .= "Command: originate SIP/".$ext." extension ".$lineDetailArray['extension']."@barge-extension-portal\r\n\r\n";
                $request .= "Timeout: $this->waitTime\r\n";
                $request .= "Priority: 1\r\n";
                $request .= "Async: yes\r\n";
                $request .= "Action: Logoff\r\n\r\n";   
            }
                // Send originate request
                $param['action'] = '';
                $response = $this->amiCommand($request, $param);
                return $response;
        }
        else{
            return false;
        }
    }

    public function getWarmCallTransfer($data,$campaign)
    {


        $agentLoginStatus = 1;
        

            
        if(!empty($agentLoginStatus)) {
            if ($data['lead_id'] != '' && $data['number'] != '') {

                $type = 'dialer';

                if (app()->environment() == "local") return true;

                if(isset($campaign->campaign_id))
                {
                 $sql = "SELECT cli FROM cdr WHERE (extension = :extension and campaign_id = :campaign_id and lead_id = :lead_id)";
                 $cli_data =DB::connection('mysql_'.$data['parent_id'])->selectOne($sql, array('extension' => $data['user_extension'], 'campaign_id' => $campaign->campaign_id, 'lead_id' => $data['lead_id']));

                 $data['campaign_id'] = $campaign->campaign_id;
                 

                }
                else
                {
                    $data['campaign_id'] = 45;
                 $sql = "SELECT cli FROM cdr WHERE (extension = :extension  and lead_id = :lead_id)";
                 $cli_data =DB::connection('mysql_'.$data['parent_id'])->selectOne($sql, array('extension' => $data['user_extension'], 'lead_id' => $data['lead_id']));


                }


                 $cli = $cli_data->cli;


                $number = $data['number'];

                //originate SIP/38061 extension 33184-38061-<campaign_id>-<lead-id>-<cli>-<phone-number>@dialer-warm-transfer-extension

                $callerId = "<$number>";
                $extenStr = $data['user_extension'].'-'.$data['forward_extension']."-".$data['campaign_id']."-".$data['lead_id']."-".$cli."-".$data['number']."-".$data['parent_id']; 
                $originateRequest = "Action: originate\r\n";
                $originateRequest .= "Channel: SIP/".$data['forward_extension']."\r\n";
                $originateRequest .= "Timeout: $this->waitTime\r\n";
                $originateRequest .= "Callerid: $callerId\r\n";
                $originateRequest .= "Exten: $extenStr\r\n";
                $originateRequest .= "Context: dialer-warm-transfer-extension\r\n";
                $originateRequest .= "Priority: 1\r\n";
                $originateRequest .= "Async: yes\r\n";
                $originateRequest .= "Action: Logoff\r\n\r\n";
                // Send originate request
                $param['action'] = 'dial';
                $param['campaign_id'] = $data['campaign_id'];
                $param['mobile'] = $number;
                $response = $this->amiCommand($originateRequest, $param);
                if($response == "true")
                {
                    /*include_once ("Class/ListClass.php");
                    $listObj = new ListClass();
                    $listObj->preApiCall($this->extension,$this->account_num, $campaignId, $id, $number);
                    echo json_encode(array('status' => 'success'));*/
                    return true;
                }
                return false;
            }
        }
        else{
            echo json_encode(array('status' => 'fail', 'msg' => "Error : Your are not logged in from extension : $this->extension"));
        }
    }

    public function getWarmCallTransferRingGroup($data,$campaign)
    {


        $agentLoginStatus = 1;
        

            
        if(!empty($agentLoginStatus)) {
            if ($data['lead_id'] != '' && $data['number'] != '') {

                $type = 'dialer';

                if (app()->environment() == "local") return true;

                if(isset($campaign->campaign_id))
                {
                 $sql = "SELECT cli FROM cdr WHERE (extension = :extension and campaign_id = :campaign_id and lead_id = :lead_id)";
                 $cli_data =DB::connection('mysql_'.$data['parent_id'])->selectOne($sql, array('extension' => $data['user_extension'], 'campaign_id' => $campaign->campaign_id, 'lead_id' => $data['lead_id']));

                }
                else
                {
                    $data['campaign_id'] = 1;
                 $sql = "SELECT cli FROM cdr WHERE (extension = :extension  and lead_id = :lead_id)";
                 $cli_data =DB::connection('mysql_'.$data['parent_id'])->selectOne($sql, array('extension' => $data['user_extension'], 'lead_id' => $data['lead_id']));


                }


                 $cli = $cli_data->cli;


                $number = $data['number'];

                //originate SIP/38061 extension 33184-38061-<campaign_id>-<lead-id>-<cli>-<phone-number>@dialer-warm-transfer-extension

                $callerId = "<$number>";
                $extenStr = $data['user_extension'].'-'.$data['forward_extension']."-".$data['campaign_id']."-".$data['lead_id']."-".$cli."-".$data['number']."-".$data['parent_id']; 
                $originateRequest = "Action: originate\r\n";
                $originateRequest .= "Channel: SIP/".$data['forward_extension']."\r\n";
                $originateRequest .= "Timeout: $this->waitTime\r\n";
                $originateRequest .= "Callerid: $callerId\r\n";
                $originateRequest .= "Exten: $extenStr\r\n";
                $originateRequest .= "Context: dialer-warm-transfer-ring-group\r\n";
                $originateRequest .= "Priority: 1\r\n";
                $originateRequest .= "Async: yes\r\n";
                $originateRequest .= "Action: Logoff\r\n\r\n";
                // Send originate request
                $param['action'] = 'dial';
                $param['campaign_id'] = $data['campaign_id'];
                $param['mobile'] = $number;
                $response = $this->amiCommand($originateRequest, $param);
                if($response == "true")
                {
                    /*include_once ("Class/ListClass.php");
                    $listObj = new ListClass();
                    $listObj->preApiCall($this->extension,$this->account_num, $campaignId, $id, $number);
                    echo json_encode(array('status' => 'success'));*/
                    return true;
                }
                return false;
            }
        }
        else{
            echo json_encode(array('status' => 'fail', 'msg' => "Error : Your are not logged in from extension : $this->extension"));
        }
    }


    public function warmCallTransferC2CCRM($data)
    {

        $agentLoginStatus = 1;
        

            
        if(!empty($agentLoginStatus)) {
            if ($data['lead_id'] != '' && $data['number'] != '') {

                $type = 'dialer';

                if (app()->environment() == "local") return true;

                 $sql = "SELECT cli FROM cdr WHERE (extension = :extension  and lead_id = :lead_id)";
                 $cli_data =DB::connection('mysql_'.$data['parent_id'])->selectOne($sql, array('extension' => $data['user_extension'], 'lead_id' => $data['lead_id']));

                 $cli = $cli_data->cli;


                $number = $data['number'];

                $data['campaign_id'] = 1;

                //originate SIP/38061 extension 33184-38061-<campaign_id>-<lead-id>-<cli>-<phone-number>@dialer-warm-transfer-extension

                $callerId = "<$number>";
                $extenStr = $data['user_extension'].'-'.$data['forward_extension']."-".$data['campaign_id']."-".$data['lead_id']."-".$cli."-".$data['number']."-".$data['parent_id']; 
                $originateRequest = "Action: originate\r\n";
                $originateRequest .= "Channel: SIP/".$data['forward_extension']."\r\n";
                $originateRequest .= "Timeout: $this->waitTime\r\n";
                $originateRequest .= "Callerid: $callerId\r\n";
                $originateRequest .= "Exten: $extenStr\r\n";
                $originateRequest .= "Context: dialer-warm-transfer-extension\r\n";
                $originateRequest .= "Priority: 1\r\n";
                $originateRequest .= "Async: yes\r\n";
                $originateRequest .= "Action: Logoff\r\n\r\n";
                // Send originate request
                $param['action'] = 'dial';
                $param['campaign_id'] = $data['campaign_id'];
                $param['mobile'] = $number;
                $response = $this->amiCommand($originateRequest, $param);
                if($response == "true")
                {
                    /*include_once ("Class/ListClass.php");
                    $listObj = new ListClass();
                    $listObj->preApiCall($this->extension,$this->account_num, $campaignId, $id, $number);
                    echo json_encode(array('status' => 'success'));*/
                    return true;
                }
                return false;
            }
        }
        else{
            echo json_encode(array('status' => 'fail', 'msg' => "Error : Your are not logged in from extension : $this->extension"));
        }
    }


    public function warmCallTransferDid($data,$campaign)
    {


        $agentLoginStatus = 1;
        

            
        if(!empty($agentLoginStatus)) {
            if ($data['lead_id'] != '' && $data['number'] != '') {

                $type = 'dialer';

                if (app()->environment() == "local") return true;

                if(isset($campaign->campaign_id))
                {
                 $sql = "SELECT cli FROM cdr WHERE (extension = :extension and campaign_id = :campaign_id and lead_id = :lead_id)";
                 $cli_data =DB::connection('mysql_'.$data['parent_id'])->selectOne($sql, array('extension' => $data['user_extension'], 'campaign_id' => $campaign->campaign_id, 'lead_id' => $data['lead_id']));

                 $data['campaign_id'] = $campaign->campaign_id;


                }
                else
                {
                    $data['campaign_id'] = 1;
                 $sql = "SELECT cli FROM cdr WHERE (extension = :extension  and lead_id = :lead_id)";
                 $cli_data =DB::connection('mysql_'.$data['parent_id'])->selectOne($sql, array('extension' => $data['user_extension'], 'lead_id' => $data['lead_id']));


                }


                 $cli = $cli_data->cli;


                $number = $data['number'];

                //originate SIP/38061 extension 33184-38061-<campaign_id>-<lead-id>-<cli>-<phone-number>@dialer-warm-transfer-extension

                $callerId = "<$number>";
                $extenStr = $data['user_extension'].'-'.$data['did_number']."-".$data['campaign_id']."-".$data['lead_id']."-".$cli."-".$data['number']."-".$data['parent_id']; 
                $originateRequest = "Action: originate\r\n";
                $originateRequest .= "Channel: SIP/pilivo/".$data['did_number']."\r\n";
                $originateRequest .= "Timeout: $this->waitTime\r\n";
                $originateRequest .= "Callerid: $callerId\r\n";
                $originateRequest .= "Exten: $extenStr\r\n";
                $originateRequest .= "Context: dialer-warm-transfer-did\r\n";
                $originateRequest .= "Priority: 1\r\n";
                $originateRequest .= "Async: yes\r\n";
                $originateRequest .= "Action: Logoff\r\n\r\n";
                // Send originate request
                $param['action'] = 'dial';
                $param['campaign_id'] = $data['campaign_id'];
                $param['mobile'] = $number;
                $response = $this->amiCommand($originateRequest, $param);
                if($response == "true")
                {
                    /*include_once ("Class/ListClass.php");
                    $listObj = new ListClass();
                    $listObj->preApiCall($this->extension,$this->account_num, $campaignId, $id, $number);
                    echo json_encode(array('status' => 'success'));*/
                    return true;
                }
                return false;
            }
        }
        else{
            echo json_encode(array('status' => 'fail', 'msg' => "Error : Your are not logged in from extension : $this->extension"));
        }
    }



    public function channelRedirectToAgentB($request,$channel,$forward_extension)
    {
            if (app()->environment() == "local") return array('status' => "true");
        if(isset($request->campaign_id))
        {
            $sql = "SELECT cli FROM cdr WHERE (extension = :extension and campaign_id = :campaign_id and lead_id = :lead_id)";
            $cli_data =DB::connection('mysql_'.$request->auth->parent_id)->selectOne($sql, array('extension' => $request->auth->alt_extension, 'campaign_id' => $request->campaign_id, 'lead_id' => $request->lead_id));

            //$data['campaign_id'] = $campaign->campaign_id;
            $campaign_id = $request->campaign_id;
            
        }
        else
        {
            $campaign_id = 45;
            $sql = "SELECT cli FROM cdr WHERE (extension = :extension  and lead_id = :lead_id)";
            $cli_data =DB::connection('mysql_'.$request->auth->parent_id)->selectOne($sql, array('extension' => $request->auth->alt_extension, 'lead_id' => $request->lead_id));
        }

       $cli = $cli_data->cli;


        $extenStr = $request->auth->alt_extension.'-'.$forward_extension.'-'.$campaign_id.'-'.$request->lead_id.'-'.$cli.'-'.$request->customer_phone_number.'-'.$request->auth->parent_id;

           /*  return array(
                        'success' => true,
                        'message' => $extenStr
                    );*/





           // $extenStr = $forward_extension; 
            $originateRequest = "Action: Redirect\r\n";
            $originateRequest .= "Channel: ".$channel."\r\n";

            $originateRequest .= "Timeout: $this->waitTime\r\n";
            //$originateRequest .= "Callerid: $callerId\r\n";
            $originateRequest .= "Exten: $extenStr\r\n";

            if($request->call_transfer_type == 'did')
            {
                $originateRequest .= "Context: dialer-warm-transfer-did-redirect\r\n";
            }
            else
                if($request->call_transfer_type == 'extension')
            {
                $originateRequest .= "Context: dialer-warm-transfer-extension-redirect\r\n";
            }
            else
            {
                $originateRequest .= "Context: dialer-warm-transfer-extension-redirect\r\n";
                
            }

            $originateRequest .= "Priority: 1\r\n";
            $originateRequest .= "Async: yes\r\n";
            $originateRequest .= "Action: Logoff\r\n\r\n";

            $param['action'] = 'dial';
            $param['extension'] = $forward_extension;

            // Send originate request
            $res = $this->amiCommand($originateRequest,$param);

            return array('status' => "true");
            

           /* if($res == "true")
                {
                    include_once ("Class/ListClass.php");
                    $listObj = new ListClass();
                    $listObj->preApiCall($this->extension,$this->account_num, $campaignId, $id, $number);
                    echo json_encode(array('status' => 'success'));
                    return true;
                }
                return false;*/

        
    }


    public function channelMergeWithNumber($request,$forward_extension,$channel)
    {

            if (app()->environment() == "local") return array('status' => "true");

    if(isset($request->campaign_id))
        {
            $sql = "SELECT cli FROM cdr WHERE (extension = :extension and campaign_id = :campaign_id and lead_id = :lead_id)";
            $cli_data =DB::connection('mysql_'.$request->auth->parent_id)->selectOne($sql, array('extension' => $request->auth->alt_extension, 'campaign_id' => $request->campaign_id, 'lead_id' => $request->lead_id));

            //$data['campaign_id'] = $campaign->campaign_id;

            $campaign_id = $request->campaign_id;

        }
        else
        {
            $campaign_id = 45;
            $sql = "SELECT cli FROM cdr WHERE (extension = :extension  and lead_id = :lead_id)";
            $cli_data =DB::connection('mysql_'.$request->auth->parent_id)->selectOne($sql, array('extension' => $request->auth->alt_extension, 'lead_id' => $request->lead_id));
        }

       $cli = $cli_data->cli;


        $extenStr = $request->auth->alt_extension.'-'.$forward_extension.'-'.$campaign_id.'-'.$request->lead_id.'-'.$cli.'-'.$request->customer_phone_number.'-'.$request->auth->parent_id;

           /*  return array(
                        'success' => true,
                        'message' => $extenStr
                    );*/





           // $extenStr = $forward_extension; 
            $originateRequest = "Action: Redirect\r\n";
            $originateRequest .= "Channel: ".$channel."\r\n";



            $originateRequest .= "Timeout: $this->waitTime\r\n";
            $originateRequest .= "Exten: $extenStr\r\n";

            $originateRequest .= "Context: dialer-warm-transfer-number-redirect\r\n";
            $originateRequest .= "Priority: 1\r\n";
            $originateRequest .= "Async: yes\r\n";
            $originateRequest .= "Action: Logoff\r\n\r\n";

            $param['action'] = 'dial';
            $param['extension'] = $forward_extension;

            // Send originate request
            $res = $this->amiCommand($originateRequest,$param);

            return array('status' => "true");


          /*  if($res == "true")
                {
                    include_once ("Class/ListClass.php");
                    $listObj = new ListClass();
                    $listObj->preApiCall($this->extension,$this->account_num, $campaignId, $id, $number);
                    echo json_encode(array('status' => 'success'));
                    return true;
                }
                return false;*/

        
    }


     public function leaveConferenceTransfer($request,$alt_extension,$channel)
    {

      /*  if(isset($request->campaign_id))
        {
            $sql = "SELECT cli FROM cdr WHERE (extension = :extension and campaign_id = :campaign_id and lead_id = :lead_id)";
            $cli_data =DB::connection('mysql_'.$request->auth->parent_id)->selectOne($sql, array('extension' => $request->auth->alt_extension, 'campaign_id' => $request->campaign_id, 'lead_id' => $request->lead_id));

            //$data['campaign_id'] = $campaign->campaign_id;
        }
        else
        {
            //$data['campaign_id'] = 1;
            $sql = "SELECT cli FROM cdr WHERE (extension = :extension  and lead_id = :lead_id)";
            $cli_data =DB::connection('mysql_'.$data['parent_id'])->selectOne($sql, array('extension' => $data['user_extension'], 'lead_id' => $data['lead_id']));
        }

        $cli = $cli_data->cli;*/


        $extenStr = $request->auth->parent_id.'-'.$request->auth->alt_extension;

         

        if (app()->environment() == "local") return array('status' => "true");



           // $extenStr = $forward_extension; 
            $originateRequest = "Action: Redirect\r\n";
            $originateRequest .= "Channel: ".$channel."\r\n";
            


            $originateRequest .= "Timeout: $this->waitTime\r\n";
            $originateRequest .= "Exten: $extenStr\r\n";

            $originateRequest .= "Context: dialer-warm-transfer-number-redirect-leave\r\n";
            $originateRequest .= "Priority: 1\r\n";
            $originateRequest .= "Async: yes\r\n";
            $originateRequest .= "Action: Logoff\r\n\r\n";

            $param['action'] = 'dial';
            $param['extension'] = $request->auth->alt_extension;

            // Send originate request
            $res = $this->amiCommand($originateRequest,$param);

            return array('status' => "true");


          /*  if($res == "true")
                {
                    include_once ("Class/ListClass.php");
                    $listObj = new ListClass();
                    $listObj->preApiCall($this->extension,$this->account_num, $campaignId, $id, $number);
                    echo json_encode(array('status' => 'success'));
                    return true;
                }
                return false;*/

        
    }



}
