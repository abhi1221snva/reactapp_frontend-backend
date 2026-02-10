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

        /*
         * --------------------------
         * GET PUSHER UUID
         * --------------------------
         */
        $pusherUuid = null;
        if (isset($request->auth->pusher_uuid)) {
            $pusherUuid = $request->auth->pusher_uuid;
        } elseif (isset($request->pusher_uuid)) {
            $pusherUuid = $request->pusher_uuid;
        } elseif ($request->get('pusher_uuid')) {
            $pusherUuid = $request->get('pusher_uuid');
        }

        $channel = 'dashboard-' . $parentId; // ✅ USE THIS

        // Append UUID if available
        $eventName = 'dashboard-notification';
        if (!empty($pusherUuid)) {
            $eventName .= $pusherUuid;
        }

            self::instance()->trigger(
                $channel,
                $eventName,
                $data
            );
        } catch (\Throwable $e) {
            Log::error('Pusher notify failed', [
                'error'   => $e->getMessage(),
                'channel' => $channel ?? null,
                'data'    => $data
            ]);
        }
    }
}
