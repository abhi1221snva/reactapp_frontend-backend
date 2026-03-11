<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class TwilioRecording extends Model
{
    protected $table   = 'twilio_recordings';
    protected $guarded = ['id'];

    protected $dates = ['recorded_at'];
}
