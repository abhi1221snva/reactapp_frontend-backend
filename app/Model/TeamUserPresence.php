<?php

namespace App\Model;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class TeamUserPresence extends Model
{
    protected $connection = 'master';

    protected $table = 'team_user_presence';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'status',
        'last_seen_at',
        'current_conversation_uuid',
        'updated_at'
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $dates = [
        'last_seen_at',
        'updated_at'
    ];

    const STATUS_ONLINE = 'online';
    const STATUS_AWAY = 'away';
    const STATUS_BUSY = 'busy';
    const STATUS_OFFLINE = 'offline';

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isOnline()
    {
        return $this->status === self::STATUS_ONLINE;
    }

    public function isOffline()
    {
        return $this->status === self::STATUS_OFFLINE;
    }

    public function setOnline($conversationUuid = null)
    {
        $this->status = self::STATUS_ONLINE;
        $this->last_seen_at = Carbon::now();
        $this->current_conversation_uuid = $conversationUuid;
        $this->save();
    }

    public function setOffline()
    {
        $this->status = self::STATUS_OFFLINE;
        $this->last_seen_at = Carbon::now();
        $this->current_conversation_uuid = null;
        $this->save();
    }

    public function setAway()
    {
        $this->status = self::STATUS_AWAY;
        $this->last_seen_at = Carbon::now();
        $this->save();
    }

    public function setBusy()
    {
        $this->status = self::STATUS_BUSY;
        $this->save();
    }

    public static function updateUserPresence($userId, $status, $conversationUuid = null)
    {
        return static::updateOrCreate(
            ['user_id' => $userId],
            [
                'status' => $status,
                'last_seen_at' => Carbon::now(),
                'current_conversation_uuid' => $conversationUuid
            ]
        );
    }

    public static function getOnlineUsers($userIds)
    {
        return static::whereIn('user_id', $userIds)
                     ->where('status', '!=', self::STATUS_OFFLINE)
                     ->get();
    }
}
