<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\SendRvmJob;
use Carbon\Carbon;
use App\Model\Master\RvmCdrLog;




class RvmSendCron extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:send:rvm-send-command  {--clientId=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run this command for send the message to all existing number in the RVM';

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
        die;
        $rvm_cdr_log = RvmCdrLog::where('timezone_status','0')->whereNull('status')->orderByDesc('id')->limit(5)->get()->all();

        //echo "<pre>";print_r($rvm_cdr_log);die;


        //die;

        
        foreach ($rvm_cdr_log as $data) {

                $rvm_data = json_decode($data->json_data);
                $rvm_data->id = $data->id;


        //echo "<pre>";print_r($rvm_data);die;



        $rvm_cdr_log = RvmCdrLog::where('id',$data->id)->get()->first();
        $rvm_cdr_log['tries'] =1;
        $rvm_cdr_log->save();

           // dispatch((new SendRvmJob($rvm_data))->delay(Carbon::now()->addSeconds(5))->onConnection("database"));
        }
    }
}
