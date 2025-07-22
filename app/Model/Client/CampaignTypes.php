<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class CampaignTypes extends Model
{
        public $timestamps = false;

    protected $table = 'campaign_types';
    protected $fillable = ['id','title','title_url','status'];

}
