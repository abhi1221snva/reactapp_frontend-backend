<?php

namespace App\Model\Master;

use Illuminate\Database\Eloquent\Model;

class ClientUsageMonthly extends Model
{
    protected $connection = 'master';
    protected $table = 'client_usage_monthly';

    protected $fillable = [
        'client_id',
        'year_month',
        'calls_count',
        'sms_count',
        'agents_peak',
    ];

    protected $casts = [
        'client_id'   => 'integer',
        'calls_count' => 'integer',
        'sms_count'   => 'integer',
        'agents_peak' => 'integer',
    ];
}
