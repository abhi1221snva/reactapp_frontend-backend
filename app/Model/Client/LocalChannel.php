<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class LocalChannel extends Model {

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
	 protected $primaryKey = 'confno'; // or null
    public $timestamps = false;
    protected $table = "local_channel1";
    protected $fillable = [
        'confno',
        'local_channel',
        'campaign_id',
        'lead_id'
    ];

}
