<?php

namespace App\Console\Commands;

use App\Jobs\RvmSchedulesProcess;
use Illuminate\Console\Command;
use App\Model\Master\RvmCdrLog;
use Illuminate\Support\Facades\DB;
use DateTime;
use DateTimeZone;


class RinglessVoicemailRunProcess extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:rvm:schedule-process {--clientId=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send RVM for all clients';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $clientId ='bc6c';

        $startTime = '08:00:00';
        $endTime = '21:00:00';
        $requestData = array();
        $clientKey =array();

       // $client = Client::where('rvm_status','1')->get()->all();

       


           
            $date = date('Y-m-d');
        echo "S";die;

            $rvm_cdr_log = RvmCdrLog::where('api_token', 'bc6c')->where('timezone_status','1')->whereNull('status')->where('created_at', 'LIKE', $date . '%')->orderBy('id', 'desc')->limit(10)->get()->all();

           //echo "<pre>";print_r($rvm_cdr_log);die;

           /* $rvm_cdr_log = RvmCdrLog::where('api_token', 'bc6c')->where('timezone_status','1')->whereNull('status')->where('created_at', '<', $date)->orderBy('id', 'desc')->limit(10)->get()->all();*/


            //echo "<pre>";print_r($rvm_cdr_log);die;

            foreach($rvm_cdr_log as $rvm_cdr)
            {
                $rvm_data = json_decode($rvm_cdr->json_data);
              //  $this->info("RvmDropBySipNameCron({$rvm_data->apiToken})");

                $requestData['phone'] = $rvm_data->phone;
                $requestData['cli']   = $rvm_data->cli;
                $requestData['api_key'] = $rvm_data->api_key;
                $requestData['voicemail_id'] = $rvm_data->voicemail_id;
                $requestData['voicemail_url'] = $rvm_data->voicemail_url;
                $requestData['callback_url'] = $rvm_data->callback_url;
                $requestData['apiToken'] = $rvm_data->apiToken;
                $requestData['rvm_domain_id'] = $rvm_data->rvm_domain_id;
                $requestData['start_time'] = $rvm_data->start_time;
                $requestData['end_time'] = $rvm_data->end_time;
                $requestData['voicemail_file_name'] = $rvm_data->voicemail_file_name;
                $requestData['user_id'] = $rvm_data->user_id;
                $requestData['sip_gateway_id'] = $rvm_data->sip_gateway_id;
                $requestData['sip_trunk_name'] = $rvm_data->sip_trunk_name;
                $requestData['sip_trunk_host'] = $rvm_data->sip_trunk_host;
                $requestData['sip_trunk_username'] = $rvm_data->sip_trunk_username;
                $requestData['sip_trunk_password'] = $rvm_data->sip_trunk_password;
                $requestData['client_name'] = $rvm_data->client_name;
                $requestData['sip_trunk_provider'] = $rvm_data->sip_trunk_provider;
                $requestData['rvm_cdr_log_id'] = $rvm_cdr->id;

                //echo "<pre>";print_r($requestData);die;

                $number = preg_replace('/[^0-9]/', '', $requestData['phone']);
                $last10Digit = substr($number, -10);
                $return = ["dialable" => 0,"areacodeTimeZone" => 0,"dialingTime" => 0];
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

                        if (strtotime($startTime) < strtotime($currentTime) && strtotime($endTime) > strtotime($currentTime)) 
                        {
                            $return["dialingTime"] = 1;
                            $return["dialable"] = 1;
                        }
                    }
                }

                // echo "<pre>";print_r($requestData);die;

               // sleep(1);

                if($return["dialable"] == 0)
                {
                    // Set your Asterisk AMI credentials
        dispatch(new RvmSchedulesProcess($clientId,$requestData))->onConnection("database");
    }
    }


    }
     public function getTimezone($numberAreacode)
    {
        $timeZone = DB::connection('master')->selectOne("SELECT timezone FROM timezone WHERE areacode = :areacode", array('areacode' => $numberAreacode));
        $timeZone = (array)$timeZone;
        return $timeZone;
    }
}
