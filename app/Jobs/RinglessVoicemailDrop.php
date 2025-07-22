<?php

namespace App\Jobs;
use Illuminate\Support\Facades\Log;
use DateTime;
use DateTimeZone;
use Illuminate\Http\Request;

use Illuminate\Support\Facades\DB;

class RinglessVoicemailDrop extends Job
{
    public $tries = 1;
    public $timeout = 300;
    private $apiKey;
    private $data;

    public function __construct(string $apiKey, array $data)
    {
        $this->apiKey = $apiKey;
        $this->data = $data;
       

        Log::info("RinglessVoicemailDrop($apiKey)", $data);
    }

    public function handle()
    {
        $number = preg_replace('/[^0-9]/', '', $this->data['phone']);
        $last10Digit = substr($number, -10);

        $return = ["dialable" => 0, "areacodeTimeZone" => 0, "dialingTime" => 0 ];
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

                if (strtotime($this->data['start_time']) < strtotime($currentTime) && strtotime($this->data['end_time']) > strtotime($currentTime)) 
                {
                    $return["dialingTime"] = 1;
                    $return["dialable"] = 1;
                }
            }
        }

        // echo "<pre>";print_r($return);die;

        if($return["dialable"] == 1)
        {
            // Set your Asterisk AMI credentials
            $host = env('ASTERISK_AMI_HOST');
            
            $port = '5038'; // Default AMI port
            $username = 'admin1';
            $secret = 'mycode';

            $extension = 's';
            $priority = 1;


            $phone = $this->data['phone'];
            $cli = $this->data['cli'];
            $voicemail_file_name = $this->data['voicemail_file_name'];
            $apiToken = $this->data['apiToken'];
            $user_id = $this->data['user_id'];
            $voicemail_id = $this->data['voicemail_id'];
            $rvm_domain_id = $this->data['rvm_domain_id'];
            $rvm_cdr_log_id = $this->data['rvm_cdr_log_id'];




            $originateCommand = "Action: Originate\r\n";
            $originateCommand .= "Channel: Local/$phone-$cli-$voicemail_file_name-$apiToken-$user_id-$voicemail_id-$rvm_domain_id-$rvm_cdr_log_id@start-voice\r\n";
            $originateCommand .= "Exten: $phone-$phone\r\n";
            $originateCommand .= "Context: voice-drop-campaign\r\n";
            $originateCommand .= "Priority: $priority\r\n";
            $originateCommand .= "Timeout: 30000\r\n"; // Set the timeout in milliseconds

            // Create a socket connection to the Asterisk AMI
            
            $socket = fsockopen($host, $port, $errno, $errstr);

            if (!$socket) {
                die("Error connecting to Asterisk AMI: $errstr ($errno)\n");
            }

            // Login to the AMI
            fputs($socket, "Action: Login\r\n");
            fputs($socket, "Username: $username\r\n");
            fputs($socket, "Secret: $secret\r\n\r\n");
        
            // Send the originate command
            fputs($socket, $originateCommand . "\r\n");

            //Read and process the response

            while (!feof($socket)) {
                $response = fgets($socket);
                //echo $response; // You can handle the AMI response as needed

                sleep(2);
            }

            // Log out from the AMI
            fputs($socket, "Action: Logoff\r\n\r\n");
            // Close the socket connection

            fclose($socket);
        }
        else
        {
            echo "not send";
        }
    }


      public function getTimezone($numberAreacode)
    {
        $timeZone = DB::connection('master')->selectOne("SELECT timezone FROM timezone WHERE areacode = :areacode", array('areacode' => $numberAreacode));
        $timeZone = (array)$timeZone;
        return $timeZone;
    }
}
