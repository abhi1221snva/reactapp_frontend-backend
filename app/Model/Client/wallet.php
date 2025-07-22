<?php

namespace App\Model\Client;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class wallet extends Model
{
    public $timestamps = false;
    protected $table = "wallet";
    protected $primaryKey = 'currency_code';

    public static function debitCharge($intCharge, $intClientId, $strCurrencyCode)
    {
        return DB::connection('mysql_' . $intClientId)->table('wallet')->where('currency_code', $strCurrencyCode)->decrement('amount', $intCharge);
    }

    public static function creditCharge($intCharge, $intClientId, $strCurrencyCode)
    {
        if (Wallet::on('mysql_' . $intClientId)->count() <= 0) {
            return DB::connection('mysql_' . $intClientId)->table('wallet')->insert(['currency_code' => $strCurrencyCode, 'amount' => $intCharge]);
        } else {
            return DB::connection('mysql_' . $intClientId)->table('wallet')->where('currency_code', $strCurrencyCode)->increment('amount', $intCharge);
        }
    }
}
