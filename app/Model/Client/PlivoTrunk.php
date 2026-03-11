<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;

class PlivoTrunk extends Model
{
    protected $table   = 'plivo_trunks';
    protected $guarded = ['id'];

    protected $casts = [
        'ip_acl' => 'array',
    ];
}
