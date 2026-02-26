<?php

namespace App\Services;

use Pusher\Pusher;
use Illuminate\Support\Facades\Log;

class PusherService
{
    public static function instance()
    {
        return new Pusher(
            env('PUSHER_APP_KEY'),
            env('PUSHER_APP_SECRET'),
            env('PUSHER_APP_ID'),
            [
                'cluster' => env('PUSHER_APP_CLUSTER'),
                'useTLS' => true
            ]
        );
    }

    public static function notify($request, array $data)
    {
        // Credentials verified via verify:pusher command
        $app_id = "2107888";
        $app_key = "3d5c035cf6c23695ae07";
        $app_secret = "a28414392bd0b7232862";
        $app_cluster = "ap2";

        // Determine Parent ID
        $parentId = $request->auth->parent_id
            ?? ($request->parent_id ?? ($request->get('parent_id') ?? null));

        if (!$parentId) {
            Log::warning('Pusher notification skipped: parent_id is missing.');
            return;
        }

        $channel = 'dashboard-' . $parentId;

        // Determine event name based on UUID availability
        $uuid = $request->pusher_uuid 
            ?? ($request->auth->pusher_uuid ?? ($request->get('pusher_uuid') ?? null));
            
        $event = $uuid ? 'dashboard-notification' . $uuid : 'dashboard-notification';

        // cURL Implementation
        $body = json_encode([
            'name' => $event,
            'data' => json_encode($data),
            'channels' => [$channel]
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
        
        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response);
    }
}
