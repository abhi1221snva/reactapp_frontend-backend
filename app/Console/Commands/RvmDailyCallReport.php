<?php

namespace App\Console\Commands;

use App\Model\Master\RvmCdrLog;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Model\Client\SmtpSetting;

use App\Mail\SystemNotificationMail;


use App\Services\MailService;


class RvmDailyCallReport extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:send:rvm-call-report  {--clientId=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send daily RVM report for all clients';

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
        $previousDate = date('Y-m-d', strtotime('-1 day'));
        $data = DB::table('rvm_cdr_log')->select(DB::raw('count(*) as count, status'))->where('api_token','bc6c')->where('created_at', 'like', '%'.$previousDate.'%')->groupBy('status')->get()->toArray();

        $smtpSetting = new SmtpSetting;
                $smtpSetting->mail_driver = "SMTP";
                $smtpSetting->mail_host = env("PORTAL_MAIL_HOST");
                $smtpSetting->mail_port = env("PORTAL_MAIL_PORT");
                $smtpSetting->mail_username = env("PORTAL_MAIL_USERNAME");
                $smtpSetting->mail_password = env("PORTAL_MAIL_PASSWORD");
                $smtpSetting->from_name = env("PORTAL_MAIL_SENDER_NAME");
                $smtpSetting->from_email = env("PORTAL_MAIL_SENDER_EMAIL");
                $smtpSetting->mail_encryption = env("PORTAL_MAIL_ENCRYPTION");
                $from = [
                    "address" => empty($smtpSetting->from_email) ? env('DEFAULT_EMAIL') : $smtpSetting->from_email,
                    "name" => empty($smtpSetting->from_name) ? env('DEFAULT_NAME') : $smtpSetting->from_name,
                ];

                $view = "emails.RvmCallReport.v1";

                        //echo "<pre>";print_r($data);die;


                $this->clientId =3;
                $subject = 'RVM Call Report '.$previousDate;
                 #create initiate mailable class
                        $mailable = new SystemNotificationMail($from, $view, $subject, $data);

                        $mailService = new MailService($this->clientId, $mailable, $smtpSetting);
                        $mailService->sendEmail('mailme@rohitwanchoo.com');



                        echo "sent";die;

                        echo "<pre>";print_r($smtpSetting);die;
    }
}
