<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class MarketingCampaignRuns  extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */

    protected $table = "marketing_campaign_runs";
    public $timestamps = false;
    protected $fillable = ['id','schedule_id','lead_id','send_type', 'send_to','scheduled_time','processing_id','start_time','sent_time'];
}
