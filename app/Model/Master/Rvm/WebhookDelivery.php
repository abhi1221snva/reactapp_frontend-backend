<?php

namespace App\Model\Master\Rvm;

use Illuminate\Database\Eloquent\Model;

class WebhookDelivery extends Model
{
    protected $connection = 'master';
    protected $table = 'rvm_webhook_deliveries';

    protected $fillable = [
        'endpoint_id', 'client_id', 'drop_id', 'event_id', 'event_type',
        'status', 'attempt', 'response_code', 'response_body',
        'next_retry_at', 'delivered_at', 'payload',
    ];

    protected $casts = [
        'payload' => 'array',
        'attempt' => 'int',
        'response_code' => 'int',
        'next_retry_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];
}
