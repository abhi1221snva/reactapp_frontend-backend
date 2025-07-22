<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\SendSmsAiChatJob;


class SmsAiChatCron extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:send:sms-ai-chat-command  {--clientId=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run this command for send the message to all existing number in the sms ai campaigns';

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
            $this->info("SendSmsAiChatJob($clientId)");
            dispatch(new SendSmsAiChatJob($clientId))->onConnection("database");
        } else {
            $clients = \App\Model\Master\Client::where('is_deleted',0)->get()->all();
            foreach ( $clients as $client ) {
                $this->info("SendSmsAiChatJob({$client->id})");
                dispatch(new SendSmsAiChatJob($client->id))->onConnection("database");
            }
        }
    }
}
