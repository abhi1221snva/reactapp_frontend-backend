<?php

namespace App\Model\Client\SmsAi;
use Illuminate\Database\Eloquent\Model;

class SmsAiWalletTransaction extends Model
{
    public $timestamps = true;
    protected $table = "sms_ai_wallet_transactions";
    protected $fillable = ['id','currency_code','amount','transaction_type','transaction_reference','description'];

}
