<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class CrmSmsMessage extends Model
{
    protected $table = 'crm_sms_messages';

    protected $fillable = [
        'conversation_id',
        'direction',
        'body',
        'from_number',
        'to_number',
        'status',
        'twilio_sid',
        'sent_by',
    ];

    public const DIRECTIONS = ['inbound', 'outbound', 'system'];
    public const STATUSES   = ['pending', 'sent', 'delivered', 'failed', 'received', 'system'];
}
