<?php

namespace App\Jobs;
use Illuminate\Support\Facades\Log;
use DateTime;
use DateTimeZone;
use Illuminate\Http\Request;
use App\Model\Master\RvmQueueList;
use App\Model\Master\RvmCdrLog;



use Illuminate\Support\Facades\DB;

class RvmSchedulesProcess extends Job
{
    public $tries = 5;
    public $timeout = 300;
    private $apiKey;
    private $data;

    public function __construct(string $apiKey, array $data)
    {
        $this->apiKey = $apiKey;
        $this->data = $data;

       

      //  Log::info("RinglessVoicemailDropBySipName($apiKey)", $data);
    }

    public function handle()
    {
        
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
            $sip_gateway_id = $this->data['sip_gateway_id'];

            $RvmQueueList = new RvmQueueList();
            $RvmQueueList->rvm_cdr_log_id = $rvm_cdr_log_id;
            $RvmQueueList->save();






            $originateCommand = "Action: Originate\r\n";
            echo  $originateCommand .= "Channel: Local/$phone-$cli-$voicemail_file_name-$apiToken-$user_id-$rvm_domain_id-$sip_gateway_id-$rvm_cdr_log_id@start-voice-testing\r\n";
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

                //sleep(2);
            }

            // Log out from the AMI
            fputs($socket, "Action: Logoff\r\n\r\n");
            // Close the socket connection

            fclose($socket);
                    $sql = "UPDATE rvm_cdr_log set timezone_status = :timezone_status, tries=:tries WHERE id = :id";
                    DB::update($sql, array('id' => $rvm_cdr->id, 'timezone_status' => '1', 'tries' => '1'));
                   // $this->info("Unsent RVM Drop Call timezone matched rvm_cdr_log table id ({$rvm_cdr->id})");

                    //return array('success' => 'true',"code" => 200,'message' => 'Ringless VoiceMail Drop By Sip Name API Success.','data' => $requestData);
                
               
            }
        
    }

   
