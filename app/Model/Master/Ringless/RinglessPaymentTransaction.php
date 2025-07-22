<?php

namespace App\Model\Master\Ringless;

use Illuminate\Database\Eloquent\Model;

class RinglessPaymentTransaction extends Model
{
    protected $connection = 'master';
    protected $table = 'ringless_payment_transactions';
    public $timestamps = true;

    protected $fillable = ['id', 'order_id', 'payment_gateway_type', 'response', 'status'];

}
