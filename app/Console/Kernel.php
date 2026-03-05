<?php

namespace App\Console;

use App\Console\Commands\ArchiveCdr;
use App\Console\Commands\CreateDatabaseConfig;
use App\Console\Commands\MigrateAllCommand;
use App\Console\Commands\ResetUserPackageFreeCounter;
use App\Console\Commands\RollbackClientMigrationCommand;
use App\Console\Commands\ScheduleDailyCallReport;
use App\Console\Commands\RvmDailyCallReport;
use App\Console\Commands\PredictiveCallCron;
use App\Console\Commands\OutboundUpdateCron;
use App\Console\Commands\CnamReportCron;
use App\Console\Commands\PredictiveDialCallDropCron;
use App\Console\Commands\TruncateLeadTemp;
use App\Console\Commands\MarketingCampaignScheduleProcess;
use App\Console\Commands\MarketingCampaignScheduleStatus;
use App\Console\Commands\MarketingCampaignRunProcess;
use App\Console\Commands\CheckFaxStatusCommand;
use App\Console\Commands\SmsAiChatCron;
use App\Console\Commands\RvmSendCron;

use App\Console\Commands\RinglessVoicemailCron;
use App\Console\Commands\RvmDropBySipNameCron;
use App\Console\Commands\UnsentYesterdayRvmDropBySipNameCron;
use App\Console\Commands\DialedRvmDropBySipNameCron;
use App\Console\Commands\InitiatedNonTimezoneRvmDropBySipNameCron;
use App\Console\Commands\VmFullOrNiNonTimezoneRvmDropBySipNameCron;


use App\Console\Commands\RinglessVoicemailRunProcess;
use App\Console\Commands\DripCampaignRunProcess;
use App\Console\Commands\DripCampaignScheduleStatus;
use App\Console\Commands\DripCampaignScheduleProcess;
use App\Console\Commands\SendScheduledReminders;
use App\Console\Commands\RenewGmailWatchesCommand;
use App\Console\Commands\CheckGmailEmailsCommand;
use Illuminate\Console\Scheduling\Schedule;
use Laravel\Lumen\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        MigrateAllCommand::class,
        RollbackClientMigrationCommand::class,
        CreateDatabaseConfig::class,
        TruncateLeadTemp::class,
        ArchiveCdr::class,
        ScheduleDailyCallReport::class,
        RvmDailyCallReport::class,
        MarketingCampaignScheduleProcess::class,
        MarketingCampaignScheduleStatus::class,
        MarketingCampaignRunProcess::class,
        ResetUserPackageFreeCounter::class,
        CheckFaxStatusCommand::class,
        PredictiveCallCron::class,
        OutboundUpdateCron::class,
        CnamReportCron::class,
        PredictiveDialCallDropCron::class,
        SmsAiChatCron::class,
        RvmSendCron::class,
        RinglessVoicemailCron::class,
        RvmDropBySipNameCron::class,
        UnsentYesterdayRvmDropBySipNameCron::class,
        DialedRvmDropBySipNameCron::class,
        InitiatedNonTimezoneRvmDropBySipNameCron::class,
        VmFullOrNiNonTimezoneRvmDropBySipNameCron::class,


        RinglessVoicemailRunProcess::class,
        DripCampaignScheduleProcess::class,
        DripCampaignScheduleStatus::class,
        DripCampaignRunProcess::class,
        SendScheduledReminders::class,
        RenewGmailWatchesCommand::class,
        CheckGmailEmailsCommand::class,

    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        $schedule->command('app:hopper:clean')->dailyAt('01:00'); // running scheduler to clean data from hopper
        //$schedule->command('app:archive:cdr')->dailyAt('01:10'); // 6:00 am utc// running scheduler to clean data from hopper
        $schedule->command('app:archive:cdr')->dailyAt('06:00'); // 6:00 am utc// running scheduler to clean data from hopper

        //$schedule->command('app:send:daily-call-report')->dailyAt('22:00'); // 3 am utc
        $schedule->command('app:send:daily-call-report')->dailyAt('04:30'); // 3 am utc
        $schedule->command('app:send:rvm-call-report')->dailyAt('03:00'); // 3 am utc


        //$schedule->command('app:reset-user-package')->dailyAt('23:00'); //every day at 11pm
        $schedule->command('app:reset-user-package')->dailyAt('04:00'); //every day at 11pm

        $schedule->command('app:send:predictive-call-dialer')->everyMinute(); //every day at 11pm
        $schedule->command('app:send:predictive-dial-call-drop')->everyThirtyMinutes(); //every 30 minutes

        $schedule->command('outbound:cron')->everyMinute(); //every 30 minutes

        //$schedule->command('cnam:cron')->dailyAt('01:00'); //1 am UTC TImezone

        //$schedule->command('app:send:sms-ai-chat-command')->everyMinute(); //1 am UTC TImezone

        $schedule->command('app:send:ringless-voicemail-command')->everyMinute(); //1 am UTC TImezone

        $schedule->command('app:send:rvm-drop-by-sip-trunk')->everyMinute(); //1 am UTC TImezone

        $schedule->command('app:send:unsent-yesterday-rvm-drop-by-sip-trunk')->everyMinute(); //1 am UTC TImezone

        $schedule->command('app:send:dialed-rvm-drop-by-sip-trunk')->everyFiveMinutes(); //1 am UTC TImezone

        $schedule->command('app:send:initiated-non-timezone-rvm-drop-by-sip-trunk')->everyFiveMinutes(); //1 am UTC TImezone

        $schedule->command('app:send:vm-full-or-ni-non-timezone-rvm-drop-by-sip-trunk')->everyThirtyMinutes(); //1 am UTC TImezone

        // Gmail watch renewal - watches expire after 7 days, run daily to keep them active
        $schedule->command('gmail:renew-watches')->dailyAt('02:00');

        //$schedule->command('app:rvm:schedule-process')->everyMinute();

        //$schedule->command('app:send:rvm-send-command')->everyMinute(); //1 am UTC TImezone




        



        
        //$schedule->command('app:mc:schedule-process')->everyMinute();
        //$schedule->command('app:mc:run-process')->everyMinute();
        //$schedule->command('app:mc:schedule-status')->everyMinute();
        //$schedule->command('app:dc:schedule-process')->everyMinute();
         //$schedule->command('app:dc:run-process')->everyMinute();
         //$schedule->command('app:dc:schedule-status')->everyMinute();
        //$schedule->command('reminders:send')->everyMinute();
    }

}
