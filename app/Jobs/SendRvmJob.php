<?php

namespace App\Jobs;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Model\Master\RvmQueueList;
use App\Model\Master\RvmCdrLog;
use App\Model\Master\AsteriskServer;

use Carbon\Carbon;

use DateTime;
use DateTimeZone;

class SendRvmJob extends Job
{
    protected $data;
    public function __construct($data)
    {
        $this->data = $data;
    }

    public function handle()
    { 
        $phone = $this->data->phone;
        $cli = $this->data->cli;
        $voicemail_file_name = $this->data->voicemail_file_name;
        $apiToken = $this->data->apiToken;
        $user_id = $this->data->user_id;
        $rvm_domain_id = $this->data->rvm_domain_id;
        $sip_gateway_id = $this->data->sip_gateway_id;

        //check for instant rvm in queue

        if(isset($this->data->timezone_queue_trigger))
        {
            $asterisk_server_id = $this->data->timezone_queue_trigger;
        }
        else
        {
            $timezone_queue_trigger = 0;
        }

        //$timezone_queue_trigger = $this->data->timezone_queue_trigger;


        if(isset($this->data->asterisk_server_id))
        {
            $asterisk_server_id = $this->data->asterisk_server_id;
        }
        else
        {
            $asterisk_server_id = 1;
        }
        
        $rvm_cdr_log_id =  $this->data->id;

        $number = preg_replace('/[^0-9]/', '', $this->data->phone);
        $last10Digit = substr($number, -10);
        $return = ["dialable" => 0,"areacodeTimeZone" => 0,"dialingTime" => 0];
        $numberAreacode = substr(trim($last10Digit), 0, 3);
        $timeZone = $this->getTimezone($numberAreacode);

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

                if (strtotime($this->data->start_time) < strtotime($currentTime) && strtotime($this->data->end_time) > strtotime($currentTime)) 
                {
                    $return["dialingTime"] = 1;
                    $return["dialable"] = 1;
                }
            }
        }

        if($return['dialable'] == 1 || $timezone_queue_trigger == 1)
        {
            $rvm_queue_list = RvmQueueList::where('rvm_cdr_log_id',$this->data->id)->get()->first();
            //echo "<pre>";print_r($rvm_queue_list);die;
            if($rvm_queue_list)
            {
                return;
            }

           // $check_rvm_entry = RvmQueueList::orderBy('id', 'desc')->get()->first();

            $latestEntry = RvmQueueList::latest('created_at')->first();

            if ($latestEntry) {
                $timeDiffInSeconds = Carbon::now()->diffInSeconds(Carbon::parse($latestEntry->created_at), false);

        if ($timeDiffInSeconds < 2) {
            sleep(1);
        }
    }



            $RvmQueueList =new RvmQueueList();
            $RvmQueueList->rvm_cdr_log_id = $this->data->id;
            $RvmQueueList->status = $this->data->status_code;
            $RvmQueueList->save();


            $AsteriskServer = AsteriskServer::where('rvm_status', '1')->where('id',$asterisk_server_id)->first();
            
            $host = $AsteriskServer->host;
            $username = $AsteriskServer->user;
            $secret = $AsteriskServer->secret;


            usleep(300000);
            $host = $host; //env('ASTERISK_AMI_HOST');
            $port = '5038'; 
            $username = $username; //'admin1';
            $secret = $secret; //'mycode';
            $extension = 's';
            $priority = 1;

            /*$originateCommand = "Action: Originate\r\n";
            $originateCommand .= "Channel: Local/$phone-$cli-$voicemail_file_name-$apiToken-$user_id-$rvm_domain_id-$sip_gateway_id-$rvm_cdr_log_id@start-voice-testing\r\n";
            $originateCommand .= "Exten: $phone-$phone\r\n";
            $originateCommand .= "Context: voice-drop-campaign\r\n";
            $originateCommand .= "Priority: $priority\r\n";
            $originateCommand .= "Timeout: 3000\r\n"; // Set the timeout in milliseconds
            $socket = fsockopen($host, $port, $errno, $errstr);*/


            //test-ringless-main
            $originateCommand = "Action: Originate\r\n";
            $originateCommand .= "Channel: Local/$phone-$cli-$voicemail_file_name-$apiToken-$user_id-$rvm_domain_id-$sip_gateway_id-$rvm_cdr_log_id@rvm87\r\n";
            $originateCommand .= "Exten: $phone\r\n";
            //$originateCommand .= "Exten: $phone-$cli-$voicemail_file_name-$apiToken-$user_id-$rvm_domain_id-$sip_gateway_id-$rvm_cdr_log_id\r\n";
            $originateCommand .= "Context: voice-drop-campaign\r\n";
            $originateCommand .= "Priority: $priority\r\n";
            $originateCommand .= "Timeout: 10000\r\n"; // Set the timeout in milliseconds
            $socket = fsockopen($host, $port, $errno, $errstr);

            fputs($socket, "Action: Login\r\n");
            fputs($socket, "Username: $username\r\n");
            fputs($socket, "Secret: $secret\r\n\r\n");
            fputs($socket, $originateCommand . "\r\n");
            fputs($socket, "Action: Logoff\r\n\r\n");
            fclose($socket);

           


            $rvm_cdr_log_update = RvmCdrLog::where('id', $this->data->id)->first();
            $rvm_cdr_log_update->tries += 1; // Increment tries by 1
            $rvm_cdr_log_update->timezone_status = '1'; // Increment tries by 1
            $rvm_cdr_log_update->save();

            
            return true;
        }

        

        

    }

    public function getTimezone($numberAreacode)
    {
        $timeZone = DB::connection('master')->selectOne("SELECT timezone FROM timezone WHERE areacode = :areacode", array('areacode' => $numberAreacode));
        $timeZone = (array)$timeZone;
        return $timeZone;
    }
}
