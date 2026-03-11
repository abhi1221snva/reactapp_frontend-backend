<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class PlivoUsageLog extends Model
{
    protected $table   = 'plivo_usage_logs';
    protected $guarded = ['id'];

    protected $dates = ['date_from', 'date_till', 'synced_at'];
}
