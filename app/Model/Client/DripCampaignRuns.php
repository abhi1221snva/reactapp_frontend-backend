<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class DripCampaignRuns  extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */

    protected $table = "drip_campaign_runs";
    public $timestamps = false;
    protected $fillable = ['id','schedule_id','lead_id','send_type','send_to','scheduled_time','processing_id','start_time','sent_time','currency_code','client_package_id','user_id','isFree','charge','lead_status_id','send_to','schedule','schedule_day'];
}
