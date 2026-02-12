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
        // Prioritize: 1. $request->pusher_uuid (explicit) 2. $request->auth->pusher_uuid (authenticated user)
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
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $httpCode !== 200) {
            Log::error('PusherService cURL Error', [
                'error' => $error,
                'http_code' => $httpCode,
                'response' => $response,
                'data' => $data
            ]);
            // Optional: throw exception or just log it
        }

        // ==========================================
        // 💾 PERSIST NOTIFICATION TO DATABASE
        // ==========================================
        try {
            $notification = new \App\Model\Client\Notification();
            $notification->setConnection("mysql_$parentId");
            
            // Determine User ID (logic similar to uuid)
            // If request has auth user, use it. usage of 'pusher_uuid' might imply specific user targeting too.
            // For now, if auth exists, use it. Else null (global).
            $userId = $request->auth->id ?? ($request->user_id ?? null);

            $notification->user_id = $userId;
            $notification->lead_id = null; // Generic notification
            $notification->title   = $data['name'] ?? 'Notification';
            $notification->message = $data['message'] ?? '';
            $notification->type    = '0'; // Default to '0' (updates) as per enum constraint. Real type is in data.
            $notification->data    = $data; // Auto-cast to JSON if model casts set, else needs json_encode. 
                                            // Model doesn't have casts for 'data' yet (I added column but didn't check model casts).
                                            // Better to pass array and let Eloquent handle cast IF I add it to model, 
                                            // OR json_encode here if I didn't. 
                                            // I didn't add cast to model in Step 562. I should probably do that or just json_encode here.
                                            // Let's json_encode here to be safe across generic model usage.
            if (is_array($data)) {
                 $notification->data = json_encode($data);
            } else {
                 $notification->data = $data;
            }

            $notification->save();
        } catch (\Throwable $e) {
            Log::error('Failed to persist Pusher notification', [
                'error' => $e->getMessage(),
                'parentId' => $parentId,
                'data' => $data
            ]);
        }

        return json_decode($response);
    }
}
