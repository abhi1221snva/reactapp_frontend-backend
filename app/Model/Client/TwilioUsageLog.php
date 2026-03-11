<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class TwilioUsageLog extends Model
{
    protected $table   = 'twilio_usage_logs';
    protected $guarded = ['id'];

    protected $dates = ['start_date', 'end_date', 'synced_at'];
}
