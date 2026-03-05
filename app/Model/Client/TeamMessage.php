<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class TeamMessage extends Model
{
    protected $table = 'team_messages';

    protected $fillable = [
        'uuid',
        'conversation_id',
        'sender_id',
        'message_type',
        'body',
        'metadata',
        'is_edited',
        'edited_at',
        'is_deleted',
        'deleted_at'
    ];

    protected $casts = [
        'metadata' => 'array',
        'is_edited' => 'boolean',
        'is_deleted' => 'boolean',
        'edited_at' => 'datetime',
        'deleted_at' => 'datetime',
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

    public function conversation()
    {
        return $this->belongsTo(TeamConversation::class, 'conversation_id');
    }

    public function attachments()
    {
        return $this->hasMany(TeamMessageAttachment::class, 'message_id');
    }

    public function readReceipts()
    {
        return $this->hasMany(TeamMessageReadReceipt::class, 'message_id');
    }

    public function isReadBy($userId)
    {
        return $this->readReceipts()->where('user_id', $userId)->exists();
    }

    public function markAsReadBy($userId)
    {
        if (!$this->isReadBy($userId)) {
            $this->readReceipts()->create([
                'user_id' => $userId,
                'read_at' => Carbon::now()
            ]);
        }
    }

    public function isText()
    {
        return $this->message_type === 'text';
    }

    public function isImage()
    {
        return $this->message_type === 'image';
    }

    public function isFile()
    {
        return $this->message_type === 'file';
    }

    public function isSystem()
    {
        return $this->message_type === 'system';
    }

    public function softDelete()
    {
        $this->is_deleted = true;
        $this->deleted_at = Carbon::now();
        $this->save();
    }

    public function edit($newBody)
    {
        $this->body = $newBody;
        $this->is_edited = true;
        $this->edited_at = Carbon::now();
        $this->save();
    }
}
