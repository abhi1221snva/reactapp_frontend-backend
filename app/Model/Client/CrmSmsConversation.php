<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class CrmSmsConversation extends Model
{
    protected $table = 'crm_sms_conversations';

    protected $fillable = [
        'lead_id',
        'lead_phone',
        'agent_id',
        'last_message_at',
        'unread_count',
        'status',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'unread_count'    => 'integer',
    ];

    public const STATUSES = ['open', 'closed', 'archived'];
}
