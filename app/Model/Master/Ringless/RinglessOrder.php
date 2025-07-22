<?php

namespace App\Model\Master\Ringless;

use Illuminate\Database\Eloquent\Model;

class RinglessOrder extends Model
{
    protected $connection = 'master';
    protected $table = 'ringless_orders';
    public $timestamps = true;

    protected $fillable = ['id', 'client_id', 'net_amount', 'discount_type', 'discount_price','gross_amount','status'];

}
