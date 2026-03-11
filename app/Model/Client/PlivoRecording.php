<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class PlivoRecording extends Model
{
    protected $table   = 'plivo_recordings';
    protected $guarded = ['id'];

    protected $dates = ['add_time'];
}
