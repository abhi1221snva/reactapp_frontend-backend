<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class DripV2Unsubscribe extends Model
{
    public $timestamps = false;

    protected $table = 'drip_v2_unsubscribes';

    protected $fillable = [
        'lead_id',
        'email',
        'phone',
        'channel',
        'reason',
        'source',
    ];
}
