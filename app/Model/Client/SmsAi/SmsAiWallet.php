<?php

namespace App\Model\Client\SmsAi;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class SmsAiWallet extends Model
{
    public $timestamps = true;
    protected $table = "sms_ai_wallet";
    protected $fillable = ['id','currency_code','amount'];

    public static function debitCharge($intCharge, $intClientId, $strCurrencyCode)
    {
        return DB::connection('mysql_' . $intClientId)->table('sms_ai_wallet')
            ->where('currency_code', $strCurrencyCode)
            ->update([
                'amount' => DB::raw('amount - ' . $intCharge),
                'updated_at' => Carbon::now(),
            ]);
    }
    
    public static function creditCharge($intCharge, $intClientId, $strCurrencyCode)
    {
        $wallet = DB::connection('mysql_' . $intClientId)->table('sms_ai_wallet')
            ->where('currency_code', $strCurrencyCode)
            ->first();
    
        if (!$wallet) {
            return DB::connection('mysql_' . $intClientId)->table('sms_ai_wallet')->insert([
                'currency_code' => $strCurrencyCode,
                'amount' => $intCharge,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        } else {
            return DB::connection('mysql_' . $intClientId)->table('sms_ai_wallet')
                ->where('currency_code', $strCurrencyCode)
                ->update([
                    'amount' => DB::raw('amount + ' . $intCharge),
                    'updated_at' => Carbon::now(),
                ]);
        }
    }
    
    

}
