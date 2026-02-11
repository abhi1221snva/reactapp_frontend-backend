<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Pusher\Pusher;

class VerifyPusher extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'verify:pusher';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify Pusher integration with hardcoded credentials';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->info('Starting Pusher Verification via cURL...');

        $app_id = "2107888";
        $app_key = "3d5c035cf6c23695ae07";
        $app_secret = "a28414392bd0b7232862";
        $app_cluster = "ap2";

        $this->info("App ID: $app_id");
        $this->info("App Key: $app_key");
        $this->info("Cluster: $app_cluster");

        $body = json_encode([
            'name' => 'test-event',
            'data' => json_encode(['message' => 'Hello from Shikha', 'time' => date('r')]),
            'channels' => ['test-channel']
        ]);

        $auth_timestamp = time();
        $auth_version = '1.0';

        $body_md5 = md5($body);
        $string_to_sign = "POST\n/apps/$app_id/events\nauth_key=$app_key&auth_timestamp=$auth_timestamp&auth_version=$auth_version&body_md5=$body_md5";
        $auth_signature = hash_hmac('sha256', $string_to_sign, $app_secret);

        $url = "https://api-$app_cluster.pusher.com/apps/$app_id/events?auth_key=$app_key&auth_timestamp=$auth_timestamp&auth_version=$auth_version&body_md5=$body_md5&auth_signature=$auth_signature";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_VERBOSE, true); // Enable verbose output for debugging

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($error) {
            $this->error("cURL Error: $error");
        } else {
            $this->info("HTTP Code: $httpCode");
            $this->info("Response: $response");
        }
    }
}
