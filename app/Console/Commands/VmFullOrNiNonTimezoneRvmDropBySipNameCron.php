<?php

namespace App\Console\Commands;
use Illuminate\Console\Command;
use App\Model\Master\RvmCdrLog;
use Illuminate\Support\Facades\DB;
use DateTime;
use DateTimeZone;
use Carbon\Carbon;
use App\Model\Master\Client;
use App\Jobs\SendRvmJob;
use App\Model\Master\RvmQueueList;
use App\Model\Master\RvmDomainList;


class VmFullOrNiNonTimezoneRvmDropBySipNameCron extends Command
{
    protected $signature = 'app:send:vm-full-or-ni-non-timezone-rvm-drop-by-sip-trunk  {--clientId=}';
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

        $date = Carbon::now()->subMinutes(30);
        $rvm_cdr_log = RvmCdrLog::where('api_token', 'bc6c')->where('voicemail_drop_log_id','1')->where('status', '=', 'VM-FULL-OR-NI')->where('updated_at', '<', $date)->orderBy('id', 'desc')->limit(100)->get()->all();

        //echo "<pre>";print_r($rvm_cdr_log);die;

        if($rvm_cdr_log)
        {
            foreach($rvm_cdr_log as $rvm_cdr)
            {
                $tries = $rvm_cdr->tries;
                $domain_list_id = $rvm_cdr->rvm_domain_id;
                $userID = $rvm_cdr->user_id;
                $voicemail_id = $rvm_cdr->voicemail_id;



                if($tries > 0)
                {
                    $domain_list = RvmDomainList::where('id',$domain_list_id)->get()->first();

                    if(isset($domain_list->callback_url))
                    {
                        $domain_url = $domain_list->callback_url;
                    }

                    $curl = curl_init();
                    $url = $domain_url."?userID=".$userID."&voicemail_id=".$voicemail_id."&status=VM-FULL-OR-NI";

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

                    $rvm_cdr['voicemail_drop_log_id'] ='2';
                    $rvm_cdr->save();
                    continue;
                }

               
            }

        }
        else
        {
            $this->info("VM FULL OR NI rvm_unsent_schedule_job no data found ");
        }
    }
}
