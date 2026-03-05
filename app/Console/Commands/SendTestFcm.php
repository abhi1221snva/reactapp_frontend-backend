<?php

namespace App\Console\Commands;

use App\Services\FirebaseService;
use Illuminate\Console\Command;

class SendTestFcm extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fcm:test {token?} {title?} {body?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send a test FCM notification to a specific token';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $token = $this->argument('token');

        // Hardcoded token fallback
        if (!$token) {
            $token = "fJsmRa6dQyygjSguxvwYgr:APA91bEDuBjLvovBrtq301fCiR1ihx0bXlsOeq8nODujBzBPFNdwcv_CueMiRrJeyQpQZ8UT0XDgOfe47X0CHsR3CystM-rNSG0tStaxyVxTNzqLdHiTr1k";
        }
        $title = $this->argument('title') ?? 'Test Notification';
        $body = $this->argument('body') ?? 'This is a test notification from Dialer Backend';

        $this->info("Sending test FCM to: {$token}");

        $result = FirebaseService::sendNotification([$token], $title, $body, [
            'test' => 'true',
            'sent_at' => date('Y-m-d H:i:s')
        ]);

        if ($result === false) {
            $this->error("Failed to send notification. Check logs for details.");
            return;
        }

        $this->info("Result: " . json_encode($result, JSON_PRETTY_PRINT));
    }
}
