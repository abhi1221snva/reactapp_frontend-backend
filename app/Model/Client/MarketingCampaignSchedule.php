<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class MarketingCampaignSchedule extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */

    protected $table = "marketing_campaign_schedules";

    protected $fillable = ['id', 'campaign_id', 'list_column_name', 'list_id', 'email_setting_id', 'email_template_id', 'sms_setting_id', 'sms_template_id', 'sms_country_code', 'send', 'status', 'run_time', 'sent_count', 'created_by'];

    CONST STATUSES = [
        "unknown",
        "planned",      #1
        "processing",   #2
        "failed",       #3
        "queued",       #4
        "queued",       #4
        "executing",    #5
        "completed",    #6
        "aborted"       #7
    ];
}
