<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class TwilioSms extends Model
{
    protected $table   = 'twilio_sms';
    protected $guarded = ['id'];

    protected $dates = ['sent_at'];

    public function scopeInbound($query)
    {
        return $query->where('direction', 'inbound');
    }

    public function scopeOutbound($query)
    {
        return $query->where('direction', 'outbound');
    }
}
