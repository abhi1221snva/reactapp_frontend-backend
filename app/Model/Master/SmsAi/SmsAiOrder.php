<?php

namespace App\Model\Master\SmsAi;

use Illuminate\Database\Eloquent\Model;

class SmsAiOrder extends Model
{
    protected $connection = 'master';
    protected $table = 'sms_ai_orders';
    public $timestamps = true;

    protected $fillable = ['id', 'client_id', 'net_amount', 'discount_type', 'discount_price','gross_amount','status'];

}
