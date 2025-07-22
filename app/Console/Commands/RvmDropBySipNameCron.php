<?php

namespace App\Console\Commands;
use Illuminate\Console\Command;
use App\Model\Master\RvmCdrLog;
use App\Model\Master\RvmQueueList;

use Illuminate\Support\Facades\DB;
use DateTime;
use DateTimeZone;
use App\Jobs\SendRvmJob;
use Carbon\Carbon;

class RvmDropBySipNameCron extends Command
{
    protected $signature = 'app:send:rvm-drop-by-sip-trunk  {--clientId=}';
    protected $description = 'Timezone Status Is not match Logs';

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

        //$date = date('Y-m-d');
        $date = Carbon::now()->subMinutes(30);

        $startDate = '2025-04-07 00:00:00';


        $apiTokens = ['bc6c','37ea9be4-azet-6087-avlg-32bb430f3929','09eabc80-qbna-8027-wwrp-5b5f92fa7412','c2b9b3a0']; //'c2b9b3a0','09eabc80-qbna-8027-wwrp-5b5f92fa7412',
      
       
       $rvm_cdr_log = RvmCdrLog::whereIn('api_token', $apiTokens)->where('timezone_status','0')->whereNull('status')->where('tries', '=', '0')->where('created_at', '>=',  $startDate)->where('created_at', '<', $date)->orderBy('id', 'desc')->limit(500)->get()->all();

        //echo "<pre>";print_r($rvm_cdr_log);die;

       if($rvm_cdr_log)
       {
        foreach($rvm_cdr_log as $rvm_cdr)
        {
            $rvm_queue_list = RvmQueueList::where('rvm_cdr_log_id',$rvm_cdr->id)->get()->first();
            //echo "<pre>";print_r($rvm_queue_list);die;

            if($rvm_queue_list)
            {
                continue;
            }

            else
            if(empty($rvm_cdr->json_data))
            {
                $rvm_cdr_log_update = RvmCdrLog::where('id',$rvm_cdr->id)->get()->first();
                $rvm_cdr_log_update['status'] ='Not Valid JSON';
                $rvm_cdr_log_update->save();
                continue;
            }

            $rvm_data = json_decode($rvm_cdr->json_data);
            $this->info("UnsentYesterdayRVMCall ({$rvm_cdr->api_token},{$rvm_cdr->id})");


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

            $rvm_data->status_code = 'rvm_timezone_zero_schedule_job';


            //echo "<pre>";print_r($rvm_data);die;
            //sleep(5);

            if($return["dialable"] == 1)
            {
                sleep(1);
                dispatch((new SendRvmJob($rvm_data))->delay(Carbon::now()->addSeconds(5))->onConnection("rvm_timezone_zero_schedule_job"));
                $this->info("RvmDropBySipNameCron rvm_timezone_zero_schedule_job timezone matched rvm_cdr_log table id ({$rvm_cdr->api_token},{$rvm_cdr->id})");
           

            }
            else
            {
                $this->info("RvmDropBySipNameCron rvm_timezone_zero_schedule_job timezone not matched rvm_cdr_log table id ({$rvm_cdr->api_token},{$rvm_cdr->id})");
            }
        }

       }

       else
        {
            $this->info("RvmDropBySipNameCron rvm_timezone_zero_schedule_job no data found ");
        }

        
    }

    public function getTimezone($numberAreacode)
    {
        $timeZone = DB::connection('master')->selectOne("SELECT timezone FROM timezone WHERE areacode = :areacode", array('areacode' => $numberAreacode));
        $timeZone = (array)$timeZone;
        return $timeZone;
    }
}
