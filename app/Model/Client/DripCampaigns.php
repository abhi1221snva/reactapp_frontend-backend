<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class DripCampaigns  extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */

    protected $table = "drip_campaigns";

    protected $fillable = ['title', 'description','status'];
    public function schedule()
{
    return $this->hasMany(DripCampaignSchedule::class, 'campaign_id', 'id');
}

}
