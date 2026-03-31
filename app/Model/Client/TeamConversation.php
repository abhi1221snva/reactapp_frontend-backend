<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class TeamConversation extends Model
{
    protected $table = 'team_conversations';

    protected $fillable = [
        'uuid',
        'type',
        'name',
        'avatar',
        'created_by',
        'is_active',
        'is_system',
        'system_slug',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_system' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function participants()
    {
        return $this->hasMany(TeamConversationParticipant::class, 'conversation_id');
    }

    public function activeParticipants()
    {
        return $this->hasMany(TeamConversationParticipant::class, 'conversation_id')
                    ->where('is_active', true);
    }

    public function messages()
    {
        return $this->hasMany(TeamMessage::class, 'conversation_id');
    }

    public function lastMessage()
    {
        return $this->hasOne(TeamMessage::class, 'conversation_id')
                    ->where('is_deleted', false)
                    ->latest();
    }

    public function unreadMessagesCount($userId)
    {
        $participant = $this->participants()->where('user_id', $userId)->first();

        if (!$participant || !$participant->last_read_message_id) {
            return $this->messages()
                        ->where('is_deleted', false)
                        ->where('sender_id', '!=', $userId)
                        ->count();
        }

        return $this->messages()
                    ->where('is_deleted', false)
                    ->where('id', '>', $participant->last_read_message_id)
                    ->where('sender_id', '!=', $userId)
                    ->count();
    }

    public function isParticipant($userId)
    {
        return $this->participants()
                    ->where('user_id', $userId)
                    ->where('is_active', true)
                    ->exists();
    }

    public function isDirect()
    {
        return $this->type === 'direct';
    }

    public function isGroup()
    {
        return $this->type === 'group';
    }

    public function isSystem()
    {
        return (bool) $this->is_system;
    }
}
