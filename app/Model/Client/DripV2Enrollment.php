<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class DripV2Enrollment extends Model
{
    protected $table = 'drip_v2_enrollments';

    protected $fillable = [
        'campaign_id',
        'lead_id',
        'current_step_id',
        'status',
        'enrolled_by',
        'enrolled_via',
        'trigger_rule',
        'next_send_at',
        'stopped_reason',
        'completed_at',
        'stopped_at',
    ];

    protected $casts = [
        'next_send_at'  => 'datetime',
        'completed_at'  => 'datetime',
        'stopped_at'    => 'datetime',
    ];

    public function campaign()
    {
        return $this->belongsTo(DripV2Campaign::class, 'campaign_id');
    }

    public function currentStep()
    {
        return $this->belongsTo(DripV2Step::class, 'current_step_id');
    }

    public function sendLogs()
    {
        return $this->hasMany(DripV2SendLog::class, 'enrollment_id');
    }
}
