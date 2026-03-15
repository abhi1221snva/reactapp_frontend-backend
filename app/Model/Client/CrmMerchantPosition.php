<?php
namespace App\Model\Client;
use Illuminate\Database\Eloquent\Model;
class CrmMerchantPosition extends Model
{
    protected $table = 'crm_merchant_positions';
    protected $fillable = ['lead_id','lender_name','funded_amount','factor_rate','daily_payment','start_date','est_payoff_date','remaining_balance','position_number','source','notes'];
    protected $casts = ['funded_amount' => 'float', 'factor_rate' => 'float', 'daily_payment' => 'float', 'remaining_balance' => 'float'];
}
