<?php

namespace App\Model\Master\SmsAi;

use Illuminate\Database\Eloquent\Model;

class SmsAiPaymentTransaction extends Model
{
    protected $connection = 'master';
    protected $table = 'sms_ai_payment_transactions';
    public $timestamps = true;

    protected $fillable = ['id', 'order_id', 'payment_gateway_type', 'response', 'status'];

}
