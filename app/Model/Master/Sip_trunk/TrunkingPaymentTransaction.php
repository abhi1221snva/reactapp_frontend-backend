<?php

namespace App\Model\Master\Sip_trunk;

use Illuminate\Database\Eloquent\Model;

class TrunkingPaymentTransaction extends Model
{
    protected $connection = 'master';
    protected $table = 'trunking_payment_transactions';
    public $timestamps = true;

    protected $fillable = ['id', 'order_id', 'payment_gateway_type', 'response', 'status'];

}
