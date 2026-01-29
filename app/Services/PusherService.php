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
        Log::info('channel',['channel'=>$request->all()]);

        try {
         $parentId = $request->auth->parent_id
            ?? $request->parent_id
            ?? $request->get('parent_id')
            ?? null;

        if (!$parentId) {
            throw new \Exception('parent_id not available for pusher notification');
        }

        $channel = 'dashboard-' . $parentId; // ✅ USE THIS
            self::instance()->trigger(
                $channel,
                'dashboard-notification',
                $data
            );
        } catch (\Exception $e) {
            Log::error('Pusher notify failed', [
                'error'   => $e->getMessage(),
                'channel' => $channel ?? null,
                'data'    => $data
            ]);
        }
    }
}
