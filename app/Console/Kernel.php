<?php

namespace App\Console;

use App\Console\Commands\ArchiveCdr;
use App\Console\Commands\CreateDatabaseConfig;
use App\Console\Commands\MigrateAllCommand;
use App\Console\Commands\MigrateLeadDataToEav;
use App\Console\Commands\MigrateLeadVisibility;
use App\Console\Commands\MigrateNotificationsToActivity;
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
use App\Console\Commands\RvmBackfillLegacy;
use App\Console\Commands\RvmCheckLiveReady;
use App\Console\Commands\RvmShadowReport;

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
use App\Console\Commands\MissedCallNotificationCron;
use App\Console\Commands\AutoClockoutCommand;
use App\Console\Commands\ProvisionClientCommand;
use App\Console\Commands\SyncPjsipRealtimeCommand;
use App\Console\Commands\FlushUsageCounters;
use App\Console\Commands\CheckExpiredSubscriptions;
use App\Console\Commands\AssignDefaultPlans;
use App\Console\Commands\StripeSyncPlans;
use App\Console\Commands\BackfillWalletBalances;
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
        MigrateLeadDataToEav::class,
        MigrateLeadVisibility::class,
        MigrateNotificationsToActivity::class,
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
        RvmShadowReport::class,
        RvmBackfillLegacy::class,
        RvmCheckLiveReady::class,
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
        \App\Console\Commands\SendTestFcm::class,
        \App\Console\Commands\BackfillPusherUuid::class,
        \App\Console\Commands\VerifyPusher::class,
        MissedCallNotificationCron::class,
        AutoClockoutCommand::class,
        \App\Console\Commands\GenerateSwaggerDocs::class,
        ProvisionClientCommand::class,
        \App\Console\Commands\EmailParserScanCommand::class,
        \App\Console\Commands\AmiListenCommand::class,
        \App\Console\Commands\EncryptTotpSecrets::class,
        \App\Console\Commands\CleanAuthEvents::class,
        \App\Console\Commands\CleanExpiredRefreshTokens::class,
        \App\Console\Commands\RenewGmailWatchesCommand::class,
        SyncPjsipRealtimeCommand::class,
        \App\Console\Commands\RecycleQueueLeadsCommand::class,
        FlushUsageCounters::class,
        CheckExpiredSubscriptions::class,
        AssignDefaultPlans::class,
        StripeSyncPlans::class,
        BackfillWalletBalances::class,
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
        



        //$schedule->command('app:rvm:schedule-process')->everyMinute();

        //$schedule->command('app:send:rvm-send-command')->everyMinute(); //1 am UTC TImezone




        



        
        //$schedule->command('app:mc:schedule-process')->everyMinute();
        //$schedule->command('app:mc:run-process')->everyMinute();
        //$schedule->command('app:mc:schedule-status')->everyMinute();
        //$schedule->command('app:dc:schedule-process')->everyMinute();
         //$schedule->command('app:dc:run-process')->everyMinute();
         //$schedule->command('app:dc:schedule-status')->everyMinute();
        //$schedule->command('reminders:send')->everyMinute();
        $schedule->command('app:missed-call-notification')->everyMinute();
        $schedule->command('workforce:auto-clockout')->everyFifteenMinutes();

        // Replenish reserved client pool every 30 minutes
        $schedule->call(function () {
            dispatch(new \App\Jobs\ReplenishPoolJob())->onConnection('database')->onQueue('clients');
        })->everyThirtyMinutes();

        // Audit log retention: delete master.audit_log rows older than
        // 90 days. The audit_log table was activated fleet-wide in
        // Apr 2026 (prior to that its migration was Pending and the
        // AuditLogMiddleware was silently catching the missing-table
        // exception), so every admin mutation now writes a row. Keep
        // 90 days on disk; older rows are pruned daily at 03:15 UTC.
        $schedule->call(function () {
            $cutoff  = now()->subDays(90);
            $deleted = \DB::connection('master')
                ->table('audit_log')
                ->where('created_at', '<', $cutoff)
                ->delete();
            \Log::info('audit_log.retention', [
                'cutoff'  => $cutoff->toDateTimeString(),
                'deleted' => $deleted,
            ]);
        })->dailyAt('03:15')->name('audit-log-retention')->withoutOverlapping();

        // Auth events retention: prune entries older than 90 days
        $schedule->command('auth:clean-events')->dailyAt('03:30')->name('auth-events-retention')->withoutOverlapping();

        // Refresh token cleanup
        $schedule->command('auth:clean-refresh-tokens')->dailyAt('03:45')->name('refresh-token-cleanup')->withoutOverlapping();

        // Gmail push-notification watch renewal (watches expire every ~7 days)
        $schedule->command('gmail:renew-watches')->dailyAt('02:00')->name('gmail-watch-renewal')->withoutOverlapping();

        // Campaign lead queue recycle: re-queue completed/failed leads per recycle rules
        $schedule->command('dialer:recycle-queue')->everyFifteenMinutes()->name('recycle-queue-leads')->withoutOverlapping();

        // Subscription usage: flush Redis counters to DB every 5 minutes
        $schedule->command('subscription:flush-usage')->everyFiveMinutes()->name('subscription-flush-usage')->withoutOverlapping();

        // Subscription expiry: expire trials/subscriptions, set grace periods, send warnings
        $schedule->command('subscription:check-expired')->hourly()->name('subscription-check-expired')->withoutOverlapping();
    }

}
