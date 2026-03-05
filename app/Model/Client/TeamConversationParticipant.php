<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class TeamConversationParticipant extends Model
{
    protected $table = 'team_conversation_participants';

    public $timestamps = false;

    protected $fillable = [
        'conversation_id',
        'user_id',
        'role',
        'nickname',
        'is_muted',
        'last_read_message_id',
        'last_read_at',
        'joined_at',
        'left_at',
        'is_active'
    ];

    protected $casts = [
        'is_muted' => 'boolean',
        'is_active' => 'boolean',
        'last_read_at' => 'datetime',
        'joined_at' => 'datetime',
        'left_at' => 'datetime',
    ];

    protected $dates = [
        'last_read_at',
        'joined_at',
        'left_at'
    ];

    public function conversation()
    {
        return $this->belongsTo(TeamConversation::class, 'conversation_id');
    }

    public function lastReadMessage()
    {
        return $this->belongsTo(TeamMessage::class, 'last_read_message_id');
    }

    public function isAdmin()
    {
        return $this->role === 'admin';
    }

    public function isMember()
    {
        return $this->role === 'member';
    }

    public function markAsRead($messageId)
    {
        $this->last_read_message_id = $messageId;
        $this->last_read_at = Carbon::now();
        $this->save();
    }
}
