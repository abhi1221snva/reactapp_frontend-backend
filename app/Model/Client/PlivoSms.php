<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class PlivoSms extends Model
{
    protected $table   = 'plivo_sms';
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
