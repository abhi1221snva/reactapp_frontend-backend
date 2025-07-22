<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class CrmLog extends Model {

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;
    protected $table = "crm_log";
    protected $fillable = [
        'lead_id',
        'campaign_id',
        'type',
        'url',
        'crm_data',
        'phone'
    ];

}
