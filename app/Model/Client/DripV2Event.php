<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class DripV2Event extends Model
{
    public $timestamps = false;

    protected $table = 'drip_v2_events';

    protected $fillable = [
        'send_log_id',
        'event_type',
        'event_data',
        'provider_event_id',
        'occurred_at',
    ];

    protected $casts = [
        'event_data'  => 'array',
        'occurred_at' => 'datetime',
    ];

    public function sendLog()
    {
        return $this->belongsTo(DripV2SendLog::class, 'send_log_id');
    }
}
