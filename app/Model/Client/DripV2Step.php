<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class DripV2Step extends Model
{
    protected $table = 'drip_v2_steps';

    protected $fillable = [
        'campaign_id',
        'position',
        'channel',
        'delay_value',
        'delay_unit',
        'send_at_time',
        'subject',
        'body_html',
        'body_plain',
        'email_template_id',
        'sms_template_id',
        'is_active',
    ];

    protected $casts = [
        'position'    => 'integer',
        'delay_value' => 'integer',
        'is_active'   => 'boolean',
    ];

    public function campaign()
    {
        return $this->belongsTo(DripV2Campaign::class, 'campaign_id');
    }

    /**
     * Calculate delay in seconds for scheduling.
     */
    public function delayInSeconds(): int
    {
        return match ($this->delay_unit) {
            'minutes' => $this->delay_value * 60,
            'hours'   => $this->delay_value * 3600,
            'days'    => $this->delay_value * 86400,
            default   => $this->delay_value * 3600,
        };
    }
}
