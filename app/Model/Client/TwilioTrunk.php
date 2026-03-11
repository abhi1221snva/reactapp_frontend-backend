<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class TwilioTrunk extends Model
{
    protected $table   = 'twilio_trunks';
    protected $guarded = ['id'];

    protected $casts = [
        'ip_acl' => 'array',
    ];
}
