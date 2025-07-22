<?php

namespace App\Console\Commands;
use Illuminate\Console\Command;
use App\Model\Master\RvmCdrLog;
use App\Model\Master\RvmDomainList;

use Illuminate\Support\Facades\DB;
use DateTime;
use DateTimeZone;
use Carbon\Carbon;
use App\Model\Master\Client;
use App\Jobs\SendRvmJob;
use App\Model\Master\RvmQueueList;

class DialedRvmDropBySipNameCron extends Command
{
    protected $signature = 'app:send:dialed-rvm-drop-by-sip-trunk  {--clientId=}';
    protected $description = 'timezone matched initiated calls';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $startTime = '09:00:00';
        $endTime = '18:00:00';
        $requestData = array();
        $clientKey =array();

        $date = Carbon::now()->subMinutes(5);
        $rvm_cdr_log = RvmCdrLog::where('api_token', 'bc6c')->where('status', '=', 'CHANUNAVAIL')->where('tries', '<=', 3)->where('updated_at', '<', $date)->orderBy('id', 'desc')->limit(25)->get()->all();

        //echo "<pre>";print_r($rvm_cdr_log);die;

        if($rvm_cdr_log)
        {
            foreach($rvm_cdr_log as $rvm_cdr)
            {
                $tries = $rvm_cdr->tries;
                $domain_list_id = $rvm_cdr->rvm_domain_id;
                $userID = $rvm_cdr->user_id;
                $voicemail_id = $rvm_cdr->voicemail_id;



               /* if($tries > 0)
                {
                    $domain_list = RvmDomainList::where('id',$domain_list_id)->get()->first();

                    if(isset($domain_list->callback_url))
                    {
                        $domain_url = $domain_list->callback_url;
                    }

                    $curl = curl_init();
                    $url = $domain_url."?userID=".$userID."&voicemail_id=".$voicemail_id."&status=failed";

                    curl_setopt_array($curl, [
                        CURLOPT_URL => $url,
                        CURLOPT_RETURNTRANSFER => true, // Return response instead of outputting
                        CURLOPT_FOLLOWLOCATION => true, // Follow redirects if necessary
                        CURLOPT_TIMEOUT => 30,          // Set a timeout for the request
                        CURLOPT_HTTPGET => true,        // Explicitly set GET method
                    ]);

                    $response = curl_exec($curl);

                    if (curl_errno($curl)) {
                        echo "cURL Error: " . curl_error($curl);
                    } else {
                        echo "Response: " . $response;
                    }



                    curl_close($curl);

                    $rvm_cdr['status'] ='failed';
                    $rvm_cdr->save();
                    continue;
                }*/

                /*$rvm_queue_list = RvmQueueList::where('rvm_cdr_log_id',$rvm_cdr->id)->get()->first();
                if($rvm_queue_list)
                {
                    $rvm_queue_list->delete();
                }*/



               /* else
                if(empty($rvm_cdr->json_data))
                {

                    $rvm_cdr_log_update = RvmCdrLog::where('id',$rvm_cdr->id)->get()->first();
                    $rvm_cdr_log_update['status'] ='Not Valid JSON';
                    $rvm_cdr_log_update->save();
                    continue;
                }*/

                $rvm_data = json_decode($rvm_cdr->json_data);
                $this->info("DialedRVMCall ({$rvm_cdr->api_token},{$rvm_cdr->id})");


                $rvm_data = json_decode($rvm_cdr->json_data);
                $rvm_data->id = $rvm_cdr->id;


                $number = preg_replace('/[^0-9]/', '', $rvm_cdr->phone);
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

                        if (strtotime($startTime) < strtotime($currentTime) && strtotime($endTime) > strtotime($currentTime)) 
                        {
                            $return["dialingTime"] = 1;
                            $return["dialable"] = 1;
                        }
                    }
                }

                $rvm_data->status_code = 'rvm_initiated_schedule_job';


             





                

                
               // echo "<pre>";print_r($rvm_data);die;

                if($return["dialable"] == 1)
                {

                    sleep(2);
                    dispatch((new SendRvmJob($rvm_data))->delay(Carbon::now()->addSeconds(5))->onConnection("rvm_unsent_schedule_job"));
                    $this->info("DialedRVMCall rvm_unsent_schedule_job timezone is matched rvm_cdr_log table id ({$rvm_cdr->api_token},{$rvm_cdr->id})");

                }
                else
                {
                    /*$rvm_cdr_log_update = RvmCdrLog::where('id',$rvm_cdr->id)->get()->first();
                    $rvm_cdr_log_update['timezone_status'] ='0';
                    $rvm_cdr_log_update->save();*/
                    $this->info("DialedRVMCall rvm_unsent_schedule_job timezone is not matched rvm_cdr_log table id ({$rvm_cdr->api_token},{$rvm_cdr->id})");
                }
            }

        }
        else
        {
            $this->info("DialedRVMCall rvm_unsent_schedule_job no data found ");
        }

        
    }

    public function getTimezone($numberAreacode)
    {
        $timeZone = DB::connection('master')->selectOne("SELECT timezone FROM timezone WHERE areacode = :areacode", array('areacode' => $numberAreacode));
        $timeZone = (array)$timeZone;
        return $timeZone;
    }
}
