<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class DripV2Campaign extends Model
{
    protected $table = 'drip_v2_campaigns';

    protected $fillable = [
        'name',
        'description',
        'status',
        'channel',
        'email_setting_id',
        'sms_from_number',
        'entry_conditions',
        'exit_conditions',
        'trigger_rules',
        'quiet_hours_start',
        'quiet_hours_end',
        'quiet_hours_tz',
        'created_by',
        'updated_by',
        'activated_at',
        'archived_at',
    ];

    protected $casts = [
        'entry_conditions' => 'array',
        'exit_conditions'  => 'array',
        'trigger_rules'    => 'array',
        'activated_at'     => 'datetime',
        'archived_at'      => 'datetime',
    ];

    public function steps()
    {
        return $this->hasMany(DripV2Step::class, 'campaign_id')->orderBy('position');
    }

    public function enrollments()
    {
        return $this->hasMany(DripV2Enrollment::class, 'campaign_id');
    }

    public function emailSetting()
    {
        return $this->belongsTo(EmailSetting::class, 'email_setting_id');
    }
}
