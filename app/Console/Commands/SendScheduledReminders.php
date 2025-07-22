<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Model\Client\CrmScheduledTask;
use App\Services\CrmMailService;
use Carbon\Carbon;
use App\Model\Client\EmailSetting;
use Illuminate\Support\Facades\DB;
use App\Model\User;
use App\Jobs\SendReminderEmail;


class SendScheduledReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reminders:send {--clientId=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send scheduled reminders';

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
        $clientId = $this->option('clientId');
        if ($clientId) {
            $this->info("SendReminderEmail($clientId)");
            dispatch(new SendReminderEmail($clientId))->onConnection("database");
        } 
        else {
            $clients = \App\Model\Master\Client::all();
            foreach ( $clients as $client ) {
                $this->info("SendReminderEmail({$client->id})");
                dispatch(new SendReminderEmail($client->id))->onConnection("database");
            }
        } 
    }
}
