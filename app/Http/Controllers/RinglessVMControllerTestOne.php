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

class RinglessVMControllerTestOne extends Controller
{
    public function index(Request $request)
    {
        // Set your Asterisk AMI credentials
            $host = env('ASTERISK_AMI_HOST');

            $port = '5038'; // Default AMI port
            $username = 'admin1';
            $secret = 'mycode';

            $extension = 's';
            $priority = 1;


            $phone = '19024412385';//$rvm_data->phone;
            $cli   =   '19892714355';//$rvm_data->cli;
            $voicemail_file_name = "ep_1_voice_1712235109.wav";//$rvm_data->voicemail_file_name;
            $apiToken = "09eabc80";//$rvm_data->apiToken;
            $user_id = 1762;//$rvm_data->user_id;
            $voicemail_id = "3_10559";//$rvm_data->voicemail_id;
            $rvm_domain_id = 5;//$rvm_data->rvm_domain_id;
            $rvm_cdr_log_id = 44879;//$rvm_cdr->id;
            $sip_gateway_id = 12;//$rvm_data->sip_gateway_id;





            $originateCommand = "Action: Originate\r\n";
           // $originateCommand .= "Channel: Local/$phone-$cli-$voicemail_file_name-$apiToken-$user_id-$voicemail_id-$rvm_domain_id-$sip_gateway_id-$rvm_cdr_log_id@start-voice-testing\r\n";
//            $originateCommand .= "Channel: Local/$phone-$cli-$voicemail_file_name-$apiToken-$user_id-$voicemail_id-$rvm_domain_id-$rvm_cdr_log_id@start-voice\r\n";

            $originateCommand .= "Channel: Local/$phone-$cli-$voicemail_file_name-$apiToken-$user_id-$rvm_domain_id-$sip_gateway_id-$rvm_cdr_log_id@start-voice-testing\r\n";

            
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
                
                $this->info("RvmDropBySipNameCron timezone matched rvm_cdr_log table id ({$rvm_cdr->id})");
}
}
