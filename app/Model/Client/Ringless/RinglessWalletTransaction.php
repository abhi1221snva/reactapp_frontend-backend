<?php

namespace App\Model\Client\Ringless;
use Illuminate\Database\Eloquent\Model;

class RinglessWalletTransaction extends Model
{
    public $timestamps = true;
    protected $table = "ringless_wallet_transactions";
    protected $fillable = ['id','currency_code','amount','transaction_type','transaction_reference','description'];

}
