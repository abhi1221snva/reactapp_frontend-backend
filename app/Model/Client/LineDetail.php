<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class LineDetail extends Model {

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
	 protected $primaryKey = 'id'; // or null
    public $timestamps = false;
    protected $table = "line_detail";
    protected $fillable = [
        'extension',
        'route',
        'type',
        'number',
        'channel',
        'start_time',
		'campaign_id',
		'lead_id'
    ];

}
