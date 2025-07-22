<?php

namespace App\Model\Master\Sip_trunk;

use Illuminate\Database\Eloquent\Model;

class TrunkingOrder extends Model
{
    protected $connection = 'master';
    protected $table = 'trunking_orders';
    public $timestamps = true;

    protected $fillable = ['id', 'client_id', 'net_amount', 'discount_type', 'discount_price','gross_amount','status'];

}
