<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class MarketingCampaign  extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */

    protected $table = "marketing_campaigns";

    protected $fillable = ['title', 'description','subject','status'];
}
