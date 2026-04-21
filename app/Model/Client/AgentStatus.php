<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class AgentStatus extends Model
{
    protected $table = 'agent_statuses';

    protected $fillable = [
        'user_id',
        'status',
        'campaign_id',
        'last_updated_at',
    ];

    protected $casts = [
        'last_updated_at' => 'datetime',
    ];

    const AVAILABLE       = 'available';
    const ON_CALL         = 'on_call';
    const ON_BREAK        = 'on_break';
    const AFTER_CALL_WORK = 'after_call_work';
    const OFFLINE         = 'offline';

    const STATUS_LABELS = [
        'available'       => 'Available',
        'on_call'         => 'On Call',
        'on_break'        => 'On Break',
        'after_call_work' => 'After Call Work',
        'offline'         => 'Offline',
    ];

    /**
     * Upsert agent dialer status and broadcast via Pusher to workforce channel.
     */
    public static function setStatus(int $userId, string $status, ?int $campaignId = null, ?int $parentId = null, ?string $dbConnection = null): self
    {
        $query = $dbConnection ? static::on($dbConnection) : static::query();
        $record = $query->firstOrNew(['user_id' => $userId]);
        $record->status          = $status;
        if ($campaignId !== null) {
            $record->campaign_id = $campaignId;
        }
        if ($status === self::OFFLINE) {
            $record->campaign_id = null;
        }
        $record->last_updated_at = Carbon::now();
        $record->save();

        // Broadcast real-time update to supervisor workforce dashboard
        if ($parentId) {
            try {
                $pusher = new \Pusher\Pusher(
                    env('PUSHER_APP_KEY'),
                    env('PUSHER_APP_SECRET'),
                    env('PUSHER_APP_ID'),
                    ['cluster' => env('PUSHER_APP_CLUSTER'), 'useTLS' => true]
                );
                $pusher->trigger(
                    'workforce-' . $parentId,
                    'agent-status-update',
                    [
                        'user_id'     => $userId,
                        'status'      => $status,
                        'campaign_id' => $record->campaign_id,
                        'updated_at'  => $record->last_updated_at->toIso8601String(),
                    ]
                );
            } catch (\Exception $e) {
                Log::warning('Workforce Pusher broadcast error: ' . $e->getMessage());
            }
        }

        return $record;
    }
}
