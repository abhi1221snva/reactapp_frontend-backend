<?php

namespace App\Model\Client\Ringless;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class RinglessWallet extends Model
{
    public $timestamps = true;
    protected $table = "ringless_wallet";
    protected $fillable = ['id','currency_code','amount'];

    public static function debitCharge($intCharge, $intClientId, $strCurrencyCode)
    {
        return DB::connection('mysql_' . $intClientId)->table('ringless_wallet')->where('currency_code', $strCurrencyCode)->decrement('amount', $intCharge);
    }

    public static function creditCharge($intCharge, $intClientId, $strCurrencyCode)
    {
        if (RinglessWallet::on('mysql_' . $intClientId)->count() <= 0) {
            return DB::connection('mysql_' . $intClientId)->table('ringless_wallet')->insert(['currency_code' => $strCurrencyCode, 'amount' => $intCharge]);
        } else {
            return DB::connection('mysql_' . $intClientId)->table('ringless_wallet')->where('currency_code', $strCurrencyCode)->increment('amount', $intCharge);
        }
    }
}
