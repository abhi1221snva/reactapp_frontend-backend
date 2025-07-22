<?php

namespace App\Model\Client\Sip_trunk;
use Illuminate\Database\Eloquent\Model;

class TrunkingWalletTransaction extends Model
{
    public $timestamps = true;
    protected $table = "trunking_wallet_transactions";
    protected $fillable = ['id','currency_code','amount','transaction_type','transaction_reference','description'];

}
