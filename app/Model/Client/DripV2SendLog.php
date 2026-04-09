<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class DripV2SendLog extends Model
{
    public $timestamps = false;

    protected $table = 'drip_v2_send_log';

    protected $fillable = [
        'enrollment_id',
        'step_id',
        'lead_id',
        'channel',
        'to_address',
        'from_address',
        'subject',
        'body_preview',
        'provider_message_id',
        'status',
        'sent_at',
        'delivered_at',
        'opened_at',
        'clicked_at',
        'failed_at',
        'error_message',
    ];

    protected $casts = [
        'sent_at'      => 'datetime',
        'delivered_at'  => 'datetime',
        'opened_at'    => 'datetime',
        'clicked_at'   => 'datetime',
        'failed_at'    => 'datetime',
    ];

    public function enrollment()
    {
        return $this->belongsTo(DripV2Enrollment::class, 'enrollment_id');
    }

    public function step()
    {
        return $this->belongsTo(DripV2Step::class, 'step_id');
    }

    public function events()
    {
        return $this->hasMany(DripV2Event::class, 'send_log_id');
    }
}
